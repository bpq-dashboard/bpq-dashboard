# BPQ Dashboard - Windows EXE Implementation Options

**Status:** Planning / Future Implementation  
**Last Updated:** February 2026

This document outlines four approaches for converting BPQ Dashboard from a web application to a standalone Windows executable.

---

## Current Architecture

BPQ Dashboard currently runs as:
- **Frontend:** HTML/CSS/JavaScript (runs in browser)
- **Backend:** PHP (requires Apache/Nginx + PHP)
- **Data:** Reads log files from `logs/` directory
- **Dependencies:** Web server, PHP 7.4+, php-sockets extension

Users must install a web server (Uniform Server, XAMPP, or Apache) and configure it manually.

---

## Option 1: Electron App

**Effort Level:** Medium  
**Package Size:** ~150-200MB  
**Native Feel:** High

### Description
Package the HTML/JS dashboards with Electron (Chromium + Node.js runtime). Replace PHP backend with Node.js equivalents.

### Architecture
```
BPQ-Dashboard.exe (Electron)
├── Chromium runtime (renders HTML/JS dashboards)
├── Node.js backend (replaces PHP)
│   ├── File system access (read logs)
│   ├── TCP sockets (BBS connection)
│   └── HTTP client (solar data, TPRFN fetch)
└── Native integrations
    ├── System tray icon
    ├── Windows notifications
    └── Auto-start capability
```

### Pros
- True native application look and feel
- Single window, no browser required
- System tray integration
- Can use native Windows notifications
- Auto-update capability built into Electron

### Cons
- Large file size (~150-200MB) due to Chromium
- Must rewrite all PHP code in Node.js:
  - `bbs-messages.php` → Node.js TCP socket client
  - `api/data.php` → Node.js file parser
  - `station-storage.php` → Node.js file I/O
  - `message-storage.php` → Node.js file I/O
  - `solar-proxy.php` → Node.js HTTP client
- Higher memory usage than native app
- Electron security considerations

### Implementation Steps
1. Set up Electron project structure
2. Port PHP backend to Node.js
3. Create IPC bridge between renderer and main process
4. Add system tray functionality
5. Create installer with electron-builder
6. Test on Windows 10/11

### Estimated Development Time
- 4-6 sessions

---

## Option 2: Portable Web Server Bundle (Recommended for Quick Implementation)

**Effort Level:** Low  
**Package Size:** ~30-50MB  
**Native Feel:** Medium

### Description
Bundle a lightweight portable web server (Caddy or portable Apache) with PHP and all dashboard files. Create a launcher executable that starts the server and opens the dashboard in the default browser.

### Architecture
```
BPQ-Dashboard-Setup.exe (Installer)
└── Extracts to: C:\BPQ-Dashboard\
    ├── server\
    │   ├── caddy.exe (or portable Apache)
    │   ├── php\
    │   │   ├── php.exe
    │   │   ├── php.ini
    │   │   └── ext\ (sockets, curl extensions)
    │   └── Caddyfile (server config)
    ├── www\
    │   ├── bpq-rf-connections.html
    │   ├── bpq-system-logs.html
    │   ├── ... (all dashboard files)
    │   ├── api\
    │   ├── logs\
    │   └── config.php
    ├── scripts\
    │   ├── sync-bpq-logs.bat
    │   ├── fetch-vara-tprfn.ps1
    │   └── setup-tasks.bat
    ├── BPQ-Dashboard.exe (launcher)
    └── config-wizard.exe (first-run setup)
```

### First-Run Config Wizard
```
┌─────────────────────────────────────────────┐
│  BPQ Dashboard Setup                        │
├─────────────────────────────────────────────┤
│  Callsign: [K1ABC     ]                     │
│  Grid Square: [EM83pl  ]                    │
│  Latitude:  [33.4735  ]                     │
│  Longitude: [-82.0105 ]                     │
│                                             │
│  BBS Settings:                              │
│  Telnet Port: [8010]                        │
│  Password:    [********]                    │
│                                             │
│  BPQ32 Location: [Auto-detected      ] [Browse]│
│  ☑ Auto-detect from %APPDATA%\BPQ32         │
│                                             │
│  ☑ Set up automatic log syncing (every 5 min)│
│  ☐ TPRFN member (fetch VARA logs)           │
│  ☐ Start BPQ Dashboard with Windows         │
│                                             │
│  [< Back]  [Next >]  [Cancel]               │
└─────────────────────────────────────────────┘
```

### Auto-Configuration Tasks
1. Creates `config.php` with user's settings
2. Configures `sync-bpq-logs.bat` with correct BPQ32 path
3. Configures `fetch-vara-tprfn.ps1` with callsign (if TPRFN)
4. Creates Windows Task Scheduler entries:
   - "BPQ Dashboard - Sync Logs" (every 5 minutes)
   - "BPQ Dashboard - Fetch VARA" (every 15 minutes, if TPRFN)
5. Optionally adds launcher to Windows Startup folder

### Launcher (BPQ-Dashboard.exe) Behavior
```
1. Check if server already running (port check)
2. If not running:
   a. Start Caddy/Apache in background
   b. Wait for server ready (poll localhost)
3. Open http://localhost:8080/bpq/ in default browser
4. Minimize to system tray
5. Tray menu:
   - Open Dashboard
   - Open RF Connections
   - Open System Logs
   - ─────────────
   - Run Log Sync Now
   - Settings
   - ─────────────
   - Stop Server
   - Exit
```

### Pros
- Minimal code changes to existing dashboard
- All current functionality preserved
- Relatively small package size
- Easy to update (just replace www\ files)
- PHP ecosystem maintained (familiar to contributors)

### Cons
- Still opens in browser (not true native window)
- Multiple processes (launcher + server + PHP)
- Slightly more complex than single EXE

### Implementation Steps
1. Package portable Caddy + PHP
2. Create Caddyfile for local serving
3. Build launcher EXE (AutoIt, C#, or Go)
4. Build config wizard (same tool)
5. Create Task Scheduler setup script
6. Package with Inno Setup or NSIS
7. Test installation and uninstallation

### Estimated Development Time
- 2-3 sessions

---

## Option 3: Python + PyWebView

**Effort Level:** Medium  
**Package Size:** ~50-80MB  
**Native Feel:** High

### Description
Rewrite the PHP backend in Python and use PyWebView to display the HTML dashboards in a native window. Package everything with PyInstaller into a single executable.

### Architecture
```
BPQ-Dashboard.exe (PyInstaller bundle)
├── Python runtime (embedded)
├── PyWebView (native window wrapper)
├── Flask/FastAPI backend (replaces PHP)
│   ├── Log file parsing
│   ├── BBS TCP connection
│   ├── Station/message storage
│   └── Solar data proxy
├── HTML/CSS/JS dashboards (bundled)
└── Native window with embedded browser
```

### Pros
- True native window (not separate browser)
- Smaller than Electron (~50-80MB)
- Python is widely known, easier to maintain
- Single EXE distribution possible
- Good Windows integration via pywin32

### Cons
- Must port all PHP code to Python:
  - `bbs-messages.php` → Python socket client
  - `api/data.php` → Python file parser
  - All storage APIs → Python file I/O
- PyWebView uses Edge WebView2 (usually pre-installed on Windows 10/11)
- Startup time slightly slower than native

### Implementation Steps
1. Set up Python project with Flask/FastAPI
2. Port PHP backend to Python
3. Integrate PyWebView for native window
4. Add system tray with pystray
5. Bundle with PyInstaller
6. Create installer wrapper

### Estimated Development Time
- 3-4 sessions

---

## Option 4: Native .NET (WPF/MAUI)

**Effort Level:** High  
**Package Size:** ~15-30MB (framework-dependent) or ~80MB (self-contained)  
**Native Feel:** Highest

### Description
Complete rewrite as a native Windows application using .NET WPF or .NET MAUI. Would provide the most polished Windows experience but requires significant development effort.

### Architecture
```
BPQ-Dashboard.exe (.NET)
├── WPF/MAUI UI (native Windows controls)
│   ├── Dashboard views (XAML)
│   ├── Charts (LiveCharts2 or OxyPlot)
│   └── Data grids
├── Backend services
│   ├── Log file parser service
│   ├── BBS TCP client service
│   ├── Solar data service
│   └── Local storage service
└── Windows integration
    ├── NotifyIcon (system tray)
    ├── Task Scheduler integration
    └── Windows notifications
```

### Pros
- Smallest package size (framework-dependent deploy)
- Best Windows integration and performance
- Native look and feel
- Lowest memory usage
- Best startup time
- Could potentially add features not possible in web UI

### Cons
- Complete rewrite required (highest effort)
- Loses cross-platform capability
- Must recreate all UI in XAML
- Must reimplement all backend logic in C#
- Charts/visualizations need new libraries
- Longer development and testing cycle

### Implementation Steps
1. Design application architecture
2. Create data models and services
3. Build log parsing engine
4. Implement BBS TCP client
5. Design and build WPF views
6. Implement charting
7. Add system tray and notifications
8. Create installer (MSIX or traditional)
9. Extensive testing

### Estimated Development Time
- 8-12 sessions

---

## Comparison Summary

| Aspect | Electron | Portable Bundle | Python+PyWebView | Native .NET |
|--------|----------|-----------------|------------------|-------------|
| **Effort** | Medium | Low | Medium | High |
| **Package Size** | 150-200MB | 30-50MB | 50-80MB | 15-80MB |
| **Native Feel** | High | Medium | High | Highest |
| **Code Reuse** | ~60% | ~98% | ~60% | ~10% |
| **Maintenance** | Medium | Easy | Medium | Medium |
| **Performance** | Good | Good | Good | Best |
| **Dev Sessions** | 4-6 | 2-3 | 3-4 | 8-12 |

---

## Recommendation

**For quick implementation:** Option 2 (Portable Bundle)
- Minimal changes to existing codebase
- Fastest time to working product
- Easy to update and maintain
- Good balance of features vs effort

**For polished native app:** Option 1 (Electron) or Option 3 (Python)
- If team knows JavaScript → Electron
- If team knows Python → PyWebView
- Both provide true native window experience

**For ultimate Windows integration:** Option 4 (.NET)
- Only if long-term Windows-only focus
- Significant investment but best result

---

## Next Steps (When Ready to Implement)

1. Choose implementation option
2. Set up development environment
3. Create proof-of-concept launcher
4. Build config wizard
5. Test Task Scheduler integration
6. Package and test installer
7. Documentation updates
8. Beta testing with users

---

## Notes

- All options should preserve the existing web-based dashboard as the primary distribution
- Windows EXE would be an additional distribution option, not a replacement
- Consider maintaining feature parity between web and EXE versions
- Auto-update mechanism should be considered for any option
