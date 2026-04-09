# NWS Weather Alerts for BPQ BBS

This package provides scripts to automatically fetch National Weather Service (NWS) alerts and post them to your BPQ32 BBS for distribution to the amateur radio community.

## Features

- Fetches real-time weather alerts from the official NWS API
- Supports Tornado Warnings, Severe Thunderstorm Warnings, Flash Flood Warnings, Hurricane Warnings
- **Regional fetching** - Get alerts for entire NWS regions (Southern, Central, Eastern, Western)
- Creates properly formatted BBS messages
- Can auto-post to BBS via telnet or save message files for manual posting
- Tracks processed alerts to avoid duplicates
- Configurable by state, region, and alert type

## v1.5.5 Dashboard Redesign

The NWS Dashboard has been completely rebuilt with the BPQ Dashboard light theme:

- **Visual consistency** - Matches RF Connections, System Logs, and other dashboard pages
- **Light theme** - White glass containers, purple/blue gradient accents
- **Unified nav bar** - Includes UTC/Local clocks like all other pages
- **Mobile responsive** - Alerts appear first on phones/tablets
- **Alert color coding** - Tornado (red), Severe (orange), Flood (cyan), Hurricane (purple), Winter (sky blue)
- **Smaller footprint** - 62% reduction in file size while preserving all functionality

## NWS Regions

| Region | Code | States Covered |
|--------|------|----------------|
| Southern | SR | TX, OK, AR, LA, MS, TN, AL, GA, FL, SC, NC, VA, PR, VI |
| Central | CR | MT, WY, CO, NM, ND, SD, NE, KS, MN, IA, MO, WI, IL, MI, IN |
| Eastern | ER | ME, NH, VT, MA, RI, CT, NY, PA, NJ, DE, MD, DC, WV, OH, KY |
| Western | WR | WA, OR, CA, NV, ID, UT, AZ, AK, HI, GU |
| Alaska | AR | AK |
| Pacific | PR | HI, GU, Pacific Islands |

## Scripts Included

| Script | Platform | Description |
|--------|----------|-------------|
| `nws-dashboard.html` | Web | **Interactive dashboard** - GUI for all NWS functions |
| `nws-monitor.sh` | Linux | **Background service** - Auto-fetches alerts for dashboard |
| `nws-monitor.ps1` | Windows | **Background service** - PowerShell version |
| `nws-monitor.service` | Linux | Systemd service file for auto-start |
| `nws-region-bbs.sh` | Linux | Regional tornado/weather fetcher |
| `nws-region-bbs.ps1` | Windows | Regional PowerShell fetcher |
| `nws-region-bbs.bat` | Windows | Batch wrapper for regional script |
| `nws-tornado-bbs.sh` | Linux | Simple state-based tornado fetcher |
| `nws-tornado-post.sh` | Linux | Auto-posts tornado warnings via telnet |
| `nws-weather-bbs.sh` | Linux | Full-featured multi-alert type script |
| `nws-tornado-bbs.ps1` | Windows | PowerShell state-based tornado fetcher |
| `nws-tornado-bbs.bat` | Windows | Batch wrapper for state-based script |

## NWS Dashboard

The `nws-dashboard.html` provides a web-based GUI that:

- Displays real-time weather alerts with color-coded severity badges
- Allows region selection (ALL, SR, CR, ER, WR, AK, PAC) with multi-select
- Filter by alert type (Tornado, Severe, Flood, Hurricane, Winter)
- Shows full alert details: areas, description, instructions, metadata
- BBS message preview with dark terminal-style formatting
- Post individual alerts or all alerts to BBS (password protected)
- Export all filtered alerts to text file
- Copy messages to clipboard
- **Configurable auto-refresh** with live countdown timer
- Activity log tracks all actions
- Settings persistence (callsign, destination, refresh interval saved to localStorage)

### Dashboard Setup

1. Copy `nws-dashboard.html` to your web server (e.g., `/var/www/html/bpq/`)
2. Create the `wx` subdirectory for alert data
3. Start the background monitor service (see below)
4. Open dashboard in browser: `http://yourserver/bpq/nws-dashboard.html`

### Auto-Refresh Feature

The dashboard can automatically fetch updated alerts from the NWS API at configurable intervals. Use the **Auto-Refresh** dropdown in the Configuration section to select your preferred interval:

| Setting | Interval | Recommended Use |
|---------|----------|-----------------|
| **30 seconds** | 0.5 min | Active severe weather - tornado outbreaks, fast-moving storms |
| **1 minute** | 1 min | Default - good balance for general monitoring |
| **5 minutes** | 5 min | Quiet weather periods - reduces API calls |
| **15 minutes** | 15 min | Background awareness - minimal resource usage |
| **Off** | Manual | Click "Fetch Alerts" button to refresh manually |

**How to verify auto-refresh is working:**

1. **Watch the countdown** - Below the dropdown, you'll see "Next refresh: 45s" counting down
2. **Check the Activity Log** - Each refresh logs "Fetching alerts from NWS API..." with a timestamp
3. **Change intervals** - When you change the dropdown, a log entry confirms "Auto-refresh set to X minutes"

**Tips:**
- Use faster refresh (30s-1min) when severe weather is in your region
- Use slower refresh (5-15min) during quiet periods to reduce NWS API load
- Your preference is saved automatically and persists across browser sessions
- Auto-refresh only fetches when alerts have been loaded at least once (click "Fetch Alerts" first)

> ⚠️ **Important:** Dashboard auto-refresh only works while the browser tab is open. When you close the tab, navigate away, or close your browser, the auto-refresh stops completely. This is normal browser behavior - JavaScript timers cannot run when a page is not loaded.

### Dashboard vs Background Service

For **24/7 unattended monitoring**, use the Background Monitor Service instead of (or in addition to) the dashboard auto-refresh:

| Method | When It Runs | Best For |
|--------|--------------|----------|
| **Dashboard auto-refresh** | Only while browser tab is open | Active monitoring - you're watching for severe weather |
| **Background service** | 24/7 on your server | Unattended monitoring - alerts saved even when nobody is watching |
| **Both together** | Continuous server fetch + browser display | Best coverage - service fetches, dashboard displays |

**Recommended setup for reliable monitoring:**
1. Install the background monitor service (runs 24/7 on your server)
2. Use dashboard auto-refresh when actively monitoring severe weather
3. The dashboard will read cached alerts from the service when you first open it

## Background Monitor Service

The monitor service runs continuously on your server and fetches NWS alerts, saving them to a JSON file. This ensures alerts are captured even when nobody has the dashboard open.

**Key benefits:**
- Runs 24/7 independently of any browser
- Captures all alerts even during overnight hours
- Dashboard loads cached alerts instantly on open
- Survives browser crashes, tab closures, and network issues

### Linux Setup

```bash
# Edit configuration in nws-monitor.sh
nano nws-monitor.sh

# Test single fetch
./nws-monitor.sh

# Run as daemon (stays running)
./nws-monitor.sh --daemon

# Install as systemd service (recommended for 24/7 operation)
sudo cp nws-monitor.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/nws-monitor.sh
sudo cp nws-monitor.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable nws-monitor
sudo systemctl start nws-monitor

# Check status
sudo systemctl status nws-monitor
```

### Windows Setup

```powershell
# Edit configuration in nws-monitor.ps1
notepad nws-monitor.ps1

# Test single fetch
.\nws-monitor.ps1

# Run as daemon (keeps window open)
.\nws-monitor.ps1 -Daemon

# Install as scheduled task (runs every 5 min)
.\nws-monitor.ps1 -Install

# Or use batch file
nws-monitor.bat -Install
```

### Monitor Configuration

Edit these settings in `nws-monitor.sh` or `nws-monitor.ps1`:

```bash
# Linux
OUTPUT_DIR="/var/www/html/bpq/wx"    # Must match dashboard location
REGIONS="ALL"                         # SR,CR,ER,WR,AR,PR or ALL
ALERT_TYPES="tornado,severe"          # tornado,severe,flood,hurricane,all
FETCH_INTERVAL=300                    # Seconds between fetches
FROM_CALLSIGN="YOURCALL"                 # Your callsign
TO_ADDRESS="WX@ALLUS"                 # BBS destination
AUTO_POST=0                           # 1 to auto-post to BBS
```

```powershell
# Windows
$OUTPUT_DIR = "C:\UniServerZ\www\bpq\wx"
$REGIONS = "ALL"
$ALERT_TYPES = "tornado,severe"
$FETCH_INTERVAL = 300
$FROM_CALLSIGN = "YOURCALL"
$TO_ADDRESS = "WX@ALLUS"
```

## Requirements

### Linux
```bash
sudo apt install curl jq expect
```

### Windows
- PowerShell 5.0+ (included in Windows 10/11)
- No additional software required

## Quick Start - Regional Alerts

### Linux

```bash
# Make executable
chmod +x nws-region-bbs.sh

# Edit configuration at top of script
nano nws-region-bbs.sh

# Fetch Southern Region tornado alerts
./nws-region-bbs.sh SR

# Fetch multiple regions
./nws-region-bbs.sh SR,CR

# Fetch ALL US alerts
./nws-region-bbs.sh ALL

# Auto-post to BBS
./nws-region-bbs.sh SR -p

# Add to cron (every 5 minutes)
crontab -e
# Add: */5 * * * * /usr/local/bin/nws-region-bbs.sh SR -p >/dev/null 2>&1
```

### Windows

```powershell
# Fetch Southern Region
.\nws-region-bbs.ps1 -Region SR

# Fetch multiple regions
.\nws-region-bbs.ps1 -Region SR,CR

# Fetch all alert types
.\nws-region-bbs.ps1 -Region SR -AlertType all

# Or use batch file
nws-region-bbs.bat SR
nws-region-bbs.bat SR all
```

## Configuration

### Linux Scripts

Edit the configuration section at the top of each script:

```bash
# Your station info
FROM_CALLSIGN="YOURCALL"          # Your callsign
TO_ADDRESS="WX@ALLUS"          # Destination (WX@ALLUS, WX@ALLGA, etc.)
STATE_FILTER="GA"              # Your state code

# BBS connection (for auto-posting)
BBS_HOST="localhost"           # BBS host
BBS_PORT="8010"                # BBS telnet port
BBS_USER="SYSOP"               # BBS login
BBS_PASS="password"            # BBS password

# Output locations
OUTPUT_DIR="/var/www/html/bpq/wx"
PROCESSED_FILE="/var/log/nws-processed.txt"
```

### Windows Scripts

Edit the configuration section in `nws-tornado-bbs.ps1`:

```powershell
$FROM_CALLSIGN = "YOURCALL"
$TO_ADDRESS = "WX@ALLUS"
$OUTPUT_DIR = "C:\BPQ32\NWSMessages"
$PROCESSED_FILE = "C:\BPQ32\nws-processed.txt"
```

## Advanced Usage

### Multi-Alert Type Script (Linux)

The `nws-weather-bbs.sh` script supports multiple alert types:

```bash
# Tornado warnings only
./nws-weather-bbs.sh -s GA -t tornado

# Severe thunderstorm warnings
./nws-weather-bbs.sh -s GA -t severe

# Flash flood warnings
./nws-weather-bbs.sh -s FL -t flood

# Hurricane warnings
./nws-weather-bbs.sh -s FL -t hurricane

# All warnings
./nws-weather-bbs.sh -s GA -t all

# Auto-post to BBS
./nws-weather-bbs.sh -s GA -t tornado -p

# Custom output directory
./nws-weather-bbs.sh -s GA -t all -o /home/pi/wx_alerts
```

## BBS Message Format

Messages are formatted for BPQ BBS compatibility:

```
SP WX@ALLUS
*** TORNADO WARNING ***

Tornado Warning issued January 14 at 2:30PM EST until January 14 at 3:15PM EST 
by NWS Peachtree City GA

ISSUED BY: NWS Peachtree City GA
EXPIRES: 01/14 2015Z
SEVERITY: Extreme
URGENCY: Immediate

AFFECTED AREAS:
Richmond County, Columbia County, Burke County

DESCRIPTION:
At 230 PM EST, a confirmed tornado was located near Augusta, moving northeast 
at 45 mph. TAKE COVER NOW!

PROTECTIVE ACTIONS:
TAKE COVER NOW! Move to an interior room on the lowest floor of a sturdy 
building. Avoid windows.

---
Alert ID: a1b2c3d4
Auto-generated by NWS-BPQ Gateway
73 de YOURCALL
/EX
```

## Automation

### Linux Cron Jobs

```bash
# Check for tornado warnings every 5 minutes
*/5 * * * * /usr/local/bin/nws-tornado-post.sh GA >/dev/null 2>&1

# Check for all severe weather every 10 minutes
*/10 * * * * /usr/local/bin/nws-weather-bbs.sh -s GA -t all -p >/dev/null 2>&1
```

### Windows Task Scheduler

1. Open Task Scheduler (`taskschd.msc`)
2. Create Basic Task
3. Name: "NWS Weather Alerts"
4. Trigger: Daily, repeat every 5 minutes
5. Action: Start a program
6. Program: `C:\path\to\nws-tornado-bbs.bat`
7. Arguments: `GA`

## BBS Posting from Dashboard

The NWS Dashboard can post alerts directly to your BBS using the included PHP backend script.

### Setup

1. **Copy files to web server:**
   ```bash
   cp nws-bbs-post.php /var/www/html/bpq/
   cp nws-config.php.example /var/www/html/bpq/nws-config.php
   ```

2. **Edit configuration:**
   ```bash
   nano /var/www/html/bpq/nws-config.php
   ```

   Update these settings:
   ```php
   return [
       'bbs_host' => 'localhost',      // Your BBS host
       'bbs_port' => 8010,             // BBS telnet port
       'bbs_user' => 'SYSOP',          // BBS login
       'bbs_pass' => 'yourpassword',   // BBS password
       'from_call' => 'N0CALL',        // Your callsign
       'to_addr' => 'WX@ALLUS',        // Destination
       'enabled' => true,              // IMPORTANT: Set to true!
   ];
   ```

3. **Set permissions:**
   ```bash
   # Create log file
   sudo touch /var/log/nws-bbs-post.log
   sudo chmod 666 /var/log/nws-bbs-post.log
   ```

4. **Test from dashboard:**
   - Open NWS Dashboard
   - Click "Post to BBS" on any alert
   - Check status log for success/failure

### Dashboard Configuration

Edit `nws-dashboard.html` if needed:

```javascript
const CONFIG = {
    bbsPostUrl: './nws-bbs-post.php',  // Path to PHP script
    bbsPostEnabled: true,               // Enable posting
    bbsDestination: 'WX@ALLUS'          // Default destination
};
```

### Troubleshooting

**"BBS posting is disabled"**
- Edit `nws-config.php` and set `'enabled' => true`

**"Cannot connect to BBS"**
- Verify BBS is running
- Check host/port settings
- Ensure firewall allows connection

**Permission denied**
- Check PHP can write to log file
- Verify web server user has network access

---

## Destination Addresses

Common BBS destination addresses for weather alerts:

| Address | Description |
|---------|-------------|
| `WX@ALLUS` | All US stations (for major events) |
| `WX@ALLGA` | All stations in Georgia |
| `WX@BPQDash` | BPQDash network stations |
| `NTS@...` | National Traffic System |

## Troubleshooting

### No alerts fetched
- Check internet connectivity
- Verify state code is valid (2-letter code)
- NWS API may be temporarily unavailable

### Duplicate messages
- Check `PROCESSED_FILE` exists and is writable
- Verify file path in configuration

### BBS posting fails
- Verify BBS credentials
- Check BBS telnet port is correct
- Ensure `expect` is installed
- Check BBS is accepting connections

### Permission errors
```bash
# Fix log file permissions
sudo touch /var/log/nws-tornado-post.log
sudo chmod 666 /var/log/nws-tornado-post.log

# Fix processed file
sudo touch /var/log/nws-processed.txt
sudo chmod 666 /var/log/nws-processed.txt
```

## API Information

This script uses the official NWS API:
- Base URL: `https://api.weather.gov/alerts/active`
- Documentation: https://www.weather.gov/documentation/services-web-api
- No API key required
- Rate limits: Be reasonable (once per 5 minutes is fine)

## Quick Setup Guide - 24/7 Background Service

Follow these step-by-step instructions to set up the background monitor service for continuous, unattended alert monitoring.

### Linux Quick Setup (5 minutes)

```bash
# 1. Create the wx directory for alert data
sudo mkdir -p /var/www/html/bpq/wx
sudo chown www-data:www-data /var/www/html/bpq/wx

# 2. Copy the monitor script
sudo cp nws-monitor.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/nws-monitor.sh

# 3. Edit configuration (change callsign, paths if needed)
sudo nano /usr/local/bin/nws-monitor.sh
# Edit these lines:
#   OUTPUT_DIR="/var/www/html/bpq/wx"   <- match your web server path
#   FROM_CALLSIGN="YOURCALL"             <- your callsign
#   REGIONS="SR"                         <- your region (SR,CR,ER,WR,AR,PR,ALL)

# 4. Test it works
/usr/local/bin/nws-monitor.sh
# Should see: "Fetched X alerts, saved to /var/www/html/bpq/wx/nws-alerts.json"

# 5. Install as systemd service
sudo cp nws-monitor.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable nws-monitor
sudo systemctl start nws-monitor

# 6. Verify it's running
sudo systemctl status nws-monitor
# Should show: "Active: active (running)"

# 7. Check logs
tail -f /var/log/nws-monitor.log
```

### Windows Quick Setup (5 minutes)

```powershell
# 1. Create the wx directory for alert data
New-Item -ItemType Directory -Path "C:\BPQ\www\wx" -Force

# 2. Edit configuration in nws-monitor.ps1
notepad nws-monitor.ps1
# Edit these lines:
#   $OUTPUT_DIR = "C:\BPQ\www\wx"        <- match your web server path
#   $FROM_CALLSIGN = "YOURCALL"          <- your callsign
#   $REGIONS = "SR"                      <- your region

# 3. Test it works
.\nws-monitor.ps1
# Should see: "Fetched X alerts"

# 4. Install as Windows Task Scheduler job (runs every 5 minutes)
.\nws-monitor.ps1 -Install

# 5. Verify task was created
Get-ScheduledTask -TaskName "NWS-Monitor" | Format-List

# Alternative: Run in background window
Start-Process powershell -ArgumentList "-WindowStyle Hidden -File nws-monitor.ps1 -Daemon"
```

### Verify Background Service is Working

After setup, verify the service is fetching alerts:

**Linux:**
```bash
# Check service status
sudo systemctl status nws-monitor

# Watch real-time logs
tail -f /var/log/nws-monitor.log

# Check JSON file is being updated
ls -la /var/www/html/bpq/wx/nws-alerts.json
cat /var/www/html/bpq/wx/nws-alerts.json | head -20
```

**Windows:**
```powershell
# Check task status
Get-ScheduledTask -TaskName "NWS-Monitor" | Select State

# Check log file
Get-Content C:\BPQ\www\wx\nws-monitor.log -Tail 20

# Check JSON file
Get-Item C:\BPQ\www\wx\nws-alerts.json
```

### Configure Dashboard to Use Background Service

Once the background service is running, configure the dashboard to read from the cached JSON file instead of fetching directly from NWS:

1. Open `nws-dashboard.html` in a text editor
2. Find the `CONFIG` section near line 350
3. Change `useLocalJson: false` to `useLocalJson: true`
4. Verify `alertsJsonUrl` points to your wx folder: `'./wx/nws-alerts.json'`

```javascript
const CONFIG = {
    alertsJsonUrl: './wx/nws-alerts.json',  // Path to cached alerts
    useLocalJson: true,                      // Use local JSON instead of live API
    // ...
};
```

Now the dashboard will load cached alerts instantly when opened, and the background service ensures fresh data even when no one is watching.

## License

MIT License - Free for amateur radio use.

## Credits

- National Weather Service for providing the free API
- BPQ32 by John Wiseman G8BPQ
- Amateur radio operators everywhere providing emergency communications

**73!**
