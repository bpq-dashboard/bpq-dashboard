#!/usr/bin/env bash
# =============================================================================
# tools/system-check.sh — is this computer ready for BPQ Dashboard? (v1.5.102)
#
# Used by install.sh (before anything is installed) and update.sh (each
# update), and on its own:   sudo bash tools/system-check.sh   (reports only)
#
#   1. Which OS and release this is (/etc/os-release), and where it stands:
#      supported, older but supported, newer than tested, interim, or out of
#      support. Debian, Ubuntu, Raspberry Pi OS, Linux Mint / LMDE and other
#      Debian- or Ubuntu-based systems are recognised by what they are built on.
#   2. The versions the dashboard needs: what is installed, else what apt
#      would install — PHP >= 8.0, Python >= 3.8, MariaDB >= 10.3 (MySQL >= 8.0)
#      are required; nginx / Apache and Dovecot are reported.
#      The OS's own packages are used on purpose: they get security fixes for
#      the life of the release. Nothing is fetched from outside repositories.
#   3. The platform: processor (VARA needs an x86 PC under Wine), memory, disk.
#   4. Updates waiting (security ones counted apart), and whether security
#      updates install by themselves (unattended-upgrades).
#   5. Writes /etc/bpq-dashboard/system.json for Install Check.
#
# Tests: SYSCHECK_OS_RELEASE=<file> stands in for /etc/os-release,
# SYSCHECK_RPI_ISSUE=<file> for /etc/rpi-issue, SYSCHECK_STATE=<dir> for
# /etc/bpq-dashboard, and stub commands earlier in PATH for apt-cache etc.
# =============================================================================

# Output helpers: the installer's if it has them, else plain ones.
declare -F ok   >/dev/null || ok()   { echo "  ✓ $*"; }
declare -F warn >/dev/null || warn() { echo "  ⚠ $*"; }
declare -F say  >/dev/null || say()  { echo "  $*"; }
declare -F explain >/dev/null || explain() { echo "      $*"; }

SC_MIN_PHP=8.0; SC_MIN_PY=3.8; SC_MIN_MARIADB=10.3; SC_MIN_MYSQL=8.0
SC_BLOCK=()          # reasons the install can't go on
SC_WARN=()           # things the sysop should know

# version a >= version b ?
sc_ge() { [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1)" == "$2" ]]; }
# "1:10.11.6-0+deb12u1" / "2.3.19.1+dfsg1-2" -> "10.11.6" / "2.3.19.1"
sc_clean() { sed -E 's/^[0-9]+://; s/^([0-9]+(\.[0-9]+)*).*/\1/' <<<"$1"; }
sc_candidate() { apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/ {print $2; exit}' | grep -v '(none)'; }

# ── 1. the OS ────────────────────────────────────────────────────────────────
sc_os() {
    local f="${SYSCHECK_OS_RELEASE:-/etc/os-release}" k v
    declare -gA OSR=()
    if [[ -r "$f" ]]; then
        while IFS='=' read -r k v; do
            [[ "$k" =~ ^[A-Z_]+$ ]] || continue
            v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
            OSR[$k]="$v"
        done < "$f"
    fi
    SC_ID="${OSR[ID]:-unknown}"; SC_LIKE="${OSR[ID_LIKE]:-}"
    SC_PRETTY="${OSR[PRETTY_NAME]:-${OSR[NAME]:-unknown} ${OSR[VERSION_ID]:-}}"
    SC_BASE=""; SC_BASEVER=""; SC_STATUS="unknown"; SC_NOTE=""
    debian_num() { case "$1" in bullseye) echo 11;; bookworm) echo 12;; trixie) echo 13;; forky) echo 14;; duke) echo 15;; *) echo "";; esac; }
    ubuntu_num() { case "$1" in focal) echo 20.04;; jammy) echo 22.04;; noble) echo 24.04;; oracular) echo 24.10;; plucky) echo 25.04;; questing) echo 25.10;; *) echo "";; esac; }
    local code="${OSR[VERSION_CODENAME]:-}" ucode="${OSR[UBUNTU_CODENAME]:-}" dcode="${OSR[DEBIAN_CODENAME]:-}"
    case "$SC_ID" in
        debian|raspbian)
            SC_BASE=debian; SC_BASEVER="${OSR[VERSION_ID]:-$(debian_num "$code")}"
            [[ "$SC_ID" == raspbian || -e "${SYSCHECK_RPI_ISSUE:-/etc/rpi-issue}" ]] && SC_PRETTY="Raspberry Pi OS (${code:-Debian $SC_BASEVER})$([[ $SC_ID == raspbian ]] && echo ', 32-bit')"
            [[ -z "$SC_BASEVER" && -n "$code" ]] && SC_BASEVER="$code" ;;
        ubuntu)
            SC_BASE=ubuntu; SC_BASEVER="${OSR[VERSION_ID]:-$(ubuntu_num "$code")}" ;;
        *)
            if [[ -n "$ucode" ]]; then SC_BASE=ubuntu; SC_BASEVER="$(ubuntu_num "$ucode")"; [[ -z "$SC_BASEVER" ]] && SC_BASEVER="$ucode"
            elif [[ -n "$dcode" ]]; then SC_BASE=debian; SC_BASEVER="$(debian_num "$dcode")"; [[ -z "$SC_BASEVER" ]] && SC_BASEVER="$dcode"
            elif [[ " $SC_LIKE " == *" ubuntu "* ]]; then SC_BASE=ubuntu; SC_BASEVER="$(ubuntu_num "$code")"
            elif [[ " $SC_LIKE " == *" debian "* ]]; then SC_BASE=debian; SC_BASEVER="$(debian_num "$code")"; [[ -z "$SC_BASEVER" ]] && SC_BASEVER="${OSR[VERSION_ID]:-}"
            fi ;;
    esac
    local major="${SC_BASEVER%%.*}"
    if [[ "$SC_BASE" == debian ]]; then
        SC_BASENAME="Debian $SC_BASEVER"
        if   [[ "$major" =~ ^[0-9]+$ ]] && (( major <= 11 )); then SC_STATUS=eol;  SC_NOTE="Debian $SC_BASEVER is past its support, and its PHP (7.4) is too old for the dashboard. Upgrade to Debian 12 or 13 (or Raspberry Pi OS Bookworm / Trixie) first."
        elif [[ "$major" == 12 || "$major" == 13 ]]; then SC_STATUS=supported
        elif [[ "$major" =~ ^[0-9]+$ ]]; then SC_STATUS=newer; SC_NOTE="Newer than the releases the dashboard was tested on (Debian 12 and 13). It should work; the version checks decide."
        else SC_STATUS=newer; SC_NOTE="Debian testing/unstable ($SC_BASEVER): changes daily and isn't tested. It should work; the version checks decide."
        fi
    elif [[ "$SC_BASE" == ubuntu ]]; then
        SC_BASENAME="Ubuntu $SC_BASEVER"
        local yy="${SC_BASEVER%%.*}" mm="${SC_BASEVER#*.}"
        if ! [[ "$yy" =~ ^[0-9]+$ ]]; then SC_STATUS=newer; SC_NOTE="Ubuntu $SC_BASEVER: not a release this check knows. The version checks below decide."
        elif (( yy < 22 )); then SC_STATUS=eol; SC_NOTE="Ubuntu $SC_BASEVER is past its standard support, and its PHP (7.4 or older) is too old for the dashboard. Upgrade to Ubuntu 22.04 or 24.04 (Linux Mint 21 or 22) first."
        elif [[ "$SC_BASEVER" == 22.04 ]]; then SC_STATUS=older; SC_NOTE="Ubuntu 22.04 (Linux Mint 21) is supported, and gets security updates until April 2027. Moving to 24.04 (Mint 22) in the next year or so is a good idea."
        elif [[ "$SC_BASEVER" == 24.04 ]]; then SC_STATUS=supported
        elif [[ "$mm" != 04 ]] || (( yy % 2 )); then SC_STATUS=interim; SC_NOTE="Ubuntu $SC_BASEVER is an interim release with only 9 months of updates. It should work, but an LTS release (24.04) is better for a station that runs all the time."
        else SC_STATUS=newer; SC_NOTE="Newer than the releases the dashboard was tested on (Ubuntu 22.04 and 24.04). It should work; the version checks decide."
        fi
    elif command -v apt-get >/dev/null 2>&1; then
        SC_BASENAME="unknown"; SC_STATUS=unknown; SC_NOTE="This system uses apt, but isn't one this check recognises. It may work; the version checks decide."
    else
        SC_BASENAME="not apt-based"; SC_STATUS=unsupported; SC_NOTE="This installer needs a Debian- or Ubuntu-based system (one that uses apt)."
    fi
    [[ "$SC_STATUS" == eol || "$SC_STATUS" == unsupported ]] && SC_BLOCK+=("$SC_NOTE")
    case "$SC_STATUS" in newer|interim|unknown|older) SC_WARN+=("$SC_NOTE");; esac
}

# ── 2. the programs' versions (installed, else what apt would install) ─────
sc_versions() {
    local v
    # PHP
    if command -v php >/dev/null 2>&1; then
        SC_PHP=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION;' 2>/dev/null); SC_PHP_SRC=installed
    else
        v=$(apt-cache depends php-fpm 2>/dev/null | grep -oE 'php[0-9]+\.[0-9]+-fpm' | head -1 | grep -oE '[0-9]+\.[0-9]+')
        SC_PHP="$v"; SC_PHP_SRC=apt
    fi
    # Python 3
    if command -v python3 >/dev/null 2>&1; then
        SC_PY=$(python3 -c 'import sys; print("%d.%d.%d" % sys.version_info[:3])' 2>/dev/null); SC_PY_SRC=installed
    else SC_PY=$(sc_clean "$(sc_candidate python3)"); SC_PY_SRC=apt; fi
    # Database
    SC_DBKIND=MariaDB
    if command -v mariadb >/dev/null 2>&1 || command -v mysql >/dev/null 2>&1; then
        v=$( (mariadb --version 2>/dev/null || mysql --version 2>/dev/null) | head -1)
        if [[ "$v" == *MariaDB* ]]; then SC_DB=$(grep -oE '[0-9]+\.[0-9]+\.[0-9]+-MariaDB' <<<"$v" | head -1 | cut -d- -f1)
        else SC_DBKIND=MySQL; SC_DB=$(grep -oE 'Ver [0-9]+\.[0-9]+\.[0-9]+' <<<"$v" | head -1 | cut -d' ' -f2); fi
        SC_DB_SRC=installed
    else SC_DB=$(sc_clean "$(sc_candidate mariadb-server)"); SC_DB_SRC=apt; fi
    # Web server
    if command -v nginx >/dev/null 2>&1; then SC_WEB="nginx $(nginx -v 2>&1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"; SC_WEB_SRC=installed
    elif command -v apache2 >/dev/null 2>&1; then SC_WEB="Apache $(apache2 -v 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"; SC_WEB_SRC=installed
    else SC_WEB="nginx $(sc_clean "$(sc_candidate nginx)")"; SC_WEB_SRC=apt; fi
    # Dovecot (BBS Mail's mail store, optional)
    if command -v dovecot >/dev/null 2>&1; then SC_DOVECOT=$(dovecot --version 2>/dev/null | grep -oE '^[0-9]+(\.[0-9]+)+'); SC_DOVECOT_SRC=installed
    else SC_DOVECOT=$(sc_clean "$(sc_candidate dovecot-core)"); SC_DOVECOT_SRC=apt; fi

    [[ -z "$SC_PHP" ]] && SC_BLOCK+=("PHP isn't available from this system's package sources (no php-fpm). Check /etc/apt/sources.list, then: sudo apt update")
    [[ -n "$SC_PHP" ]] && ! sc_ge "$SC_PHP" "$SC_MIN_PHP" && SC_BLOCK+=("PHP $SC_PHP is too old: the dashboard needs PHP $SC_MIN_PHP or newer.")
    [[ -z "$SC_PY" ]] && SC_BLOCK+=("Python 3 isn't available from this system's package sources.")
    [[ -n "$SC_PY" ]] && ! sc_ge "$SC_PY" "$SC_MIN_PY" && SC_BLOCK+=("Python $SC_PY is too old: the dashboard needs Python $SC_MIN_PY or newer.")
    if [[ -z "$SC_DB" ]]; then SC_BLOCK+=("MariaDB isn't available from this system's package sources.")
    elif [[ "$SC_DBKIND" == MySQL ]]; then ! sc_ge "$SC_DB" "$SC_MIN_MYSQL" && SC_BLOCK+=("MySQL $SC_DB is too old: the dashboard needs MariaDB $SC_MIN_MARIADB or MySQL $SC_MIN_MYSQL or newer.")
    else ! sc_ge "$SC_DB" "$SC_MIN_MARIADB" && SC_BLOCK+=("MariaDB $SC_DB is too old: the dashboard needs $SC_MIN_MARIADB or newer."); fi
    [[ -n "$SC_DOVECOT" ]] && ! sc_ge "$SC_DOVECOT" 2.3 && SC_WARN+=("Dovecot $SC_DOVECOT is older than 2.3: BBS Mail's mail store needs 2.3 or 2.4 (the rest of the dashboard works without it).")
    return 0
}

# ── 3. the platform ──────────────────────────────────────────────────────────
sc_platform() {
    SC_ARCH=$(dpkg --print-architecture 2>/dev/null || uname -m)
    SC_MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null)
    SC_DISK_MB=$(df -Pm / 2>/dev/null | awk 'NR==2 {print $4}')
    SC_VAR_MB=$(df -Pm /var 2>/dev/null | awk 'NR==2 {print $4}')
    case "$SC_ARCH" in
        amd64|x86_64) ;;
        arm64|aarch64) SC_WARN+=("ARM computer ($SC_ARCH, e.g. a Raspberry Pi): the dashboard runs fine. VARA HF itself needs an x86 PC (it runs under Wine): run VARA on another computer and point LinBPQ's VARA port at it. Mercury and Data2G need their ARM builds.");;
        armhf|armel|armv7l) SC_WARN+=("32-bit ARM ($SC_ARCH, e.g. 32-bit Raspberry Pi OS): the dashboard runs, but some modem programs only come for 64-bit. The 64-bit Raspberry Pi OS is the better choice on a Pi 3, 4 or 5. VARA HF needs an x86 PC.");;
        i386|i686) SC_WARN+=("32-bit PC ($SC_ARCH): newer releases of Debian and Ubuntu no longer support it; a 64-bit system is recommended.");;
    esac
    [[ -n "$SC_MEM_MB" ]] && (( SC_MEM_MB < 900 )) && SC_WARN+=("Only ${SC_MEM_MB} MB of memory: the web server, PHP and MariaDB together want about 1 GB. Pages may be slow.")
    local free="$SC_DISK_MB"; [[ -n "$SC_VAR_MB" && -n "$free" ]] && (( SC_VAR_MB < free )) && free="$SC_VAR_MB"
    if [[ -n "$free" ]]; then
        if (( free < 500 )); then SC_BLOCK+=("Only ${free} MB of free disk space: the dashboard, its database and logs need at least 500 MB (2 GB or more recommended).")
        elif (( free < 2048 )); then SC_WARN+=("${free} MB of free disk space: enough to install, but logs and the message archive grow; 2 GB or more is recommended."); fi
    fi
}

# ── 4. updates ───────────────────────────────────────────────────────────────
sc_updates() {
    local sim
    sim=$(apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | grep '^Inst ')
    SC_UPD_ALL=$(grep -c . <<<"$sim"); [[ -z "$sim" ]] && SC_UPD_ALL=0
    SC_UPD_SEC=$(grep -ciE 'security' <<<"$sim"); [[ -z "$sim" ]] && SC_UPD_SEC=0
    SC_AUTO=no
    if dpkg -s unattended-upgrades >/dev/null 2>&1; then
        SC_AUTO=installed
        apt-config dump 2>/dev/null | grep -qE '^APT::Periodic::Unattended-Upgrade "(1|always)"' && SC_AUTO=on
    fi
    SC_REBOOT=no; [[ -e /var/run/reboot-required ]] && SC_REBOOT=yes
}

# Offer to install the waiting updates (install.sh only: nothing runs yet).
sc_offer_updates() {
    (( SC_UPD_ALL > 0 )) || { ok "All of this system's packages are up to date"; return 0; }
    say "$SC_UPD_ALL update(s) are waiting for this system, $SC_UPD_SEC of them security fixes."
    explain "Installing them first means the dashboard starts on a patched system."
    if yesno "Install the waiting updates now (recommended)?" Y; then
        if DEBIAN_FRONTEND=noninteractive apt-get -y -q -o Dpkg::Options::=--force-confold upgrade >/dev/null 2>&1; then
            ok "Updates installed"; SC_UPD_ALL=0; SC_UPD_SEC=0
            [[ -e /var/run/reboot-required ]] && { SC_REBOOT=yes; warn "A restart is needed to finish (a new kernel or core library). Finish this install, then: sudo reboot"; }
        else
            warn "Some updates didn't install (sudo apt upgrade shows why). Carrying on."
        fi
    else
        warn "Updates left for later: sudo apt update && sudo apt upgrade"
    fi
}

# Offer automatic security updates (install.sh; update.sh only suggests it).
sc_offer_auto() {
    [[ "$SC_AUTO" == on ]] && { ok "Security updates install by themselves (unattended-upgrades)"; return 0; }
    say "Security updates don't install by themselves on this system yet."
    explain "unattended-upgrades installs the OS's security fixes once a day, on its own."
    explain "It changes only security updates of the OS's own packages, never the dashboard."
    if yesno "Turn on automatic security updates (recommended)?" Y; then
        if { dpkg -s unattended-upgrades >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive apt-get install -y -q unattended-upgrades >/dev/null 2>&1; } && \
           printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > "${SYSCHECK_APTCONF:-/etc/apt/apt.conf.d}/20auto-upgrades"; then
            SC_AUTO=on; ok "Automatic security updates on (/etc/apt/apt.conf.d/20auto-upgrades)"
        else warn "Couldn't turn them on: sudo apt install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades"; fi
    else
        say "Left off. To turn them on later: sudo apt install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades"
    fi
}

# ── 5. report and record ─────────────────────────────────────────────────────
sc_summary() {
    local st
    case "$SC_STATUS" in supported) st="supported";; older) st="supported (older)";; newer) st="newer than tested";;
        interim) st="interim release";; eol) st="OUT OF SUPPORT";; *) st="not recognised";; esac
    say "System:   $SC_PRETTY  —  based on $SC_BASENAME, $st"
    say "Platform: $SC_ARCH, ${SC_MEM_MB:-?} MB memory, ${SC_DISK_MB:-?} MB free on /"
    src() { [[ "$1" == apt ]] && echo " (to install)" || echo ""; }
    say "PHP ${SC_PHP:-none}$(src $SC_PHP_SRC) · Python ${SC_PY:-none}$(src $SC_PY_SRC) · $SC_DBKIND ${SC_DB:-none}$(src $SC_DB_SRC) · ${SC_WEB}$(src $SC_WEB_SRC) · Dovecot ${SC_DOVECOT:-none}$(src $SC_DOVECOT_SRC)"
    [[ -n "${SC_UPD_ALL:-}" ]] && say "Updates waiting: $SC_UPD_ALL ($SC_UPD_SEC security) · automatic security updates: $SC_AUTO$([[ $SC_REBOOT == yes ]] && echo ' · restart needed')"
}
sc_record() {
    local dir="${SYSCHECK_STATE:-/etc/bpq-dashboard}"
    mkdir -p "$dir" 2>/dev/null || return 0
    SC_JSON_OS="$SC_PRETTY" SC_JSON_BASE="$SC_BASENAME" SC_JSON_ST="$SC_STATUS" SC_JSON_NOTE="$SC_NOTE" \
    python3 - "$dir/system.json" <<'PY' 2>/dev/null && chmod 644 "$dir/system.json" 2>/dev/null
import json, os, sys, time
e = os.environ
g = lambda k: e.get(k, '')
d = {'checked_at': time.strftime('%Y-%m-%d %H:%M:%SZ', time.gmtime()), 'os': g('SC_JSON_OS'), 'based_on': g('SC_JSON_BASE'),
     'status': g('SC_JSON_ST'), 'note': g('SC_JSON_NOTE'), 'arch': g('SC_ARCH'),
     'memory_mb': g('SC_MEM_MB'), 'disk_free_mb': g('SC_DISK_MB'),
     'php': g('SC_PHP'), 'python': g('SC_PY'), 'database': (g('SC_DBKIND') + ' ' + g('SC_DB')).strip(), 'web': g('SC_WEB'),
     'dovecot': g('SC_DOVECOT'), 'updates': g('SC_UPD_ALL'), 'security_updates': g('SC_UPD_SEC'),
     'auto_updates': g('SC_AUTO'), 'reboot_needed': g('SC_REBOOT') == 'yes'}
json.dump(d, open(sys.argv[1], 'w'), indent=1)
PY
}
export_sc() { export SC_ARCH SC_MEM_MB SC_DISK_MB SC_PHP SC_PY SC_DBKIND SC_DB SC_WEB SC_DOVECOT SC_UPD_ALL SC_UPD_SEC SC_AUTO SC_REBOOT; }

# Run all checks (no changes). Returns 1 if something blocks the install.
system_check() {
    SC_BLOCK=(); SC_WARN=()
    sc_os; sc_versions; sc_platform; sc_updates
    sc_summary
    local w b
    for w in "${SC_WARN[@]}"; do warn "$w"; done
    for b in "${SC_BLOCK[@]}"; do warn "BLOCKING: $b"; done
    export_sc
    (( ${#SC_BLOCK[@]} == 0 ))
}

# On its own: report, record, change nothing.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    echo "BPQ Dashboard system check"
    if system_check; then rc=0; echo; echo "  Ready for BPQ Dashboard."; else rc=1; echo; echo "  Not ready: see BLOCKING above."; fi
    [[ $EUID -eq 0 ]] && sc_record
    [[ "$SC_AUTO" != on ]] && echo "  Tip: automatic security updates are off. sudo apt install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades"
    exit $rc
fi
