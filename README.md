# BPQ Dashboard

**Version 1.5.93** · A modern web interface for amateur radio packet nodes running LinBPQ / BPQ32

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
- **BBS Mail** (v1.5.71–1.5.79) — an email page laid out like Thunderbird: folders and subfolders, sortable columns, quick filter and search, tabs, keyboard shortcuts, an address book with vCard/CSV, message filters, drafts, Quick Check-In, NTS and bulletin sending, bulletin retention, and Tools (forwarding activity, BBSes seen, statistics). It reads an optional **mail store** (Dovecot, `tools/bbs-mail-setup.sh`, offered by the installer and update.sh) that also puts your BBS mail in Thunderbird or a phone's mail app on your home network; deleting from Trash kills the message on the BBS. Works on iPad and phones. Address suggestions as you type, callsigns first (v1.5.89); the mail store works with Dovecot 2.4 (v1.5.84)
- **Winlink mail** (v1.5.77) — mail LinBPQ's BBS collects from Winlink (*Poll RMS*) is marked Winlink, shows the sender's address and can be answered through Winlink (`rms:user@domain`); other accounts (a club or tactical call) get their own folder
- **Safe sending** (v1.5.76) — a send interrupted by a dropped connection (an iPad locking its screen) is checked with the server instead of failing, and can't post twice
- **System Audit** — automated health checks for daemons, certs, security, resources
- **Connect Watchdog** — pauses a forwarding partner that stops answering; shares LinBPQ restarts and never restarts during a radio session (v1.5.81). System Audit also flags forwarding scripts whose `TIMES` bands leave hours with no forwarding (v1.5.82). Pause length, attempts before a pause and the never-pause list are set in ⚙ Dashboard Settings → Watchdog (v1.5.90)
- **Nightly station backups (optional, recommended)** — `scripts/bpq-backup-setup.sh` backs up LinBPQ, the databases, the dashboard and the system settings to a separate drive every night (encrypted, with restic) and shows the result on the Maintenance page
- **RF Connections, redesigned (v1.5.44)** — status strip, headline numbers, partner rows with a 7-day strip, modems and conditions side by side, details in tabs, light and dark
- **Messages, Traffic, Connects and System Logs, redesigned (v1.5.86–1.5.88)** — the same layout as RF Connections: a status strip, headline cards, partner or station tables with day blocks, tabs and a detail panel for any callsign. Messages counts only messages that really moved, shows the forwarding queue and flags stuck partners, and loads in seconds
- **Station report for every modem (v1.5.85)** — the RF Connections PDF report covers VARA HF, Mercury, Data2G and VHF packet, together or one at a time
- **Winlink sessions reported accurately (v1.5.91–1.5.92)** — on VARA, Data2G and VHF, a call is labelled WL2K only when LinBPQ's gateway log confirms it, with the frequency the radio actually transmitted on (from flrig's history); VHF rows show the port's frequency
- **Quieter BBS log (v1.5.90)** — saving message text lists only new messages, so forwarding sessions stay easy to find in LinBPQ's log
- **Mercury HF modem** — for stations running the open-source Mercury modem beside VARA as a LinBPQ port: a status card on RF Connections, its calls beside the VARA sessions (v1.5.46), checks in System Audit, and a setup guide (Manual §10.17)
- **Data2G HF modem** (v1.5.56) — the same for the open-source Data2G modem: a status card, its calls (with S/N) beside the VARA sessions and on the Station Map, System Audit checks, a desktop monitor-page script (`tools/data2g-monitor.sh`), and a setup guide (Manual §10.18)
- **Forwarded messages by modem** (v1.5.82) — messages sent or received over Mercury or Data2G are labelled as such, not as VARA
- **Modem performance** (v1.5.63) — RF Connections tab comparing VARA HF, Mercury and Data2G over 7, 14 or 30 days: success rate, how calls ended, S/N, success by band, a timeline of every connection against the planetary K index, a day-by-day table with NOAA's A index, a head-to-head of the same station on the same band, and findings worked out from your numbers
- **Throughput you can compare** (v1.5.64) — each modem's own rate figure is labelled for what it is (VARA's peak speed level, Data2G's best minute), and an **Effective** throughput (bytes × 8 over the time connected) is worked out the same way for every modem
- **VHF paths** (v1.5.50) — hour-by-hour history of when watched stations were heard, with optional scheduled test connects; VHF packet connects listed beside the HF sessions (v1.5.51)
- **Packet Live NetRom/Node Map** (v1.5.62) — the UK Packet Radio Network's live node map, in an RF Connections tab
- **Audio Map** (v1.5.57) — Admin page that draws, from the running system, how each LinBPQ radio port reaches its radio: modem program, shared ALSA device (dmix/dsnoop), sound card with mixer levels, and what keys the transmitter
- **Radio settings for the Yaesu FTdx-3000** (v1.5.60) — on Rig Status, through flrig: live ALC / power / SWR / compression meters, and width, roofing filter, IPO, attenuator, AGC, noise blanker, noise reduction and notch, each with a hint for data use; changes need the sysop password and wait while a modem is transmitting. The card appears only when flrig reports an FTdx-3000

See the [User Guide](BPQ-Dashboard-v1.5.93-User-Guide.pdf) for detailed feature walkthroughs.

## Quick links

| Document | What it covers |
|---|---|
| 📖 **[User Guide](BPQ-Dashboard-v1.5.93-User-Guide.pdf)** | How to use every feature — for sysops, operators, visitors |
| 🔧 **[Installation & Troubleshooting Manual](BPQ-Dashboard-v1.5.93-Manual.pdf)** | Setting up the dashboard from scratch + diagnosing issues |
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
wget -O bpq-dashboard.zip https://github.com/bpq-dashboard/bpq-dashboard/archive/refs/tags/v1.5.93.zip
unzip bpq-dashboard.zip && cd bpq-dashboard-1.5.93
sudo bash install.sh
```

The guided installer sets up everything:

- the web server, with private files locked away and admin pages behind a password
- PHP, Python and the MariaDB database
- your `config.php`
- the link to your LinBPQ logs
- the helper services
- the log parsers and the daily bulletin clean-up

Then open `http://<your-server-ip>/`, run the self-test at `http://<your-server-ip>/install-check.php` (it asks for the admin login you chose during install), and check the helper services for the pages you chose (guide, step 9).

Prefer to do it by hand? See *Part B — Manual install* in the same section.

**Updating** (v1.5.19 and later; works on older installs too): unzip the new release, then in its folder

```bash
sudo bash update.sh --dry-run    # what it will change
sudo bash update.sh              # update in place; prints the command that undoes it
```

Your settings, data, database and web-server config are kept, and every file it replaces is backed up first.

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
