#!/bin/bash
# ============================================================================
#  BPQ Dashboard v1.5.7 — Guided Installer for Amateur Radio Operators
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
   from the folder where you unzipped BPQ-Dashboard-v1.5.7.zip?

   Current folder: $SCRIPT_DIR"
fi

# ────────────────────────────────────────────────────────────────────────────
# Welcome banner
# ────────────────────────────────────────────────────────────────────────────
clear
cat << 'BANNER'
╔══════════════════════════════════════════════════════════════════════╗
║                                                                      ║
║             BPQ Dashboard v1.5.7 — Guided Installer                  ║
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
  ${BOLD}4.${NC} Offer to install MariaDB (the database that stores session
       history). You can skip this — the dashboard works without it,
       but you'll lose some long-term-history features.
  ${BOLD}5.${NC} Copy the dashboard files to /var/www/bpq-dashboard/
  ${BOLD}6.${NC} Generate a config.php with your station settings
  ${BOLD}7.${NC} Configure the web server to serve the dashboard
  ${BOLD}8.${NC} Set up systemd services for the helper daemons
  ${BOLD}9.${NC} Show you the URL where you can open the dashboard

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
PY_PKGS=(python3 python3-pip python3-requests python3-pexpect python3-serial)
say "Installing Python 3 and the libraries the dashboard daemons use..."
if apt-get install -y -qq "${PY_PKGS[@]}"; then
    ok "Python 3 installed"
else
    warn "Some Python packages failed to install — daemons may need manual fixup"
fi

# Useful utilities
say "Installing a few small utilities (curl, jq, sudo)..."
apt-get install -y -qq curl jq sudo >/dev/null 2>&1 && ok "Utilities installed"

# ============================================================================
hdr "STEP 6 — Database (optional)"
# ============================================================================

INSTALL_DB="n"
DB_NAME="bpqdash"
DB_USER="bpqdash"
DB_PASS=""

echo
echo "${BOLD}About the database:${NC}"
echo "The dashboard can store session history, station heatmaps, and"
echo "per-partner quality scores in a small MariaDB database. Without"
echo "the database, the dashboard still works but only shows recent"
echo "log data (the 'live' view) — long-term history charts will be"
echo "empty."
echo
echo "If you're not sure, install it. It's small and harmless."
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

        # Load schema if present
        SCHEMA_FILE="$SCRIPT_DIR/data/prop-decisions-schema.sql"
        if [[ -f "$SCHEMA_FILE" ]]; then
            mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SCHEMA_FILE" 2>/dev/null \
                && ok "Loaded database schema" \
                || warn "Schema file present but didn't load cleanly"
        fi
    else
        err "Could not create database. You may need to run mysql_secure_installation"
        err "first and then re-run this script."
        INSTALL_DB="n"
    fi
fi

# ============================================================================
hdr "STEP 7 — Copy dashboard files"
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
         "$SCRIPT_DIR/"  "$WEB_ROOT/" \
    && ok "Files copied" || err "rsync failed"

# Make sure cache and logs subdirs exist (some are empty in the archive)
mkdir -p "$WEB_ROOT/cache" "$WEB_ROOT/logs" "$WEB_ROOT/data/backups"

# Ownership
chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT" && ok "Set ownership to $WEB_USER"

# Tighten permissions
find "$WEB_ROOT" -type d -exec chmod 755 {} \;
find "$WEB_ROOT" -type f -exec chmod 644 {} \;
# Shell / Python scripts stay executable
find "$WEB_ROOT" -name "*.sh" -exec chmod 755 {} \;
find "$WEB_ROOT" -name "*.py" -exec chmod 755 {} \;

# ============================================================================
hdr "STEP 8 — Generate config.php"
# ============================================================================

CFG="$WEB_ROOT/config.php"
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
    'rate_limit'    => ['enabled' => false],
    'cors'          => ['allow_all' => true],
    'logging'       => ['enabled' => false],
    'paths'         => [
        'logs'    => '$WEB_ROOT/logs/',
        'scripts' => '$WEB_ROOT/scripts/',
        'datalog' => '$WEB_ROOT/logs/',
    ],
    'logs' => [
        'vara_file' => '$(echo "$INPUT_CALL" | tr '[:upper:]' '[:lower:]').vara',
    ],
];
EOF
chmod 640 "$CFG"
chown "$WEB_USER:$WEB_USER" "$CFG"
ok "config.php generated (mode 640, owner $WEB_USER)"

# ============================================================================
hdr "STEP 9 — Configure the web server"
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

    # Block hidden files
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/bpq-dashboard.error.log
    CustomLog \${APACHE_LOG_DIR}/bpq-dashboard.access.log combined
</VirtualHost>
APACHE

    a2enmod php* >/dev/null 2>&1
    a2enmod rewrite >/dev/null 2>&1
    a2dissite 000-default >/dev/null 2>&1 || true
    a2ensite bpq-dashboard >/dev/null 2>&1

    if apache2ctl configtest 2>/dev/null; then
        systemctl reload apache2 && ok "Apache configured and reloaded"
    else
        err "Apache config test failed. See: sudo apache2ctl configtest"
    fi
fi

# ============================================================================
hdr "STEP 10 — Link LinBPQ logs (so the log viewer can see them)"
# ============================================================================

if [[ -d "$INPUT_LINBPQ_DIR" ]]; then
    # The dashboard expects logs at $WEB_ROOT/logs — point it at LinBPQ's logs
    if [[ -L "$WEB_ROOT/logs" ]] || [[ -d "$WEB_ROOT/logs" ]]; then
        rm -rf "$WEB_ROOT/logs"
    fi
    if ln -s "$INPUT_LINBPQ_DIR" "$WEB_ROOT/logs" 2>/dev/null; then
        ok "Symlinked $WEB_ROOT/logs -> $INPUT_LINBPQ_DIR"
    else
        warn "Could not create symlink; created plain directory instead"
        mkdir -p "$WEB_ROOT/logs"
    fi
else
    say "Skipping log symlink — LinBPQ directory doesn't exist yet."
    say "When you set up LinBPQ, run:"
    say "  sudo ln -sf <your-linbpq-dir> $WEB_ROOT/logs"
    mkdir -p "$WEB_ROOT/logs"
fi

# ============================================================================
hdr "STEP 11 — Install systemd services for helper daemons (optional)"
# ============================================================================

echo
echo "The dashboard ships with a few helper daemons (small Python programs"
echo "that run in the background):"
echo
echo "  • bpq-telnet  — keeps a connection open to BPQ for live commands"
echo "  • bpq-chat    — bridges the BPQ chat node to the web UI"
echo "  • bpq-aprs    — feeds APRS data to the APRS page"
echo "  • bpq-vara    — logs VARA modem activity"
echo
echo "You can install them now (they only start if you run 'systemctl"
echo "enable' on them) or skip and add them later by hand."
echo

if yesno "Install systemd unit files for the helper daemons?" Y; then
    for svc in bpq-telnet bpq-chat bpq-aprs bpq-vara vara-logger; do
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
hdr "STEP 12 — Final permissions sweep"
# ============================================================================

chown -R "$WEB_USER:$WEB_USER" "$WEB_ROOT/cache" "$WEB_ROOT/data" 2>/dev/null
chmod 750 "$WEB_ROOT/cache" "$WEB_ROOT/data" 2>/dev/null
chmod 640 "$CFG"
ok "Permissions tightened"

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
   ${CYN}http://$SERVER_IP/nws-dashboard.html${NC}         ← NWS weather alerts
   ${CYN}http://$SERVER_IP/bbs-messages.html${NC}          ← BBS messages
   ${CYN}http://$SERVER_IP/system-audit.html${NC}          ← server health check

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
   3. Read TROUBLESHOOTING.md if anything doesn't work

73 and good luck with the dashboard!
EOF
