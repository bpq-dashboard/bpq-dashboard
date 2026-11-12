#!/bin/bash
# ============================================================================
#  BPQ Dashboard — in-place update (v1.5.19 and later)
#  ────────────────────────────────────────────────────────────────────────
#  Updates an existing dashboard to the release in THIS folder, without a
#  full reinstall. Run it from the unzipped new release:
#
#      sudo bash update.sh              # shows the plan, asks, then updates
#      sudo bash update.sh --dry-run    # shows the plan only, changes nothing
#
#  Options:
#      --web-root DIR   the dashboard's folder (default: found automatically)
#      --yes            don't ask before updating
#      --force          allow re-installing the same or an older version
#      --keep-local     don't replace files changed on this server since the
#                       last update (they're listed in the plan)
#      --keep-list FILE paths never to replace or remove (default:
#                       /etc/bpq-dashboard/update-keep.txt if it exists);
#                       also /etc/systemd/system/<unit> and
#                       /etc/sudoers.d/www-data-tools to keep those
#      --rollback DIR   undo an update (DIR = its backup folder, printed at
#                       the end of every update)
#
#  What it keeps exactly as it is:
#    config.php (and db-/bbs-/nws-config.php), data/ (your partners,
#    settings, stations), cache/, the logs link to LinBPQ, pktnet-forms/,
#    img/, yapp-files/, the database contents, your nginx / Apache site
#    file, your firewall, LinBPQ and everything outside the dashboard.
#
#  What it does:
#    1. compares every file of the new release with your installed copy
#       (and, from v1.5.19 on, with the release you installed, to spot files
#       you edited by hand: they're listed, backed up and replaced);
#    2. backs up every file it will replace or remove, and writes a restore
#       script, in /root/bpq-dashboard-update-<date>/;
#    3. copies the new and changed files (owner and mode kept), removes the
#       ones the release dropped (old PDFs, retired pages), checks each copy;
#    4. applies the database updates (sql/migrations.list; each is safe to
#       repeat);
#    5. refreshes the systemd units and the sudo rules that the installer
#       set up, only where they changed, and restarts only the helper
#       services whose program or unit changed;
#    6. records the installed version (VERSION, MANIFEST.md5).
#
#  A full log is written next to the backup.
# ============================================================================
set -u

if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GRN=$'\033[0;32m'; YLW=$'\033[0;33m'; CYN=$'\033[0;36m'
    BOLD=$'\033[1m'; DIM=$'\033[2m'; NC=$'\033[0m'
else RED=''; GRN=''; YLW=''; CYN=''; BOLD=''; DIM=''; NC=''; fi
ok()   { echo "  ${GRN}✓${NC} $*"; }
warn() { echo "  ${YLW}⚠${NC} $*"; WARNS+=("$*"); }
fail() { echo "  ${RED}✗${NC} $*"; FAILS+=("$*"); }
say()  { echo "  $*"; }
hdr()  { echo; echo "${BOLD}${CYN}── $* ──${NC}"; }
die()  { echo; echo "${RED}${BOLD}Stopped: $*${NC}"; exit 1; }
WARNS=(); FAILS=()

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRY=n; YES=n; FORCE=n; WEB_ROOT=""; ROLLBACK=""; KEEP_LOCAL=n; KEEP_FILE=/etc/bpq-dashboard/update-keep.txt
while (( $# )); do
    case "$1" in
        --dry-run) DRY=y ;;
        --yes|-y)  YES=y ;;
        --force)   FORCE=y ;;
        --keep-local) KEEP_LOCAL=y ;;
        --keep-list) shift; KEEP_FILE="${1:-}"; [[ -f "$KEEP_FILE" ]] || { echo "keep list $KEEP_FILE not found"; exit 1; } ;;
        --web-root) shift; WEB_ROOT="${1:-}" ;;
        --rollback) shift; ROLLBACK="${1:-}" ;;
        -h|--help) sed -n '2,48p' "$0"; exit 0 ;;
        *) die "unknown option $1 (try --help)" ;;
    esac
    shift
done
[[ $EUID -eq 0 ]] || die "run it as root:  sudo bash $0"

# ── Rollback ────────────────────────────────────────────────────────────────
if [[ -n "$ROLLBACK" ]]; then
    [[ -x "$ROLLBACK/restore.sh" ]] || die "$ROLLBACK/restore.sh not found"
    echo "This puts back what the update in $ROLLBACK replaced and removes what it added."
    if [[ "$YES" != y ]]; then read -r -p "  Roll back now? [y/N]: " a; [[ "$a" =~ ^[Yy] ]] || exit 0; fi
    bash "$ROLLBACK/restore.sh"; exit $?
fi

# ── The new release ────────────────────────────────────────────────────────
[[ -f "$SRC/MANIFEST.md5" && -f "$SRC/VERSION" ]] || \
    die "run this from an unzipped BPQ Dashboard release (MANIFEST.md5 and VERSION are missing here: $SRC)"
NEW_VER=$(tr -d '[:space:]' < "$SRC/VERSION")
( cd "$SRC" && md5sum --quiet -c MANIFEST.md5 >/dev/null 2>&1 ) || \
    die "files in $SRC don't match its MANIFEST.md5 (incomplete download, or edited). Unzip the release again."

# ── The installed dashboard ────────────────────────────────────────────────
is_dash() { [[ -f "$1/bpq-nav-loader.js" && -f "$1/config.php" ]]; }
if [[ -z "$WEB_ROOT" ]]; then
    CANDS=()
    is_dash /var/www/bpq-dashboard && CANDS+=(/var/www/bpq-dashboard)
    for d in $(grep -rhoE '^[[:space:]]*(root|DocumentRoot)[[:space:]]+[^;[:space:]]+' \
                 /etc/nginx/sites-enabled /etc/nginx/conf.d /etc/apache2/sites-enabled 2>/dev/null \
               | awk '{print $2}' | tr -d '"' | sed 's|/$||' | sort -u); do
        is_dash "$d" && [[ " ${CANDS[*]:-} " != *" $d "* ]] && CANDS+=("$d")
    done
    if (( ${#CANDS[@]} == 1 )); then WEB_ROOT="${CANDS[0]}"
    elif (( ${#CANDS[@]} == 0 )); then die "no installed dashboard found; give its folder with --web-root DIR"
    else die "several dashboards found (${CANDS[*]}); choose one with --web-root DIR"; fi
fi
WEB_ROOT="${WEB_ROOT%/}"
is_dash "$WEB_ROOT" || die "$WEB_ROOT doesn't look like a dashboard folder (no config.php / bpq-nav-loader.js)"
[[ "$WEB_ROOT" != "$SRC" ]] || die "this is the installed folder itself; run update.sh from the unzipped new release"
WEB_USER=$(stat -c %U "$WEB_ROOT/config.php"); WEB_GROUP=$(stat -c %G "$WEB_ROOT/config.php")

if [[ -f "$WEB_ROOT/VERSION" ]]; then OLD_VER=$(tr -d '[:space:]' < "$WEB_ROOT/VERSION")
else OLD_VER=$(grep -ohE 'BPQ Dashboard v[0-9]+\.[0-9]+\.[0-9]+' "$WEB_ROOT/install-check.php" 2>/dev/null | head -1 | grep -oE '[0-9.]+$'); fi
OLD_VER="${OLD_VER:-unknown}"
OLD_MAN="$WEB_ROOT/MANIFEST.md5"; [[ -f "$OLD_MAN" ]] || OLD_MAN=""

echo
echo "${BOLD}BPQ Dashboard update${NC}  $OLD_VER → ${BOLD}$NEW_VER${NC}  $( [[ $DRY == y ]] && echo "(dry run: nothing is changed)" )"
say "Installed: $WEB_ROOT  (files owned by $WEB_USER)"
say "New release: $SRC"
if [[ "$OLD_VER" != unknown ]]; then
    newest=$(printf '%s\n%s\n' "$OLD_VER" "$NEW_VER" | sort -V | tail -1)
    if [[ "$OLD_VER" == "$NEW_VER" ]]; then
        [[ $FORCE == y || $DRY == y ]] || die "$NEW_VER is already installed. To check or repair it: --dry-run, or --force to copy it again"
    elif [[ "$newest" == "$OLD_VER" ]]; then
        [[ $FORCE == y ]] || die "the installed $OLD_VER is newer than $NEW_VER. Use --force to go back (or --rollback)"
    fi
fi

# config.php values (booleans as 1 / 0; missing = empty)
php_cfg() { php -r '$c = @include $argv[1]; $v = is_array($c) ? ($c[$argv[2]][$argv[3]] ?? "") : ""; echo is_bool($v) ? ($v ? "1" : "0") : (is_scalar($v) ? $v : "");' -- "$WEB_ROOT/config.php" "$1" "$2" 2>/dev/null; }
command -v php >/dev/null 2>&1 || die "php (CLI) not found; the dashboard needs it"
VF=$(php_cfg logs vara_file); VF=$(basename "${VF:-}")
CALL_LC=$(php_cfg station callsign | tr '[:upper:]' '[:lower:]')
# What the installer makes of a package file in the web folder (paths, callsign)
adjust_stream() {   # adjust_stream <relative path>  (reads stdin, writes stdout)
    if [[ "$1" == vara-logger.sh ]]; then
        sed "s|/var/www/bpq-dashboard|$WEB_ROOT|g; s|yourcall\.vara|${VF:-${CALL_LC:-yourcall}.vara}|g"
    elif [[ "$1" =~ ^[^/]+\.(service|timer)$ ]]; then
        sed "s|/var/www/bpq-dashboard|$WEB_ROOT|g"
    else cat; fi
}

# ── Plan ───────────────────────────────────────────────────────────────────
# Never written by an update (even if a release ever shipped them):
protected() {
    case "$1" in
        config.php|db-config.php|bbs-config.php|nws-config.php|tprfn-config.php|bpqdash-config.php) return 0 ;;
        logs/*|cache/*|pktnet-forms/*|img/*|yapp-files/*|data/stations/*|data/backups/*|private/*) return 0 ;;
        data/*.json) return 0 ;;
        install.sh|update.sh|MANIFEST.md5|CLAUDE.md|docs/SESSION-JOURNAL.md) return 0 ;;
    esac
    return 1
}
# The station's own keep list (v1.5.20): files a station deliberately runs in
# its own version. One path per line, relative to the web folder; shell
# wildcards allowed; # starts a comment. Kept files are never replaced or
# removed. Keep it outside the web folder, root-owned.
KEEP_PATS=()
if [[ -f "$KEEP_FILE" ]]; then
    while IFS= read -r l; do
        l="${l%%#*}"; l="${l#"${l%%[![:space:]]*}"}"; l="${l%"${l##*[![:space:]]}"}"
        [[ -n "$l" ]] && KEEP_PATS+=("$l")
    done < "$KEEP_FILE"
fi
kept() { local p; for p in "${KEEP_PATS[@]}"; do [[ "$1" == $p ]] && return 0; done; return 1; }
# Files the installer adjusts after copying (paths, callsign): never "edited by hand"
adjusted() { [[ "$1" =~ ^[^/]+\.(service|timer)$ || "$1" == vara-logger.sh ]]; }

declare -A NEWSUM OLDSUM
while read -r sum f; do NEWSUM["$f"]="$sum"; done < "$SRC/MANIFEST.md5"
if [[ -n "$OLD_MAN" ]]; then while read -r sum f; do OLDSUM["$f"]="$sum"; done < "$OLD_MAN"; fi

ADD=(); REPL=(); LOCAL=(); REMOVE=(); KEPT=(); SAME=0
for f in $(printf '%s\n' "${!NEWSUM[@]}" | sort); do
    protected "$f" && continue
    live="$WEB_ROOT/$f"
    if kept "$f"; then
        [[ -e "$live" && "$(md5sum < "$live" | cut -d' ' -f1)" == "${NEWSUM[$f]}" ]] && { SAME=$((SAME+1)); continue; }
        KEPT+=("$f"); continue
    fi
    if [[ ! -e "$live" ]]; then ADD+=("$f"); continue; fi
    cur=$(md5sum < "$live" | cut -d' ' -f1)
    if [[ "$cur" == "${NEWSUM[$f]}" ]]; then SAME=$((SAME+1)); continue; fi
    if adjusted "$f" && [[ "$cur" == "$(adjust_stream "$f" < "$SRC/$f" | md5sum | cut -d' ' -f1)" ]]; then SAME=$((SAME+1)); continue; fi
    REPL+=("$f")
    if [[ -n "$OLD_MAN" && -n "${OLDSUM[$f]:-}" && "$cur" != "${OLDSUM[$f]}" ]] && ! adjusted "$f"; then
        if [[ $KEEP_LOCAL == y ]]; then unset 'REPL[-1]'; KEPT+=("$f"); else LOCAL+=("$f"); fi
    fi
done
# Dropped by this release: in the old manifest, not in the new one
if [[ -n "$OLD_MAN" ]]; then
    for f in "${!OLDSUM[@]}"; do
        [[ -n "${NEWSUM[$f]:-}" ]] && continue
        protected "$f" && continue
        kept "$f" && continue
        [[ -e "$WEB_ROOT/$f" ]] && REMOVE+=("$f")
    done
fi
# Older guides/manuals (before manifests existed)
for p in "$WEB_ROOT"/BPQ-Dashboard-v*-User-Guide.pdf "$WEB_ROOT"/BPQ-Dashboard-v*-Manual.pdf; do
    [[ -e "$p" ]] || continue
    b=$(basename "$p"); [[ -n "${NEWSUM[$b]:-}" ]] && continue
    kept "$b" && continue
    [[ " ${REMOVE[*]:-} " == *" $b "* ]] || REMOVE+=("$b")
done

hdr "Plan"
say "$SAME file(s) already up to date, ${#REPL[@]} to update, ${#ADD[@]} new, ${#REMOVE[@]} to remove (kept in the backup)"
(( ${#ADD[@]} ))    && say "New: ${DIM}$(printf '%s ' "${ADD[@]}")${NC}"
(( ${#REMOVE[@]} )) && say "Removed by this release: ${DIM}$(printf '%s ' "${REMOVE[@]}")${NC}"
if (( ${#KEPT[@]} )); then
    say "${BOLD}Kept as they are${NC} ($( [[ $KEEP_LOCAL == y ]] && echo "--keep-local" )$( [[ $KEEP_LOCAL == y && ${#KEEP_PATS[@]} -gt 0 ]] && echo ", " )$( (( ${#KEEP_PATS[@]} )) && echo "keep list $KEEP_FILE" )): ${DIM}$(printf '%s ' "${KEPT[@]}")${NC}"
    say "  They differ from $NEW_VER; check them against the CHANGELOG when you have time."
fi
if (( ${#LOCAL[@]} )); then
    warn "These files were changed on this server since $OLD_VER was installed. They are backed up, then replaced:"
    for f in "${LOCAL[@]}"; do say "     $f"; done
    say "  (Re-apply a change by hand afterwards if you still need it; the backup keeps your copy."
    say "   To keep them instead: --keep-local, or list them in $KEEP_FILE.)"
elif [[ -z "$OLD_MAN" ]]; then
    say "${DIM}No MANIFEST.md5 from the installed version (before v1.5.19), so hand edits can't be told apart; every replaced file is in the backup.${NC}"
fi
for t in nginx-bpq-dashboard.conf bpq-dashboard.conf nginx-maintenance-block.conf; do
    # only when the template itself changed between the two releases
    if [[ -n "${OLDSUM[$t]:-}" && -n "${NEWSUM[$t]:-}" && "${OLDSUM[$t]}" != "${NEWSUM[$t]}" ]]; then
        say "${YLW}Note:${NC} the web-server template $t changed in this release. Your live site file is NOT changed;"
        say "      see CHANGELOG.md ($NEW_VER) for anything to add to it."
        break
    fi
done

# Units and sudo rules this update would refresh (shown so hand-tuned ones
# can be checked first; a unit not run from this folder is never touched)
# The package's sudo rules for this station. The one rule that changes
# something (Maintenance > Change admin password) is kept only where the
# station already has it; adding it is the sysop's choice (Manual §11).
sudo_want() {
    if grep -qs 'bpq-dashboard-set-admin-password' /etc/sudoers.d/*; then
        sed "s/^www-data /$WEB_USER /" "$SRC/www-data-tools.sudoers"
    else
        sed "s/^www-data /$WEB_USER /" "$SRC/www-data-tools.sudoers" | grep -v '^[^#].*bpq-dashboard-set-admin-password'
    fi
}
unit_want() { sed "s|/var/www/bpq-dashboard|$WEB_ROOT|g; s|^User=www-data$|User=$WEB_USER|; s|^Group=www-data$|Group=$WEB_GROUP|" "$1"; }
ours_unit() {   # $1 = unit name
    local live="/etc/systemd/system/$1"
    grep -q -- "$WEB_ROOT" "$live" 2>/dev/null && return 0
    [[ "$1" == *.timer ]] && grep -q -- "$WEB_ROOT" "/etc/systemd/system/${1%.timer}.service" 2>/dev/null
}
PLAN_UNITS=()
for unit in "$SRC"/*.service "$SRC"/*.timer; do
    u=$(basename "$unit"); [[ -f "/etc/systemd/system/$u" ]] || continue
    ours_unit "$u" || continue
    kept "/etc/systemd/system/$u" && { say "Kept (keep list): /etc/systemd/system/$u"; continue; }
    [[ "$(unit_want "$unit")" != "$(cat "/etc/systemd/system/$u")" ]] && PLAN_UNITS+=("$u")
done
(( ${#PLAN_UNITS[@]} )) && say "Systemd units to refresh: ${DIM}${PLAN_UNITS[*]}${NC}  (see the difference: diff /etc/systemd/system/NAME $SRC/NAME)"
if kept /etc/sudoers.d/www-data-tools; then
    say "Kept (keep list): /etc/sudoers.d/www-data-tools"
elif [[ -f /etc/sudoers.d/www-data-tools && -f "$SRC/www-data-tools.sudoers" ]] && \
   ! cmp -s <(sudo_want) /etc/sudoers.d/www-data-tools; then
    say "Sudo rules to refresh: /etc/sudoers.d/www-data-tools  (see: diff /etc/sudoers.d/www-data-tools $SRC/www-data-tools.sudoers)"
fi

if (( ${#REPL[@]} + ${#ADD[@]} + ${#REMOVE[@]} == 0 )) && [[ $FORCE != y ]]; then
    echo; ok "Nothing to copy: every file already matches $NEW_VER."
fi
if [[ $DRY == y ]]; then echo; say "Dry run: nothing changed. Run without --dry-run to update."; exit 0; fi
if [[ $YES != y ]]; then
    echo; read -r -p "  Update $WEB_ROOT to $NEW_VER now? [Y/n]: " a
    [[ "$a" =~ ^[Nn] ]] && { say "Nothing changed."; exit 0; }
fi

# ── Backup + restore script ────────────────────────────────────────────────
TS=$(date +%Y%m%d-%H%M%S)
BK="/root/bpq-dashboard-update-$TS"
mkdir -p "$BK/etc" && chmod 700 "$BK"
LOG="$BK/update.log"
exec > >(tee -a "$LOG") 2>&1
hdr "Backup → $BK"
( cd "$WEB_ROOT" && printf '%s\n' "${REPL[@]}" "${REMOVE[@]}" "VERSION" "MANIFEST.md5" | grep -v '^$' | sort -u | \
    while read -r f; do [[ -e "$f" ]] && echo "$f"; done > "$BK/saved.txt" )
if [[ -s "$BK/saved.txt" ]]; then
    tar czpf "$BK/web-before.tgz" -C "$WEB_ROOT" -T "$BK/saved.txt" && ok "$(wc -l < "$BK/saved.txt") file(s) saved (web-before.tgz)" \
        || die "backup failed — nothing was changed"
fi
printf '%s\n' "${ADD[@]}" | grep -v '^$' > "$BK/added.txt" || true
[[ -e "$WEB_ROOT/MANIFEST.md5" ]] || echo MANIFEST.md5 >> "$BK/added.txt"   # first update with manifests
cat > "$BK/restore.sh" <<RESTORE
#!/bin/bash
# Undo the BPQ Dashboard update of $TS ($OLD_VER → $NEW_VER) in $WEB_ROOT
set -u
cd "$WEB_ROOT" || exit 1
[[ -s "$BK/web-before.tgz" ]] && tar xzpf "$BK/web-before.tgz" -C "$WEB_ROOT"
while read -r f; do [[ -n "\$f" ]] && rm -f -- "$WEB_ROOT/\$f"; done < "$BK/added.txt"
for u in "$BK"/etc/systemd/*; do [[ -e "\$u" ]] && cp -p "\$u" /etc/systemd/system/; done
if [[ -f "$BK/units-added.txt" ]]; then
    while read -r u; do systemctl disable --now "\$u" >/dev/null 2>&1; rm -f "/etc/systemd/system/\$u"; done < "$BK/units-added.txt"
fi
for s in "$BK"/etc/sudoers.d/*; do [[ -e "\$s" ]] && cp -p "\$s" /etc/sudoers.d/; done
[[ -f "$BK/new-root-files.txt" ]] && while read -r f; do rm -f "\$f"; done < "$BK/new-root-files.txt"
[[ -e "$BK/etc/bpq-dashboard-set-admin-password" ]] && cp -p "$BK/etc/bpq-dashboard-set-admin-password" /usr/local/sbin/
systemctl daemon-reload
for s in bpq-telnet bpq-chat bpq-aprs bpq-vara vara-logger vara-validator rig-history-poller; do
    systemctl is-active --quiet "\$s" && systemctl restart "\$s"
done
echo "Rolled back to $OLD_VER (files, units and sudo rules as they were before the update)."
echo "Database updates are kept (they are additions the older version ignores)."
RESTORE
chmod 700 "$BK/restore.sh"
ok "Restore script: sudo bash $SRC/update.sh --rollback $BK"

# ── Copy ───────────────────────────────────────────────────────────────────
hdr "Files"
CHANGED=()
mode_for() {   # keep an existing file's mode; new files: 755 for programs
    local f="$1"
    if [[ -e "$WEB_ROOT/$f" ]]; then stat -c %a "$WEB_ROOT/$f"; return; fi
    case "$f" in *.sh|*.py|tools/set-admin-password) echo 755 ;; *) echo 644 ;; esac
}
for f in "${REPL[@]}" "${ADD[@]}"; do
    [[ -n "$f" ]] || continue
    d=$(dirname "$WEB_ROOT/$f")
    if [[ ! -d "$d" ]]; then mkdir -p "$d" && chown "$WEB_USER:$WEB_GROUP" "$d"; fi
    m=$(mode_for "$f")
    if install -o "$WEB_USER" -g "$WEB_GROUP" -m "$m" "$SRC/$f" "$WEB_ROOT/$f.bpqnew.$$" && mv -f "$WEB_ROOT/$f.bpqnew.$$" "$WEB_ROOT/$f"; then
        [[ "$(md5sum < "$WEB_ROOT/$f" | cut -d' ' -f1)" == "${NEWSUM[$f]}" ]] && CHANGED+=("$f") || fail "checksum differs after copy: $f"
    else
        rm -f "$WEB_ROOT/$f.bpqnew.$$"; fail "could not copy $f"
    fi
done
for f in "${REMOVE[@]}"; do [[ -n "$f" ]] && rm -f -- "$WEB_ROOT/$f"; done
ok "${#CHANGED[@]} file(s) copied and checked, ${#REMOVE[@]} removed"
(( ${#FAILS[@]} )) && die "some files failed to copy (above). Put everything back with: sudo bash $SRC/update.sh --rollback $BK"

# Re-apply what the installer adjusts in the copied files
for f in "${CHANGED[@]}"; do
    if adjusted "$f"; then
        adjust_stream "$f" < "$SRC/$f" > "$WEB_ROOT/$f.bpqnew.$$" && cat "$WEB_ROOT/$f.bpqnew.$$" > "$WEB_ROOT/$f"; rm -f "$WEB_ROOT/$f.bpqnew.$$"
    fi
done

# Syntax check of what was copied
LINT=0
for f in "${CHANGED[@]}"; do
    case "$f" in
        *.php) php -l "$WEB_ROOT/$f" >/dev/null 2>&1 || { fail "PHP syntax: $f"; LINT=1; } ;;
        *.py)  python3 -c 'import ast,sys; ast.parse(open(sys.argv[1],encoding="utf-8").read())' "$WEB_ROOT/$f" 2>/dev/null || { fail "Python syntax: $f"; LINT=1; } ;;
        *.sh)  bash -n "$WEB_ROOT/$f" 2>/dev/null || { fail "shell syntax: $f"; LINT=1; } ;;
    esac
done
(( LINT )) || ok "Syntax check passed"

# ── Folders the newer versions use ─────────────────────────────────────────
hdr "Folders"
for d in /var/log/bpq-dashboard /var/lib/bpq-dashboard; do
    if [[ ! -d "$d" ]]; then mkdir -p "$d" && chown "$WEB_USER:$WEB_GROUP" "$d" && chmod 750 "$d" && ok "Created $d"
    elif ! sudo -u "$WEB_USER" test -w "$d"; then chown "$WEB_USER:$WEB_GROUP" "$d" && ok "$d now writable by $WEB_USER"
    else ok "$d"; fi
done
if [[ ! -e /etc/logrotate.d/bpq-dashboard ]]; then
    printf '# BPQ Dashboard'"'"'s own logs (update.sh)\n/var/log/bpq-dashboard/*.log {\n    weekly\n    rotate 8\n    compress\n    delaycompress\n    missingok\n    notifempty\n    copytruncate\n}\n' > /etc/logrotate.d/bpq-dashboard \
        && ok "Weekly log rotation added (/etc/logrotate.d/bpq-dashboard)"
fi

# ── Database ───────────────────────────────────────────────────────────────
hdr "Database"
DBH=$(php_cfg db host); DBN=$(php_cfg db name); DBU=$(php_cfg db user); DBP=$(php_cfg db pass); DBE=$(php_cfg db enabled)   # DBE: 0 = switched off
if [[ -f "$WEB_ROOT/db-config.php" ]]; then   # older installs: db-config.php wins
    eval "$(php -r '$c = @include $argv[1]; if (is_array($c)) foreach (["host"=>"DBH","name"=>"DBN","user"=>"DBU","pass"=>"DBP"] as $k=>$v) if (isset($c[$k])) echo $v."=".escapeshellarg((string)$c[$k])."\n";' -- "$WEB_ROOT/db-config.php" 2>/dev/null)"
fi
if [[ -z "$DBN" || -z "$DBU" || "$DBE" == "0" ]] || ! command -v mysql >/dev/null 2>&1; then
    say "No database configured (or no mysql client) — skipped."
else
    CNF=$(mktemp); chmod 600 "$CNF"
    P=${DBP//\\/\\\\}; P=${P//\"/\\\"}     # option-file quoting: \ and " escaped
    printf '[client]\nhost=%s\nuser=%s\npassword="%s"\n' "${DBH:-localhost}" "$DBU" "$P" > "$CNF"; unset P
    # v1.5.22: the dashboard's own database user may only read and write data
    # (ARSSYSTEM: tprfn_app), so CREATE / ALTER were refused even for tables
    # that already exist. update.sh runs under sudo: use MariaDB's root
    # account over the local socket when it lets us in, else the dashboard user.
    MYSQL=()
    if [[ "${DBH:-localhost}" =~ ^(localhost|127\.0\.0\.1|::1)$ ]] && mysql -e 'SELECT 1' "$DBN" >/dev/null 2>&1; then
        MYSQL=(mysql); say "Database files run as MariaDB root (local socket)."
    elif mysql --defaults-extra-file="$CNF" "$DBN" -e 'SELECT 1' >/dev/null 2>&1; then
        MYSQL=(mysql --defaults-extra-file="$CNF")
    fi
    if (( ${#MYSQL[@]} )); then
        n=0; bad=0; denied=0
        for s in $(grep -vE '^[[:space:]]*(#|$)' "$SRC/sql/migrations.list"); do
            [[ -f "$SRC/$s" ]] || continue
            ERR=$("${MYSQL[@]}" "$DBN" < "$SRC/$s" 2>&1 >/dev/null); rc=$?
            [[ -n "$ERR" ]] && printf '%s: %s\n' "$s" "$ERR" >> "$LOG"
            if (( rc == 0 )); then n=$((n+1))
            elif grep -q "ERROR 1142" <<<"$ERR"; then denied=$((denied+1))   # CREATE/ALTER command denied
            else bad=$((bad+1)); warn "$s didn't apply cleanly (details in $LOG)"; fi
        done
        ok "$n database file(s) applied (all safe to repeat)"
        (( denied )) && say "  $denied file(s) skipped: the dashboard's database user may not create or alter tables. Fine when the tables already exist (System Audit checks them); to apply them, run update.sh with sudo on a MariaDB whose root logs in over the socket."
    else
        warn "Could not log in to the database '$DBN' as '$DBU' — updates skipped. System Audit lists any it lacks."
    fi
    rm -f "$CNF"
fi

# ── systemd units ──────────────────────────────────────────────────────────
hdr "Services"
UNITS_CHANGED=()
for unit in "$SRC"/*.service "$SRC"/*.timer; do
    u=$(basename "$unit"); live="/etc/systemd/system/$u"
    [[ -f "$live" ]] || continue
    # Only units the dashboard's installer set up (they run from this folder)
    ours_unit "$u" || { say "${DIM}$u: your own unit (not run from $WEB_ROOT) — left as it is${NC}"; continue; }
    kept "/etc/systemd/system/$u" && { say "${DIM}$u: on your keep list — left as it is${NC}"; continue; }
    want=$(unit_want "$unit")
    if [[ "$want" != "$(cat "$live")" ]]; then
        mkdir -p "$BK/etc/systemd"; cp -p "$live" "$BK/etc/systemd/"
        printf '%s\n' "$want" > "$live"; UNITS_CHANGED+=("${u}"); ok "Updated $u"
    fi
done
(( ${#UNITS_CHANGED[@]} )) && systemctl daemon-reload
# New optional units this release adds, when config.php already asks for them
if [[ "$(php_cfg vara_remote enabled)" == "1" && ! -f /etc/systemd/system/vara-remote.timer && -f "$WEB_ROOT/vara-remote.timer" ]]; then
    sed "s|/var/www/bpq-dashboard|$WEB_ROOT|g; s|^User=www-data$|User=$WEB_USER|; s|^Group=www-data$|Group=$WEB_GROUP|" "$WEB_ROOT/vara-remote.service" > /etc/systemd/system/vara-remote.service
    cp "$WEB_ROOT/vara-remote.timer" /etc/systemd/system/
    echo vara-remote.service >> "$BK/units-added.txt"; echo vara-remote.timer >> "$BK/units-added.txt"
    systemctl daemon-reload; systemctl enable --now vara-remote.timer >/dev/null 2>&1 && ok "vara-remote.timer installed (config.php vara_remote is on)"
fi
# Restart a running helper only if its program, a module it uses, or its unit changed
declare -A DEPS=(
    [bpq-telnet]="scripts/bpq-telnet-daemon.py scripts/yapp_protocol.py"
    [bpq-chat]="scripts/bpq-chat-daemon.py scripts/print-bbs-config.php"
    [bpq-aprs]="scripts/bpq-aprs-daemon.py scripts/bpq_db_config.py"
    [bpq-vara]="scripts/bpq-vara-daemon.py scripts/print-bbs-config.php"
    [vara-logger]="vara-logger.sh"
    [vara-validator]="scripts/vara-callsign-validator.py scripts/bpq_db_config.py"
    [rig-history-poller]="scripts/rig-history-poller.php vara-api.php"
)
RESTARTED=()
for svc in "${!DEPS[@]}"; do
    systemctl is-active --quiet "$svc" 2>/dev/null || continue
    need=n
    for f in ${DEPS[$svc]}; do [[ " ${CHANGED[*]:-} " == *" $f "* ]] && need=y; done
    [[ " ${UNITS_CHANGED[*]:-} " == *" $svc.service "* ]] && need=y
    # a unit that doesn't run from this folder (e.g. an older vara-validator) isn't ours to restart
    grep -q -- "$WEB_ROOT" "/etc/systemd/system/$svc.service" 2>/dev/null || need=n
    if [[ $need == y ]]; then
        if systemctl restart "$svc"; then sleep 1; systemctl is-active --quiet "$svc" && RESTARTED+=("$svc") || fail "$svc did not come back: sudo journalctl -u $svc -n 30"
        else fail "could not restart $svc"; fi
    fi
done
(( ${#RESTARTED[@]} )) && ok "Restarted: ${RESTARTED[*]}" || ok "No helper service needed a restart"

# ── Sudo rules (only if the installer's file is there) ─────────────────────
SUDO_LIVE=/etc/sudoers.d/www-data-tools
if [[ -f "$SUDO_LIVE" && -f "$SRC/www-data-tools.sudoers" ]] && ! kept "$SUDO_LIVE"; then
    TMP=$(mktemp); sudo_want > "$TMP"
    if ! cmp -s "$TMP" "$SUDO_LIVE"; then
        if visudo -cf "$TMP" >/dev/null 2>&1; then
            mkdir -p "$BK/etc/sudoers.d"; cp -p "$SUDO_LIVE" "$BK/etc/sudoers.d/"
            install -m 440 -o root -g root "$TMP" "$SUDO_LIVE" && ok "Sudo rules updated ($SUDO_LIVE)"
        else warn "The new www-data-tools.sudoers didn't pass visudo -c — left the old one"; fi
    fi
    rm -f "$TMP"
fi
if grep -qs 'bpq-dashboard-set-admin-password' /etc/sudoers.d/* && [[ -f "$SRC/tools/set-admin-password" ]]; then
    H=/usr/local/sbin/bpq-dashboard-set-admin-password
    if ! cmp -s "$SRC/tools/set-admin-password" "$H"; then
        if [[ -e "$H" ]]; then cp -p "$H" "$BK/etc/"; else echo "$H" >> "$BK/new-root-files.txt"; fi
        install -o root -g root -m 755 "$SRC/tools/set-admin-password" "$H" && ok "Admin-password helper updated ($H)"
    fi
fi

# ── Record the version ─────────────────────────────────────────────────────
install -o "$WEB_USER" -g "$WEB_GROUP" -m 644 "$SRC/MANIFEST.md5" "$WEB_ROOT/MANIFEST.md5"
[[ -f "$WEB_ROOT/VERSION" ]] || install -o "$WEB_USER" -g "$WEB_GROUP" -m 644 "$SRC/VERSION" "$WEB_ROOT/VERSION"
ok "Installed version recorded: $(cat "$WEB_ROOT/VERSION")"

# ── Done ───────────────────────────────────────────────────────────────────
hdr "Done"
if (( ${#FAILS[@]} )); then
    echo "${RED}${BOLD}Updated to $NEW_VER with problems:${NC}"; for e in "${FAILS[@]}"; do say "- $e"; done
else
    echo "${GRN}${BOLD}Updated $OLD_VER → $NEW_VER.${NC}"
fi
(( ${#WARNS[@]} )) && { echo "${YLW}Worth a look:${NC}"; for w in "${WARNS[@]}"; do say "- $w"; done; }
cat <<EOF

  Next: open each page once with ${BOLD}Ctrl+Shift+R${NC} (the menu is cached), then
  ${BOLD}Install Check${NC} (/install-check.php) and ${BOLD}Admin → System Audit${NC}.
  What's new: CHANGELOG.md, section $NEW_VER.

  Backup and log: $BK
  Undo:           sudo bash $SRC/update.sh --rollback $BK
EOF
# v1.5.42: point out the optional station backup until something reports one
if [[ ! -r /etc/bpq-dashboard/backup.conf && ! -s /var/lib/bpq-backup/status.json ]]; then
    cat <<EOF

  ${YLW}Optional, recommended:${NC} this station has no nightly backup. With a backup
  drive mounted: ${BOLD}sudo bash $WEB_ROOT/scripts/bpq-backup-setup.sh${NC}  (Manual §10.16)
EOF
fi
