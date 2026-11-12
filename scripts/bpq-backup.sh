#!/bin/bash
# bpq-backup.sh — nightly backup of a BPQ Dashboard station (BPQ Dashboard v1.5.42)
#
# OPTIONAL. Set up with:  sudo bash <web root>/scripts/bpq-backup-setup.sh
# (Installation Manual §10.16). Nothing runs until that setup is done.
#
# What it saves (restic: encrypted, deduplicated, keeps a schedule of copies):
#   - LinBPQ's folder (bpq32.cfg, linmail.cfg, BBS mail, chat ...), copied
#     while LinBPQ is stopped for a few seconds so the BBS files are consistent
#   - every MariaDB database (one consistent mysqldump)
#   - the dashboard folder (config.php, data/, LinBPQ's logs)
#   - /etc, /root, /home, /opt, /usr/local/bin, /usr/local/sbin, crontabs
#   - lists of installed packages, enabled services and firewall rules, so a
#     fresh install can be rebuilt
# Settings: /etc/bpq-dashboard/backup.conf (written by the setup).
# Reports to the dashboard: /var/lib/bpq-backup/status.json
#   (Admin → Maintenance → Backups, and System Audit).
#
# Usage (as root):  bpq-backup.sh            a normal run (what the timer does)
#                   bpq-backup.sh --check    also verify part of the stored data
set -uo pipefail

CONF=${BPQ_BACKUP_CONF:-/etc/bpq-dashboard/backup.conf}
STATUS=/var/lib/bpq-backup/status.json
STAGE=/var/backups/bpq-dashboard

# defaults; backup.conf overrides them
BACKUP_DIR=""; REQUIRE_MOUNT=yes; REPO=""; PASSWORD_FILE=/etc/bpq-dashboard/backup-password
WEB_ROOT=""; LINBPQ_DIR=""; BPQ_SERVICE=""; STOP_BPQ=yes
PATHS="/etc /root /home /opt /usr/local/bin /usr/local/sbin /var/spool/cron"
EXCLUDES="/home/*/Downloads /home/*/.cache /root/.cache /home/*/.vnc/*.log /home/*/.local/share/Trash"
KEEP_DAILY=30; KEEP_WEEKLY=12; KEEP_MONTHLY=6
LOG=/var/log/bpq-dashboard/backup.log

[ "$(id -u)" = 0 ] || { echo "Run as root (sudo)."; exit 1; }
[ -r "$CONF" ] || { echo "Backups aren't set up: run sudo bash <web root>/scripts/bpq-backup-setup.sh"; exit 1; }
# shellcheck disable=SC1090
. "$CONF"
mkdir -p "$(dirname "$LOG")" 2>/dev/null || LOG=/var/log/bpq-backup.log
exec >>"$LOG" 2>&1
echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') start"

export RESTIC_REPOSITORY="$REPO" RESTIC_PASSWORD_FILE="$PASSWORD_FILE"
export RESTIC_CACHE_DIR=/var/cache/restic        # systemd gives no $HOME

jstr() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n\r'; }
write_status() {   # result message [snapshot]
    local now lastok
    now=$(date -u +%FT%TZ)
    if [ "$1" = ok ]; then lastok=$now
    else lastok=$(sed -n 's/.*"last_ok_at":"\([^"]*\)".*/\1/p' "$STATUS" 2>/dev/null); fi
    mkdir -p "$(dirname "$STATUS")" && chmod 755 "$(dirname "$STATUS")"
    printf '{"result":"%s","finished_at":"%s","last_ok_at":"%s","message":"%s","snapshot":"%s","tool":"restic","drive":"%s","keep":"%s daily + %s weekly + %s monthly"}\n' \
        "$1" "$now" "$lastok" "$(jstr "$2")" "$(jstr "${3:-}")" "$BACKUP_DIR" "$KEEP_DAILY" "$KEEP_WEEKLY" "$KEEP_MONTHLY" \
        > "$STATUS.tmp" && chmod 644 "$STATUS.tmp" && mv -f "$STATUS.tmp" "$STATUS"
}
fail() { echo "FAILED: $*"; write_status failed "$*"; echo "===== failed"; exit 1; }

command -v restic >/dev/null || fail "restic is not installed (apt install restic)"
[ -n "$BACKUP_DIR" ] && [ -n "$REPO" ] || fail "BACKUP_DIR / REPO not set in $CONF"
# 1. the backup drive must really be there (never fill the system disk)
if [ "$REQUIRE_MOUNT" = yes ]; then
    mountpoint -q "$BACKUP_DIR" || mount "$BACKUP_DIR" 2>/dev/null
    mountpoint -q "$BACKUP_DIR" || fail "backup drive not mounted at $BACKUP_DIR"
fi
[ -r "$PASSWORD_FILE" ] || fail "no password file $PASSWORD_FILE"
[ -d "$REPO" ] || fail "no backup store at $REPO (run the setup again)"

umask 077
mkdir -p "$STAGE/system" "$STAGE/linbpq"

# 2. databases
if command -v mysqldump >/dev/null && { systemctl is-active --quiet mariadb || systemctl is-active --quiet mysql; }; then
    mysqldump --all-databases --single-transaction --routines --events --triggers \
        | gzip -6 > "$STAGE/mariadb-all.sql.gz.tmp" \
        && mv "$STAGE/mariadb-all.sql.gz.tmp" "$STAGE/mariadb-all.sql.gz" \
        || fail "database dump (mysqldump) failed"
    echo "database dump: $(du -h "$STAGE/mariadb-all.sql.gz" | cut -f1)"
else
    echo "no MariaDB/MySQL running: database dump skipped"
fi

# 3. what's installed and running
dpkg --get-selections            > "$STAGE/system/dpkg-selections.txt" 2>/dev/null
apt-mark showmanual              > "$STAGE/system/apt-manual.txt" 2>/dev/null
systemctl list-unit-files --state=enabled --no-legend > "$STAGE/system/enabled-units.txt" 2>/dev/null
iptables-save                    > "$STAGE/system/iptables-save.txt"  2>/dev/null
ip6tables-save                   > "$STAGE/system/ip6tables-save.txt" 2>/dev/null
for f in /var/spool/cron/crontabs/*; do [ -f "$f" ] && cp -f "$f" "$STAGE/system/crontab-$(basename "$f").txt"; done
lsblk -f                         > "$STAGE/system/lsblk.txt" 2>/dev/null
cat /etc/os-release              > "$STAGE/system/os-release.txt" 2>/dev/null
uname -a                         > "$STAGE/system/uname.txt"

# 4. LinBPQ, copied while it is stopped for a few seconds
if [ -n "$LINBPQ_DIR" ] && [ -d "$LINBPQ_DIR" ]; then
    if [ "$STOP_BPQ" = yes ] && [ -n "$BPQ_SERVICE" ] && systemctl is-active --quiet "$BPQ_SERVICE"; then
        systemctl stop "$BPQ_SERVICE"
        rsync -a --delete "$LINBPQ_DIR/" "$STAGE/linbpq/"; rc=$?
        systemctl start "$BPQ_SERVICE"
        [ $rc -eq 0 ] || fail "copy of $LINBPQ_DIR"
        echo "LinBPQ copied (stopped briefly), restarted: $(systemctl is-active "$BPQ_SERVICE")"
    else
        rsync -a --delete "$LINBPQ_DIR/" "$STAGE/linbpq/" || fail "copy of $LINBPQ_DIR"
        echo "LinBPQ copied while running"
    fi
else
    echo "LinBPQ folder not set or missing ($LINBPQ_DIR): skipped"
fi

# 5. the snapshot
EXARGS=(--exclude "$REPO")
[ -n "$LINBPQ_DIR" ] && EXARGS+=(--exclude "$LINBPQ_DIR")     # backed up via the stopped copy
for e in $EXCLUDES; do EXARGS+=(--exclude "$e"); done
# shellcheck disable=SC2086
restic backup --tag nightly --one-file-system --exclude-caches "${EXARGS[@]}" \
    "$STAGE" ${WEB_ROOT:+"$WEB_ROOT"} $PATHS || fail "restic backup failed"

# 6. keep the schedule of copies
restic forget --prune --keep-daily "$KEEP_DAILY" --keep-weekly "$KEEP_WEEKLY" --keep-monthly "$KEEP_MONTHLY" \
    --group-by host,tags || fail "restic forget failed"

# 7. verify (Sundays, or --check)
if [ "${1:-}" = "--check" ] || [ "$(date +%u)" = 7 ]; then
    restic check --read-data-subset=5% || fail "restic check found a problem"
fi

SNAP=$(restic snapshots --latest 1 --json 2>/dev/null | sed -n 's/.*"short_id":"\([0-9a-f]*\)".*/\1/p' | tail -1)
FREE=$(df -h "$BACKUP_DIR" | tail -1 | awk '{print $3 " used, " $4 " free"}')
write_status ok "$FREE" "$SNAP"
echo "drive: $FREE"
echo "===== $(date -u '+%Y-%m-%d %H:%M:%S UTC') OK"
