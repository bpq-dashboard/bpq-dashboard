# BPQ Dashboard v1.5.6

A comprehensive, modern monitoring dashboard suite for BPQ packet radio nodes featuring real-time RF connection analysis, system monitoring, traffic statistics, message monitoring, BBS message management, and NWS weather alerts.

## What's New in v1.5.6

### Best Paths Visualization (RF Connections)
- **Quality-Scored Links** — station-to-home paths on Station Map scored by S/N (40%), VARA throughput (35%), and reliability (25%)
- **Visual Display** — color-coded lines (red→green) with thickness proportional to score, animated particles on top 5 paths
- **Interactive** — click paths for popup with score, S/N, sessions, avg/peak speed, primary band
- **Time-Range Aware** — updates with Today/7 Day/30 Day selection

### Propagation-Based Forwarding Scheduler (Optional)
- **Automatic HF Optimization** — `scripts/prop-scheduler.py` adjusts BPQ linmail.cfg ConnectScript schedules every 48 hours
- **Multi-Source Data** — combines NOAA solar flux, Kp index, seasonal NVIS models, and historical BBS connection data
- **Per-Partner Tuning** — distance-aware band selection (NVIS vs skip), supports fixed-schedule scanning stations
- **Safe Updates** — backs up config before changes, stop→write→start sequence for BPQ, sends report as BBS personal message

### Geomagnetic Storm Monitor (Optional)
- **Hourly Protection** — `scripts/storm-monitor.py` monitors Kp index and forces 80m-only during storms (Kp ≥ 5)
- **Auto-Recovery** — restores optimized schedules when Kp drops below 3 for 2 consecutive hours
- **BBS Alerts** — sends storm activation and recovery notifications as BBS personal messages

### Forwarded Messages Panel (RF Connections)
- **Real Message Data** — parses actual FBB protocol transfers from BBS logs, replacing the byte-threshold estimate
- **Per-Message Details** — time, direction (received/forwarded), partner, type (Bulletin/Personal/Traffic), from, to (category@area), BID, and size
- **Filterable** — dropdown filters for partner and message type
- **Collapsible** — click header to expand/collapse, sits between Forwarding Partners and Propagation Report

### Dark Mode (All Pages)
- **Automatic OS Detection** — follows your system's light/dark preference via `prefers-color-scheme`
- **Manual Toggle** — 🌙/☀️ button in the nav bar next to UTC/Local clocks
- **Persistent** — choice saved to localStorage across page loads and navigation
- **Chart.js Integration** — charts re-render with dark-appropriate text and grid colors on toggle
- **Full Coverage** — backgrounds, stat cards, tables, forms, log viewer, Leaflet map controls, modals, scrollbars all adapt
- Applied to all 7 dashboard pages: RF, Traffic, System, Connects, Messages, BBS, Weather

### Performance Optimizations
- **Removed 69 console.log statements** across 5 dashboard files (system logs, RF connections, traffic, messages, RF power monitor)
- **Batch DOM rendering** in System Logs — log entries now built as single innerHTML string instead of per-line createElement/appendChild loop
- **Auto-refresh reduced** from 1 minute to 5 minutes on System Logs (matches MHeard/RTKnown intervals)
- **Chart.js animations disabled** on 8 charts across 3 pages (connect log, traffic, email monitor) — charts update instantly on refresh

### Centralized Callsign Lookups
- **RF Connections** — replaced inline callook.info-only lookup with `callsign-lookup.php` (QRZ.com primary, callook.info fallback), enabling international station mapping (VE/VA, G/M, VK, etc.)
- **Connect Log** — removed inline callook.info + HamDB fallback chain; now uses `callsign-lookup.php` exclusively with batch processing
- User locations, manual locations, and localStorage cache layers preserved in RF Connections

### Usability
- **Text selection re-enabled** — removed `user-select: none` from all 5 core pages so users can copy callsigns, log entries, and stats
- **Right-click and keyboard shortcuts restored** — removed context menu blocking and F12/Ctrl+U/Ctrl+S prevention

### Files Changed
- `bpq-system-logs.html` — dark mode, console.log removal, batch rendering, 5-min refresh
- `bpq-rf-connections.html` — dark mode, console.log removal, callsign-lookup.php integration
- `bpq-connect-log.html` — dark mode, chart animation disabled, callsign-lookup.php integration
- `bpq-traffic.html` — dark mode, console.log removal, chart animation disabled
- `bpq-email-monitor.html` — dark mode, chart animation disabled
- `bbs-messages.html` — dark mode, console.log removal
- `nws-dashboard.html` — dark mode
- `rf-power-monitor.html` — console.log removal

See [CHANGELOG.md](CHANGELOG.md) for complete version history.

## What's New in v1.5.6

### System Logs Enhancements
- **Callsign Search & PDF Reports** - Search logs by callsign with PDF report generation featuring color-coded entries, summary statistics, and multi-page support
- **Enhanced Error Details Panel** - Click "Errors ▼" to expand detailed breakdown showing RF Failures, Node Disappeared, Protocol Errors, Timeouts, Connect Failures with visual distribution bars and recent errors list

### NWS Dashboard Redesign
- **Complete Visual Overhaul** - Rebuilt with BPQ Dashboard light theme: white glass containers, purple/blue gradients, consistent nav bar with UTC/Local clocks
- **Mobile Responsive** - Alerts appear first on phones/tablets
- **Configurable Auto-Refresh** - Select 30s/1min/5min/15min/Off with live countdown timer
- **62% Smaller** - Reduced from 2316 to 871 lines while preserving all functionality

### Dual-Mode Message Monitor
- **BBS Mode** - Parses `log_*_BBS.txt` for message forwarding activity:
  - Messages Received/Forwarded with hourly chart
  - Bulletin groups by category@area (NEWS@WW, TECH@WW, etc.)
  - Click groups to view and read bulletins from BBS
  - Forwarding partners with incoming/outgoing counts
  - Recent message BIDs with from/to and direction arrows
- **SMTP/POP Server Mode** - Parses `log_*_TCP.txt` for email clients:
  - POP3/SMTP/NNTP session counts
  - Top users, security events, hourly chart
- Mode toggle saves preference to localStorage
- Intelligent warnings for missing logs or no activity

## What's New in v1.5.6

### Major Features
- **VARA Channel Quality Analysis** - Dedicated panel mapping observed VARA bitrates to modem modulation tiers (BFSK→32QAM), with quality distribution bars, 7-day S/N and speed trends, QSB/multipath detection, and S/N-bitrate mismatch warnings
- **Propagation-Aware Scoring** - Best Band recommendations now classify failures as RF (timeout/nodata) vs infrastructure (channel busy/port in session), score bands by VARA modulation tier (35% weight), detect S/N trends, and flag multipath conditions
- **UTC & Local Clocks** - Live dual digital clocks (UTC + local) in the nav bar on all 7 pages, updating every second
- **Station Location Persistence** - Server-side storage via `station-storage.php` for station locations and forwarding partners; syncs across browsers/devices with `localStorage` fallback
- **Enhanced Best Band Recommendations** - 6×4hr seasonal time slots with sunrise/sunset calculation, trend indicators (↑↗→↘↓), dual-band recommendations, VARA modulation-tier scoring with propagation-aware failure classification
- **30-Day Geomagnetic Tracking** - K-index expanded to 3 days, A-index expanded to 30 days using official NOAA Planetary Ap data
- **Enhanced Propagation Report** - Failure analysis, station×band matrix, week-over-week trends, solar conditions, message metrics, interactive heatmap grid
- **Performance Optimizations** - 7 major optimizations: frequency indexing (O(n)→O(n/days)), callsign cache layer, Set-based lookups, non-blocking UI, dead allocation removal
- **Server-Side Data API** - New `api/data.php` endpoint with intelligent caching for RF Power Monitor
- **VARA Disconnect Fix** - Two new disconnect patterns handled, reducing bloated connection windows by 51%
- **Health Check Tool** - `health-check.php` tests PHP, directories, config, APIs, logs, and BBS connectivity
- **UI Reorganization** - MHeard Stations and Known Stations Routing Table moved from RF Connections to System Logs
- **Calendar-Based Filtering** - Traffic (7D/4W/12W) and Message Monitor (7D/4W/30D) filter by actual calendar days
- **Unified Configuration** - Single `config.php` replaces multiple config files
- **Security Modes** - `local` (full features) and `public` (read-only) modes

See [CHANGELOG.md](CHANGELOG.md) for complete version history.

## Documentation

| Document | Description |
|----------|-------------|
| **[INSTALL-WINDOWS.md](INSTALL-WINDOWS.md)** | Step-by-step Windows installation for beginners |
| **[INSTALL-LINUX.md](INSTALL-LINUX.md)** | Step-by-step Linux installation for beginners |
| [INSTALL.md](INSTALL.md) | Complete configuration reference guide |
| [QUICK-START.md](QUICK-START.md) | Get running in 5 minutes (experienced users) |
| [DATA-ARCHIVAL.md](DATA-ARCHIVAL.md) | Automated backup and archival system |
| [PUBLIC-DEPLOYMENT.md](PUBLIC-DEPLOYMENT.md) | Security guide for internet exposure |
| [VARA-FETCH-WINDOWS.md](VARA-FETCH-WINDOWS.md) | BPQDash VARA log fetching setup |
| [NWS-WEATHER-README.md](NWS-WEATHER-README.md) | Weather alerts configuration |
| [CODE-REVIEW.md](CODE-REVIEW.md) | Security recommendations |
| [CHANGELOG.md](CHANGELOG.md) | Version history |

## Dashboard Components

| Dashboard | File | Description |
|-----------|------|-------------|
| **RF Connections** | `bpq-rf-connections.html` | VARA metrics, VARA channel quality analysis, 30-day geomagnetic tracking, station mapping, band analysis, propagation report, seasonal best band recommendations with propagation-aware scoring, forwarded messages panel |
| **System Logs** | `bpq-system-logs.html` | Live log viewer, MHeard stations, known stations routing table, most active stations |
| **Traffic Statistics** | `bpq-traffic.html` | Station rankings, efficiency metrics, calendar-based time ranges (7D/4W/12W) |
| **Message Monitor** | `bpq-email-monitor.html` | Dual-mode: BBS forwarding analytics (messages, bulletins, partners) or SMTP/POP Server analytics (POP3/SMTP/NNTP sessions, users, security) |
| **BBS Messages** | `bbs-messages.html` | Full BBS client with read/compose/delete, folder management, server storage |
| **Connect Log** | `bpq-connect-log.html` | Connection mode analytics (NETROM, VARA, AX.25, TELNET), station mapping with international callsign lookup, hourly/daily charts, mode filtering |
| **NWS Weather** | `nws-dashboard.html` | National Weather Service alerts with BBS posting capability |
| **RF Power Monitor** | `rf-power-monitor.html` | 4-channel RF power monitoring with frequency correlation (requires WaveNode meter) |

All pages include live UTC and Local digital clocks in the nav bar.

## Required Log Files

Each dashboard reads specific BPQ32-generated files from the `logs/` directory:

| Dashboard | File Pattern | Generated By |
|-----------|-------------|--------------|
| RF Connections | `log_YYMMDD_debug.txt` | BPQ32 daily debug logs |
| System Logs | `log_YYMMDD.txt` | BPQ32 daily system logs |
| System Logs | `MHSave.txt` | BPQ32 MHeard data |
| Traffic Statistics | `Traffic_YYMMDD.txt` | BPQ32 weekly traffic reports |
| Message Monitor (BBS Mode) | `log_YYMMDD_BBS.txt` | BPQ32 daily BBS logs |
| Message Monitor (SMTP/POP) | `log_YYMMDD_TCP.txt` | BPQ32 daily TCP logs |
| RF Power Monitor | `DataLog_YYMMDD.csv` | WaveNode data logger |
| RF Power Monitor | `VaraLog_YYMMDD.txt` | VARA modem connection logs |
| Connect Log | `ConnectLog_YYMMDD.log` | BPQ32 connection mode tracking |

If a dashboard shows a warning, check that the required files exist in the `logs/` directory.

---

## Quick Start

### Linux Installation

```bash
# Extract and run installer
unzip BPQ-Dashboard-v1_4_1.zip
cd BPQ-Dashboard
chmod +x deploy-linux.sh
sudo ./deploy-linux.sh --auto

# Configure (REQUIRED)
sudo cp /var/www/html/bpq/config.php.example /var/www/html/bpq/config.php
sudo nano /var/www/html/bpq/config.php
# Edit: callsign, coordinates, BBS password

# Set permissions
sudo chown -R www-data:www-data /var/www/html/bpq/
sudo chmod -R 755 /var/www/html/bpq/data/
```

### Windows Installation

1. Extract `BPQ-Dashboard` folder to web root (e.g., `C:\UniServerZ\www\bpq\`)
2. Create directories: `logs`, `data\messages`, `wx`, `archives`
3. Copy `config.php.example` to `config.php` and edit settings
4. Access: `http://localhost/bpq/`

See [INSTALL.md](INSTALL.md) for detailed instructions.

---

## Features

### Unified Configuration (v1.5.6)

Single `config.php` file for all settings:

```php
return [
    'security_mode' => 'local',  // or 'public' for internet
    'station' => [
        'callsign'  => 'W1AW',
        'latitude'  => 41.7147,
        'longitude' => -72.7272,
        'grid'      => 'FN31pr',
    ],
    'bbs' => [
        'host' => 'localhost',
        'port' => 8010,
        'user' => 'SYSOP',
        'pass' => 'YourPassword',  // CHANGE THIS!
        'alias' => 'bbs',
    ],
    // ... more settings
];
```

### Security Modes

| Feature | Local Mode | Public Mode |
|---------|------------|-------------|
| View messages | ✅ | ✅ |
| Send/Delete messages | ✅ | ❌ |
| Post weather to BBS | ✅ | ❌ |
| Rate limiting | Optional | ✅ Enforced |
| CORS | Allow all | Whitelist only |

### RF Connections Dashboard
- **Connection Statistics** - Success/failure rates, timeout analysis, propagation vs infrastructure failure classification
- **Geomagnetic Conditions** - 3-day K-index (3-hour intervals), 30-day A-index (official NOAA Planetary Ap), solar flux, sunspot number, storm alerts
- **Best Band Recommendations** - Seasonal 6×4hr time slots with sunrise/sunset awareness, trend indicators, dual-band suggestions, VARA modulation-tier scoring (BFSK→32QAM), S/N trend detection, multipath flagging, propagation-aware failure filtering
- **VARA Channel Quality** - Per-band modulation tier analysis, quality distribution bars, avg/median/peak bitrate, 7-day S/N and speed trends, QSB/multipath detection, S/N-bitrate mismatch warnings
- **Propagation Report** - Failure analysis with RF vs infrastructure separation, station×band matrix, week-over-week trends, propagation availability metric, solar conditions, message metrics, interactive heatmap
- **Station Map** - Geographic visualization with callsign lookup, persistent locations via Export/Import JSON
- **Station Detail Modal** - Click any callsign for 7-day history and efficiency
- **UTC & Local Clocks** - Live digital clocks in the nav bar

### System Logs Dashboard
- **Real-time Log Viewing** - Auto-refreshing with search and filtering
- **MHeard Stations** - 4-port tabbed display with band badges and time-ago
- **Known Stations Routing Table** - Searchable routing data with region identification
- **Time Range Filtering** - 1D/7D/30D with UTC-based calculations
- **Most Active Stations** - Top stations ranked by activity

### Traffic Report Dashboard
- **Calendar-Based Time Ranges** - 7D/4W/12W filtering by actual dates
- **Efficiency Metrics** - Success rate, messages per connection, KB per message
- **BBS Partners Filter** - Show only active forwarding partners
- **Empty State Warnings** - Specific file type and location when data missing

### Message Monitor Dashboard
- **Dual Mode Operation** - Switch between BBS Mode and SMTP/POP Server mode
- **BBS Mode** - Parses `log_*_BBS.txt` files for message forwarding activity:
  - Messages Received/Forwarded counts with hourly activity chart
  - Bulletin tracking by category@area (e.g., NEWS@WW, TECH@WW)
  - Forwarding partner connections with incoming/outgoing counts
  - Recent message IDs with from/to, direction arrows, and BID
  - Click bulletin groups to view and read messages from BBS
  - Rejected messages (by BID) counter
- **SMTP/POP Server Mode** - Parses `log_*_TCP.txt` files for email client activity:
  - POP3/SMTP/NNTP session counts with hourly activity chart
  - Top 5 users with session and message counts
  - Security events (auth failures, suspicious activity, relay attempts)
  - Shows "No activity" warning when no email server data in selected range
- **Time Range Selection** - 7D/4W/30D with instant re-filtering
- **Empty State Warnings** - Specific guidance for missing log files or no activity

### RF Power Monitor
- **4-Channel Power Monitoring** - Real-time gauges with history charts
- **Frequency Correlation** - TX events linked to VARA connections with band/callsign
- **Success/Failed Indicators** - Connection outcome shown per TX session
- **Server-Side API** - Fast loading via `api/data.php` with automatic fallback
- **Requires WaveNode** hardware RF power meter (WN-2m must run as administrator)

### BBS Messages Dashboard
- **Message Management** - View, read, compose, delete messages (up to 100)
- **Folder System** - Create custom folders to organize saved messages
- **Bulletin Downloads** - Fetch bulletins by category (WX, NEWS, ARRL, etc.)
- **Server Storage** - Save messages to server for cross-device access

### Data Archival

Automated backup scripts included:

```bash
# Linux - run weekly via cron
/var/www/html/bpq/scripts/archive-bpq-data.sh

# Windows - run via Task Scheduler
C:\UniServerZ\www\bpq\scripts\archive-bpq-data.bat
```

### Station Location Management

Station locations and forwarding partners are stored on the server via `station-storage.php` with browser `localStorage` as automatic fallback. When server storage is active, data syncs across all browsers and devices.

The Manage Locations and Manage Partners modals show a live storage status indicator:
- **🟢 Saved to server** — data persists on the web server in `data/stations/`
- **🟠 Browser storage only** — `station-storage.php` is missing or not reachable; deploy it for persistent storage

Export/Import buttons remain available for manual backup and migration between installations.

---

## File Structure

```
BPQ-Dashboard/
├── config.php                 # Main configuration (create from example)
├── config.php.example         # Configuration template
├── includes/
│   └── bootstrap.php          # Security and config loader
├── api/
│   ├── config.php             # Configuration API endpoint
│   └── data.php               # DataLog + connection data API (RF Power Monitor)
├── shared/
│   └── config.js              # Client-side configuration
├── bpq-rf-connections.html    # RF connections dashboard
├── bpq-system-logs.html       # System logs + MHeard + routing table
├── bpq-traffic.html           # Traffic report dashboard
├── bpq-email-monitor.html     # Email monitor dashboard
├── bbs-messages.html          # BBS messages dashboard
├── bbs-messages.php           # BBS API backend
├── bpq-connect-log.html       # Connect log dashboard (connection modes)
├── message-storage.php        # Server-side message storage
├── station-storage.php        # Server-side station location & partner storage
├── rf-power-monitor.html      # RF power monitoring dashboard
├── health-check.php           # Installation diagnostic page
├── nws-dashboard.html         # Weather dashboard
├── nws-bbs-post.php           # Weather BBS posting
├── datalog-list.php           # DataLog file lister (copy to logs/)
├── scripts/
│   ├── archive-bpq-data.sh    # Linux archival script
│   └── archive-bpq-data.bat   # Windows archival script
├── cache/                     # API cache (auto-populated, safe to clear)
├── data/
│   ├── messages/              # Server-side message storage
│   └── stations/              # Server-side station locations & partners
├── archives/                  # Data archives
├── logs/                      # Log files (symlinks or copies)
└── wx/                        # Weather data cache
```

---

## Troubleshooting

### Built-in Health Check

Open `http://YOUR_SERVER/bpq/health-check.php` in your browser. This diagnostic page tests PHP, directories, permissions, config, all API endpoints, log files, and BBS connectivity — and tells you exactly how to fix any issues it finds.

### "No Traffic/TCP Log Files Found"
Each dashboard shows a detailed warning when required files are missing, including the exact file pattern, expected location, and example filename. Check that BPQ32 log files are being synced to the `logs/` directory.

### "Configuration file not found"
```bash
sudo cp config.php.example config.php
sudo nano config.php  # Edit your settings
```

### "BBS password not configured"
Edit `config.php` and change `'pass' => 'CHANGEME'` to your BBS password.

### Station map locations lost after clearing browser data
If `station-storage.php` is deployed, locations are stored on the server and survive browser data clears. Check the storage indicator in Manage Locations — if it shows "Browser storage only," ensure `station-storage.php` is in your dashboard root and `data/stations/` is writable by your web server.

### "Cannot connect to BBS"
1. Test: `telnet localhost 8010`
2. Verify port matches BPQ configuration
3. Check username/password

### "Server storage not available"
```bash
sudo chown -R www-data:www-data /var/www/html/bpq/data/
sudo chmod -R 755 /var/www/html/bpq/data/
```

See [INSTALL.md](INSTALL.md) for more troubleshooting tips.

---

## Upgrading from v1.3.x

1. **Backup your existing installation**
2. **Extract v1.5.6 files** to your dashboard directory
3. **Keep your existing `config.php`** — no config changes required
4. **Create `data/stations/` directory** with web server write permissions
5. **Note:** MHeard Stations and Known Stations Routing Table have moved from RF Connections to System Logs
6. **New features:** UTC/Local clocks on all pages, VARA Channel Quality analysis panel, propagation-aware Best Band scoring with VARA modulation tiers, enhanced Propagation Report with RF vs infrastructure failure separation, server-side station location storage
7. **New file:** `station-storage.php` — stores station locations and forwarding partners on the server (replaces browser-only `localStorage`)

## Upgrading from v1.2.x

1. **Backup your existing installation**
2. **Extract v1.5.6 files** to your dashboard directory
3. **Create new config.php** from `config.php.example`
4. **Migrate settings** from old `bbs-config.php`:
   ```php
   // Old format (bbs-config.php):
   $config['bbs_host'] = 'localhost';
   $config['bbs_pass'] = 'mypassword';
   
   // New format (config.php):
   'bbs' => [
       'host' => 'localhost',
       'pass' => 'mypassword',
   ],
   ```
5. **Delete old config files**: `bbs-config.php`, `nws-config.php`

---

## Browser Compatibility

| Browser | Support |
|---------|---------|
| Chrome/Edge | ✅ Full support (recommended) |
| Firefox | ✅ Full support |
| Safari | ✅ Full support |
| Opera | ✅ Full support |
| IE11 | ❌ Not supported |

---

## Support & Resources

- **BPQ32 Documentation:** https://www.cantab.net/users/john.wiseman/Documents/
- **LinBPQ:** https://www.intermanual.com/bpq32
- **VARA Modem:** https://rosmodem.wordpress.com/
- **BPQ Network:** https://bpqdash.net/

---

## License

MIT License - Free for personal and commercial use.

## Credits

- BPQ32/LinBPQ by John Wiseman G8BPQ
- VARA Modem by EA5HVK
- Dashboard design with assistance from Claude AI
- Solar data from HamQSL.com and NOAA SWPC (K-index, Planetary Ap, DGD)
- Callsign lookup via callook.info
