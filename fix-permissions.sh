#!/bin/bash
# ============================================================================
#  BPQ Dashboard — Permissions Fixer
#  ────────────────────────────────────────────────────────────────────────
#  Re-applies the same ownership/permission scheme install.sh sets up on
#  first install (Step 7 + Step 8's config.php lockdown + Step 12's final
#  sweep) — safe to run any time you want to confirm nothing has drifted,
#  e.g. after manually deploying files with `sudo cp` instead of rsync.
#
#  HOW TO RUN:
#    sudo bash fix-permissions.sh
#
#  To use a different web root or web server user than the defaults below,
#  set environment variables instead of editing this file:
#    sudo WEB_ROOT=/var/www/mydash WEB_USER=www-data bash fix-permissions.sh
#
#  --dry-run shows exactly what would change without touching anything:
#    sudo bash fix-permissions.sh --dry-run
# ============================================================================

set -u

# ────────────────────────────────────────────────────────────────────────────
# Config — override via environment variables, not by editing this file,
# so re-downloading an updated copy later doesn't clobber your settings.
# ────────────────────────────────────────────────────────────────────────────
WEB_ROOT="${WEB_ROOT:-/var/www/bpq-dashboard}"
WEB_USER="${WEB_USER:-www-data}"
DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

# ────────────────────────────────────────────────────────────────────────────
# Colour codes (same convention as install.sh)
# ────────────────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GRN=$'\033[0;32m'; YLW=$'\033[0;33m'
    CYN=$'\033[0;36m'; WHT=$'\033[1;37m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    RED=''; GRN=''; YLW=''; CYN=''; WHT=''; BOLD=''; NC=''
fi

stamp() { date '+%H:%M:%S'; }
say()   { echo "$(stamp) ${WHT}[ .. ]${NC} $*"; }
ok()    { echo "$(stamp) ${GRN}[ OK ]${NC} $*"; }
warn()  { echo "$(stamp) ${YLW}[WARN]${NC} $*"; }
err()   { echo "$(stamp) ${RED}[FAIL]${NC} $*"; }
hdr()   { echo; echo "${BOLD}${CYN}═══ $* ═══${NC}"; }

run() {
    # Executes (or, under --dry-run, just prints) the command passed as args.
    if [[ "$DRY_RUN" == "1" ]]; then
        echo "  ${YLW}[dry-run]${NC} $*"
    else
        "$@"
    fi
}

# ────────────────────────────────────────────────────────────────────────────
# Pre-flight checks
# ────────────────────────────────────────────────────────────────────────────
hdr "Pre-flight checks"

if [[ $EUID -ne 0 ]]; then
    err "Must be run with sudo/root (needs to chown files to $WEB_USER)."
    exit 1
fi
ok "Running as root"

if [[ ! -d "$WEB_ROOT" ]]; then
    err "WEB_ROOT does not exist: $WEB_ROOT"
    echo "  Set it explicitly if your install lives somewhere else, e.g.:"
    echo "    sudo WEB_ROOT=/var/www/mydash bash $(basename "$0")"
    exit 1
fi
ok "Found web root: $WEB_ROOT"

if ! id "$WEB_USER" &>/dev/null; then
    err "User '$WEB_USER' does not exist on this system."
    echo "  Set WEB_USER explicitly if your web server runs as someone else."
    exit 1
fi
ok "Found web user: $WEB_USER"

[[ "$DRY_RUN" == "1" ]] && warn "DRY RUN — no changes will be made"

# ────────────────────────────────────────────────────────────────────────────
# Step 1 — Ownership
# ────────────────────────────────────────────────────────────────────────────
hdr "Step 1 — Ownership"
say "Setting $WEB_ROOT recursively to $WEB_USER:$WEB_USER"
run chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT" && ok "Ownership set"

# ────────────────────────────────────────────────────────────────────────────
# Step 2 — Baseline sweep: directories 755, files 644
# ────────────────────────────────────────────────────────────────────────────
hdr "Step 2 — Baseline permissions (dirs 755, files 644)"
say "Directories → 755 (owner rwx, everyone else read+traverse)"
run find "$WEB_ROOT" -type d -exec chmod 755 {} \; && ok "Directories set to 755"

say "Regular files → 644 (owner rw, everyone else read-only)"
run find "$WEB_ROOT" -type f -exec chmod 644 {} \; && ok "Files set to 644"

# ────────────────────────────────────────────────────────────────────────────
# Step 3 — Executable scripts
# ────────────────────────────────────────────────────────────────────────────
hdr "Step 3 — Executable scripts (.sh / .py) → 755"
run find "$WEB_ROOT" -name "*.sh" -exec chmod 755 {} \; && ok "Shell scripts executable"
run find "$WEB_ROOT" -name "*.py" -exec chmod 755 {} \; && ok "Python scripts executable"

# ────────────────────────────────────────────────────────────────────────────
# Step 4 — Lock down config.php (holds the DB password in plaintext)
# ────────────────────────────────────────────────────────────────────────────
hdr "Step 4 — config.php lockdown"
CFG="$WEB_ROOT/config.php"
if [[ -f "$CFG" ]]; then
    run chmod 640 "$CFG"
    run chown "$WEB_USER:$WEB_USER" "$CFG"
    ok "config.php set to 640 (owner+group only — not world-readable)"
else
    warn "config.php not found at $CFG — skipping (nothing to tighten)"
fi

# ────────────────────────────────────────────────────────────────────────────
# Step 5 — Writable runtime directories (cache, data, YAPP downloads, logs)
# ────────────────────────────────────────────────────────────────────────────
hdr "Step 5 — Writable runtime directories → 750"
for d in cache data yapp-files logs; do
    DIR="$WEB_ROOT/$d"
    if [[ -d "$DIR" ]]; then
        run chown -R "$WEB_USER:$WEB_USER" "$DIR"
        run chmod 750 "$DIR"
        ok "$d/ set to 750 (was 755 — these hold generated/log content, no reason for 'everyone else' to browse it)"
    else
        say "$d/ not present — skipping"
    fi
done

# ────────────────────────────────────────────────────────────────────────────
# Summary
# ────────────────────────────────────────────────────────────────────────────
hdr "Done"
if [[ "$DRY_RUN" == "1" ]]; then
    echo "Dry run complete — nothing was changed. Re-run without --dry-run to apply."
else
    echo "Permissions restored to the standard scheme for $WEB_ROOT."
    echo "Re-run this any time after a manual deploy if you're unsure things drifted."
fi
