# BPQ Dashboard

**Version 1.5.16** · A modern web interface for amateur radio packet nodes running LinBPQ / BPQ32

[![License: GPL v3](https://img.shields.io/badge/license-GPLv3-blue.svg)](LICENSE)
[![Platform: Debian / Ubuntu / Raspberry Pi](https://img.shields.io/badge/platform-Linux-orange.svg)]()
[![PHP 8.x · Python 3.x](https://img.shields.io/badge/stack-PHP%208%20%C2%B7%20Python%203-brightgreen.svg)]()

BPQ Dashboard turns your packet radio node into a friendly, browser-accessible operations suite. Monitor connections, manage email-style BBS messages, transfer files via YAPP, run VARA HF keyboard sessions, view APRS traffic, watch weather alerts, and audit system health — all from any device on your network.

## What's in the box

- **17 web pages** organized by purpose: communications, messages, monitoring, admin
- **8 background daemons** providing live data: telnet, chat, VARA, APRS, etc.
- **Database-backed pages** — RF Connections, Traffic and System Logs load 10-15× faster from MariaDB (set up automatically by the installer)
- **Browser-based file transfer** via YAPP — including a Browse Files mode for clickable downloads
- **Day/night theme toggle** with light mode on every page
- **NWS Weather alerts** with nationwide severe weather coverage, and optional automatic bulletins: new Warnings and Watches for your state posted to the BBS (e.g. `WX@GA`) every 5 minutes
- **APRS map** with messaging, weather popups, your BBS's station info, and an optional beacon that puts the BBS on aprs.fi
- **RF Connections analytics** — band activity heatmaps, propagation scoring, station map
- **Full BBS message client** with NTS folder, address book, custom folders
- **System Audit** — automated health checks for daemons, certs, security, resources

See the [User Guide](BPQ-Dashboard-v1.5.16-User-Guide.pdf) for detailed feature walkthroughs.

## Quick links

| Document | What it covers |
|---|---|
| 📖 **[User Guide](BPQ-Dashboard-v1.5.16-User-Guide.pdf)** | How to use every feature — for sysops, operators, visitors |
| 🔧 **[Installation & Troubleshooting Manual](BPQ-Dashboard-v1.5.16-Manual.pdf)** | Setting up the dashboard from scratch + diagnosing issues |
| 🧭 **[Installation guide](USER-MANUAL.md#4-installation--step-by-step)** | Step-by-step install for newcomers, plus manual install and updating |
| ⚡ **[Phase 1 Deployment Guide](docs/PHASE-1-DEPLOYMENT.md)** | Background on the DB-backed RF Connections (done automatically by `install.sh`) |
| ⚡ **[Traffic Report Deployment Guide](docs/TRAFFIC-STATS-DEPLOYMENT.md)** | Background on the DB-backed Traffic Report (done automatically by `install.sh`) |
| 📋 **[CHANGELOG](CHANGELOG.md)** | What's new in each release |

## Quick install

**New to Linux? Follow the step-by-step guide:** [USER-MANUAL.md, section 4](USER-MANUAL.md#4-installation--step-by-step). It lists every question the installer asks, where to find each answer, and what to do afterwards.

On the computer that already runs LinBPQ:

```bash
sudo apt update && sudo apt install -y wget unzip
cd ~
wget -O bpq-dashboard.zip https://github.com/bpq-dashboard/bpq-dashboard/archive/refs/tags/v1.5.16.zip
unzip bpq-dashboard.zip && cd bpq-dashboard-1.5.16
sudo bash install.sh
```

The guided installer sets up everything:

- the web server, with private files locked away and admin pages behind a password
- PHP, Python and the MariaDB database
- your `config.php`
- the link to your LinBPQ logs
- the helper services
- the log parsers and the daily bulletin clean-up

Then open `http://<your-server-ip>/`, run the self-test at `http://<your-server-ip>/install-check.php` (it asks for the admin login you chose during install), and switch on the helper services you want (guide, step 9).

Prefer to do it by hand? See *Part B — Manual install* in the same section. Upgrading? Run the new version's `install.sh` and answer **Y** to the backup question; your database is kept.

## System requirements

- **OS:** Debian 11+ / Ubuntu 20.04+ / Raspberry Pi OS Bullseye+ (any recent Debian-based system)
- **Hardware:** Raspberry Pi 3/4/5 or any modern x86 box. 1 GB RAM minimum, 2 GB recommended.
- **LinBPQ:** Already installed and running (the dashboard reads its logs and connects via telnet)
- **MariaDB** — installed for you by `install.sh`; required by the Email Client and the database-backed pages

## Architecture in one diagram

```
┌──────────────────────────────────────────────────────────────────────┐
│                          Your web browser                            │
│       (any device — phone, tablet, laptop, desktop)                  │
└─────────────────────────────────┬────────────────────────────────────┘
                                  │ HTTPS
┌─────────────────────────────────▼────────────────────────────────────┐
│                Linux server running LinBPQ                           │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │  nginx + PHP-FPM    →  17 dashboard pages, 25 PHP APIs       │    │
│  ├──────────────────────────────────────────────────────────────┤    │
│  │  Python daemons:                                              │    │
│  │    bpq-telnet   ─ Telnet Client + YAPP transfers (WebSocket) │    │
│  │    bpq-chat     ─ BPQ Chat bridge                            │    │
│  │    bpq-vara     ─ VARA HF Terminal bridge                    │    │
│  │    bpq-aprs     ─ APRS-IS feed, map data, BBS beacon         │    │
│  │    vara-validator ─ VARA callsign allowlist                  │    │
│  ├──────────────────────────────────────────────────────────────┤    │
│  │  LinBPQ ─ the actual packet node software                    │    │
│  │    • Telnet port (e.g. 8010)  → BPQ Node interface           │    │
│  │    • FBB port (e.g. 8011)     → Raw binary for YAPP          │    │
│  │    • Daily log files          → Read by dashboard pages       │    │
│  └──────────────────────────────────────────────────────────────┘    │
└────────────────────────┬─────────────────────────────────────────────┘
                         │ RF
                ┌────────▼─────────┐
                │  Radio + VARA HF │
                │  + flrig         │
                └──────────────────┘
```

## Security

The dashboard can send mail, post bulletins and transmit as your station, so reach it over HTTPS with the admin login and never open it to the internet without both. Run Install Check after every install or upgrade. See [SECURITY.md](SECURITY.md) for safe-deployment notes (including the IPv6 firewall check) and how to report a problem privately.

## Contributing

Bug reports and feature requests welcome via GitHub Issues. Pull requests welcomed too — see the User Manual for the architectural overview.

## Credits

BPQ Dashboard runs on top of other people's work: **LinBPQ / BPQ32** by John Wiseman **G8BPQ**, the **flrig** rig-control suite by Dave Freese **W1HKJ**, and the **PKTNET / TPRFN HTML Forms** by Glenn Allison **N3MEL**. See [CREDITS.md](CREDITS.md) for the full list.

## License

Released under GPLv3 — free for amateur radio use, modification, and redistribution. Some included third-party libraries have their own licenses (see `LICENSES/` directory).

---

**73 — open source, free, and built for ham radio operators.**
