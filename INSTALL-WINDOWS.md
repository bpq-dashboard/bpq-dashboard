# BPQ Dashboard — Windows Installation Guide

**Version 1.5.3** | **Last Updated:** March 2026

This guide walks you through installing BPQ Dashboard on a Windows 10 or 11 computer step by step. No prior experience with web servers is required.

> 📌 **Linux user?** See **[INSTALL-LINUX.md](INSTALL-LINUX.md)** instead.

---

## Table of Contents

1. [What You'll Need](#1-what-youll-need)
2. [Install a Web Server](#2-install-a-web-server)
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
- Windows 10 or 11 (64-bit)
- BPQ32 already installed and running
- Internet connection for downloading software

**Time:** About 30–45 minutes for a standard install.

**Gather this information before you start:**

| Item | Where to find it | Example |
|------|-----------------|---------|
| Your callsign | Your FCC licence | `YOURCALL` |
| Grid square | qrz.com or online calculator | `EM73kj` |
| Latitude / Longitude | Google Maps — right-click your location | `33.4735, -82.0105` |
| BPQ32 telnet port | BPQ32 config → TELNET section | `8010` |
| BBS sysop username | BPQ32 config | `YOURCALL` |
| BBS password | BPQ32 config | `mypassword` |
| BPQ32 log directory | Where BPQ32 writes `log_*_BBS.txt` | `C:\BPQ32\` |

---

## 2. Install a Web Server

BPQ Dashboard requires Apache and PHP. On Windows we recommend **Uniform Server** — it's free, portable, and requires no installer.

### Download Uniform Server

1. Open your browser and go to: [https://www.uniformserver.com/](https://www.uniformserver.com/)
2. Click **Downloads**
3. Download the latest **UniServerZ** release (about 30–40 MB)

### Extract and Start

1. Move the downloaded file to `C:\`
2. Right-click the file → **Extract All** → extract to `C:\`
   - You should now have `C:\UniServerZ\`
3. Open `C:\UniServerZ\` and double-click **UniController.exe**
4. If Windows Firewall asks, click **Allow access**
5. Click the **Start Apache** button — it turns green when running

### Verify it's working

Open your browser and go to `http://localhost/` — you should see the Uniform Server welcome page.

> **Note:** If port 80 is already in use (common if Skype or another app is running), Uniform Server can run on a different port. See UniController → Settings → Apache → Change port to e.g. `8080`, then access the dashboard at `http://localhost:8080/bpq/`.

---

## 3. Install BPQ Dashboard Files

### Using the Deployment Script (Recommended)

The included batch script automates the installation:

1. Extract the dashboard zip file to a temporary folder (e.g. `C:\temp\BPQ-Dashboard-v1.5.2\`)
2. Right-click `deploy-windows.bat` → **Run as administrator**
3. Follow the prompts — it will ask for your installation directory (default: `C:\UniServerZ\www\bpq\`)

The script creates:
- The installation directory
- Required subdirectories (`logs\`, `cache\`, `data\`, `data\stations\`, `data\messages\`, `wx\`)
- A copy of `config.php.example` → `config.php` ready to edit

### Manual Installation

1. Open `C:\UniServerZ\www\` in Windows Explorer
2. Create a new folder named `bpq`
3. Copy all files from the extracted dashboard zip into `C:\UniServerZ\www\bpq\`
4. Create these subfolders inside `bpq\`:
   - `logs\`
   - `cache\`
   - `data\`
   - `data\stations\`
   - `data\messages\`
   - `wx\`
5. Copy `config.php.example` and rename the copy to `config.php`

---

## 4. Configure the Dashboard

Open `C:\UniServerZ\www\bpq\config.php` in Notepad or any text editor.

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
    'port'    => 8010,                // ← Your BPQ32 telnet port
    'user'    => 'YOURCALL',            // ← Your BBS sysop username
    'pass'    => 'CHANGEME',          // ← ⚠️ Your BBS password
    'alias'   => 'bbs',
    'timeout' => 30,
],

// ── Log file paths ────────────────────────────────────────────
'paths' => [
    'logs'    => './logs/',
    'data'    => './data/',
    'wx'      => './wx/',
    'scripts' => './scripts/',
],
```

### Security mode

```php
'security_mode' => 'local',   // 'local' for home network, 'public' for internet
```

### Log file patterns

These match standard BPQ32 log filenames and should not need changing:

```php
'logs' => [
    'bbs_pattern'  => 'log_%s_BBS.txt',   // %s = YYMMDD
    'vara_pattern' => 'log_%s_VARA.txt',
    'tcp_pattern'  => 'log_%s_TCP.txt',
    'cms_pattern'  => 'CMSAccess_%s.log', // %s = YYYYMMDD
    'vara_file'    => '',                  // Leave blank for auto-detection
    'days_to_load' => 30,
],
```

> **Save the file** when done. Restart Apache in UniController if the page doesn't load.

---

## 5. Set Up Log File Access

The dashboard reads BPQ32 log files from its `logs\` folder. The simplest method on Windows is to copy the logs on a schedule.

### Find your BPQ32 log directory

BPQ32 typically writes logs to one of these locations:
- `C:\BPQ32\`
- `C:\Users\YourName\AppData\Roaming\BPQ32\`
- Wherever BPQ32 is installed

Look for files named `log_YYMMDD_BBS.txt`.

### Option A: Scheduled copy with Task Scheduler (Recommended)

1. Open Notepad and create `C:\BPQ32\sync-logs.bat`:

```batch
@echo off
:: Copy BPQ32 logs to dashboard
xcopy "C:\BPQ32\log_*.txt" "C:\UniServerZ\www\bpq\logs\" /Y /Q
xcopy "C:\BPQ32\CMSAccess_*.log" "C:\UniServerZ\www\bpq\logs\" /Y /Q
xcopy "C:\Users\%USERNAME%\AppData\Roaming\VARA HF\VARAHF.txt" "C:\UniServerZ\www\bpq\logs\yourcall.vara" /Y /Q 2>nul
```

2. Open **Task Scheduler** (search in Start menu)
3. Click **Create Basic Task**
4. Name: `BPQ Log Sync`
5. Trigger: **Daily**, then change to repeat every 5 minutes
6. Action: **Start a program** → browse to `C:\BPQ32\sync-logs.bat`
7. Click Finish

### Option B: Direct path (if BPQ32 is on the same machine)

Edit `config.php` to point directly to the BPQ32 log folder:

```php
'paths' => [
    'logs' => 'C:/BPQ32/',   // Note: forward slashes work on Windows in PHP
    ...
],
```

> **Note on CMSAccess logs:** BPQ32 writes these as `CMSAccess_20260325.log` (YYYYMMDD format). The dashboard reads them to show incoming Winlink (WL2K) sessions on the RF Connections page.

---

## 6. Set Up VARA Log Fetching (Optional)

If VARA HF runs on a **separate machine** from your web server, use the included PowerShell script to copy the VARA log.

### Configure the fetch script

Open `C:\UniServerZ\www\bpq\fetch-vara.bat` in Notepad:

```batch
:: Edit these values:
set VARA_LOG=C:\Users\%USERNAME%\AppData\Roaming\VARA HF\VARAHF.txt
set DEST=C:\UniServerZ\www\bpq\logs\yourcall.vara
```

Or use the PowerShell version (`fetch-vara.ps1`) for more options including remote machine support over SSH.

Schedule with Task Scheduler every 5 minutes (same as above).

---

## 7. Test Your Installation

### Built-in health check

Open in your browser:
```
http://localhost/bpq/health-check.php
```

This tests PHP, directory permissions, configuration, API endpoints, log file access, and BBS connectivity — and tells you exactly what to fix.

### Open the dashboard pages

| Page | URL |
|------|-----|
| RF Connections | `http://localhost/bpq/bpq-rf-connections.html` |
| System Logs | `http://localhost/bpq/bpq-system-logs.html` |
| Traffic Stats | `http://localhost/bpq/bpq-traffic.html` |
| Message Monitor | `http://localhost/bpq/bpq-email-monitor.html` |
| BBS Messages | `http://localhost/bpq/bbs-messages.html` |
| Connect Log | `http://localhost/bpq/bpq-connect-log.html` |
| NWS Weather | `http://localhost/bpq/nws-dashboard.html` |
| Maintenance | `http://localhost/bpq/bpq-maintenance.html` |
| Health Check | `http://localhost/bpq/health-check.php` |

Other computers on your network can access the dashboard at `http://YOUR_PC_IP/bpq/`.

---

## 8. Optional: Automation Scripts

The three automation scripts (`connect-watchdog.py`, `prop-scheduler.py`, `storm-monitor.py`) all share a single `partners.json` file that defines your forwarding partners:

```
C:\UniServerZ\www\bpq\data\partners.json
```

Edit this file to match your station's partners:

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
- `call` — Partner's base callsign (must match block name in `linmail.cfg`)
- `connect_call` — The SSID used in ConnectScript (e.g. `PARTNER3-7`)
- `distance_mi` — Distance from your station in miles
- `active` — Set `false` to skip this partner in all scripts
- `suspend_kp` — Kp threshold for storm-monitor suspension
- `attach_port` — BPQ AGNODE attach port number

### Installing Python

All three scripts require Python 3.8+:

1. Go to [https://www.python.org/downloads/](https://www.python.org/downloads/)
2. Download the latest Python 3.x installer
3. Run the installer — **check "Add Python to PATH"** before clicking Install
4. Verify: open Command Prompt and type `python --version`

---

## 9. Optional: Connect Watchdog

Monitors BBS logs for repeated failed outgoing connection attempts. When a partner fails 3 times in a 180-minute window, sets `Enabled=0` in `linmail.cfg` and restarts BPQ32. Automatically restores after 4 hours. Sends BBS personal message notifications.

### Configure

Open `C:\UniServerZ\www\bpq\scripts\connect-watchdog.py` in a text editor and edit the `CONFIG` block:

```python
CONFIG = {
    'linmail_cfg':  r'C:\BPQ32\linmail.cfg',                        # ← Path to linmail.cfg
    'bbs_log_dir':  r'C:\UniServerZ\www\bpq\logs',                  # ← Dashboard logs dir
    'state_file':   r'C:\UniServerZ\www\bpq\cache\watchdog-state.json',
    'log_file':     r'C:\UniServerZ\www\bpq\logs\connect-watchdog.log',

    'fail_threshold':   3,     # Failures before suspending
    'fail_window_mins': 180,   # Rolling window in minutes
    'fail_window_secs': 120,   # Max connect→disconnect seconds to count as failed
    'pause_hours':      4,     # Hours to suspend before auto-restore

    'bpq_stop_cmd':  'net stop BPQ32',
    'bpq_start_cmd': 'net start BPQ32',
}
```

Also edit `BBS_CONFIG`:

```python
BBS_CONFIG = {
    'enabled':   True,
    'host':      'localhost',
    'port':      8010,
    'user':      'YOURCALL',       # ← Your callsign
    'password':  'mypassword',  # ← Your BBS password
    'notify_to': 'YOURCALL',       # ← Who to notify
}
```

And `PARTNERS` (must match `partners.json`):

```python
PARTNERS = {
    'PARTNER3':  {'connect_call': 'PARTNER3-7',  'attach_port': 3},
    'PARTNER1':  {'connect_call': 'PARTNER1-2',  'attach_port': 3},
}
```

> **Important:** Keys in `PARTNERS` must exactly match the block name in `linmail.cfg` (e.g. `PARTNER3`, not `PARTNER3-7`).

### Test

Open Command Prompt as Administrator:

```cmd
:: Check status
python C:\UniServerZ\www\bpq\scripts\connect-watchdog.py --status

:: Manual pause for testing
python C:\UniServerZ\www\bpq\scripts\connect-watchdog.py --pause PARTNER3

:: Manual resume
python C:\UniServerZ\www\bpq\scripts\connect-watchdog.py --resume PARTNER3
```

### Schedule with Task Scheduler (every 5 minutes)

1. Open **Task Scheduler** → **Create Basic Task**
2. Name: `BPQ Connect Watchdog`
3. Trigger: **Daily** → change to repeat every **5 minutes** for a duration of **1 day**
4. Action: **Start a program**
   - Program: `python`
   - Arguments: `C:\UniServerZ\www\bpq\scripts\connect-watchdog.py`
5. Under **General** tab: check **Run with highest privileges**
6. Click Finish

> **Must run with highest privileges** — the script needs to write `linmail.cfg` and restart BPQ32 via `net stop`/`net start`.

---

## 10. Optional: Propagation Scheduler

Automatically adjusts BPQ32 `linmail.cfg` ConnectScript schedules every 48 hours based on solar flux (SFI), geomagnetic conditions (Kp), season, and historical BBS connection data.

### Configure

Open `C:\UniServerZ\www\bpq\scripts\prop-scheduler.py` and edit `CONFIG`:

```python
CONFIG = {
    'linmail_cfg':   r'C:\BPQ32\linmail.cfg',
    'backup_dir':    r'C:\UniServerZ\www\bpq\data\backups',
    'bbs_host':      'localhost',
    'bbs_port':      8010,
    'bbs_user':      'YOURCALL',
    'bbs_pass':      'mypassword',
    'bpq_stop_cmd':  'net stop BPQ32',
    'bpq_start_cmd': 'net start BPQ32',
}
```

### Test (dry run — no changes)

```cmd
python C:\UniServerZ\www\bpq\scripts\prop-scheduler.py
```

### Apply changes

```cmd
:: Run as Administrator
python C:\UniServerZ\www\bpq\scripts\prop-scheduler.py --apply
```

### Schedule with Task Scheduler (every 48 hours)

1. Open **Task Scheduler** → **Create Basic Task**
2. Name: `BPQ Propagation Scheduler`
3. Trigger: **Daily** at `06:00 AM`, repeat every **2 days**
4. Action: Start a program → `python` with argument `C:\UniServerZ\www\bpq\scripts\prop-scheduler.py --apply`
5. Check **Run with highest privileges**

---

## 11. Optional: Storm Monitor

Monitors NOAA Kp index and suspends HF forwarding partners during geomagnetic storms based on distance-tiered thresholds.

**Storm tiers:**
| Storm Level | Kp | Action |
|------------|-----|--------|
| G1 (Minor) | ≥ 5 | Suspend 80m-only partners |
| G2 (Moderate) | ≥ 6 | Suspend partners ≥ 500 miles |
| G3 (Strong) | ≥ 7 | Suspend partners ≥ 300 miles |
| G4 (Severe) | ≥ 8 | Suspend all partners > 200 miles |

### Configure

Open `C:\UniServerZ\www\bpq\scripts\storm-monitor.py` and edit `CONFIG` with the same credentials as `prop-scheduler.py`.

### Schedule with Task Scheduler (every hour)

1. Open **Task Scheduler** → **Create Basic Task**
2. Name: `BPQ Storm Monitor`
3. Trigger: **Daily** → repeat every **1 hour** for **1 day**
4. Action: Start a program → `python` with argument `C:\UniServerZ\www\bpq\scripts\storm-monitor.py`
5. Check **Run with highest privileges**

### Commands

```cmd
:: Check current status
python C:\UniServerZ\www\bpq\scripts\storm-monitor.py --status

:: Force restore all partners
python C:\UniServerZ\www\bpq\scripts\storm-monitor.py --restore
```

---

## 12. Optional: NWS Weather Alerts

Polls the National Weather Service API for active alerts. Configure in `config.php`:

```php
'nws' => [
    'default_regions'  => ['SR'],        // SR=Southern, ER=Eastern, CR=Central, WR=Western
    'default_types'    => ['tornado', 'severe', 'winter'],
    'auto_refresh'     => true,
    'refresh_interval' => 60000,
    'post_destination' => 'WX@ALLUS',
],
```

For automatic BBS bulletin posting, schedule `nws-monitor.bat` or `nws-monitor.ps1` via Task Scheduler hourly.

---

## 13. Optional: Data Archival

```cmd
:: Configure paths in the script then run:
C:\UniServerZ\www\bpq\scripts\archive-bpq-data.bat
```

Schedule with Task Scheduler monthly for automated archival.

---

## 14. Security Hardening

### Firewall rules

By default Uniform Server is only accessible from your local machine. To allow other computers on your LAN:

1. Open **Windows Defender Firewall** → **Advanced Settings**
2. **Inbound Rules** → **New Rule**
3. Port → TCP → `80` (or your Apache port)
4. Allow the connection → apply to **Private** networks only

### Password protection

The BBS Messages page has built-in password protection — you'll be prompted to set a password on first access. This password is stored as a SHA-256 hash on the server.

### Change default passwords

After installation:
1. Change your BPQ32 BBS password in BPQ32 configuration
2. Update `config.php` with the new BBS password
3. Never share your `config.php` — it contains your BBS credentials

---

## 15. Troubleshooting

### Run the health check first

```
http://localhost/bpq/health-check.php
```

### Dashboard shows a blank page or PHP errors

1. Check PHP is installed: open `http://localhost/bpq/health-check.php`
2. In UniController, verify Apache is running (green button)
3. Check the Apache error log: `C:\UniServerZ\www\logs\apache_error.log`

### "Configuration file not found"

1. Open `C:\UniServerZ\www\bpq\`
2. Copy `config.php.example` → rename copy to `config.php`
3. Edit `config.php` with your settings

### BBS Messages page won't log in

1. Open Command Prompt and test telnet:
   ```cmd
   telnet localhost 8010
   ```
   You should see a BPQ32 welcome prompt. Type `BYE` to exit.
2. Verify the `bbs.port` and `bbs.pass` in `config.php` are correct

### Dashboard shows "No data"

1. Check that log files were copied to `C:\UniServerZ\www\bpq\logs\`
2. Verify the log filenames match the patterns in `config.php` (default `log_YYMMDD_BBS.txt`)
3. Open browser dev tools (F12) → Console tab for JavaScript errors

### Connect watchdog not running

1. Check Task Scheduler — is the task running? Check the **History** tab
2. Ensure the task runs with **highest privileges**
3. Test manually from Command Prompt **as Administrator**:
   ```cmd
   python C:\UniServerZ\www\bpq\scripts\connect-watchdog.py --status
   ```
4. Check the log:
   ```cmd
   type C:\UniServerZ\www\bpq\logs\connect-watchdog.log
   ```

### Python not found

1. Open Command Prompt and type `python --version`
2. If "not recognized," Python wasn't added to PATH during install
3. Uninstall and reinstall Python — **check "Add Python to PATH"**

---

## 16. Quick Reference

### File locations

| File | Location |
|------|----------|
| Dashboard root | `C:\UniServerZ\www\bpq\` |
| Configuration | `C:\UniServerZ\www\bpq\config.php` |
| Log files | `C:\UniServerZ\www\bpq\logs\` |
| Partners config | `C:\UniServerZ\www\bpq\data\partners.json` |
| Watchdog state | `C:\UniServerZ\www\bpq\cache\watchdog-state.json` |
| Watchdog log | `C:\UniServerZ\www\bpq\logs\connect-watchdog.log` |

### Task Scheduler summary

| Task | Schedule | Script |
|------|----------|--------|
| Log sync | Every 5 min | `sync-logs.bat` |
| VARA log fetch | Every 5 min | `fetch-vara.bat` |
| Connect Watchdog | Every 5 min | `connect-watchdog.py` |
| Storm Monitor | Every hour | `storm-monitor.py` |
| Prop Scheduler | Every 48h at 6 AM | `prop-scheduler.py --apply` |
| Data Archival | Monthly | `archive-bpq-data.bat` |

### Useful commands (run as Administrator)

```cmd
:: Check watchdog status
python C:\UniServerZ\www\bpq\scripts\connect-watchdog.py --status

:: Manual watchdog resume
python C:\UniServerZ\www\bpq\scripts\connect-watchdog.py --resume PARTNER3

:: Check storm monitor status
python C:\UniServerZ\www\bpq\scripts\storm-monitor.py --status

:: Prop scheduler dry run
python C:\UniServerZ\www\bpq\scripts\prop-scheduler.py
```

---

*BPQ Dashboard v1.5.3 — YOURCALL | BPQ Network | [https://www.bpqdash.net](https://www.bpqdash.net)*
