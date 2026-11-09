# BPQ Dashboard

**Version 1.5.33**
**User Manual & Installation Guide**
*Amateur Radio Packet Node Operations Suite*

| | |
|---|---|
| Platform | Linux — Debian / Ubuntu / Raspberry Pi |
| Requirements | LinBPQ running · nginx or Apache · PHP 8.x |
| Target audience | Amateur radio operators — no prior Linux experience needed |
| Updated | October 2026 |
| Repository | https://github.com/bpq-dashboard/bpq-dashboard |

---

## Table of Contents

1. What Is BPQ Dashboard?
2. What's New
3. Before You Start
4. Installation — Step by Step
    - Part A: The guided installer (recommended) — steps 1–11
    - Updating to a newer version
    - Part B: Manual install (experienced users)
5. Testing Your Installation
6. Dashboard Pages Reference
7. The Shared Navigation & Day/Night Theme
8. YAPP File Transfer
9. VARA HF Terminal Setup
    - 9b. Supported Radios — flrig Profiles
10. Optional Features
    - 10.1 Connect Watchdog
    - 10.2 Propagation Scheduler
    - 10.3 Storm Monitor
    - 10.4 NWS Weather Alerts
    - 10.5 RF Power Monitor (FLRIG-based)
    - 10.6 Data Archival
    - 10.7 PTT Indicator and Remote Power Control
    - 10.8 RF Connections database optimization
    - 10.9 VARA HF Callsign Validator
    - 10.10 Winlink White Pages cleanup tools
    - 10.11 VARA log collection
    - 10.12 NetROM RTT collection
    - 10.13 VHF/UHF packet with a sound modem (QtSoundModem)
    - 10.14 Winlink: an RF gateway as the outage route
    - 10.15 VARA HF under Wine (no Windows PC)
11. Security Hardening
12. Troubleshooting
13. Quick Reference

---

## 1. What Is BPQ Dashboard?

BPQ Dashboard is a web-based monitoring and operations suite for amateur radio packet nodes running BPQ32 or LinBPQ. It runs on the same Linux computer as your packet node and gives you a rich browser interface to monitor connections, manage email-style BBS messages, view logs, track RF power, transfer files over packet via YAPP, run VARA HF keyboard sessions, and more — all from any device on your local network.

You access it by opening a web browser and going to your node's IP address. No software to install on the viewing device — it works on any phone, tablet or computer.

| Feature | Description |
|---|---|
| RF Connections | VARA HF sessions, forwarding partners and their queues, forwarded messages (how they travelled, delivered or rejected), propagation, best bands and a station map. The home page |
| Chat Client | Browser window onto your BPQ chat server, through the station's own chat connection |
| VARA HF Terminal | Keyboard-to-keyboard HF sessions over your BPQ VARA port, from the browser |
| Telnet Client | Terminal on your node with a NetROM nodes list, command reference and **YAPP file transfer** |
| Email Client | Webmail-style client for BBS mail: Personal, NTS, bulletins, folders, rules, 📌 keep, address book; everything saved in the database |
| Message Monitor | BBS and BPQ mail-server activity from the daily logs, with a security view |
| System Logs | Statistics and a searchable viewer for BPQ's daily logs, plus MHeard and the routing table |
| Traffic Report | Per-station traffic from BPQ's traffic reports (7 days, 4 or 12 weeks) |
| Connect Log | Incoming connects by mode (NET/ROM, VARA, AX.25, Telnet), with a map |
| Rig Status | Frequency, mode, S-meter, PTT, TX power and the scan list, through flrig; optional network power switch |
| RF Power Monitor | Power and SWR history read from the radio through flrig (no separate wattmeter) |
| APRS Map | APRS-IS stations around you, messaging, your BBS's station info and an optional aprs.fi beacon |
| NWS Weather | National Weather Service alerts, nationwide severe weather, and posting alerts to your BBS |
| Maintenance | Copy-ready sysop commands, checks and the forwarding-partners editor |
| Firewall Status | What the internet can reach, IPv4 and IPv6, with fail2ban |
| Log Viewer | Recent lines of, and search through, the dashboard, parser, VARA, BPQ and web-server logs |
| System Audit | Automated check of security, resources, web server, database, services and timers |
| Day/Night Theme | Moon/sun button in the menu |

How to use each page is in the **User Guide** (`USER-GUIDE.md`, and the 📖 Guide PDF in the menu). This manual covers installing, configuring, the optional tools and troubleshooting.

---

## 2. What's New

### In v1.5.33

- **Email Client:** marking messages read now always clears the folder's red unread number (database-only copies were left unread before); the Bulletins view also shows each group's database-only bulletins.

### In v1.5.32

- **Email Client → ⚙ Properties:** one menu at the top of the page with every Email Client option (mail, view, folders, contacts, settings, help). Messages now sort newest first by their date, so a new message no longer lands at the bottom of a 📌 folder.

### In v1.5.31

- **Email Client:** messages killed on the BBS (copies kept in 📌 folders) are hidden unless Preferences → *Show messages killed on the BBS* is ticked; the list says how many are hidden. Fixed "undefined" in place of the message type on some saved messages.

### In v1.5.30

- **RF Connections → Failures:** a call nobody answered (VARA's 15 tries with no "Connected to") now counts as **Timeout (no prop)** instead of **No Data**; No Data is left for a link that came up and carried nothing. Earlier sessions are moved by the update. VARA's "Disconnected (Timeout)" line is now read too (such sessions used to stay open until the next one).

### In v1.5.29

- **RF Connections → VHF Connections Status lists the stations heard** on each sound-modem port in the page's reporting period (Today = 00:00Z to now, or 7 / 30 / 90 days), with their UTC times. It follows the Today / 7 / 30 / 90 Days buttons. Each station appears once, at the last time BPQ heard it.

### In v1.5.28

- **RF Connections → VHF Connections Status:** the sound-modem status (on air / LinBPQ not connected / modem not running, last heard, watched stations) is now a card beside RF Connections Status instead of a badge in the status box.
- **§10.15 (VARA HF under Wine) after a real reboot test:** a corrected start-up script (it doesn't hand its lock to flrig and VARA, and stops with its desktop); one way to start the VNC desktop; clean-up on stop; LinBPQ waits for flrig at boot; older sound-driver settings that override the card numbers; flrig's garbled "separate PTT port" setting.

### In v1.5.27

- **New §10.15 *VARA HF under Wine (no Windows PC)*:** run VARA HF on the dashboard's server — interface and fixed device names, sound-card numbering, flrig as the only program on the CAT port (CAT-only PTT; the mixed-PTT setting that leaves a radio transmitting), the Wine prefix and the full VARA setup (MSCOMM32.OCX), LinBPQ and dashboard settings, starting at boot, testing, and the way back. Maintenance → Rig Status & RF Power Monitor has matching checks.

### In v1.5.26

- **Watched stations and live MHeard on the Maintenance page** (*Watched stations* section): add or remove stations, set the port and the hours, see when each was last heard, switch live MHeard on or off. Saved in `data/system-logs.json`; `config.php` is not written by the page (it holds passwords). A watch list already in `config.php` (v1.5.25) keeps working until the page saves one.

### In v1.5.25

- **Live MHeard:** System Logs' MHeard card is no longer as of LinBPQ's last restart. Every 5 minutes the dashboard reads the node's `MHU` list for each port (with `bbs.user` / `bbs.pass`) and merges it with `MHSave.txt`. Off switch: `'system_logs' => ['live_mheard' => false]`.
- **Watched stations:** a listen-only check for stations you depend on (e.g. a Winlink gateway used as a backup, §10.14): `'system_logs' => ['watch' => [['call' => 'GATEWAY-10', 'port' => 4, 'hours' => 6]]]` in `config.php`. System Logs shows each one heard / ⚠ quiet; a watched station on a sound-modem port also appears under its RF Connections' VHF Connections Status card. Nothing is transmitted.

### In v1.5.24

- **RF Connections → Forwarded Messages** shows your BBS's Winlink (CMS) forwards. They were never listed: BPQ logs them in Winlink's B2F form, which the parser didn't read. A new Via choice **📮 Winlink (CMS)** lists them, with the route in the *To* column (`WINLINK@CMS` = internet, `WINLINK@<gateway>` = through an RF gateway, §10.14). Earlier forwards appear after a re-scan: `sudo -u www-data python3 <web root>/scripts/parse-vara-log.py --bbs-log-dir <web root>/logs --my-callsign YOURCALL --rescan-messages-days 30`.

### In v1.5.23

- **Installation Manual:** new §10.13 (VHF/UHF packet with QtSoundModem and a Digirig) and §10.14 (a Winlink RF gateway as the outage route); Maintenance page section *Sound modem*.
- **RF Connections:** a status badge for each LinBPQ port that uses a sound modem over KISS TCP (QtSoundModem, Direwolf): on air / LinBPQ not connected / modem not running, and the last station heard on it. Nothing to set up: the ports come from `bpq32.cfg`.

### In v1.5.22

- **System Logs MHeard tabs** are your own LinBPQ ports, named from `bpq32.cfg` (`ID=`), instead of five fixed tabs; ports above 5 are shown too. Optional `config.php` → `'system_logs' => ['port_names' => […]]` when the web server can't read `bpq32.cfg`.
- **`update.sh`** runs the database files as MariaDB's root over the local socket (it runs under sudo). Stations whose dashboard database user can't create or alter tables no longer get "didn't apply cleanly" warnings.

### In v1.5.21

Requests and a report from N3MEL:

- **System Logs** no longer stops with "Maximum call stack size exceeded" on large logs (seen on iPad/Safari).
- **RF Connections:** click a Failures row (Timeout, Channel Busy, No Data, Port in Use, Incoming Failed) to list those sessions in Recent Connections.
- **Email Client:** Quick Check-In can send as a personal message (SP) to a net manager instead of the SB bulletin; message texts are read up to 10 per BBS login, so Get Mail with Eager body fetching is much quicker on a slow BBS.

### In v1.5.20

- **Maintenance page:** the *Check for a new release* and PKTNET *Check again* buttons work (they answered "Unknown action").
- **`update.sh`:** the dry run also lists the systemd units and sudo rules it would refresh; an update never adds the admin-password sudo rule by itself; a keep list (`/etc/bpq-dashboard/update-keep.txt`) or `--keep-local` keeps files you run in your own version.

### In v1.5.19

From the first Linux Mint and Ubuntu installs (N3MEL, KG9NI) and requests from sysops (K5DAT and others):

- **Choose your pages.** The installer asks which optional pages the station uses (Telnet Client, Chat, APRS map, VARA HF Terminal, VARA callsign check; Rig Status and RF Power Monitor follow the flrig question). Only their helper services are installed and started; the others are hidden from the menu (`config.php 'pages'`, `'radio' => 'enabled'`) and can be switched on later (Maintenance → *Optional pages*). The Rig pages are no longer deleted when you skip flrig.
- **Web server and port of your choice** (thanks K5DAT). nginx or Apache, on port 80 or its own port. On a machine that already serves other sites (for example nginx as a reverse proxy), existing sites, the default site and port 80 are left alone, `/etc/nginx` is backed up first, and a reverse-proxy example is written to `/root/bpq-dashboard-reverse-proxy.txt` (§4, *Step 5*).
- **LinBPQ in a home folder** (Ubuntu 21.04+, Mint 21+): the installer checks that the web server can reach the LinBPQ folder and its logs, and offers the narrow access fix (ACLs). Install Check prints the commands for your path.
- **The dashboard's own logs** (bpq-telnet, bpq-vara, Email Client, message-text job, NWS posting, forwarding tools) are written to `/var/log/bpq-dashboard/`, not LinBPQ's logs folder; on stations where the web user could only read that folder, bpq-telnet and bpq-vara used to stop at start. Weekly log rotation is installed.
- **VARA log from the TPRFN server:** TPRFN stations whose VARA HF sends its syslog to the TPRFN server can have the dashboard download it every 5 minutes (`vara-remote.timer`, §10.11) instead of running a receiver.
- **Firewall, optional:** `tools/setup-firewall.sh` sets up ufw with a question per port and never locks out your SSH session; the installer offers it at the end (default No). See §11.
- **In-place updates:** every release from now on has `update.sh`: `sudo bash update.sh` in the unzipped new release updates your install without a reinstall, keeps your settings and data, backs up what it replaces, and prints a one-line rollback (§4, *Updating*). Maintenance shows when a new release is out.
- **Maintenance page:** *Change admin password* (no SSH needed), *PKTNET forms* update with Undo, *Optional pages*, *Firewall setup* and *LinBPQ in a home folder* sections.
- **Email Client signature** (thanks N3MEL): Preferences → Signature, added to new messages (and optionally replies), kept on the server.
- **vara-validator** runs on any station: as the web user from the dashboard folder, settings in `config.php 'vara_validator'` (§10.9).
- **Clearer empty pages:** Traffic and Connect Log say why they're empty (no weekly report yet; `LogAllConnects=1` missing), and Install Check has rows for both. The installer loads the Traffic reports already on the machine. Install Check suggests PHP packages for the PHP version you run.

### In v1.5.18

From the first Linux Mint install (thanks to N3MEL for the testing):

- **Email Client, message text job and forwarding queue log in on every BBS.** They waited for a `}` in BPQ's greeting after the password and reported *"Login failed"* when it never came. One shared login (`includes/bbs-login.php`) now accepts a login that lands at the node or straight in the BBS, whatever the greeting, and a failure says which step went wrong and shows BPQ's last line.
- **Telnet Client uses your BPQ ports.** The Node, BBS and Chat buttons, the Custom port and file-transfer mode use `bbs.port` from `config.php` and `FBBPORT=` from `bpq32.cfg` (or the new optional `bbs.fbb_port`) instead of 8010 / 8011, and the `bpq-telnet` service allows those ports.
- **Traffic Report finds BPQ's reports.** BPQ writes `Traffic_YYMMDD.txt` in the LinBPQ folder; the parser now looks there (`paths.linbpq`) as well as in `logs/`, and says where it looked when it finds none.
- **System Audit is more accurate:** LinBPQ running as `linbpq64` or `pilinbpq` is recognised (Install Check too); a dashboard service you switched off is *Info*, not *Critical*; the local-only ports `ssh -X` opens (6010+) aren't reported as SSH; the Maintenance page's own login check isn't counted as nginx errors; open station ports are reported as *open to every network (check your router)*.

### In v1.5.17

- **Log Viewer opens every log.** Message text job, Email Client (BBS) and Dashboard log failed with *"JSON.parse: unexpected end of data"*: they hold raw BBS text, and one byte that wasn't valid UTF-8 made the server send an empty reply. Such bytes now show as **�** and the rest of the log reads normally. If a log still can't be read, the page shows what the server sent back instead of a bare JSON error.

### In v1.5.16

- **Fresh installs: admin pages and PHP work first time.** The v1.5.15 installer left the admin login file readable by root only, so every page behind the admin login answered **500**, and on some systems (seen on Ubuntu with PHP 8.5) it pointed nginx at a PHP socket that didn't exist, so PHP pages answered **502**. The installer now sets the login file to `root:www-data 640`, writes your PHP version's real socket, and tests both before it finishes. Install Check and System Audit flag either problem; §12 has the fixes by hand. Thanks to KG9NI for the report.

### In v1.5.15

- **VARA HF log for RF Connections, set up by the installer:** a new question sets this server up to receive VARA HF's session log as syslog (rsyslog on UDP 514, from the VARA computer only), installs rsyslog where it's missing (Debian 12), and starts `vara-logger`. §10.11 explains the VARA HF side (Settings → VARA Setup → Syslog) step by step.
- **Fresh installs on Debian 12 / Ubuntu 24.04:** RF Connections no longer stays empty — `vara-logger` converts the new rsyslog timestamps to the form the parsers read. Install Check shows whether the VARA log is arriving.

### In v1.5.14

- **The admin login guards everything that acts as your station** — Email Client, Chat, NWS posting, APRS sending, map saves, the admin pages, Install Check and Health Check — and the PHP refuses those actions itself unless the web server checked the login, so an old or hand-made web-server config fails closed. Kill and Forward are POST requests, other web sites can no longer call the dashboard from a browser unless you list them (`cors.allowed_origins`), and the Settings password is rate-limited. See `SECURITY.md`.
- **Email Client:** message text is saved on the server every 5 minutes, even with the page closed (BPQ can remove bulletins within days), messages open in a pop-up window, and columns can be resized.
- **RF Connections:** Forwarded Messages shows how each message travelled (VARA, NET/ROM, AXIP, Telnet, AX.25), whether the partner accepted, rejected or deferred it, and when it arrived, with one row per partner; counts are deliveries, not offers. The failure counts now add up and match System Logs.
- **APRS Map:** your BBS's station info, and an optional beacon that puts the BBS on aprs.fi.
- **Rig Status / RF Power Monitor:** radio model detection and meter readings for Icom, Kenwood, Elecraft and other radios through flrig, the scan list from `bpq32.cfg`, and TX power control.
- **APRS map key:** CARTO, which supplies the APRS map, now needs a free API key or it stamps "API KEY REQUIRED" across the map. Each station enters its own key in `config.php` (the installer asks for it). See **Section 4, Step 10** for how to get one.
- **NWS Alerts:** **Post to BBS** and **Post All** work again (they were rejected with "Missing required fields: subject, message"). Posts go to the address in the page's **Destination** box — as a bulletin (`SB`) for an address like `WX@GA`, as a private message (`SP`) when it starts with a callsign (2026-10-27; before, everything went as `SP`).
- **Your station on the pages (2026-10-27):** the Chat, VARA, NWS and Email Client pages show your callsign and node from `config.php` instead of placeholder text; access requests from the Chat and VARA pages are e-mailed by the server to `station.email`, whose address no longer appears on the pages. The NTS Report asks for the admin login, and `config.php.example` has the `smartplug` (power switch) section; with no switch set, nothing is probed.
- **Automatic NWS bulletins (optional, 2026-10-26):** the installer asks for your state; every 5 minutes the first issue of each new Warning or Watch for it is posted to the BBS as a bulletin (e.g. `WX@GA`). One bulletin per watch even when several NWS offices issue it; nothing expired, nothing twice (§10.4).
- **Credits:** `CREDITS.md` thanks the people and projects the dashboard is built on.
- **YAPP file transfer reworked (2026-10-24):** one click switches the Telnet Client to file-transfer mode, enters the BBS and lists its files; uploads by drag and drop with no command to type; long transfers no longer cut off after 2 minutes; Abort stops cleanly.
- **Chat:** the Online panel works, and the chat service rejoins by itself after BPQ's 2-hour idle disconnect.
- **Admin pages report this install accurately (2026-10-25):** System Audit, Firewall Status and Log Viewer were rewritten against the current code (no false alarms, IPv6, every daemon and timer), and `install.sh` offers to install their read-only sudo rules.
- **User Guide** checked page by page against the code, with new sysop reference chapters.

### Also since v1.5.12 (first released in v1.5.13)

- **Safer, complete installer.** It now sets up the database fully (all tables), schedules the log parsers and a daily bulletin clean-up, installs every Python module the dashboard uses, and downloads the APRS map symbols. It also locks private files away from the web server and puts **all** admin pages behind your admin password. Earlier installers left `/admin/` open and could expose `bpq32.cfg` through the logs link, so re-running the new installer is recommended.
- **Your settings, not the code's.** Callsign, grid square, VARA hub, paths and APRS login are all read from `config.php`. Public pages show only the **centre of your grid square**, never your exact position.
- **Passwords in one place.** Database credentials can live in `db-config.php`, or the `db` section of `config.php`, and are no longer written into any program file.
- **Email Client:** message text is saved in the database, and 📌 **pinned** folders keep their messages even after you kill them on the BBS.
- **Bulletins older than 14 days** are cleaned up automatically, except in 📌 pinned groups and your own folders.
- **Traffic Report** picks up BPQ's weekly traffic files automatically.
- **Publishing check** (`tools/prepublish-check.py`) for anyone sharing their copy of the dashboard.

### In v1.5.12

The biggest changes since v1.5.6:

### Major new features
- **Database-backed RF Connections (10-15× faster page load)** — the RF Connections page now reads pre-parsed VARA HF sessions from MySQL instead of parsing multi-megabyte log files in your browser. A new background parser (`parse-vara-log.py`) runs every 5 minutes via cron to keep the table fresh. See section 10.8 for setup; the status pill shows **(DB)** when active.
- **Lazy BBS log loading** — the RF Connections page only fetches log files for the currently-selected time range (1 day for Today, 7 for 7d, 30 for 30d). Clicking a longer range lazy-loads the missing files. Initial page load is dramatically lighter.
- **YAPP File Transfer over packet radio** — receive and send binary files via YAPP/YAPP-C protocol directly from your browser, fully integrated into the Telnet Client page. Verified working over real RF with another sysop. Includes a **Browse Files** button that lists files available on the BBS so you can click to receive.
- **Email Client** (formerly "BBS Messages") — fully redesigned message client with NTS first-class folder, custom folders, multi-select, forwarding visibility, address book, message threading.
- **Nationwide NWS severe weather** — convective panel now covers all WFOs and SPC's nationwide watches (was Norman OK only).
- **Day/night theme toggle** in the shared navigation — appears on every page, persists across visits, every page is theme-aware.

### Page additions and renames
- **Rig Status** — new page showing live frequency, mode, S-meter, power readings from flrig.
- **RF Power Monitor** — live RF telemetry read from flrig (existed in v1.5.6 but improved; migrated off WaveNode hardware — see RF-POWER-MONITOR-MIGRATION.md).
- **Telnet Client** — formerly "BPQ Telnet", now includes YAPP and FBB-mode support.
- **Email Client** rename — formerly "BBS Messages".
- **Message Monitor** — formerly "Email Monitor".
- **Hub Operations** — REMOVED (was vestigial).

### Operations & infrastructure
- **Daemons monitored** — Maintenance and System Audit cover nginx, PHP-FPM, LinBPQ, bpq-telnet, bpq-chat, bpq-aprs, bpq-vara, vara-logger, vara-validator and rig-history-poller (and, since 2026-10-25, the message-bodies and bulletin-retention timers).
- **Firewall page rewrite** — accurate port categorization (Public / LAN Only / IP Restricted / Blocked / Localhost), proper source IP display, per-jail fail2ban cards.
- **Multi-domain SSL cert monitoring** — System Audit can check any number of certificates via the `ssl_domains` config setting.
- **Light-mode support** on every page (Maintenance, Firewall, System Audit, Rig Status, VARA, Telnet, RF Power Monitor, Log Viewer all now respond to the toggle).
- **Config-aware error messages** — Rig Status and others now show actual error details rather than hardcoded placeholder text.

### Removed
- **Hub Operations page** — vestigial admin tool, fully cleaned up from nav, install-check, nginx config.

---

## 3. Before You Start

### What you need

- A Linux computer running LinBPQ — Raspberry Pi 3/4/5, Ubuntu 20.04+, Debian 11+, or any Debian-based system
- An internet connection. The installer adds everything else, including the MariaDB database the Email Client needs
- LinBPQ already installed, configured and running. If you can telnet to your node it is ready.
- SSH access to your Linux computer, or a keyboard and monitor attached to it
- About 15 to 30 minutes with the guided installer

> **Important:** BPQ Dashboard does NOT install or configure LinBPQ. LinBPQ must already be working before you start. The dashboard reads LinBPQ log files and connects to it via telnet — it does not replace it.

### Gather this information before you start

The installer asks about a dozen questions: your callsign, node, location, BPQ telnet port, sysop login, and the LinBPQ folder. **Section 4, Step 2** has the full list, with where to find each answer and an example. Fill it in before you start.

### Linux command line basics

Some steps require typing commands into a terminal. If you are new to Linux:

- **SSH** — connects you to your Linux computer from another computer. Windows: use PuTTY or Windows Terminal. Mac/Linux: open Terminal and type `ssh username@192.168.x.x`
- **sudo** — runs a command as administrator. You may be asked for your password.
- **nano** — a simple text editor built into Linux. Arrow keys move the cursor. Ctrl+O saves. Ctrl+X exits.
- **Tab key** — auto-completes filenames and commands as you type them
- **Commands are case-sensitive** — type them exactly as shown, including capital letters

---

## 4. Installation — Step by Step

This chapter takes you from nothing to a working dashboard. **Most people only need Part A**: a guided installer does the work and asks you simple questions along the way. Part B is the same thing done by hand, for experienced Linux users.

You don't need to know Linux. You will type (or copy and paste) a few commands; each one is explained. When a step shows a box like this:

```bash
sudo apt update
```

type that line into the terminal and press **Enter**.

---

### Part A — The guided installer (recommended)

#### Step 1 — Check you have what you need

- A computer running **Debian 11 or newer, Ubuntu 20.04 or newer, or Raspberry Pi OS (Bullseye or newer)**. A Raspberry Pi 3/4/5 is fine.
- **LinBPQ already installed and working** on that same computer.
- A way to type commands on it: a keyboard and screen, or an SSH connection. You must be able to use `sudo` (the installer needs administrator rights).
- An internet connection (the installer downloads software).

#### Step 2 — Write down your answers first

The installer asks these questions. Having the answers ready makes it quick.

| The installer asks for… | What to enter | Where to find it | Example |
|---|---|---|---|
| Your callsign | Your amateur callsign | — | `W1AW` |
| Your BPQ node callsign with SSID | Your node's call-SSID | `NODECALL=` line in `bpq32.cfg` | `W1AW-4` |
| Your sysop email | Any email you read | — | `you@example.com` |
| Latitude / longitude | Decimal degrees. **South and West are negative** | Google Maps: right-click your location, click the numbers to copy them | `41.7148` / `-72.7272` |
| CARTO map key *(optional, press Enter to skip)* | The free key for the APRS map | See **Step 10 — Get a CARTO map key**. You can add it later | `cb1_xxxx_x_xxxxxxxx…` |
| Automatic NWS bulletins *(optional, default No)* | If Yes: your **2-letter state code**, the bulletin address (default `WX@` + state) and Warnings + Watches or Warnings only | The address should be a flood area your forwarding partners carry. See §10.4. You can turn it on later | `GA`, `WX@GA`, `1` |
| Where RF Connections gets your VARA HF log *(1, 2 or 3; default 1)* | **1** = VARA HF sends its syslog to this server: then the **IP address of the computer running VARA HF** (Enter = this server). **2** = it goes to the TPRFN server: then the web address of your file there, as the TPRFN server admin gives it (ends in `/<call>.vara`). **3** = no VARA HF | For 1, in VARA HF: Settings → VARA Setup → Syslog on, Host = this server, port 514, Station Name `H-<call-SSID>` (§10.11). For 2, ask the TPRFN server admin to set up your file first | `1`, `192.168.1.50` |
| BPQ telnet port | A number | In `bpq32.cfg`, the Telnet port section, `TCPPORT=` | `8010` |
| BPQ sysop username *(default `SYSOP`)* | First field of your `USER=` line | `bpq32.cfg`, e.g. `USER=John,secret,W1AW-4,NODE,SYSOP` | `John` |
| BPQ sysop password | Second field of that same line | as above | `secret` |
| Folder containing `bpq32.cfg` *(default `/opt/linbpq`)* | The LinBPQ folder. It should have a `logs` folder inside | Wherever you installed LinBPQ | `/home/pi/linbpq` |
| Web server *(1 = nginx, 2 = Apache)* and port *(default 80, or 8080 when the machine already serves other sites)* | The one you want the dashboard on. On a machine with your own sites or a reverse proxy, a free port such as `8080`, optionally on 127.0.0.1 only | `sudo ss -ltnp` lists ports in use | `2`, `8080` |
| Optional pages *(yes/no each)* | Telnet Client, Chat, APRS map, VARA HF Terminal, VARA callsign check | Answer N for a page you don't use (e.g. Chat if you use another chat hub, APRS if you run APRSIS32) | |
| Database password | **Invent a new one**, or just press Enter and one is made for you | — | |
| Admin username / password | **Invent them.** This is the *admin login*: it protects the admin pages and everything that acts as your station (Email Client, Chat, posting, sending) | — | `admin` / something strong |
| flrig *(if you use it)* | Whether flrig runs on this computer (then the radio's serial port) or another one (its address and port, default 12345) | flrig's Config → Setup → XML-RPC | `/dev/ttyUSB0` or `192.168.1.20` |

Your map position on public pages is shown as the **centre of your grid square** (about 5 × 2.5 km), never your exact coordinates.

#### Step 3 — Download the dashboard

Open a terminal on the LinBPQ computer and run these four lines:

```bash
sudo apt update
sudo apt install -y wget unzip
cd ~
wget -O bpq-dashboard.zip https://github.com/bpq-dashboard/bpq-dashboard/archive/refs/tags/v1.5.26.zip
```

Then unpack it and go into the new folder:

```bash
unzip bpq-dashboard.zip
cd bpq-dashboard-1.5.33
```

> If you downloaded the zip with a web browser instead, it is probably in your Downloads folder. Use `cd ~/Downloads` before the `unzip` line.

#### Step 4 — Run the installer

```bash
sudo bash install.sh
```

Type your Linux password if asked. The screen explains what will happen; press **Enter** to begin.

> **Normal:** when you type a password, nothing appears on screen — not even dots. Type it and press Enter.

#### Step 5 — Answer the questions

Type the answers from your Step 2 table. A value in `[brackets]` is the default; press **Enter** to accept it.

Some questions are **yes/no**. The capital letter is the default:

| Question | Answer |
|---|---|
| Use it for the dashboard? *(MariaDB already installed)* / Install MariaDB now? | **Y** (the Email Client and the fast pages need it) |
| Back it up and reinstall fresh? *(only if the dashboard was installed before)* | **Y** |
| Which web server should run the dashboard? / Port | See Step 2. If the machine already serves other sites, the installer says so and leaves them alone |
| Reach the dashboard only through your reverse proxy (listen on 127.0.0.1)? *(only when sharing the machine)* | **y** if you will forward to it from your own nginx; **n** to open it on that port from your network too |
| Use the Telnet Client / Chat / APRS map / VARA HF Terminal / VARA callsign check page? | **Y** for the pages you use. **N** hides a page and skips its helper; add it later (Maintenance → *Optional pages*) |
| Set up flrig now (for Rig Status / RF Power Monitor)? | **Y** if you use flrig, then answer whether it runs on this server. **n** hides the Rig Status and RF Power Monitor pages (both need flrig); everything else, including BPQ's own CAT control of the radio, works without it |
| Apply that fix now (setfacl)? *(only if the web server can't reach your LinBPQ folder)* | **Y** — gives the web server read access to the LinBPQ folder and its logs only (§12, *LinBPQ in a home folder*) |
| Start the page helpers now (and at every boot)? | **Y** |
| Install those sudo rules (/etc/sudoers.d/www-data-tools)? | **Y** — read-only rules the Firewall Status and System Audit pages need, plus the Maintenance page's *Change admin password* helper |
| Set up a basic firewall (ufw) now? *(default No)* | **N** to do it later; **y** runs `tools/setup-firewall.sh` (§11) |

The whole run takes 5–15 minutes, mostly downloading. Everything the installer does is also written to a log file named on the first screen (`/tmp/bpq-dashboard-install-….log`).

#### Step 6 — Read the summary

At the end you'll see something like:

```
Results:
  ✓ 41 steps succeeded
  ⚠ 3 warnings
  ✗ 0 errors
```

- **✓** worked.
- **⚠** is something to know about, not a failure. For example, *"Skipping flrig setup"* just means you answered **n**.
- **✗** is an error. It is listed underneath with what went wrong. The log file has the details; include it if you ask for help.

Just before the summary, the installer asks the web server for three addresses the way a browser would: a PHP file (expects **200**; **502** means the web server can't reach PHP), an admin page with no login (expects **401**; **500** means the web server can't read the admin login file) and the same page with your admin login (expects **200**). A ✗ on any of them names the fix; Section 12 has the details.

#### Step 7 — Open your dashboard

On the LinBPQ computer, find its network address:

```bash
hostname -I
```

The first number (for example `192.168.1.50`) is its address. On any computer, phone or tablet on your network, open a web browser and go to:

```
http://192.168.1.50/
```

(use your own number; if you chose another port, add it: `http://192.168.1.50:8080/`). The **RF Connections** page should appear. Bookmark it.

#### Step 8 — Run the self-test

Go to:

```
http://192.168.1.50/install-check.php
```

Log in with the **admin username and password** you invented. The page checks everything the dashboard needs and shows each item in green, amber or red. **Every red item shows the exact command that fixes it.**

A *daemon heartbeat* line can stay red for a minute or two while the helpers connect to LinBPQ. Pages you switched off are passed as *not used*.

#### Step 9 — The helper services

Small background programs power the live pages. Since v1.5.19 the installer installs and starts the ones for the pages you chose; this table is for checking them, or for turning one on later (also Maintenance → *Optional pages*, which first installs the unit file):

| Service | What it's for | Turn it on |
|---|---|---|
| `bpq-telnet` | Telnet Client page and YAPP file transfers | `sudo systemctl enable --now bpq-telnet` |
| `bpq-chat` | Chat page | `sudo systemctl enable --now bpq-chat` |
| `bpq-aprs` | APRS map | `sudo systemctl enable --now bpq-aprs` |
| `bpq-vara` | VARA HF Terminal page | `sudo systemctl enable --now bpq-vara` |
| `vara-logger` | Records VARA HF activity for the RF Connections page when VARA HF sends its syslog to this server (see §10.11) | `sudo systemctl enable --now vara-logger` |
| `vara-remote.timer` | Downloads your VARA log from the TPRFN server instead (§10.11) | `sudo systemctl enable --now vara-remote.timer` |
| `rig-history-poller` *(flrig installs)* | Records power and SWR for the RF Power Monitor | `sudo systemctl enable --now rig-history-poller` |

To check one is running, for example `bpq-aprs`:

```bash
systemctl status bpq-aprs
```

Look for **active (running)** in green. Press **q** to get back to the prompt.

The installer already scheduled the **log parsers** (and loaded the weekly Traffic reports already on the machine), which fill the RF Connections, Traffic and System Logs pages every 5 minutes, the **message-text job** (`message-bodies.timer`, saves new BBS messages' text every 5 minutes) and a **daily clean-up** that deletes bulletins older than 14 days, except in groups you pin with 📌 in the Email Client. (All of these need the database.) Pages may look empty for the first few minutes. If you said Yes to automatic NWS bulletins, `nws-auto-post.timer` is running too (no database needed); see §10.4.

##### Telnet and VARA pages

The installer's site file (nginx or Apache) puts the **Telnet Client** and the **VARA HF Terminal**, their live connections (`/ws/telnet` to the `bpq-telnet` service on port 8765, `/ws/vara` to `bpq-vara` on 8767, both listening on the server only) and the NetROM list (`bpq-nodes-api.php`) behind the admin login. The VARA connection has no login of its own, so this is what stops anyone else from transmitting as your station. Both pages work over HTTP or HTTPS. On Apache the installer also turns on `proxy_wstunnel` for these connections and runs PHP through PHP-FPM (`a2enconf php*-fpm`).

Both read `config.php`; there is nothing to edit in the scripts:

- **Telnet NetROM Nodes list:** logs in with `bbs.user` / `bbs.pass` and leaves your own node (`station.node`) out of the list.
- **VARA HF Terminal:** the `bpq-vara` service logs in with `bbs.user` / `bbs.pass` and finds your VARA HF port in `bpq32.cfg` (the first `DRIVER=VARA` port that isn't VARA FM). Set it yourself if it picks the wrong one: `'vara' => ['bpq_port' => 3],`. After starting it, check what it chose:
  ```bash
  sudo systemctl enable --now bpq-vara
  sudo journalctl -u bpq-vara -n 5 | grep "VARA HF ="
  ```
  `www-data` must be able to read `bpq32.cfg` for this (it already must for the Rig page). If it can't, or there's no VARA port, the service stops and the journal says what to set.

#### Step 10 — Optional settings

Your settings live in one file. To open it:

```bash
sudo nano /var/www/bpq-dashboard/config.php
```

Use the arrow keys to move. To save, press **Ctrl+O** then **Enter**. To exit, press **Ctrl+X**. Things you might change:

- **CARTO map key (APRS map):** see *Get a CARTO map key* just below. If you skipped it in the installer, the APRS map shows "API KEY REQUIRED" across every tile until you add it.

- **Send on APRS-IS:** by default the APRS map only *receives*. To send messages, or to beacon your BBS to aprs.fi (APRS Map → 🏠 BBS Info → Edit), set your passcode in the `'aprs'` section: `'passcode' => 'YOUR_PASSCODE',`. Get your passcode from https://apps.magicbug.co.uk/passcode/. Then run `sudo systemctl restart bpq-aprs`. You don't need LinBPQ's own APRS (`APRSDIGI`) for either; if you do run it, give it a different SSID from the dashboard's.
- **VARA HF hub:** if you run a VARA HF hub, put its call-SSID in `'hub' => 'W1AW-7',` (in the `'station'` section) to get band and frequency history on RF Connections.
- **flrig (rig control):** the `'radio'` section, if you skipped it in the installer.
- **QRZ.com locations on the maps:** with a QRZ XML data subscription, put your QRZ login in the `'qrz'` section (password in single quotes). Without it, US stations are still placed through callook.info. Check it with `sudo -u www-data php /var/www/bpq-dashboard/callsign-lookup.php --qrz-test` (never shows the password).

Web pages pick up changes immediately; only the helper services need a restart.

##### Get a CARTO map key

The APRS map is drawn with map tiles from CARTO. CARTO now asks for a free API key; without one the map still loads but is stamped **"API KEY REQUIRED"** all over. Each station needs its own key.

1. Go to **https://www.carto.com/basemaps/apikey/** and fill in the request form (or go straight to **https://dashboard.basemaps.carto.com**). You sign in with a link CARTO emails you; there is no password.
2. Create a key. Personal, club and other non-commercial use is free up to 5 million map tiles a month, far more than a station dashboard uses.
3. **Limit the key to your dashboard's web address** (for example `https://bpq.example.com`) in CARTO's dashboard. The key is sent to every visitor's browser along with the map, so anyone can see it; the address limit stops others from using it on their own sites.
4. Copy the key and put it in `config.php`:

   ```php
   'map' => [
       'carto_key' => 'cb1_xxxx_x_xxxxxxxxxxxxxxxxxxxxxxxx',
   ],
   ```

   Use single quotes. If your `config.php` has no `'map'` section (installed before 2026-10-20), add the lines above inside the main `return [ ... ];`, for example just before the `'qrz'` section.
5. Open the APRS map and press **Ctrl+Shift+R** so your browser drops the old stamped tiles. Install Check's **CARTO map key (APRS map)** row turns green once the key is set.

If the stamp is still there with a key set, check in CARTO's dashboard that the key is active and that its address limit matches the address you use to open the dashboard.

#### Step 11 — Stay safe

- Everything that acts as your station (Email Client, Chat, NWS posting, APRS sending, map saves, the admin pages, Install Check, Health Check) is protected by your admin login, and so are the Telnet and VARA pages. Keep that password private.
- **Never share `config.php`**. It contains your BBS and database passwords.
- Keep Install Check: it's behind the login, and it's the quickest way to check the install after every update.
- If you want the dashboard reachable **from the internet**, set up HTTPS and check the firewall first, **IPv6 included**: see Section 11 and `SECURITY.md`.

---

### Updating to a newer version

Since v1.5.19 every release has **`update.sh`**, which updates your dashboard in place: no reinstall, no questions, nothing to re-enter. It also works on an install of an older version.

1. Download and unzip the new version as in Step 3 (use its version number):
   ```bash
   cd ~
   wget -O bpq-dashboard.zip https://github.com/bpq-dashboard/bpq-dashboard/archive/refs/tags/vX.Y.Z.zip
   unzip -o bpq-dashboard.zip
   cd bpq-dashboard-X.Y.Z
   ```
2. Look at what it will do (changes nothing):
   ```bash
   sudo bash update.sh --dry-run
   ```
   It finds your dashboard (`/var/www/bpq-dashboard`, or the folder your nginx/Apache site serves; add `--web-root /path` if it asks) and lists the files it will update, add and remove, plus any systemd unit or sudo-rules file it would refresh (compare one first with the `diff` command it prints). The admin-password sudo rule is only kept where you already set it up; it is never added by an update.
3. Update:
   ```bash
   sudo bash update.sh
   ```
4. Press **Ctrl+Shift+R** on each page once (the menu is cached), then open Install Check.

**What it keeps:** `config.php` (and `db-config.php` etc.), `data/` (partners, settings, saved stations), `cache/`, the `logs` link to LinBPQ, `pktnet-forms/`, `img/`, `yapp-files/`, everything in the database, your nginx or Apache site file, your firewall, and everything outside the dashboard folder.

**What it does:** backs up every file it replaces or removes to `/root/bpq-dashboard-update-<date>/` (with a restore script and a log); copies the new and changed files, keeping owner and mode, and checks each one; removes files the release dropped (old PDFs, retired pages); applies the database updates in `sql/migrations.list` (each is safe to repeat); refreshes the systemd units and sudo rules the installer set up, only where they changed (a unit you wrote yourself is left alone); restarts only the helper services whose program or unit changed; and records the version in `VERSION` and `MANIFEST.md5`. Maintenance → *Install check, updates & backups* shows the installed and latest versions.

**Files you edited on the server** (from v1.5.19 on it can tell, using the installed `MANIFEST.md5`) are listed before it starts; they are replaced with the new version and your copy stays in the backup. To keep them instead, run it with `--keep-local`. Files you always run in your own version go in a keep list, `/etc/bpq-dashboard/update-keep.txt` (one path per line relative to the dashboard folder, e.g. `callsign-lookup.php` or `scripts/wp_*.py`; `#` starts a comment): they are never replaced or removed, and every plan lists them so you can compare them with the CHANGELOG. The same list can name a hand-tuned unit or the sudo rules file to keep: `/etc/systemd/system/bpq-chat.service`, `/etc/sudoers.d/www-data-tools`.

**Undo:** the last lines it prints include the exact command, e.g. `sudo bash update.sh --rollback /root/bpq-dashboard-update-20261115-101500`. It puts back every file, unit and sudo rule as it was. Database updates stay (they only add tables and columns, which the older version ignores).

**When the web-server template changed** (`nginx-bpq-dashboard.conf`, `bpq-dashboard.conf`), `update.sh` says so but doesn't touch your live site file; the CHANGELOG says what to add.

**A full reinstall** is still possible: run the new version's `sudo bash install.sh`, give the same answers, and answer **Y** to *"Back it up and reinstall fresh?"*. Your database is kept; `config.php` is written new, so re-apply optional settings (Step 10) from the old `config.php` in `/var/www/bpq-dashboard.backup-<date>`.

**Coming from an older version?** These one-time steps bring earlier history into features added since then. Each is safe to run again. Use your real backup folder name and callsign (`W` is the web folder):

```bash
W=/var/www/bpq-dashboard
```

- **Email Client folders kept in files** (`data/messages/*.json`, versions before July 2026): copy them from the backup and import them into the database. The JSON files are only read, never deleted.
  ```bash
  sudo cp -a /var/www/bpq-dashboard.backup-<date>/data/messages $W/data/
  sudo chown -R www-data:www-data $W/data/messages
  sudo -u www-data php $W/migrate-json-to-db.php
  ```
- **System Logs history** (the page reads the database since v1.5.13). Add `--dry-run` first to see what it would load; a year of logs can take several minutes:
  ```bash
  sudo -u www-data python3 $W/scripts/parse-system-logs.py --logs-dir $W/logs --backfill --verbose
  ```
- **RF Connections forwarded messages**: label older messages as RF (VARA) or not, for the Forwarding Partners tiles. Use the number of days your BBS logs go back:
  ```bash
  sudo -u www-data python3 $W/scripts/parse-vara-log.py --bbs-log-dir $W/logs --my-callsign W1AW --rescan-messages-days 365
  ```
- **Message text** for messages already in the database: `sudo -u www-data php $W/scripts/fetch-message-bodies.php --backfill`
- **Forwarded Messages delivery status** (Via, Status, Received; added 2026-10-06): installers before 2026-10-25 didn't load this migration. Load it once (safe to repeat), then re-scan with the `--rescan-messages-days` line above:
  ```bash
  sudo mysql bpqdash < sql/2026-10-06-rf-messages-delivery.sql
  ```
  (`bpqdash` is the database name from `config.php`; run it in the unzipped folder.) System Audit lists any update your database still lacks.
- **Telnet and VARA pages, NetROM list** (2026-10-25): the reinstall writes the new site file and the new scripts read `config.php`. If you had edited `scripts/bpq-vara-daemon.py` or `bpq-nodes-api.php` by hand, check `config.php`'s `bbs` login and `station.node` (and `vara.bpq_port` if your VARA port isn't found), then `sudo systemctl restart bpq-vara`. Both pages now ask for the admin login.
- **NTS Report login and power switch (2026-10-27):** the reinstall writes the new site file, which puts `nts-forwarding-report.php` behind the admin login; with a hand-made site file the report answers "needs the dashboard admin login" until you add it to the login locations (compare `nginx-bpq-dashboard.conf`). If Rig Status showed your power switch without a `smartplug` section in `config.php` (the old built-in `192.168.1.100`, `admin`/`admin`), add the section with those values.
- **VARA HF log on Debian 12 / Ubuntu 24.04 (2026-10-27):** rsyslog there writes timestamps the parsers didn't read, so RF Connections stayed empty; the new `vara-logger.sh` converts them — after updating, `sudo systemctl restart vara-logger`. If the installer never asked you about the VARA HF log receiver, set it up with §10.11 steps 1–3.
- **v1.5.19 (from 1.5.18 or earlier):**
  - The dashboard's own logs move to `/var/log/bpq-dashboard/`. A reinstall creates it; by hand: `sudo mkdir -p /var/log/bpq-dashboard /var/lib/bpq-dashboard && sudo chown www-data:www-data /var/log/bpq-dashboard /var/lib/bpq-dashboard && sudo chmod 750 /var/log/bpq-dashboard /var/lib/bpq-dashboard`, then restart `bpq-telnet` and `bpq-vara`. Older log files stay in `logs/`; the Log Viewer shows them until the new ones fill.
  - Optional pages: an existing `config.php` has no `'pages'` section, so every page stays on. Add one to hide pages you don't use (Maintenance → *Optional pages*). If an earlier install left Rig Status / RF Power Monitor out (no flrig), they come back as files but stay hidden while `'radio' => ['enabled' => false]`.
  - `vara-validator`: the new unit runs as `www-data` from the dashboard folder and reads `config.php 'vara_validator'` (§10.9). If you run the old copy in `/usr/local/bin`, keep it until you have copied your settings into `config.php`.
  - The Maintenance page's *Change admin password* needs the helper in `/usr/local/sbin` and its sudo rule (§11, *Configure sudoers*); a reinstall offers both.
- **Apache installs from before 2026-10-25:** the installer didn't hook PHP-FPM into Apache, so PHP pages were sent as text and nothing that reads data worked. Re-running the installer fixes it; by hand: `sudo a2enmod proxy_fcgi setenvif proxy proxy_http proxy_wstunnel && sudo a2enconf php*-fpm && sudo systemctl reload apache2`.

---

### Part B — Manual install (experienced users)

These are the same steps the guided installer performs. Paths assume the standard web folder `/var/www/bpq-dashboard`.

**1. Packages**

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-cli php-curl php-mbstring php-xml php-mysql php-sqlite3 php-gd \
    mariadb-server python3 python3-pymysql python3-reportlab python3-websockets \
    python3-requests python3-serial python3-pexpect rsync curl unzip apache2-utils
```

**2. Database** (choose your own password)

```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS bpqdash CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'bpqdash'@'localhost' IDENTIFIED BY 'CHOOSE_A_PASSWORD';
GRANT ALL PRIVILEGES ON bpqdash.* TO 'bpqdash'@'localhost';
SQL
for f in sql/2026-07-messages-schema.sql data/rf-connections-schema.sql data/prop-decisions-schema.sql \
         data/sessions-stations-schema.sql data/traffic-schema.sql data/system-logs-schema.sql \
         sql/2026-09-21-rf-messages-medium.sql sql/2026-09-26-message-keep-and-bodies.sql \
         sql/2026-10-06-rf-messages-delivery.sql; do
    sudo mysql bpqdash < "$f"
done
```

Run this from the unzipped folder. All files are safe to re-run. The last three are updates: on a new database they add the columns the base schema doesn't have yet (the delivery-status one is needed for Forwarded Messages' Via/Status columns).

**3. Files**

```bash
W=/var/www/bpq-dashboard
sudo mkdir -p $W
sudo rsync -a --exclude install.sh --exclude .git --exclude private ./ $W/
sudo mkdir -p $W/cache/aprs $W/cache/chat-sessions $W/cache/network $W/data/backups $W/img
echo '{"partners":[]}' | sudo tee $W/data/partners.json >/dev/null
sudo curl -fsSL -o $W/img/aprs-symbols-pri.png https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/aprs-symbols-24-0.png
sudo curl -fsSL -o $W/img/aprs-symbols-alt.png https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/aprs-symbols-24-1.png
sudo bash $W/scripts/fetch-forms.sh $W    # message forms for the Email Client (optional; re-run with --force to update)
sudo chown -R www-data:www-data $W
```

**4. Configuration**

```bash
sudo cp $W/config.php.example $W/config.php
sudo nano $W/config.php
sudo chown www-data:www-data $W/config.php && sudo chmod 640 $W/config.php
```

At minimum, set:

| Setting | Description | Example |
|---|---|---|
| `station.callsign` | Your callsign | `'W1AW'` |
| `station.node` | Your BPQ node (callsign-SSID) | `'W1AW-4'` |
| `station.grid` | Grid square. Public pages (RF Connections map) show the **centre of this square**, never your exact position | `'FN31PR'` |
| `station.hub` | Optional: your VARA HF hub SSID; enables band/frequency correlation from your own BBS log | `'W1AW-7'` |
| `station.lat` | Latitude (exact; used server-side only, never published) | `41.7148` |
| `station.lon` | Longitude (exact; used server-side only, never published) | `-72.7272` |
| `features.bbs_write` | `false` makes the Email Client read-only: Send, Forward and Kill are refused (NWS posting has its own `features.nws_post`) | `true` |
| `cors.allowed_origins` | Other web sites allowed to call the dashboard from a browser. Leave empty unless you need it; the dashboard's own pages don't | `['https://club.example.org']` |
| `map.carto_key` | Free CARTO key for the APRS map tiles (see Part A, Step 10 — *Get a CARTO map key*). Without it the map is stamped "API KEY REQUIRED" | `'cb1_xxxx_x_…'` |
| `paths.linbpq` | Your LinBPQ directory (`bpq32.cfg`, `WP.cfg`, `linmail.cfg`) | `'/home/linbpq'` |
| `logs.vara_file` | Your VARA log file name inside `paths.logs`, or a full path (the TPRFN download uses `/var/log/bpq-dashboard/<call>.vara`) | `'w1aw.vara'` |
| `bbs.host` | LinBPQ host | `'127.0.0.1'` |
| `bbs.port` | LinBPQ telnet port | `8010` |
| `bbs.user` | Your BPQ sysop user | `'W1AW'` |
| `bbs.pass` | Its password. Also the password for the VARA page, the Rig page controls and the partners editor | `'mypassword'` |
| `aprs.callsign` / `aprs.passcode` | APRS-IS login; passcode `-1` = receive only | `'W1AW-1'` / `'12345'` |
| `qrz.username` / `qrz.password` | Optional QRZ XML login for map locations | |
| `messages.bulletin_text` | Which bulletins' text the server saves: `all`, `pinned`, `none` | `'all'` |
| `nws.post_destination` | Default address for NWS page posts; empty = `WX@<nws.auto_post.area>`, else `WX@ALLUS`. A callsign address is sent as `SP`, anything else as a bulletin (`SB`) | `'WX@ALLUS'` |
| `nws.auto_post` | Automatic Warning/Watch bulletins (`nws-auto-post.timer`, §10.4) | `enabled => false` |
| `smartplug` | Optional network power switch for Rig Status: `host`, `user`, `pass`, `outlet` (§10.7). `host` empty = none; nothing is contacted | `'host' => ''` |
| `radio.flrig_host` | flrig host IP | `'192.168.1.100'` |
| `radio.flrig_port` | flrig XML-RPC port | `12345` |
| `radio.enabled` | `false` when flrig was skipped: Rig Status and RF Power Monitor hidden from the menu, no history poller | `true` |
| `pages` | Optional pages (v1.5.19): `telnet`, `chat`, `aprs`, `vara`, `vara_validator`; `false` hides the page from the menu (install its helper only for pages that are `true`). Missing = on | `['chat' => false, 'aprs' => false]` |
| `paths.app_logs` | Where the dashboard writes its own logs (v1.5.19; must exist and be writable by the web user) | `'/var/log/bpq-dashboard'` |
| `vara_remote` | VARA HF log downloaded from the TPRFN server (§10.11): `enabled`, `url` | `['enabled' => true, 'url' => 'https://<TPRFN server>/w1aw.vara']` |
| `vara_validator` | VARA callsign check settings (§10.9) | |
| `radio.bpq_radio` | bpq32.cfg RADIO number for the Rig page scan list; `0` = the block with an FLRIG line | `0` |
| `radio.max_power` | Highest TX power (W) the Rig page may set; `0` = the radio's maximum | `50` |
| `radio.min_power` / `radio.model` | Lowest TX power (default 1); force a radio profile instead of auto-detect | `5` / `'FTdx3000'` |
| `security_mode` | `'public'` for an internet-facing site with no login in front (Section 11) | `'local'` |
| `ssl_domains` | Domains to monitor for cert expiry (optional) | `['bpq.example.com']` |

Put the database name, user and password from step 2 in the `'db'` section. Alternatively, put them in `db-config.php` (copy `db-config.php.example`), which takes priority.

**5. Link the BPQ logs**

Link LinBPQ's **logs** folder. Never link the whole LinBPQ folder: `bpq32.cfg` holds your passwords.

```bash
sudo rm -rf $W/logs
sudo ln -s /path/to/linbpq/logs $W/logs
sudo mkdir -p /var/log/bpq-dashboard /var/lib/bpq-dashboard     # the dashboard's own logs; kept copies (v1.5.19)
sudo chown www-data:www-data /var/log/bpq-dashboard /var/lib/bpq-dashboard
sudo chmod 750 /var/log/bpq-dashboard /var/lib/bpq-dashboard
```

LinBPQ inside a home folder (Ubuntu 21.04+, Mint 21+)? Give the web server access as in §12, *LinBPQ in a home folder*, then check: `sudo -u www-data ls /path/to/linbpq/logs | head -3`.

**6. Web server**

For a LAN-only server, the simplest safe choice is to let `install.sh` write it. By hand, start from `bpq-dashboard.conf` (HTTPS, public-facing) and keep **all** of its `return 403` blocks. They stop `scripts/`, `data/`, `.sql`, backup files and anything in `logs/` other than log files from being downloaded. The template protects `/admin/` with the password file `/etc/nginx/.bpq-mgmt-auth`. Create it:

```bash
sudo htpasswd -cB /etc/nginx/.bpq-mgmt-auth admin
sudo chown root:www-data /etc/nginx/.bpq-mgmt-auth
sudo chmod 640 /etc/nginx/.bpq-mgmt-auth
```

The last two lines matter: `htpasswd -c` makes the file readable by root only, and nginx's workers (user `www-data`) then can't read it, so every admin page answers **500**.

Both templates name PHP 8.3's socket (`fastcgi_pass unix:/run/php/php8.3-fpm.sock;`). Use your own version's socket — `ls /run/php/` shows it, for example `php8.5-fpm.sock` — and always the versioned name, not `php-fpm.sock`:

```bash
ls /run/php/
SITE=/etc/nginx/sites-available/bpq-dashboard     # your site file
sudo sed -i 's|php8.3-fpm.sock|php8.5-fpm.sock|g' $SITE   # your version
```

**On a machine that already runs nginx for other sites** (a reverse proxy, K5DAT's case): don't replace its default site. Either add the dashboard as its own `server { }` block with its own `listen` port or `server_name` (from `nginx-bpq-dashboard.conf`, without `default_server`), or run it under Apache on another port and forward to it. Apache on port 8080, reachable only through your nginx:

```bash
sudo apt install -y apache2 libapache2-mod-fcgid      # if port 80 is nginx's, Apache's first start fails until the next lines
sudo sed -i 's/^Listen 80$/# Listen 80/' /etc/apache2/ports.conf
echo 'Listen 127.0.0.1:8080' | sudo tee -a /etc/apache2/ports.conf
sudo a2dissite 000-default
```

then write the site as `install.sh` does (its Apache block, with `<VirtualHost 127.0.0.1:8080>`), and in your nginx's server block for the dashboard's host name:

```nginx
location /     { proxy_pass http://127.0.0.1:8080; proxy_set_header Host $host;
                 proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_read_timeout 120s; }
location /ws/  { proxy_pass http://127.0.0.1:8080; proxy_http_version 1.1;
                 proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_read_timeout 7200s; }
```

The guided installer (v1.5.19) does all of this when you choose Apache and port 8080 on such a machine, backs up `/etc/nginx` and writes the proxy block to `/root/bpq-dashboard-reverse-proxy.txt`. Give the dashboard its own host name or port: a sub-path such as `/dashboard/` doesn't work.

Both templates have the `/ws/telnet` and `/ws/vara` blocks: `nginx-bpq-dashboard.conf` puts them and the two pages behind the admin login, like the installer; `bpq-dashboard.conf` limits `/ws/telnet` to private addresses and puts `/ws/vara` behind the admin login as well.

**7. PHP time limit**

```bash
for d in /etc/php/*/fpm/conf.d; do printf 'max_execution_time = 90\n' | sudo tee $d/99-bpq-dashboard.ini; done
sudo systemctl restart 'php*-fpm'
```

**8. Schedule the log parsers and the bulletin clean-up**

Replace `W1AW` with your callsign:

```bash
sudo mkdir -p /var/log/bpq-dashboard && sudo chown www-data:www-data /var/log/bpq-dashboard
sudo tee /etc/cron.d/bpq-dashboard >/dev/null <<'CRON'
*/5 * * * * www-data /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py --bbs-log-dir /var/www/bpq-dashboard/logs --my-callsign W1AW >> /var/log/bpq-dashboard/parse-vara-log.log 2>&1
*/5 * * * * www-data /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-traffic-report.py --days 8 >> /var/log/bpq-dashboard/parse-traffic-report.log 2>&1
*/5 * * * * www-data /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-system-logs.py >> /var/log/bpq-dashboard/parse-system-logs.log 2>&1
CRON
for u in bulletin-retention.service bulletin-retention.timer message-bodies.service message-bodies.timer; do
  sed "s|/var/www/bpq-dashboard|$W|g" $W/$u | sudo tee /etc/systemd/system/$u >/dev/null
done
sudo systemctl daemon-reload && sudo systemctl enable --now bulletin-retention.timer message-bodies.timer
sudo -u www-data python3 $W/scripts/parse-traffic-report.py --backfill     # load the Traffic reports already there, once
```

Log rotation for the dashboard's own logs (v1.5.19):

```bash
printf '/var/log/bpq-dashboard/*.log {\n    weekly\n    rotate 8\n    compress\n    delaycompress\n    missingok\n    notifempty\n    copytruncate\n}\n' | sudo tee /etc/logrotate.d/bpq-dashboard
```

`message-bodies.timer` saves the text of new BBS messages to the database every 5 minutes, whether or not the Email Client is open (BPQ can remove bulletins within days). Bulletins: `'messages' => ['bulletin_text' => 'all']` in config.php; `'pinned'` saves only groups pinned 📌, `'none'` none. To fetch text for messages already in the database once: `sudo -u www-data php $W/scripts/fetch-message-bodies.php --backfill`.

`--days 8` matters: some BPQ versions write the traffic report weekly rather than daily.

**9. Helper services** (only for the pages you use; set the others to `false` in `config.php 'pages'`)

```bash
sudo cp $W/bpq-telnet.service $W/bpq-chat.service $W/bpq-aprs.service $W/bpq-vara.service $W/vara-logger.service /etc/systemd/system/
sudo cp $W/rig-history-poller.service /etc/systemd/system/     # only with flrig
sudo systemctl daemon-reload
sudo systemctl enable --now bpq-telnet bpq-chat bpq-aprs bpq-vara vara-logger   # leave out the ones you don't use
sudo systemctl enable --now rig-history-poller                  # only with flrig
```

The service files run the daemons from `$W/scripts/` (`vara-logger` runs `$W/vara-logger.sh` as root, because it reads `/var/log/syslog`). Don't copy the scripts elsewhere. Edit `$W/vara-logger.sh` so `OUTPUT_FILE` names your `<callsign>.vara` file. The Telnet and VARA pages need the `/ws/telnet` and `/ws/vara` blocks from `nginx-bpq-dashboard.conf` (Part A, Step 9 explains what they do).

**10. Check** with `install-check.php`, as in Part A, Step 8.

---

## 5. Testing Your Installation

Visit `http://YOUR_SERVER_IP/install-check.php` and log in with your admin username and password. It checks:

- every dashboard page, API and script is present (the Rig pages only when flrig is set up)
- `config.php` values are filled in, including the APRS, CARTO, CORS and radio settings
- the logs folder is readable and the cache, data and YAPP folders writable
- PHP, its extensions and the database work; the tables exist; the Python modules are installed; the log parsers are scheduled
- the services are installed and running
- the web-server site file serves this folder, the admin folder and the station-acting pages are behind the login, and the login reaches PHP
- LinBPQ is reachable, with mail and chat enabled, and the FLRIG `RADIO` block can be read

Each failure shows the command that fixes it. `health-check.php` (same login) is a shorter diagnostic that also tries a BBS login; it runs from the command line too: `sudo -u www-data php /var/www/bpq-dashboard/health-check.php`. After the first day, run **System Audit** (Admin → Audit) as well.

---

## 6. Dashboard Pages Reference

> **Core vs. optional:** the pages below work once Section 4 is done. The forwarding tools, the callsign validator and the other extras in Section 10 are add-ons; skip the ones you don't need.

The **User Guide** describes every page in detail. In short, with what each needs on the server:

| Page | Address | Login | Needs |
|---|---|---|---|
| RF Connections (home) | `/` or `/bpq-rf-connections.html` | none (saving partners/locations: admin) | `vara-logger` or `vara-remote.timer` (§10.11), `parse-vara-log.py`, BBS logs |
| Traffic Report | `/bpq-traffic.html` | none | `Traffic_*.txt`, `parse-traffic-report.py` |
| System Logs | `/bpq-system-logs.html` | none | BBS/TCP/CMS logs, `parse-system-logs.py`; optional `nodes-rtt.sh` (§10.12) |
| Connect Log | `/bpq-connect-log.html` | none | `ConnectLog_*.log` (`LogAllConnects=1` in `bpq32.cfg`) |
| Message Monitor | `/bpq-email-monitor.html` | none (reading bulletins: admin) | BBS and TCP logs |
| Email Client | `/bbs-messages.html` | admin | database, `bbs` login in `config.php`, `message-bodies.timer`, `bulletin-retention.timer` |
| NWS Weather | `/nws-dashboard.html` | none (posting: admin) | api.weather.gov; `bbs` login for posting |
| Chat Client | `/bpq-chat.html` | admin | `bpq-chat` |
| APRS Map | `/bpq-aprs.html` | none (sending, BBS info: admin) | `bpq-aprs`, `aprs` and `map` in `config.php` |
| VARA HF Terminal | `/bpq-vara.html` | admin + BBS password | `bpq-vara` (`bbs` login, VARA port from `bpq32.cfg` or `vara.bpq_port`), flrig, `RADIO 2` FLRIG block in `bpq32.cfg` |
| Telnet Client | `/bpq-telnet.html` | admin + your BPQ login | `bpq-telnet`; `bpq-nodes-api.php` for the nodes list (`bbs` login, `station.node`) |
| Rig Status | `/rig-status.html` | none (controls: BBS password) | flrig; optional `smartplug` |
| RF Power Monitor | `/rf-power-monitor.html` | none | flrig, `rig-history-poller` |
| Maintenance, Firewall Status, Log Viewer, System Audit | `/admin/…` | admin | sudo rules (§11) for Firewall/Audit and for *Change admin password* |
| Install Check, Health Check | `/install-check.php`, `/health-check.php` | admin | — |

Chat, APRS Map, VARA HF Terminal, Telnet Client, Rig Status and RF Power Monitor are **optional pages** (v1.5.19): switched off in `config.php 'pages'` (or `'radio' => 'enabled'`) they are hidden from the menu and their helper isn't needed.

All admin pages live in `/admin/`; one web-server login protects the folder, its APIs, Install Check and Health Check, and the PHP refuses the station-acting endpoints unless that login was checked.

---

## 7. The Shared Navigation & Day/Night Theme

Every page loads the same menu from `_nav.html` (through `bpq-nav-loader.js`): **📡 BPQ Dashboard** (home), 13 page links (RF, Traffic, System, Connects, Messages, Email, Weather, Chat, APRS, VARA, Telnet, Rig, RF Power), **📖 Guide** (the User Guide PDF), the **Admin** menu (Maintenance, Firewall, Logs, Audit), the UTC and local clocks and the 🌙/☀️ theme button; on the admin pages also **⚙** (Dashboard Settings, §10.2). On phones the links fold behind ☰. The installer removes the Rig and RF Power links when flrig is skipped.

The theme follows the device's light/dark setting until you choose; the choice is kept per browser (`bpq-dark-mode`). The Telnet and VARA terminal areas and the RF Power meter stay dark on purpose.

> **After changing the menu:** browsers cache it. Raise `NAV_VERSION` in `bpq-nav-loader.js` and the `?v=` on every page's `bpq-nav-loader.js` script tag to the same value (System Audit flags pages that differ); users then see the change after one Ctrl+Shift+R. The 📖 Guide link names the PDF file, so update it with each version.

---

## 8. YAPP File Transfer

YAPP (Yet Another Packet Protocol) is a file transfer protocol used by BBS networks to move binary files over packet radio. The dashboard now supports YAPP directly in the browser via the **Telnet Client** page.

### How to receive a file

1. Open the **Telnet Client** page, click **BPQ Node** in the sidebar and log in at BPQ's `user:` / `password:` prompts
2. Click the **📁 Files** button under the terminal, next to **Send ↵** (it's active once you're connected)
3. Click **⚡ Switch to file-transfer mode**. It reconnects on the FBB port (`FBBPORT=` in `bpq32.cfg`, 8011 by default) with the login you just typed, enters the BBS and lists its files. *(YAPP needs the FBB port because it's a binary protocol; the standard telnet port changes some bytes.)*
4. **Click any file** in the list. The dashboard arms the receiver and types `YAPP <filename>` for you
5. The progress bar shows speed and time left
6. When complete, click **⬇ Save** to save the file. Recent downloads stay listed in the panel

### How to send a file

1. Switch to file-transfer mode as above (you're at the BBS prompt)
2. **⬆ Upload to BBS** tab → drop a file on the box or click to choose one (10 MB cap)
3. Click **⬆ Send to BBS**. There's no command to type: a BPQ BBS recognises the upload by itself and saves it in its `Files` folder. The panel warns first if the BBS already has a file of that name (BPQ won't overwrite it) and shows BPQ's message if it refuses
4. After the data is handed to the node, the panel shows *waiting for the BBS to confirm* while it goes out on air

### Where files are stored

Received files land in `/var/www/bpq-dashboard/yapp-files/` on the server with the **real filename from the YAPP header**, prefixed by a timestamp (e.g. `20260524-191234-update-bpq`).

The System Audit page reports the folder's size and warns from 500 MB.

For cleanup:

```bash
# Delete YAPP files older than 30 days
find /var/www/bpq-dashboard/yapp-files/ -mtime +30 -type f -delete
```

### Troubleshooting YAPP

**The file list is empty or says you're at the node prompt** — enter the BBS first (the panel's *Enter BBS* link, or type `BBS`), then list the files again.

**File-transfer mode says the login was refused** — it uses the BPQ user name and password you typed in that tab (or the ones you enter in the panel), not `config.php`. Check them against your `USER=` line in `bpq32.cfg`.

**Transfer stops** — a transfer is stopped only when nothing at all arrives for 2 minutes (an upload also allows for the file's time on air while the BBS confirms). If that happens the link has dropped or is too poor.

**Upload refused** — BPQ won't overwrite an existing file, and has its own size limit; the panel shows BPQ's message.

Received files whose names could run on the web server (`.php`, `.html`, `.svg`, `.js` …) are stored as `name_ext.bin`; the Save link restores the original name. The folder is behind the admin login.

---

## 9. VARA HF Terminal Setup

The VARA HF Terminal lets you make keyboard-to-keyboard HF radio connections from your browser.

### Requirements

- VARA HF modem software running (Windows PC connected to your radio, or Wine on Linux: §10.15)
- flrig running and connected to the radio (on this server or another computer; `radio` in `config.php`)
- A VARA HF port in `bpq32.cfg` (`DRIVER=VARA`)
- An FLRIG **`RADIO 2`** block in `bpq32.cfg`: the page retunes by rewriting that block (frequency changes and the End Session restore currently handle `RADIO 2` only), and `www-data` must be able to write `bpq32.cfg`
- The `bpq-vara` service running (`sudo systemctl enable --now bpq-vara`); the installer's site file passes its `/ws/vara` connection through, behind the admin login

### Configure the service

The service reads `config.php` (through `scripts/print-bbs-config.php`, like the chat service):

| Setting in `config.php` | Used for | Default |
|---|---|---|
| `bbs` host, port, user, pass | BPQ telnet login. Use a BPQ user whose default application is the BBS: the service goes BBS → `NODE` → `ATT`, and a user that lands straight at the node may not be allowed to `ATT` | — |
| `vara.bpq_port` | BPQ port number of the VARA HF port | `0` = the first `DRIVER=VARA` port in `bpq32.cfg` that isn't VARA FM (its `PORTNUM=`, or its position) |
| `vara.user`, `vara.pass` | A different BPQ login just for the VARA terminal | unset = the `bbs` login |

```bash
sudo systemctl restart bpq-vara
sudo journalctl -u bpq-vara -n 5      # "BPQ telnet … as <user>; VARA HF = BPQ port N (from …)"
```

If the login isn't set or no VARA port is found, the service stops at start-up with a `FATAL:` line saying what to set.

The page's own login is `bbs.pass` from `config.php`. *Request access* on the login box (and on the Chat page) has the server e-mail `station.email`; the address isn't shown to visitors. That needs a working mail setup on the server (PHP `mail()`, e.g. a sendmail-compatible relay); with none, or with `station.email` empty or an `example.com` address, the page says the request couldn't be sent. One request per address every 10 minutes (`cache/access-requests.json`).

### Using the VARA HF Terminal

See the User Guide, Section 7: five quick-frequency buttons (fixed in this release), any other frequency typed in MHz (ITU Region 2 data bands only; set when you press Connect), bandwidth BW500/BW2300/BW2750, **D** to drop the link and keep the port, **End Session** to release the port and restore the scan schedule.

### 9b. Supported Radios — flrig Profiles

BPQ Dashboard communicates with your radio through flrig, which acts as a universal bridge. This means it works with Yaesu, Icom, Kenwood, Elecraft, and other radios without hardware-specific code.

When the VARA HF Terminal or Rig Status page starts, the server asks flrig for the radio's name (`rig.get_xcvr`) to automatically detect your radio model. You can also force a specific profile by setting `model` in the `radio` section of config.php.

Built-in profiles (each sets the right data mode for VARA): **Yaesu** FTdx3000, FTdx10, FTdx101, FT-991, FT-710, FT-891, FT-450, FT-897, FT-857 · **Icom** IC-7300, IC-7610, IC-705, IC-7100, IC-7600, IC-7700, IC-7800, IC-9700, IC-7200 · **Kenwood** TS-590, TS-890, TS-2000 · **Elecraft** K3, KX3, K4. Matching ignores case, dashes and spaces and takes the longest name that fits, so an FTdx101D uses the FTdx101 profile, a K3S the K3, an IC-7300MK2 the IC-7300 and an FT-991A the FT-991. A radio with no profile still shows frequency, power and SWR; the dashboard just never switches its mode. Power, SWR and S-meter readings work whichever reply format your radio's flrig driver uses.

> **Important:** flrig must be running and showing your radio model in its title bar AND actively reading frequency before the dashboard can use it. Without flrig, the Rig Status page shows offline and the VARA HF Terminal can't QSY.

> **Installer note:** `install.sh` (Step 9) installs and configures flrig for you — either locally with a systemd service (flrig has no native headless mode, so this runs it under Xvfb) if the radio is connected to the same machine, or just records the host/port if flrig runs elsewhere. Either way, one manual step remains: on a local install, you still need to pick your radio model and confirm it's reading frequency via flrig's own GUI once (over `ssh -X`) before the unattended service can use it — the installer prints the exact steps at the end of that step.

---

## 10. Optional Features

### 10.1 Connect Watchdog

`scripts/connect-watchdog.py` watches the BBS log for repeated failed outgoing forwarding connects. When a partner fails 3 times within 3 hours, it replaces that partner's ConnectScript in `linmail.cfg` with a pause, and puts the original back after 4 hours, restarting LinBPQ each time so it loads the change (`systemctl stop bpq` / `start bpq`; set `bpq_stop_cmd` / `bpq_start_cmd` in its `CONFIG` if your service has another name). State is kept in `cache/watchdog-state.json`; its log is `connect-watchdog.log` in the logs folder.

Set up:

1. Edit the `PARTNERS` dict near the top of the script: one entry per partner, with the `connect_call` that appears in your ConnectScript (e.g. `W1XYZ-1`) and its `attach_port`. The detection settings (`fail_threshold`, `fail_window_mins`, `pause_hours`) are in `CONFIG` just above.
2. It edits `linmail.cfg` (found through `config.php` `paths.linbpq`) and restarts LinBPQ, so run it as root from root's crontab:
   ```
   */5 * * * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/connect-watchdog.py
   ```

### 10.2 Propagation Scheduler

`scripts/prop-scheduler.py` rewrites your HF forwarding partners' ConnectScripts in `linmail.cfg` from current conditions (NOAA K-index and solar flux), your connection history and time-of-day band models, restarts LinBPQ (`systemctl stop bpq` / `start bpq`) to load them, and sends you a report as a BBS message.

- **Partners:** `data/partners.json` — edit it on the Maintenance page (*Forwarding partners editor*; BBS password). The `PARTNERS` dict in the script is only the fallback when that file is empty.
- **Its own settings** (home position, the BBS login for the report, scoring weights, the LinBPQ service commands) are in the `CONFIG` dict at the top of the script, or in `data/settings.json`, which overrides it. Edit `settings.json` with **⚙ Dashboard Settings** on any admin page (BBS password; User Guide §19). **Save All** writes every tab, so fill in Station and BBS first. Partners listed there replace `data/partners.json`.
- **Database:** read like the log parsers — `db-config.php`, else `config.php` `db` (or `BPQ_DB_*` environment variables). Paths follow the install folder and `config.php` `paths`.
- Try it without changes first: `sudo python3 /var/www/bpq-dashboard/scripts/prop-scheduler.py` (a dry run). `--apply` writes `linmail.cfg`; the previous copy goes to `scripts/prop-backups/`. As with the watchdog, the LinBPQ service commands are in `CONFIG`.

Schedule it in root's crontab (it edits `linmail.cfg` and restarts LinBPQ):

```
0 6 */2 * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/prop-scheduler.py --apply >> /var/log/bpq-dashboard/prop-scheduler-cron.log 2>&1
```

**Optional decision history:** the installer loads the `prop_decisions` table, where the scheduler records each decision (time, Kp/SFI, band). For a manual install: `sudo mysql bpqdash < /var/www/bpq-dashboard/data/prop-decisions-schema.sql`.

### 10.3 Storm Monitor

`scripts/storm-monitor.py` is the fast companion to the scheduler: every hour it checks the planetary K-index and, when a geomagnetic storm builds, moves all HF forwarding partners to 80 m (and suspends a partner entirely above its `suspend_kp`). When conditions recover it restores the scheduler's ConnectScripts. It reads the same `data/partners.json`; its own settings are in the script's `CONFIG` or ⚙ Dashboard Settings (*Storm Mon* tab, `data/settings.json`), as for the scheduler.

```bash
sudo python3 /var/www/bpq-dashboard/scripts/storm-monitor.py --status    # current Kp and state
sudo python3 /var/www/bpq-dashboard/scripts/storm-monitor.py --restore   # put normal schedules back
```

Root's crontab:

```
0 * * * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/storm-monitor.py >> /var/log/bpq-dashboard/storm-monitor-cron.log 2>&1
```

It doesn't post weather bulletins; NWS posting is done from the NWS page (§10.4).

### 10.4 NWS Weather Alerts

The NWS page needs no setup to show alerts: it reads api.weather.gov directly in the browser. Posting an alert to your BBS (**Post to BBS**, **Post All**) goes through `nws-bbs-post.php`, which uses:

- the `bbs` login and `station.callsign` from `config.php`;
- the page's **BBS Destination** box, which starts as `'nws' => ['post_destination' => …]` from `config.php` — if that's empty, `WX@<nws.auto_post.area>`, otherwise `WX@ALLUS`. An address starting with a callsign is sent as a private message (`SP`), anything else (e.g. `WX@GA`) as a bulletin (`SB`) that floods to the BBSs carrying it;
- `features.nws_post` (on by default; forced off in public mode);
- the admin login.

Posts are logged in `/var/log/bpq-dashboard/dashboard.log` (Log Viewer → *Dashboard log*; `logs/dashboard.log` before v1.5.19). `nws-config.php.example` is left over from an older version and isn't read.

**Automatic posting (optional).** `scripts/nws-auto-post.php`, run every 5 minutes by `nws-auto-post.timer`, posts new Warnings and Watches for one state as bulletins (`SB`, through the Email Client's send code and BBS lock). It posts the **first issue** of each alert only (no updates, extensions or cancellations), skips anything that has already ended, never posts the same alert twice (`cache/nws-auto-post-state.json`), and combines a watch that several NWS offices each issued into one bulletin (waiting `watch_hold_minutes` for their pieces). Settings are `'nws' => ['auto_post' => [...]]` in `config.php` (see `config.php.example`); nothing posts until `enabled` is `true` and `area` is a state code. It does not depend on `features.nws_post` or public mode.

The installer asks whether to turn it on, and for your state, the bulletin address and which alerts. It always installs the timer's unit files, and starts the timer only if you said Yes.

| Setting | Installer default | Meaning |
|---|---|---|
| `enabled` | your answer | Nothing posts until `true` (`--dry-run` and `--seed` still work). |
| `area` | your state | 2-letter state or territory code whose alerts are read. |
| `destination` | `WX@` + state | Bulletin address. Empty = `WX@<area>`. Use a flood area your forwarding partners carry, or the bulletins stay on your BBS. |
| `events` | `['Warning', 'Watch']` | Event names ending in these. `['Warning']` = warnings only. |
| `exclude` | `[]` | Exact event names never posted, e.g. `['Red Flag Warning', 'Freeze Warning']`. |
| `watch_hold_minutes` | `10` | Wait for the other NWS offices' pieces of a watch before posting it. `0` = no wait. |
| `max_per_run` | `10` | Upper limit per 5-minute run; the rest go out on the next run. |
| `timezone` | your state's main zone | Time zone of the times printed in the bulletins. |

To turn it on later (or after an upgrade), set `'enabled' => true` and `'area' => 'XX'` in `config.php`. Check what it would post now (`--dry-run` posts and records nothing):

```bash
sudo -u www-data php /var/www/bpq-dashboard/scripts/nws-auto-post.php --dry-run
```

Optionally, mark the alerts already in force as posted, so only new ones go out:

```bash
sudo -u www-data php /var/www/bpq-dashboard/scripts/nws-auto-post.php --seed
```

Start the timer. The first two lines copy its unit files only if the installer didn't (older installs):

```bash
cd /etc/systemd/system
[ -f nws-auto-post.timer ] || sudo cp /var/www/bpq-dashboard/nws-auto-post.{service,timer} .
sudo systemctl daemon-reload
sudo systemctl enable --now nws-auto-post.timer
journalctl -u nws-auto-post -n 20 --no-pager
```

The journal shows one summary line per run. (If your web root is not `/var/www/bpq-dashboard`, use yours in these commands and in the `ExecStart=` line of `/etc/systemd/system/nws-auto-post.service`.) To stop it: `'enabled' => false`, or `sudo systemctl disable --now nws-auto-post.timer`.

"Warning" includes the less urgent ones too (Flood, Freeze, Wind, Heat, Red Flag, Winter Storm …); add any that are too chatty to `exclude`. The NWS page doesn't know what the timer has posted, so don't post the same alerts by hand. System Audit shows a row for the timer when it is switched on.

Log: `logs/nws-auto-post.log` (Log Viewer → *NWS auto-post*).

### 10.5 RF Power Monitor (FLRIG-based)

Reads power and SWR from your radio through flrig — the same connection Rig Status uses. No separate wattmeter, no file copying from another machine.

The history is written by the `rig-history-poller` service to `rig-history.jsonl` in `paths.datalog` (default the logs folder), kept 30 days: about one sample a minute when idle and every 3 seconds while transmitting. flrig reports SWR but not reflected power, so reflected watts are calculated from SWR and forward power. Watt-hours always read 0 (flrig has no energy reading). See `RF-POWER-MONITOR-MIGRATION.md` for the move from the old WaveNode-based version.

### 10.6 Data Archival

`scripts/archive-bpq-data.sh` copies BPQ's logs and the dashboard's `data/` folder into `archives/`: a 7-day archive each week (12 kept) and, in the first week of each month, a full archive of the previous month (12 kept). It only makes copies. Its default folder is an old path, so pass yours:

```
0 2 * * 0 DASHBOARD_DIR=/var/www/bpq-dashboard /var/www/bpq-dashboard/scripts/archive-bpq-data.sh >> /var/log/bpq-archive.log 2>&1
```

### 10.7 PTT Indicator and Remote Power Control

Rig Status always shows PTT (receive, transmit). It can also switch a network power switch for the radio (written for a Synaccess NP-05B-style switch). Fill in the `smartplug` section of `config.php` (it's in `config.php.example`; on an older `config.php`, add it inside `return [ … ];`):

```php
'smartplug' => [
    'host'   => '192.168.1.60',
    'user'   => 'admin',
    'pass'   => 'secret',
    'outlet' => 1,
],
```

With `host` empty, or no section at all, the outlet card shows **Not set up** and nothing is contacted (versions before 2026-10-27 tried `192.168.1.100` with `admin`/`admin`; if your switch really is there with those settings, write them into the section). **Power OFF** and **Reboot** are refused while the radio is transmitting.

The same 🔒 unlock (the BBS password) also enables the **TX Power** card: a slider, a number box and presets set the radio's power through flrig (`rig.set_power`). The range is the radio's own, limited by `radio.max_power` / `radio.min_power` when set. Each change is written to the PHP error log with the requesting address.

### 10.8 RF Connections database optimization (recommended)

> **Already done if you used `install.sh`:** it loads the schema and schedules the parser (`/etc/cron.d/bpq-dashboard`). The steps below are for manual installs and for background.
>
> **Strongly recommended** — makes the RF Connections page load in under a second instead of several seconds. Required if you want to use the Phase 1 fast path.

The RF Connections page can either parse your VARA log file on every page load (the legacy fallback) or read pre-digested session records from a MySQL/MariaDB table (the fast path). The fast path requires:

1. MariaDB/MySQL installed and the `bpqdash` database (section 4, Part B step 2)
2. The `rf_connections` schema applied (one-time)
3. A cron job that runs `parse-vara-log.py` every few minutes to keep the table fresh

**One-time setup:**

```bash
# 1. Apply the schema and its updates (rf_connections, rf_parser_state, rf_messages)
sudo mysql bpqdash < /var/www/bpq-dashboard/data/rf-connections-schema.sql
for f in 2026-09-21-rf-messages-medium 2026-10-06-rf-messages-delivery; do sudo mysql bpqdash < sql/$f.sql; done   # from the unzipped folder

# 2. Install pymysql for the parser
sudo apt install -y python3-pymysql

# 3. Initial backfill — walks the entire VARA log once
sudo -u www-data python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py --bbs-log-dir /var/www/bpq-dashboard/logs --my-callsign W1AW --reset --verbose

# 4. Verify the table populated
sudo mysql bpqdash -e "SELECT COUNT(*) AS sessions FROM rf_connections"
```

**Schedule incremental updates:** the parser line in `/etc/cron.d/bpq-dashboard` (Section 4, Part B, step 8). Its log is `/var/log/bpq-dashboard/parse-vara-log.log`. `--bbs-log-dir` and `--my-callsign` matter: without them forwarded messages and frequencies aren't picked up.

**Verify the fast path is active:**

After hard-refreshing the RF Connections page, the status pill in the upper right should read:

> ✓ **N connections (DB)**

The **(DB)** suffix confirms the page is using the database. If you see only "N connections" without it, the page fell back to reading the log files in the browser — check the parser log and the browser console for `[RF API]` messages.

For more detail, troubleshooting, and rollback procedures, see `docs/PHASE-1-DEPLOYMENT.md`.

> **Note:** The parser user needs `SELECT/INSERT/UPDATE/DELETE` privileges on the `bpqdash` database — standard CRUD, no need for DROP or ALTER. The `bpqdash` user created by the installer (or in section 4, Part B step 2) has all required grants.

### 10.9 VARA HF Callsign Validator

A transparent TCP proxy that sits between BPQ32 and your VARA HF modem to **reject malformed or unauthorized callsigns before BPQ32 ever sees the session**. Useful if you operate a public-facing gateway and want to protect your BBS from connection attempts from invalid or unlicensed callsigns.

**Architecture:**

```
   VARA HF (Windows or Wine)  e.g. 127.0.0.1:8300/8301
            ↑  validator forwards here after callsign check
   [validator] listening on 127.0.0.1:9025/9026  (config.php)
            ↑  BPQ32 connects to validator instead of VARA directly
   BPQ32 (Linux)
```

**Validation rules:**

- ITU Radio Regulations Appendix 42 callsign format (prefix, digit, suffix; SSID and /portable removed first)
- an allow list (always accepted) and a deny list (always refused, default `NOCALL TEST N0CALL INVALID`)

**Install** (v1.5.19: the installer's page question *VARA callsign check* does steps 1–2; it runs as the web user from the dashboard folder and logs to `/var/log/bpq-dashboard/vara-validator.log`):

```bash
# 1. Settings: config.php
sudo nano /var/www/bpq-dashboard/config.php
#   'vara_validator' => [
#       'vara_host'       => '127.0.0.1',  // where VARA HF listens (its command port; data = +1)
#       'vara_cmd_port'   => 8300,
#       'listen_host'     => '127.0.0.1',  // where BPQ connects ('0.0.0.0' only if BPQ is on another machine)
#       'listen_cmd_port' => 9025,         // data = 9026
#       'allow' => [], 'deny' => ['NOCALL', 'TEST', 'N0CALL', 'INVALID'],
#   ],
#   and 'pages' => ['vara_validator' => true, ...]

# 2. The service
sed 's|/var/www/bpq-dashboard|/var/www/bpq-dashboard|g' /var/www/bpq-dashboard/vara-validator.service \
  | sudo tee /etc/systemd/system/vara-validator.service >/dev/null
sudo systemctl daemon-reload
sudo systemctl enable --now vara-validator

# 3. Point BPQ32 at the validator instead of VARA directly:
#    In bpq32.cfg, the VARA port's ADDR line becomes 127.0.0.1 9025
#    (listen_host / listen_cmd_port) instead of VARA HF's own address.

# 4. Restart LinBPQ (your service name, e.g. bpq or linbpq) to pick up the new target.
sudo systemctl restart bpq
```

Before v1.5.19 the unit said `User=pi` and `/usr/local/bin/vara-callsign-validator.py`, and the settings were inside the script; copy any you changed into `config.php`, install the new unit, and delete the old copy in `/usr/local/bin`.

**Verify:**

```bash
ss -tlnp | grep -E ':902[56]\b'                                  # listening
sudo tail -f /var/log/bpq-dashboard/vara-validator.log           # verdicts (Log Viewer → VARA Validator)
```

Each incoming connection logs `ACCEPTED` or `REJECTED` with the callsign and the reason; a rejected one is dropped before BPQ32 receives a CONNECT event.

### 10.10 Winlink White Pages cleanup tools

Two scripts for maintaining a clean Winlink White Pages database (`WP.cfg`):

- **`wp_scanner.py`** — read-only scan that reports bogus, invalid, or stale entries
- **`wp_manager.py`** — interactive workflow to safely remove flagged entries while preserving R-numbers (so Winlink/BPQ32 internal references stay intact)

**Manual workflow:**

```bash
# 1. Scan and produce a review file
python3 /var/www/bpq-dashboard/scripts/wp_manager.py --scan

# 2. Edit wp_review.txt — change "?" to REMOVE or KEEP for each entry

# 3. Apply your decisions
python3 /var/www/bpq-dashboard/scripts/wp_manager.py --apply

# 4. Check for changes after the next WP sync
python3 /var/www/bpq-dashboard/scripts/wp_manager.py --diff
```

**Automated cleanup via cron** (only removes entries with obvious format errors, never stale-but-valid ones):

```bash
# Edit www-data's crontab
sudo crontab -u www-data -e

# Add: daily auto-clean at 03:00 UTC
0 3 * * * cd /opt/linbpq && python3 /var/www/bpq-dashboard/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1
```

> Use your LinBPQ folder in the `cd` (the script works on `WP.cfg` there, or pass `--wp /path/WP.cfg`). **Caution:** run `--auto-clean --dry-run` first to preview what would be removed. Bogus auto-cleanup of valid entries could break Winlink message routing.

### 10.11 VARA log collection

RF Connections is built from VARA HF's session log (`… VARAHF <CALL> connected VARA HF`, bitrate and S/N lines). It travels like this:

**VARA HF** (Syslog on) → UDP 514 → **rsyslog** on this server → `/var/log/syslog` → **`vara-logger`** → `logs/<callsign>.vara` → `parse-vara-log.py` (every 5 minutes) → the database → RF Connections.

**If you answered Yes to the installer's VARA HF log question**, the server side is done: rsyslog is installed and accepts UDP 514 from the VARA computer only (`/etc/rsyslog.d/10-bpq-dashboard-vara.conf`), ufw (if active) lets that address in, and `vara-logger` is running. Do step 1 below in VARA HF, then the test in step 5.

**1. VARA HF — send the log.** Syslog is in the **registered (paid) version** of VARA HF. In VARA HF: **Settings → VARA Setup**:

| Setting | Value |
|---|---|
| **Syslog** | ticked |
| **Syslog Host** | this server's IP address (e.g. `192.168.1.10`); `localhost` only if VARA runs on this server |
| **UDP Port** | `514` |
| **Station Name** | `H-` + your VARA call-SSID, e.g. `H-W1AW-7`. RF Connections looks for lines from `H-…`, so keep the `H-` |

Give the VARA computer a fixed address (a DHCP reservation in your router): the receiver only accepts that address.

**2. rsyslog — receive it** (by hand; the installer does this). Debian 12 doesn't install rsyslog, so:

```bash
sudo apt install -y rsyslog
grep -rn imudp /etc/rsyslog.conf /etc/rsyslog.d/ | grep -v ':#'
```

If the `grep` prints nothing (only commented-out `#` lines exist), create the receiver (replace `192.168.1.50` with the VARA computer's address):

```bash
sudo tee /etc/rsyslog.d/10-bpq-dashboard-vara.conf >/dev/null <<'EOF'
# BPQ Dashboard: receive VARA HF's syslog on UDP 514, from these senders only
$AllowedSender UDP, 127.0.0.1, 192.168.1.50
module(load="imudp")
input(type="imudp" port="514")
EOF
sudo rsyslogd -N1 && sudo systemctl restart rsyslog
ss -uln | grep ':514 '
```

`rsyslogd -N1` must end with "End of config validation run"; `ss` shows `0.0.0.0:514`. If the `grep` printed a line, UDP reception is already on: don't add a second receiver: add the VARA computer to that receiver's allowed senders instead.

**3. Firewall — let only the VARA computer in:**

```bash
sudo ufw allow from 192.168.1.50 to any port 514 proto udp comment 'VARA HF syslog'
```

(Other firewalls: allow UDP 514 from that address only. Never open 514 to the internet.)

**4. vara-logger — keep the lines.** `vara-logger.sh` copies every new `VARAHF` line from `/var/log/syslog` into `logs/<callsign>.vara` (`logs.vara_file` in `config.php`), a file that survives log rotation. rsyslog on Debian 12 and Ubuntu 24.04 writes timestamps like `2026-10-27T13:15:48-04:00`; vara-logger rewrites them to the `Oct 27 13:15:48` form the parsers read. The installer sets the file name and installs the service; by hand:

```bash
sudo nano /var/www/bpq-dashboard/vara-logger.sh        # set OUTPUT_FILE to …/logs/<yourcall>.vara
sudo cp /var/www/bpq-dashboard/vara-logger.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now vara-logger
```

**5. Test.**

```bash
logger -n 127.0.0.1 -P 514 -d -t VARATEST "receiver test"; sleep 1; grep VARATEST /var/log/syslog | tail -1
```

shows the test line (rsyslog works; the `VARATEST` tag keeps it out of the VARA log). Then make or receive a VARA HF connection:

```bash
grep VARAHF /var/log/syslog | tail -3                   # arrived from VARA
tail -3 /var/www/bpq-dashboard/logs/<yourcall>.vara     # kept by vara-logger
```

The lines start with the time and `H-<your call-SSID> VARAHF`. Within 5 minutes the session is on RF Connections. Install Check's *VARA HF log* rows show the receiver and when the last line arrived.

**If nothing arrives:** VARA's Syslog box ticked and the Host/port right; the VARA computer's address is the one allowed (rsyslog and firewall); a Windows firewall on the VARA computer isn't blocking outgoing UDP 514; `sudo systemctl status rsyslog vara-logger`.

**TPRFN stations: the log from the TPRFN server (v1.5.19).** If your VARA HF sends its syslog to the TPRFN server instead of this machine, the server keeps a copy for your station (`https://<TPRFN server>/<call>.vara`; the TPRFN server admin sets it up and gives you the address) and the dashboard downloads it. No receiver, rsyslog rule or vara-logger here. The installer does this when you choose **2** at the VARA log question; by hand:

```bash
sudo nano /var/www/bpq-dashboard/config.php
#   'vara_remote' => ['enabled' => true, 'url' => 'https://<TPRFN server>/w1aw.vara'],
#   'logs'        => ['vara_file' => '/var/log/bpq-dashboard/w1aw.vara'],
W=/var/www/bpq-dashboard
sed "s|/var/www/bpq-dashboard|$W|g" $W/vara-remote.service | sudo tee /etc/systemd/system/vara-remote.service >/dev/null
sudo cp $W/vara-remote.timer /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now vara-remote.timer
sudo -u www-data python3 $W/scripts/fetch-vara-remote.py -v      # test now
```

Every 5 minutes (at :03, :08 …, two minutes after the server rebuilds the file) it fetches only the new part, writes through a temporary file and keeps the last good copy if the server can't be reached; if the server's file got shorter it fetches it all again. It sends a `BPQ-Dashboard/<version> (<CALL>)` user agent (the server's bot filter refuses plain curl). Log: `/var/log/bpq-dashboard/vara-remote.log` (Log Viewer → VARA download); Install Check and System Audit show the last good download. *HTTP 404* = no file for your call on the server yet.

### 10.12 NetROM RTT collection

`nodes-rtt.sh` (in the dashboard folder) logs in to the node, runs `n t` and writes the NetROM nodes with their round-trip times to `logs/nodesrtt.txt`. `parse-system-logs.py` loads it; System Logs shows the RTT on the *Internet Connected Stations* tiles.

```bash
sudo apt install -y expect
sudo nano /var/www/bpq-dashboard/nodes-rtt.sh
# Set TELNET_HOST, TELNET_PORT, TELNET_USER, TELNET_PASS and OUTPUT_FILE
sudo chmod 700 /var/www/bpq-dashboard/nodes-rtt.sh      # it holds a password
```

It writes into the LinBPQ logs folder (through the `logs` link), so run it as a user that may write there, e.g. root's crontab:

```
*/10 * * * * /var/www/bpq-dashboard/nodes-rtt.sh
```

### 10.13 VHF/UHF packet with a sound modem (QtSoundModem)

QtSoundModem (G8BPQ's version of the UZ7HO sound modem) turns a USB sound interface into a 1200-baud packet TNC that LinBPQ uses over KISS TCP. RF Connections then shows that port in its *VHF Connections Status* card, and System Logs an MHeard tab named from the port's `ID=`. Written from a Debian 12 install with a Digirig; other interfaces differ only in the PTT setting.

1. **Find the interface.** With it plugged in: `lsusb` (a Digirig is a C-Media audio codec plus a CP2102 serial chip), `arecord -l` (its card number and name, e.g. card 1 "Device") and `ls -l /dev/serial/by-id/` (its serial port).
2. **Install** (64-bit Intel; `piQtSoundModem64` for a Pi):
   ```bash
   sudo mkdir -p /opt/qtsoundmodem && cd /opt/qtsoundmodem
   sudo wget -O QtSoundModem64 https://www.cantab.net/users/john.wiseman/Downloads/Beta/QtSoundModem64
   sudo chmod 755 QtSoundModem64 && sudo chown -R YOURUSER:YOURUSER /opt/qtsoundmodem
   sudo apt install -y libqt5serialport5 libqt5multimedia5 libqt5multimedia5-plugins libfftw3-single3 alsa-utils
   sudo usermod -aG audio,dialout YOURUSER
   ldd QtSoundModem64 | grep "not found"      # should print nothing
   ```
3. **Keep the desktop's sound system off the interface**, so system sounds can never go out over the radio. PulseAudio (replace the IDs with yours from `lsusb`):
   ```
   # /etc/udev/rules.d/89-digirig-no-pulse.rules
   SUBSYSTEM=="sound", ATTRS{idVendor}=="0d8c", ATTRS{idProduct}=="0012", ENV{PULSE_IGNORE}="1"
   ```
   and give the PTT port a fixed short name (`serial` from `udevadm info -a -n /dev/ttyUSB0`):
   ```
   # /etc/udev/rules.d/89-digirig-ptt.rules
   SUBSYSTEM=="tty", ATTRS{idVendor}=="10c4", ATTRS{idProduct}=="ea60", ATTRS{serial}=="YOURSERIAL", SYMLINK+="digirig-ptt"
   ```
   then `sudo udevadm control --reload && sudo udevadm trigger`. Use the short name: QtSoundModem can't take the long `/dev/serial/by-id/...` path.
4. **Set it up once in its window**, from a desktop terminal: `cd /opt/qtsoundmodem && ./QtSoundModem64`. Without a desktop, it can show its window over VNC: `QT_QPA_PLATFORM=vnc:size=1280x900:port=5905 ./QtSoundModem64`, then a VNC viewer on port 5905 (open that port to your own address only while you use it).
   - *Settings → Devices*: sound in and out = the **plughw** entry of the interface (the card runs at 44.1/48 kHz, plughw converts to the modem's 12000); PTT port `digirig-ptt`, **RTS**; **Dual PTT off** (with it on, QtSoundModem keys DTR, and a Digirig doesn't key); KISS server on, port 8100; AGW off unless something uses it.
   - *Settings → Modems*: channel A = AFSK AX.25 1200bd.
   - Levels: `alsamixer -c 1` (F4 capture: Mic about 40 % with capture on; F3 playback: Speaker about 50 %), then `sudo alsactl store 1`. Received packets should decode in the monitor pane; the Calibration buttons key the radio with tones.
5. **Run it as a service** without its window:
   ```ini
   # /etc/systemd/system/qtsoundmodem.service
   [Unit]
   Description=QtSoundModem - KISS on port 8100
   After=sound.target network.target
   Before=bpq.service
   [Service]
   User=YOURUSER
   Group=YOURUSER
   SupplementaryGroups=audio dialout
   WorkingDirectory=/opt/qtsoundmodem
   ExecStart=/opt/qtsoundmodem/QtSoundModem64 nogui
   Restart=on-failure
   RestartSec=10
   [Install]
   WantedBy=multi-user.target
   ```
   `sudo systemctl daemon-reload && sudo systemctl enable --now qtsoundmodem`, then `ss -ltnp | grep 8100` shows it listening.
6. **Add the LinBPQ port** (back up `bpq32.cfg` first; use a free port number) and restart LinBPQ:
   ```
   PORT
    PORTNUM=4
    ID=VHF Packet 1200 (QtSoundModem)
    TYPE=ASYNC
    PROTOCOL=KISS
    IPADDR=127.0.0.1
    TCPPORT=8100
    CHANNEL=A
    QUALITY=192
    MAXFRAME=2
    FRACK=7000
    RESPTIME=1000
    RETRIES=10
    PACLEN=128
    TXDELAY=300
    SLOTTIME=100
    PERSIST=64
   ENDPORT
   ```
   `ss -tnp | grep 8100` then shows an ESTAB connection from linbpq, and RF Connections' *VHF Connections Status* card shows the port **On air**. `MH 4` lists stations heard; a `C 4 CALL` connect proves both directions.

### 10.14 Winlink: an RF gateway as the outage route

A BPQ RMS gateway reaches the Winlink CMS over the internet (`APPLICATION n,RMS,C 1 CMS,...`). With `FALLBACKTORELAY=1` and `RELAYAPPL=BBS` in the telnet port, users' sessions go to your BBS when the CMS can't be reached, and the BBS keeps their mail. To let the BBS send mail on through a nearby Winlink RF gateway while the internet is down, give the BBS's RMS forwarding partner an `ELSE` step (stop LinBPQ first: BPQ Mail rewrites `linmail.cfg` when it stops):

```
ConnectScript = "attach 1|nofallback|c cms|ELSE|C 4 GATEWAY-10";
```

Internet first, as before; only if `c cms` fails does it connect over port 4. Agree it with the gateway's sysop first. Test by blocking the CMS port for a few minutes (`sudo iptables -I OUTPUT -p tcp --dport 8772 -j REJECT`, remove with `-D` instead of `-I`) and forwarding a message: the BBS log shows `c cms` failing, then the gateway connect and `FS Y`. Through another gateway the BBS signs in as an ordinary user (`;FW:` lists only its own call), so your own mail goes; mail from other users may be refused there and then waits for the internet route. Nothing is lost either way. Since v1.5.24 these forwards show on RF Connections → Forwarded Messages, Via *📮 Winlink (CMS)*, with `WINLINK@GATEWAY-10` as the route. Don't schedule test connects to the gateway (each opens a CMS session); to know it's still there, watch it instead: add it on **Admin → Maintenance → Watched stations** (v1.5.26; in v1.5.25 `'system_logs' => ['watch' => [['call' => 'GATEWAY-10', 'port' => 4, 'hours' => 6]]]` in `config.php`), which flags it on System Logs and in RF Connections' *VHF Connections Status* card when it hasn't been heard for 6 hours.

### 10.15 VARA HF under Wine (no Windows PC)

VARA HF is a Windows program, but it runs on the dashboard's Linux server under **Wine**. Wine runs VARA's own x86 code and translates its Windows calls, so on a 64-bit Intel/AMD server there's no speed penalty and on-air throughput is the same; it is not an option on ARM (Raspberry Pi). What you trade: VARA needs a desktop session to run in, the Wine setup is fussier than Windows (re-test after Wine or VARA updates), and serial-port tricks are best kept out of Wine. The dashboard sees no difference: it is the same VARA writing the same log. Written from K1AJD's move (Debian 12, Wine 11, VARA HF 4.9.1, Yaesu FTdx-3000 with an SCU-17); other radios and interfaces differ only in the device IDs and the flrig rig.

How the pieces connect:

```
radio -- USB interface (sound card + CAT serial port) -- this server
  sound card ← VARA HF (Wine)  TCP 8300/8301 ← LinBPQ VARA port (DRIVER=VARA, PTT CAT)
  CAT port   ← flrig           TCP 12345     ← LinBPQ rig control (FLRIG) + dashboard Rig pages
  VARA syslog → 127.0.0.1:514 → rsyslog → vara-logger → logs/<call>.vara → RF Connections (§10.11)
```

**Keying:** VARA's own PTT is set to *None*. When VARA wants to transmit it tells LinBPQ, LinBPQ asks flrig, and flrig sends the radio's CAT transmit command. Only flrig ever opens the CAT port. (On Windows the same was true in practice: two programs can't open one COM port.)

**Before you start:** set the radio's **TX time-out timer** (e.g. TX TOT 3 min) so a stuck transmit can't run on, and keep the old Windows setup untouched as the way back until the server has run cleanly for a while.

1. **Plug the interface into the server and give its devices fixed names.** `lsusb` shows the parts (SCU-17: a TI PCM2903B codec `08bb:29b3` and a CP2105 dual serial bridge `10c4:ea70`; radios with built-in USB look similar). A udev rule keeps the desktop sound system off the sound card and gives the serial ports names that survive reboots:
   ```
   # /etc/udev/rules.d/89-scu17.rules
   SUBSYSTEM=="sound", ATTRS{idVendor}=="08bb", ENV{PULSE_IGNORE}="1"
   SUBSYSTEM=="tty", ATTRS{idVendor}=="10c4", ATTRS{idProduct}=="ea70", \
     ENV{ID_USB_INTERFACE_NUM}=="00", SYMLINK+="scu17-cat"
   ```
   `sudo udevadm control --reload`, re-plug, `ls -l /dev/scu17-cat`. On the SCU-17 the first port (*Enhanced*) is CAT.
2. **More than one USB sound card? Fix their numbers.** QtSoundModem (§10.13) picks its card by number; a second card can swap the numbers at the next boot and send packet audio into the wrong radio. Pin them by USB ID, e.g. HF interface = card 0, Digirig = card 1:
   ```
   # /etc/modprobe.d/bpq-usb-audio.conf
   options snd-usb-audio index=0,1 vid=0x08bb,0x0d8c pid=0x29b3,0x0012
   ```
   Check after plugging in with `arecord -l`.
3. **flrig on the server** (`sudo apt install flrig`, or a newer build). Run it once in the desktop as the station user: *Config → Setup → Transceiver*: your rig, port `/dev/scu17-cat` (a short name: flrig cuts long `/dev/serial/by-id/…` paths), the radio's CAT baud, and **RTS/CTS** only if the radio's *CAT RTS* menu is on (both sides must agree); *PTT*: **CAT** only, no RTS/DTR, *separate PTT port* = NONE; *Server*: 127.0.0.1, 12345. Mixed PTT settings (CAT plus RTS plus a missing separate port) keyed K1AJD's radio and left it transmitting. Check the settings file (`~/.flrig/<RIG>.prefs`: `ptt_via_cat` set, `ptt_via_rts:0`, `sep_serial_port:NONE`), then test into a dummy load at low power — this always sends "transmit off", even after an error:
   ```bash
   python3 - <<'EOF'
   import xmlrpc.client, time
   r = xmlrpc.client.ServerProxy("http://127.0.0.1:12345")
   print("freq:", r.rig.get_vfo())
   try:
       r.rig.set_ptt(1); time.sleep(2)
   finally:
       r.rig.set_ptt(0)
   time.sleep(1); print("PTT now:", r.rig.get_ptt())   # 0 = receive
   EOF
   ```
4. **VARA HF in its own 32-bit Wine prefix**, in a terminal inside the desktop (not over SSH), as the station user:
   ```bash
   export WINEPREFIX=$HOME/.wine-vara WINEARCH=win32 WINEDEBUG=-all
   wineboot -i
   winetricks -q vb6run pdh_nt4 comctl32ocx comdlg32ocx sound=alsa
   ```
   Download the **full** VARA HF setup zip (downloads.winlink.org → VARA Products), unzip it into a folder of its own and run the setup from there: it installs `MSCOMM32.OCX` from the `OCX` folder beside it (run on its own, it stops with *MSCOMM32.OCX not found*). Wine's `wineusb` / `winebth` "Failed to create device" lines are harmless. Start it with `wine "$WINEPREFIX/drive_c/VARA/VARA.exe" &`, then: register it; *VARA Setup*: TCP command port 8300 (data 8301), **Syslog** to `127.0.0.1` port 514 with Station Name `H-<CALL-SSID>` (the name vara-logger filters on, §10.11); *SoundCard*: the HF interface for input and output (not the packet radio's); **PTT: None**; drive level as before.
5. **Point LinBPQ and the dashboard at it** (back up both files first):
   ```
   bpq32.cfg, the VARA port:    ADDR 127.0.0.1 8300 PTT CAT
   bpq32.cfg, rig control:      FLRIG 127.0.0.1:12345
   config.php:                  'radio' => ['flrig_host' => '127.0.0.1', ...]
   ```
   Restart LinBPQ; `ss -tnp | grep -E ':8300|:8301|:12345'` shows linbpq connected to all three. (Using the callsign validator, §10.9: LinBPQ to 9025, the validator's upstream 127.0.0.1:8300.)
6. **Start it at boot.** Run the desktop as a service: a TigerVNC session (`/etc/tigervnc/vncserver.users` `:3=YOURUSER`, `sudo systemctl enable --now tigervncserver@:3`, and a drop-in with `Restart=always` so a Log Out brings it back). In it, an autostart script starts flrig, then VARA, and starts either again if it closes:
   ```bash
   #!/bin/bash
   # ~/bin/hf-station.sh, run by ~/.config/autostart/hf-station.desktop
   [ "${DISPLAY%%.*}" = ":3" ] || exit 0       # only in the VNC desktop
   exec 9>/tmp/hf-station-$USER.lock; flock -n 9 || exit 0
   exec >>"$HOME/.cache/hf-station.log" 2>&1
   export WINEPREFIX=$HOME/.wine-vara WINEDEBUG=-all
   # stop when this desktop's X server goes (else an old copy keeps the
   # lock and the next desktop's copy quits)
   XPID=$(pgrep -u "$USER" -f "Xtigervnc :3" | head -1)
   alive() { [ -n "$XPID" ] && kill -0 "$XPID" 2>/dev/null; }
   # 9>&- : don't hand the lock to flrig / VARA
   ( while alive; do
       pgrep -u "$USER" -x flrig >/dev/null || flrig 9>&-
       sleep 10
     done ) &
   sleep 10
   while alive; do
       pgrep -u "$USER" -f 'VARA\.exe' >/dev/null ||
           wine "$WINEPREFIX/drive_c/VARA/VARA.exe" 9>&-
       sleep 10
   done
   ```
   Under Wine VARA's process is named `C:\VARA\VARA.exe`, so look for it with `pgrep -af 'VARA\.exe'` (a pattern with `/` never matches), and note that the `wine` command returns while VARA keeps running. Start only **one** way: the TigerVNC service, not also an old `@reboot vncserver` crontab line (both racing for :3 makes the service restart in a loop). Add to the service's drop-in `ExecStopPost=-/usr/bin/pkill -u YOURUSER -f hf-station.sh` (and the same for `-x flrig` and `-f VARA.exe`) so a desktop restart leaves nothing behind, and make LinBPQ wait for flrig at boot — it doesn't reconnect to flrig later:
   ```
   # /etc/systemd/system/bpq.service.d/wait-flrig.conf
   [Unit]
   After=tigervncserver@:3.service
   [Service]
   ExecStartPre=/bin/sh -c 'for i in $(seq 60); do ss -ltn | grep -q ":12345 " && exit 0; sleep 2; done; exit 0'
   TimeoutStartSec=180
   ```
   If another sound driver setting exists (`grep -rn snd-usb-audio /etc/modprobe.d/`), comment it out: a later file overrides the card numbers. And after changing flrig's setup, check `~/.flrig/<RIG>.prefs` for a garbled `sep_serial_port` (flrig 2.0.10 can leave part of an old long path after "NONE"; it then fails with *Cannot access NONE…*): it must read exactly `sep_serial_port:NONE`.
7. **Test on the air.** Start a forward to a partner on the current band: flrig follows the script's `RADIO` command, the radio keys and releases in bursts, the session completes, and `tail -4 logs/<call>.vara` shows the session (S/N, bytes, speed) — RF Connections lists it at the next parse. Then restart the desktop (`sudo systemctl restart tigervncserver@:3`) and check flrig and VARA come back and LinBPQ reconnects.

**Way back:** put the two backed-up files back, restart LinBPQ, close VARA and flrig on the server, move the USB cable to the Windows PC and start them there.

---

## 11. Security Hardening

> **Important:** Do not skip this section if your server is accessible from the internet.

### Admin login (required) and LAN-only (optional)

Everything in `/admin/`, `install-check.php` and `health-check.php` must ask for the admin login. Check it from any machine (each line should print `401`):

```bash
for u in /admin/bpq-maintenance.html "/admin/partners-api.php?action=validate" /install-check.php /health-check.php; do
  curl -s -o /dev/null -w "%{http_code}  $u\n" -A 'Mozilla/5.0' "https://YOUR_HOST$u"
done
```

`install.sh` and `bpq-dashboard.conf` set this up with one `location ^~ /admin/` block. If several site names (server blocks) share the same web root, each one needs it, or a redirect to the protected one. Before v1.5.13 the templates protected only the old top-level page names, which no longer exist, so re-check older installs. The Maintenance page also shows the result at the top every time it opens.

To **also** allow the admin pages only from your home network, add `allow`/`deny` lines inside that same `/admin/` block. `nginx-maintenance-block.conf` shows where. Don't add a second `/admin/` block.

```nginx
location ^~ /admin/ {
    auth_basic           "YOURCALL Admin";
    auth_basic_user_file /etc/nginx/.bpq-mgmt-auth;
    allow 10.0.0.0/8;
    allow 192.168.0.0/16;
    allow 127.0.0.1;
    deny all;
    # ... the existing nested PHP block and try_files stay as they are
}
```

Then: `sudo nginx -t && sudo systemctl reload nginx && echo RELOADED`. Check that `RELOADED` prints: until nginx reloads, it keeps using the old rules even though the file has changed.

### HTTPS and the firewall (IPv6 too)

If the dashboard can be reached from outside your home network:

1. **Use HTTPS**, so the login and your mail aren't sent in clear text: `sudo certbot --nginx -d your.domain`.
2. **Check the firewall for IPv4 and IPv6.** Many home connections now give the server a public IPv6 address that bypasses the router's port forwarding, and LinBPQ listens on every address by default. Only 80/443, and anything you mean to share such as forwarding ports, should be open:
   ```bash
   sudo iptables -S INPUT | head -3
   sudo ip6tables -S INPUT | head -3      # policy should be DROP, not ACCEPT
   sudo ss -tlnp | grep -E 'linbpq|:80 |:443 '
   ```
3. Read `SECURITY.md` (in the dashboard folder). It lists everything that matters for running the dashboard safely and how to report a security problem.

**No firewall yet? (v1.5.19)** `tools/setup-firewall.sh` sets up ufw for a packet station. It asks: your home network (detected, you confirm it), SSH from home only or anywhere, the dashboard from home only or anywhere, BPQ telnet/FBB from home only or anywhere (for forwarding partners who connect in). BPQ's web-management port stays home-only; VARA HF's syslog (UDP 514, from the VARA computer) and BPQ AXIP/AXUDP ports from `bpq32.cfg` are added by themselves. The address of the SSH session you run it from is always allowed, so you can't lock yourself out. It shows every rule and asks again before switching ufw on, and offers fail2ban. On a server where ufw is already on, it only adds the rules that are missing.

```bash
sudo bash /var/www/bpq-dashboard/tools/setup-firewall.sh --dry-run   # questions + the rules, nothing changed
sudo bash /var/www/bpq-dashboard/tools/setup-firewall.sh             # the real run
sudo ufw status numbered                                             # check;  undo: sudo ufw disable  (or ufw reset)
```

The installer offers it as its last step (default No).

### Protect the data directory (Apache only)

The nginx configuration from the installer already refuses `/data/`. On Apache:

```bash
echo "deny from all" | sudo tee /var/www/bpq-dashboard/data/.htaccess
```

### Change default passwords

- Linux user password: `passwd`
- BPQ BBS password — change in bpq32.cfg AND update config.php to match
- Dashboard admin login (Email Client, Chat, admin pages, Install Check, Health Check) — Maintenance → *Admin login* → **Change admin password** (v1.5.19), or over SSH `sudo htpasswd -B /etc/nginx/.bpq-mgmt-auth admin` (use your admin user name; it asks for the new password)

### Configure sudoers for www-data

The Firewall Status and System Audit pages need read-only access to a few system commands. The package has the rules ready in `www-data-tools.sudoers`:

```bash
sudo cp /var/www/bpq-dashboard/www-data-tools.sudoers /etc/sudoers.d/www-data-tools
sudo chmod 440 /etc/sudoers.d/www-data-tools
sudo visudo -c
```

It allows exactly these commands, with exactly these arguments, and nothing else:

```
www-data ALL=(root) NOPASSWD: /usr/sbin/iptables -L -n --line-numbers -v
www-data ALL=(root) NOPASSWD: /usr/sbin/ip6tables -L -n --line-numbers -v
www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client status
www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client status *
www-data ALL=(root) NOPASSWD: /usr/bin/ss -tlnp
www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -t
```

v1.5.19 adds one rule that changes something: the Maintenance page's *Change admin password*. It may only run a small root-owned helper, which changes the password of an admin login already in `/etc/nginx/.bpq-mgmt-auth` (password on stdin, never logged):

```
www-data ALL=(root) NOPASSWD: /usr/local/sbin/bpq-dashboard-set-admin-password *
```

The helper must be a root-owned copy outside the web folder (the web user can write in the web folder, so a sudo rule pointing there would hand it root):

```bash
sudo install -o root -g root -m 755 /var/www/bpq-dashboard/tools/set-admin-password /usr/local/sbin/bpq-dashboard-set-admin-password
```

Without the helper or the rule, the page says so and shows the `htpasswd` command instead.

> Both `status` and `status *` lines are needed: in sudoers `*` needs at least one argument, so it doesn't cover bare `status` (the jail list).
>
> Paths are Debian / Ubuntu's; `command -v iptables ip6tables fail2ban-client ss nginx` shows yours. A sudo rule must match the command exactly, so after an upgrade copy the file again (the 2026-10-25 version replaced rules that no longer matched what the pages run).

The pages run these with `sudo -n`, so a missing rule never hangs a page: the row says what it couldn't check and shows the command above. Everything else on both pages works without sudo.

### Public-mode

If the dashboard will be internet-facing, set in config.php:

```php
'security_mode' => 'public',
```

This turns off everything that writes to the BBS (Send, Forward, Kill in the Email Client, and NWS posting), turns on the dashboard's own rate limiting and keeps CORS same-origin. It doesn't replace the admin login; keep that on everything that acts as your station.

---

## 12. Troubleshooting

When something stops working, always start with the **System Audit** page (`/admin/system-audit.html`). It diagnoses most problems automatically.

### Page won't load — "This site can't be reached"

nginx is not running or has a configuration error.

```bash
sudo systemctl status nginx
sudo systemctl start nginx
sudo nginx -t
sudo journalctl -u nginx -n 30
```

### 502 Bad Gateway

nginx can't reach PHP: either PHP-FPM is not running, or the site file points at a socket that doesn't exist.

```bash
systemctl list-units 'php*-fpm*'          # shows your version, e.g. php8.5-fpm
sudo systemctl restart php8.5-fpm         # use the name shown above
ls -l /run/php/                           # the socket PHP really listens on
sudo grep -n fastcgi_pass /etc/nginx/sites-enabled/*
```

Every `fastcgi_pass` line must name a socket that `ls` shows, with its version number (`php8.5-fpm.sock`). Installers before 2026-10-30 could write the generic `/run/php/php-fpm.sock`, which doesn't exist on some systems (seen on Ubuntu with PHP 8.5). Fix it with your version:

```bash
SITE=/etc/nginx/sites-enabled/bpq-dashboard
sudo sed -i --follow-symlinks \
  's|php-fpm.sock|php8.5-fpm.sock|g' $SITE
sudo nginx -t && sudo systemctl reload nginx
```

The nginx error log shows this as `connect() to unix:/run/php/php-fpm.sock failed (2: No such file or directory)`. Install Check (*PHP-FPM socket in site config*) and System Audit flag it.

### 500 Internal Server Error on the admin pages (and Email Client, Chat, Install Check)

Usually the web server can't read the admin login file. The error log shows:

```
[crit] open() "/etc/nginx/.bpq-mgmt-auth" failed (13: Permission denied)
```

```bash
sudo tail -20 /var/log/nginx/bpq-dashboard.error.log
ls -l /etc/nginx/.bpq-mgmt-auth            # should be: -rw-r----- root www-data
sudo chown root:www-data /etc/nginx/.bpq-mgmt-auth
sudo chmod 640 /etc/nginx/.bpq-mgmt-auth
```

No reload is needed; the next request reads the file. Installers before 2026-10-30 left it `root:root` (fixed: the installer and `fix-permissions.sh` now set `root:www-data 640`). If the file is missing altogether, nginx also answers 500; create it with `sudo htpasswd -cB /etc/nginx/.bpq-mgmt-auth admin` and run the two lines above.

### 503 Service Temporarily Unavailable / 429 Too Many Requests

nginx's rate limiter turned the request away — PHP is fine (a PHP failure shows as 502 or 504). The sample nginx configs allow each visitor about 5 PHP requests per second plus a short burst. Older configs answer with **503**, current ones with **429**.

Confirm it and see which page and zone caused it:

```bash
sudo grep "limiting requests" /var/log/nginx/error.log | tail -10
```

Each line names the zone (`... by zone "dashboard_api"`), the client IP and the request. Common causes:
- **A bulk action in the Email Client** (Mark Read on a big selection, Empty folder, bulk Kill) on an older `bbs-messages.html`. Since 2026-10-19 the page paces these itself — update the page and hard-refresh (Ctrl+Shift+R).
- **Both sites sharing one rate-limit bucket** in an older two-site config, so a busy page on one site gives 503s on the other. Merge the 2026-10-19 `limit_req` changes from `bpq-dashboard.conf` (separate zone for the BPQ site, home pages on the page bucket), then `sudo nginx -t && sudo systemctl reload nginx`.
- **Several devices behind one router** count as one visitor; that is normal and rarely enough on its own.

To see who is sending the most requests:

```bash
sudo tail -n 20000 /var/log/nginx/access.log | awk '{print $1}' | sort | uniq -c | sort -rn | head -5
```

### "JSON.parse: unexpected character" on dashboard page

PHP-FPM crashed during the request. Common causes:
- A network call inside the PHP timed out (defensive timeouts added in v1.5.12 prevent this)
- PHP-FPM hit a memory limit
- Bad sudoers configuration

```bash
# the installer's site logs to /var/log/nginx/bpq-dashboard.error.log
sudo tail -50 /var/log/nginx/error.log
sudo tail -50 /var/log/php*-fpm.log
sudo systemctl restart 'php*-fpm'
```

### Log Viewer: "JSON.parse: unexpected end of data" on some logs

Up to v1.5.16 the Log Viewer's PHP sent an empty reply for any log holding bytes that aren't valid UTF-8 — usually the BBS-session logs (Message text job, Email Client (BBS), Dashboard log). Fixed in v1.5.17 (`admin/log-viewer-api.php` 1.3.1): those bytes show as **�**. Update `admin/log-viewer-api.php` and `admin/log-viewer.html`, then reload with Ctrl+Shift+R. To see which lines of a log have such bytes:

```bash
LANG=C.UTF-8 grep -naxv '.*' /var/log/bpq-dashboard/bbs-messages.log | head
```

If the page now says *"Empty reply from the server"* or *"Server reply was not JSON"*, the PHP error is in the web-server error log (above).

### YAPP transfer fails

- Are you in FBB mode? YAPP requires BPQ's FBB port (8011 by default), not the standard telnet port. If yours is different and the dashboard can't read `bpq32.cfg`, set `'fbb_port' => N` in the `bbs` section of `config.php` and restart bpq-telnet.
- Is bpq-telnet daemon running? `sudo systemctl status bpq-telnet`
- Check `/var/log/bpq-dashboard/bpq-telnet-daemon.log` for protocol errors (Admin → Logs → bpq-telnet).

### Telnet or VARA page can't connect

- Is the service running? `sudo systemctl status bpq-telnet bpq-vara`
- Telnet: a port is refused, or the buttons show the wrong port — the page uses `bbs.port` from `config.php` and the FBB port from `bpq32.cfg` (or `bbs.fbb_port`). After changing `config.php`, `sudo systemctl restart bpq-telnet`; its log (Log Viewer → *bpq-telnet*) lists the allowed ports when it starts.
- Does the web server pass `/ws/telnet` and `/ws/vara` through? The installer's site file does from 2026-10-25; an older one doesn't — re-run the installer or copy the blocks from `nginx-bpq-dashboard.conf`.
- VARA: `sudo journalctl -u bpq-vara -n 30` shows the login, the VARA port it chose and the `ATT` reply (Section 9). NetROM list empty: the error under the list says whether the `bbs` login or `station.node` is the problem.

### Chat or VARA terminal shows "daemon not connected"

```bash
sudo systemctl status bpq-chat bpq-vara
sudo journalctl -u bpq-chat -n 30
sudo systemctl restart bpq-chat
```

### Email Client or message text job: "Login refused" / "Login failed"

The dashboard logs in with `bbs.user` / `bbs.pass` from `config.php`. The message names the step and the last line BPQ sent.

- *Login refused* — BPQ rejected the user name or password: make them match the `USER=` line in `bpq32.cfg`.
- *nothing came back after the password* — LinBPQ stopped answering; check it's running and the port (`bbs.port`) is its telnet port.
- *No BBS prompt after 'bbs'* — the BBS application has another name: set `bbs.alias` to it (`APPLICATION` line in `bpq32.cfg`).
- Plain *"Login failed"* on v1.5.17 or earlier: BPQ's greeting had no `}`; update to v1.5.18.

Test by hand: `sudo -u www-data php /var/www/bpq-dashboard/scripts/fetch-message-bodies.php --dry-run --limit=1`, or `telnet localhost <bbs.port>` and log in as the dashboard does.

### "BBS password not configured" error

Open config.php and set `'pass'` under `'bbs'` (still `'CHANGE_THIS_PASSWORD'` in a copied example) to your BPQ sysop password.

### Firewall page says the rules could not be read

The sudo rules are missing or out of date (see Security Hardening → Configure sudoers for www-data). Copy `www-data-tools.sudoers` again; sudo picks the change up at once, nothing needs restarting.

### Rig Status shows "flrig offline"

The page now shows the actual error from the API. Common causes:
- flrig not running on the configured `flrig_host`
- flrig not connected to the radio (its title bar must show the radio model)
- Firewall blocking 12345/tcp between the server and the flrig computer
- Wrong host in config.php `radio.flrig_host`

```bash
# Test connectivity
nc -zv YOUR_FLRIG_HOST 12345

# Test flrig RPC
curl -s -X POST http://YOUR_FLRIG_HOST:12345/RPC2 \
  -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?><methodCall><methodName>rig.get_vfo</methodName><params></params></methodCall>'
```

### Dashboard shows "No data" or blank charts

The web server cannot read your BPQ log files.

```bash
sudo -u www-data ls /var/www/bpq-dashboard/logs/
sudo -u www-data cat /var/www/bpq-dashboard/logs/log_$(date +%y%m%d)_BBS.txt | head -3
```

`Permission denied` with LinBPQ inside a home folder: see the next section.

### LinBPQ in a home folder (Ubuntu 21.04+, Linux Mint 21+)

These systems make `/home/<user>` private, so the web server can't see a LinBPQ folder inside it: every log page stays empty, Traffic finds no reports. The installer (v1.5.19) checks this and offers the fix; Install Check's *LinBPQ folder* row prints these commands for your path. Example for `/home/n3mel/linbpq`:

```bash
sudo apt install -y acl
sudo setfacl -m u:www-data:x /home/n3mel                     # pass through only
sudo setfacl -m u:www-data:rx /home/n3mel/linbpq             # list the LinBPQ folder (Traffic_*.txt live here)
sudo setfacl -R -m u:www-data:rX /home/n3mel/linbpq/logs     # read the logs
sudo setfacl -d -m u:www-data:rX /home/n3mel/linbpq/logs     # ... and the ones LinBPQ writes later
sudo -u www-data ls /home/n3mel/linbpq/logs | tail -3        # test
```

The web server can't read anything else in the home folder, and `bpq32.cfg` (your BPQ passwords) stays closed; give it read on that one file only if you need the Rig page scan list or the VARA HF Terminal without `vara.bpq_port`. Don't `chmod 755` the home folder or add `www-data` to your group: both open the whole home folder. Undo with `sudo setfacl -b <folder>`. Since v1.5.19 the dashboard writes its own logs to `/var/log/bpq-dashboard`, so it no longer needs write access to LinBPQ's logs folder.

### Connect Log page is empty

BPQ writes `ConnectLog_YYMMDD.log` only with `LogAllConnects=1` in the main section of `bpq32.cfg` (before the first `PORT`). Add it, restart LinBPQ, and new connections appear. (`LOGGING=1` on the telnet port writes `Telnet_*.log`, which this page doesn't read.)

### Permission denied on cache or data directories

```bash
sudo chown -R www-data:www-data /var/www/bpq-dashboard/cache /var/www/bpq-dashboard/data /var/www/bpq-dashboard/yapp-files
sudo chmod 750 /var/www/bpq-dashboard/cache /var/www/bpq-dashboard/data
```

`sudo bash /var/www/bpq-dashboard/fix-permissions.sh` (try `--dry-run` first) resets the whole folder to the installer's scheme.

### APRS map shows no stations

Check daemon status and APRS-IS connection.

```bash
sudo systemctl status bpq-aprs
cat /var/www/bpq-dashboard/cache/aprs/aprs-daemon.json | python3 -m json.tool | head -10
```

### Email Client says "BBS busy"

Only one session with the BBS runs at a time (Get Mail, the message-text job, the forwarding-queue check). It waits up to 40 seconds; try again. If it happens all the time, check `sudo journalctl -u message-bodies.service -n 20`.

### A page is empty and its status has no "(DB)"

The database path failed. Check the parser logs in `/var/log/bpq-dashboard/`, `cat /etc/cron.d/bpq-dashboard`, and System Audit's *Database* and *Logs* rows.

### Theme toggle doesn't work on a page

Hard-refresh the page (Ctrl+Shift+R). If still no response, clear the `bpq-dark-mode` localStorage key in browser DevTools.

---

## 13. Quick Reference

### File locations (default install to /var/www/bpq-dashboard)

| File / Directory | Purpose |
|---|---|
| `/var/www/bpq-dashboard/` | Dashboard web root |
| `/var/www/bpq-dashboard/config.php` | Main configuration (mode 640); `db-config.php` optionally holds the database login |
| `/var/www/bpq-dashboard/logs` | Link to LinBPQ's `logs` folder (BPQ logs, the VARA log, `nodesrtt.txt`) |
| `/var/log/bpq-dashboard/` | The dashboard's own logs: daemons, Email Client, message-text job, NWS posting, parsers, forwarding tools, VARA download (v1.5.19) |
| `/var/lib/bpq-dashboard/` | Copies the dashboard keeps (the PKTNET forms replaced by an update, for Undo) |
| `/var/www/bpq-dashboard/cache/` | Runtime state — managed automatically |
| `/var/www/bpq-dashboard/data/` | Partners lists, BBS station info, saved locations, backups |
| `/var/www/bpq-dashboard/yapp-files/` | Files received by YAPP (behind the admin login) |
| `/var/www/bpq-dashboard/scripts/` | Daemons, parsers and tools — the services run them from here |
| `/etc/systemd/system/` | `bpq-telnet`, `bpq-chat`, `bpq-aprs`, `bpq-vara`, `vara-logger`, `rig-history-poller` services; `message-bodies` and `bulletin-retention` timers |
| `/etc/cron.d/bpq-dashboard` | The three log parsers |
| `/var/log/bpq-dashboard/` | Parser logs |
| `/etc/nginx/sites-available/bpq-dashboard` | The installer's web-server site |
| `/etc/nginx/.bpq-mgmt-auth` | Admin login password file |
| `/etc/sudoers.d/www-data-tools` | Read-only sudo rules for Firewall Status and System Audit |

### Service management

```bash
# Status of the dashboard's services and timers
systemctl status bpq-telnet bpq-chat bpq-aprs bpq-vara vara-logger rig-history-poller --no-pager
systemctl list-timers message-bodies.timer bulletin-retention.timer nws-auto-post.timer

# Restart one
sudo systemctl restart bpq-telnet

# Logs
sudo journalctl -u bpq-telnet -n 50 --no-pager
tail -50 /var/log/bpq-dashboard/bpq-telnet-daemon.log
```

### Services and timers

| Service | What it does | Required for |
|---|---|---|
| nginx (or Apache) | Web server | Everything |
| php*-fpm | PHP | Everything |
| LinBPQ (`bpq` or `linbpq`) | The node, BBS and chat | Everything |
| MariaDB | Database | Email Client; fast RF Connections, Traffic, System Logs |
| bpq-telnet | Telnet WebSocket + YAPP bridge | Telnet Client, YAPP |
| bpq-chat | The station's chat connection | Chat |
| bpq-aprs | APRS-IS connection and BBS beacon | APRS Map |
| bpq-vara | VARA HF session bridge | VARA HF Terminal |
| vara-logger | Copies VARA log lines from syslog | RF Connections |
| rig-history-poller | Power/SWR history | RF Power Monitor |
| vara-validator *(optional)* | Callsign check in front of VARA | §10.9 |
| message-bodies.timer | Saves message text every 5 minutes | Email Client |
| bulletin-retention.timer | Daily 14-day bulletin clean-up | Email Client |
| nws-auto-post.timer *(optional)* | Posts new NWS Warnings/Watches for your state every 5 minutes | Automatic NWS bulletins (§10.4) |
| vara-remote.timer *(optional, v1.5.19)* | Downloads the VARA log from the TPRFN server every 5 minutes | RF Connections on TPRFN stations (§10.11) |

### Complete crontab reference

```cron
# /etc/cron.d/bpq-dashboard — written by install.sh (runs as www-data)
*/5 * * * * www-data /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py --bbs-log-dir /var/www/bpq-dashboard/logs --my-callsign W1AW >> /var/log/bpq-dashboard/parse-vara-log.log 2>&1
*/5 * * * * www-data /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-traffic-report.py --days 8 >> /var/log/bpq-dashboard/parse-traffic-report.log 2>&1
*/5 * * * * www-data /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-system-logs.py >> /var/log/bpq-dashboard/parse-system-logs.log 2>&1

# Optional — root's crontab (sudo crontab -e)
*/5 * * * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/connect-watchdog.py
0 * * * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/storm-monitor.py >> /var/log/bpq-dashboard/storm-monitor-cron.log 2>&1
0 6 */2 * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/prop-scheduler.py --apply >> /var/log/bpq-dashboard/prop-scheduler-cron.log 2>&1
*/10 * * * * /var/www/bpq-dashboard/nodes-rtt.sh
0 2 * * 0 DASHBOARD_DIR=/var/www/bpq-dashboard /var/www/bpq-dashboard/scripts/archive-bpq-data.sh >> /var/log/bpq-archive.log 2>&1
0 3 * * 0 find /var/www/bpq-dashboard/yapp-files/ -mtime +30 -type f -delete

# Optional — www-data's crontab (sudo crontab -u www-data -e), run from the LinBPQ folder
0 3 * * * cd /opt/linbpq && python3 /var/www/bpq-dashboard/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1
```

The message-text job and the bulletin clean-up are systemd timers, not cron lines.

### Dashboard URLs at a glance

Replace `YOUR_SERVER_IP` with your server's actual IP or hostname.

| Page | URL |
|---|---|
| RF Connections (home) | `http://YOUR_SERVER_IP/` |
| Chat Client | `http://YOUR_SERVER_IP/bpq-chat.html` |
| VARA HF Terminal | `http://YOUR_SERVER_IP/bpq-vara.html` |
| Telnet Client | `http://YOUR_SERVER_IP/bpq-telnet.html` |
| System Logs | `http://YOUR_SERVER_IP/bpq-system-logs.html` |
| Traffic Report | `http://YOUR_SERVER_IP/bpq-traffic.html` |
| Email Client | `http://YOUR_SERVER_IP/bbs-messages.html` |
| Message Monitor | `http://YOUR_SERVER_IP/bpq-email-monitor.html` |
| Connect Log | `http://YOUR_SERVER_IP/bpq-connect-log.html` |
| RF Power Monitor | `http://YOUR_SERVER_IP/rf-power-monitor.html` |
| Rig Status | `http://YOUR_SERVER_IP/rig-status.html` |
| APRS Map | `http://YOUR_SERVER_IP/bpq-aprs.html` |
| NWS Weather | `http://YOUR_SERVER_IP/nws-dashboard.html` |
| Maintenance | `http://YOUR_SERVER_IP/admin/bpq-maintenance.html` |
| Firewall Status | `http://YOUR_SERVER_IP/admin/firewall-status.html` |
| Log Viewer | `http://YOUR_SERVER_IP/admin/log-viewer.html` |
| System Audit | `http://YOUR_SERVER_IP/admin/system-audit.html` |
| Install Check | `http://YOUR_SERVER_IP/install-check.php` (admin login) |
| Health Check | `http://YOUR_SERVER_IP/health-check.php` (admin login) |
| NTS Report | `http://YOUR_SERVER_IP/nts-forwarding-report.php` (admin login) |
| User Guide (PDF) | `http://YOUR_SERVER_IP/BPQ-Dashboard-v1.5.33-User-Guide.pdf` |

---

**73 — BPQ Dashboard v1.5.33 — Amateur Radio Packet Node Operations Suite**

*Open source · https://github.com/bpq-dashboard/bpq-dashboard*
