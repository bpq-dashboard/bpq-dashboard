#!/bin/bash
# ============================================================================
#  BPQ Dashboard v1.5.15 — Guided Installer for Amateur Radio Operators
#  ────────────────────────────────────────────────────────────────────────
#  For Debian / Ubuntu / Raspberry Pi OS (apt-based systems only)
#
#  This installer is written for hams who may have never used Linux.
#  It explains what it is doing at every step and asks before making
#  changes. Press ENTER at any prompt to accept the suggested default
#  shown in [brackets].
#
#  HOW TO RUN:
#    1.  Open a terminal on your Linux server
#    2.  cd into the folder where you unzipped this archive
#    3.  Run:    sudo bash install.sh
#
#  IF SOMETHING GOES WRONG:
#    The installer writes a full log to /tmp/bpq-dashboard-install-*.log
#    Send that log if you ask for help on the BPQ mailing list.
# ============================================================================

set -u   # error on unset variables (catches typos)
# Do NOT set -e — we want to keep going through optional steps even if one
# fails, and report a summary at the end.

# ────────────────────────────────────────────────────────────────────────────
# Colour codes — make the output readable
# ────────────────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GRN=$'\033[0;32m'; YLW=$'\033[0;33m'
    BLU=$'\033[0;34m'; CYN=$'\033[0;36m'; WHT=$'\033[1;37m'
    BOLD=$'\033[1m';   DIM=$'\033[2m';   NC=$'\033[0m'
else
    RED=''; GRN=''; YLW=''; BLU=''; CYN=''; WHT=''; BOLD=''; DIM=''; NC=''
fi

# ────────────────────────────────────────────────────────────────────────────
# Logging and counters
# ────────────────────────────────────────────────────────────────────────────
LOG="/tmp/bpq-dashboard-install-$(date +%Y%m%d-%H%M%S).log"
PASS=0; WARN=0; FAIL=0
WARNINGS=()
ERRORS=()
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Append every echo to the log too
exec > >(tee -a "$LOG") 2>&1

stamp()  { date '+%H:%M:%S'; }
say()    { echo "$(stamp) ${WHT}[ .. ]${NC} $*"; }
ok()     { echo "$(stamp) ${GRN}[ OK ]${NC} $*";   ((PASS++)); }
warn()   { echo "$(stamp) ${YLW}[WARN]${NC} $*";   ((WARN++)); WARNINGS+=("$*"); }
err()    { echo "$(stamp) ${RED}[FAIL]${NC} $*";   ((FAIL++)); ERRORS+=("$*"); }
hdr()    { echo; echo "${BOLD}${CYN}═══ $* ═══${NC}"; }
ask()    { echo; echo "${BOLD}${YLW}▶ $*${NC}"; }
explain(){ echo "${DIM}   $*${NC}"; }
yesno()  { local d="${2:-N}"; local p="[y/N]"; [[ "$d" == "Y" ]] && p="[Y/n]"
           read -r -p "  $1 $p: " _yn
           if [[ "$d" == "Y" ]]; then [[ ! "$_yn" =~ ^[Nn] ]]; \
                                 else [[   "$_yn" =~ ^[Yy] ]]; fi }
die()    { echo; echo "${RED}${BOLD}FATAL: $*${NC}"; echo "Log: $LOG"; exit 1; }

# ────────────────────────────────────────────────────────────────────────────
# Pre-flight checks
# ────────────────────────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && die "This script needs to run as root. Please re-run:
   sudo bash install.sh"

# Confirm we're on a Debian-family system
if ! command -v apt-get >/dev/null 2>&1; then
    die "This installer only supports Debian / Ubuntu / Raspberry Pi OS systems
   (systems that use the 'apt' package manager).

   You appear to be on a different distribution. The dashboard itself
   will work, but you'll need to install nginx, PHP-FPM, Python 3, and
   MariaDB by hand using your distribution's package manager."
fi

# Confirm we can find the dashboard files
if [[ ! -f "$SCRIPT_DIR/config.php.example" ]] || \
   [[ ! -f "$SCRIPT_DIR/bbs-messages.html" ]]; then
    die "Could not find the dashboard files. Are you running this script
   from the folder where you unzipped BPQ-Dashboard-v1.5.15.zip?

   Current folder: $SCRIPT_DIR"
fi

# ────────────────────────────────────────────────────────────────────────────
# Welcome banner
# ────────────────────────────────────────────────────────────────────────────
clear
cat << 'BANNER'
╔══════════════════════════════════════════════════════════════════════╗
║                                                                      ║
║             BPQ Dashboard v1.5.15 — Guided Installer                  ║
║              for Amateur Radio Packet Node Operators                 ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
BANNER
cat << EOF

Welcome! This installer will set up the BPQ Dashboard on your Linux
server. It will:

  ${BOLD}1.${NC} Ask you a few questions about your station and node
  ${BOLD}2.${NC} Detect whether you already have a web server installed
       (nginx or Apache). If not, it will install nginx.
  ${BOLD}3.${NC} Install PHP and Python 3 if they aren't already present
  ${BOLD}4.${NC} Install MariaDB, the database behind the Email Client and the
       fast RF Connections / Traffic / System Logs pages (recommended —
       the Email Client does not work without it)
  ${BOLD}5.${NC} Set a login for the admin pages (firewall, logs, audit)
  ${BOLD}6.${NC} Copy the dashboard files to /var/www/bpq-dashboard/
  ${BOLD}7.${NC} Generate a config.php with your station settings
  ${BOLD}8.${NC} Configure the web server (with the private files locked away)
  ${BOLD}9.${NC} Link your LinBPQ logs folder and set up the helper services
 ${BOLD}10.${NC} Schedule the log parsers and the daily bulletin clean-up
 ${BOLD}11.${NC} Show you the web address of your dashboard

${BOLD}Time required:${NC} 5 to 15 minutes depending on your internet speed.

${BOLD}Before you start, have these handy:${NC}

  • Your callsign (e.g. W1AW)
  • Your BPQ node callsign with SSID (e.g. W1AW-4)
  • Your latitude / longitude  ${DIM}(Google Maps → right-click your QTH → copy)${NC}
  • Your BPQ telnet port  ${DIM}(see bpq32.cfg, look for TCPPORT under TELNET)${NC}
  • Your BPQ sysop username and password  ${DIM}(from bpq32.cfg USER= line)${NC}
  • The folder where your bpq32.cfg lives
        ${DIM}(commonly /opt/linbpq or wherever you installed LinBPQ)${NC}

  Logs of this run: ${BLU}$LOG${NC}

EOF
read -r -p "Press ENTER when ready (or Ctrl-C to quit)... "

# ============================================================================
hdr "STEP 1 — Tell me about your station"
# ============================================================================

ask "Your station callsign (e.g. W1AW):"
explain "This is the callsign your station is licensed under."
read -r INPUT_CALL
INPUT_CALL=$(echo "${INPUT_CALL:-YOURCALL}" | tr '[:lower:]' '[:upper:]')
ok "Callsign: $INPUT_CALL"

ask "Your BPQ node callsign with SSID [${INPUT_CALL}-4]:"
explain "BPQ nodes use callsign-SSID format. SSID 4 is common for nodes."
read -r INPUT_NODE
INPUT_NODE=$(echo "${INPUT_NODE:-${INPUT_CALL}-4}" | tr '[:lower:]' '[:upper:]')
ok "Node: $INPUT_NODE"

ask "Your sysop email address [sysop@example.com]:"
explain "Shown on the dashboard's About page and station map popup."
read -r INPUT_EMAIL
INPUT_EMAIL="${INPUT_EMAIL:-sysop@example.com}"
ok "Email: $INPUT_EMAIL"

ask "Your station latitude in decimal degrees (e.g. 40.7128) [0.0]:"
explain "Positive = North, negative = South. Use Google Maps if unsure."
read -r INPUT_LAT
INPUT_LAT="${INPUT_LAT:-0.0}"
ok "Latitude: $INPUT_LAT"

ask "Your station longitude in decimal degrees (e.g. -74.0060) [0.0]:"
explain "Positive = East, negative = West. (USA is negative.)"
read -r INPUT_LON
INPUT_LON="${INPUT_LON:-0.0}"
ok "Longitude: $INPUT_LON"

ask "Your CARTO map key for the APRS map (press Enter to skip and add it later):"
explain "Free from https://www.carto.com/basemaps/apikey/ . Without it the APRS"
explain "map works but shows \"API KEY REQUIRED\" across the map. See the guide, Step 10."
read -r INPUT_CARTO_KEY
INPUT_CARTO_KEY=$(echo "$INPUT_CARTO_KEY" | tr -d '[:space:]')
if [[ -z "$INPUT_CARTO_KEY" ]]; then
    warn "No CARTO map key - the APRS map will be stamped \"API KEY REQUIRED\" until you add one to config.php ('map' => 'carto_key')"
elif [[ ! "$INPUT_CARTO_KEY" =~ ^[A-Za-z0-9_-]{8,128}$ ]]; then
    warn "That doesn't look like a CARTO key (letters, numbers, _ and - only) - skipped; add it to config.php later"
    INPUT_CARTO_KEY=""
else
    ok "CARTO map key: set"
fi

# 2026-10-26: optional automatic NWS Warning/Watch bulletins (scripts/nws-auto-post.php)
NWS_AUTO="n"; NWS_STATE=""; NWS_DEST=""; NWS_EVENTS="'Warning', 'Watch'"; NWS_TZ="America/New_York"
# Time zone for the times printed in the bulletins: the state's main zone
# (servers usually run on UTC). Edit 'timezone' in config.php if yours differs.
nws_state_tz() {
    case "$1" in
        AL|AR|IL|IA|KS|LA|MN|MS|MO|NE|ND|OK|SD|TN|TX|WI) echo "America/Chicago" ;;
        CO|ID|MT|NM|UT|WY) echo "America/Denver" ;;
        AZ)                echo "America/Phoenix" ;;
        CA|NV|OR|WA)       echo "America/Los_Angeles" ;;
        AK)                echo "America/Anchorage" ;;
        HI)                echo "Pacific/Honolulu" ;;
        PR|VI)             echo "America/Puerto_Rico" ;;
        GU|MP)             echo "Pacific/Guam" ;;
        AS)                echo "Pacific/Pago_Pago" ;;
        *)                 echo "America/New_York" ;;
    esac
}
NWS_STATES="AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY PR VI GU AS MP"
ask "Post NWS weather Warnings and Watches to your BBS automatically? (optional)"
explain "Every 5 minutes the server checks api.weather.gov for your state and posts"
explain "the FIRST issue of each new Warning or Watch as a bulletin (e.g. WX@GA)."
explain "No updates or cancellations, nothing already expired, never the same alert"
explain "twice. You can switch it on or off later in config.php ('nws' => 'auto_post')."
if yesno "Turn on automatic NWS bulletins?" N; then
    while true; do
        ask "Your 2-letter state code (e.g. GA, TX, NY):"
        explain "Alerts for this state are posted. US territories: PR VI GU AS MP."
        read -r NWS_STATE
        NWS_STATE=$(echo "$NWS_STATE" | tr -d '[:space:]' | tr '[:lower:]' '[:upper:]')
        if [[ -z "$NWS_STATE" ]]; then
            NWS_AUTO="n"; warn "No state entered - automatic NWS bulletins left off (config.php 'nws' => 'auto_post' to turn on later)"; break
        elif [[ " $NWS_STATES " == *" $NWS_STATE "* ]]; then
            break
        fi
        echo "  '$NWS_STATE' is not a US state/territory code. Try again, or press ENTER to skip."
    done
    if [[ -n "$NWS_STATE" ]]; then
        NWS_AUTO="y"
        NWS_TZ=$(nws_state_tz "$NWS_STATE")
        ask "Bulletin address to post them to [WX@$NWS_STATE]:"
        explain "Use a flood area your forwarding partners carry (e.g. WX@$NWS_STATE, WX@USA)."
        read -r NWS_DEST
        NWS_DEST=$(echo "${NWS_DEST:-WX@$NWS_STATE}" | tr -d '[:space:]' | tr '[:lower:]' '[:upper:]')
        if [[ ! "$NWS_DEST" =~ ^[A-Z0-9][A-Z0-9_.#@-]{0,59}$ ]]; then
            warn "'$NWS_DEST' is not a valid BBS address - using WX@$NWS_STATE"
            NWS_DEST="WX@$NWS_STATE"
        fi
        ask "Which alerts?  1 = Warnings and Watches   2 = Warnings only   [1]:"
        explain "Watches add Tornado / Severe Thunderstorm / Hurricane Watches etc."
        read -r _nws_ev
        [[ "$_nws_ev" == "2" ]] && NWS_EVENTS="'Warning'"
        ok "Automatic NWS bulletins: $NWS_STATE -> $NWS_DEST ($( [[ "$_nws_ev" == "2" ]] && echo "Warnings only" || echo "Warnings and Watches" ))"
    fi
else
    ok "Automatic NWS bulletins: off (can be turned on later in config.php)"
fi

# 2026-10-27: VARA HF's session log for RF Connections arrives as syslog
# (UDP 514). Debian 12 doesn't install rsyslog, and nothing received UDP
# syslog before this question. Installation Manual §10.11.
VARA_SYSLOG="n"; VARA_IP=""
ask "Receive VARA HF's log for the RF Connections page? (recommended if you run VARA HF)"
explain "RF Connections is built from VARA HF's session log. The registered VARA HF"
explain "sends it as syslog: VARA Settings > VARA Setup > tick Syslog, Syslog Host ="
explain "this server's IP address, port 514, Station Name = H-<VARA call-SSID>"
explain "(e.g. H-${INPUT_CALL}-7). The installer sets this server up to receive it"
explain "(rsyslog on UDP 514, accepting only the VARA computer)."
if yesno "Set up the VARA HF log receiver?" Y; then
    while true; do
        ask "IP address of the computer running VARA HF (Enter = VARA runs on this server):"
        explain "Usually a Windows PC on your network, e.g. 192.168.1.50. Give it a fixed"
        explain "address (DHCP reservation in your router) so this stays right."
        read -r VARA_IP
        VARA_IP=$(echo "$VARA_IP" | tr -d '[:space:]')
        if [[ -z "$VARA_IP" ]]; then VARA_IP="127.0.0.1"; break; fi
        if [[ "$VARA_IP" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] \
           && (( BASH_REMATCH[1] <= 255 && BASH_REMATCH[2] <= 255 && BASH_REMATCH[3] <= 255 && BASH_REMATCH[4] <= 255 )); then
            break
        fi
        echo "  '$VARA_IP' is not an IPv4 address (like 192.168.1.50). Try again, or press ENTER for this server."
    done
    VARA_SYSLOG="y"
    ok "VARA HF log: received from $( [[ "$VARA_IP" == "127.0.0.1" ]] && echo "this server" || echo "$VARA_IP" ) on UDP 514"
else
    ok "VARA HF log receiver: skipped (Installation Manual §10.11 to set it up later)"
fi

# ============================================================================
hdr "STEP 2 — Tell me about your BPQ node"
# ============================================================================

ask "What port does your BPQ telnet listen on? [8010]:"
explain "Open your bpq32.cfg in a text editor. Look in the TELNET section"
explain "for a line like 'TCPPORT=8010'. Use that number."
read -r INPUT_BPQ_PORT
INPUT_BPQ_PORT="${INPUT_BPQ_PORT:-8010}"
ok "BPQ telnet port: $INPUT_BPQ_PORT"

ask "Your BPQ sysop username (from bpq32.cfg USER= line) [SYSOP]:"
explain "In bpq32.cfg, look for lines like:  USER=tony,mypassword,W1AW-4,..."
explain "The first field after USER= is the username."
read -r INPUT_BPQ_USER
INPUT_BPQ_USER="${INPUT_BPQ_USER:-SYSOP}"
ok "BPQ username: $INPUT_BPQ_USER"

ask "Your BPQ sysop password:"
explain "The second field on the USER= line. This is stored in config.php"
explain "with mode 0640 so only the web server can read it."
read -rs INPUT_BPQ_PASS; echo
INPUT_BPQ_PASS="${INPUT_BPQ_PASS:-CHANGE_THIS_PASSWORD}"
ok "BPQ password recorded (hidden)"

ask "Path to the folder that contains your bpq32.cfg [/opt/linbpq]:"
explain "This is the folder where LinBPQ runs. The dashboard will create"
explain "a symlink from /var/www/bpq-dashboard/logs to LinBPQ's logs folder"
explain "so the log viewer pages can read your BBS and VARA logs."
read -r INPUT_LINBPQ_DIR
INPUT_LINBPQ_DIR="${INPUT_LINBPQ_DIR:-/opt/linbpq}"
if [[ -d "$INPUT_LINBPQ_DIR" ]]; then
    ok "LinBPQ directory exists: $INPUT_LINBPQ_DIR"
else
    warn "LinBPQ directory $INPUT_LINBPQ_DIR does not exist yet."
    warn "The log-viewer pages won't have anything to read until LinBPQ is set up."
fi

# ============================================================================
hdr "STEP 3 — System update"
# ============================================================================
say "Refreshing the package index (apt update). This downloads the list"
say "of available packages from your distribution's servers — no software"
say "is installed yet."
if apt-get update -qq; then
    ok "Package index refreshed"
else
    warn "apt-get update reported a problem. Continuing anyway."
fi

# ============================================================================
hdr "STEP 4 — Detect or install a web server"
# ============================================================================

WEBSERVER=""
WEB_USER="www-data"

if systemctl is-active --quiet nginx 2>/dev/null; then
    WEBSERVER="nginx"
    ok "Detected nginx — running"
elif command -v nginx >/dev/null 2>&1; then
    WEBSERVER="nginx"
    ok "Detected nginx — installed but not running; will start it"
elif systemctl is-active --quiet apache2 2>/dev/null; then
    WEBSERVER="apache2"
    ok "Detected Apache (apache2) — running"
elif command -v apache2 >/dev/null 2>&1; then
    WEBSERVER="apache2"
    ok "Detected Apache (apache2) — installed but not running; will start it"
else
    say "No web server detected. Will install nginx."
    if apt-get install -y -qq nginx; then
        WEBSERVER="nginx"
        ok "Installed nginx"
    else
        err "Failed to install nginx"
        die "Cannot continue without a web server. Check the log."
    fi
fi

# Make sure it's running and enabled
systemctl enable --now "$WEBSERVER" >/dev/null 2>&1 || \
    warn "Could not enable/start $WEBSERVER — please check 'systemctl status $WEBSERVER'"

# ============================================================================
hdr "STEP 5 — Install PHP and Python"
# ============================================================================

# PHP-FPM (with sensible extensions for the dashboard's needs)
PHP_PKGS=(php-fpm php-cli php-curl php-json php-mbstring php-xml php-mysql php-sqlite3 php-gd)
say "Installing PHP and the extensions the dashboard needs..."
if apt-get install -y -qq "${PHP_PKGS[@]}"; then
    ok "PHP installed"
else
    err "Failed to install PHP packages"
    die "Cannot continue without PHP. Check the log, fix the issue (often a bad" \
        "package name for your distro version), and re-run this script."
fi

# Detect installed PHP-FPM version (e.g. php8.1-fpm, php8.2-fpm)
PHP_FPM_SVC=$(systemctl list-units --type=service --state=loaded | \
              awk '/php[0-9.]+-fpm/{print $1; exit}')
if [[ -z "$PHP_FPM_SVC" ]]; then
    # Fall back to whatever php-fpm meta-package set up
    PHP_FPM_SVC=$(ls /etc/init.d/ 2>/dev/null | grep '^php.*fpm' | head -1)
fi
if [[ -n "$PHP_FPM_SVC" ]]; then
    systemctl enable --now "$PHP_FPM_SVC" >/dev/null 2>&1
    ok "PHP-FPM running: $PHP_FPM_SVC"
else
    warn "Could not detect a PHP-FPM service. PHP pages may not work."
fi

# Find the FPM socket path (varies by PHP version)
PHP_FPM_SOCK=$(find /run/php /var/run/php -maxdepth 1 -name '*fpm.sock' 2>/dev/null | head -1)
[[ -z "$PHP_FPM_SOCK" ]] && PHP_FPM_SOCK="/run/php/php-fpm.sock"

# Python 3 plus pip and the libraries the daemon scripts need
PY_PKGS=(python3 python3-pip python3-requests python3-pexpect python3-serial python3-pymysql python3-reportlab python3-websockets)
say "Installing Python 3 and the libraries the dashboard daemons use..."
if apt-get install -y -qq "${PY_PKGS[@]}"; then
    ok "Python 3 installed"
else
    warn "Some Python packages failed to install — daemons may need manual fixup"
fi

# Useful utilities
say "Installing a few small utilities (curl, jq, sudo, rsyslog)..."
# rsyslog writes /var/log/syslog, which vara-logger reads (Debian 12 no longer
# installs it by default).
apt-get install -y -qq curl jq sudo rsync rsyslog >/dev/null 2>&1 && ok "Utilities installed"

# ============================================================================
# Give long-running pages (log parsing, reports) enough time: PHP's
# default 30 s limit is too short for some dashboard requests.
for d in /etc/php/*/fpm/conf.d /etc/php/*/apache2/conf.d; do
    [[ -d "$d" ]] && printf '; BPQ Dashboard (install.sh)\nmax_execution_time = 90\n' > "$d/99-bpq-dashboard.ini"
done
[[ -n "$PHP_FPM_SVC" ]] && systemctl restart "$PHP_FPM_SVC" >/dev/null 2>&1
ok "PHP time limit set to 90 seconds"

# ============================================================================
hdr "STEP 6 — Database (required for the Email Client)"
# ============================================================================

INSTALL_DB="n"
DB_NAME="bpqdash"
DB_USER="bpqdash"
DB_PASS=""

echo
echo "${BOLD}About the database:${NC}"
echo "The dashboard stores BBS messages, folders, and rules (Email Client),"
echo "RF connection history (RF Connections page), and propagation session"
echo "history/heatmaps in a small MariaDB database."
echo
echo "The Email Client will NOT work without this — message storage has no"
echo "fallback (the old JSON-file storage was fully replaced). RF Connections"
echo "degrades gracefully (falls back to slower log parsing) if skipped, but"
echo "the Email Client simply won't function at all."
echo
echo "Install it unless you have a specific reason not to."
echo

if command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1; then
    ok "MariaDB / MySQL is already installed"
    if yesno "Use it for the dashboard?" Y; then
        INSTALL_DB="y"
    fi
else
    if yesno "Install MariaDB now?" Y; then
        say "Installing MariaDB..."
        if apt-get install -y -qq mariadb-server; then
            systemctl enable --now mariadb >/dev/null 2>&1
            ok "MariaDB installed and running"
            INSTALL_DB="y"
        else
            err "MariaDB install failed — continuing without database"
            INSTALL_DB="n"
        fi
    fi
fi

if [[ "$INSTALL_DB" == "y" ]]; then
    ask "Set a password for the dashboard's database user '$DB_USER':"
    explain "This is a brand-new password for a new MariaDB user that only"
    explain "the dashboard PHP code will use. It's NOT your BPQ sysop password."
    read -rs DB_PASS; echo
    if [[ -z "$DB_PASS" ]]; then
        # Generate a random password since user didn't pick one
        DB_PASS=$(head -c 16 /dev/urandom | base64 | tr -d '+/=' | head -c 20)
        say "No password entered — generated a random one and saved it to config.php"
    fi

    say "Creating database '$DB_NAME' and user '$DB_USER'..."
    if mysql --protocol=socket -u root <<-SQL 2>/dev/null
        CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
        CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
        ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
        GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
        FLUSH PRIVILEGES;
SQL
    then
        ok "Database and user created"

        # Load every base schema (2026-09-26: was only 3 of the 6, which
        # left Traffic, System Logs and the network sessions/stations
        # tables missing on a fresh install). All are safe to re-run.
        # The sql/2026-09-* files are UPGRADE migrations: no-ops on a fresh
        # database, but they add new columns when re-run over an older one.
        say "Loading database schemas..."
        SCHEMA_OK=0
        SCHEMA_TOTAL=0
        for SCHEMA_FILE in \
            "$SCRIPT_DIR/sql/2026-07-messages-schema.sql" \
            "$SCRIPT_DIR/data/rf-connections-schema.sql" \
            "$SCRIPT_DIR/data/prop-decisions-schema.sql" \
            "$SCRIPT_DIR/data/sessions-stations-schema.sql" \
            "$SCRIPT_DIR/data/traffic-schema.sql" \
            "$SCRIPT_DIR/data/system-logs-schema.sql" \
            "$SCRIPT_DIR/sql/2026-09-21-rf-messages-medium.sql" \
            "$SCRIPT_DIR/sql/2026-09-26-message-keep-and-bodies.sql" \
            "$SCRIPT_DIR/sql/2026-10-06-rf-messages-delivery.sql"   # 2026-10-25: was missing — Forwarded Messages Via/Status
        do
            [[ -f "$SCHEMA_FILE" ]] || continue
            SCHEMA_TOTAL=$((SCHEMA_TOTAL + 1))
            if mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SCHEMA_FILE" 2>/dev/null; then
                ok "Loaded $(basename "$SCHEMA_FILE")"
                SCHEMA_OK=$((SCHEMA_OK + 1))
            else
                warn "$(basename "$SCHEMA_FILE") didn't load cleanly — re-run manually: mysql -u $DB_USER -p $DB_NAME < $SCHEMA_FILE"
            fi
        done
        if [[ "$SCHEMA_OK" -lt "$SCHEMA_TOTAL" ]]; then
            warn "$((SCHEMA_TOTAL - SCHEMA_OK)) of $SCHEMA_TOTAL schema file(s) failed to load — run install-check.php after setup to confirm which tables are missing"
        fi
    else
        err "Could not create database. You may need to run mysql_secure_installation"
        err "first and then re-run this script."
        INSTALL_DB="n"
    fi
fi

# ============================================================================
hdr "STEP 7 — Admin page authentication"
# ============================================================================

echo
echo "${BOLD}About this:${NC}"
echo "Five pages — Maintenance, Firewall Status, Log Viewer, System Audit,"
echo "and Install Check — expose sensitive system info (firewall rules,"
echo "live logs, audit results) and are password-protected with a separate"
echo "login, independent of your BPQ sysop password."
echo

ADMIN_HTPASSWD="/etc/nginx/.bpq-mgmt-auth"
ADMIN_REALM="${INPUT_CALL} Admin"

# htpasswd (from apache2-utils) is needed regardless of which web server
# ends up serving the site — it's just the tool that writes the password
# file, not tied to Apache specifically.
if ! command -v htpasswd >/dev/null 2>&1; then
    say "Installing apache2-utils (provides the htpasswd tool)..."
    if apt-get install -y -qq apache2-utils; then
        ok "htpasswd tool installed"
    else
        err "Failed to install apache2-utils"
        die "Cannot set up admin authentication without htpasswd. Install" \
            "apache2-utils manually and re-run this script."
    fi
fi

ask "Admin username for these pages [admin]:"
explain "This is separate from your BPQ sysop login — it's HTTP Basic Auth"
explain "for the dashboard's own admin pages."
read -r ADMIN_USER
ADMIN_USER="${ADMIN_USER:-admin}"

ask "Admin password for these pages:"
explain "Leave blank to generate a random one (shown once, and saved to"
explain "the install log at $LOG)."
read -rs ADMIN_PASS; echo
if [[ -z "$ADMIN_PASS" ]]; then
    ADMIN_PASS=$(head -c 16 /dev/urandom | base64 | tr -d '+/=' | head -c 20)
    say "Generated admin password: $ADMIN_PASS"
    say "Write this down now — it won't be shown again after this install finishes."
fi

# -c creates a fresh file (this is the only place that writes it during
# install, so always safe to use -c here); -B uses bcrypt, the stronger
# hash bpq-maintenance.html's own docs recommend over MD5-APR1 for new
# entries.
if htpasswd -cbB "$ADMIN_HTPASSWD" "$ADMIN_USER" "$ADMIN_PASS" 2>/dev/null; then
    ok "Admin credentials saved to $ADMIN_HTPASSWD"
else
    err "Failed to write $ADMIN_HTPASSWD"
    die "Cannot continue without admin auth configured. Check permissions on" \
        "$(dirname "$ADMIN_HTPASSWD") and re-run this script."
fi
chmod 640 "$ADMIN_HTPASSWD"

# ============================================================================
hdr "STEP 8 — Copy dashboard files"
# ============================================================================

WEB_ROOT="/var/www/bpq-dashboard"

if [[ -d "$WEB_ROOT" ]]; then
    warn "$WEB_ROOT already exists from a previous install."
    BACKUP="${WEB_ROOT}.backup-$(date +%Y%m%d-%H%M%S)"
    if yesno "Back it up to $BACKUP and reinstall fresh?" Y; then
        mv "$WEB_ROOT" "$BACKUP" && ok "Backed up existing install to $BACKUP"
    fi
fi

say "Creating $WEB_ROOT..."
mkdir -p "$WEB_ROOT" && ok "Created $WEB_ROOT" || err "mkdir failed"

say "Copying dashboard files (this is the biggest step — may take 10 seconds)..."
# Copy everything except the installer itself and these utility scripts
rsync -a --exclude='install.sh' \
         --exclude='*.log' \
         --exclude='.git' --exclude='private' \
         --exclude='docs/SESSION-JOURNAL.md' \
         "$SCRIPT_DIR/"  "$WEB_ROOT/" \
    && ok "Files copied" || err "rsync failed"

# Make sure cache and logs subdirs exist (some are empty in the archive)
mkdir -p "$WEB_ROOT/cache" "$WEB_ROOT/logs" "$WEB_ROOT/data/backups" \
         "$WEB_ROOT/cache/aprs" "$WEB_ROOT/cache/chat-sessions" "$WEB_ROOT/cache/network"
# Partners list used by the admin Partners editor (starts empty)
[[ -f "$WEB_ROOT/data/partners.json" ]] || echo '{"partners":[]}' > "$WEB_ROOT/data/partners.json"

# APRS map symbols (standard set by OH7LZB, CC-licensed — downloaded here
# rather than bundled; see github.com/hessu/aprs-symbols for credits)
mkdir -p "$WEB_ROOT/img"
for n in 0 1; do
    [[ $n == 0 ]] && f=pri || f=alt
    if curl -fsSL -o "$WEB_ROOT/img/aprs-symbols-$f.png" \
         "https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/aprs-symbols-24-$n.png"; then
        ok "Downloaded APRS symbols ($f)"
    else
        warn "Could not download APRS symbols ($f) — the APRS map will show plain dots. Retry later with install-check.php's fix command."
    fi
done

# Message forms for the Email Client's "PKTNET Forms" button (TPRFN Forms
# Suite by N3MEL and contributors). Maintained in the authors' own
# repository, so downloaded here rather than bundled. Kept if already there.
if [[ -f "$WEB_ROOT/pktnet-forms/index.html" ]]; then
    ok "Message forms already present (pktnet-forms/)"
elif bash "$WEB_ROOT/scripts/fetch-forms.sh" "$WEB_ROOT" >/dev/null; then
    ok "Downloaded the message forms suite (pktnet-forms/)"
else
    warn "Could not download the message forms suite — the Email Client's 'PKTNET Forms' button will show a 404. Retry later: sudo bash $WEB_ROOT/scripts/fetch-forms.sh"
fi

# Ownership
chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT" && ok "Set ownership to $WEB_USER"

# Tighten permissions
find "$WEB_ROOT" -type d -exec chmod 755 {} \;
find "$WEB_ROOT" -type f -exec chmod 644 {} \;
# Shell / Python scripts stay executable
find "$WEB_ROOT" -name "*.sh" -exec chmod 755 {} \;
find "$WEB_ROOT" -name "*.py" -exec chmod 755 {} \;

# ============================================================================
hdr "STEP 9 — flrig (rig control)"
# ============================================================================

echo
echo "${BOLD}About this:${NC}"
echo "Rig Status and RF Power Monitor both read live frequency/power/SWR from"
echo "your radio via flrig, not directly — flrig acts as the bridge between"
echo "the dashboard and whatever radio you have."
echo

FLRIG_HOST="127.0.0.1"
FLRIG_PORT="12345"
FLRIG_MODE="skip"

if yesno "Set up flrig now (for Rig Status / RF Power Monitor)?" Y; then

    if yesno "Does flrig run on THIS server (radio physically connected here)?" Y; then
        FLRIG_MODE="local"

        if command -v flrig >/dev/null 2>&1; then
            ok "flrig is already installed"
        else
            say "Installing flrig and Xvfb..."
            explain "flrig is a GUI application with no native headless mode — Xvfb"
            explain "gives it a virtual display so it can run unattended on a server"
            explain "with no monitor. This is the standard approach the ham radio"
            explain "community uses for exactly this situation."
            if apt-get install -y -qq flrig xvfb; then
                ok "flrig and Xvfb installed"
            else
                err "Failed to install flrig/xvfb"
                warn "Continuing without flrig — Rig Status and RF Power Monitor"
                warn "won't show live data until this is resolved manually."
                FLRIG_MODE="skip"
            fi
        fi

        if [[ "$FLRIG_MODE" == "local" ]]; then
            ask "Which serial/USB port is your radio connected to? [/dev/ttyUSB0]:"
            explain "Just for your reference during the manual setup step below —"
            explain "this script doesn't need it for anything itself."
            read -r INPUT_RADIO_PORT
            INPUT_RADIO_PORT="${INPUT_RADIO_PORT:-/dev/ttyUSB0}"

            say "Writing /etc/systemd/system/flrig.service ..."
            cat > /etc/systemd/system/flrig.service <<FLRIGSVC
[Unit]
Description=flrig rig control (headless via Xvfb)
After=network.target

[Service]
User=$WEB_USER
Environment=DISPLAY=:99
ExecStartPre=/usr/bin/Xvfb :99 -screen 0 800x600x24
ExecStart=/usr/bin/flrig
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
FLRIGSVC
            systemctl daemon-reload
            ok "flrig.service created (not started yet — see below)"

            echo
            echo "${BOLD}One-time manual step required before starting the service:${NC}"
            echo "flrig needs you to pick your radio model and confirm it connects"
            echo "before it can run unattended — Xvfb has no screen for you to see,"
            echo "so this has to be done with a real display first. From your own"
            echo "computer:"
            echo "    ssh -X $(whoami)@$(hostname -I | awk '{print $1}')"
            echo "    flrig"
            echo "Select your radio model and serial port ($INPUT_RADIO_PORT), confirm"
            echo "it's reading frequency, then close flrig normally (it saves your"
            echo "settings). After that, start the service:"
            echo "    sudo systemctl enable --now flrig"
            echo
        fi

    else
        FLRIG_MODE="remote"
        ask "IP address or hostname of the machine running flrig:"
        read -r INPUT_FLRIG_HOST
        FLRIG_HOST="${INPUT_FLRIG_HOST:-127.0.0.1}"

        ask "flrig's XML-RPC port [12345]:"
        explain "This is flrig's own setting (Config > Setup > XML-RPC Server in its"
        explain "GUI) — 12345 is the default and rarely changed."
        read -r INPUT_FLRIG_PORT
        FLRIG_PORT="${INPUT_FLRIG_PORT:-12345}"

        if command -v nc >/dev/null 2>&1; then
            if nc -zw3 "$FLRIG_HOST" "$FLRIG_PORT" 2>/dev/null; then
                ok "Reached $FLRIG_HOST:$FLRIG_PORT"
            else
                warn "Couldn't reach $FLRIG_HOST:$FLRIG_PORT right now — that's fine if"
                warn "flrig isn't running yet, but worth checking once it is: nc -zv $FLRIG_HOST $FLRIG_PORT"
            fi
        fi
    fi
else
    warn "Skipping flrig — the Rig Status and RF Power Monitor pages are not installed"
    warn "(both need flrig). To add them later, re-run this script and answer Y here."
fi

# 2026-10-05: Rig Status and RF Power Monitor only work through flrig, so
# without it they are left out entirely (pages, nav links, history poller)
# rather than installed as pages that can only show "flrig offline".
if [[ "$FLRIG_MODE" == "skip" ]]; then
    for f in rig-status.html rf-power-monitor.html \
             rig-history-poller.service scripts/rig-history-poller.php; do
        rm -f "$WEB_ROOT/$f"
    done
    if sed -i -e '/href="\/rig-status\.html"/d' -e '/href="\/rf-power-monitor\.html"/d' \
          "$WEB_ROOT/_nav.html"; then
        chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/_nav.html"
        ok "Rig Status / RF Power Monitor left out (no flrig)"
    else
        warn "Could not remove the Rig / RF Power links from $WEB_ROOT/_nav.html"
    fi
fi

# ============================================================================
hdr "STEP 10 — Generate config.php"
# ============================================================================

CFG="$WEB_ROOT/config.php"
# Maidenhead grid square from lat/lon. Public pages show only the
# centre of this square, never the exact coordinates.
INPUT_GRID=$(python3 -c '
import sys
lat, lon = float(sys.argv[1]) + 90, float(sys.argv[2]) + 180
if not (0 <= lat < 180 and 0 <= lon < 360) or (lat == 90 and lon == 180):
    print(""); sys.exit()
A = "ABCDEFGHIJKLMNOPQRSTUVWX"
print(A[int(lon // 20)] + A[int(lat // 10)] + str(int(lon % 20 // 2)) + str(int(lat % 10))
      + A[int(lon % 2 * 12)] + A[int(lat % 1 * 24)])' "$INPUT_LAT" "$INPUT_LON" 2>/dev/null)
INPUT_CALL_LC=$(echo "$INPUT_CALL" | tr '[:upper:]' '[:lower:]')
say "Writing $CFG with your settings..."
cat > "$CFG" <<EOF
<?php
/**
 * BPQ Dashboard Configuration — generated by install.sh on $(date)
 */
return [
    'station' => [
        'callsign' => '$INPUT_CALL',
        'node'     => '$INPUT_NODE',
        'email'    => '$INPUT_EMAIL',
        'lat'      => $INPUT_LAT,
        'lon'      => $INPUT_LON,
        'grid'     => '$INPUT_GRID',   // public pages show the centre of this square only
        'hub'      => '',              // optional: your VARA HF hub SSID, e.g. $INPUT_CALL-7
    ],
    'aprs' => [
        'callsign' => '$INPUT_CALL-1', // APRS-IS login
        'passcode' => '-1',            // your APRS-IS passcode; -1 = receive-only
        'filter'   => '',              // empty = 80 km around your station
    ],
    'map' => [
        'carto_key' => '$INPUT_CARTO_KEY', // APRS map tiles; free key: https://www.carto.com/basemaps/apikey/
    ],
    'bbs' => [
        'host'    => 'localhost',
        'port'    => $INPUT_BPQ_PORT,
        'user'    => '$INPUT_BPQ_USER',
        'pass'    => '$(echo "$INPUT_BPQ_PASS" | sed "s/'/\\\\'/g")',
        'alias'   => 'bbs',
        'timeout' => 30,
    ],
    'db' => [
        'host' => 'localhost',
        'name' => '$DB_NAME',
        'user' => '$DB_USER',
        'pass' => '$(echo "$DB_PASS" | sed "s/'/\\\\'/g")',
        'enabled' => $( [[ "$INSTALL_DB" == "y" ]] && echo "true" || echo "false" ),
    ],
    'security_mode' => 'local',
    'features'      => ['bbs_read' => true, 'bbs_write' => true, 'nws_post' => true],
    'nws' => [                  // NWS alerts; auto_post = scripts/nws-auto-post.php (nws-auto-post.timer)
        'post_destination' => 'WX@ALLUS',   // default address for the NWS page's Post buttons
        'auto_post' => [
            'enabled'            => $( [[ "$NWS_AUTO" == "y" ]] && echo "true" || echo "false" ),
            'area'               => '$NWS_STATE',      // 2-letter state code
            'destination'        => '$NWS_DEST',       // empty = WX@<area>
            'events'             => [$NWS_EVENTS],     // ['Warning'] = warnings only
            'exclude'            => [],             // exact names never posted, e.g. 'Red Flag Warning'
            'watch_hold_minutes' => 10,             // wait for other NWS offices' pieces of a watch
            'max_per_run'        => 10,
            'timezone'           => '$NWS_TZ',   // times shown in the bulletins
        ],
    ],
    'rate_limit'    => ['enabled' => false],
    'cors'          => ['allow_all' => false, 'allowed_origins' => []],   // same-origin only
    'logging'       => ['enabled' => false],
    'paths'         => [
        'logs'    => '$WEB_ROOT/logs/',
        'scripts' => '$WEB_ROOT/scripts/',
        'datalog' => '$WEB_ROOT/logs/',
        'linbpq'  => '$INPUT_LINBPQ_DIR',
    ],
    'logs' => [
        'vara_file' => '$INPUT_CALL_LC.vara',
    ],
    'vara' => [                 // VARA HF Terminal (bpq-vara service)
        'bpq_port' => 0,        // BPQ port number of your VARA HF port; 0 = found in bpq32.cfg
        // 'user' => '', 'pass' => '',   // BPQ login for it, if not the 'bbs' one above
    ],
    'radio' => [
        'enabled'    => $( [[ "$FLRIG_MODE" == "skip" ]] && echo "false" || echo "true" ),   // false = no flrig: Rig pages not installed
        'flrig_host' => '$FLRIG_HOST',
        'flrig_port' => $FLRIG_PORT,
        'bpq_radio'  => 0,      // bpq32.cfg RADIO number for the Rig page scan list; 0 = the FLRIG block
        'max_power'  => 0,      // highest TX power (W) the Rig page may set; 0 = the radio's maximum
    ],
];
EOF
chmod 640 "$CFG"
chown "$WEB_USER:$WEB_USER" "$CFG"
ok "config.php generated (mode 640, owner $WEB_USER)"

# ============================================================================
hdr "STEP 11 — Configure the web server"
# ============================================================================

if [[ "$WEBSERVER" == "nginx" ]]; then
    say "Writing nginx site config to /etc/nginx/sites-available/bpq-dashboard ..."
    cat > /etc/nginx/sites-available/bpq-dashboard <<NGINX
server {
    listen 80 default_server;
    server_name _;
    root $WEB_ROOT;
    index bpq-rf-connections.html index.html index.php;

    # Logs
    access_log /var/log/nginx/bpq-dashboard.access.log;
    error_log  /var/log/nginx/bpq-dashboard.error.log;

    # Admin pages — HTTP Basic Auth (credentials set during install,
    # Step 7). These 5 pages expose firewall rules, live logs, and
    # audit results, so they're gated separately from the rest of the
    # site rather than relying on network placement alone.
    # (2026-09-26: the admin pages live in /admin/ — the old rule only
    # matched top-level names, so /admin/* was left unprotected.)
    location ^~ /admin/ {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        location ~ \.php\$ {
            fastcgi_param BPQ_WEB_LOGIN \$remote_user;   # tells the PHP the login was checked
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:$PHP_FPM_SOCK;
        }
        try_files \$uri =404;
    }
    # Old top-level admin URLs -> /admin/
    location ~ ^/(bpq-maintenance|firewall-status|log-viewer|system-audit)\.html\$ {
        return 301 /admin/\$1.html;
    }

    # ── Never serve code, data, config or backups ──────────────────────
    location ~* /(config|bbs-config|nws-config|db-config)\.php\$ { return 403; }
    location ^~ /includes/ { return 403; }
    location ^~ /scripts/  { return 403; }
    location ^~ /data/     { return 403; }
    location ^~ /cache/    { return 403; }
    location ^~ /archives/ { return 403; }
    location ^~ /sql/      { return 403; }
    location ^~ /tools/    { return 403; }
    location ~* \.(php\.example|example|bak|old|tmp|swp|orig|dist|md|sh|bat|yml|yaml|py|cfg|conf|service|timer|sudoers|sql)\$ { return 403; }
    # 2026-10-10: also a digit straight after the suffix (.bak3, .old2) — a .json.bak3 was served
    location ~* \.(bak|old|orig|save|tmp|swp)[-._0-9][^/]*\$ { return 403; }
    # 2026-10-19: work/editor copies, archives, build files, download duplicates, QRZ lookup data
    location ~* \.(ps1|pl|exp|jsx|ajd|wrk[0-9]*|html[0-9]+|save|docx?|xlsx?|zip|tar|gz|tgz|xz|o|ko|mod|c|h)\$ { return 403; }
    location ~* \.before-[^/]*\$ { return 403; }
    location ~* \([0-9]+\)\.[^/]*\$ { return 403; }
    location ~* ^/(callsign-cache|manual-locations)\.json\$ { return 403; }
    location ~* /SESSION-JOURNAL[^/]*\$ { return 403; }
    # App logs hold BBS session traces: never serve them (2026-10-03)
    location = /logs/bbs-messages.log { return 403; }
    location = /logs/dashboard.log    { return 403; }

    # BPQ logs: only log/text formats, never anything else in that folder
    location ^~ /logs/ {
        # Pages only read ConnectLog_/CMSAccess_ .log files; Telnet, daemon and
        # app logs record logins incl. passwords (2026-10-03)
        location ~* /(?!(ConnectLog|CMSAccess)_)[^/]*\.log\$ { return 403; }
        location ~* \.(txt|log|csv|vara|js)\$ {
            add_header Cache-Control "no-cache, no-store, must-revalidate";
            try_files \$uri =404;
        }
        return 403;
    }
    # YAPP files received by the Telnet Client (2026-10-04): sent by another
    # station, so untrusted. Admin login, never run as PHP (^~ stops the
    # .php regex), always a download, never rendered as a page here.
    location ^~ /yapp-files/ {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        types { }
        default_type application/octet-stream;
        add_header Content-Disposition     "attachment" always;
        add_header X-Content-Type-Options  "nosniff" always;
        add_header Content-Security-Policy "sandbox; default-src 'none'" always;
        add_header X-Frame-Options         "DENY" always;
        add_header X-Robots-Tag            "noindex, nofollow" always;
        add_header Cache-Control           "private, no-store" always;
        try_files \$uri =404;
    }
    # Everything that reads your mail or acts as the station (2026-10-04):
    # Email Client, NWS posting, Chat, APRS sending, map saves. Same login as
    # /admin/; the PHP also refuses unless nginx passes BPQ_WEB_LOGIN.
    location ~ ^/(bbs-messages|bpq-chat)\.html\$ {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        try_files \$uri =404;
    }
    location = /bbs-messages.php {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        fastcgi_read_timeout 120s;
        fastcgi_send_timeout 120s;
        fastcgi_buffering off;
        fastcgi_param BPQ_WEB_LOGIN \$remote_user;   # tells the PHP the login was checked
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }
    location ~ ^/(message-storage|nws-bbs-post|bpq-chat|nts-forwarding-report)\.php\$ {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        fastcgi_read_timeout 90s;
        fastcgi_send_timeout 90s;
        fastcgi_param BPQ_WEB_LOGIN \$remote_user;   # tells the PHP the login was checked
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }
    location ~ ^/(station-storage|bpq-aprs|callsign-lookup)\.php\$ {
        limit_except GET {   # writes need the login; reads stay public for the maps
            auth_basic "$ADMIN_REALM";
            auth_basic_user_file $ADMIN_HTPASSWD;
        }
        fastcgi_read_timeout 60s;
        fastcgi_send_timeout 60s;
        fastcgi_param BPQ_WEB_LOGIN \$remote_user;   # tells the PHP the login was checked
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }
    location ~ ^/(install-check|health-check)\.php\$ {   # health-check: 2026-10-20
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        fastcgi_param BPQ_WEB_LOGIN \$remote_user;   # tells the PHP the login was checked
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }

    # Telnet Client and VARA HF Terminal (2026-10-25): the pages, their live
    # connections and the NetROM list, behind the admin login. The VARA
    # bridge has no login of its own — without this, anyone who could reach
    # it could transmit as your station. Services listen on 127.0.0.1 only:
    # bpq-telnet 8765, bpq-vara 8767.
    location ~ ^/(bpq-telnet|bpq-vara)\.html\$ {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        try_files \$uri =404;
    }
    location = /bpq-nodes-api.php {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        fastcgi_read_timeout 30s;
        fastcgi_send_timeout 30s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }
    location ^~ /ws/telnet {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        proxy_pass         http://127.0.0.1:8765;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade \$http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
    location ^~ /ws/vara {
        auth_basic "$ADMIN_REALM";
        auth_basic_user_file $ADMIN_HTPASSWD;
        proxy_pass         http://127.0.0.1:8767;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade \$http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_read_timeout 7200s;
        proxy_send_timeout 7200s;
    }

    # Default: try files, then PHP, then 404
    location / {
        try_files \$uri \$uri/ =404;
    }

    # PHP handler
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }

    # Block hidden files (.env, .git, etc.) — security hardening
    location ~ /\. {
        deny all;
        return 404;
    }

    client_max_body_size 10M;
}
NGINX

    # Disable default site if present (only on a fresh nginx install)
    [[ -f /etc/nginx/sites-enabled/default ]] && \
        rm -f /etc/nginx/sites-enabled/default && \
        say "Removed default nginx site"

    # Enable our site
    ln -sf /etc/nginx/sites-available/bpq-dashboard \
           /etc/nginx/sites-enabled/bpq-dashboard

    if nginx -t 2>/dev/null; then
        systemctl reload nginx && ok "nginx configured and reloaded"
    else
        err "nginx config test failed. See: sudo nginx -t"
    fi

elif [[ "$WEBSERVER" == "apache2" ]]; then
    say "Writing Apache site config to /etc/apache2/sites-available/bpq-dashboard.conf ..."
    cat > /etc/apache2/sites-available/bpq-dashboard.conf <<APACHE
<VirtualHost *:80>
    DocumentRoot $WEB_ROOT
    DirectoryIndex bpq-rf-connections.html index.html index.php

    <Directory $WEB_ROOT>
        AllowOverride All
        Require all granted
    </Directory>

    # Admin pages — HTTP Basic Auth (credentials set during install,
    # Step 7). These 5 pages expose firewall rules, live logs, and
    # audit results, so they're gated separately from the rest of the
    # site rather than relying on network placement alone.
    <FilesMatch "^(install-check|health-check)\.php\$">
        AuthType Basic
        AuthName "$ADMIN_REALM"
        AuthUserFile $ADMIN_HTPASSWD
        Require valid-user
    </FilesMatch>
    # Everything that reads your mail or acts as the station (2026-10-04):
    # Email Client, NWS posting, Chat, NTS report — whole file behind the login.
    <FilesMatch "^(bbs-messages\.(php|html)|message-storage\.php|nws-bbs-post\.php|bpq-chat\.(php|html)|nts-forwarding-report\.php)\$">
        AuthType Basic
        AuthName "$ADMIN_REALM"
        AuthUserFile $ADMIN_HTPASSWD
        Require valid-user
    </FilesMatch>
    # APRS sending and map saves: writes behind the login, reads stay public.
    <FilesMatch "^(station-storage|bpq-aprs|callsign-lookup)\.php\$">
        AuthType Basic
        AuthName "$ADMIN_REALM"
        AuthUserFile $ADMIN_HTPASSWD
        <LimitExcept GET HEAD>
            Require valid-user
        </LimitExcept>
    </FilesMatch>
    # Telnet Client and VARA HF Terminal (2026-10-25): pages, NetROM list and
    # live connections behind the admin login. The VARA bridge has no login
    # of its own. Services listen on 127.0.0.1 only (bpq-telnet 8765,
    # bpq-vara 8767); mod_proxy_wstunnel carries the WebSocket.
    <FilesMatch "^(bpq-telnet\.html|bpq-vara\.html|bpq-nodes-api\.php)\$">
        AuthType Basic
        AuthName "$ADMIN_REALM"
        AuthUserFile $ADMIN_HTPASSWD
        Require valid-user
    </FilesMatch>
    ProxyRequests  Off
    ProxyTimeout   7200
    ProxyPass        /ws/telnet ws://127.0.0.1:8765/ws/telnet
    ProxyPassReverse /ws/telnet ws://127.0.0.1:8765/ws/telnet
    ProxyPass        /ws/vara   ws://127.0.0.1:8767/ws/vara
    ProxyPassReverse /ws/vara   ws://127.0.0.1:8767/ws/vara
    <Location /ws/>
        AuthType Basic
        AuthName "$ADMIN_REALM"
        AuthUserFile $ADMIN_HTPASSWD
        Require valid-user
    </Location>
    # (2026-09-26: admin pages live in /admin/ — protect the whole folder)
    <Directory $WEB_ROOT/admin>
        AuthType Basic
        AuthName "$ADMIN_REALM"
        AuthUserFile $ADMIN_HTPASSWD
        Require valid-user
    </Directory>
    # Never serve code, data, config or backups
    <DirectoryMatch "^$WEB_ROOT/(includes|scripts|data|cache|archives|sql|tools)(/|\$)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "(^(config|bbs-config|nws-config|db-config)\.php|\.(example|bak|old|tmp|swp|orig|dist|md|sh|bat|yml|yaml|py|cfg|conf|service|timer|sudoers|sql)|\.(bak|old|orig|save|tmp|swp)[-._].*|^SESSION-JOURNAL.*)\$">
        Require all denied
    </FilesMatch>
    <Directory $WEB_ROOT/logs>
        Require all denied
        <FilesMatch "\.(txt|log|csv|vara|js)\$">
            Require all granted
        </FilesMatch>
        # App logs hold BBS session traces: never serve them (2026-10-03)
        <FilesMatch "^(?!(ConnectLog|CMSAccess)_).*\.log\$">
            Require all denied
        </FilesMatch>
    </Directory>

    # YAPP files received by the Telnet Client (2026-10-04): sent by another
    # station, so untrusted. Admin login, nothing in there is ever run
    # (PHP/CGI refused outright), always a download.
    <Directory $WEB_ROOT/yapp-files>
        AllowOverride None
        Options -ExecCGI -Includes -Indexes
        AuthType Basic
        AuthName "$ADMIN_REALM"
        AuthUserFile $ADMIN_HTPASSWD
        Require valid-user
        RemoveHandler .php .phtml .phar .pht .cgi .pl .py .shtml
        RemoveType .php .phtml .phar .pht
        <FilesMatch "\.(php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|sh|shtml)(\.|\$)">
            Require all denied
        </FilesMatch>
        ForceType application/octet-stream
        Header always set Content-Disposition "attachment"
        Header always set X-Content-Type-Options "nosniff"
        Header always set Content-Security-Policy "sandbox; default-src 'none'"
        Header always set Cache-Control "private, no-store"
    </Directory>

    # Block hidden files
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/bpq-dashboard.error.log
    CustomLog \${APACHE_LOG_DIR}/bpq-dashboard.access.log combined
</VirtualHost>
APACHE

    a2enmod php* >/dev/null 2>&1   # only if mod_php happens to be installed
    # PHP through PHP-FPM (installed in Step 5). 2026-10-25: nothing hooked
    # FPM into Apache, so every .php page was sent as source and no API worked.
    a2enmod proxy_fcgi setenvif >/dev/null 2>&1
    PHP_FPM_CONF=$(ls /etc/apache2/conf-available/ 2>/dev/null | grep -m1 -E '^php[0-9.]+-fpm\.conf$')
    if [[ -n "$PHP_FPM_CONF" ]]; then
        a2enconf "${PHP_FPM_CONF%.conf}" >/dev/null 2>&1 && ok "Apache runs PHP through ${PHP_FPM_CONF%.conf}"
    else
        warn "No php*-fpm.conf in /etc/apache2/conf-available — PHP pages won't run."
        warn "Fix: sudo apt-get install php-fpm && sudo a2enconf php*-fpm && sudo systemctl reload apache2"
    fi
    a2enmod rewrite >/dev/null 2>&1
    a2enmod auth_basic authn_file authz_user headers >/dev/null 2>&1
    a2enmod proxy proxy_http proxy_wstunnel >/dev/null 2>&1   # /ws/telnet, /ws/vara (2026-10-25)
    a2dissite 000-default >/dev/null 2>&1 || true
    a2ensite bpq-dashboard >/dev/null 2>&1

    if apache2ctl configtest 2>/dev/null; then
        systemctl reload apache2 && ok "Apache configured and reloaded"
    else
        err "Apache config test failed. See: sudo apache2ctl configtest"
    fi
fi

# ============================================================================
hdr "STEP 12 — Link LinBPQ logs (so the log viewer can see them)"
# ============================================================================

# 2026-09-26: link LinBPQ's *logs* subfolder. Linking the whole LinBPQ
# folder (as before) made bpq32.cfg — which holds your passwords —
# reachable through the web server.
BPQ_LOGS_DIR="$INPUT_LINBPQ_DIR/logs"
if [[ -d "$BPQ_LOGS_DIR" ]]; then
    if [[ -L "$WEB_ROOT/logs" ]]; then
        rm -f "$WEB_ROOT/logs"
    elif [[ -d "$WEB_ROOT/logs" ]] && [[ -z "$(ls -A "$WEB_ROOT/logs" | grep -v '^\.gitkeep$')" ]]; then
        rm -rf "$WEB_ROOT/logs"
    fi
    if ln -s "$BPQ_LOGS_DIR" "$WEB_ROOT/logs" 2>/dev/null; then
        ok "Symlinked $WEB_ROOT/logs -> $BPQ_LOGS_DIR"
    else
        warn "Could not create symlink; created plain directory instead"
        mkdir -p "$WEB_ROOT/logs"
    fi
else
    warn "No logs folder at $BPQ_LOGS_DIR yet — skipping the link."
    say "Once LinBPQ has written its first logs, run:"
    say "  sudo rm -rf $WEB_ROOT/logs && sudo ln -s $BPQ_LOGS_DIR $WEB_ROOT/logs"
    mkdir -p "$WEB_ROOT/logs"
fi

# ============================================================================
hdr "STEP 13 — Install systemd services for helper daemons (optional)"
# ============================================================================

echo
echo "The dashboard ships with a few helper daemons (small Python programs"
echo "that run in the background):"
echo
echo "  • bpq-telnet  — keeps a connection open to BPQ for live commands"
echo "  • bpq-chat    — bridges the BPQ chat node to the web UI"
echo "  • bpq-aprs    — feeds APRS data to the APRS page"
echo "  • bpq-vara    — VARA HF Terminal page: connects it to your VARA port"
[[ "$FLRIG_MODE" != "skip" ]] && \
echo "  • rig-history-poller — records TX power / SWR for the Rig and RF Power charts"
echo
echo "You can install them now (they only start if you run 'systemctl"
echo "enable' on them) or skip and add them later by hand."
echo

if yesno "Install systemd unit files for the helper daemons?" Y; then
    # vara-logger.sh writes the VARA log the RF Connections parser reads
    sed -i "s|/var/www/bpq-dashboard|$WEB_ROOT|g; s|yourcall\.vara|$INPUT_CALL_LC.vara|g" \
        "$WEB_ROOT/vara-logger.sh" 2>/dev/null
    DAEMONS="bpq-telnet bpq-chat bpq-aprs bpq-vara vara-logger"
    [[ "$FLRIG_MODE" != "skip" ]] && DAEMONS="$DAEMONS rig-history-poller"
    for svc in $DAEMONS; do
        UNIT="$WEB_ROOT/${svc}.service"
        if [[ -f "$UNIT" ]]; then
            # Fix up paths inside the unit file
            sed -i "s|/var/www/bpq-dashboard|$WEB_ROOT|g" "$UNIT"
            cp "$UNIT" "/etc/systemd/system/$(basename "$UNIT")"
            ok "Installed $(basename "$UNIT")"
        fi
    done
    systemctl daemon-reload
    say "Daemons are installed but NOT started. Enable each one when you're"
    say "ready, e.g.:    sudo systemctl enable --now bpq-telnet"
else
    say "Skipping daemon services."
fi

# ============================================================================
hdr "STEP 14 — Schedule the log parsers and bulletin clean-up"
# ============================================================================

if [[ "$INSTALL_DB" == "y" ]]; then
    explain "Every 5 minutes, three small programs read the BPQ logs into the"
    explain "database so the RF Connections, Traffic and System Logs pages load"
    explain "fast. Once a day, bulletins older than 14 days are cleaned up. Every"
    explain "5 minutes the text of new BBS messages is saved to the database."
    mkdir -p /var/log/bpq-dashboard && chown "$WEB_USER:$WEB_USER" /var/log/bpq-dashboard
    cat > /etc/cron.d/bpq-dashboard <<CRON
# BPQ Dashboard log parsers — installed by install.sh on $(date +%F)
*/5 * * * * $WEB_USER /usr/bin/python3 $WEB_ROOT/scripts/parse-vara-log.py --bbs-log-dir $WEB_ROOT/logs --my-callsign $INPUT_CALL >> /var/log/bpq-dashboard/parse-vara-log.log 2>&1
*/5 * * * * $WEB_USER /usr/bin/python3 $WEB_ROOT/scripts/parse-traffic-report.py --days 8 >> /var/log/bpq-dashboard/parse-traffic-report.log 2>&1
*/5 * * * * $WEB_USER /usr/bin/python3 $WEB_ROOT/scripts/parse-system-logs.py >> /var/log/bpq-dashboard/parse-system-logs.log 2>&1
CRON
    chmod 644 /etc/cron.d/bpq-dashboard && ok "Log parsers scheduled (/etc/cron.d/bpq-dashboard)"
    for unit in bulletin-retention.service bulletin-retention.timer message-bodies.service message-bodies.timer; do
        if [[ -f "$WEB_ROOT/$unit" ]]; then
            sed "s|/var/www/bpq-dashboard|$WEB_ROOT|g" "$WEB_ROOT/$unit" > "/etc/systemd/system/$unit"
        fi
    done
    systemctl daemon-reload >/dev/null 2>&1
    if systemctl enable --now bulletin-retention.timer >/dev/null 2>&1; then
        ok "Daily bulletin clean-up enabled (bulletin-retention.timer)"
    else
        warn "Could not enable bulletin-retention.timer — run: sudo systemctl enable --now bulletin-retention.timer"
    fi
    if systemctl enable --now message-bodies.timer >/dev/null 2>&1; then
        ok "Message text saved every 5 minutes (message-bodies.timer)"
    else
        warn "Could not enable message-bodies.timer — run: sudo systemctl enable --now message-bodies.timer"
    fi
else
    say "Skipping — the parsers and bulletin clean-up need the database."
fi

# NWS automatic bulletins (no database needed). The units are always
# installed so the feature can be switched on later; the timer is only
# enabled when it was chosen in Step 1.
for unit in nws-auto-post.service nws-auto-post.timer; do
    if [[ -f "$WEB_ROOT/$unit" ]]; then
        sed "s|/var/www/bpq-dashboard|$WEB_ROOT|g" "$WEB_ROOT/$unit" > "/etc/systemd/system/$unit"
    fi
done
systemctl daemon-reload >/dev/null 2>&1
if [[ "$NWS_AUTO" == "y" ]]; then
    if systemctl enable --now nws-auto-post.timer >/dev/null 2>&1; then
        ok "NWS Warnings/Watches for $NWS_STATE posted to $NWS_DEST every 5 minutes (nws-auto-post.timer)"
    else
        warn "Could not enable nws-auto-post.timer — run: sudo systemctl enable --now nws-auto-post.timer"
    fi
else
    say "Automatic NWS bulletins off. To turn on later: set 'nws' => 'auto_post' in config.php"
    say "('enabled' => true, 'area' => 'XX'), then: sudo systemctl enable --now nws-auto-post.timer"
fi

# VARA HF log receiver (Step 1 question; Installation Manual §10.11)
if [[ "$VARA_SYSLOG" == "y" ]]; then
    RSYS_CONF=/etc/rsyslog.d/10-bpq-dashboard-vara.conf
    if grep -qsE '^[[:space:]]*(module\(load="imudp"|\$ModLoad[[:space:]]+imudp|\$UDPServerRun)' \
            /etc/rsyslog.conf /etc/rsyslog.d/*.conf && [[ ! -f "$RSYS_CONF" ]]; then
        warn "rsyslog already receives UDP syslog (another config loads imudp) - left as it is."
        warn "Make sure it accepts $VARA_IP on port 514 (Installation Manual §10.11)."
    else
        cat > "$RSYS_CONF" <<RSYS
# BPQ Dashboard (install.sh, $(date +%F)): receive VARA HF's syslog on UDP 514.
# Only these senders are accepted (add another with a comma). VARA HF:
# Settings > VARA Setup > Syslog, Host = this server, port 514,
# Station Name = H-<call-SSID>. Installation Manual §10.11.
\$AllowedSender UDP, 127.0.0.1, $VARA_IP
module(load="imudp")
input(type="imudp" port="514")
RSYS
        if rsyslogd -N1 >/dev/null 2>&1; then
            systemctl enable rsyslog >/dev/null 2>&1
            if systemctl restart rsyslog >/dev/null 2>&1; then
                ok "rsyslog receives VARA HF's log on UDP 514 from $VARA_IP ($RSYS_CONF)"
            else
                warn "rsyslog didn't restart - check: sudo systemctl status rsyslog"
            fi
        else
            rm -f "$RSYS_CONF"
            warn "rsyslog rejected the receiver config - removed it. See Installation Manual §10.11."
        fi
    fi
    if [[ "$VARA_IP" != "127.0.0.1" ]] && command -v ufw >/dev/null 2>&1 \
            && ufw status 2>/dev/null | grep -q "Status: active"; then
        ufw allow from "$VARA_IP" to any port 514 proto udp comment 'VARA HF syslog' >/dev/null 2>&1 \
            && ok "Firewall (ufw): UDP 514 open to $VARA_IP only"
    elif [[ "$VARA_IP" != "127.0.0.1" ]]; then
        say "If this server runs a firewall, allow UDP 514 from $VARA_IP only."
    fi
    if [[ -f /etc/systemd/system/vara-logger.service ]]; then
        systemctl enable --now vara-logger >/dev/null 2>&1 \
            && ok "vara-logger started: VARA lines go to $WEB_ROOT/logs/$INPUT_CALL_LC.vara"
    fi
    say "In VARA HF: Settings > VARA Setup > tick Syslog; Syslog Host = $( [[ "$VARA_IP" == "127.0.0.1" ]] && echo "localhost" || echo "this server's IP" ),"
    say "port 514; Station Name = H-<your VARA call-SSID>, e.g. H-${INPUT_CALL}-7."
fi

# ============================================================================
hdr "STEP 15 — Final permissions sweep"
# ============================================================================

chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT/cache" "$WEB_ROOT/data" 2>/dev/null
chmod 750 "$WEB_ROOT/cache" "$WEB_ROOT/data" 2>/dev/null
chmod 640 "$CFG"
# 2026-10-05: older installs (upgrades) may still have per-feature config
# files holding passwords; keep them out of reach of other local accounts.
for f in db-config.php bbs-config.php nws-config.php tprfn-config.php bpqdash-config.php; do
    if [[ -f "$WEB_ROOT/$f" ]]; then
        chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/$f"
        chmod 640 "$WEB_ROOT/$f"
    fi
done
ok "Permissions tightened"

# 2026-10-25: read-only sudo rules for the Firewall Status and System Audit
# pages (iptables/ip6tables -L, fail2ban-client status, ss -tlnp, nginx -t).
# Without them those pages say which rows they couldn't check.
if [[ -f "$WEB_ROOT/www-data-tools.sudoers" ]]; then
    explain "The Firewall Status and System Audit admin pages read the firewall,"
    explain "fail2ban and listening ports through a few read-only sudo rules"
    explain "(listed in www-data-tools.sudoers). They can't change anything."
    if yesno "Install those sudo rules (/etc/sudoers.d/www-data-tools)?" Y; then
        TMP_SUDO=$(mktemp)
        sed "s/^www-data /$WEB_USER /" "$WEB_ROOT/www-data-tools.sudoers" > "$TMP_SUDO"
        if visudo -cf "$TMP_SUDO" >/dev/null 2>&1; then
            install -m 440 -o root -g root "$TMP_SUDO" /etc/sudoers.d/www-data-tools \
                && ok "Admin-page sudo rules installed (/etc/sudoers.d/www-data-tools)"
        else
            warn "www-data-tools.sudoers did not pass visudo -c — not installed"
        fi
        rm -f "$TMP_SUDO"
    else
        say "Skipped. Later: sudo cp $WEB_ROOT/www-data-tools.sudoers /etc/sudoers.d/www-data-tools && sudo chmod 440 /etc/sudoers.d/www-data-tools"
    fi
fi

# ============================================================================
hdr "DONE — Summary"
# ============================================================================

# Get the server's primary IP for the "open this URL" message
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[[ -z "$SERVER_IP" ]] && SERVER_IP="<your-server-ip>"

echo
echo "${GRN}${BOLD}Installation finished.${NC}"
echo
echo "${BOLD}Results:${NC}"
echo "  ${GRN}✓${NC} $PASS steps succeeded"
echo "  ${YLW}⚠${NC} $WARN warnings"
echo "  ${RED}✗${NC} $FAIL errors"
echo

if (( WARN > 0 )); then
    echo "${YLW}${BOLD}Warnings:${NC}"
    for w in "${WARNINGS[@]}"; do echo "  - $w"; done
    echo
fi
if (( FAIL > 0 )); then
    echo "${RED}${BOLD}Errors:${NC}"
    for e in "${ERRORS[@]}"; do echo "  - $e"; done
    echo
fi

cat <<EOF
${BOLD}Open your dashboard:${NC}

   ${CYN}http://$SERVER_IP/${NC}                           ← home (RF Connections page)
   ${CYN}http://$SERVER_IP/bpq-system-logs.html${NC}       ← live BPQ logs
   ${CYN}http://$SERVER_IP/nws-dashboard.html${NC}         ← NWS weather alerts$( [[ "$NWS_AUTO" == "y" ]] && echo " (auto-posting $NWS_STATE to $NWS_DEST)" )
   ${CYN}http://$SERVER_IP/bbs-messages.html${NC}          ← BBS messages
   ${CYN}http://$SERVER_IP/admin/system-audit.html${NC}    ← server health check (admin login)
   ${CYN}http://$SERVER_IP/install-check.php${NC}          ← installation self-test (admin login)

${BOLD}Configuration file:${NC}
   $CFG
   ${DIM}Edit this if you need to change your callsign, BPQ password,
   or any other setting later. After editing, no restart is needed —
   PHP picks up changes on the next request.${NC}

${BOLD}Web root:${NC}
   $WEB_ROOT
   ${DIM}This is where all the HTML / PHP / Python files live.${NC}

${BOLD}Install log:${NC}
   $LOG
   ${DIM}If something doesn't work, this log has the full record of what
   the installer did. Send it if you ask for help.${NC}

${BOLD}Next steps:${NC}
   1. Open the home URL above in your browser
   2. If pages load but charts are empty, give it 5 minutes for the
      helper daemons to gather data
   3. Open install-check.php (above) — every red line shows how to fix it
   4. See USER-MANUAL.md, section 4 ("After installing"), to switch on
      the helper daemons, the VARA logger and APRS

73 and good luck with the dashboard!
EOF
