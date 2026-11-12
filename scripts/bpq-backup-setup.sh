#!/bin/bash
# bpq-backup-setup.sh — set up nightly backups of this station (OPTIONAL)
# BPQ Dashboard v1.5.42 — Installation Manual §10.16
#
#   sudo bash <web root>/scripts/bpq-backup-setup.sh
#
# Asks where to keep the backups (a mounted USB drive or other disk), makes a
# password, creates the backup store, installs the nightly timer and runs the
# first backup. Run it again to change the settings; it never erases a drive.
# To switch backups off: sudo systemctl disable --now bpq-backup.timer
set -uo pipefail

BOLD=$(tput bold 2>/dev/null || true); NC=$(tput sgr0 2>/dev/null || true)
YLW=$(tput setaf 3 2>/dev/null || true); GRN=$(tput setaf 2 2>/dev/null || true); RED=$(tput setaf 1 2>/dev/null || true)
say()  { echo "$*"; }
hdr()  { echo; echo "${BOLD}== $* ==${NC}"; }
die()  { echo "${RED}${BOLD}$*${NC}"; exit 1; }
yesno() { local d="${2:-N}" a; read -r -p "$1 $( [ "$d" = Y ] && echo '[Y/n]' || echo '[y/N]') " a; a=${a:-$d}; [[ "$a" =~ ^[Yy] ]]; }

[ "$(id -u)" = 0 ] || die "Run as root: sudo bash $0"
SCRIPTS=$(cd "$(dirname "$0")" && pwd)
WEB_ROOT=$(dirname "$SCRIPTS")
CONF_DIR=/etc/bpq-dashboard
CONF=$CONF_DIR/backup.conf
PASSWORD_FILE=$CONF_DIR/backup-password

cat <<EOF
${BOLD}BPQ Dashboard — nightly backups (optional)${NC}

This is an OPTIONAL extra, but a strongly recommended one: a dead disk on a
packet station loses LinBPQ's configuration and BBS mail, the dashboard's
database and settings, and the system settings that took an afternoon to get
right (radio device names, sound cards, services, firewall).

It sets up a nightly backup to a drive you choose (usually a USB drive that
stays plugged into this computer), keeps 30 daily, 12 weekly and 6 monthly
copies, checks itself weekly, and shows the result on Admin → Maintenance →
Backups. LinBPQ is stopped for a few seconds each night during the copy.
It does NOT format or erase anything.

EOF
yesno "Set up nightly backups now?" Y || { say "Nothing changed. Run this again any time: sudo bash $0"; exit 0; }

# ── restic ─────────────────────────────────────────────────────────────────
hdr "1. Backup program (restic)"
if ! command -v restic >/dev/null; then
    say "restic isn't installed."
    yesno "Install it now (apt install restic)?" Y || die "restic is needed. Install it, then run this again."
    apt-get install -y restic >/dev/null || die "Could not install restic."
fi
say "${GRN}restic $(restic version | awk '{print $2}')${NC}"

# ── where ──────────────────────────────────────────────────────────────────
hdr "2. Where to keep the backups"
OLD_DIR=""; [ -r "$CONF" ] && OLD_DIR=$(sed -n 's/^BACKUP_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CONF")
cat <<EOF
Give the folder where the backup drive is mounted, e.g. /mnt/backup.
Drives this computer can see (the backup drive should be one that is NOT
your system disk):
EOF
lsblk -o NAME,SIZE,MODEL,LABEL,FSTYPE,MOUNTPOINT | sed 's/^/   /'
cat <<EOF

A new USB drive has to be formatted and mounted first; this script won't do
that for you, so nothing is erased by mistake. The Installation Manual
§10.16 has the commands (format with a label, then a "nofail" line in
/etc/fstab so the computer still starts without it).
EOF
read -r -p "Backup folder [${OLD_DIR:-/mnt/backup}]: " BACKUP_DIR
BACKUP_DIR=${BACKUP_DIR:-${OLD_DIR:-/mnt/backup}}
BACKUP_DIR=${BACKUP_DIR%/}
[ -d "$BACKUP_DIR" ] || die "$BACKUP_DIR doesn't exist. Mount the drive there first (Manual §10.16)."
REQUIRE_MOUNT=yes
if ! mountpoint -q "$BACKUP_DIR"; then
    ROOTDEV=$(df --output=source / | tail -1); HEREDEV=$(df --output=source "$BACKUP_DIR" | tail -1)
    if [ "$ROOTDEV" = "$HEREDEV" ]; then
        echo "${YLW}$BACKUP_DIR is on the SYSTEM disk ($ROOTDEV), not a separate drive.${NC}"
        echo "A backup on the same disk is lost with it. Use a separate drive if you can."
        yesno "Use it anyway?" N || die "Mount a separate drive at $BACKUP_DIR, then run this again."
    fi
    REQUIRE_MOUNT=no
fi
df -h "$BACKUP_DIR" | tail -1 | awk '{print "   " $1 ": " $2 " size, " $4 " free"}'

# ── what ───────────────────────────────────────────────────────────────────
hdr "3. What to back up"
LINBPQ_DIR=$(php -r '$c = @include $argv[1]; echo is_array($c) ? rtrim((string)($c["paths"]["linbpq"] ?? ""), "/") : "";' "$WEB_ROOT/config.php" 2>/dev/null)
read -r -p "LinBPQ folder [${LINBPQ_DIR:-/opt/linbpq}]: " a; LINBPQ_DIR=${a:-${LINBPQ_DIR:-/opt/linbpq}}; LINBPQ_DIR=${LINBPQ_DIR%/}
[ -f "$LINBPQ_DIR/bpq32.cfg" ] || echo "${YLW}No bpq32.cfg in $LINBPQ_DIR — check the folder (it's backed up anyway if it exists).${NC}"
BPQ_SERVICE=""
for u in bpq linbpq; do systemctl list-unit-files "$u.service" --no-legend 2>/dev/null | grep -q . && { BPQ_SERVICE=$u; break; }; done
if [ -n "$BPQ_SERVICE" ]; then
    say "LinBPQ service: $BPQ_SERVICE (stopped for a few seconds during the nightly copy)"
else
    say "${YLW}No bpq/linbpq systemd service found: LinBPQ's folder is copied while it runs.${NC}"
fi
say "Also saved: the dashboard ($WEB_ROOT), all databases, /etc, /root, /home, /opt,"
say "/usr/local/bin, /usr/local/sbin, crontabs, and lists of packages and services."
say "Left out: Downloads folders, caches, Trash."

# ── password ───────────────────────────────────────────────────────────────
hdr "4. Password"
mkdir -p "$CONF_DIR" && chmod 755 "$CONF_DIR"
REPO="$BACKUP_DIR/restic-$(hostname -s)"
if [ -s "$PASSWORD_FILE" ]; then
    say "Keeping the existing password ($PASSWORD_FILE)."
else
    ( umask 077; openssl rand -base64 24 > "$PASSWORD_FILE" 2>/dev/null || head -c 24 /dev/urandom | base64 > "$PASSWORD_FILE" )
    chmod 600 "$PASSWORD_FILE"
    cat <<EOF
The backups are encrypted with this password. ${BOLD}Without it nobody can read
them, you included${NC} — if the system disk dies, you'll need it to restore.

   ${BOLD}$(cat "$PASSWORD_FILE")${NC}

Write it down now and keep it away from this computer.
EOF
    read -r -p "Press Enter once you've written it down... " _
fi

# ── store ──────────────────────────────────────────────────────────────────
hdr "5. Backup store"
export RESTIC_PASSWORD_FILE="$PASSWORD_FILE" RESTIC_CACHE_DIR=/var/cache/restic
if [ -f "$REPO/config" ]; then
    restic -r "$REPO" cat config >/dev/null 2>&1 || die "$REPO exists but this password doesn't open it."
    say "Using the existing store: $REPO"
else
    restic -r "$REPO" init >/dev/null || die "Could not create the store in $REPO."
    say "${GRN}Created $REPO${NC}"
fi

# ── settings + timer ───────────────────────────────────────────────────────
hdr "6. Nightly timer"
cat > "$CONF" <<EOF
# BPQ Dashboard backups — written by bpq-backup-setup.sh $(date -u +%F)
# Run the setup again to change these, or edit here.
BACKUP_DIR="$BACKUP_DIR"
REQUIRE_MOUNT=$REQUIRE_MOUNT
REPO="$REPO"
PASSWORD_FILE="$PASSWORD_FILE"
WEB_ROOT="$WEB_ROOT"
LINBPQ_DIR="$LINBPQ_DIR"
BPQ_SERVICE="$BPQ_SERVICE"
STOP_BPQ=yes
PATHS="/etc /root /home /opt /usr/local/bin /usr/local/sbin /var/spool/cron"
EXCLUDES="/home/*/Downloads /home/*/.cache /root/.cache /home/*/.vnc/*.log /home/*/.local/share/Trash"
KEEP_DAILY=30
KEEP_WEEKLY=12
KEEP_MONTHLY=6
EOF
chmod 600 "$CONF"
sed "s#@WEB_ROOT@#$WEB_ROOT#g" "$WEB_ROOT/bpq-backup.service" > /etc/systemd/system/bpq-backup.service
cp -f "$WEB_ROOT/bpq-backup.timer" /etc/systemd/system/bpq-backup.timer
systemctl daemon-reload
systemctl enable --now bpq-backup.timer >/dev/null 2>&1
say "Nightly at about 03:15 (this computer's time); a missed night runs at the next start."
systemctl list-timers bpq-backup.timer --no-pager | sed -n 2p | sed 's/^/   /'

# ── first run ──────────────────────────────────────────────────────────────
hdr "7. First backup"
if yesno "Run the first backup now (a few minutes; LinBPQ stops for a few seconds)?" Y; then
    systemctl start bpq-backup.service
    tail -4 /var/log/bpq-dashboard/backup.log 2>/dev/null | sed 's/^/   /'
fi

cat <<EOF

${GRN}${BOLD}Backups are set up.${NC}
  - Result:  Admin → Maintenance → Backups (and System Audit)
  - Log:     /var/log/bpq-dashboard/backup.log
  - Restore: Installation Manual §10.16 ("Restoring")
  - Settings: $CONF    Off: sudo systemctl disable --now bpq-backup.timer
${BOLD}Keep the password somewhere safe, and test a restore once (Manual §10.16).${NC}
EOF
