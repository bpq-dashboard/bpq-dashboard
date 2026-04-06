#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
#  BPQ Dashboard v1.5.5 — Guided Installation Script
#  For Debian / Ubuntu / Raspberry Pi OS
#
#  HOW TO RUN:
#    1. Copy the BPQ-Dashboard-v1.5.5.zip to your Linux machine
#    2. Unzip it:   unzip BPQ-Dashboard-v1.5.5.zip
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
  ║      BPQ Dashboard v1.5.5 — Guided Installer        ║
  ║      K1AJD — TPRFN Network — Hephzibah GA           ║
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
ask "Your station callsign (e.g. K1AJD):"
read -r INPUT_CALL
INPUT_CALL="${INPUT_CALL:-K1AJD}"
INPUT_CALL="${INPUT_CALL^^}"   # uppercase
ok "Callsign: $INPUT_CALL"

# Node callsign
ask "Your BPQ node callsign with SSID (e.g. K1AJD-4) [${INPUT_CALL}-4]:"
read -r INPUT_NODE
INPUT_NODE="${INPUT_NODE:-${INPUT_CALL}-4}"
INPUT_NODE="${INPUT_NODE^^}"
ok "Node: $INPUT_NODE"

# BPQ telnet password
ask "Your BPQ telnet password (set in bpq32.cfg USER= line):"
read -r -s INPUT_BPQ_PASS
echo ""
INPUT_BPQ_PASS="${INPUT_BPQ_PASS:-changeme}"
ok "BPQ password: (set)"

# Domain / hostname
HOSTNAME_AUTO=$(hostname -f 2>/dev/null || hostname)
ask "Your server hostname or domain name [$HOSTNAME_AUTO]:"
read -r INPUT_HOST
INPUT_HOST="${INPUT_HOST:-$HOSTNAME_AUTO}"
ok "Hostname: $INPUT_HOST"

# Web root — detect or ask
ask "Web root directory [/var/www/tprfn]:"
read -r INPUT_WEBROOT
WEB_ROOT="${INPUT_WEBROOT:-/var/www/tprfn}"
ok "Web root: $WEB_ROOT"

# MySQL root password
ask "Choose a password for the database (MariaDB root) — you'll need this later:"
read -r -s INPUT_DB_ROOT_PASS
echo ""
INPUT_DB_ROOT_PASS="${INPUT_DB_ROOT_PASS:-BpqDashboard1!}"
ok "Database root password: (set)"

# DB for dashboard
INPUT_DB_NAME="tprfn"
INPUT_DB_USER="tprfn_user"
ask "Choose a database password for BPQ Dashboard [auto-generated]:"
read -r -s INPUT_DB_PASS
echo ""
INPUT_DB_PASS="${INPUT_DB_PASS:-$(openssl rand -base64 16 2>/dev/null || echo 'BpqDb2025!')}"
ok "Dashboard DB password: (set)"

# LinBPQ directory
ask "Path to your LinBPQ directory [/home/linbpq]:"
read -r INPUT_LINBPQ
LINBPQ_DIR="${INPUT_LINBPQ:-/home/linbpq}"
ok "LinBPQ dir: $LINBPQ_DIR"

# APRS credentials
ask "Your APRS-IS callsign (usually YOURCALL-1) [${INPUT_CALL}-1]:"
read -r INPUT_APRS_CALL
INPUT_APRS_CALL="${INPUT_APRS_CALL:-${INPUT_CALL}-1}"
INPUT_APRS_CALL="${INPUT_APRS_CALL^^}"

ask "Your APRS-IS passcode (see https://apps.magicbug.co.uk/passcode/):"
read -r INPUT_APRS_PASS
INPUT_APRS_PASS="${INPUT_APRS_PASS:-0}"
ok "APRS: $INPUT_APRS_CALL / passcode set"

# Sysop email
ask "Sysop email address [${INPUT_CALL,,}@example.com]:"
read -r INPUT_EMAIL
INPUT_EMAIL="${INPUT_EMAIL:-${INPUT_CALL,,}@example.com}"
ok "Sysop email: $INPUT_EMAIL"

echo -e "\n${GRN}${BOLD}Configuration collected. Starting installation...${NC}\n"
sleep 2

# Set derived paths
SCRIPTS_DIR="$WEB_ROOT/scripts"
CACHE_DIR="$WEB_ROOT/cache"
DATA_DIR="$WEB_ROOT/data"
IMG_DIR="$WEB_ROOT/img"
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
    php8.3-json
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
            # Try php8.2 fallback
            if [[ "$pkg" == php8.3* ]]; then
                FALLBACK="${pkg/8.3/8.2}"
                say "Trying fallback: $FALLBACK..."
                if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$FALLBACK" >> "$LOG" 2>&1; then
                    ok "Installed fallback: $FALLBACK"
                else
                    warn "Could not install $pkg or fallback — continuing"
                fi
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

# Set root password and secure installation
mysql -u root 2>/dev/null << MYSQL_SETUP
ALTER USER 'root'@'localhost' IDENTIFIED BY '${INPUT_DB_ROOT_PASS}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
CREATE DATABASE IF NOT EXISTS \`${INPUT_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${INPUT_DB_USER}'@'localhost' IDENTIFIED BY '${INPUT_DB_PASS}';
GRANT ALL PRIVILEGES ON \`${INPUT_DB_NAME}\`.* TO '${INPUT_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
MYSQL_SETUP

if [[ $? -eq 0 ]]; then
    ok "MariaDB secured and database '$INPUT_DB_NAME' created"
    ok "Database user '$INPUT_DB_USER' created"
else
    warn "MariaDB setup may have had issues — check $LOG"
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
        'lat'       => 33.4259,
        'lon'       => -82.0099,
        'locator'   => 'EM83XG',
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
        'filter'    => 'r/33.4259/-82.0100/300',
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
hdr "STEP 10 — CONFIGURE NGINX"
# ═══════════════════════════════════════════════════════════════════

# Remove default nginx site if it exists
[[ -f /etc/nginx/sites-enabled/default ]] && \
    rm /etc/nginx/sites-enabled/default && \
    ok "Removed nginx default site"

# Write nginx config
NGINX_CONF="/etc/nginx/sites-available/bpq-dashboard.conf"
NGINX_ENABLED="/etc/nginx/sites-enabled/bpq-dashboard.conf"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"

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
echo -e "  ${YLW}1.${NC} Verify LinBPQ is running and accessible on port 8010"
echo -e "  ${YLW}2.${NC} Review config:  ${BLU}sudo nano $WEB_ROOT/config.php${NC}"
echo -e "  ${YLW}3.${NC} Check daemons:  ${BLU}sudo systemctl status bpq-chat bpq-aprs${NC}"
echo -e "  ${YLW}4.${NC} View logs:      ${BLU}sudo journalctl -u bpq-chat -u bpq-aprs -f${NC}"
echo -e "  ${YLW}5.${NC} Run SSL setup:  ${BLU}sudo certbot --nginx -d $INPUT_HOST${NC}"
echo -e "  ${YLW}6.${NC} Remove checker: ${BLU}sudo rm $WEB_ROOT/install-check.php${NC}"
echo ""
echo -e "  Log saved to: $LOG"
echo ""
