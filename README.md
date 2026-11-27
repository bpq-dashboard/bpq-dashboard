# BPQ Dashboard

**Version 1.6.0** · A modern web interface for amateur radio packet nodes running LinBPQ / BPQ32

[![License: GPL v3](https://img.shields.io/badge/license-GPLv3-blue.svg)](LICENSE)
[![Platform: Debian / Ubuntu / Raspberry Pi](https://img.shields.io/badge/platform-Linux-orange.svg)]()
[![PHP 8.x · Python 3.x](https://img.shields.io/badge/stack-PHP%208%20%C2%B7%20Python%203-brightgreen.svg)]()

## Why it was created

BPQ Dashboard was built so a sysop can see and run a whole BPQ station from one web page, on any device, without a terminal.

LinBPQ is superb packet software, but looking after a node day to day means hopping between places: the telnet console, the BBS, log files, the VARA and sound-modem windows on the desktop, the rig control program, and the Linux command line. Answering simple questions (Who connected overnight? Did the forwarding to my partners run? Is VARA busy? How is the band?) often takes several logins and some digging.

BPQ Dashboard started as a way to put all of that in one place. It grew page by page from what a working sysop actually needed, and from the reports of the sysops who tried it. The goal throughout has been the same: make running a BPQ station easier, and make the dashboard itself painless to install and keep up to date.

## What it is

BPQ Dashboard is a free, web-based control panel for LinBPQ, the Linux edition of John Wiseman G8BPQ's BPQ32 node, BBS and chat software. It runs on the same computer as LinBPQ, alongside it rather than in place of it. It reads LinBPQ's logs and talks to it over its telnet port, so your existing configuration stays as it is.

You open it in any web browser: a desktop, a laptop, a tablet or a phone. Visitors can see the public pages. Sysop pages and anything that acts on the station sit behind an admin login.

## What it offers

The menu groups the pages by what a sysop does. Pages your station doesn't use, such as Rig control without flrig, can be switched off and drop out of the menu.

| Menu | What you can do there |
| --- | --- |
| Station | See the node at a glance: who is connected, recent connections, the nodes and routes LinBPQ knows, and the reasons behind disconnects (Node Debug) |
| Mail | Read and send BBS mail in a modern mail client (BBS Mail: folders, select-and-delete, a choice of fonts), follow forwarding and traffic reports, check NTS traffic and the White Pages |
| On air | Use a telnet client with YAPP file transfers, BPQ Chat, and a VARA HF terminal from the browser; see RF connections from the VARA log |
| Radio | Control the rig through flrig and watch RF power |
| Weather | Local weather and conditions for the station |
| Admin | Watch the VARA HF, Mercury, Data2G and QtSoundModem modems without going to the desktop (watch-only), run maintenance tasks with copy-and-paste commands, audit the system, manage the firewall, read the logs, and run Install Check and Health Check |

An APRS map and beacon, and BBS mail through any standard mail app (IMAP), are there too if you want them.

## Easy to install and keep current

You don't need to be a Linux expert. A guided installer asks about a dozen plain questions (your callsign, node, location, telnet port and admin login) and does the rest, in about 15 to 30 minutes.

- **It checks the computer first.** Before it changes anything, the installer confirms your Linux release is supported and its software is new enough. If it isn't, it says why and stops, leaving the system untouched.
- **It keeps the system safe.** It offers to install waiting security updates and to turn on automatic security updates.
- **Updates are one command.** `sudo bash update.sh` brings a station up to the new version and keeps its settings.
- **It tells you when something is wrong.** Install Check and Health Check show what's working and what isn't, with the command to fix each problem.
- **It's documented.** A User Guide and a full Manual come with every release, as web pages and PDFs.

To get started, download the latest release from this repository's **Releases** page, unzip it, and run `sudo bash install.sh` (see *System requirements* below). Questions, ideas and bug reports are welcome. BPQ Dashboard has grown from sysops' feedback, and that's how it will keep improving.

73, Tony K1AJD


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
- **LinBPQ Status** (v1.5.94) — Admin page: is LinBPQ running and how (systemd, screen), its starts, stops and crashes (segfaults, core dumps) over 30 days, and a live view of its console
- **Node Debug** (v1.5.95–1.5.96) — Admin page that reads LinBPQ's `NodeDebuglog` for you: restarts and *why* each happened (Connect Watchdog, a backup or other job, a user or root login, a reboot or a crash, with troubleshooting for unknown ones), neighbour links dropped, internet and Winlink server outages, Winlink channel reports, known problems explained, and a live view of today's log
- **Modems** (v1.5.97) — Admin page with each modem's own display without opening the station's desktop, watch-only: VARA HF, flrig and other modem windows as live pictures, Mercury's spectrum and waterfall, Data2G's waterfall, constellation, link and bursts
- **HF Channel Quality per modem** (v1.5.96) — RF Connections cards per band for VARA, Data2G and Mercury
- **White Pages addresses** (v1.5.96) — the To field in BBS Mail and the Email Client suggests any station your BBS knows from LinBPQ's White Pages, with its home BBS
- **Radio settings for the Yaesu FTdx-3000** (v1.5.60) — on Rig Status, through flrig: live ALC / power / SWR / compression meters, and width, roofing filter, IPO, attenuator, AGC, noise blanker, noise reduction and notch, each with a hint for data use; changes need the sysop password and wait while a modem is transmitting. The card appears only when flrig reports an FTdx-3000

See the [User Guide](BPQ-Dashboard-v1.6.0-User-Guide.pdf) for detailed feature walkthroughs.

## Quick links

| Document | What it covers |
|---|---|
| 📖 **[User Guide](BPQ-Dashboard-v1.6.0-User-Guide.pdf)** | How to use every feature — for sysops, operators, visitors |
| 🔧 **[Installation & Troubleshooting Manual](BPQ-Dashboard-v1.6.0-Manual.pdf)** | Setting up the dashboard from scratch + diagnosing issues |
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
wget -O bpq-dashboard.zip https://github.com/bpq-dashboard/bpq-dashboard/archive/refs/tags/v1.6.0.zip
unzip bpq-dashboard.zip && cd bpq-dashboard-1.6.0
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

- **OS:** Debian 12 or 13, Ubuntu 22.04 or 24.04 LTS, Raspberry Pi OS Bookworm or Trixie, Linux Mint 21 / 22 / LMDE (or another system built on these). The installer checks the computer first (`sudo bash tools/system-check.sh` does it on its own) and stops on releases that are too old (Debian 11, Ubuntu 20.04)
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
