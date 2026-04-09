#!/bin/bash
#
# ============================================================================
# BPQ Dashboard - Linux Deployment Script
# ============================================================================
#
# Deploys the BPQ Dashboard suite to a Linux web server
#
# Components:
#   - RF Connections Dashboard (bpq-rf-connections.html)
#   - System Logs Dashboard (bpq-system-logs.html)
#   - Traffic Statistics Dashboard (bpq-traffic.html)
#   - Email Monitor Dashboard (bpq-email-monitor.html)
#   - VARA Analysis Tools
#   - Node RTT Testing Script
#
# Usage:
#   ./deploy-linux.sh              # Interactive setup
#   ./deploy-linux.sh --auto       # Auto-detect and install
#   ./deploy-linux.sh --help       # Show help
#
# ============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Defaults
WEB_ROOT=""
BPQ_DIR="bpq"
INSTALL_DIR=""
BPQ_TELNET_HOST="localhost"
BPQ_TELNET_PORT="8010"
BPQ_SYSOP_USER=""
BPQ_SYSOP_PASS=""
VARA_LOG_DIR="/var/log/vara"
VARA_CALLSIGN_URL="https://your-domain.com/callsign.txt"
VARA_CALLSIGN_FILE=""

# ============================================================================
# Functions
# ============================================================================

print_banner() {
    echo -e "${CYAN}"
    echo "╔═══════════════════════════════════════════════════════════════════╗"
    echo "║                                                                   ║"
    echo "║                    BPQ Dashboard Deployment                       ║"
    echo "║                                                                   ║"
    echo "║     RF Connections • System Logs • Traffic Stats • Email          ║"
    echo "║                                                                   ║"
    echo "╚═══════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

check_root() {
    if [ "$EUID" -ne 0 ]; then
        log_warn "Not running as root. Some operations may require sudo."
    fi
}

check_wget() {
    if ! command -v wget &> /dev/null; then
        log_warn "wget not found. Installing..."
        if command -v apt-get &> /dev/null; then
            sudo apt-get update && sudo apt-get install -y wget
        elif command -v yum &> /dev/null; then
            sudo yum install -y wget
        elif command -v pacman &> /dev/null; then
            sudo pacman -S wget
        else
            log_error "Please install wget manually"
            return 1
        fi
    fi
    log_success "wget is available"
}

detect_web_server() {
    log_info "Detecting web server..."
    
    # Check for Apache
    if [ -d "/var/www/html" ] && (systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null); then
        WEB_ROOT="/var/www/html"
        log_success "Detected Apache at /var/www/html"
        return 0
    fi
    
    # Check for Nginx
    if [ -d "/usr/share/nginx/html" ] && systemctl is-active --quiet nginx 2>/dev/null; then
        WEB_ROOT="/usr/share/nginx/html"
        log_success "Detected Nginx at /usr/share/nginx/html"
        return 0
    fi
    
    # Check for lighttpd
    if [ -d "/var/www/html" ] && systemctl is-active --quiet lighttpd 2>/dev/null; then
        WEB_ROOT="/var/www/html"
        log_success "Detected lighttpd at /var/www/html"
        return 0
    fi
    
    # Check directory exists but server may not be running
    if [ -d "/var/www/html" ]; then
        WEB_ROOT="/var/www/html"
        log_warn "Found /var/www/html but web server may not be running"
        return 0
    fi
    
    # No web server found
    log_error "No web server detected!"
    echo ""
    echo -e "${YELLOW}════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${YELLOW}                    WEB SERVER REQUIRED                             ${NC}"
    echo -e "${YELLOW}════════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "BPQ Dashboard requires a web server to function."
    echo ""
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║  RECOMMENDED: Uniform Server (Free & Lightweight)                 ║${NC}"
    echo -e "${CYAN}║                                                                   ║${NC}"
    echo -e "${CYAN}║  Download: https://www.uniformserver.com/                         ║${NC}"
    echo -e "${CYAN}║                                                                   ║${NC}"
    echo -e "${CYAN}║  • Portable - no installation required                            ║${NC}"
    echo -e "${CYAN}║  • Lightweight - minimal resource usage                           ║${NC}"
    echo -e "${CYAN}║  • Easy to use - just extract and run                             ║${NC}"
    echo -e "${CYAN}║  • Cross-platform - works on Windows and Linux (via Wine)         ║${NC}"
    echo -e "${CYAN}╚═══════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "Alternative options for Linux:"
    echo ""
    echo "  Apache (Ubuntu/Debian):"
    echo "    sudo apt-get install apache2"
    echo "    sudo systemctl start apache2"
    echo ""
    echo "  Nginx (Ubuntu/Debian):"
    echo "    sudo apt-get install nginx"
    echo "    sudo systemctl start nginx"
    echo ""
    echo "  Lighttpd (lightweight):"
    echo "    sudo apt-get install lighttpd"
    echo "    sudo systemctl start lighttpd"
    echo ""
    echo "After installing a web server, run this script again."
    echo ""
    
    read -p "Enter web root path manually (or press Enter to exit): " manual_path
    if [ -n "$manual_path" ] && [ -d "$manual_path" ]; then
        WEB_ROOT="$manual_path"
        log_info "Using manual path: $WEB_ROOT"
        return 0
    fi
    
    return 1
}

detect_bpq() {
    # Check common BPQ locations
    if [ -f "/etc/bpq32.cfg" ]; then
        log_info "Found BPQ config at /etc/bpq32.cfg"
    elif [ -f "$HOME/linbpq/bpq32.cfg" ]; then
        log_info "Found BPQ config at $HOME/linbpq/bpq32.cfg"
    elif [ -f "/opt/linbpq/bpq32.cfg" ]; then
        log_info "Found BPQ config at /opt/linbpq/bpq32.cfg"
    fi
    
    # Check if BPQ telnet port is open
    if command -v nc &> /dev/null; then
        if nc -z localhost 8010 2>/dev/null; then
            log_success "BPQ Telnet port 8010 is open"
        else
            log_warn "BPQ Telnet port 8010 not responding"
        fi
    fi
}

fetch_vara_callsign() {
    log_info "Fetching VARA callsign list..."
    
    VARA_CALLSIGN_FILE="$INSTALL_DIR/data/callsign.vara"
    
    # Create data directory if needed
    mkdir -p "$INSTALL_DIR/data"
    
    # Fetch callsign list using wget
    if wget -q --timeout=30 --tries=3 -O "$VARA_CALLSIGN_FILE" "$VARA_CALLSIGN_URL" 2>/dev/null; then
        if [ -s "$VARA_CALLSIGN_FILE" ]; then
            local count=$(wc -l < "$VARA_CALLSIGN_FILE")
            log_success "Downloaded callsign.vara ($count entries)"
        else
            log_warn "Downloaded file is empty"
            rm -f "$VARA_CALLSIGN_FILE"
        fi
    else
        log_warn "Could not fetch callsign list from $VARA_CALLSIGN_URL"
        log_info "You can manually download later with:"
        echo "  wget -O $VARA_CALLSIGN_FILE $VARA_CALLSIGN_URL"
    fi
}

create_vara_update_script() {
    log_info "Creating VARA callsign update script..."
    
    cat > "$INSTALL_DIR/scripts/update-vara-callsigns.sh" << 'VARASCRIPT'
#!/bin/bash
#
# Update VARA callsign list from BPQDash
#
# Usage: ./update-vara-callsigns.sh
#
# Add to crontab for automatic updates:
#   0 6 * * * /path/to/update-vara-callsigns.sh
#

VARA_URL="https://your-domain.com/callsign.txt"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VARA_FILE="$SCRIPT_DIR/../data/callsign.vara"
BACKUP_FILE="$VARA_FILE.bak"

echo "Updating VARA callsign list..."
echo "Source: $VARA_URL"

# Backup existing file
if [ -f "$VARA_FILE" ]; then
    cp "$VARA_FILE" "$BACKUP_FILE"
fi

# Fetch new list
if wget -q --timeout=30 --tries=3 -O "$VARA_FILE.tmp" "$VARA_URL"; then
    if [ -s "$VARA_FILE.tmp" ]; then
        mv "$VARA_FILE.tmp" "$VARA_FILE"
        COUNT=$(wc -l < "$VARA_FILE")
        echo "Success: Downloaded $COUNT callsigns"
        echo "Saved to: $VARA_FILE"
    else
        echo "Error: Downloaded file is empty"
        rm -f "$VARA_FILE.tmp"
        # Restore backup
        if [ -f "$BACKUP_FILE" ]; then
            mv "$BACKUP_FILE" "$VARA_FILE"
        fi
        exit 1
    fi
else
    echo "Error: Failed to download from $VARA_URL"
    rm -f "$VARA_FILE.tmp"
    exit 1
fi
VARASCRIPT

    chmod +x "$INSTALL_DIR/scripts/update-vara-callsigns.sh"
    log_success "Created update-vara-callsigns.sh"
}

interactive_setup() {
    echo ""
    log_info "Interactive Setup"
    echo "================="
    echo ""
    
    # Web root
    read -p "Web server root directory [$WEB_ROOT]: " input
    WEB_ROOT="${input:-$WEB_ROOT}"
    
    # BPQ subdirectory
    read -p "BPQ dashboard subdirectory [$BPQ_DIR]: " input
    BPQ_DIR="${input:-$BPQ_DIR}"
    
    INSTALL_DIR="$WEB_ROOT/$BPQ_DIR"
    
    # BPQ Telnet settings
    echo ""
    log_info "BPQ Telnet Configuration"
    read -p "BPQ Telnet host [$BPQ_TELNET_HOST]: " input
    BPQ_TELNET_HOST="${input:-$BPQ_TELNET_HOST}"
    
    read -p "BPQ Telnet port [$BPQ_TELNET_PORT]: " input
    BPQ_TELNET_PORT="${input:-$BPQ_TELNET_PORT}"
    
    read -p "BPQ Sysop username (for scripts): " BPQ_SYSOP_USER
    read -s -p "BPQ Sysop password: " BPQ_SYSOP_PASS
    echo ""
    
    # VARA settings
    echo ""
    log_info "VARA Configuration (optional)"
    read -p "VARA log directory [$VARA_LOG_DIR]: " input
    VARA_LOG_DIR="${input:-$VARA_LOG_DIR}"
    
    echo ""
    echo "Configuration Summary:"
    echo "======================"
    echo "  Install directory: $INSTALL_DIR"
    echo "  BPQ Telnet: $BPQ_TELNET_HOST:$BPQ_TELNET_PORT"
    echo "  VARA logs: $VARA_LOG_DIR"
    echo ""
    
    read -p "Proceed with installation? [Y/n]: " confirm
    if [[ "$confirm" =~ ^[Nn] ]]; then
        log_info "Installation cancelled"
        exit 0
    fi
}

create_directories() {
    log_info "Creating directories..."
    
    mkdir -p "$INSTALL_DIR"
    mkdir -p "$INSTALL_DIR/logs"
    mkdir -p "$INSTALL_DIR/data"
    mkdir -p "$INSTALL_DIR/scripts"
    
    log_success "Directories created"
}

install_dashboards() {
    log_info "Installing dashboard files..."
    
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    # Copy HTML dashboards
    cp "$SCRIPT_DIR/bpq-rf-connections.html" "$INSTALL_DIR/" 2>/dev/null || log_warn "bpq-rf-connections.html not found"
    cp "$SCRIPT_DIR/bpq-system-logs.html" "$INSTALL_DIR/" 2>/dev/null || log_warn "bpq-system-logs.html not found"
    cp "$SCRIPT_DIR/bpq-traffic.html" "$INSTALL_DIR/" 2>/dev/null || log_warn "bpq-traffic.html not found"
    cp "$SCRIPT_DIR/bpq-email-monitor.html" "$INSTALL_DIR/" 2>/dev/null || log_warn "bpq-email-monitor.html not found"
    cp "$SCRIPT_DIR/favicon.svg" "$INSTALL_DIR/" 2>/dev/null || true
    
    # Copy PHP proxy for solar data
    cp "$SCRIPT_DIR/solar-proxy.php" "$INSTALL_DIR/" 2>/dev/null || true
    
    log_success "Dashboard files installed"
}

install_scripts() {
    log_info "Installing utility scripts..."
    
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    # Copy scripts
    cp "$SCRIPT_DIR/fetch-vara.sh" "$INSTALL_DIR/scripts/" 2>/dev/null || true
    cp "$SCRIPT_DIR/vara-analysis.sh" "$INSTALL_DIR/scripts/" 2>/dev/null || true
    cp "$SCRIPT_DIR/vara-logger.sh" "$INSTALL_DIR/scripts/" 2>/dev/null || true
    cp "$SCRIPT_DIR/nodes-rtt.sh" "$INSTALL_DIR/scripts/" 2>/dev/null || true
    
    # Make scripts executable
    chmod +x "$INSTALL_DIR/scripts/"*.sh 2>/dev/null || true
    
    log_success "Scripts installed"
}

configure_vara_logger() {
    log_info "Configuring VARA logger service..."
    
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    # Update service file with correct paths
    if [ -f "$SCRIPT_DIR/vara-logger.service" ]; then
        sed -e "s|/path/to/vara-logger.sh|$INSTALL_DIR/scripts/vara-logger.sh|g" \
            -e "s|/var/log/vara|$VARA_LOG_DIR|g" \
            "$SCRIPT_DIR/vara-logger.service" > "/tmp/vara-logger.service"
        
        if [ "$EUID" -eq 0 ]; then
            cp "/tmp/vara-logger.service" "/etc/systemd/system/vara-logger.service"
            systemctl daemon-reload
            log_success "VARA logger service installed"
            echo "  To enable: sudo systemctl enable vara-logger"
            echo "  To start:  sudo systemctl start vara-logger"
        else
            log_warn "Run as root to install systemd service"
            cp "/tmp/vara-logger.service" "$INSTALL_DIR/scripts/vara-logger.service"
        fi
    fi
}

create_index_page() {
    log_info "Creating index page..."
    
    cat > "$INSTALL_DIR/index.html" << 'INDEXEOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BPQ Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg-primary: #0a0a0f;
            --bg-card: #12121a;
            --bg-card-hover: #1a1a24;
            --border-color: #2a2a3a;
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --accent-red: #ff2d55;
            --accent-orange: #ff9500;
            --accent-green: #30d158;
            --accent-blue: #0a84ff;
            --accent-purple: #bf5af2;
        }
        
        body {
            font-family: 'JetBrains Mono', monospace;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .header {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .header h1 {
            font-family: 'Oswald', sans-serif;
            font-size: 3rem;
            font-weight: 700;
            letter-spacing: 2px;
            margin-bottom: 0.5rem;
        }
        
        .header h1 span {
            color: var(--accent-red);
        }
        
        .header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            max-width: 1200px;
            width: 100%;
        }
        
        .dashboard-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-card:hover {
            background: var(--bg-card-hover);
            transform: translateY(-4px);
            border-color: var(--accent-red);
            box-shadow: 0 10px 40px rgba(255, 45, 85, 0.2);
        }
        
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--card-accent, var(--accent-red));
        }
        
        .dashboard-card h2 {
            font-family: 'Oswald', sans-serif;
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .dashboard-card h2 .icon {
            font-size: 1.5rem;
        }
        
        .dashboard-card p {
            color: var(--text-secondary);
            font-size: 0.8rem;
            line-height: 1.5;
        }
        
        .card-rf { --card-accent: var(--accent-green); }
        .card-logs { --card-accent: var(--accent-orange); }
        .card-traffic { --card-accent: var(--accent-blue); }
        .card-email { --card-accent: var(--accent-purple); }
        
        .footer {
            margin-top: 3rem;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.75rem;
        }
        
        .footer a {
            color: var(--accent-red);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>BPQ <span>Dashboard</span></h1>
        <p>Packet Radio Network Monitoring Suite</p>
    </div>
    
    <div class="dashboard-grid">
        <a href="bpq-rf-connections.html" class="dashboard-card card-rf">
            <h2><span class="icon">📡</span> RF Connections</h2>
            <p>Real-time RF connection monitoring with signal quality metrics, node mapping, and connection history.</p>
        </a>
        
        <a href="bpq-system-logs.html" class="dashboard-card card-logs">
            <h2><span class="icon">📋</span> System Logs</h2>
            <p>Live BPQ system log viewer with filtering, search, and automatic refresh capabilities.</p>
        </a>
        
        <a href="bpq-traffic.html" class="dashboard-card card-traffic">
            <h2><span class="icon">📊</span> Traffic Statistics</h2>
            <p>Network traffic analysis with charts, throughput metrics, and historical data visualization.</p>
        </a>
        
        <a href="bpq-email-monitor.html" class="dashboard-card card-email">
            <h2><span class="icon">📧</span> Email Monitor</h2>
            <p>BBS message queue monitoring with delivery status tracking and queue management.</p>
        </a>
    </div>
    
    <div class="footer">
        <p>BPQ Dashboard • <a href="https://github.com/g8bpq/linbpq" target="_blank">LinBPQ</a></p>
    </div>
</body>
</html>
INDEXEOF

    log_success "Index page created"
}

set_permissions() {
    log_info "Setting permissions..."
    
    # Set ownership to web server user
    if id "www-data" &>/dev/null; then
        chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || true
    elif id "nginx" &>/dev/null; then
        chown -R nginx:nginx "$INSTALL_DIR" 2>/dev/null || true
    elif id "apache" &>/dev/null; then
        chown -R apache:apache "$INSTALL_DIR" 2>/dev/null || true
    fi
    
    chmod -R 755 "$INSTALL_DIR"
    chmod 644 "$INSTALL_DIR"/*.html 2>/dev/null || true
    chmod 644 "$INSTALL_DIR"/*.php 2>/dev/null || true
    
    log_success "Permissions set"
}

print_summary() {
    echo ""
    echo -e "${GREEN}════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}                    Installation Complete!                          ${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "Dashboard URL: http://localhost/$BPQ_DIR/"
    echo ""
    echo "Installed Dashboards:"
    echo "  • RF Connections:  http://localhost/$BPQ_DIR/bpq-rf-connections.html"
    echo "  • System Logs:     http://localhost/$BPQ_DIR/bpq-system-logs.html"
    echo "  • Traffic Stats:   http://localhost/$BPQ_DIR/bpq-traffic.html"
    echo "  • Email Monitor:   http://localhost/$BPQ_DIR/bpq-email-monitor.html"
    echo ""
    echo "Utility Scripts:"
    echo "  • $INSTALL_DIR/scripts/fetch-vara.sh"
    echo "  • $INSTALL_DIR/scripts/vara-analysis.sh"
    echo "  • $INSTALL_DIR/scripts/nodes-rtt.sh"
    echo "  • $INSTALL_DIR/scripts/update-vara-callsigns.sh"
    echo ""
    echo "VARA Callsign Data:"
    if [ -f "$INSTALL_DIR/data/callsign.vara" ]; then
        local count=$(wc -l < "$INSTALL_DIR/data/callsign.vara")
        echo "  • $INSTALL_DIR/data/callsign.vara ($count entries)"
    else
        echo "  • Not downloaded (run update-vara-callsigns.sh to fetch)"
    fi
    echo ""
    echo "To update VARA callsigns automatically, add to crontab:"
    echo "  0 6 * * * $INSTALL_DIR/scripts/update-vara-callsigns.sh"
    echo ""
    echo "Configuration:"
    echo "  Edit each dashboard HTML file to configure your BPQ connection"
    echo "  settings (host, port, callsign) in the CONFIG section."
    echo ""
    echo -e "${YELLOW}Note: Ensure your BPQ node's web server or API is accessible.${NC}"
    echo ""
}

show_help() {
    echo "BPQ Dashboard - Linux Deployment Script"
    echo ""
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --auto        Auto-detect settings and install"
    echo "  --help, -h    Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0              # Interactive installation"
    echo "  sudo $0 --auto  # Automatic installation"
    echo ""
}

# ============================================================================
# Main
# ============================================================================

main() {
    print_banner
    
    case "$1" in
        --help|-h)
            show_help
            exit 0
            ;;
        --auto)
            check_root
            check_wget
            if ! detect_web_server; then
                exit 1
            fi
            detect_bpq
            INSTALL_DIR="$WEB_ROOT/$BPQ_DIR"
            ;;
        *)
            check_root
            check_wget
            if ! detect_web_server; then
                exit 1
            fi
            detect_bpq
            interactive_setup
            ;;
    esac
    
    create_directories
    install_dashboards
    install_scripts
    create_vara_update_script
    fetch_vara_callsign
    configure_vara_logger
    create_index_page
    set_permissions
    print_summary
}

main "$@"
