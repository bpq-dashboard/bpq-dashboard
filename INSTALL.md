# BPQ Dashboard Installation & Configuration Guide

**Version:** 1.5.5  
**Last Updated:** February 2026

---

## Getting Started

**New to BPQ Dashboard?** Start with the step-by-step guide for your operating system:

- **[INSTALL-WINDOWS.md](INSTALL-WINDOWS.md)** - Complete Windows installation guide for beginners
- **[INSTALL-LINUX.md](INSTALL-LINUX.md)** - Complete Linux installation guide for beginners
- **[QUICK-START.md](QUICK-START.md)** - 5-minute setup for experienced users

This document serves as a comprehensive configuration reference.

---

## Table of Contents

1. [Overview](#overview)
2. [System Requirements](#system-requirements)
3. [Quick Start](#quick-start)
4. [Linux Installation](#linux-installation)
5. [Windows Installation](#windows-installation)
6. [Configuration](#configuration)
7. [Security Modes](#security-modes)
8. [Server-Side Message Storage](#server-side-message-storage)
9. [Dashboard Setup](#dashboard-setup)
10. [Data Archival](#data-archival)
11. [Background Services](#background-services)
12. [Troubleshooting](#troubleshooting)
13. [Security Considerations](#security-considerations)

---

## Overview

BPQ Dashboard is a web-based monitoring suite for BPQ packet radio nodes. It provides real-time visibility into RF connections, system logs, traffic statistics, email queues, BBS messages, and weather alerts.

### Dashboard Components

| Dashboard | Purpose | Data Source |
|-----------|---------|-------------|
| **RF Connections** | VARA HF analytics, channel quality analysis, band analysis, station mapping | VARA log files |
| **System Logs** | Live log viewer, station activity, RMS status | BPQ log files |
| **Traffic Stats** | Message distribution, data transfer metrics | BPQ Traffic files |
| **Message Monitor** | BBS forwarding analytics OR SMTP/POP Server analytics | BBS or TCP log files |
| **BBS Messages** | Read/compose/manage BBS messages | BBS telnet connection |
| **Weather Alerts** | NWS alerts with BBS posting | NWS API |
| **RF Power Monitor** | 4-channel RF power monitoring | DataLog files |

### What's New in v1.5.6

- **Unified Configuration** - Single `config.php` replaces multiple config files
- **Security Modes** - `local` (full features) and `public` (read-only) modes
- **Data Archival System** - Automated backup scripts preserve logs and traffic data
- **Improved Caching** - Optimized performance for large log files
- **Rate Limiting** - Built-in protection against abuse
- **Traffic Report Enhancements** - Weekly time ranges, efficiency metrics
- **Server-Side Message Storage** - Save messages to server filesystem

---

## System Requirements

### Server Requirements

- **Web Server:** Apache 2.4+ or Nginx 1.18+
- **PHP:** 7.4 or higher (8.x recommended) with extensions:
  - `php-sockets` (required for BBS)
  - `php-curl` (recommended)
- **Operating System:** Linux (recommended) or Windows
- **Memory:** 512MB minimum, 1GB recommended
- **Storage:** 100MB for dashboard + space for logs and archives

### BPQ Node Requirements

- BPQ32 or LinBPQ running with:
  - Telnet port enabled (default: 8010)
  - Log file generation enabled
  - SYSOP account configured

### Client Requirements

- Modern web browser (Chrome 90+, Firefox 88+, Safari 14+, Edge 90+)
- JavaScript enabled
- Network access to web server

---

## Quick Start

### Linux (5 minutes)

```bash
# 1. Extract dashboard files
unzip BPQ-Dashboard-v1_4_0.zip
cd BPQ-Dashboard

# 2. Run automated installer
chmod +x deploy-linux.sh
sudo ./deploy-linux.sh --auto

# 3. Configure (REQUIRED)
sudo cp /var/www/html/bpq/config.php.example /var/www/html/bpq/config.php
sudo nano /var/www/html/bpq/config.php
# Edit: callsign, coordinates, BBS password

# 4. Set permissions
sudo chown -R www-data:www-data /var/www/html/bpq/
sudo chmod -R 755 /var/www/html/bpq/data/

# 5. Access dashboard
# Open browser to: http://your-server/bpq/
```

### Windows (5 minutes)

```batch
REM 1. Extract BPQ-Dashboard folder to your web root
REM    Example: C:\UniServerZ\www\bpq\

REM 2. Create required directories
mkdir logs
mkdir data
mkdir data\messages
mkdir wx
mkdir archives

REM 3. Copy and edit configuration (REQUIRED)
copy config.php.example config.php
notepad config.php
REM Edit: callsign, coordinates, BBS password

REM 4. Access dashboard
REM    Open browser to: http://localhost/bpq/
```

---

## Linux Installation

### Step 1: Install Prerequisites

**Debian/Ubuntu:**
```bash
sudo apt update
sudo apt install apache2 php php-sockets php-curl libapache2-mod-php
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

**CentOS/RHEL:**
```bash
sudo yum install httpd php php-sockets php-curl
sudo systemctl enable httpd
sudo systemctl start httpd
```

### Step 2: Create Dashboard Directory

```bash
# Create directory
sudo mkdir -p /var/www/html/bpq

# Extract files
cd /path/to/extracted/BPQ-Dashboard
sudo cp -r * /var/www/html/bpq/

# Create required subdirectories
sudo mkdir -p /var/www/html/bpq/logs
sudo mkdir -p /var/www/html/bpq/data/messages
sudo mkdir -p /var/www/html/bpq/wx
sudo mkdir -p /var/www/html/bpq/archives/{current,weekly,monthly}
```

### Step 3: Set Permissions

```bash
# Set ownership to web server user
sudo chown -R www-data:www-data /var/www/html/bpq/

# Set directory permissions
sudo find /var/www/html/bpq/ -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /var/www/html/bpq/ -type f -exec chmod 644 {} \;

# Make data and archives writable
sudo chmod -R 755 /var/www/html/bpq/data/
sudo chmod -R 755 /var/www/html/bpq/archives/

# Secure config file
sudo chmod 640 /var/www/html/bpq/config.php
```

### Step 4: Configure Log File Access

Create symlinks to BPQ log files:

```bash
# Find your BPQ log directory
# LinBPQ: usually /opt/oarc/bpq/ or ~/linbpq/

# Create symlinks for log files
sudo ln -sf /path/to/bpq/logs/log_*.txt /var/www/html/bpq/logs/
sudo ln -sf /path/to/bpq/logs/CMSAccess*.txt /var/www/html/bpq/logs/
sudo ln -sf /path/to/bpq/Traffic_*.txt /var/www/html/bpq/logs/

# Verify symlinks work
ls -la /var/www/html/bpq/logs/
```

### Step 5: Create Configuration

```bash
cd /var/www/html/bpq
sudo cp config.php.example config.php
sudo nano config.php
```

**IMPORTANT:** You MUST edit `config.php`:
- Change `callsign` from `N0CALL` to your callsign
- Change BBS `pass` from `CHANGEME` to your BBS password
- Set your `latitude`, `longitude`, and `grid` square

### Step 6: Test Installation

```bash
# Test configuration API
curl http://localhost/bpq/api/config.php

# Expected response:
# {"success":true,"mode":"local","station":{"callsign":"YOURCALL",...}}

# If you see "password not configured" error, edit config.php
```

---

## Windows Installation

### Step 1: Install Web Server

**Option A: Uniform Server (Recommended)**
1. Download from https://www.uniformserver.com/
2. Extract to `C:\UniServerZ\`
3. Run `UniController.exe`
4. Start Apache

**Option B: XAMPP**
1. Download from https://www.apachefriends.org/
2. Install to `C:\xampp\`
3. Start Apache from XAMPP Control Panel

**Option C: IIS with PHP**
1. Enable IIS in Windows Features
2. Install PHP via Web Platform Installer
3. Enable PHP sockets extension in `php.ini`

### Step 2: Extract Dashboard Files

```
Extract BPQ-Dashboard folder to:
- Uniform Server: C:\UniServerZ\www\bpq\
- XAMPP: C:\xampp\htdocs\bpq\
- IIS: C:\inetpub\wwwroot\bpq\
```

### Step 3: Create Directory Structure

```batch
cd C:\UniServerZ\www\bpq
mkdir logs
mkdir data
mkdir data\messages
mkdir wx
mkdir archives
mkdir archives\current
mkdir archives\weekly
mkdir archives\monthly
```

### Step 4: Create Configuration

```batch
copy config.php.example config.php
notepad config.php
```

**IMPORTANT:** You MUST edit `config.php`:
- Change `callsign` from `N0CALL` to your callsign
- Change BBS `pass` from `CHANGEME` to your BBS password
- Set your `latitude`, `longitude`, and `grid` square

### Step 5: Configure Log Access

**Option A: Symbolic Links (Recommended, requires Admin)**

Open Command Prompt as Administrator:
```batch
mklink /D "C:\UniServerZ\www\bpq\logs" "C:\BPQ32\LogFiles"
```

**Option B: Copy Script**

Create `sync-logs.bat`:
```batch
@echo off
copy "C:\BPQ32\LogFiles\log_*.txt" "C:\UniServerZ\www\bpq\logs\" /Y
copy "C:\BPQ32\LogFiles\CMSAccess*.txt" "C:\UniServerZ\www\bpq\logs\" /Y
copy "C:\BPQ32\LogFiles\Traffic_*.txt" "C:\UniServerZ\www\bpq\logs\" /Y
```

Create a scheduled task to run every 5 minutes:
```batch
schtasks /create /tn "BPQ Log Sync" /tr "C:\UniServerZ\www\bpq\sync-logs.bat" /sc minute /mo 5
```

### Step 6: Enable PHP Sockets (if needed)

Edit `php.ini` and uncomment:
```ini
extension=sockets
extension=curl
```

Restart Apache after changes.

### Step 7: Test Installation

Open browser to: `http://localhost/bpq/api/config.php`

Should show JSON with your configuration.

---

## Configuration

### Main Configuration File (`config.php`)

The v1.5.6 unified configuration replaces the old `bbs-config.php` and `nws-config.php` files.

```php
<?php
return [
    // Security mode: 'local' or 'public'
    'security_mode' => 'local',
    
    // Your station information
    'station' => [
        'callsign'  => 'W1AW',          // Your callsign
        'latitude'  => 41.7147,          // Your latitude
        'longitude' => -72.7272,         // Your longitude
        'grid'      => 'FN31pr',         // Your grid square
    ],
    
    // BBS connection settings
    'bbs' => [
        'host'    => 'localhost',        // BPQ telnet host
        'port'    => 8010,               // BPQ telnet port
        'user'    => 'SYSOP',            // BBS username
        'pass'    => 'YourPassword',     // YOUR BBS PASSWORD - CHANGE THIS!
        'alias'   => 'bbs',              // Command to enter BBS
        'timeout' => 30,                 // Connection timeout
    ],
    
    // File paths (relative to dashboard directory)
    'paths' => [
        'logs'    => './logs/',
        'data'    => './data/',
        'wx'      => './wx/',
        'scripts' => './scripts/',
    ],
    
    // Feature toggles
    'features' => [
        'bbs_read'       => true,
        'bbs_write'      => true,
        'bbs_bulletins'  => true,
        'nws_alerts'     => true,
        'nws_post'       => true,
        'rf_connections' => true,
        'system_logs'    => true,
        'traffic_stats'  => true,
        'email_monitor'  => true,
    ],
    
    // UI preferences
    'ui' => [
        'default_msg_count' => 20,
        'max_msg_count'     => 100,
        'refresh_interval'  => 60000,
    ],
];
```

### Finding Your BBS Alias

The `alias` is the command to connect from the node to the BBS:

1. Telnet to your BPQ node: `telnet localhost 8010`
2. Login with your credentials
3. Type `PORTS` or `APPLICATIONS` to list available services
4. Look for BBS entry (e.g., `BBS`, `MYBBS`, `b`)

### Migrating from v1.2.x

If upgrading from v1.2.x with `bbs-config.php`:

```bash
# Your old settings in bbs-config.php:
$config['bbs_host'] = 'localhost';
$config['bbs_port'] = 8010;
$config['bbs_user'] = 'MYCALL';
$config['bbs_pass'] = 'mypassword';

# Become in config.php:
'bbs' => [
    'host' => 'localhost',
    'port' => 8010,
    'user' => 'MYCALL',
    'pass' => 'mypassword',
    'alias' => 'bbs',
],
```

---

## Security Modes

v1.5.6 introduces two security modes:

### Local Mode (Default)

For home networks and trusted environments:
- All features enabled
- No rate limiting
- CORS allows all origins
- Write operations (send/delete) enabled

### Public Mode

For internet-facing deployments:
- Read-only (no send/delete)
- Rate limiting enforced (30 requests/minute)
- CORS restricted to whitelist
- BBS posting disabled
- Test endpoint disabled

To enable public mode:

```php
'security_mode' => 'public',

'cors' => [
    'allow_all' => false,
    'allowed_origins' => [
        'https://yourdomain.com',
    ],
],
```

See [PUBLIC-DEPLOYMENT.md](PUBLIC-DEPLOYMENT.md) for complete internet deployment guide.

---

## Server-Side Message Storage

Save BBS messages to the server filesystem instead of browser localStorage.

### Benefits

- **Cross-device access** - Messages available from any device
- **Persistent storage** - Survives browser data clearing
- **Larger capacity** - Up to 1000 messages, 10MB total

### Setup

1. **Ensure data directory is writable:**

   **Linux:**
   ```bash
   sudo chown -R www-data:www-data /var/www/html/bpq/data/
   sudo chmod -R 755 /var/www/html/bpq/data/
   ```

   **Windows:**
   - Right-click `data` folder → Properties → Security
   - Add write permissions for `IUSR` or web server user

2. **Verify storage API:**
   ```
   http://your-server/bpq/message-storage.php?action=stats
   ```
   
   Should return:
   ```json
   {"success":true,"stats":{"messages":0,"folders":0,...}}
   ```

3. **Switch storage mode in BBS Messages:**
   - Click the storage indicator (💻 Browser) next to Folders button
   - Choose to migrate existing local messages to server
   - Indicator changes to (🖥️ Server) when using server storage

### Storage Files

```
data/messages/
├── .htaccess         # Blocks direct web access
├── messages.json     # Saved messages
├── folders.json      # Custom folders
└── addresses.json    # Saved bulletin addresses
```

---

## Dashboard Setup

### Log File Naming Conventions

BPQ generates logs with these patterns:

| Log Type | Pattern | Example |
|----------|---------|---------|
| BBS | `log_YYMMDD_BBS.txt` | `log_260119_BBS.txt` |
| VARA | `log_YYMMDD_VARA.txt` | `log_260119_VARA.txt` |
| TCP | `log_YYMMDD_TCP.txt` | `log_260119_TCP.txt` |
| Traffic | `Traffic_YYMMDD.txt` | `Traffic_260119.txt` |
| CMSAccess | `CMSAccessYYYYMMDD.txt` | `CMSAccess20260119.txt` |

### Dashboard Features Summary

| Dashboard | Key Features |
|-----------|-------------|
| **RF Connections** | Connection stats, VARA channel quality analysis (modulation tiers, trends, QSB detection), 3-day K-index, 30-day A-index, propagation-aware best band recommendations (6×4hr), propagation report with RF vs infra failure separation, station map with Export/Import, 7-day history modal |
| **System Logs** | Live log viewer, time range filtering (1D/7D/30D), hourly activity chart (UTC), MHeard stations, routing table |
| **Traffic Report** | Calendar-based time ranges (7D/4W/12W), efficiency metrics, BBS Partners filter |
| **Message Monitor** | Dual-mode: BBS Mode (message forwarding, bulletins, partners) OR SMTP/POP Server Mode (POP3/SMTP/NNTP sessions, users, security), time ranges (7D/4W/30D), click bulletins to read messages |
| **BBS Messages** | Read/compose/delete, folders, server storage, up to 100 messages |
| **NWS Weather** | Real-time alerts, region filtering, BBS posting |
| **RF Power Monitor** | 4-channel power monitoring, frequency correlation, success/failed indicators (requires WaveNode) |

All pages include live UTC and Local digital clocks in the navigation bar.

---

## Data Archival

v1.5.6 includes automated archival scripts for backing up logs and traffic data.

### Linux

```bash
# Run manually
/var/www/html/bpq/scripts/archive-bpq-data.sh

# Or add to cron (weekly on Sunday at 2 AM)
crontab -e
0 2 * * 0 /var/www/html/bpq/scripts/archive-bpq-data.sh
```

### Windows

```batch
REM Run manually
C:\UniServerZ\www\bpq\scripts\archive-bpq-data.bat

REM Or add to Task Scheduler
schtasks /create /tn "BPQ Archive" /tr "C:\UniServerZ\www\bpq\scripts\archive-bpq-data.bat" /sc weekly /d SUN /st 02:00
```

See [DATA-ARCHIVAL.md](DATA-ARCHIVAL.md) for detailed documentation.

---

## Background Services

### Linux: VARA Logger Service

```bash
# Install service
sudo cp vara-logger.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable vara-logger
sudo systemctl start vara-logger

# Check status
sudo systemctl status vara-logger
```

### Linux: NWS Monitor Service

```bash
# Install service
sudo cp nws-monitor.service /etc/systemd/system/
sudo systemctl enable nws-monitor
sudo systemctl start nws-monitor
```

### Windows: Scheduled Tasks

```batch
REM Log sync (every 5 minutes)
schtasks /create /tn "BPQ Log Sync" /tr "C:\path\to\sync-logs.bat" /sc minute /mo 5

REM Data archival (weekly)
schtasks /create /tn "BPQ Archive" /tr "C:\path\to\archive-bpq-data.bat" /sc weekly /d SUN /st 02:00
```

---

## Troubleshooting

### "Configuration file not found"

```bash
# Copy the example config
sudo cp config.php.example config.php
sudo nano config.php
# Edit your settings
```

### "BBS password not configured"

Edit `config.php` and change `'pass' => 'CHANGEME'` to your actual BBS password.

### "Cannot connect to BBS"

1. Test telnet manually: `telnet localhost 8010`
2. Verify port in `config.php` matches BPQ configuration
3. Check BPQ is running
4. Verify username/password

### "No log files found"

1. Check log directory exists: `ls -la /var/www/html/bpq/logs/`
2. Verify symlinks are valid: `file /var/www/html/bpq/logs/*`
3. Check web server can read: `sudo -u www-data cat /var/www/html/bpq/logs/log_*.txt`

### "Server storage not available"

1. Check directory permissions:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/bpq/data/
   sudo chmod -R 755 /var/www/html/bpq/data/
   ```
2. Test API: `curl http://localhost/bpq/message-storage.php?action=stats`

### "Rate limit exceeded" (public mode)

This is working as intended. Wait 60 seconds or reduce request frequency.

### Permission Denied Errors

```bash
# Fix all permissions
sudo chown -R www-data:www-data /var/www/html/bpq/
sudo find /var/www/html/bpq/ -type d -exec chmod 755 {} \;
sudo find /var/www/html/bpq/ -type f -exec chmod 644 {} \;
sudo chmod 640 /var/www/html/bpq/config.php
sudo chmod -R 755 /var/www/html/bpq/data/
sudo chmod -R 755 /var/www/html/bpq/archives/
```

---

## Security Considerations

### Local Network Deployment (Default)

Suitable for trusted home networks:
- No additional security needed
- Full feature access
- Acceptable for amateur radio use

### Internet-Facing Deployment

**Required hardening:**

1. **Enable HTTPS:**
   ```bash
   sudo certbot --apache -d bpq.yourdomain.com
   ```

2. **Enable public mode in config.php:**
   ```php
   'security_mode' => 'public',
   ```

3. **Add HTTP authentication:**
   ```bash
   sudo htpasswd -c /etc/apache2/.htpasswd admin
   ```

4. **Firewall rules:**
   ```bash
   sudo ufw allow 443/tcp
   sudo ufw deny 80/tcp
   ```

See [PUBLIC-DEPLOYMENT.md](PUBLIC-DEPLOYMENT.md) for complete guide.

### File Permissions Summary

```bash
# Recommended Linux permissions
sudo chown -R www-data:www-data /var/www/html/bpq/
sudo find /var/www/html/bpq/ -type d -exec chmod 755 {} \;
sudo find /var/www/html/bpq/ -type f -exec chmod 644 {} \;
sudo chmod 640 /var/www/html/bpq/config.php
sudo chmod -R 755 /var/www/html/bpq/data/
sudo chmod -R 755 /var/www/html/bpq/archives/
```

---

## Quick Reference

### File Structure

```
BPQ-Dashboard/
├── config.php                 # Main configuration (create from example)
├── config.php.example         # Configuration template
├── includes/
│   └── bootstrap.php          # Security and config loader
├── api/
│   └── config.php             # Configuration API endpoint
├── shared/
│   └── config.js              # Client-side configuration
├── bpq-rf-connections.html    # RF connections dashboard
├── bpq-system-logs.html       # System logs dashboard
├── bpq-traffic.html           # Traffic report dashboard
├── bpq-email-monitor.html     # Email monitor dashboard
├── bbs-messages.html          # BBS messages dashboard
├── bbs-messages.php           # BBS API backend
├── bpq-connect-log.html       # Connect log dashboard (connection modes)
├── message-storage.php        # Server-side message storage API
├── station-storage.php        # Server-side station location & partner storage
├── nws-dashboard.html         # Weather dashboard
├── nws-bbs-post.php           # Weather BBS posting
├── solar-proxy.php            # Solar data proxy
├── scripts/
│   ├── archive-bpq-data.sh    # Linux archival script
│   └── archive-bpq-data.bat   # Windows archival script
├── data/
│   ├── messages/              # Server-side message storage
│   └── stations/              # Server-side station locations & partners
├── archives/                  # Data archives
│   ├── current/
│   ├── weekly/
│   └── monthly/
├── logs/                      # Log files (symlinks or copies)
└── wx/                        # Weather data cache
```

### Default Ports

| Service | Port |
|---------|------|
| BPQ Telnet | 8010 |
| Web Server | 80/443 |

### Common Paths

| Item | Linux | Windows |
|------|-------|---------|
| Dashboard | `/var/www/html/bpq/` | `C:\UniServerZ\www\bpq\` |
| Config | `/var/www/html/bpq/config.php` | `C:\UniServerZ\www\bpq\config.php` |
| BPQ Logs | `/opt/oarc/bpq/logs/` | `C:\BPQ32\LogFiles\` |

---

## Support

- **BPQ32 Documentation:** https://www.cantab.net/users/john.wiseman/Documents/
- **LinBPQ:** https://www.intermanual.com/bpq32
- **VARA Modem:** https://rosmodem.wordpress.com/

See `CHANGELOG.md` for version history.
