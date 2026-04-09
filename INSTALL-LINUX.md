# BPQ Dashboard — Linux Installation Guide

**Version 1.5.3** | **Last Updated:** March 2026

This guide walks you through installing BPQ Dashboard on a Linux computer (Raspberry Pi, Ubuntu, Debian, etc.) step by step. No prior experience with web servers is required.

> 📌 **Windows user?** See **[INSTALL-WINDOWS.md](INSTALL-WINDOWS.md)** instead.

---

## Table of Contents

1. [What You'll Need](#1-what-youll-need)
2. [Install the Web Server](#2-install-the-web-server)
3. [Install BPQ Dashboard Files](#3-install-bpq-dashboard-files)
4. [Configure the Dashboard](#4-configure-the-dashboard)
5. [Set Up Log File Access](#5-set-up-log-file-access)
6. [Set Up VARA Log Fetching](#6-set-up-vara-log-fetching-optional)
7. [Test Your Installation](#7-test-your-installation)
8. [Optional: Automation Scripts](#8-optional-automation-scripts)
9. [Optional: Connect Watchdog](#9-optional-connect-watchdog)
10. [Optional: Propagation Scheduler](#10-optional-propagation-scheduler)
11. [Optional: Storm Monitor](#11-optional-storm-monitor)
12. [Optional: NWS Weather Alerts](#12-optional-nws-weather-alerts)
13. [Optional: Data Archival](#13-optional-data-archival)
14. [Security Hardening](#14-security-hardening)
15. [Troubleshooting](#15-troubleshooting)
16. [Quick Reference](#16-quick-reference)

---

## 1. What You'll Need

**Hardware / OS:**
- Linux computer — Raspberry Pi 3/4/5, Ubuntu 20.04+, Debian 11+, or any Debian-based distro
- LinBPQ already installed and running
- Terminal access (SSH or direct)
- Internet connection for downloading packages

**Time:** About 30–45 minutes for a standard install.

**Gather this information before you start:**

| Item | Where to find it | Example |
|------|-----------------|---------|
| Your callsign | Your FCC/Ofcom licence | `YOURCALL` |
| Grid square | qrz.com or online calculator | `EM73kj` |
| Latitude / Longitude | Google Maps — right-click your location | `33.4735, -82.0105` |
| LinBPQ telnet port | LinBPQ config → TELNET section | `8010` |
| BBS sysop username | LinBPQ config | `YOURCALL` |
| BBS password | LinBPQ config | `mypassword` |
| LinBPQ log directory | Where LinBPQ writes `log_*_BBS.txt` | `/home/tony/linbpq/` |
| LinBPQ config file | Path to `linmail.cfg` | `/home/tony/linbpq/linmail.cfg` |

---

## 2. Install the Web Server

BPQ Dashboard requires **Apache** (or nginx) and **PHP 7.4+**.

### Install Apache and PHP

```bash
# Update package list
sudo apt update

# Install Apache, PHP, and required PHP extensions
sudo apt install -y apache2 php php-sockets php-curl libapache2-mod-php

# Start Apache and enable auto-start on boot
sudo systemctl start apache2
sudo systemctl enable apache2
```

### Verify Apache is running

```bash
# Check status
sudo systemctl status apache2

# Find your IP address
hostname -I
```

Open a browser and go to `http://YOUR_IP/` — you should see the Apache default page.

> **Using nginx instead?** See the included `nginx-bpqdash.conf` for a working nginx virtual host configuration. The dashboard works identically under nginx.

---

## 3. Install BPQ Dashboard Files

### Option A: Deployment Script (Recommended)

The included script automates the entire installation:

```bash
# Extract the dashboard zip (adjust filename for your version)
unzip BPQ-Dashboard-v1.5.3.zip
cd BPQ-Dashboard-v1.5.2

# Make the deployment script executable
chmod +x deploy-linux.sh

# Run it (will prompt for your installation directory)
sudo ./deploy-linux.sh
```

The script will:
- Create the installation directory (default: `/var/www/html/bpq/`)
- Copy all files with correct permissions
- Create required subdirectories (`logs/`, `cache/`, `data/`, `data/stations/`, `wx/`)
- Set `www-data` ownership on writable directories
- Copy `config.php.example` to `config.php` for editing

### Option B: Manual Installation

```bash
# Create the installation directory
sudo mkdir -p /var/www/html/bpq
sudo chown $USER:$USER /var/www/html/bpq

# Copy all dashboard files
cp -r BPQ-Dashboard-v1.5.2/* /var/www/html/bpq/

# Create required directories
sudo mkdir -p /var/www/html/bpq/{logs,cache,data/stations,data/messages,wx}

# Set permissions — web server needs write access to these directories
sudo chown -R www-data:www-data /var/www/html/bpq/{cache,data,wx,logs}
sudo chmod -R 755 /var/www/html/bpq/{cache,data,wx}

# Copy configuration template
sudo cp /var/www/html/bpq/config.php.example /var/www/html/bpq/config.php
```

---

## 4. Configure the Dashboard

Edit `config.php` with your station information:

```bash
sudo nano /var/www/html/bpq/config.php
```

### Essential settings to change

```php
// ── Your station ──────────────────────────────────────────────
'station' => [
    'callsign'  => 'YOURCALL',          // ← Your callsign
    'latitude'  => 33.4735,           // ← Your latitude
    'longitude' => -82.0105,          // ← Your longitude
    'grid'      => 'EM73kj',          // ← Your 6-char grid square
],

// ── BBS connection ────────────────────────────────────────────
'bbs' => [
    'host'    => 'localhost',
    'port'    => 8010,                // ← Your LinBPQ telnet port
    'user'    => 'YOURCALL',            // ← Your BBS sysop username
    'pass'    => 'CHANGEME',          // ← ⚠️ Your BBS password
    'alias'   => 'bbs',
    'timeout' => 30,
],

// ── Log file paths ────────────────────────────────────────────
'paths' => [
    'logs'    => './logs/',            // Relative to dashboard root
    'data'    => './data/',
    'wx'      => './wx/',
    'scripts' => './scripts/',
],
```

### Security mode

```php
'security_mode' => 'local',   // 'local' for LAN use, 'public' for internet
```

Use `'public'` if the dashboard will be accessible from the internet — this enforces read-only mode and rate limiting automatically.

### Log file patterns

These default values match standard LinBPQ log filenames:

```php
'logs' => [
    'bbs_pattern'  => 'log_%s_BBS.txt',   // %s = YYMMDD
    'vara_pattern' => 'log_%s_VARA.txt',  // %s = YYMMDD
    'tcp_pattern'  => 'log_%s_TCP.txt',   // %s = YYMMDD
    'cms_pattern'  => 'CMSAccess_%s.log', // %s = YYYYMMDD (Winlink gateway log)
    'vara_file'    => '',                  // Leave blank for auto-detection
    'days_to_load' => 30,
],
```

> **Note on CMSAccess logs:** LinBPQ writes these as `CMSAccess_20260325.log` (YYYYMMDD format). The dashboard reads these to identify incoming Winlink (WL2K) sessions on the RF Connections page.

---

## 5. Set Up Log File Access

The dashboard reads BPQ log files from its `logs/` directory. The easiest method is symbolic links — the dashboard sees the files without copying them.

### Find your LinBPQ log directory

```bash
# Common locations:
ls ~/linbpq/*.txt 2>/dev/null
ls /home/linbpq/*.txt 2>/dev/null
ls /opt/linbpq/*.txt 2>/dev/null
```

### Create symbolic links

```bash
cd /var/www/html/bpq/logs/

# BBS logs (required for all dashboards)
sudo ln -sf /home/tony/linbpq/log_*_BBS.txt .

# TCP logs (required for Email Monitor in SMTP/POP mode)
sudo ln -sf /home/tony/linbpq/log_*_TCP.txt .

# VARA session log (required for RF Connections frequency data)
# The filename matches your callsign — adjust accordingly
sudo ln -sf /home/tony/linbpq/yourcall.vara .

# CMS Access log (required for Winlink/WL2K session display)
sudo ln -sf /home/tony/linbpq/CMSAccess_*.log .
```

> **Alternative — cron-based copy:** If symbolic links don't work (e.g. logs are on a different machine), use the included `sync-bpq-logs.sh` script on a cron schedule instead.

### Verify the web server can read the logs

```bash
sudo -u www-data ls /var/www/html/bpq/logs/
```

If this returns "Permission denied," add `www-data` to the group that owns the LinBPQ directory:

```bash
sudo usermod -aG tony www-data    # Replace 'tony' with your username
sudo systemctl restart apache2
```

---

## 6. Set Up VARA Log Fetching (Optional)

If your VARA modem runs on a **separate Windows machine** from your web server, use the included fetch script to copy the VARA log over SSH/SCP.

### On the Linux web server

```bash
# Copy and configure the fetch script
sudo cp /var/www/html/bpq/fetch-vara.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/fetch-vara.sh
sudo nano /usr/local/bin/fetch-vara.sh
```

Edit the variables at the top:

```bash
REMOTE_USER="tony"                      # Windows username
REMOTE_HOST="192.168.1.100"            # Windows machine IP
REMOTE_PATH="/c/Users/tony/AppData/Roaming/VARA HF/VARAHF.txt"
LOCAL_PATH="/var/www/html/bpq/logs/yourcall.vara"
```

### Schedule with cron (every 5 minutes)

```bash
sudo crontab -e
# Add:
*/5 * * * * /usr/local/bin/fetch-vara.sh >> /var/log/vara-fetch.log 2>&1
```

---

## 7. Test Your Installation

### Built-in health check

Open in your browser:
```
http://YOUR_IP/bpq/health-check.php
```

This diagnostic page tests PHP, directory permissions, config file, all API endpoints, log file access, and BBS connectivity — and tells you exactly what to fix.

### Manual tests

```bash
# Test the main API
curl -s "http://localhost/bpq/health-check.php" | grep -oP '(✅|❌|⚠️)[^<]+'

# Test BBS connectivity
curl -s "http://localhost/bpq/bbs-messages.php?action=test"

# Check PHP is working
php -r "echo 'PHP OK: ' . PHP_VERSION . PHP_EOL;"
```

### Open the dashboard pages

| Page | URL |
|------|-----|
| RF Connections | `http://YOUR_IP/bpq/bpq-rf-connections.html` |
| System Logs | `http://YOUR_IP/bpq/bpq-system-logs.html` |
| Traffic Stats | `http://YOUR_IP/bpq/bpq-traffic.html` |
| Message Monitor | `http://YOUR_IP/bpq/bpq-email-monitor.html` |
| BBS Messages | `http://YOUR_IP/bpq/bbs-messages.html` |
| Connect Log | `http://YOUR_IP/bpq/bpq-connect-log.html` |
| NWS Weather | `http://YOUR_IP/bpq/nws-dashboard.html` |
| Maintenance | `http://YOUR_IP/bpq/bpq-maintenance.html` |
| Health Check | `http://YOUR_IP/bpq/health-check.php` |

---

## 8. Optional: Automation Scripts

### `partners.json` — Shared partner configuration

All three automation scripts (`connect-watchdog.py`, `prop-scheduler.py`, `storm-monitor.py`) share a single `partners.json` file that defines your forwarding partners and their distances:

```bash
sudo cp /var/www/html/bpq/data/partners.json /var/www/html/bpq/data/partners.json.example
sudo nano /var/www/html/bpq/data/partners.json
```

```json
[
  {
    "call": "PARTNER3",
    "connect_call": "PARTNER3-7",
    "distance_mi": 87,
    "active": true,
    "suspend_kp": 7.0,
    "attach_port": 3
  },
  {
    "call": "PARTNER1",
    "connect_call": "PARTNER1-2",
    "distance_mi": 571,
    "active": true,
    "suspend_kp": 6.0,
    "attach_port": 3
  }
]
```

**Fields:**
- `call` — Partner's base callsign (must match the block name in `linmail.cfg`)
- `connect_call` — The SSID used in the ConnectScript (e.g. `PARTNER3-7`)
- `distance_mi` — Distance from your station in miles (used by storm-monitor for tiered suspension)
- `active` — Set `false` to skip this partner in all scripts
- `suspend_kp` — Kp threshold above which storm-monitor suspends this partner
- `attach_port` — BPQ AGNODE attach port number

---

## 9. Optional: Connect Watchdog

Monitors BBS logs for repeated failed outgoing connection attempts. When a partner fails 3 times in a rolling 180-minute window, sets `Enabled=0` in `linmail.cfg` and restarts BPQ. Automatically restores after 4 hours. Sends BBS personal message notifications on both suspend and restore.

### Requirements

- Python 3.8+
- Root access (needed to write `linmail.cfg` and restart BPQ via `systemctl`)
- BPQ managed by systemd (`systemctl start/stop bpq`)

### Install

```bash
# Copy script
sudo cp /var/www/html/bpq/scripts/connect-watchdog.py /var/www/html/bpq/scripts/

# Set permissions — must run as root for linmail.cfg write + systemctl
sudo chown root:www-data /var/www/html/bpq/scripts/connect-watchdog.py
sudo chmod 750 /var/www/html/bpq/scripts/connect-watchdog.py
```

### Configure

Edit the `CONFIG` block at the top of `connect-watchdog.py`:

```python
CONFIG = {
    'linmail_cfg':   '/home/tony/linbpq/linmail.cfg',  # ← Path to your linmail.cfg
    'bbs_log_dir':  '/var/www/html/bpq/logs',          # ← Dashboard logs directory
    'state_file':   '/var/www/html/bpq/cache/watchdog-state.json',
    'log_file':     '/var/www/html/bpq/logs/connect-watchdog.log',

    'fail_threshold':   3,     # Failures before suspending
    'fail_window_mins': 180,   # Rolling window in minutes
    'fail_window_secs': 120,   # Max seconds connect→disconnect to count as failed
    'pause_hours':      4,     # Hours to suspend before auto-restore
    'lookback_mins':    30,    # How far back in the log to scan each run

    'bpq_stop_cmd':  'systemctl stop bpq',
    'bpq_start_cmd': 'systemctl start bpq',
}
```

Also edit `BBS_CONFIG` for BBS message notifications:

```python
BBS_CONFIG = {
    'enabled':   True,
    'host':      'localhost',
    'port':      8010,
    'user':      'YOURCALL',       # ← Your callsign
    'password':  'mypassword',  # ← Your BBS password
    'notify_to': 'YOURCALL',       # ← Who to send notifications to
}
```

And update the `PARTNERS` dict to match your `partners.json`:

```python
PARTNERS = {
    'PARTNER3':  {'connect_call': 'PARTNER3-7',  'attach_port': 3},
    'PARTNER1':  {'connect_call': 'PARTNER1-2',  'attach_port': 3},
    # Add all your partners here
}
```

> **Important:** The key in `PARTNERS` (e.g. `'PARTNER3'`) must exactly match the block name in `linmail.cfg`. The `connect_call` value is only used for log scanning — `linmail.cfg` block matching always uses the bare callsign key.

### Test

```bash
# Check current status of all partners
python3 /var/www/html/bpq/scripts/connect-watchdog.py --status

# Manual pause (for testing)
sudo python3 /var/www/html/bpq/scripts/connect-watchdog.py --pause PARTNER3

# Manual resume
sudo python3 /var/www/html/bpq/scripts/connect-watchdog.py --resume PARTNER3

# Run once manually
sudo python3 /var/www/html/bpq/scripts/connect-watchdog.py
```

### Schedule via root crontab (every 5 minutes)

```bash
sudo crontab -e
# Add this line:
*/5 * * * * /usr/bin/python3 /var/www/html/bpq/scripts/connect-watchdog.py
```

> **Must be in root's crontab** — the script needs root to write `linmail.cfg` and run `systemctl stop/start bpq`.

### Monitor

```bash
# Watch the log in real time
tail -f /var/www/html/bpq/logs/connect-watchdog.log

# View current state
cat /var/www/html/bpq/cache/watchdog-state.json | python3 -m json.tool
```

---

## 10. Optional: Propagation Scheduler

Automatically adjusts BPQ `linmail.cfg` ConnectScript schedules every 48 hours based on current solar flux (SFI), geomagnetic conditions (Kp), season, and historical BBS connection data. Distance-aware band selection (NVIS for near stations, skip for distant ones).

### Requirements

- Python 3.6+
- Internet access for NOAA solar data
- Root access (writes `linmail.cfg` and restarts BPQ)

### Install

```bash
sudo cp /var/www/html/bpq/scripts/prop-scheduler.py /var/www/html/bpq/scripts/
sudo chown root:www-data /var/www/html/bpq/scripts/prop-scheduler.py
sudo chmod 750 /var/www/html/bpq/scripts/prop-scheduler.py
```

### Configure

Edit `CONFIG` at the top of `prop-scheduler.py`:

```python
CONFIG = {
    'linmail_cfg':   '/home/tony/linbpq/linmail.cfg',
    'backup_dir':    '/var/www/html/bpq/data/backups',
    'bbs_host':      'localhost',
    'bbs_port':      8010,
    'bbs_user':      'YOURCALL',
    'bbs_pass':      'mypassword',
    'bpq_stop_cmd':  'systemctl stop bpq',
    'bpq_start_cmd': 'systemctl start bpq',
}
```

The script loads partner list from `data/partners.json` automatically. Ensure your partners are configured there (see [Section 8](#8-optional-automation-scripts)).

### Test (dry run — no changes)

```bash
python3 /var/www/html/bpq/scripts/prop-scheduler.py
```

This prints what changes it would make without applying them.

### Apply changes

```bash
sudo python3 /var/www/html/bpq/scripts/prop-scheduler.py --apply
```

This will:
1. Stop BPQ (allowing it to save its state)
2. Re-read `linmail.cfg` (gets BPQ's saved version)
3. Apply new schedule recommendations
4. Write updated `linmail.cfg`
5. Start BPQ with the new config
6. Send you a report via BBS personal message

### Schedule (every 48 hours at 06:00 UTC)

```bash
sudo crontab -e
# Add:
0 6 */2 * * /usr/bin/python3 /var/www/html/bpq/scripts/prop-scheduler.py --apply >> /var/www/html/bpq/logs/prop-scheduler.log 2>&1
```

---

## 11. Optional: Storm Monitor

Monitors NOAA Kp index hourly and applies tiered suspension of HF forwarding partners during geomagnetic storms. Partners are suspended based on their distance and the severity of the storm, then automatically restored when conditions improve.

**Storm tiers:**
| Storm Level | Kp | Action |
|------------|-----|--------|
| G1 (Minor) | ≥ 5 | Suspend all 80m-only partners |
| G2 (Moderate) | ≥ 6 | Suspend partners ≥ 500 miles |
| G3 (Strong) | ≥ 7 | Suspend partners ≥ 300 miles |
| G4 (Severe) | ≥ 8 | Suspend all partners > 200 miles |

### Install

```bash
sudo cp /var/www/html/bpq/scripts/storm-monitor.py /var/www/html/bpq/scripts/
sudo chown root:www-data /var/www/html/bpq/scripts/storm-monitor.py
sudo chmod 750 /var/www/html/bpq/scripts/storm-monitor.py
```

### Configure

Edit `CONFIG` at the top of `storm-monitor.py` with the same credentials as `prop-scheduler.py`. Partners and distances are loaded from `data/partners.json` automatically.

### Schedule (every hour)

```bash
sudo crontab -e
# Add:
0 * * * * /usr/bin/python3 /var/www/html/bpq/scripts/storm-monitor.py >> /var/www/html/bpq/logs/storm-monitor.log 2>&1
```

### Commands

```bash
# Check current status and Kp
python3 /var/www/html/bpq/scripts/storm-monitor.py --status

# Force restore all partners (after a storm passes)
sudo python3 /var/www/html/bpq/scripts/storm-monitor.py --restore
```

---

## 12. Optional: NWS Weather Alerts

Polls the National Weather Service API for active alerts in your region and displays them on the NWS Dashboard. Can also post alerts directly to your BBS as bulletins.

### Configure

In `config.php`:

```php
'nws' => [
    'default_regions'  => ['SR'],        // SR=Southern, ER=Eastern, CR=Central, WR=Western
    'default_types'    => ['tornado', 'severe', 'winter'],
    'auto_refresh'     => true,
    'refresh_interval' => 60000,         // Milliseconds
    'post_destination' => 'WX@ALLUS',   // BBS bulletin destination
],
```

### NWS Monitor Service (optional — for automatic BBS posting)

```bash
# Copy the service file
sudo cp /var/www/html/bpq/nws-monitor.service /etc/systemd/system/

# Edit paths inside the service file if needed
sudo nano /etc/systemd/system/nws-monitor.service

# Enable and start
sudo systemctl daemon-reload
sudo systemctl enable nws-monitor
sudo systemctl start nws-monitor
```

---

## 13. Optional: Data Archival

Automatically archives BPQ log files to a compressed archive on a schedule, preventing disk space issues and preserving historical data.

```bash
# Copy and make executable
sudo cp /var/www/html/bpq/scripts/archive-bpq-data.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/archive-bpq-data.sh

# Edit paths at top of script
sudo nano /usr/local/bin/archive-bpq-data.sh

# Schedule monthly archival (1st of each month at 2 AM)
sudo crontab -e
# Add:
0 2 1 * * /usr/local/bin/archive-bpq-data.sh >> /var/log/bpq-archive.log 2>&1
```

---

## 14. Security Hardening

### Restrict admin pages to LAN only (nginx)

Copy the included `nginx-maintenance-block.conf` into your nginx server block to restrict `bpq-maintenance.html` and `bpq-rf-connections.html` (Admin section) to LAN addresses only:

```nginx
# In your nginx server block:
location ~* (bpq-maintenance|bpq-admin)\.html$ {
    allow 10.0.0.0/8;
    allow 192.168.0.0/16;
    allow 127.0.0.1;
    deny all;
}
```

### Protect the data directory (Apache)

Add to `/var/www/html/bpq/data/.htaccess`:

```apache
Deny from all
```

### Fail2ban for nginx (if internet-facing)

```bash
sudo apt install fail2ban
# The included nginx-maintenance-block.conf includes recommended fail2ban rules
```

### Change default passwords

**Do not skip this.** After installation:

1. Change your Linux user password: `passwd`
2. Change your BBS password in LinBPQ config
3. Update `config.php` with the new BBS password
4. Set a BBS web interface password on the BBS Messages page (first visit prompts setup)

---

## 15. Troubleshooting

### Run the health check first

```
http://YOUR_IP/bpq/health-check.php
```

This tests everything automatically. Fix what it flags before digging deeper.

### "Configuration file not found"

```bash
sudo cp /var/www/html/bpq/config.php.example /var/www/html/bpq/config.php
sudo nano /var/www/html/bpq/config.php
```

### Dashboard shows "No data" or blank charts

1. Check log files exist in the `logs/` directory:
   ```bash
   ls -la /var/www/html/bpq/logs/
   ```
2. Check the web server can read them:
   ```bash
   sudo -u www-data cat /var/www/html/bpq/logs/log_$(date +%y%m%d)_BBS.txt | head -5
   ```
3. Open browser developer tools (F12) → Console tab for JavaScript errors

### BBS Messages page won't log in

1. Verify BPQ telnet is accessible:
   ```bash
   telnet localhost 8010
   ```
2. Check credentials in `config.php` match your LinBPQ configuration
3. Check the PHP error log:
   ```bash
   sudo tail -20 /var/log/apache2/error.log
   ```

### Connect watchdog not firing

```bash
# Check the watchdog log
tail -50 /var/www/html/bpq/logs/connect-watchdog.log

# Run a manual test
sudo python3 /var/www/html/bpq/scripts/connect-watchdog.py --status

# Verify it's in root's crontab (not tony's crontab)
sudo crontab -l | grep watchdog
```

### "linmail.cfg not found" in prop-scheduler or watchdog

Check the `linmail_cfg` path in the script's `CONFIG` block. The most common mistake is the path not matching where LinBPQ actually stores the file:

```bash
find /home /opt /var -name "linmail.cfg" 2>/dev/null
```

### Web server can't write to cache or data directories

```bash
sudo chown -R www-data:www-data /var/www/html/bpq/cache
sudo chown -R www-data:www-data /var/www/html/bpq/data
sudo chmod -R 755 /var/www/html/bpq/cache
sudo chmod -R 755 /var/www/html/bpq/data
```

### VARA log not loading (RF Connections shows no frequency data)

The RF Connections page reads `yourcall.vara` (your callsign + `.vara`) from the `logs/` directory. The VARA log contains two date formats depending on LinBPQ version — both are handled automatically:

- Old format: `251226 00:06:02 <NP4JN VARA} Failure...`
- New format: `Mar 25 17:44:39 H-YOURCALL-7 VARAHF WB2HJQ Average S/N...`

Check the symlink exists:
```bash
ls -la /var/www/html/bpq/logs/*.vara
```

---

## 16. Quick Reference

### File locations

| File | Location |
|------|----------|
| Dashboard root | `/var/www/html/bpq/` |
| Configuration | `/var/www/html/bpq/config.php` |
| Log files | `/var/www/html/bpq/logs/` |
| Partners config | `/var/www/html/bpq/data/partners.json` |
| Watchdog state | `/var/www/html/bpq/cache/watchdog-state.json` |
| Watchdog log | `/var/www/html/bpq/logs/connect-watchdog.log` |
| Prop-scheduler log | `/var/www/html/bpq/logs/prop-scheduler.log` |
| Storm-monitor log | `/var/www/html/bpq/logs/storm-monitor.log` |

### Root crontab summary (all optional scripts)

```cron
# Connect Watchdog — every 5 minutes
*/5 * * * * /usr/bin/python3 /var/www/html/bpq/scripts/connect-watchdog.py

# Storm Monitor — every hour
0 * * * * /usr/bin/python3 /var/www/html/bpq/scripts/storm-monitor.py >> /var/www/html/bpq/logs/storm-monitor.log 2>&1

# Propagation Scheduler — every 48 hours at 06:00 UTC
0 6 */2 * * /usr/bin/python3 /var/www/html/bpq/scripts/prop-scheduler.py --apply >> /var/www/html/bpq/logs/prop-scheduler.log 2>&1

# VARA log fetch from remote Windows machine — every 5 minutes
*/5 * * * * /usr/local/bin/fetch-vara.sh >> /var/log/vara-fetch.log 2>&1

# Data archival — monthly
0 2 1 * * /usr/local/bin/archive-bpq-data.sh >> /var/log/bpq-archive.log 2>&1
```

### Useful commands

```bash
# Check all script statuses
sudo python3 /var/www/html/bpq/scripts/connect-watchdog.py --status
python3 /var/www/html/bpq/scripts/storm-monitor.py --status

# Watch logs in real time
tail -f /var/www/html/bpq/logs/connect-watchdog.log
tail -f /var/www/html/bpq/logs/storm-monitor.log

# Test the dashboard API
curl -s http://localhost/bpq/health-check.php | head -50

# Restart Apache after config changes
sudo systemctl restart apache2
```

---

*BPQ Dashboard v1.5.3 — YOURCALL | BPQ Network | [https://www.bpqdash.net](https://www.bpqdash.net)*
