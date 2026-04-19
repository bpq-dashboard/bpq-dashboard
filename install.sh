#!/bin/bash
# ╔══════════════════════════════════════════════════════════════════════╗
# ║  BPQ Dashboard v1.5.6 — Guided Installation Script                  ║
# ║  For Debian / Ubuntu / Raspberry Pi OS                               ║
# ║                                                                      ║
# ║  HOW TO RUN:                                                         ║
# ║    1. Copy bpq-dashboard-v1.5.6-deploy.zip to your Linux machine     ║
# ║    2. unzip bpq-dashboard-v1.5.6-deploy.zip                          ║
# ║    3. cd BPQ-Dashboard-deploy                                         ║
# ║    4. sudo bash install.sh                                            ║
# ╚══════════════════════════════════════════════════════════════════════╝
# Installation order (dependency-safe):
#  1  Gather all configuration from the user
#  2  System package update
#  3  Install core packages (nginx, PHP, Python, utilities)
#  4  Start and enable core services
#  5  Python pip dependencies
#  6  Create web root and all required directories
#  7  Copy dashboard files
#  8  Generate config.php from collected settings
#  9  Patch daemon scripts with user settings
# 10  Configure nginx (after PHP-FPM is confirmed running)
# 11  Create log symlink to LinBPQ directory
# 12  Check LinBPQ telnet reachability
# 13  Install systemd daemon services
# 14  Configure root crontab jobs
# 15  Create log files
# 16  Configure firewall (iptables)
# 17  Final permissions sweep
# 18  Summary and next steps

# ── Colours ────────────────────────────────────────────────────────────
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[0;33m'
BLU='\033[0;34m'; CYN='\033[0;36m'; WHT='\033[1;37m'
BOLD='\033[1m';   NC='\033[0m'

# ── Counters and log ───────────────────────────────────────────────────
PASS=0; FAIL=0; WARN=0
LOG="/tmp/bpq-dashboard-install-$(date +%Y%m%d-%H%M%S).log"
ERRORS=()
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Helpers ────────────────────────────────────────────────────────────
stamp()   { date '+%H:%M:%S'; }
tee_log() { tee -a "$LOG"; }
say()  { echo -e "$(stamp) ${WHT}[....] $*${NC}" | tee_log; }
ok()   { echo -e "$(stamp) ${GRN}[ OK ] $*${NC}" | tee_log; ((PASS++)); }
warn() { echo -e "$(stamp) ${YLW}[WARN] $*${NC}" | tee_log; ((WARN++)); }
err()  { echo -e "$(stamp) ${RED}[FAIL] $*${NC}" | tee_log; ((FAIL++)); ERRORS+=("$*"); }
hdr()  { echo -e "\n$(stamp) ${BOLD}${CYN}╔═══ $* ═══╗${NC}" | tee_log; }
ask()  { echo -e "\n${BOLD}${YLW}▶ $*${NC}"; }
yesno(){ read -r -p "  $* [y/N]: " _yn; [[ "$_yn" =~ ^[Yy] ]]; }
die()  { echo -e "\n${RED}${BOLD}FATAL: $*${NC}\nLog: $LOG"; exit 1; }

# ── Must be root ───────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && die "Please run with sudo:\n  sudo bash install.sh"

# ── Welcome ────────────────────────────────────────────────────────────
clear
echo -e "${BOLD}${CYN}"
cat << 'BANNER'
  ╔══════════════════════════════════════════════════════════════╗
  ║         BPQ Dashboard v1.5.6 — Guided Installer             ║
  ║           Amateur Radio Packet Node Operations               ║
  ╚══════════════════════════════════════════════════════════════╝
BANNER
echo -e "${NC}"
echo -e "This script installs BPQ Dashboard on your Linux server."
echo -e "It will install ${BOLD}nginx${NC}, ${BOLD}PHP${NC}, ${BOLD}Python 3${NC} and all required components."
echo -e "Log saved to: ${BLU}$LOG${NC}\n"
echo -e "${YLW}Time required: approximately 5–15 minutes.${NC}"
echo -e "${YLW}You will be asked several questions. Press ENTER to accept defaults shown in [brackets].${NC}\n"
echo -e "${BOLD}Before you start, have these ready:${NC}"
echo -e "  • Your callsign and grid square"
echo -e "  • Your latitude and longitude (Google Maps → right-click → copy coordinates)"
echo -e "  • Your BPQ telnet port (from bpq32.cfg — look for TCPPORT= in TELNET section)"
echo -e "  • Your BPQ sysop username and password (from bpq32.cfg USER= line)"
echo -e "  • The path to your LinBPQ directory (where bpq32.cfg lives)\n"
read -r -p "Press ENTER when ready..."

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 1 — GATHER CONFIGURATION"
# ═══════════════════════════════════════════════════════════════════════
echo -e "\n${BOLD}Station information${NC}"
echo -e "────────────────────────────────────────────────────────────\n"

# ── Callsign ──────────────────────────────────────────────────────────
ask "Your station callsign (letters and numbers only, e.g. W1AW):"
read -r INPUT_CALL
INPUT_CALL="${INPUT_CALL:-YOURCALL}"
INPUT_CALL="${INPUT_CALL^^}"
ok "Callsign: $INPUT_CALL"

# ── Node callsign (with SSID) ─────────────────────────────────────────
ask "Your BPQ node callsign with SSID (e.g. W1AW-4) [${INPUT_CALL}-4]:"
read -r INPUT_NODE
INPUT_NODE="${INPUT_NODE:-${INPUT_CALL}-4}"
INPUT_NODE="${INPUT_NODE^^}"
ok "Node: $INPUT_NODE"

# ── Grid square ───────────────────────────────────────────────────────
echo -e "  ${BLU}Find your 6-character grid square at: qrz.com or maidenhead.info${NC}"
ask "Your Maidenhead grid square (e.g. EM73kj) [EM00aa]:"
read -r INPUT_GRID
INPUT_GRID="${INPUT_GRID:-EM00aa}"
ok "Grid: $INPUT_GRID"

# ── Latitude / Longitude ──────────────────────────────────────────────
echo -e "  ${BLU}Find your coordinates: right-click on maps.google.com, copy the two numbers${NC}"
echo -e "  ${BLU}Example: 33.4735, -82.0105  (West longitudes are negative)${NC}"
ask "Your station latitude  (decimal, e.g. 33.4735) [0.0000]:"
read -r INPUT_LAT
INPUT_LAT="${INPUT_LAT:-0.0000}"
ok "Latitude: $INPUT_LAT"

ask "Your station longitude (decimal, e.g. -82.0105) [0.0000]:"
read -r INPUT_LON
INPUT_LON="${INPUT_LON:-0.0000}"
ok "Longitude: $INPUT_LON"

# ── Email ─────────────────────────────────────────────────────────────
ask "Sysop email address [${INPUT_CALL,,}@example.com]:"
read -r INPUT_EMAIL
INPUT_EMAIL="${INPUT_EMAIL:-${INPUT_CALL,,}@example.com}"
ok "Email: $INPUT_EMAIL"

echo -e "\n${BOLD}LinBPQ connection settings${NC}"
echo -e "────────────────────────────────────────────────────────────\n"

# ── LinBPQ directory ──────────────────────────────────────────────────
echo -e "  ${BLU}This is the directory where bpq32.cfg and log_*.txt files live.${NC}"
echo -e "  ${BLU}Common locations: /home/linbpq   /home/pi/linbpq   /home/YOUR_USER/linbpq${NC}"
echo -e "  ${BLU}Not sure? Run: find /home -name bpq32.cfg 2>/dev/null${NC}"
ask "Path to your LinBPQ directory [/home/linbpq]:"
read -r INPUT_LINBPQ
LINBPQ_DIR="${INPUT_LINBPQ:-/home/linbpq}"
# Trim trailing slash
LINBPQ_DIR="${LINBPQ_DIR%/}"
if [[ -d "$LINBPQ_DIR" ]]; then
    ok "LinBPQ directory found: $LINBPQ_DIR"
else
    warn "Directory $LINBPQ_DIR not found — enter correct path above or create it later"
fi

# ── BPQ telnet port ───────────────────────────────────────────────────
echo -e "  ${BLU}Check bpq32.cfg: look for TCPPORT= in the TELNET section. Usually 8010.${NC}"
ask "LinBPQ telnet port [8010]:"
read -r INPUT_BPQ_PORT
INPUT_BPQ_PORT="${INPUT_BPQ_PORT:-8010}"
ok "BPQ telnet port: $INPUT_BPQ_PORT"

# ── BPQ HTTP management port ──────────────────────────────────────────
echo -e "  ${BLU}The BPQ HTTP management interface port. Usually 8008.${NC}"
echo -e "  ${BLU}Check bpq32.cfg: look for PORT= in the [HTTPSERVER] section.${NC}"
ask "LinBPQ HTTP management port [8008]:"
read -r INPUT_BPQ_HTTP
INPUT_BPQ_HTTP="${INPUT_BPQ_HTTP:-8008}"
ok "BPQ HTTP port: $INPUT_BPQ_HTTP"

# ── BPQ sysop username ────────────────────────────────────────────────
echo -e "  ${BLU}Check bpq32.cfg for the USER= line. Format: USER=CALLSIGN,PASSWORD,...${NC}"
ask "Your BPQ sysop username/callsign [${INPUT_CALL}]:"
read -r INPUT_BPQ_USER
INPUT_BPQ_USER="${INPUT_BPQ_USER:-$INPUT_CALL}"
INPUT_BPQ_USER="${INPUT_BPQ_USER^^}"
ok "BPQ user: $INPUT_BPQ_USER"

# ── BPQ sysop password ────────────────────────────────────────────────
echo -e "  ${BLU}This is the password from the USER= line in bpq32.cfg (after the first comma).${NC}"
echo -e "  ${BLU}It is also used for the BPQ HTTP management interface (RIGRECONFIG).${NC}"
ask "Your BPQ sysop password (input hidden):"
read -r -s INPUT_BPQ_PASS
echo ""
INPUT_BPQ_PASS="${INPUT_BPQ_PASS:-changeme}"
ok "BPQ password: (set)"

echo -e "\n${BOLD}Web server settings${NC}"
echo -e "────────────────────────────────────────────────────────────\n"

# ── Server IP / hostname ──────────────────────────────────────────────
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
HOSTNAME_AUTO=$(hostname -f 2>/dev/null || hostname)
echo -e "  ${BLU}Your server IP address is: ${WHT}${SERVER_IP}${NC}"
echo -e "  ${BLU}If you don't have a domain name just press ENTER to use the IP address.${NC}"
ask "Server hostname or IP address [$SERVER_IP]:"
read -r INPUT_HOST
INPUT_HOST="${INPUT_HOST:-$SERVER_IP}"
ok "Server: $INPUT_HOST"

# ── Web root ──────────────────────────────────────────────────────────
ask "Web root directory [/var/www/html/bpq]:"
read -r INPUT_WEBROOT
WEB_ROOT="${INPUT_WEBROOT:-/var/www/html/bpq}"
WEB_ROOT="${WEB_ROOT%/}"
ok "Web root: $WEB_ROOT"

echo -e "\n${BOLD}Database settings${NC}"
echo -e "────────────────────────────────────────────────────────────\n"
echo -e "  ${BLU}MariaDB (MySQL) is used for two features:${NC}"
echo -e "  ${BLU}  • VARA HF callsign allowlist (controls who can connect via VARA terminal)${NC}"
echo -e "  ${BLU}  • Network session history beyond 5 days on RF Connections page${NC}"
echo -e "  ${BLU}Without a database these features are disabled but everything else works fine.${NC}"
if yesno "Install MariaDB database? (recommended — required for VARA allowlist)"; then
    HAS_DB=Y
    ask "Choose a MariaDB root password (strong, write it down) [BpqDash2025!]:"
    read -r -s INPUT_DB_ROOT_PASS; echo ""; INPUT_DB_ROOT_PASS="${INPUT_DB_ROOT_PASS:-BpqDash2025!}"
    ok "Database root password: (set)"
    INPUT_DB_NAME="bpqdash"
    INPUT_DB_USER="bpqdash_user"
    INPUT_DB_PASS=$(cat /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 16 2>/dev/null || echo "BpqDb$(shuf -i 100000-999999 -n1)!")
    ok "Database '$INPUT_DB_NAME' will be created with auto-generated user password"
else
    HAS_DB=N
    INPUT_DB_ROOT_PASS=""; INPUT_DB_NAME="bpqdash"
    INPUT_DB_USER="bpqdash_user"; INPUT_DB_PASS=""
    say "Database skipped — VARA allowlist and extended history will be unavailable"
fi

echo -e "\n${BOLD}Optional features${NC}"
echo -e "────────────────────────────────────────────────────────────\n"

# ── APRS ──────────────────────────────────────────────────────────────
echo -e "  ${BLU}The APRS Map shows stations heard through your node on a live map.${NC}"
if yesno "Enable APRS map? (requires a free APRS-IS passcode)"; then
    HAS_APRS=Y
    INPUT_APRS_CALL="${INPUT_CALL}-1"
    echo -e "  ${BLU}Your APRS callsign is usually YOURCALL-1. Press ENTER to accept.${NC}"
    ask "APRS-IS callsign [${INPUT_APRS_CALL}]:"
    read -r _ac; INPUT_APRS_CALL="${_ac:-$INPUT_APRS_CALL}"; INPUT_APRS_CALL="${INPUT_APRS_CALL^^}"
    echo -e "  ${BLU}Get your free passcode at: https://apps.magicbug.co.uk/passcode/${NC}"
    echo -e "  ${BLU}Enter your callsign (no SSID) to get a number, e.g. 15769${NC}"
    ask "APRS-IS passcode (number from magicbug.co.uk) [0]:"
    read -r INPUT_APRS_PASS; INPUT_APRS_PASS="${INPUT_APRS_PASS:-0}"
    ok "APRS: $INPUT_APRS_CALL / passcode set"
else
    HAS_APRS=N
    INPUT_APRS_CALL="${INPUT_CALL}-1"
    INPUT_APRS_PASS="0"
    say "APRS map skipped — can be enabled later by editing config.php"
fi

# ── VARA HF Terminal ──────────────────────────────────────────────────
echo ""
echo -e "  ${BLU}The VARA HF Terminal lets you make keyboard-to-keyboard HF connections from your browser.${NC}"
echo -e "  ${BLU}Requires: VARA HF modem software running (on this machine or a Windows PC on your LAN).${NC}"
if yesno "Enable VARA HF Terminal?"; then
    HAS_VARA=Y
    echo -e "  ${BLU}Is VARA HF running on THIS Linux machine (localhost) or a Windows PC?${NC}"
    ask "VARA HF host IP [127.0.0.1]:"
    read -r INPUT_VARA_HOST; INPUT_VARA_HOST="${INPUT_VARA_HOST:-127.0.0.1}"
    echo -e "  ${BLU}Check VARA Settings → TCP Ports for the command port. Usually 8300 or 9025.${NC}"
    ask "VARA HF command port [8300]:"
    read -r INPUT_VARA_PORT; INPUT_VARA_PORT="${INPUT_VARA_PORT:-8300}"
    echo -e "  ${BLU}Which BPQ port number drives VARA HF? Check bpq32.cfg for PORTNUM= in the VARA section.${NC}"
    ask "BPQ VARA HF port number [3]:"
    read -r INPUT_BPQ_VARA_PORT; INPUT_BPQ_VARA_PORT="${INPUT_BPQ_VARA_PORT:-3}"
    echo ""
    echo -e "  ${BLU}flrig provides rig frequency control so the radio auto-QSYs when you pick a frequency.${NC}"
    echo -e "  ${BLU}flrig must be running on a machine with the radio connected.${NC}"
    if yesno "Do you use flrig for rig control?"; then
        HAS_FLRIG=Y
        ask "flrig host IP [${INPUT_VARA_HOST}]:"
        read -r INPUT_FLRIG_HOST; INPUT_FLRIG_HOST="${INPUT_FLRIG_HOST:-$INPUT_VARA_HOST}"
        ask "flrig XML-RPC port [12345]:"
        read -r INPUT_FLRIG_PORT; INPUT_FLRIG_PORT="${INPUT_FLRIG_PORT:-12345}"
        echo -e "  ${BLU}Path to bpq32.cfg on this server (needed for frequency scan restore).${NC}"
        ask "Path to bpq32.cfg [${LINBPQ_DIR}/bpq32.cfg]:"
        read -r INPUT_BPQ32_CFG; INPUT_BPQ32_CFG="${INPUT_BPQ32_CFG:-${LINBPQ_DIR}/bpq32.cfg}"
        ok "flrig: ${INPUT_FLRIG_HOST}:${INPUT_FLRIG_PORT}"
        ok "bpq32.cfg: $INPUT_BPQ32_CFG"
    else
        HAS_FLRIG=N
        INPUT_FLRIG_HOST="${INPUT_VARA_HOST}"
        INPUT_FLRIG_PORT="12345"
        INPUT_BPQ32_CFG="${LINBPQ_DIR}/bpq32.cfg"
    fi
    echo ""
    echo -e "  ${BLU}Radio model — used to select the correct digital mode for VARA HF.${NC}"
    echo -e "  ${BLU}Leave blank to auto-detect from flrig (recommended).${NC}"
    echo -e "  ${BLU}Supported: FTdx3000, FTdx10, FT-991, FT-710, FT-891, FT-450, FT-897,${NC}"
    echo -e "  ${BLU}           IC-7300, IC-7610, IC-705, IC-7100, IC-7200, IC-7600, IC-7700,${NC}"
    echo -e "  ${BLU}           IC-7800, IC-9700, TS-590, TS-890, TS-2000, K3, KX3, K4${NC}"
    ask "Radio model (blank = auto-detect) []:"
    read -r INPUT_RADIO_MODEL
    INPUT_RADIO_MODEL="${INPUT_RADIO_MODEL:-}"
    if [[ -n "$INPUT_RADIO_MODEL" ]]; then
        ok "Radio model: $INPUT_RADIO_MODEL"
    else
        ok "Radio model: auto-detect from flrig"
    fi
    ok "VARA HF: ${INPUT_VARA_HOST}:${INPUT_VARA_PORT} BPQ port ${INPUT_BPQ_VARA_PORT}"
else
    HAS_VARA=N
    INPUT_VARA_HOST="127.0.0.1"; INPUT_VARA_PORT="8300"
    INPUT_BPQ_VARA_PORT="3";     INPUT_FLRIG_HOST="127.0.0.1"
    INPUT_FLRIG_PORT="12345";    INPUT_BPQ32_CFG="${LINBPQ_DIR}/bpq32.cfg"
    INPUT_RADIO_MODEL=""
    say "VARA HF terminal will be installed but inactive until VARA HF is set up"
fi

# ── WaveNode RF Power Monitor ─────────────────────────────────────────
echo ""
echo -e "  ${BLU}WaveNode is an RF wattmeter that writes DataLog files. The RF Power Monitor${NC}"
echo -e "  ${BLU}displays power and SWR data from those files. Requires a WaveNode device.${NC}"
if yesno "Do you have a WaveNode wattmeter?"; then
    HAS_WAVENODE=Y
    echo -e "  ${BLU}The Windows PC running WaveNode needs OpenSSH Server installed.${NC}"
    echo -e "  ${BLU}Windows: Settings → Apps → Optional Features → Add → OpenSSH Server${NC}"
    ask "Windows PC IP address running WaveNode [192.168.1.100]:"
    read -r INPUT_WN_HOST; INPUT_WN_HOST="${INPUT_WN_HOST:-192.168.1.100}"
    ask "Windows username [tony]:"
    read -r INPUT_WN_USER; INPUT_WN_USER="${INPUT_WN_USER:-tony}"
    ok "WaveNode: ${INPUT_WN_USER}@${INPUT_WN_HOST}"
else
    HAS_WAVENODE=N
    INPUT_WN_HOST=""; INPUT_WN_USER="tony"
    say "WaveNode sync skipped — can be set up later"
fi

# ── Confirm before proceeding ─────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYN}║                  CONFIGURATION SUMMARY                      ║${NC}"
echo -e "${BOLD}${CYN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${WHT}Callsign:${NC}        $INPUT_CALL"
echo -e "  ${WHT}Node:${NC}            $INPUT_NODE"
echo -e "  ${WHT}Grid:${NC}            $INPUT_GRID"
echo -e "  ${WHT}Lat/Lon:${NC}         $INPUT_LAT, $INPUT_LON"
echo -e "  ${WHT}Email:${NC}           $INPUT_EMAIL"
echo -e "  ${WHT}LinBPQ dir:${NC}      $LINBPQ_DIR"
echo -e "  ${WHT}BPQ telnet:${NC}      localhost:$INPUT_BPQ_PORT  (user: $INPUT_BPQ_USER)"
echo -e "  ${WHT}BPQ HTTP:${NC}        localhost:$INPUT_BPQ_HTTP"
echo -e "  ${WHT}Web root:${NC}        $WEB_ROOT"
echo -e "  ${WHT}Server:${NC}          http://$INPUT_HOST/"
echo -e "  ${WHT}APRS:${NC}            $([[ $HAS_APRS == Y ]] && echo "$INPUT_APRS_CALL" || echo 'disabled')"
echo -e "  ${WHT}VARA HF:${NC}         $([[ $HAS_VARA == Y ]] && echo "${INPUT_VARA_HOST}:${INPUT_VARA_PORT} BPQ port ${INPUT_BPQ_VARA_PORT}" || echo 'disabled')"
echo -e "  ${WHT}flrig:${NC}           $([[ $HAS_FLRIG == Y ]] && echo "${INPUT_FLRIG_HOST}:${INPUT_FLRIG_PORT}" || echo 'disabled')
  ${WHT}Radio model:${NC}     ${INPUT_RADIO_MODEL:-auto-detect from flrig}"
echo -e "  ${WHT}WaveNode:${NC}        $([[ $HAS_WAVENODE == Y ]] && echo "${INPUT_WN_USER}@${INPUT_WN_HOST}" || echo 'not configured')
  ${WHT}Database:${NC}        $([[ $HAS_DB == Y ]] && echo "MariaDB — $INPUT_DB_NAME (user: $INPUT_DB_USER)" || echo 'skipped (VARA allowlist disabled)')"
echo ""
if ! yesno "Everything looks correct — proceed with installation?"; then
    echo "Installation cancelled. Re-run the script to start over."
    exit 0
fi

# ── Derived paths ─────────────────────────────────────────────────────
SCRIPTS_DIR="$WEB_ROOT/scripts"
CACHE_DIR="$WEB_ROOT/cache"
DATA_DIR="$WEB_ROOT/data"
IMG_DIR="$WEB_ROOT/img"
WN_LOGS_DIR="$WEB_ROOT/wavenode-logs"
WEB_USER="www-data"

echo -e "\n${GRN}${BOLD}Configuration confirmed. Starting installation...${NC}\n"
sleep 1

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 2 — SYSTEM UPDATE"
# ═══════════════════════════════════════════════════════════════════════
say "Updating package lists..."
if apt-get update -qq >> "$LOG" 2>&1; then
    ok "Package lists updated"
else
    warn "Package update had warnings — continuing (check $LOG if problems occur)"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 3 — INSTALL CORE PACKAGES"
# ═══════════════════════════════════════════════════════════════════════
# Install in dependency-safe order:
# 1. Python3 and pip first (needed by later steps)
# 2. PHP-FPM (detect version)
# 3. nginx (requires PHP-FPM socket to exist)
# 4. Security and utilities last

say "Installing Python 3 and pip..."
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3 python3-pip python3-venv >> "$LOG" 2>&1 \
    && ok "Python 3 installed" || err "Python 3 install failed"

say "Detecting and installing PHP-FPM..."
PHP_VER=""
for v in 8.3 8.2 8.1 8.4 7.4; do
    if apt-cache show "php${v}-fpm" >> "$LOG" 2>&1; then
        PHP_VER="$v"
        say "PHP ${PHP_VER} available — installing..."
        break
    fi
done
[[ -z "$PHP_VER" ]] && die "No PHP version found in apt. Run: sudo apt update and try again."

PHP_PACKAGES=(
    "php${PHP_VER}-fpm"
    "php${PHP_VER}-cli"
    "php${PHP_VER}-curl"
    "php${PHP_VER}-mbstring"
    "php${PHP_VER}-xml"
    "php${PHP_VER}-zip"
    "php${PHP_VER}-sockets"
)

for pkg in "${PHP_PACKAGES[@]}"; do
    if dpkg -l "$pkg" 2>/dev/null | grep -q "^ii"; then
        ok "Already installed: $pkg"
    else
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$pkg" >> "$LOG" 2>&1 \
            && ok "Installed: $pkg" || warn "Could not install $pkg — some features may not work"
    fi
done

say "Installing nginx..."
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nginx >> "$LOG" 2>&1 \
    && ok "nginx installed" || err "nginx install failed"

# Install MariaDB if requested — before nginx/PHP so it is available when needed
if [[ "$HAS_DB" == "Y" ]]; then
    say "Installing MariaDB..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server >> "$LOG" 2>&1         && ok "MariaDB installed" || err "MariaDB install failed — database features will not work"
fi

say "Installing utility packages..."
UTILS=(curl wget unzip git logrotate iptables-persistent fail2ban netcat-openbsd)
for pkg in "${UTILS[@]}"; do
    if dpkg -l "$pkg" 2>/dev/null | grep -q "^ii"; then
        ok "Already installed: $pkg"
    else
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$pkg" >> "$LOG" 2>&1 \
            && ok "Installed: $pkg" || warn "Could not install $pkg"
    fi
done

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 4 — START AND ENABLE CORE SERVICES"
# ═══════════════════════════════════════════════════════════════════════
# PHP-FPM must start before nginx (nginx needs the PHP socket)

PHP_FPM="php${PHP_VER}-fpm"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

say "Starting PHP-FPM ${PHP_VER}..."
systemctl enable "$PHP_FPM" >> "$LOG" 2>&1
if systemctl restart "$PHP_FPM" >> "$LOG" 2>&1; then
    sleep 1
    if [[ -S "$PHP_SOCK" ]]; then
        ok "PHP-FPM ${PHP_VER} running (socket: $PHP_SOCK)"
    else
        err "PHP-FPM started but socket $PHP_SOCK not found — nginx PHP processing will fail"
    fi
else
    err "PHP-FPM failed to start — check: sudo journalctl -u $PHP_FPM -n 20"
fi

say "Starting nginx..."
# Remove conflicting default site first
[[ -f /etc/nginx/sites-enabled/default ]] && rm /etc/nginx/sites-enabled/default && say "Removed nginx default site"
systemctl enable nginx >> "$LOG" 2>&1
if systemctl restart nginx >> "$LOG" 2>&1; then
    ok "nginx running"
else
    err "nginx failed to start — check: sudo journalctl -u nginx -n 20"
fi

say "Starting fail2ban..."
systemctl enable fail2ban >> "$LOG" 2>&1
systemctl restart fail2ban >> "$LOG" 2>&1 && ok "fail2ban running" || warn "fail2ban failed to start"

if [[ "$HAS_DB" == "Y" ]]; then
    say "Starting MariaDB..."
    systemctl enable mariadb >> "$LOG" 2>&1
    if systemctl restart mariadb >> "$LOG" 2>&1; then
        ok "MariaDB running"
    else
        err "MariaDB failed to start — check: sudo journalctl -u mariadb -n 20"
    fi
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 5 — INSTALL PYTHON DEPENDENCIES"
# ═══════════════════════════════════════════════════════════════════════
# Must be after python3-pip is installed

say "Installing Python websockets library (required for daemon services)..."
pip3 install websockets --break-system-packages --quiet >> "$LOG" 2>&1 \
    && ok "websockets installed" || warn "websockets pip install failed — daemons may not start"

say "Installing Python requests library (required for prop-scheduler and storm-monitor)..."
pip3 install requests --break-system-packages --quiet >> "$LOG" 2>&1 \
    && ok "requests installed" || warn "requests pip install failed — automation scripts may fail"

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 5b — CONFIGURE MARIADB"
# ═══════════════════════════════════════════════════════════════════════

if [[ "$HAS_DB" == "Y" ]]; then
    say "Securing MariaDB and creating bpqdash database..."

    # Secure installation and create database
    sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('${INPUT_DB_ROOT_PASS}');" >> "$LOG" 2>&1 || true
    sudo mysql -e "DELETE FROM mysql.user WHERE User='';" >> "$LOG" 2>&1 || true
    sudo mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');" >> "$LOG" 2>&1 || true
    sudo mysql -e "DROP DATABASE IF EXISTS test;" >> "$LOG" 2>&1 || true
    sudo mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\_%';" >> "$LOG" 2>&1 || true

    DB_OK=1
    sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${INPUT_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >> "$LOG" 2>&1 || DB_OK=0
    sudo mysql -e "CREATE USER IF NOT EXISTS '${INPUT_DB_USER}'@'localhost' IDENTIFIED BY '${INPUT_DB_PASS}';" >> "$LOG" 2>&1 || DB_OK=0
    sudo mysql -e "GRANT ALL PRIVILEGES ON \`${INPUT_DB_NAME}\`.* TO '${INPUT_DB_USER}'@'localhost';" >> "$LOG" 2>&1 || DB_OK=0
    sudo mysql -e "FLUSH PRIVILEGES;" >> "$LOG" 2>&1 || true

    if [[ $DB_OK -eq 1 ]]; then
        ok "MariaDB secured — database '$INPUT_DB_NAME' created"
        ok "Database user '$INPUT_DB_USER' created"
    else
        err "MariaDB setup had errors — check $LOG and run manually if needed"
    fi

    # Create VARA HF callsign allowlist table
    say "Creating VARA HF allowlist table..."
    sudo mysql "${INPUT_DB_NAME}" -e "
CREATE TABLE IF NOT EXISTS vara_allowed_stations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    callsign   VARCHAR(10) NOT NULL,
    name       VARCHAR(60) DEFAULT '',
    notes      VARCHAR(255) DEFAULT '',
    added_by   VARCHAR(10) DEFAULT 'SYSOP',
    added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    active     TINYINT(1) DEFAULT 1,
    UNIQUE KEY uq_callsign (callsign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" >> "$LOG" 2>&1 && ok "vara_allowed_stations table created" || warn "Table creation failed — run manually"

    # Import prop-decisions schema if present
    if [[ -f "$SCRIPT_DIR/data/prop-decisions-schema.sql" ]]; then
        mysql -u root -p"${INPUT_DB_ROOT_PASS}" "${INPUT_DB_NAME}"             < "$SCRIPT_DIR/data/prop-decisions-schema.sql" >> "$LOG" 2>&1             && ok "prop_decisions schema imported" || warn "Schema import had warnings"
    fi

    # Update tprfn-db.php with actual credentials
    if [[ -f "$WEB_ROOT/tprfn-db.php" ]]; then
        sed -i "s|define('TPRFN_DB_PASS', 'YOURDBPASSWORD');|define('TPRFN_DB_PASS', '${INPUT_DB_PASS}');|" "$WEB_ROOT/tprfn-db.php"
        sed -i "s|define('TPRFN_DB_NAME', 'bpqdash');|define('TPRFN_DB_NAME', '${INPUT_DB_NAME}');|" "$WEB_ROOT/tprfn-db.php"
        sed -i "s|define('TPRFN_DB_USER', 'bpqdash_user');|define('TPRFN_DB_USER', '${INPUT_DB_USER}');|" "$WEB_ROOT/tprfn-db.php"
        chmod 640 "$WEB_ROOT/tprfn-db.php"
        chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/tprfn-db.php"
        ok "tprfn-db.php credentials updated"
    fi
else
    say "Database skipped — tprfn-db.php will report unavailable and features will degrade gracefully"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 6 — CREATE WEB ROOT AND DIRECTORIES"
# ═══════════════════════════════════════════════════════════════════════

DIRS=(
    "$WEB_ROOT"
    "$SCRIPTS_DIR"
    "$CACHE_DIR"
    "$CACHE_DIR/aprs"
    "$CACHE_DIR/chat-sessions"
    "$CACHE_DIR/vara-sessions"
    "$CACHE_DIR/telnet-sessions"
    "$CACHE_DIR/network"
    "$DATA_DIR"
    "$DATA_DIR/messages"
    "$DATA_DIR/stations"
    "$DATA_DIR/backups"
    "$IMG_DIR"
    "$WN_LOGS_DIR"
)

for dir in "${DIRS[@]}"; do
    mkdir -p "$dir" && ok "Directory: $dir" || err "Failed to create: $dir"
done

# Set initial ownership
chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"
chmod -R 775 "$CACHE_DIR" "$DATA_DIR" "$WN_LOGS_DIR"
ok "Base permissions set"

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 7 — COPY DASHBOARD FILES"
# ═══════════════════════════════════════════════════════════════════════

say "Copying HTML pages..."
for f in "$SCRIPT_DIR"/*.html; do
    [[ -f "$f" ]] || continue
    cp "$f" "$WEB_ROOT/"
    ok "HTML: $(basename "$f")"
done

say "Copying PHP files..."
for f in "$SCRIPT_DIR"/*.php; do
    [[ -f "$f" ]] || continue
    [[ "$(basename "$f")" == "config.php" ]] && continue  # generated separately
    cp "$f" "$WEB_ROOT/"
    ok "PHP: $(basename "$f")"
done

say "Copying shell scripts..."
for f in "$SCRIPT_DIR"/*.sh; do
    [[ -f "$f" ]] || continue
    cp "$f" "$WEB_ROOT/"
    chmod 755 "$WEB_ROOT/$(basename "$f")"
    ok "Script: $(basename "$f")"
done

say "Copying Python daemon scripts..."
for f in "$SCRIPT_DIR/scripts"/*.py; do
    [[ -f "$f" ]] || continue
    cp "$f" "$SCRIPTS_DIR/"
    chmod 755 "$SCRIPTS_DIR/$(basename "$f")"
    ok "Daemon: $(basename "$f")"
done

say "Copying nginx config..."
[[ -f "$SCRIPT_DIR/nginx-maintenance-block.conf" ]] && \
    cp "$SCRIPT_DIR/nginx-maintenance-block.conf" "$WEB_ROOT/" && ok "nginx config snippet copied"

say "Copying data files..."
[[ -f "$SCRIPT_DIR/data/partners.json" ]] && \
    cp "$SCRIPT_DIR/data/partners.json" "$DATA_DIR/" || echo '[]' > "$DATA_DIR/partners.json"
[[ -f "$SCRIPT_DIR/data/prop-decisions-schema.sql" ]] && \
    cp "$SCRIPT_DIR/data/prop-decisions-schema.sql" "$DATA_DIR/"
ok "Data files copied"

say "Copying service files to /usr/local/bin..."
for f in restore-radio2.sh wavenode-sync.sh sync-bpq-logs.sh; do
    if [[ -f "$SCRIPT_DIR/$f" ]]; then
        cp "$SCRIPT_DIR/$f" /usr/local/bin/
        chmod +x "/usr/local/bin/$f"
        ok "Installed: /usr/local/bin/$f"
    fi
done

say "Copying PDF manual..."
[[ -f "$SCRIPT_DIR/BPQ-Dashboard-v1.5.6-Manual.pdf" ]] && \
    cp "$SCRIPT_DIR/BPQ-Dashboard-v1.5.6-Manual.pdf" "$WEB_ROOT/" && \
    ok "PDF manual installed"

say "Copying shared JS files..."
if [[ -d "$SCRIPT_DIR/shared" ]]; then
    mkdir -p "$WEB_ROOT/shared"
    cp -r "$SCRIPT_DIR/shared/"* "$WEB_ROOT/shared/"
    ok "Shared JS files copied"
fi

say "Copying includes..."
if [[ -d "$SCRIPT_DIR/includes" ]]; then
    mkdir -p "$WEB_ROOT/includes"
    cp -r "$SCRIPT_DIR/includes/"* "$WEB_ROOT/includes/"
    ok "Includes copied"
fi

# Download APRS symbol sprites
if [[ "$HAS_APRS" == "Y" ]]; then
    say "Downloading APRS symbol sprites..."
    for pair in "aprs-symbols-pri.png|aprs-symbols-24-0.png" "aprs-symbols-alt.png|aprs-symbols-24-1.png"; do
        LOCAL="${pair%%|*}"; REMOTE="${pair##*|}"
        if wget -q -O "$IMG_DIR/$LOCAL" \
            "https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/$REMOTE" >> "$LOG" 2>&1; then
            ok "APRS sprite: $LOCAL"
        else
            warn "Failed to download $LOCAL — APRS icons will be missing (check internet connection)"
        fi
    done
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 8 — GENERATE config.php"
# ═══════════════════════════════════════════════════════════════════════

if [[ -f "$WEB_ROOT/config.php" ]]; then
    BACKUP="$WEB_ROOT/config.php.bak.$(date +%Y%m%d%H%M%S)"
    cp "$WEB_ROOT/config.php" "$BACKUP"
    ok "Existing config.php backed up to $(basename "$BACKUP")"
fi

cat > "$WEB_ROOT/config.php" << PHPEOF
<?php
/**
 * BPQ Dashboard Configuration
 * Generated by install.sh on $(date)
 *
 * Edit this file to change settings.
 * After editing, no restart is needed — PHP reads it on every request.
 */
return [

    // ── Station identity ─────────────────────────────────────────────
    'station' => [
        'callsign' => '${INPUT_CALL}',
        'node'     => '${INPUT_NODE}',
        'email'    => '${INPUT_EMAIL}',
        'lat'      => ${INPUT_LAT},
        'lon'      => ${INPUT_LON},
        'grid'     => '${INPUT_GRID}',
    ],

    // ── BPQ node connection ───────────────────────────────────────────
    // Must match bpq32.cfg USER= line credentials
    'bbs' => [
        'host'      => '127.0.0.1',
        'port'      => ${INPUT_BPQ_PORT},    // LinBPQ telnet port (TCPPORT in bpq32.cfg)
        'http_port' => ${INPUT_BPQ_HTTP},    // BPQ HTTP management port (8008)
        'user'      => '${INPUT_BPQ_USER}',
        'pass'      => '${INPUT_BPQ_PASS}',
        'alias'     => 'bbs',
        'timeout'   => 30,
    ],

    // ── APRS ─────────────────────────────────────────────────────────
    'aprs' => [
        'call'   => '${INPUT_APRS_CALL}',
        'pass'   => '${INPUT_APRS_PASS}',   // passcode from apps.magicbug.co.uk/passcode/
        'host'   => 'rotate.aprs2.net',
        'port'   => 14580,
        'filter' => 'r/${INPUT_LAT}/${INPUT_LON}/300',
    ],

    // ── File paths ────────────────────────────────────────────────────
    'paths' => [
        'linbpq'  => '${LINBPQ_DIR}',         // LinBPQ directory (bpq32.cfg location)
        'logs'    => '${LINBPQ_DIR}',          // BPQ log files directory
        'scripts' => '${SCRIPTS_DIR}',
        'web_root'=> '${WEB_ROOT}',
        'datalog' => '${WN_LOGS_DIR}',         // WaveNode DataLog files (RF Power Monitor)
    ],

    // ── Security ──────────────────────────────────────────────────────
    // 'local'  = full features, for home LAN use
    // 'public' = read-only, rate limited, for internet-facing installs
    'security_mode' => 'local',

    // ── Feature flags ─────────────────────────────────────────────────
    'features' => [
        'bbs_read'  => true,
        'bbs_write' => true,
        'nws_post'  => true,
        'aprs'      => $([[ $HAS_APRS == Y ]] && echo 'true' || echo 'false'),
        'vara_hf'   => $([[ $HAS_VARA == Y ]] && echo 'true' || echo 'false'),
    ],

    // ── NWS Weather Alerts ────────────────────────────────────────────
    'nws' => [
        'default_regions'  => ['SR'],   // SR=South, ER=East, CR=Central, WR=West
        'default_types'    => ['tornado', 'severe', 'winter'],
        'auto_refresh'     => true,
        'refresh_interval' => 60000,    // milliseconds
        'post_destination' => 'WX@ALLUS',
    ],

    // ── Rate limiting ─────────────────────────────────────────────────
    'rate_limit' => ['enabled' => false],

    // ── CORS ──────────────────────────────────────────────────────────
    'cors' => ['allow_all' => true],

    // ── Logging ───────────────────────────────────────────────────────
    'logging' => ['enabled' => false, 'file' => '${WEB_ROOT}/logs/bpq-dashboard.log'],

];
PHPEOF

chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/config.php"
chmod 640 "$WEB_ROOT/config.php"
ok "config.php generated with all settings"

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 9 — PATCH DAEMON SCRIPTS WITH YOUR SETTINGS"
# ═══════════════════════════════════════════════════════════════════════

patch_file() {
    local file="$1" old="$2" new="$3" label="$4"
    if grep -qF "$old" "$file" 2>/dev/null; then
        sed -i "s|${old}|${new}|g" "$file"
        ok "Patched $label in $(basename "$file")"
    fi
}

# bpq-chat-daemon.py
if [[ -f "$SCRIPTS_DIR/bpq-chat-daemon.py" ]]; then
    patch_file "$SCRIPTS_DIR/bpq-chat-daemon.py" "YOURCALL" "$INPUT_BPQ_USER" "callsign"
    patch_file "$SCRIPTS_DIR/bpq-chat-daemon.py" "YOURPASSWORD" "$INPUT_BPQ_PASS" "password"
    patch_file "$SCRIPTS_DIR/bpq-chat-daemon.py" "'8010'" "'${INPUT_BPQ_PORT}'" "port"
fi

# bpq-aprs-daemon.py
if [[ -f "$SCRIPTS_DIR/bpq-aprs-daemon.py" ]]; then
    patch_file "$SCRIPTS_DIR/bpq-aprs-daemon.py" "YOURCALL-1" "$INPUT_APRS_CALL" "APRS call"
    patch_file "$SCRIPTS_DIR/bpq-aprs-daemon.py" "APRS_PASS.*=.*'0'" "APRS_PASS    = '${INPUT_APRS_PASS}'" "APRS pass"
    python3 -c "
import re
f = open('$SCRIPTS_DIR/bpq-aprs-daemon.py','r'); c = f.read(); f.close()
c = re.sub(r'r/0\.0+/0\.0+/300', 'r/${INPUT_LAT}/${INPUT_LON}/300', c)
f = open('$SCRIPTS_DIR/bpq-aprs-daemon.py','w'); f.write(c); f.close()
" && ok "APRS filter patched: r/${INPUT_LAT}/${INPUT_LON}/300"
fi

# bpq-vara-daemon.py
if [[ -f "$SCRIPTS_DIR/bpq-vara-daemon.py" ]]; then
    patch_file "$SCRIPTS_DIR/bpq-vara-daemon.py" "BPQ_USER    = 'YOURCALL'" "BPQ_USER    = '${INPUT_BPQ_USER}'" "callsign"
    patch_file "$SCRIPTS_DIR/bpq-vara-daemon.py" "BPQ_PASS    = 'YOURPASSWORD'" "BPQ_PASS    = '${INPUT_BPQ_PASS}'" "password"
    patch_file "$SCRIPTS_DIR/bpq-vara-daemon.py" "BPQ_VARA_PORT = 3" "BPQ_VARA_PORT = ${INPUT_BPQ_VARA_PORT}" "VARA port"
    patch_file "$SCRIPTS_DIR/bpq-vara-daemon.py" "'8010'" "'${INPUT_BPQ_PORT}'" "telnet port"
fi

# vara-api.php
if [[ -f "$WEB_ROOT/vara-api.php" ]]; then
    patch_file "$WEB_ROOT/vara-api.php" "YOURPASSWORD" "$INPUT_BPQ_PASS" "BPQ password"
    patch_file "$WEB_ROOT/vara-api.php" "sysop@example.com" "$INPUT_EMAIL" "email"
    patch_file "$WEB_ROOT/vara-api.php" "127.0.0.1';          // flrig host" "${INPUT_FLRIG_HOST}';          // flrig host" "flrig host"
    sed -i "s|\$FLRIG_PORT  = 12345;|\$FLRIG_PORT  = ${INPUT_FLRIG_PORT};|" "$WEB_ROOT/vara-api.php"
    sed -i "s|'YOURCALL'|'${INPUT_BPQ_USER}'|g" "$WEB_ROOT/vara-api.php"
    if [[ "$HAS_FLRIG" == "Y" ]]; then
        sed -i "s|/home/linbpq/bpq32.cfg|${INPUT_BPQ32_CFG}|g" "$WEB_ROOT/vara-api.php" || true
    fi
    ok "vara-api.php patched"
fi

# bpq-nodes-api.php
if [[ -f "$WEB_ROOT/bpq-nodes-api.php" ]]; then
    patch_file "$WEB_ROOT/bpq-nodes-api.php" "'YOURCALL'" "'${INPUT_BPQ_USER}'" "callsign"
    patch_file "$WEB_ROOT/bpq-nodes-api.php" "'YOURPASSWORD'" "'${INPUT_BPQ_PASS}'" "password"
fi

# bpq-aprs.html — centre map on station
if [[ -f "$WEB_ROOT/bpq-aprs.html" ]]; then
    sed -i "s|const HOME_LAT = 0\.0000;|const HOME_LAT = ${INPUT_LAT};|" "$WEB_ROOT/bpq-aprs.html"
    sed -i "s|const HOME_LON = 0\.0000;|const HOME_LON = ${INPUT_LON};|" "$WEB_ROOT/bpq-aprs.html"
    ok "APRS map centred on ${INPUT_LAT}, ${INPUT_LON}"
fi

# restore-radio2.sh
if [[ -f /usr/local/bin/restore-radio2.sh ]]; then
    sed -i "s|/home/linbpq/bpq32.cfg|${INPUT_BPQ32_CFG}|g" /usr/local/bin/restore-radio2.sh
    sed -i "s|BPQ_USER=\"YOURCALL\"|BPQ_USER=\"${INPUT_BPQ_USER}\"|" /usr/local/bin/restore-radio2.sh
    sed -i "s|BPQ_PASS=\"YOURPASSWORD\"|BPQ_PASS=\"${INPUT_BPQ_PASS}\"|" /usr/local/bin/restore-radio2.sh
    ok "restore-radio2.sh patched"
fi

# wavenode-sync.sh
if [[ "$HAS_WAVENODE" == "Y" && -f /usr/local/bin/wavenode-sync.sh ]]; then
    sed -i "s|WINDOWS_HOST=\"10\.0\.0\.213\"|WINDOWS_HOST=\"${INPUT_WN_HOST}\"|" /usr/local/bin/wavenode-sync.sh
    sed -i "s|WINDOWS_USER=\"tony\"|WINDOWS_USER=\"${INPUT_WN_USER}\"|" /usr/local/bin/wavenode-sync.sh
    sed -i "s|LOCAL_DIR=.*wavenode-logs.*|LOCAL_DIR=\"${WN_LOGS_DIR}\"|" /usr/local/bin/wavenode-sync.sh
    ok "wavenode-sync.sh patched for ${INPUT_WN_USER}@${INPUT_WN_HOST}"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 10 — CONFIGURE NGINX"
# ═══════════════════════════════════════════════════════════════════════
# Done AFTER PHP-FPM is confirmed running (socket must exist for nginx test)

NGINX_CONF="/etc/nginx/sites-available/bpq-dashboard.conf"
NGINX_LINK="/etc/nginx/sites-enabled/bpq-dashboard.conf"

say "Writing nginx configuration..."
cat > "$NGINX_CONF" << NGINXEOF
# BPQ Dashboard — nginx virtual host
# Generated by install.sh on $(date)

# API rate limiting zone
limit_req_zone \$binary_remote_addr zone=bpq_api:10m rate=10r/s;

server {
    listen 80;
    server_name ${INPUT_HOST};
    root ${WEB_ROOT};
    index index.html index.php;

    charset utf-8;
    client_max_body_size 16M;

    access_log /var/log/nginx/bpq-dashboard-access.log;
    error_log  /var/log/nginx/bpq-dashboard-error.log;

    # ── BPQ Chat daemon — longer timeout for long-poll ────────────────
    location = /bpq-chat.php {
        limit_req zone=bpq_api burst=20 nodelay;
        fastcgi_read_timeout 90s;
        fastcgi_send_timeout 90s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── APRS daemon ───────────────────────────────────────────────────
    location = /bpq-aprs.php {
        limit_req zone=bpq_api burst=10 nodelay;
        fastcgi_read_timeout 60s;
        fastcgi_send_timeout 60s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── VARA HF API — extended timeout for RIGRECONFIG ────────────────
    location = /vara-api.php {
        limit_req zone=bpq_api burst=10 nodelay;
        fastcgi_read_timeout 60s;
        fastcgi_send_timeout 60s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── NetROM nodes API ──────────────────────────────────────────────
    location = /bpq-nodes-api.php {
        limit_req zone=bpq_api burst=5 nodelay;
        fastcgi_read_timeout 30s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── BPQ Chat WebSocket proxy ──────────────────────────────────────
    location ^~ /ws/chat {
        proxy_pass         http://127.0.0.1:8766;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade \$http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # ── BPQ Telnet WebSocket proxy ────────────────────────────────────
    location ^~ /ws/telnet {
        proxy_pass         http://127.0.0.1:8765;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade \$http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # ── VARA HF Terminal WebSocket proxy ──────────────────────────────
    location ^~ /ws/vara {
        proxy_pass         http://127.0.0.1:8767;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade \$http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_read_timeout 7200s;
        proxy_send_timeout 7200s;
    }

    # ── Admin pages — LAN access only ────────────────────────────────
    location ~* ^/(bpq-maintenance|system-audit|firewall-status|log-viewer|install-check)\.html?$ {
        allow 10.0.0.0/8;
        allow 192.168.0.0/16;
        allow 172.16.0.0/12;
        allow 127.0.0.1;
        deny all;
        try_files \$uri =404;
    }

    # ── Admin APIs — LAN access only ──────────────────────────────────
    location ~* ^/(partners-api|firewall-api|system-audit-api|log-viewer-api|install-check)\.php$ {
        allow 10.0.0.0/8;
        allow 192.168.0.0/16;
        allow 172.16.0.0/12;
        allow 127.0.0.1;
        deny all;
        limit_req zone=bpq_api burst=5 nodelay;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── General PHP ───────────────────────────────────────────────────
    location ~ \.php$ {
        limit_req zone=bpq_api burst=20 nodelay;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── Static files — cache in browser ──────────────────────────────
    location ~* \.(jpg|jpeg|png|gif|ico|svg|css|js|woff2?|ttf)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    # ── Default — serve file or 404 ───────────────────────────────────
    location / {
        try_files \$uri \$uri/ =404;
    }

    # ── Security — block common probes ────────────────────────────────
    location ~* \.(env|git|htaccess|htpasswd)$ { deny all; }
    location ~* /\. { deny all; }
}
NGINXEOF

# Enable site and test
ln -sf "$NGINX_CONF" "$NGINX_LINK"

if nginx -t >> "$LOG" 2>&1; then
    systemctl reload nginx >> "$LOG" 2>&1
    ok "nginx configured, tested and reloaded"
    ok "Dashboard available at: http://$INPUT_HOST/"
else
    err "nginx config test FAILED — check: sudo nginx -t"
    err "You may need to manually edit $NGINX_CONF"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 11 — CONNECT DASHBOARD TO LINBPQ LOGS"
# ═══════════════════════════════════════════════════════════════════════

say "Setting up log file access..."

if [[ -d "$LINBPQ_DIR" ]]; then
    # Create symlink from web root logs → LinBPQ directory
    # Remove existing logs dir and replace with symlink
    if [[ -d "$WEB_ROOT/logs" && ! -L "$WEB_ROOT/logs" ]]; then
        rmdir "$WEB_ROOT/logs" 2>/dev/null || rm -rf "$WEB_ROOT/logs"
    fi
    ln -sf "$LINBPQ_DIR" "$WEB_ROOT/logs"
    chown -h "$WEB_USER:$WEB_USER" "$WEB_ROOT/logs"

    # Test that www-data can read it
    if sudo -u www-data ls "$LINBPQ_DIR" >> "$LOG" 2>&1; then
        ok "Log symlink: $WEB_ROOT/logs → $LINBPQ_DIR"
    else
        warn "www-data cannot read $LINBPQ_DIR — adding www-data to LinBPQ group"
        LINBPQ_OWNER=$(stat -c '%U' "$LINBPQ_DIR" 2>/dev/null)
        if [[ -n "$LINBPQ_OWNER" && "$LINBPQ_OWNER" != "root" ]]; then
            usermod -aG "$LINBPQ_OWNER" "$WEB_USER" && \
                ok "Added $WEB_USER to group $LINBPQ_OWNER — restart services to take effect" || \
                warn "Could not add $WEB_USER to group $LINBPQ_OWNER — edit permissions manually"
        else
            warn "Could not determine LinBPQ owner — run: sudo chmod o+rx $LINBPQ_DIR"
        fi
    fi
else
    warn "LinBPQ directory $LINBPQ_DIR not found"
    warn "Create it or update the 'logs' path in $WEB_ROOT/config.php"
    # Create an empty logs dir as fallback
    mkdir -p "$WEB_ROOT/logs"
    chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/logs"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 12 — CHECK LINBPQ CONNECTIVITY"
# ═══════════════════════════════════════════════════════════════════════

say "Testing LinBPQ telnet on localhost:${INPUT_BPQ_PORT}..."
if nc -z 127.0.0.1 "$INPUT_BPQ_PORT" 2>/dev/null; then
    ok "LinBPQ telnet port ${INPUT_BPQ_PORT} is reachable — BPQ is running"
else
    warn "LinBPQ telnet port ${INPUT_BPQ_PORT} not responding"
    warn "LinBPQ must be running for Chat, VARA terminal and BBS messages to work"
    warn "Start LinBPQ and verify the telnet port is ${INPUT_BPQ_PORT}"
fi

say "Testing LinBPQ HTTP management on localhost:${INPUT_BPQ_HTTP}..."
if nc -z 127.0.0.1 "$INPUT_BPQ_HTTP" 2>/dev/null; then
    ok "LinBPQ HTTP management port ${INPUT_BPQ_HTTP} is reachable"
else
    warn "LinBPQ HTTP port ${INPUT_BPQ_HTTP} not responding — RIGRECONFIG will not work until BPQ is running"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 13 — INSTALL SYSTEMD DAEMON SERVICES"
# ═══════════════════════════════════════════════════════════════════════
# Install daemons AFTER scripts are patched (Step 9) so they start with correct settings

install_daemon() {
    local name="$1" script="$2" run_user="${3:-$WEB_USER}"
    local svc_file="/etc/systemd/system/${name}.service"

    [[ ! -f "$script" ]] && { warn "$name: script not found at $script"; return 1; }

    say "Installing $name daemon..."

    # Use service file from archive if present, otherwise generate
    local src_svc="$SCRIPT_DIR/${name}.service"
    if [[ -f "$src_svc" ]]; then
        cp "$src_svc" "$svc_file"
        # Patch exec path
        sed -i "s|ExecStart=.*|ExecStart=/usr/bin/python3 ${script}|" "$svc_file"
    else
        cat > "$svc_file" << SVCEOF
[Unit]
Description=BPQ Dashboard - ${name} daemon
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${run_user}
Group=${run_user}
ExecStart=/usr/bin/python3 ${script}
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=${name}

[Install]
WantedBy=multi-user.target
SVCEOF
    fi

    systemctl daemon-reload >> "$LOG" 2>&1
    systemctl enable "$name" >> "$LOG" 2>&1
    if systemctl restart "$name" >> "$LOG" 2>&1; then
        sleep 2
        if systemctl is-active --quiet "$name"; then
            ok "$name daemon running and enabled at boot"
        else
            err "$name started but immediately stopped — check: sudo journalctl -u $name -n 30"
        fi
    else
        err "$name failed to start — check: sudo journalctl -u $name -n 30"
    fi
}

# BPQ Chat — always installed
[[ -f "$SCRIPTS_DIR/bpq-chat-daemon.py" ]] \
    && install_daemon "bpq-chat" "$SCRIPTS_DIR/bpq-chat-daemon.py" "$WEB_USER" \
    || warn "bpq-chat-daemon.py not found — Chat page will not work"

# VARA HF Terminal
if [[ "$HAS_VARA" == "Y" ]]; then
    [[ -f "$SCRIPTS_DIR/bpq-vara-daemon.py" ]] \
        && install_daemon "bpq-vara" "$SCRIPTS_DIR/bpq-vara-daemon.py" "$WEB_USER" \
        || warn "bpq-vara-daemon.py not found — VARA HF Terminal will not work"
else
    # Install but don't start — user can enable later
    if [[ -f "$SCRIPTS_DIR/bpq-vara-daemon.py" ]]; then
        [[ -f "$SCRIPT_DIR/bpq-vara.service" ]] && \
            cp "$SCRIPT_DIR/bpq-vara.service" /etc/systemd/system/ || true
        systemctl daemon-reload >> "$LOG" 2>&1
        say "VARA HF daemon installed but not started (VARA HF not configured)"
        say "Enable later with: sudo systemctl enable --now bpq-vara"
    fi
fi

# APRS Map
if [[ "$HAS_APRS" == "Y" ]]; then
    [[ -f "$SCRIPTS_DIR/bpq-aprs-daemon.py" ]] \
        && install_daemon "bpq-aprs" "$SCRIPTS_DIR/bpq-aprs-daemon.py" "$WEB_USER" \
        || warn "bpq-aprs-daemon.py not found"
else
    say "APRS daemon not started (APRS not configured)"
fi

# VARA callsign validator (optional proxy)
if [[ -f "$SCRIPTS_DIR/vara-callsign-validator.py" ]]; then
    cat > /etc/systemd/system/vara-validator.service << VSVC
[Unit]
Description=BPQ Dashboard - VARA Callsign Validator
After=network.target

[Service]
Type=simple
User=root
ExecStart=/usr/bin/python3 ${SCRIPTS_DIR}/vara-callsign-validator.py
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=vara-validator

[Install]
WantedBy=multi-user.target
VSVC
    systemctl daemon-reload >> "$LOG" 2>&1
    systemctl enable vara-validator >> "$LOG" 2>&1
    ok "vara-validator service installed (starts automatically)"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 14 — CONFIGURE CRON JOBS"
# ═══════════════════════════════════════════════════════════════════════

CURRENT_CRON=$(crontab -l 2>/dev/null || echo "")

add_cron() {
    local pattern="$1" entry="$2" label="$3"
    if echo "$CURRENT_CRON" | grep -qF "$pattern"; then
        ok "Cron already exists: $label"
    else
        CURRENT_CRON="${CURRENT_CRON}"$'\n'"${entry}"
        echo "$CURRENT_CRON" | crontab -
        ok "Cron added: $label"
    fi
}

# Connect watchdog — every 5 minutes, root required
add_cron "connect-watchdog" \
    "*/5 * * * * /usr/bin/python3 $SCRIPTS_DIR/connect-watchdog.py >> /var/log/connect-watchdog.log 2>&1" \
    "connect-watchdog (every 5 min)"

# WP manager auto-clean — 3am daily
add_cron "wp_manager" \
    "0 3 * * * cd $LINBPQ_DIR && /usr/bin/python3 $SCRIPTS_DIR/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1" \
    "wp_manager auto-clean (3am daily)"

# WaveNode sync — every 5 minutes (only if configured)
if [[ "$HAS_WAVENODE" == "Y" ]]; then
    # Use /etc/cron.d for system-level cron
    echo "*/5 * * * * root /usr/local/bin/wavenode-sync.sh >> /var/log/wavenode-sync.log 2>&1" \
        > /etc/cron.d/wavenode-sync
    chmod 644 /etc/cron.d/wavenode-sync
    ok "WaveNode sync cron: /etc/cron.d/wavenode-sync (every 5 min)"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 15 — CREATE LOG FILES"
# ═══════════════════════════════════════════════════════════════════════

for lf in \
    /var/log/connect-watchdog.log \
    /var/log/vara-validator.log \
    /var/log/wp-auto-clean.log \
    /var/log/bpq-dashboard-install.log \
    /var/log/wavenode-sync.log; do
    touch "$lf" && chmod 644 "$lf" && ok "Log: $lf"
done

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 16 — CONFIGURE FIREWALL"
# ═══════════════════════════════════════════════════════════════════════

say "Configuring iptables rules..."

# Loopback
if ! iptables -C INPUT -i lo -j ACCEPT 2>/dev/null; then
    iptables -I INPUT 1 -i lo -j ACCEPT && ok "iptables: loopback ACCEPT" || warn "iptables: loopback rule failed"
else
    ok "iptables: loopback rule already present"
fi

# HTTP and HTTPS
for port in 80 443; do
    if ! iptables -C INPUT -p tcp --dport $port -j ACCEPT 2>/dev/null; then
        iptables -A INPUT -p tcp --dport "$port" -j ACCEPT && ok "iptables: port $port opened" || warn "iptables: port $port failed"
    else
        ok "iptables: port $port already open"
    fi
done

# Save rules
if command -v netfilter-persistent &>/dev/null; then
    netfilter-persistent save >> "$LOG" 2>&1 && ok "iptables rules saved (persistent)" || warn "iptables rules not saved"
else
    warn "netfilter-persistent not found — iptables rules will reset on reboot"
    warn "Install with: sudo apt install iptables-persistent"
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 17 — FINAL PERMISSIONS SWEEP"
# ═══════════════════════════════════════════════════════════════════════

say "Applying final ownership and permissions..."

# All web files owned by www-data
chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT"

# HTML, PHP, static — readable by web server
find "$WEB_ROOT" -maxdepth 1 -type f \( -name "*.html" -o -name "*.php" -o -name "*.svg" -o -name "*.ico" -o -name "*.pdf" \) \
    -exec chmod 644 {} \;

# Scripts — executable
find "$SCRIPTS_DIR" -name "*.py" -exec chmod 755 {} \;
find "$WEB_ROOT" -maxdepth 1 -name "*.sh" -exec chmod 755 {} \;

# Cache and data — writable by www-data
chmod -R 775 "$CACHE_DIR" "$DATA_DIR" "$WN_LOGS_DIR"

# config.php — readable by www-data, not world-readable
chmod 640 "$WEB_ROOT/config.php"

# Service scripts in /usr/local/bin
for f in restore-radio2.sh wavenode-sync.sh sync-bpq-logs.sh; do
    [[ -f "/usr/local/bin/$f" ]] && chmod 755 "/usr/local/bin/$f"
done

ok "All permissions applied"

# ═══════════════════════════════════════════════════════════════════════
hdr "STEP 18 — WAVENODE SSH KEY SETUP"
# ═══════════════════════════════════════════════════════════════════════

if [[ "$HAS_WAVENODE" == "Y" ]]; then
    say "Setting up SSH key for WaveNode sync..."
    if [[ ! -f /root/.ssh/wavenode_key ]]; then
        ssh-keygen -t ed25519 -f /root/.ssh/wavenode_key -N "" \
            -C "bpqdash-wavenode-sync" >> "$LOG" 2>&1
        ok "SSH key generated: /root/.ssh/wavenode_key"
    else
        ok "SSH key already exists: /root/.ssh/wavenode_key"
    fi
    echo ""
    echo -e "${BOLD}${YLW}WaveNode SSH key setup — manual step required:${NC}"
    echo -e "  1. On the Windows PC (${INPUT_WN_HOST}), open PowerShell as Administrator"
    echo -e "  2. Run these commands (one at a time):"
    echo -e "     ${CYN}New-Item -ItemType Directory -Force -Path \"\$env:USERPROFILE\.ssh\"${NC}"
    PUB_KEY=$(cat /root/.ssh/wavenode_key.pub 2>/dev/null)
    echo -e "     ${CYN}Add-Content -Force -Path \"C:\\ProgramData\\ssh\\administrators_authorized_keys\" -Value \"${PUB_KEY}\"${NC}"
    echo -e "     ${CYN}icacls \"C:\\ProgramData\\ssh\\administrators_authorized_keys\" /inheritance:r${NC}"
    echo -e "     ${CYN}icacls \"C:\\ProgramData\\ssh\\administrators_authorized_keys\" /grant \"SYSTEM:(F)\"${NC}"
    echo -e "     ${CYN}icacls \"C:\\ProgramData\\ssh\\administrators_authorized_keys\" /grant \"Administrators:(F)\"${NC}"
    echo -e "  3. Then test from this server:"
    echo -e "     ${CYN}ssh -i /root/.ssh/wavenode_key ${INPUT_WN_USER}@${INPUT_WN_HOST} \"echo SSH_OK\"${NC}"
    echo -e "  4. Run a manual sync test:"
    echo -e "     ${CYN}sudo bash /usr/local/bin/wavenode-sync.sh${NC}"
    echo ""
    read -r -p "Press ENTER to continue..."
fi

# ═══════════════════════════════════════════════════════════════════════
hdr "INSTALLATION COMPLETE"
# ═══════════════════════════════════════════════════════════════════════

echo ""
echo -e "${BOLD}${CYN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYN}║                   INSTALLATION SUMMARY                      ║${NC}"
echo -e "${BOLD}${CYN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${GRN}✓ Passed : $PASS${NC}"
echo -e "  ${RED}✗ Failed : $FAIL${NC}"
echo -e "  ${YLW}⚠ Warned : $WARN${NC}"
echo -e "  Log saved: $LOG"
echo ""

if [[ ${#ERRORS[@]} -gt 0 ]]; then
    echo -e "${RED}${BOLD}Issues that need attention:${NC}"
    for e in "${ERRORS[@]}"; do
        echo -e "  ${RED}→ $e${NC}"
    done
    echo ""
fi

echo -e "${BOLD}${GRN}BPQ DASHBOARD IS INSTALLED${NC}\n"
echo -e "  Dashboard URL  : ${BLU}http://${INPUT_HOST}/${NC}"
echo -e "  Health Check   : ${BLU}http://${INPUT_HOST}/install-check.php${NC}  (password: bpqcheck)"
echo -e "  Configuration  : ${BLU}sudo nano ${WEB_ROOT}/config.php${NC}"
echo -e "  Install log    : ${BLU}${LOG}${NC}"
echo ""
echo -e "${BOLD}NEXT STEPS:${NC}"
echo -e "  ${YLW} 1.${NC}  Open the dashboard: ${BLU}http://${INPUT_HOST}/${NC}"
echo -e "  ${YLW} 2.${NC}  Run health check:   ${BLU}http://${INPUT_HOST}/install-check.php${NC}"
echo -e "  ${YLW} 3.${NC}  Check services:     ${BLU}sudo systemctl status bpq-chat bpq-aprs bpq-vara nginx${NC}"
echo -e "  ${YLW} 4.${NC}  View daemon logs:   ${BLU}sudo journalctl -u bpq-chat -u bpq-vara -n 30${NC}"
echo -e "  ${YLW} 5.${NC}  Open BPQ Chat:      ${BLU}http://${INPUT_HOST}/bpq-chat.html${NC}"
if [[ "$HAS_VARA" == "Y" ]]; then
    echo -e "  ${YLW} 6.${NC}  VARA HF Terminal:   ${BLU}http://${INPUT_HOST}/bpq-vara.html${NC}"
fi
if [[ "$HAS_APRS" == "Y" ]]; then
    echo -e "  ${YLW} 7.${NC}  APRS Map:           ${BLU}http://${INPUT_HOST}/bpq-aprs.html${NC}"
fi
if [[ "$HAS_WAVENODE" == "Y" ]]; then
    echo -e "  ${YLW} 8.${NC}  Complete WaveNode SSH key setup (instructions shown above)"
fi
echo -e "  ${YLW} 9.${NC}  Add SSL/HTTPS:      ${BLU}sudo certbot --nginx -d ${INPUT_HOST}${NC}"
echo -e "  ${YLW}10.${NC}  Remove health check after testing: ${BLU}sudo rm ${WEB_ROOT}/install-check.php${NC}"
echo ""
echo -e "  Full manual: ${BLU}${WEB_ROOT}/BPQ-Dashboard-v1.5.6-Manual.pdf${NC}"
echo ""
