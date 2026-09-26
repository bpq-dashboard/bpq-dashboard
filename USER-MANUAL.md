# BPQ Dashboard

**Version 1.5.13**
**User Manual & Installation Guide**
*Amateur Radio Packet Node Operations Suite*

| | |
|---|---|
| Platform | Linux — Debian / Ubuntu / Raspberry Pi |
| Requirements | LinBPQ running · nginx or Apache · PHP 8.x |
| Target audience | Amateur radio operators — no prior Linux experience needed |
| Updated | September 2026 |
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
    - 10.11 VARA log analysis & aggregation
    - 10.12 NetROM RTT collection
11. Security Hardening
12. Troubleshooting
13. Quick Reference

---

## 1. What Is BPQ Dashboard?

BPQ Dashboard is a web-based monitoring and operations suite for amateur radio packet nodes running BPQ32 or LinBPQ. It runs on the same Linux computer as your packet node and gives you a rich browser interface to monitor connections, manage email-style BBS messages, view logs, track RF power, transfer files over packet via YAPP, run VARA HF keyboard sessions, and more — all from any device on your local network.

You access it by opening a web browser and going to your node's IP address. No software to install on the viewing device — it works on any phone, tablet or computer.

| Feature | Description |
|---|---|
| RF Connections | All VARA HF connections, channel quality, band activity, propagation reports and station map |
| Chat Client | Live browser-based chat terminal connected to your BPQ chat server |
| VARA HF Terminal | Keyboard-to-keyboard HF radio sessions via VARA modem — connect to stations from your browser |
| Telnet Client | Browser-based telnet terminal with live NetROM nodes sidebar AND **YAPP file transfer** |
| Email Client | Read, search and manage BBS messages with NTS folder support, multi-select, address book. Password protected. Server or browser storage. |
| System Logs | BPQ daily logs, MHeard list, routing table and connection history |
| Traffic Stats | Charts of message traffic by week, month and band |
| RF Power Monitor | Real-time power and SWR read from your radio via flrig (no separate wattmeter hardware needed) |
| Rig Status | Live frequency, mode, S-meter, power, PTT state from flrig |
| APRS Map | Live map of APRS stations heard through your node with messaging |
| NWS Weather | National Weather Service alerts posted automatically to your BBS — nationwide severe weather coverage |
| Maintenance | Server health checks, system tools, sysop command reference, troubleshooting |
| Firewall Status | Live iptables + fail2ban view with per-port categorization |
| Log Viewer | Real-time tail of daemon logs and system logs |
| System Audit | Automated health audit — daemons, certs, disk, security, more |
| **Day/Night Theme** | Toggle between dark and light themes via the moon/sun button in the nav |

---

## 2. What's New

### Since v1.5.13 (current development)

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
- **8 daemons monitored** — Maintenance and System Audit pages now cover nginx, php8.x-fpm, bpq, vara-validator, bpq-vara, bpq-telnet (new), bpq-chat, bpq-aprs.
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
| BPQ telnet port | A number | In `bpq32.cfg`, the Telnet port section, `TCPPORT=` | `8010` |
| BPQ sysop username | First field of your `USER=` line | `bpq32.cfg`, e.g. `USER=John,secret,W1AW-4,NODE,SYSOP` | `John` |
| BPQ sysop password | Second field of that same line | as above | `secret` |
| Folder containing `bpq32.cfg` | The LinBPQ folder. It should have a `logs` folder inside | Wherever you installed LinBPQ | `/home/pi/linbpq` |
| Database password | **Invent a new one**, or just press Enter and one is made for you | — | |
| Admin username / password | **Invent them.** They protect the admin pages (firewall, logs, audit) | — | `admin` / something strong |

Your map position on public pages is shown as the **centre of your grid square** (about 5 × 2.5 km), never your exact coordinates.

#### Step 3 — Download the dashboard

Open a terminal on the LinBPQ computer and run these four lines:

```bash
sudo apt update
sudo apt install -y wget unzip
cd ~
wget -O bpq-dashboard.zip https://github.com/bpq-dashboard/bpq-dashboard/archive/refs/tags/v1.5.13.zip
```

Then unpack it and go into the new folder:

```bash
unzip bpq-dashboard.zip
cd bpq-dashboard-1.5.13
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
| Use MariaDB / Install MariaDB now? | **Y** (the Email Client needs it) |
| Back it up and reinstall fresh? *(only if the dashboard was installed before)* | **Y** |
| Set up flrig now? | **n**, unless you use flrig for rig control |
| Install systemd unit files for the helper daemons? | **Y** |

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

#### Step 7 — Open your dashboard

On the LinBPQ computer, find its network address:

```bash
hostname -I
```

The first number (for example `192.168.1.50`) is its address. On any computer, phone or tablet on your network, open a web browser and go to:

```
http://192.168.1.50/
```

(use your own number). The **RF Connections** page should appear. Bookmark it.

#### Step 8 — Run the self-test

Go to:

```
http://192.168.1.50/install-check.php?pass=bpqcheck
```

Log in with the **admin username and password** you invented. The page checks everything the dashboard needs and shows each item in green, amber or red. **Every red item shows the exact command that fixes it.**

At this point it is normal to see red for the *daemon heartbeat* lines, because the helper services aren't switched on yet. That's the next step.

#### Step 9 — Switch on the helper services

Small background programs power the live pages. Turn on the ones you want:

| Service | What it's for | Turn it on |
|---|---|---|
| `bpq-telnet` | Telnet Client page and YAPP file transfers | `sudo systemctl enable --now bpq-telnet` |
| `bpq-chat` | Chat page | `sudo systemctl enable --now bpq-chat` |
| `bpq-aprs` | APRS map | `sudo systemctl enable --now bpq-aprs` |
| `bpq-vara` | VARA HF Terminal page | `sudo systemctl enable --now bpq-vara` |
| `vara-logger` | Records VARA HF activity for the RF Connections page | `sudo systemctl enable --now vara-logger` |

To check one is running, for example `bpq-aprs`:

```bash
systemctl status bpq-aprs
```

Look for **active (running)** in green. Press **q** to get back to the prompt.

The installer already scheduled the **log parsers**, which fill the RF Connections, Traffic and System Logs pages every 5 minutes. It also scheduled a **daily clean-up** that deletes bulletins older than 14 days, except in groups you pin with 📌 in the Email Client. Pages may look empty for the first few minutes.

#### Step 10 — Optional settings

Your settings live in one file. To open it:

```bash
sudo nano /var/www/bpq-dashboard/config.php
```

Use the arrow keys to move. To save, press **Ctrl+O** then **Enter**. To exit, press **Ctrl+X**. Things you might change:

- **Send on APRS-IS:** by default the APRS map only *receives*. To send, set your passcode in the `'aprs'` section: `'passcode' => 'YOUR_PASSCODE',`. Get your passcode from https://apps.magicbug.co.uk/passcode/. Then run `sudo systemctl restart bpq-aprs`.
- **VARA HF hub:** if you run a VARA HF hub, put its call-SSID in `'hub' => 'W1AW-7',` (in the `'station'` section) to get band and frequency history on RF Connections.
- **flrig (rig control):** the `'radio'` section, if you skipped it in the installer.

Web pages pick up changes immediately; only the helper services need a restart.

#### Step 11 — Stay safe

- The admin pages (`/admin/…`) and the self-test are protected by your admin login. Keep that password private.
- **Never share `config.php`**. It contains your BBS and database passwords.
- When the self-test is all green, you can remove it: `sudo rm /var/www/bpq-dashboard/install-check.php`.
- If you want the dashboard reachable **from the internet**, set up HTTPS first. See `docs/legacy/PUBLIC-DEPLOYMENT.md`, and use `bpq-dashboard.conf` as your web-server configuration.

---

### Updating to a newer version

1. Download and unzip the new version exactly as in Step 3.
2. Run `sudo bash install.sh` again and give the same answers.
3. Answer **Y** to *"Back it up and reinstall fresh?"*.

Your database, with all messages and history, is kept and updated automatically. The previous installation is saved as `/var/www/bpq-dashboard.backup-<date>`. **Re-apply any optional settings** from Step 10: you can copy them from the old `config.php` inside that backup folder.

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
         sql/2026-09-21-rf-messages-medium.sql sql/2026-09-26-message-keep-and-bodies.sql; do
    sudo mysql bpqdash < "$f"
done
```

Run this from the unzipped folder. All files are safe to re-run; the last two are upgrade migrations and do nothing on a new database.

**3. Files**

```bash
W=/var/www/bpq-dashboard
sudo mkdir -p $W
sudo rsync -a --exclude install.sh --exclude .git --exclude private ./ $W/
sudo mkdir -p $W/cache/aprs $W/cache/chat-sessions $W/cache/network $W/data/backups $W/img
echo '{"partners":[]}' | sudo tee $W/data/partners.json >/dev/null
sudo curl -fsSL -o $W/img/aprs-symbols-pri.png https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/aprs-symbols-24-0.png
sudo curl -fsSL -o $W/img/aprs-symbols-alt.png https://raw.githubusercontent.com/hessu/aprs-symbols/master/png/aprs-symbols-24-1.png
sudo bash $W/scripts/fetch-forms.sh $W    # message forms for the Email Client (optional)
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
| `paths.linbpq` | Your LinBPQ directory (`bpq32.cfg`, `WP.cfg`, `linmail.cfg`) | `'/home/linbpq'` |
| `logs.vara_file` | Your VARA log file name inside `paths.logs` | `'w1aw.vara'` |
| `bbs.host` | LinBPQ host | `'127.0.0.1'` |
| `bbs.port` | LinBPQ telnet port | `8010` |
| `bbs.fbb_port` | LinBPQ FBB port for YAPP | `8011` |
| `bbs.user` | Your BPQ user | `'W1AW'` |
| `bbs.pass` | Your BPQ password | `'mypassword'` |
| `radio.flrig_host` | flrig host IP | `'192.168.1.100'` |
| `radio.flrig_port` | flrig XML-RPC port | `12345` |
| `ssl_domains` | Domains to monitor for cert expiry (optional) | `['bpq.example.com']` |

Put the database name, user and password from step 2 in the `'db'` section. Alternatively, put them in `db-config.php` (copy `db-config.php.example`), which takes priority.

**5. Link the BPQ logs**

Link LinBPQ's **logs** folder. Never link the whole LinBPQ folder: `bpq32.cfg` holds your passwords.

```bash
sudo rm -rf $W/logs
sudo ln -s /path/to/linbpq/logs $W/logs
```

**6. Web server**

For a LAN-only server, the simplest safe choice is to let `install.sh` write it. By hand, start from `bpq-dashboard.conf` (HTTPS, public-facing) and keep **all** of its `return 403` blocks. They stop `scripts/`, `data/`, `.sql`, backup files and anything in `logs/` other than log files from being downloaded. The template protects `/admin/` with the password file `/etc/nginx/.bpq-mgmt-auth`. Create it:

```bash
sudo htpasswd -c /etc/nginx/.bpq-mgmt-auth admin
```

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
sudo cp $W/bulletin-retention.service $W/bulletin-retention.timer /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now bulletin-retention.timer
```

`--days 8` matters: some BPQ versions write the traffic report weekly rather than daily.

**9. Helper services**

```bash
sudo cp $W/bpq-telnet.service $W/bpq-chat.service $W/bpq-aprs.service $W/bpq-vara.service $W/vara-logger.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now bpq-telnet bpq-chat bpq-aprs bpq-vara vara-logger
```

The service files run the daemons from `$W/scripts/`. Don't copy the scripts elsewhere. Edit `$W/vara-logger.sh` so `OUTPUT_FILE` names your `<callsign>.vara` file.

**10. Check** with `install-check.php`, as in Part A, Step 8.

---

## 5. Testing Your Installation

Visit `http://YOUR_SERVER_IP/install-check.php?pass=bpqcheck` and log in with your admin username and password for an automated installation check. It verifies:

- All 17 dashboard pages are present
- All PHP API files are present
- All Python daemon scripts are installed
- Logs directory is readable
- Cache/data directories are writable
- LinBPQ is reachable on the configured port
- The database tables exist, the Python modules are installed, and the log parsers are scheduled

If anything is missing or misconfigured, the page shows the exact command to fix it.

---

## 6. Dashboard Pages Reference

> **Core vs. optional:** The dashboard has a baseline set of pages that work as soon as you finish section 4 (these are the **Daily-use** pages plus admin pages described below). Additional capabilities — automated forwarding, propagation modeling, NWS alerts, RF Connections database optimization, etc. — are **opt-in features** documented in section 10. Skip the ones you don't need; they're separate add-ons that won't break the rest of the dashboard if not installed.

### Daily-use pages

**RF Connections** — `/bpq-rf-connections.html`
Shows all VARA HF connection history, channel quality analysis (modulation tier, S/N ratio, bitrate trends), band activity heatmap, seasonal best-band recommendations with propagation scoring, 30-day geomagnetic activity chart, propagation report, and a live station map.

**Chat Client** — `/bpq-chat.html`
Live browser-based chat connected to your BPQ chat server. Shows all rooms and connected users.

**VARA HF Terminal** — `/bpq-vara.html`
Keyboard-to-keyboard HF radio terminal. Select a frequency preset (auto-QSYs your radio), enter a callsign, select bandwidth (BW500/2300/2750) and click Connect. Protected by sysop password.

**Telnet Client** — `/bpq-telnet.html`
Browser-based telnet terminal connected to your BPQ node with a live NetROM routing table sidebar. **Now includes YAPP file transfer** — see Section 8.

**System Logs** — `/bpq-system-logs.html`
Viewer for BPQ daily log files with search, date selection, MHeard station list and routing table.

**Traffic Stats** — `/bpq-traffic.html`
Charts of BBS message traffic by day, week and month.

**Message Monitor** — `/bpq-email-monitor.html`
Monitor of BBS email activity — messages by callsign, subject line search, date range filtering.

**Email Client** — `/bbs-messages.html`
Full email-style BBS message browser — read, search, organize messages with NTS folder, custom folders, multi-select, forwarding visibility, address book. Password-protected (set on first visit).

**Connect Log** — `/bpq-connect-log.html`
Recent connection history with callsign filtering and port breakdown.

**RF Power Monitor** — `/rf-power-monitor.html`
Live RF power and SWR data read from your radio via flrig — same source rig-status.html uses. No separate wattmeter hardware required.

**Rig Status** — `/rig-status.html`
Live frequency, mode, bandwidth, S-meter, power output, and PTT state from flrig. Requires flrig running and connected to your radio.

**APRS Map** — `/bpq-aprs.html`
Live map of APRS stations heard through your node. Full APRS messaging, weather data popup, station details.

**NWS Weather** — `/nws-dashboard.html`
National Weather Service alerts for your configured regions. **Nationwide severe weather** coverage — includes all WFOs and SPC nationwide watches. Can automatically post alerts to your BBS as bulletins.

### Admin pages (LAN-only recommended)

**Maintenance** — `/bpq-maintenance.html`
System administration — server health checks for all 8 daemons, log viewer, VARA callsign validator, forwarding partners editor, BPQ sysop command reference, YAPP file transfer ops commands, and troubleshooting tools.

**Firewall Status** — `/firewall-status.html`
Live view of iptables INPUT chain rules with per-port categorization (Public, LAN Only, IP Restricted, Blocked, Localhost) and fail2ban jail status. Password-protected.

**Log Viewer** — `/log-viewer.html`
Real-time tail of nginx, PHP-FPM, BPQ, and daemon logs with filter and search.

**System Audit** — `/system-audit.html`
Automated health audit covering security, resources, nginx, MariaDB, logs, updates, and per-daemon health. Shows findings with severity (ok / warn / crit), descriptions, and remediation commands. **Now includes per-jail fail2ban status, YAPP files directory monitoring, and multi-domain SSL cert checking.**

**Install Check** — `/install-check.php` (password `bpqcheck`)
Post-installation diagnostic — verifies all 17 dashboard pages, PHP files, daemon scripts, directories, and permissions are correct.

---

## 7. The Shared Navigation & Day/Night Theme

Every page loads the same navigation bar with these elements:

- **Page links** organized by category (RF Connections, Email, etc.)
- **Admin dropdown** for Maintenance / Firewall / Logs / Audit
- **Local clock** showing current time
- **UTC clock** for logging accuracy
- **Theme toggle** — 🌙 (currently in light mode, click for dark) or ☀️ (currently in dark mode, click for light)

The theme toggle remembers your choice in browser local storage. Every page on the dashboard now responds correctly to the toggle — including the admin pages.

**Deliberate exceptions:** the terminal display areas in the Telnet Client and VARA HF Terminal stay dark (green-on-black) regardless of theme, because that's the conventional terminal aesthetic. The RF Power Monitor meter card also stays dark for instrument readability.

---

## 8. YAPP File Transfer

YAPP (Yet Another Packet Protocol) is a file transfer protocol used by BBS networks to move binary files over packet radio. The dashboard now supports YAPP directly in the browser via the **Telnet Client** page.

### How to receive a file

1. Open the **Telnet Client** page
2. Click **Connect** — this opens a standard interactive telnet session
3. Click the **📁 YAPP** button (top right of the terminal)
4. If you're not already in FBB mode, click **Switch to FBB Mode** in the YAPP panel — this reconnects on the FBB port (8011) with auto-login. *(YAPP requires FBB mode because it's a binary protocol; the standard telnet port escapes some bytes.)*
5. In the terminal, type `BBS` and press Enter to enter your BBS
6. Click **📂 Browse Files** in the YAPP panel — this asks the BBS what files are available and shows them in a clickable list
7. **Click any file** in the list — this automatically arms the receiver AND types the `YAPP <filename>` command for you
8. Watch the progress bar fill as the file transfers
9. When complete, a **Download** link appears in the YAPP panel — click it to save the file to your computer

### How to send a file

1. Connect to BBS in FBB mode (as above)
2. Click the **📁 YAPP** button → **Send** tab
3. Click **Choose File** and select a file from your computer (10 MB cap)
4. Tell the remote BBS to receive — typically type `YUPLOAD <filename>` at the BBS prompt
5. Click **Send File** in the YAPP panel
6. Progress bar shows transfer status

### Where files are stored

Received files land in `/var/www/bpq-dashboard/yapp-files/` on the server with the **real filename from the YAPP header**, prefixed by a timestamp (e.g. `20260524-191234-update-bpq`).

The System Audit page monitors this directory and warns at 100 MB / 500 MB thresholds.

For cleanup:

```bash
# Delete YAPP files older than 30 days
find /var/www/bpq-dashboard/yapp-files/ -mtime +30 -type f -delete
```

### Troubleshooting YAPP

**"Browse Files" returns nothing** — Are you at the BPQ Node prompt or in the BBS? Type `BBS` first to enter the BBS, then try Browse Files again.

**FBB mode says "auth failed"** — Check your `bbs.user` and `bbs.pass` in config.php match exactly what's in bpq32.cfg.

**Transfer stalls partway** — Packet radio is slow. A 2 KB file can take 30 seconds. Larger files several minutes. The dashboard has a 120-second no-progress timeout; if you hit it the link conditions are likely too poor.

---

## 9. VARA HF Terminal Setup

The VARA HF Terminal lets you make keyboard-to-keyboard HF radio connections from your browser.

### Requirements

- VARA HF modem software running (Windows PC connected to your radio, or Wine on Linux)
- flrig running on the same PC, connected to your radio
- A VARA HF port configured in your bpq32.cfg (`DRIVER=VARA`)
- bpq-vara daemon installed and running (Section 4.6)

### Configure the daemon

Edit the daemon to match your setup:

```bash
sudo nano /usr/local/bin/bpq-vara-daemon.py
```

Find and change these lines near the top:

| Setting | Description | Example |
|---|---|---|
| BPQ_HOST | IP of your Linux BPQ node | 127.0.0.1 |
| BPQ_PORT | LinBPQ telnet port | 8010 |
| BPQ_USER | Your BPQ callsign | W1AW |
| BPQ_PASS | Your BPQ password | mypassword |
| BPQ_VARA_PORT | BPQ port number for VARA HF | 3 |

```bash
sudo systemctl restart bpq-vara
sudo systemctl status bpq-vara
```

### Using the VARA HF Terminal

- **Frequency presets** — click any preset to QSY your radio
- **Manual frequency** — type any frequency in MHz
- **Bandwidth** — BW500 (narrow, weak signals), BW2300 (standard), BW2750 (wideband)
- **Connect** — enter a callsign and click Connect
- **End Session** — disconnects and automatically restores your full frequency scan schedule

### 9b. Supported Radios — flrig Profiles

BPQ Dashboard communicates with your radio through flrig, which acts as a universal bridge. This means it works with Yaesu, Icom, Kenwood, Elecraft, and other radios without hardware-specific code.

When the VARA HF Terminal or Rig Status page starts, it calls `rig.get_xcvr` on flrig to automatically detect your radio model. You can also force a specific profile by setting `model` in the `radio` section of config.php.

> **Important:** flrig must be running and showing your radio model in its title bar AND actively reading frequency before the dashboard can use it. Without flrig, the Rig Status page shows offline and the VARA HF Terminal can't QSY.

> **Installer note:** `install.sh` (Step 9) installs and configures flrig for you — either locally with a systemd service (flrig has no native headless mode, so this runs it under Xvfb) if the radio is connected to the same machine, or just records the host/port if flrig runs elsewhere. Either way, one manual step remains: on a local install, you still need to pick your radio model and confirm it's reading frequency via flrig's own GUI once (over `ssh -X`) before the unattended service can use it — the installer prints the exact steps at the end of that step.

---

## 10. Optional Features

### 10.1 Connect Watchdog

Cron-driven script that connects to forwarding partners on a schedule. Edit `/var/www/bpq-dashboard/data/partners.json` to define partners. Add to root crontab:

```
*/5 * * * * /usr/bin/python3 /usr/local/bin/connect-watchdog.py
```

### 10.2 Propagation Scheduler

Reads geomagnetic conditions from NOAA and automatically updates RADIO 2 scan schedule with band priorities matched to current propagation.

**Optional: enable decision history logging.** The scheduler can record every band-change decision it makes (with timestamp, Kp/SFI inputs, and band choice) to a MariaDB table called `prop_decisions`. This is helpful for tuning the propagation scoring algorithm to your station's conditions. Skip this if you don't run MariaDB.

```bash
# Apply the schema (one-time setup)
sudo mysql -u root -p bpqdash < /var/www/bpq-dashboard/data/prop-decisions-schema.sql
```

Then schedule the scheduler itself:

```
0 6 */2 * * /usr/bin/python3 /usr/local/bin/prop-scheduler.py --apply >> /var/log/prop-scheduler.log 2>&1
```

> **Note:** Edit the `PARTNERS` dict at the top of `prop-scheduler.py` with your actual forwarding partners (their callsigns, coordinates, default frequencies, and fallback connect scripts). The dashboard's Settings UI also lets you edit these via the Forwarding Partners page.

### 10.3 Storm Monitor

Hourly check for severe weather affecting your area. Auto-posts bulletins to your BBS when conditions warrant.

```
0 * * * * /usr/bin/python3 /usr/local/bin/storm-monitor.py >> /var/log/storm-monitor.log 2>&1
```

### 10.4 NWS Weather Alerts

The NWS Weather dashboard pulls alerts from api.weather.gov and displays them with map overlays. The **nationwide severe weather panel** (new in v1.5.12) shows all active Tornado / Severe Thunderstorm / Flash Flood Watches and Warnings across all NWS offices PLUS the Storm Prediction Center's nationwide watches.

**Configure:**

```bash
# Copy the example config and edit it for your location
sudo cp /var/www/bpq-dashboard/nws-config.php.example /var/www/bpq-dashboard/nws-config.php
sudo nano /var/www/bpq-dashboard/nws-config.php
# Set your WFO code (e.g. 'PIT' for Pittsburgh PA, 'OAX' for Omaha NE),
# county/zone codes, and which alert categories you want to display.
```

NWS data can also drive automatic BBS posting via `storm-monitor.py` (section 10.3). When configured together, severe weather alerts get auto-posted to your BBS for radio operators to forward via the packet network.

### 10.5 RF Power Monitor (FLRIG-based)

Reads live power, SWR, frequency, and band from your radio via flrig — the same source rig-status.html uses (`vara-api.php`'s `get_rig_info`/`get_rig_history` actions). No separate wattmeter hardware, no file syncing from a Windows machine — if flrig is running and connected to your radio, this page works.

Two tradeoffs worth knowing about, inherited from reading history off flrig rather than a dedicated always-on meter:
- History sampling is throttled to roughly once/minute while idle (every 3s during active TX) — coarser than a dedicated wattmeter would give you, though accurate enough for typical multi-second-to-minutes transmissions.
- flrig only reports SWR directly, not a separate reflected-power reading — the Reflected-watts figure shown is back-calculated from flrig's SWR + forward power using the standard VSWR formula run in reverse, not a directly hardware-measured value.

See `RF-POWER-MONITOR-MIGRATION.md` for the full detail on what changed from the earlier WaveNode-hardware-based version.

### 10.6 Data Archival

Monthly script that archives old logs, traffic data, and connection records.

```
0 2 1 * * /usr/local/bin/archive-bpq-data.sh >> /var/log/bpq-archive.log 2>&1
```

### 10.7 PTT Indicator and Remote Power Control

The Rig Status page can show PTT state and optionally control a network power switch (e.g., NP-05B) for remote on/off of the rig. Configure under `radio.power_switch` in config.php.

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
# 1. Apply the schema (creates rf_connections and rf_parser_state tables)
sudo mysql -u root -p bpqdash < /var/www/bpq-dashboard/data/rf-connections-schema.sql

# 2. Install pymysql for the parser
sudo apt install -y python3-pymysql

# 3. Initial backfill — walks the entire VARA log once
sudo -u www-data python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py --reset --verbose

# 4. Verify the table populated
sudo mysql -u root -p bpqdash -e "SELECT COUNT(*) AS sessions FROM rf_connections"
```

**Schedule incremental updates** (keeps the table fresh as new VARA sessions occur):

```bash
sudo crontab -u www-data -e
```

Add this line:

```
*/5 * * * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py >> /var/log/parse-vara-log.log 2>&1
```

Create the log file with correct ownership:

```bash
sudo touch /var/log/parse-vara-log.log
sudo chown www-data:www-data /var/log/parse-vara-log.log
```

**Verify the fast path is active:**

After hard-refreshing the RF Connections page, the status pill in the upper right should read:

> ✓ **N connections (DB)**

The **(DB)** suffix confirms the page is using the database. If you see only "N connections" without it, the page fell back to legacy log parsing — check the browser console for `[RF API]` messages.

For more detail, troubleshooting, and rollback procedures, see `docs/PHASE-1-DEPLOYMENT.md`.

> **Note:** The parser user needs `SELECT/INSERT/UPDATE/DELETE` privileges on the `bpqdash` database — standard CRUD, no need for DROP or ALTER. The `bpqdash` user created by the installer (or in section 4, Part B step 2) has all required grants.

### 10.9 VARA HF Callsign Validator

A transparent TCP proxy that sits between BPQ32 and your VARA HF modem to **reject malformed or unauthorized callsigns before BPQ32 ever sees the session**. Useful if you operate a public-facing gateway and want to protect your BBS from connection attempts from invalid or unlicensed callsigns.

**Architecture:**

```
   VARA HF (Windows or Wine)  on 127.0.0.1:8300/8301
            ↑  validator forwards here after callsign check
   [validator] listening on 0.0.0.0:9025/9026
            ↑  BPQ32 connects to validator instead of VARA directly
   BPQ32 (Linux)
```

**Validation rules (configurable):**

- ITU Radio Regulations Appendix 42 callsign format
- Optional MariaDB allowlist of permitted base callsigns
- Optional length and structure checks

**Install:**

```bash
# 1. Configure the validator (point upstream at your VARA host/ports)
sudo nano /var/www/bpq-dashboard/scripts/vara-callsign-validator.py
# Edit the VARA_HOST / VARA_CMD_PORT / VARA_DATA_PORT values near the top

# 2. Install the systemd service
sudo cp /var/www/bpq-dashboard/vara-validator.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable vara-validator
sudo systemctl start vara-validator

# 3. Point BPQ32 at the validator instead of VARA directly:
#    In bpq32.cfg, change your VARA port from 192.168.x.x:9025 (or wherever
#    VARA was) to 127.0.0.1:9025.

# 4. Restart BPQ to pick up the new TCP target.
sudo systemctl restart linbpq
```

**Verify:**

```bash
# Watch the validator log to see incoming connection attempts and their verdicts
sudo journalctl -u vara-validator -f
```

Rejected callsigns log a line like `REJECT: callsign='ABC123' reason='format'` and the TCP connection is dropped before BPQ32 receives a CONNECT event.

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
0 3 * * * cd /home/linbpq && python3 /var/www/bpq-dashboard/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1
```

> **Caution:** Run `--dry-run` first to preview what would be removed. Bogus auto-cleanup of valid entries could break Winlink message routing.

### 10.11 VARA log aggregation

- **`vara-logger.sh`** — survives syslog rotation, continuously captures VARA HF logs to a single concatenated file. This is the file the Phase 1 database parser (`parse-vara-log.py`, §10.8) and the RF Connections page read, so keep it running if your VARA logs are rotated by logrotate. Installed via `vara-logger.service`.

**Install:**

```bash
# Edit paths in the script to match your install
sudo nano /var/www/bpq-dashboard/vara-logger.sh

# Install vara-logger as a service
sudo cp /var/www/bpq-dashboard/vara-logger.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable vara-logger
sudo systemctl start vara-logger
```

> **Removed in 2026-09:** the old `vara-analysis.sh` daily text report. The RF Connections page (best frequencies, partner success rates, channel quality) covers the same ground, with or without the database.

### 10.12 NetROM RTT collection

The `nodes-rtt.sh` script collects round-trip-time data from BPQ32's NetROM routing table via the local telnet port, useful for monitoring link quality across your packet network. Output feeds the Connect Log page's RTT column.

**Install:**

```bash
# Install expect (required dependency)
sudo apt install -y expect

# Edit credentials in the script
sudo nano /var/www/bpq-dashboard/scripts/nodes-rtt.sh
# Set TELNET_USER, TELNET_PASS, and TELNET_HOST/PORT

# Schedule via cron — every 10 minutes is a reasonable default
sudo crontab -u www-data -e
# Add: */10 * * * * /var/www/bpq-dashboard/scripts/nodes-rtt.sh
```

---

## 11. Security Hardening

> **Important:** Do not skip this section if your server is accessible from the internet.

### Restrict admin pages to LAN only

Add this to your nginx server block:

```nginx
location ~* (bpq-maintenance|firewall-status|log-viewer|system-audit)\.html$ {
    allow 10.0.0.0/8;
    allow 192.168.0.0/16;
    allow 127.0.0.1;
    deny all;
}
```

Then: `sudo nginx -t && sudo systemctl reload nginx`

### Protect the data directory

```bash
echo "deny from all" | sudo tee /var/www/bpq-dashboard/data/.htaccess
```

### Change default passwords

- Linux user password: `passwd`
- BPQ BBS password — change in bpq32.cfg AND update config.php to match
- Email Client web password — first visit to the page prompts you to create one
- Health check password (`bpqcheck`) — change in install-check.php

### Configure sudoers for www-data

The Firewall Status and System Audit pages need read-only access to a few system commands. Create `/etc/sudoers.d/www-data-tools`:

```
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -L -n --line-numbers -v
www-data ALL=(ALL) NOPASSWD: /sbin/iptables -L INPUT -n --line-numbers -v
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status *
www-data ALL=(ALL) NOPASSWD: /bin/ss -tlnp
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t
```

> **IMPORTANT:** Both `status` AND `status *` lines are required. The sudoers `*` wildcard requires AT LEAST ONE argument, so bare `status` (used to list all jails) needs its own rule.

`sudo chmod 0440 /etc/sudoers.d/www-data-tools && sudo visudo -c`

### Public-mode

If the dashboard will be internet-facing, set in config.php:

```php
'security_mode' => 'public',
```

This automatically enforces read-only mode, rate limiting, and hides sensitive system information.

---

## 12. Troubleshooting

When something stops working, always start with the **System Audit** page (`/system-audit.html`). It diagnoses most problems automatically.

### Page won't load — "This site can't be reached"

nginx is not running or has a configuration error.

```bash
sudo systemctl status nginx
sudo systemctl start nginx
sudo nginx -t
sudo journalctl -u nginx -n 30
```

### 502 Bad Gateway

PHP-FPM is not running.

```bash
sudo systemctl status php8.3-fpm
sudo systemctl start php8.3-fpm
```

### "JSON.parse: unexpected character" on dashboard page

PHP-FPM crashed during the request. Common causes:
- A network call inside the PHP timed out (defensive timeouts added in v1.5.12 prevent this)
- PHP-FPM hit a memory limit
- Bad sudoers configuration

```bash
sudo tail -50 /var/log/nginx/error.log
sudo tail -50 /var/log/php8.3-fpm.log
sudo systemctl restart php8.3-fpm
```

### YAPP transfer fails

- Are you in FBB mode? YAPP requires port 8011, not the standard telnet port.
- Is bpq-telnet daemon running? `sudo systemctl status bpq-telnet`
- Check `/var/log/bpq-telnet-daemon.log` for protocol errors.

### Chat or VARA terminal shows "daemon not connected"

```bash
sudo systemctl status bpq-chat bpq-vara
sudo journalctl -u bpq-chat -n 30
sudo systemctl restart bpq-chat
```

### "BBS password not configured" error

Open config.php and change `'pass'` under `'bbs'` from `'CHANGEME'` to your actual BPQ password.

### Firewall page shows all zero counts

Make sure both sudoers rules are present (see Security Hardening). After fixing sudoers, restart PHP-FPM:

```bash
sudo systemctl restart php8.3-fpm
```

### Rig Status shows "flrig offline"

The page now shows the actual error from the API. Common causes:
- flrig not running on the configured `flrig_host`
- Firewall blocking 12345/tcp between Linux server and Windows shack PC
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

### Permission denied on cache or data directories

```bash
sudo chown -R www-data:www-data /var/www/bpq-dashboard/cache /var/www/bpq-dashboard/data
sudo chmod -R 775 /var/www/bpq-dashboard/cache /var/www/bpq-dashboard/data
```

### APRS map shows no stations

Check daemon status and APRS-IS connection.

```bash
sudo systemctl status bpq-aprs
cat /var/www/bpq-dashboard/cache/aprs/aprs-daemon.json | python3 -m json.tool | head -10
```

### Theme toggle doesn't work on a page

Hard-refresh the page (Ctrl+Shift+R). If still no response, clear the `bpq-dark-mode` localStorage key in browser DevTools.

---

## 13. Quick Reference

### File locations (default install to /var/www/bpq-dashboard)

| File / Directory | Purpose |
|---|---|
| `/var/www/bpq-dashboard/` | Dashboard web root |
| `/var/www/bpq-dashboard/config.php` | Main configuration |
| `/var/www/bpq-dashboard/logs/` | BPQ log files (symlink to LinBPQ directory) |
| `/var/www/bpq-dashboard/cache/` | Dashboard cache — automatically managed |
| `/var/www/bpq-dashboard/data/` | Persistent data — messages, station info, partners |
| `/var/www/bpq-dashboard/yapp-files/` | Received YAPP file transfers (new in v1.5.12) |
| `/usr/local/bin/bpq-chat-daemon.py` | BPQ Chat background service |
| `/usr/local/bin/bpq-vara-daemon.py` | VARA HF Terminal background service |
| `/usr/local/bin/bpq-aprs-daemon.py` | APRS background service |
| `/usr/local/bin/bpq-telnet-daemon.py` | Telnet + YAPP background service (new) |
| `/etc/systemd/system/bpq-*.service` | Daemon systemd services |

### Service management

```bash
# Check status of all dashboard daemons
sudo systemctl is-active nginx php8.3-fpm bpq vara-validator bpq-vara bpq-telnet bpq-chat bpq-aprs

# Restart a single daemon
sudo systemctl restart bpq-telnet

# Logs
sudo journalctl -u bpq-telnet -n 50 --no-pager
tail -50 /var/www/bpq-dashboard/logs/bpq-telnet-daemon.log
```

### All 8 daemons summary

| Service | What it does | Required for |
|---|---|---|
| nginx | Web server | Everything |
| php8.3-fpm | PHP processor | Everything |
| bpq | LinBPQ packet node | The heart of the station |
| vara-validator | Callsign allowlist enforcer | VARA HF (optional) |
| bpq-vara | VARA HF terminal bridge | VARA HF Terminal page |
| **bpq-telnet** | **Telnet WebSocket + YAPP bridge** | **Telnet Client + YAPP transfers** |
| bpq-chat | BPQ Chat bridge | Chat page |
| bpq-aprs | APRS packet receiver | APRS Map page |

### Complete crontab reference

```cron
# Edit root crontab with: sudo crontab -e

# Connect Watchdog — every 5 minutes
*/5 * * * * /usr/bin/python3 /usr/local/bin/connect-watchdog.py

# Storm Monitor — every hour
0 * * * * /usr/bin/python3 /usr/local/bin/storm-monitor.py >> /var/log/storm-monitor.log 2>&1

# Propagation Scheduler — every 48 hours at 06:00 UTC
0 6 */2 * * /usr/bin/python3 /usr/local/bin/prop-scheduler.py --apply >> /var/log/prop-scheduler.log 2>&1

# VARA log fetch from remote Windows PC — every 5 minutes
*/5 * * * * /usr/local/bin/fetch-vara.sh >> /var/log/vara-fetch.log 2>&1

# Monthly data archival — 1st of each month at 2 AM
0 2 1 * * /usr/local/bin/archive-bpq-data.sh >> /var/log/bpq-archive.log 2>&1

# Weekly YAPP files cleanup (optional)
0 3 * * 0 find /var/www/bpq-dashboard/yapp-files/ -mtime +30 -type f -delete
```

### Dashboard URLs at a glance

Replace `YOUR_SERVER_IP` with your server's actual IP or hostname.

| Page | URL |
|---|---|
| RF Connections | `http://YOUR_SERVER_IP/bpq-rf-connections.html` |
| Chat Client | `http://YOUR_SERVER_IP/bpq-chat.html` |
| VARA HF Terminal | `http://YOUR_SERVER_IP/bpq-vara.html` |
| Telnet Client | `http://YOUR_SERVER_IP/bpq-telnet.html` |
| System Logs | `http://YOUR_SERVER_IP/bpq-system-logs.html` |
| Traffic Stats | `http://YOUR_SERVER_IP/bpq-traffic.html` |
| Email Client | `http://YOUR_SERVER_IP/bbs-messages.html` |
| Message Monitor | `http://YOUR_SERVER_IP/bpq-email-monitor.html` |
| Connect Log | `http://YOUR_SERVER_IP/bpq-connect-log.html` |
| RF Power Monitor | `http://YOUR_SERVER_IP/rf-power-monitor.html` |
| Rig Status | `http://YOUR_SERVER_IP/rig-status.html` |
| APRS Map | `http://YOUR_SERVER_IP/bpq-aprs.html` |
| NWS Weather | `http://YOUR_SERVER_IP/nws-dashboard.html` |
| Maintenance | `http://YOUR_SERVER_IP/bpq-maintenance.html` |
| Firewall Status | `http://YOUR_SERVER_IP/firewall-status.html` |
| Log Viewer | `http://YOUR_SERVER_IP/log-viewer.html` |
| System Audit | `http://YOUR_SERVER_IP/system-audit.html` |
| Install Check | `http://YOUR_SERVER_IP/install-check.php` (pw: `bpqcheck`) |

---

**73 — BPQ Dashboard v1.5.13 — Amateur Radio Packet Node Operations Suite**

*Open source · https://github.com/bpq-dashboard/bpq-dashboard*
