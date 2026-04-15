#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
#  BPQ Dashboard v1.5.6 — Guided Installation Script
#  For Debian / Ubuntu / Raspberry Pi OS
#
#  HOW TO RUN:
#    1. Copy the BPQ-Dashboard-v1.5.6.zip to your Linux machine
#    2. Unzip it:   unzip BPQ-Dashboard-v1.5.6.zip
#    3. Enter dir:  cd BPQ-Dashboard-v1.5.2
#    4. Run:        sudo bash install.sh
# ═══════════════════════════════════════════════════════════════════

# ── Colour codes ───────────────────────────────────────────────────
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[0;33m'
BLU='\033[0;34m'; CYN='\033[0;36m'; WHT='\033[1;37m'
BOLD='\033[1m';   NC='\033[0m'

# ── Counters & log ─────────────────────────────────────────────────
PASS=0; FAIL=0; WARN=0
LOG="/tmp/bpq-dashboard-install-$(date +%Y%m%d-%H%M%S).log"
ERRORS=()
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Logging helpers ────────────────────────────────────────────────
stamp() { date '+%H:%M:%S'; }
tee_log() { tee -a "$LOG"; }

say()  { echo -e "$(stamp) ${WHT}[....] $*${NC}" | tee_log; }
ok()   { echo -e "$(stamp) ${GRN}[ OK ] $*${NC}" | tee_log; ((PASS++)); }
warn() { echo -e "$(stamp) ${YLW}[WARN] $*${NC}" | tee_log; ((WARN++)); }
err()  { echo -e "$(stamp) ${RED}[FAIL] $*${NC}" | tee_log; ((FAIL++)); ERRORS+=("$*"); }
hdr()  { echo -e "\n$(stamp) ${BOLD}${CYN}╔═══ $* ═══╗${NC}" | tee_log; }
ask()  { echo -e "\n${BOLD}${YLW}[INPUT] $*${NC}"; }
pause(){ echo -e "${BLU}Press ENTER to continue...${NC}"; read -r; }
die()  { echo -e "\n${RED}${BOLD}FATAL: $*${NC}\nInstall log: $LOG"; exit 1; }

# ── Must be root ───────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && die "Please run with sudo:\n  sudo bash install.sh"

# ── Welcome banner ─────────────────────────────────────────────────
clear
echo -e "${BOLD}${CYN}"
cat << 'BANNER'
  ╔══════════════════════════════════════════════════════╗
  ║      BPQ Dashboard v1.5.6 — Guided Installer        ║
  ║         Amateur Radio Packet Network                 ║
  ╚══════════════════════════════════════════════════════╝
BANNER
echo -e "${NC}"
echo -e "This script will install BPQ Dashboard on your Linux server."
echo -e "It will install ${BOLD}Nginx${NC}, ${BOLD}PHP${NC}, ${BOLD}MariaDB${NC} and all required components."
echo -e "Log file: ${BLU}$LOG${NC}\n"
echo -e "${YLW}This will take approximately 5-10 minutes.${NC}"
echo -e "${YLW}You will be asked a few questions during installation.${NC}\n"
pause

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 1 — GATHER CONFIGURATION"
# ═══════════════════════════════════════════════════════════════════

echo -e "\n${BOLD}We need a few details to configure BPQ Dashboard.${NC}"
echo -e "Press ENTER to accept the default shown in [brackets].\n"

# Callsign
ask "Your station callsign (e.g. YOURCALL):"
read -r INPUT_CALL
INPUT_CALL="${INPUT_CALL:-YOURCALL}"
INPUT_CALL="${INPUT_CALL^^}"   # uppercase
ok "Callsign: $INPUT_CALL"

# Node callsign
ask "Your BPQ node callsign with SSID (e.g. YOURCALL-4) [${INPUT_CALL}-4]:"
read -r INPUT_NODE
INPUT_NODE="${INPUT_NODE:-${INPUT_CALL}-4}"
INPUT_NODE="${INPUT_NODE^^}"
ok "Node: $INPUT_NODE"

# BPQ telnet password
echo -e "  ${BLU}Hint: Open bpq32.cfg and find the USER= line.${NC}"
echo -e "  ${BLU}Example: USER=YOURCALL,YOURPASSWORD,YOURCALL → password is YOURPASSWORD${NC}"
ask "Your BPQ telnet password (from bpq32.cfg USER= line):"
read -r -s INPUT_BPQ_PASS
echo ""
INPUT_BPQ_PASS="${INPUT_BPQ_PASS:-changeme}"
ok "BPQ password: (set)"

# Domain / hostname
HOSTNAME_AUTO=$(hostname -f 2>/dev/null || hostname)
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
echo -e "  ${BLU}Your server IP address is: ${WHT}${SERVER_IP}${NC}"
echo -e "  ${BLU}If you don't have a domain name, just press ENTER to use the IP.${NC}"
ask "Your server hostname or domain name [$HOSTNAME_AUTO]:"
read -r INPUT_HOST
INPUT_HOST="${INPUT_HOST:-$HOSTNAME_AUTO}"
ok "Hostname: $INPUT_HOST"

# Web root — detect or ask
ask "Web root directory [/var/www/html/bpq]:"
read -r INPUT_WEBROOT
WEB_ROOT="${INPUT_WEBROOT:-/var/www/html/bpq}"
ok "Web root: $WEB_ROOT"

# MySQL root password
ask "Choose a password for the database (MariaDB root) — you'll need this later:"
read -r -s INPUT_DB_ROOT_PASS
echo ""
INPUT_DB_ROOT_PASS="${INPUT_DB_ROOT_PASS:-BpqDashboard1!}"
ok "Database root password: (set)"

# DB for dashboard
INPUT_DB_NAME="bpqdash"
INPUT_DB_USER="bpqdash_user"
ask "Choose a database password for BPQ Dashboard [auto-generated]:"
read -r -s INPUT_DB_PASS
echo ""
INPUT_DB_PASS="${INPUT_DB_PASS:-$(cat /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 16 2>/dev/null || echo 'BpqDb2025!')}"
ok "Dashboard DB password: (set)"

# LinBPQ directory
echo -e "  ${BLU}This is where bpq32.cfg lives. Common paths:${NC}"
echo -e "  ${BLU}  /home/linbpq    /home/pi/linbpq    /home/tony/linbpq${NC}"
echo -e "  ${BLU}  Not sure? Run: find /home -name bpq32.cfg 2>/dev/null${NC}"
ask "Path to your LinBPQ directory [/home/linbpq]:"
read -r INPUT_LINBPQ
LINBPQ_DIR="${INPUT_LINBPQ:-/home/linbpq}"
ok "LinBPQ dir: $LINBPQ_DIR"

# Station coordinates (for APRS map centering and filter)
echo -e "  ${BLU}Find your coordinates: right-click on maps.google.com${NC}"
ask "Your station latitude  (e.g. 33.4259) [0.0000]:"
read -r INPUT_LAT
INPUT_LAT="${INPUT_LAT:-0.0000}"
ok "Latitude: $INPUT_LAT"

ask "Your station longitude (e.g. -82.0099 — West is negative) [0.0000]:"
read -r INPUT_LON
INPUT_LON="${INPUT_LON:-0.0000}"
ok "Longitude: $INPUT_LON"

# APRS credentials
ask "Your APRS-IS callsign (usually YOURCALL-1) [${INPUT_CALL}-1]:"
read -r INPUT_APRS_CALL
INPUT_APRS_CALL="${INPUT_APRS_CALL:-${INPUT_CALL}-1}"
INPUT_APRS_CALL="${INPUT_APRS_CALL^^}"

echo -e "  ${BLU}Get your free passcode at: https://apps.magicbug.co.uk/passcode/${NC}"
echo -e "  ${BLU}Enter your callsign (no SSID) — it gives you a number e.g. 15769${NC}"
ask "Your APRS-IS passcode (number from magicbug.co.uk):"
read -r INPUT_APRS_PASS
INPUT_APRS_PASS="${INPUT_APRS_PASS:-0}"
ok "APRS: $INPUT_APRS_CALL / passcode set"

# Sysop email
ask "Sysop email address [${INPUT_CALL,,}@example.com]:"
read -r INPUT_EMAIL
INPUT_EMAIL="${INPUT_EMAIL:-${INPUT_CALL,,}@example.com}"
ok "Sysop email: $INPUT_EMAIL"

# VARA HF terminal
echo ""
echo -e "  ${BLU}The BPQ Telnet Client and VARA HF Terminal are included in this release.${NC}"
echo -e "  ${BLU}The Telnet Client works immediately with no extra software.${NC}"
echo -e "  ${BLU}The VARA HF Terminal requires VARA HF modem software already running.${NC}"
read -r -p "  Do you have VARA HF software running on this or a network machine? [y/N]: " HAS_VARA
HAS_VARA="${HAS_VARA:-N}"
if [[ "$HAS_VARA" =~ ^[Yy] ]]; then
    echo -e "  ${BLU}Is VARA HF running on THIS machine (localhost)?${NC}"
    read -r -p "  VARA HF host IP [127.0.0.1]: " INPUT_VARA_HOST
    INPUT_VARA_HOST="${INPUT_VARA_HOST:-127.0.0.1}"
    echo -e "  ${BLU}VARA HF command port — check VARA Settings → TCP Ports. Default is 8300,${NC}"
    echo -e "  ${BLU}but some installs use different ports (e.g. 9025).${NC}"
    read -r -p "  VARA HF command port [8300]: " INPUT_VARA_PORT
    INPUT_VARA_PORT="${INPUT_VARA_PORT:-8300}"
    # BPQ port number for VARA HF — ask which BPQ port drives VARA
    echo -e "  ${BLU}Which BPQ port number is your VARA HF port? (check bpq32.cfg PORTNUM= in the VARA section)${NC}"
    read -r -p "  BPQ VARA HF port number [3]: " INPUT_BPQ_VARA_PORT
    INPUT_BPQ_VARA_PORT="${INPUT_BPQ_VARA_PORT:-3}"
    # flrig for frequency control
    read -r -p "  Do you use flrig for rig control? [y/N]: " HAS_FLRIG
    HAS_FLRIG="${HAS_FLRIG:-N}"
    if [[ "$HAS_FLRIG" =~ ^[Yy] ]]; then
        read -r -p "  flrig host IP [127.0.0.1]: " INPUT_FLRIG_HOST
        INPUT_FLRIG_HOST="${INPUT_FLRIG_HOST:-127.0.0.1}"
        read -r -p "  flrig XML-RPC port [12345]: " INPUT_FLRIG_PORT
        INPUT_FLRIG_PORT="${INPUT_FLRIG_PORT:-12345}"
    else
        INPUT_FLRIG_HOST="127.0.0.1"
        INPUT_FLRIG_PORT="12345"
    fi
    ok "VARA HF: ${INPUT_VARA_HOST}:${INPUT_VARA_PORT} — BPQ port ${INPUT_BPQ_VARA_PORT}"
    ok "flrig: ${INPUT_FLRIG_HOST}:${INPUT_FLRIG_PORT}"
else
    INPUT_VARA_HOST="127.0.0.1"
    INPUT_VARA_PORT="8300"
    INPUT_BPQ_VARA_PORT="3"
    INPUT_FLRIG_HOST="127.0.0.1"
    INPUT_FLRIG_PORT="12345"
    say "VARA HF terminal will be installed but requires VARA HF software to function"
fi

echo -e "\n${GRN}${BOLD}Configuration collected. Starting installation...${NC}\n"
sleep 2

# Set derived paths
SCRIPTS_DIR="$WEB_ROOT/scripts"
CACHE_DIR="$WEB_ROOT/cache"
DATA_DIR="$WEB_ROOT/data"
IMG_DIR="$WEB_ROOT/img"
VARA_CACHE_DIR="$WEB_ROOT/cache/vara-sessions"
TELNET_CACHE_DIR="$WEB_ROOT/cache/telnet-sessions"
WEB_USER="www-data"

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 2 — SYSTEM UPDATE"
# ═══════════════════════════════════════════════════════════════════

say "Updating package lists..."
if apt-get update -qq >> "$LOG" 2>&1; then
    ok "Package lists updated"
else
    warn "Package update had warnings — continuing"
fi

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 3 — INSTALL REQUIRED PACKAGES"
# ═══════════════════════════════════════════════════════════════════

PACKAGES=(
    nginx
    php8.3-fpm
    php8.3-cli
    php8.3-mysql
    php8.3-curl
    php8.3-mbstring
    php8.3-xml
    php8.3-zip
    mariadb-server
    python3
    python3-pip
    curl
    wget
    unzip
    certbot
    python3-certbot-nginx
    iptables-persistent
    fail2ban
    logrotate
)

say "Installing required packages (this may take a few minutes)..."
for pkg in "${PACKAGES[@]}"; do
    if dpkg -l "$pkg" 2>/dev/null | grep -q "^ii"; then
        ok "Already installed: $pkg"
    else
        say "Installing: $pkg..."
        if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$pkg" >> "$LOG" 2>&1; then
            ok "Installed: $pkg"
        else
            # Try PHP version fallbacks 8.2 → 8.1 → 8.4
            if [[ "$pkg" == php8.3* ]]; then
                INSTALLED=0
                for PHPFB in 8.2 8.1 8.4; do
                    FALLBACK="${pkg/8.3/$PHPFB}"
                    say "Trying fallback: $FALLBACK..."
                    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$FALLBACK" >> "$LOG" 2>&1; then
                        ok "Installed fallback: $FALLBACK"
                        INSTALLED=1
                        break
                    fi
                done
                [[ $INSTALLED -eq 0 ]] && warn "Could not install $pkg or any fallback — continuing"
            else
                warn "Could not install $pkg — may cause issues"
            fi
        fi
    fi
done

# Detect active PHP-FPM
PHP_FPM=""
for v in 8.4 8.3 8.2 8.1; do
    if systemctl is-active --quiet "php$v-fpm" 2>/dev/null || \
       [[ -S "/run/php/php$v-fpm.sock" ]]; then
        PHP_FPM="php$v-fpm"
        PHP_VER="$v"
        break
    fi
done
[[ -z "$PHP_FPM" ]] && die "No PHP-FPM found. Install with: sudo apt install php8.3-fpm"
ok "PHP-FPM detected: $PHP_FPM (socket: /run/php/php$PHP_VER-fpm.sock)"

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 4 — START & ENABLE SERVICES"
# ═══════════════════════════════════════════════════════════════════

for svc in nginx "$PHP_FPM" mariadb fail2ban; do
    systemctl enable "$svc" >> "$LOG" 2>&1
    if systemctl restart "$svc" >> "$LOG" 2>&1; then
        ok "$svc started and enabled"
    else
        err "$svc failed to start — check: sudo journalctl -u $svc -n 20"
    fi
done

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 5 — CONFIGURE MARIADB"
# ═══════════════════════════════════════════════════════════════════

say "Securing MariaDB and creating dashboard database..."

# Use individual -e commands — avoids heredoc auth issues on Raspberry Pi / Debian
# sudo mysql uses unix socket auth and works without a root password
DB_OK=1
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('${INPUT_DB_ROOT_PASS}');" >> "$LOG" 2>&1 || true
sudo mysql -e "DELETE FROM mysql.user WHERE User='';" >> "$LOG" 2>&1 || true
sudo mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');" >> "$LOG" 2>&1 || true
sudo mysql -e "DROP DATABASE IF EXISTS test;" >> "$LOG" 2>&1 || true
sudo mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\_%%';" >> "$LOG" 2>&1 || true
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${INPUT_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >> "$LOG" 2>&1 || DB_OK=0
sudo mysql -e "CREATE USER IF NOT EXISTS '${INPUT_DB_USER}'@'localhost' IDENTIFIED BY '${INPUT_DB_PASS}';" >> "$LOG" 2>&1 || DB_OK=0
sudo mysql -e "GRANT ALL PRIVILEGES ON \`${INPUT_DB_NAME}\`.* TO '${INPUT_DB_USER}'@'localhost';" >> "$LOG" 2>&1 || DB_OK=0
sudo mysql -e "FLUSH PRIVILEGES;" >> "$LOG" 2>&1 || true

if [[ $DB_OK -eq 1 ]]; then
    ok "MariaDB secured and database '$INPUT_DB_NAME' created"
    ok "Database user '$INPUT_DB_USER' created"
else
    warn "MariaDB setup may have had issues — check $LOG"
    warn "If DB errors persist run: sudo mysql and enter commands manually"
fi

# Import schema if present
if [[ -f "$SCRIPT_DIR/data/schema.sql" ]]; then
    mysql -u root -p"${INPUT_DB_ROOT_PASS}" "$INPUT_DB_NAME" \
        < "$SCRIPT_DIR/data/schema.sql" >> "$LOG" 2>&1 && \
        ok "Database schema imported" || warn "Schema import had warnings"
fi

# Import prop-decisions schema
if [[ -f "$SCRIPT_DIR/data/prop-decisions-schema.sql" ]]; then
    mysql -u root -p"${INPUT_DB_ROOT_PASS}" "$INPUT_DB_NAME" \
        < "$SCRIPT_DIR/data/prop-decisions-schema.sql" >> "$LOG" 2>&1 && \
        ok "prop_decisions schema imported" || warn "prop_decisions schema had warnings"
fi

# Create vara_allowed_stations table for VARA HF terminal allowlist
say "Creating VARA HF allowlist table..."
sudo mysql "${INPUT_DB_NAME}" -e "
CREATE TABLE IF NOT EXISTS vara_allowed_stations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    callsign   VARCHAR(10) NOT NULL,
    name       VARCHAR(60) DEFAULT '',
    notes      VARCHAR(255) DEFAULT '',
    added_by   VARCHAR(10) DEFAULT '${INPUT_CALL}',
    added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    active     TINYINT(1) DEFAULT 1,
    UNIQUE KEY uq_callsign (callsign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" >> "$LOG" 2>&1 && ok "vara_allowed_stations table created" || warn "vara_allowed_stations table creation had warnings — run manually if needed"

# Grant bpqdash_user access to the vara table (needs explicit grant since GRANT ALL was on *.*)
sudo mysql -e "GRANT SELECT, INSERT, UPDATE, DELETE ON \`${INPUT_DB_NAME}\`.vara_allowed_stations TO '${INPUT_DB_USER}'@'localhost';" >> "$LOG" 2>&1 || true
sudo mysql -e "FLUSH PRIVILEGES;" >> "$LOG" 2>&1 || true
ok "VARA table permissions granted to ${INPUT_DB_USER}"

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 6 — CREATE WEB ROOT & DIRECTORIES"
# ═══════════════════════════════════════════════════════════════════

DIRS=(
    "$WEB_ROOT"
    "$SCRIPTS_DIR"
    "$CACHE_DIR"
    "$CACHE_DIR/aprs"
    "$CACHE_DIR/chat-sessions"
    "$CACHE_DIR/network"
    "$VARA_CACHE_DIR"
    "$TELNET_CACHE_DIR"
    "$DATA_DIR"
    "$DATA_DIR/backups"
    "$IMG_DIR"
    "$WEB_ROOT/logs"
    "$WEB_ROOT/css"
    "$WEB_ROOT/js"
)

for dir in "${DIRS[@]}"; do
    mkdir -p "$dir" && ok "Directory: $dir" || err "Failed to create: $dir"
done

# Set all ownership to www-data
chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"
# Cache and data dirs need to be writable by www-data
chmod -R 775 "$CACHE_DIR" "$DATA_DIR"
ok "Permissions set on all directories"

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 7 — INSTALL DASHBOARD FILES"
# ═══════════════════════════════════════════════════════════════════

# Copy all HTML files
say "Copying dashboard HTML pages..."
for f in "$SCRIPT_DIR"/*.html; do
    [[ -f "$f" ]] || continue
    cp "$f" "$WEB_ROOT/"
    chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/$(basename "$f")"
    chmod 644 "$WEB_ROOT/$(basename "$f")"
    ok "HTML: $(basename "$f")"
done

# Patch APRS map with user's coordinates
if [[ -f "$WEB_ROOT/bpq-aprs.html" ]]; then
    sed -i "s|const HOME_LAT = 0.0000;.*|const HOME_LAT = ${INPUT_LAT};|" "$WEB_ROOT/bpq-aprs.html"
    sed -i "s|const HOME_LON = 0.0000;.*|const HOME_LON = ${INPUT_LON};|" "$WEB_ROOT/bpq-aprs.html"
    ok "APRS map centred on ${INPUT_LAT}, ${INPUT_LON}"
fi

# Copy all PHP files (except config.php — handled separately)
say "Copying PHP API files..."
for f in "$SCRIPT_DIR"/*.php; do
    [[ -f "$f" ]] || continue
    [[ "$(basename "$f")" == "config.php" ]] && continue
    cp "$f" "$WEB_ROOT/"
    chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/$(basename "$f")"
    chmod 644 "$WEB_ROOT/$(basename "$f")"
    ok "PHP: $(basename "$f")"
done

# Copy Python scripts
say "Copying Python scripts..."
for f in "$SCRIPT_DIR/scripts"/*.py; do
    [[ -f "$f" ]] || continue
    cp "$f" "$SCRIPTS_DIR/"
    chown "$WEB_USER:$WEB_USER" "$SCRIPTS_DIR/$(basename "$f")"
    chmod 755 "$SCRIPTS_DIR/$(basename "$f")"
    ok "Script: $(basename "$f")"
done

# Copy data files
[[ -f "$SCRIPT_DIR/data/partners.json" ]] && \
    cp "$SCRIPT_DIR/data/partners.json" "$DATA_DIR/" || \
    echo '[]' > "$DATA_DIR/partners.json"
chown "$WEB_USER:$WEB_USER" "$DATA_DIR/partners.json"
ok "Data: partners.json"

# Copy favicon if present
[[ -f "$SCRIPT_DIR/favicon.svg" ]] && \
    cp "$SCRIPT_DIR/favicon.svg" "$WEB_ROOT/" && \
    chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/favicon.svg" && \
    ok "Favicon: favicon.svg"

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 8 — GENERATE CONFIG.PHP"
# ═══════════════════════════════════════════════════════════════════

if [[ -f "$WEB_ROOT/config.php" ]]; then
    say "config.php already exists — creating backup and regenerating..."
    cp "$WEB_ROOT/config.php" "$WEB_ROOT/config.php.bak.$(date +%Y%m%d%H%M%S)"
    ok "Backup created"
fi

cat > "$WEB_ROOT/config.php" << PHPCONF
<?php
// BPQ Dashboard Configuration
// Generated by install.sh on $(date)
// Edit this file to update your settings.

return [
    'station' => [
        'callsign'  => '${INPUT_CALL}',
        'node'      => '${INPUT_NODE}',
        'email'     => '${INPUT_EMAIL}',
        'lat'       => 0.0,
        'lon'       => 0.0,
        'locator'   => '',
    ],
    'bbs' => [
        'host'      => '127.0.0.1',
        'port'      => 8010,
        'fbb_port'  => 8011,
        'http_port' => 8008,
        'user'      => '${INPUT_CALL}',
        'pass'      => '${INPUT_BPQ_PASS}',
    ],
    'db' => [
        'host'      => 'localhost',
        'name'      => '${INPUT_DB_NAME}',
        'user'      => '${INPUT_DB_USER}',
        'pass'      => '${INPUT_DB_PASS}',
    ],
    'aprs' => [
        'call'      => '${INPUT_APRS_CALL}',
        'pass'      => '${INPUT_APRS_PASS}',
        'host'      => 'rotate.aprs2.net',
        'port'      => 14580,
        'filter'    => 'r/0.0000/-0.0000/300',
    ],
    'paths' => [
        'linbpq'    => '${LINBPQ_DIR}',
        'logs'      => '${LINBPQ_DIR}',
        'web_root'  => '${WEB_ROOT}',
        'scripts'   => '${SCRIPTS_DIR}',
    ],
];
PHPCONF

chown "$WEB_USER:$WEB_USER" "$WEB_ROOT/config.php"
chmod 640 "$WEB_ROOT/config.php"   # readable by www-data, not world
ok "config.php generated with your settings"

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 9 — DOWNLOAD APRS SYMBOL SPRITES"
# ═══════════════════════════════════════════════════════════════════

say "Downloading APRS symbol images from GitHub..."
for pair in \
    "aprs-symbols-pri.png|aprs-symbols-24-0.png" \
    "aprs-symbols-alt.png|aprs-symbols-24-1.png"; do
    LOCAL="${pair%%|*}"
    REMOTE="${pair##*|}"
    DEST="$IMG_DIR/$LOCAL"
    if [[ -f "$DEST" && -s "$DEST" ]]; then
        ok "APRS sprite already present: $LOCAL"
    else
        if wget -q -O "$DEST" \
            "https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/$REMOTE" \
            >> "$LOG" 2>&1; then
            chown "$WEB_USER:$WEB_USER" "$DEST"
            ok "Downloaded APRS sprite: $LOCAL ($(du -h "$DEST" | cut -f1))"
        else
            err "Failed to download $LOCAL — APRS map icons will be missing"
        fi
    fi
done

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 10 — CONFIGURE WEB SERVER"
# ═══════════════════════════════════════════════════════════════════

PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

# Detect which web server is running
WEB_SERVER=""
if systemctl is-active --quiet apache2 2>/dev/null; then
    WEB_SERVER="apache2"
    ok "Detected Apache2 web server"
elif systemctl is-active --quiet nginx 2>/dev/null; then
    WEB_SERVER="nginx"
    ok "Detected Nginx web server"
elif dpkg -l nginx 2>/dev/null | grep -q "^ii"; then
    WEB_SERVER="nginx"
    ok "Nginx installed — using nginx"
else
    WEB_SERVER="nginx"
    warn "No web server detected — defaulting to nginx"
fi

if [[ "$WEB_SERVER" == "apache2" ]]; then
    say "Configuring Apache2..."

    # Enable required modules
    a2enmod proxy_fcgi setenvif >> "$LOG" 2>&1 && ok "Apache2 modules enabled"
    a2enconf "php${PHP_VER}-fpm" >> "$LOG" 2>&1 && ok "PHP-FPM config enabled"

    # Write Apache2 vhost config
    APACHE_CONF="/etc/apache2/sites-available/bpq-dashboard.conf"
    cat > "$APACHE_CONF" << APACHECONF
<VirtualHost *:80>
    ServerName ${INPUT_HOST}
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        Options -Indexes -FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.html index.php
    </Directory>

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:${PHP_SOCK}|fcgi://localhost"
    </FilesMatch>

    # Block sensitive directories
    <DirectoryMatch "^${WEB_ROOT}/(cache|data|scripts|includes)">
        Require all denied
    </DirectoryMatch>

    # Allow logs directory
    <Directory ${WEB_ROOT}/logs>
        Options -Indexes
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/bpq-error.log
    CustomLog \${APACHE_LOG_DIR}/bpq-access.log combined
</VirtualHost>
APACHECONF

    # Disable default site, enable bpq-dashboard
    a2dissite 000-default >> "$LOG" 2>&1 || true
    a2ensite bpq-dashboard >> "$LOG" 2>&1

    # Test and reload
    if apache2ctl configtest >> "$LOG" 2>&1; then
        systemctl reload apache2 >> "$LOG" 2>&1
        ok "Apache2 configured and reloaded"
        ok "Site available at: http://$INPUT_HOST/"
    else
        err "Apache2 config test FAILED — check: sudo apache2ctl configtest"
    fi

else
    # ── NGINX (default) ────────────────────────────────────────────
    say "Configuring Nginx..."

    # Remove default nginx site if it exists
    [[ -f /etc/nginx/sites-enabled/default ]] && \
        rm /etc/nginx/sites-enabled/default && \
        ok "Removed nginx default site"

    # Write nginx config
    NGINX_CONF="/etc/nginx/sites-available/bpq-dashboard.conf"
    NGINX_ENABLED="/etc/nginx/sites-enabled/bpq-dashboard.conf"

    say "Writing nginx configuration..." 
cat > "$NGINX_CONF" << NGINXCONF
# BPQ Dashboard — nginx configuration
# Generated by install.sh on $(date)

# Rate limiting zone
limit_req_zone \$binary_remote_addr zone=bpq_api:10m rate=10r/s;

server {
    listen 80;
    server_name ${INPUT_HOST};
    root ${WEB_ROOT};
    index index.html index.php;

    charset utf-8;
    client_max_body_size 16M;

    # Logging
    access_log /var/log/nginx/bpq-dashboard-access.log;
    error_log  /var/log/nginx/bpq-dashboard-error.log;

    # ── Extended timeouts for daemon PHP files ─────────────────────
    location = /bpq-chat.php {
        limit_req zone=bpq_api burst=10 nodelay;
        fastcgi_read_timeout 90s;
        fastcgi_send_timeout 90s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    location = /bpq-aprs.php {
        limit_req zone=bpq_api burst=10 nodelay;
        fastcgi_read_timeout 60s;
        fastcgi_send_timeout 60s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── BPQ log files (for bpq-system-logs.html) ──────────────────
    location /logs/ {
        alias ${LINBPQ_DIR}/;
        allow all;
        location ~* \\.log\$ { allow all; }
    }

    # ── Admin pages — LAN only ─────────────────────────────────────
    location ~* ^/(bpq-maintenance|system-audit|firewall-status|log-viewer|admin|install-check)\\.html?\$ {
        allow 10.0.0.0/8;
        allow 192.168.0.0/16;
        allow 172.16.0.0/12;
        allow 127.0.0.1;
        deny all;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── API files — LAN only ───────────────────────────────────────
    location ~* ^/(partners-api|firewall-api|system-audit-api|log-viewer-api|install-check)\\.php\$ {
        allow 10.0.0.0/8;
        allow 192.168.0.0/16;
        allow 172.16.0.0/12;
        allow 127.0.0.1;
        deny all;
        limit_req zone=bpq_api burst=5 nodelay;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── VARA HF terminal API ───────────────────────────────────────
    location = /vara-api.php {
        limit_req zone=bpq_api burst=10 nodelay;
        fastcgi_read_timeout 15s;
        fastcgi_send_timeout 15s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── NetROM nodes API ───────────────────────────────────────────
    location = /bpq-nodes-api.php {
        limit_req zone=bpq_api burst=5 nodelay;
        fastcgi_read_timeout 30s;
        fastcgi_send_timeout 30s;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── VARA HF terminal page ──────────────────────────────────────
    location = /bpq-vara.html {
        try_files \$uri =404;
    }

    # ── BPQ Telnet WebSocket proxy ─────────────────────────────────
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

    # ── VARA HF Terminal WebSocket proxy ───────────────────────────
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

    # ── General PHP ────────────────────────────────────────────────
    location ~ \\.php\$ {
        limit_req zone=bpq_api burst=20 nodelay;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
    }

    # ── Static files ───────────────────────────────────────────────
    location ~* \\.(jpg|jpeg|png|gif|ico|svg|css|js|woff2?|ttf)\$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files \$uri \$uri/ =404;
    }

    # ── Block common attack probes ─────────────────────────────────
    location ~* \\.(env|git|htaccess|htpasswd)\$ { deny all; }
    location ~* /\\. { deny all; }
}
NGINXCONF

    # Enable site
    ln -sf "$NGINX_CONF" "$NGINX_ENABLED"

    # Test nginx config
    if nginx -t >> "$LOG" 2>&1; then
        systemctl reload nginx
        ok "Nginx configured and reloaded"
        ok "Site available at: http://$INPUT_HOST/"
    else
        err "Nginx config test FAILED — check: sudo nginx -t"
    fi

fi # end web server configuration

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 11 — INSTALL SYSTEMD DAEMON SERVICES"
# ═══════════════════════════════════════════════════════════════════

install_daemon() {
    local name="$1"
    local script="$2"
    local svc_file="/etc/systemd/system/${name}.service"
    local run_user="${3:-$WEB_USER}"

    say "Installing $name daemon..."

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

    systemctl daemon-reload >> "$LOG" 2>&1
    systemctl enable "$name" >> "$LOG" 2>&1

    if systemctl restart "$name" >> "$LOG" 2>&1; then
        sleep 2
        if systemctl is-active --quiet "$name"; then
            ok "$name daemon running and enabled at boot"
            return 0
        fi
    fi
    err "$name daemon failed to start — check: sudo journalctl -u $name -n 30"
    return 1
}

# Patch daemon scripts with user's settings
say "Patching daemon scripts with your settings..."
if [[ -f "$SCRIPTS_DIR/bpq-chat-daemon.py" ]]; then
    sed -i "s|BPQ_USER   = 'YOURCALL'.*|BPQ_USER   = '${INPUT_CALL}'|" "$SCRIPTS_DIR/bpq-chat-daemon.py"
    sed -i "s|BPQ_PASS   = 'YOURPASSWORD'.*|BPQ_PASS   = '${INPUT_BPQ_PASS}'|" "$SCRIPTS_DIR/bpq-chat-daemon.py"
    ok "bpq-chat-daemon.py patched with callsign and password"
fi
if [[ -f "$SCRIPTS_DIR/bpq-aprs-daemon.py" ]]; then
    sed -i "s|APRS_CALL.*=.*'YOURCALL-1'|APRS_CALL    = '${INPUT_APRS_CALL}'|" "$SCRIPTS_DIR/bpq-aprs-daemon.py"
    sed -i "s|APRS_PASS.*=.*'0'|APRS_PASS    = '${INPUT_APRS_PASS}'|" "$SCRIPTS_DIR/bpq-aprs-daemon.py"
    # Use Python to patch APRS filter — avoids sed regex issues with decimal points in coordinates
    python3 -c "
import re, sys
f = open('$SCRIPTS_DIR/bpq-aprs-daemon.py', 'r')
c = f.read()
f.close()
c = re.sub(r'r/0\.0000/0\.0000/300', 'r/${INPUT_LAT}/${INPUT_LON}/300', c)
f = open('$SCRIPTS_DIR/bpq-aprs-daemon.py', 'w')
f.write(c)
f.close()
" && ok "APRS filter patched: r/${INPUT_LAT}/${INPUT_LON}/300" || warn "APRS filter patch failed — edit bpq-aprs-daemon.py manually" 
    ok "bpq-aprs-daemon.py patched with callsign, passcode and location"
fi

# Patch bpq-telnet-daemon.py
if [[ -f "$SCRIPTS_DIR/bpq-telnet-daemon.py" ]]; then
    sed -i "s|BPQ_USER    = 'YOURCALL'|BPQ_USER    = '${INPUT_CALL}'|" "$SCRIPTS_DIR/bpq-telnet-daemon.py"
    sed -i "s|BPQ_PASS    = 'YOURPASSWORD'|BPQ_PASS    = '${INPUT_BPQ_PASS}'|" "$SCRIPTS_DIR/bpq-telnet-daemon.py"
    ok "bpq-telnet-daemon.py patched with callsign and password"
fi

# Patch bpq-vara-daemon.py
if [[ -f "$SCRIPTS_DIR/bpq-vara-daemon.py" ]]; then
    sed -i "s|BPQ_USER    = 'YOURCALL'|BPQ_USER    = '${INPUT_CALL}'|" "$SCRIPTS_DIR/bpq-vara-daemon.py"
    sed -i "s|BPQ_PASS    = 'YOURPASSWORD'|BPQ_PASS    = '${INPUT_BPQ_PASS}'|" "$SCRIPTS_DIR/bpq-vara-daemon.py"
    sed -i "s|BPQ_VARA_PORT = 3|BPQ_VARA_PORT = ${INPUT_BPQ_VARA_PORT}|" "$SCRIPTS_DIR/bpq-vara-daemon.py"
    ok "bpq-vara-daemon.py patched with callsign, password and VARA port"
fi

# Patch vara-api.php
if [[ -f "$WEB_ROOT/vara-api.php" ]]; then
    sed -i "s|'YOURPASSWORD';        // overridden from config.php bbs.pass|'${INPUT_BPQ_PASS}';        // overridden from config.php bbs.pass|" "$WEB_ROOT/vara-api.php"
    sed -i "s|'sysop@example.com';  // set to your email address|'${INPUT_EMAIL}';  // set to your email address|" "$WEB_ROOT/vara-api.php"
    sed -i "s|'127.0.0.1';          // flrig host — localhost or remote IP|'${INPUT_FLRIG_HOST}';          // flrig host|" "$WEB_ROOT/vara-api.php"
    sed -i "s|\$FLRIG_PORT  = 12345;|\$FLRIG_PORT  = ${INPUT_FLRIG_PORT};|" "$WEB_ROOT/vara-api.php"
    sed -i "s|'YOURCALL'|'${INPUT_CALL}'|g" "$WEB_ROOT/vara-api.php"
    ok "vara-api.php patched with callsign, email and flrig settings"
fi

# Patch bpq-nodes-api.php
if [[ -f "$WEB_ROOT/bpq-nodes-api.php" ]]; then
    sed -i "s|\$BPQ_USER  = 'YOURCALL';|\$BPQ_USER  = '${INPUT_CALL}';|" "$WEB_ROOT/bpq-nodes-api.php"
    sed -i "s|\$BPQ_PASS  = 'YOURPASSWORD';|\$BPQ_PASS  = '${INPUT_BPQ_PASS}';|" "$WEB_ROOT/bpq-nodes-api.php"
    sed -i "s|\$NODE_CALL = 'YOURCALL-4';|\$NODE_CALL = '${INPUT_NODE}';|" "$WEB_ROOT/bpq-nodes-api.php"
    ok "bpq-nodes-api.php patched with callsign and password"
fi

# ── Optional: Configure LinBPQ logdir switch ──────────────────────
say "Checking for LinBPQ log redirect..."
if [[ -f "/etc/systemd/system/linbpq.service" ]]; then
    LINBPQ_SVC=$(cat /etc/systemd/system/linbpq.service)
    if echo "$LINBPQ_SVC" | grep -q "logdir"; then
        ok "LinBPQ logdir already configured"
    else
        echo ""
        echo -e "${BOLD}LinBPQ service found. Redirect its logs to the dashboard logs folder?${NC}"
        echo "  This allows the dashboard log viewer to show BPQ BBS activity logs."
        read -r -p "  Configure LinBPQ logdir? [Y/n]: " LOGDIR_CHOICE
        LOGDIR_CHOICE="${LOGDIR_CHOICE:-Y}"
        if [[ "$LOGDIR_CHOICE" =~ ^[Yy] ]]; then
            # Add logdir to ExecStart line
            sed -i "s|ExecStart=\(.*linbpq\)\(.*\)$|ExecStart= logdir=${WEB_ROOT}/logs|"                 /etc/systemd/system/linbpq.service
            chmod 777 "${WEB_ROOT}/logs"
            systemctl daemon-reload >> "$LOG" 2>&1
            ok "LinBPQ logdir set to ${WEB_ROOT}/logs"
            warn "Restart LinBPQ for changes to take effect: sudo systemctl restart linbpq"
        fi
    fi
elif command -v linbpq &>/dev/null || find /home -name linbpq -type f 2>/dev/null | head -1 | grep -q linbpq; then
    echo ""
    say "LinBPQ found but no systemd service detected."
    say "To redirect logs manually, start LinBPQ with: logdir=${WEB_ROOT}/logs"
fi

# bpq-chat daemon
if [[ -f "$SCRIPTS_DIR/bpq-chat-daemon.py" ]]; then
    install_daemon "bpq-chat" "$SCRIPTS_DIR/bpq-chat-daemon.py" "$WEB_USER" 
else
    warn "bpq-chat-daemon.py not found — chat daemon not installed"
fi

# bpq-aprs daemon
if [[ -f "$SCRIPTS_DIR/bpq-aprs-daemon.py" ]]; then
    install_daemon "bpq-aprs" "$SCRIPTS_DIR/bpq-aprs-daemon.py" "$WEB_USER"
else
    warn "bpq-aprs-daemon.py not found — APRS daemon not installed"
fi

# vara-validator (optional — needs VARA HF configured)
if [[ -f "$SCRIPTS_DIR/vara-callsign-validator.py" ]]; then
    say "Installing VARA callsign validator (optional)..."
    cat > /etc/systemd/system/vara-validator.service << VARASVC
[Unit]
Description=BPQ Dashboard - VARA Callsign Validator Proxy
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
VARASVC
    systemctl daemon-reload >> "$LOG" 2>&1
    systemctl enable vara-validator >> "$LOG" 2>&1
    ok "vara-validator service installed (start when VARA HF is configured)"
fi

# bpq-telnet daemon (WebSocket→BPQ telnet proxy)
if [[ -f "$SCRIPTS_DIR/bpq-telnet-daemon.py" ]]; then
    install_daemon "bpq-telnet" "$SCRIPTS_DIR/bpq-telnet-daemon.py" "$WEB_USER"
else
    warn "bpq-telnet-daemon.py not found — Telnet terminal daemon not installed"
fi

# bpq-vara daemon (WebSocket→BPQ VARA HF proxy)
if [[ -f "$SCRIPTS_DIR/bpq-vara-daemon.py" ]]; then
    install_daemon "bpq-vara" "$SCRIPTS_DIR/bpq-vara-daemon.py" "$WEB_USER"
else
    warn "bpq-vara-daemon.py not found — VARA HF terminal daemon not installed"
fi

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 12 — CONFIGURE CRON JOBS (ROOT)"
# ═══════════════════════════════════════════════════════════════════

say "Setting up scheduled tasks..."

# Read existing root crontab
CURRENT_CRON=$(crontab -l 2>/dev/null || echo "")

add_cron_entry() {
    local pattern="$1"
    local entry="$2"
    local label="$3"
    if echo "$CURRENT_CRON" | grep -qF "$pattern"; then
        ok "Cron already exists: $label"
    else
        CURRENT_CRON="${CURRENT_CRON}"$'\n'"${entry}"
        echo "$CURRENT_CRON" | crontab -
        ok "Cron added: $label"
    fi
}

# connect-watchdog — every 5 minutes (ROOT ONLY)
add_cron_entry "connect-watchdog" \
    "*/5 * * * * /usr/bin/python3 $SCRIPTS_DIR/connect-watchdog.py >> /var/log/connect-watchdog.log 2>&1" \
    "connect-watchdog every 5 min"

# wp_manager auto-clean — 3am daily
add_cron_entry "wp_manager" \
    "0 3 * * * cd $LINBPQ_DIR && /usr/bin/python3 $SCRIPTS_DIR/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1" \
    "wp_manager auto-clean at 3am"

# Remove duplicate cron from non-root users (race condition fix)
for CRONUSER in tony pi ubuntu debian "${SUDO_USER:-}"; do
    [[ -z "$CRONUSER" ]] && continue
    id "$CRONUSER" &>/dev/null || continue
    USER_CRON=$(crontab -u "$CRONUSER" -l 2>/dev/null || echo "")
    if echo "$USER_CRON" | grep -q "connect-watchdog"; then
        echo "$USER_CRON" | grep -v "connect-watchdog" | crontab -u "$CRONUSER" -
        warn "Removed duplicate connect-watchdog from $CRONUSER crontab (prevented race condition)"
    fi
done

ok "Cron jobs configured in root crontab"

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 13 — CREATE LOG FILES"
# ═══════════════════════════════════════════════════════════════════

for lf in \
    /var/log/connect-watchdog.log \
    /var/log/vara-validator.log \
    /var/log/wp-auto-clean.log; do
    touch "$lf"
    chmod 644 "$lf"
    ok "Log file: $lf"
done

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 14 — FIREWALL / IPTABLES"
# ═══════════════════════════════════════════════════════════════════

say "Configuring firewall rules..."

# Loopback rule (required for VARA validator)
if ! iptables -C INPUT -i lo -j ACCEPT 2>/dev/null; then
    iptables -I INPUT 1 -i lo -j ACCEPT
    ok "Added iptables loopback ACCEPT rule"
else
    ok "Loopback ACCEPT rule already exists"
fi

# Allow HTTP and HTTPS
for port in 80 443; do
    if ! iptables -C INPUT -p tcp --dport $port -j ACCEPT 2>/dev/null; then
        iptables -A INPUT -p tcp --dport $port -j ACCEPT
        ok "Opened port $port (HTTP/HTTPS)"
    else
        ok "Port $port already open"
    fi
done

# Save iptables rules
if command -v netfilter-persistent &>/dev/null; then
    netfilter-persistent save >> "$LOG" 2>&1
    ok "iptables rules saved (persistent)"
else
    warn "netfilter-persistent not found — iptables rules not persisted across reboot"
fi

# ═══════════════════════════════════════════════════════════════════
hdr "STEP 15 — FINAL PERMISSIONS SWEEP"
# ═══════════════════════════════════════════════════════════════════

say "Applying final permissions..."

# Web root — all files owned by www-data
chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT"

# HTML/PHP/static — readable
find "$WEB_ROOT" -maxdepth 1 -type f \( -name "*.html" -o -name "*.php" \
    -o -name "*.svg" -o -name "*.ico" \) -exec chmod 644 {} \;

# Scripts — executable
find "$SCRIPTS_DIR" -name "*.py" -exec chmod 755 {} \;

# Cache + data — writable by www-data
chmod -R 775 "$CACHE_DIR" "$DATA_DIR"

# config.php — not world-readable
chmod 640 "$WEB_ROOT/config.php"

# Log files
chmod 644 /var/log/connect-watchdog.log \
           /var/log/vara-validator.log \
           /var/log/wp-auto-clean.log 2>/dev/null || true

ok "All permissions applied"

# ═══════════════════════════════════════════════════════════════════
hdr "INSTALLATION COMPLETE"
# ═══════════════════════════════════════════════════════════════════

echo ""
echo -e "${BOLD}${CYN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYN}║              INSTALLATION SUMMARY                   ║${NC}"
echo -e "${BOLD}${CYN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${GRN}✓ Passed : $PASS${NC}"
echo -e "  ${RED}✗ Failed : $FAIL${NC}"
echo -e "  ${YLW}⚠ Warned : $WARN${NC}"
echo -e "  Full log : $LOG"
echo ""

if [[ ${#ERRORS[@]} -gt 0 ]]; then
    echo -e "${RED}${BOLD}Issues to resolve:${NC}"
    for e in "${ERRORS[@]}"; do
        echo -e "  ${RED}→ $e${NC}"
    done
    echo ""
fi

echo -e "${BOLD}${GRN}YOUR BPQ DASHBOARD IS READY${NC}\n"
echo -e "  Dashboard URL : ${BLU}http://$INPUT_HOST/${NC}"
echo -e "  Check tool    : ${BLU}http://$INPUT_HOST/install-check.php?pass=bpqcheck${NC}"
echo ""
echo -e "${BOLD}NEXT STEPS:${NC}"
echo -e "  ${YLW}1.${NC}  Verify LinBPQ is running and accessible on port 8010"
echo -e "  ${YLW}2.${NC}  Open dashboard:  ${BLU}http://${INPUT_HOST}/${NC}"
echo -e "  ${YLW}3.${NC}  Review config:   ${BLU}sudo nano $WEB_ROOT/config.php${NC}"
echo -e "  ${YLW}4.${NC}  Check daemons:   ${BLU}sudo systemctl status bpq-chat bpq-aprs bpq-telnet bpq-vara${NC}"
echo -e "  ${YLW}5.${NC}  View logs:       ${BLU}sudo journalctl -u bpq-telnet -u bpq-vara -f${NC}"
echo -e "  ${YLW}6.${NC}  BPQ Telnet:      ${BLU}http://${INPUT_HOST}/bpq-telnet.html${NC}"
echo -e "  ${YLW}7.${NC}  VARA HF Terminal:${BLU}http://${INPUT_HOST}/bpq-vara.html${NC}  (password: your BPQ password)"
echo -e "  ${YLW}8.${NC}  NetROM Nodes:    Auto-populated in bpq-telnet.html sidebar"
echo -e "  ${YLW}9.${NC}  Run SSL setup:   ${BLU}sudo certbot --nginx -d $INPUT_HOST${NC}"
echo -e "  ${YLW}10.${NC} Remove checker:  ${BLU}sudo rm $WEB_ROOT/install-check.php${NC}"
echo ""
echo -e "  Log saved to: $LOG"
echo ""
