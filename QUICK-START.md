# BPQ Dashboard Quick Start Guide

**Version 1.5.6** - Get your BPQ Dashboard running in 5 minutes!

## Prerequisites

- BPQ32 or LinBPQ running with logging enabled
- A web server (Apache, Nginx, or Uniform Server) with PHP
- PHP extensions: `sockets` (required), `curl` (recommended)
- Web browser (Chrome, Firefox, Edge, or Safari)

---

## Windows Quick Start

### Step 1: Install Web Server

**Recommended: Uniform Server (Free & Portable)**

1. Download from https://www.uniformserver.com/
2. Extract to `C:\UniServerZ`
3. Run `UniServerZ\UniController.exe`
4. Click **Start Apache**

### Step 2: Deploy Dashboard

1. Extract `BPQ-Dashboard-v1.5.6-deploy.zip`
2. Copy `BPQ-Dashboard` folder to `C:\UniServerZ\www\bpq\`
3. Create directories:
   ```batch
   mkdir logs
   mkdir cache
   mkdir data\messages
   mkdir wx
   mkdir archives\current archives\weekly archives\monthly
   ```

### Step 3: Configure (REQUIRED)

```batch
cd C:\UniServerZ\www\bpq
copy config.php.example config.php
notepad config.php
```

**You MUST edit these settings:**
```php
'station' => [
    'callsign'  => 'YOURCALL',    // Your callsign
    'latitude'  => 33.7490,        // Your latitude
    'longitude' => -84.3880,       // Your longitude
    'grid'      => 'EM73',         // Your grid square
],
'bbs' => [
    'pass' => 'YourBBSPassword',   // CHANGE FROM 'CHANGEME'!
],
```

### Step 4: Set Up Log Access

**Option A: Symbolic Link (Recommended)**

Open Command Prompt as Administrator:
```batch
mklink /D "C:\UniServerZ\www\bpq\logs" "C:\BPQ32\LogFiles"
```

**Option B: Copy Script**

Create `sync-logs.bat`:
```batch
@echo off
copy "C:\BPQ32\LogFiles\log_*.txt" "C:\UniServerZ\www\bpq\logs\" /Y
copy "C:\BPQ32\LogFiles\Traffic_*.txt" "C:\UniServerZ\www\bpq\logs\" /Y
copy "C:\BPQ32\LogFiles\MHSave.txt" "C:\UniServerZ\www\bpq\logs\" /Y
copy "C:\BPQ32\LogFiles\RTKnown.txt" "C:\UniServerZ\www\bpq\logs\" /Y
```
Schedule to run every 5 minutes.

> **Note:** Each dashboard requires specific files:
> - System Logs: `log_YYMMDD_BBS.txt` (daily)
> - RF Connections: `*.vara` (VARA log from BPQDash)
> - Message Monitor: `log_YYMMDD_TCP.txt` (daily)
> - Traffic: `Traffic_YYMMDD.txt` (weekly)
> - System Logs MHeard: `MHSave.txt`
> - System Logs Routing: `RTKnown.txt`
>
> If a dashboard shows "No files found," verify the required file type exists in `logs/`.

### Step 5: Access Dashboard

Open browser: `http://localhost/bpq/`

---

## Linux Quick Start

### Step 1: Install Web Server

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install apache2 php php-sockets php-curl libapache2-mod-php
sudo systemctl enable apache2
sudo systemctl start apache2
```

### Step 2: Deploy Dashboard

```bash
# Extract and copy files
unzip BPQ-Dashboard-v1.5.6-deploy.zip
sudo mkdir -p /var/www/html/bpq
sudo cp -r BPQ-Dashboard/* /var/www/html/bpq/

# Create directories
sudo mkdir -p /var/www/html/bpq/{logs,cache,data/messages,wx,archives/{current,weekly,monthly}}

# Set permissions
sudo chown -R www-data:www-data /var/www/html/bpq/
sudo chmod -R 755 /var/www/html/bpq/data/
sudo chmod -R 755 /var/www/html/bpq/archives/
```

### Step 3: Configure (REQUIRED)

```bash
cd /var/www/html/bpq
sudo cp config.php.example config.php
sudo nano config.php
```

**You MUST edit these settings:**
```php
'station' => [
    'callsign'  => 'YOURCALL',    // Your callsign
    'latitude'  => 33.7490,        // Your latitude
    'longitude' => -84.3880,       // Your longitude
    'grid'      => 'EM73',         // Your grid square
],
'bbs' => [
    'pass' => 'YourBBSPassword',   // CHANGE FROM 'CHANGEME'!
],
```

### Step 4: Link Log Files

```bash
# Create symlinks to your BPQ logs
sudo ln -sf /path/to/bpq/logs/log_*.txt /var/www/html/bpq/logs/
sudo ln -sf /path/to/bpq/Traffic_*.txt /var/www/html/bpq/logs/
```

### Step 5: Access Dashboard

Open browser: `http://your-server/bpq/`

---

## Enable Server-Side Message Storage

Store saved BBS messages on the server instead of browser:

```bash
# Linux - set permissions
sudo chown -R www-data:www-data /var/www/html/bpq/data/
sudo chmod -R 755 /var/www/html/bpq/data/
```

Then in BBS Messages dashboard, click the storage indicator (💻 Browser) to switch to server storage.

---

## Verify Installation

### Run the Health Check

Open `http://localhost/bpq/health-check.php` in your browser. This tests everything at once and shows exactly what needs fixing.

### Test Configuration API

```bash
curl http://localhost/bpq/api/config.php
```

Expected response:
```json
{"success":true,"mode":"local","station":{"callsign":"YOURCALL",...}}
```

### Test Data API (RF Power Monitor)

```bash
curl "http://localhost/bpq/api/data.php?source=datalog&days=1&debug=1"
```

Expected response: JSON with `serverTz`, `totalSamples`, and `files` fields.

### Common Errors

| Error | Solution |
|-------|----------|
| "Configuration file not found" | Copy `config.php.example` to `config.php` |
| "BBS password not configured" | Edit `config.php`, change password from `CHANGEME` |
| "Cannot connect to BBS" | Check BPQ is running, verify port (default 8010) |

---

## Dashboard URLs

All pages show live UTC and Local clocks in the nav bar.

| Dashboard | URL |
|-----------|-----|
| RF Connections | `/bpq/bpq-rf-connections.html` (VARA channel quality, seasonal best bands, 30-day geomagnetic, propagation report, station map) |
| System Logs | `/bpq/bpq-system-logs.html` (includes MHeard + Routing Table) |
| Traffic Stats | `/bpq/bpq-traffic.html` (7D/4W/12W range selector) |
| Message Monitor | `/bpq/bpq-email-monitor.html` (7D/4W/30D range selector) |
| **BBS Messages** | `/bpq/bbs-messages.html` |
| **Connect Log** | `/bpq/bpq-connect-log.html` (connection mode analytics, station mapping) |
| NWS Weather | `/bpq/nws-dashboard.html` |
| **RF Power Monitor** | `/bpq/rf-power-monitor.html` (requires WaveNode meter) |
| **Health Check** | `/bpq/health-check.php` (installation diagnostic) |

---

## Security Modes

v1.5.6 includes two security modes:

### Local Mode (Default)
- Full features enabled
- For home networks

### Public Mode
- Read-only (no send/delete)
- Rate limiting enforced
- For internet-facing installations

To enable public mode:
```php
'security_mode' => 'public',
```

See [PUBLIC-DEPLOYMENT.md](PUBLIC-DEPLOYMENT.md) for internet deployment guide.

---

## Upgrading from v1.2.x

1. Backup existing installation
2. Extract v1.5.6 files
3. Create `config.php` from example
4. Migrate settings from old `bbs-config.php` to new format
5. Delete old `bbs-config.php` and `nws-config.php`

**After upgrading:** All pages now show UTC and Local clocks in the nav bar. RF Connections has a new VARA Channel Quality panel (modulation tier analysis, quality distribution, S/N and bitrate trends, QSB/multipath detection), enhanced Best Band Recommendations (propagation-aware scoring with VARA modulation tiers, seasonal time slots, trend indicators), and an improved Propagation Report (RF vs infrastructure failure separation, heatmap, failure analysis, station×band matrix). Station map locations and forwarding partners are now stored on the server via `station-storage.php` — create `data/stations/` directory with web server write permissions.

---

## Need Help?

See [INSTALL.md](INSTALL.md) for detailed configuration and troubleshooting.

---

**73 de the BPQ Dashboard Team!**
