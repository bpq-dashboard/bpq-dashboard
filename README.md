# BPQ Dashboard v1.5.6 — User Manual

**A comprehensive monitoring and operations suite for BPQ packet radio nodes.**

BPQ Dashboard transforms your LinBPQ or BPQ32 node into a fully-featured
web-based operations centre. Every aspect of your station — RF connections,
BBS traffic, VARA HF sessions, NetROM networking, APRS, NWS weather alerts,
and live terminal access — is visible and controllable from any browser on
your LAN.

---

## Table of Contents

1. [What is BPQ Dashboard?](#1-what-is-bpq-dashboard)
2. [Quick Start](#2-quick-start)
3. [RF Connections](#3-rf-connections)
4. [Traffic Monitor](#4-traffic-monitor)
5. [System Logs](#5-system-logs)
6. [Connect Log](#6-connect-log)
7. [Message Monitor](#7-message-monitor)
8. [BBS Messages](#8-bbs-messages)
9. [APRS Map](#9-aprs-map)
10. [BPQ Chat](#10-bpq-chat)
11. [NWS Weather Dashboard](#11-nws-weather-dashboard)
12. [BPQ Telnet Client](#12-bpq-telnet-client)
13. [VARA HF Terminal](#13-vara-hf-terminal)
14. [Maintenance Reference](#14-maintenance-reference)
15. [Settings](#15-settings)
16. [Automation Scripts](#16-automation-scripts)
17. [Security](#17-security)
18. [Troubleshooting](#18-troubleshooting)

---

## 1. What is BPQ Dashboard?

BPQ Dashboard is a web application that runs on the same Linux machine as
your LinBPQ or BPQ32 node. It reads your BPQ log files, connects to BPQ
services via telnet and WebSocket, and presents everything through a
modern browser interface — no special software required on the viewing device.

### Key capabilities

- **Real-time RF monitoring** — see who is connecting to your node, their signal reports, VARA throughput, and historical session data
- **BBS management** — read, write, and organise your BBS messages from any browser
- **Live terminal access** — connect to BPQ nodes via NetROM, your BBS, or any authorised station over VARA HF, directly in the browser
- **APRS map** — live APRS-IS map centred on your station with configurable filter radius
- **NWS weather** — real-time National Weather Service alerts for your area
- **System visibility** — logs, firewall status, system health, all in one place

---

## 2. Quick Start

After running `install.sh`, open your browser to:

```
http://YOUR-SERVER-IP/
```

The dashboard opens to the **RF Connections** page. The navigation bar at
the top links to every section. Most pages auto-refresh automatically.

**First time setup checklist:**

1. Confirm LinBPQ is running — the RF Connections page will show stations if BPQ is active
2. Open **Settings** (gear icon in nav) and verify your grid square, latitude and longitude
3. Check all daemons are running: `sudo systemctl status bpq-chat bpq-aprs bpq-telnet bpq-vara`
4. Open `bpq-vara.html` and add your first allowed station to the VARA HF allowlist
5. Run the health check at `http://YOUR-SERVER-IP/install-check.php`

---

## 3. RF Connections

**URL:** `bpq-rf-connections.html`

The main dashboard view — an interactive map and statistics panel showing
all stations that have connected to your node.

### Station Map

A Leaflet.js map centred on your station. Each connecting station appears
as a marker. Click any marker for callsign lookup data, grid square,
distance, session count, average SNR, and peak throughput.

Colour-coded lines connect stations to your node — red through green
indicates quality score based on SNR (40%), VARA throughput (35%),
and reliability (25%). Animated particles appear on the top 5 paths.

### Statistics panels

- **Today / 7 Day / 30 Day** tabs filter all statistics
- **Sessions** — total inbound connections
- **Unique stations** — distinct callsigns heard
- **Avg SNR** — mean signal-to-noise across all sessions
- **Peak speed** — highest VARA throughput recorded

---

## 4. Traffic Monitor

**URL:** `bpq-traffic.html`

Hourly and daily traffic charts. Shows inbound/outbound sessions,
bytes transferred, band distribution, and busiest callsigns.
Use the Today / 7 Day / 30 Day selector to change the time window.

---

## 5. System Logs

**URL:** `bpq-system-logs.html`

Live log viewer for BPQ's `log_*_BBS.txt` and `log_*_Nodes.txt` files.

- **Search by callsign** — filter all log entries for a specific station
- **PDF report** — generate a formatted PDF of search results
- **Error panel** — breakdown of RF failures, timeouts, and connect failures
- **Auto-refresh** — logs update every 5 minutes
- **MHeard list** — stations recently heard on each port

---

## 6. Connect Log

**URL:** `bpq-connect-log.html`

Searchable table of every connection to your node, parsed from BPQ's
ConnectLog files. Columns: time, station, direction, port, duration,
bytes TX/RX. Click any column header to sort. Click a callsign to
see all sessions for that station.

---

## 7. Message Monitor

**URL:** `bpq-email-monitor.html`

Dual-mode monitor for BBS message forwarding activity.

**BBS Mode:** Messages received/forwarded with hourly chart, bulletin
groups by category, per-partner forwarding statistics.

**VARA Mode:** VARA HF session timeline with per-session SNR,
throughput, and callsign validation results.

---

## 8. BBS Messages

**URL:** `bbs-messages.html`

Full BBS client in the browser — read, write, and organise messages
without using a packet terminal.

- **Read** — list new or all messages, click to open
- **Write** — personal messages, bulletins, replies, forwards
- **Kill** — delete messages
- **Folders** — create custom folders, move messages between them
- **Rules engine** — automatic filing rules by sender, subject, or category
- **Search** — full-text search across all messages

---

## 9. APRS Map

**URL:** `bpq-aprs.html`

Live APRS-IS map showing stations in your filter area. Click any
station for callsign, beacon text, grid square, and distance.
Filter radius is configurable in Settings. Powered by
`bpq-aprs-daemon.py` which maintains a persistent APRS-IS connection.

---

## 10. BPQ Chat

**URL:** `bpq-chat.html`

Browser-based BPQ chat client bridged via `bpq-chat-daemon.py`.

- Mode selector for BPQ Chat channels
- Sound alerts and browser notifications for incoming messages
- Unread message badge in browser tab
- Quick commands panel
- Export session transcript

---

## 11. NWS Weather Dashboard

**URL:** `nws-dashboard.html`

National Weather Service alerts for your configured location.
Alerts colour-coded by severity (Extreme / Severe / Moderate / Minor).
Configurable auto-refresh. Configure your NWS zone in `config.php`.

---

## 12. BPQ Telnet Client

**URL:** `bpq-telnet.html`

Full browser terminal connected to your BPQ node. No external software
needed — works in any browser on your LAN.

```
Browser → wss://your-server/ws/telnet → bpq-telnet-daemon.py → BPQ :8010
```

### Quick Connect buttons

| Button | Connects to |
|--------|-------------|
| BPQ Node | Node prompt |
| BPQ BBS | Node → BBS |
| BPQ Chat | Node → Chat |
| WL2K CMS | server.winlink.org:8772 |

### Live NetROM Sidebar

Active direct neighbours fetched from BPQ every 30 seconds. Green ●
means the node is reachable right now. Click to open a connection,
then log in and type the `C CALLSIGN` shown to connect via NetROM.

### BPQ Command Reference

Collapsible panel — click any command to paste it into the input box.

**Node commands:** `?` `NODES` `ROUTES` `LINKS` `PORTS` `INFO` `BBS` `CHAT` `BYE`

**BBS commands:** `L` `LA` `LM` `R #` `S CALL` `SP CALL` `SB TOPIC` `K #` `NODE` `B`

**Connect commands:** `C CALL` `C ALIAS` `MH #`

### Terminal features

ANSI colour, command history (Up/Down arrows), session timer,
copy transcript to clipboard, clean disconnect.

---

## 13. VARA HF Terminal

**URL:** `bpq-vara.html` *(Sysop password required)*

Browser-based VARA HF terminal for keyboard-to-keyboard HF operation.
Connects through BPQ's `ATT` command to your VARA HF port.

```
Browser → wss://your-server/ws/vara → bpq-vara-daemon.py
        → BPQ :8010 (login → NODE → ATT 3) → VARA HF modem → Radio
```

### Authentication

The page is blocked entirely until you enter the sysop password.
Unauthorised users can click "Request access from sysop" to send
an email to your configured sysop address.

### Frequency Selector

**Preset buttons** — your configured operating frequencies.
Clicking one immediately commands flrig to QSY and sets USB mode.

**Custom frequency** — enter any frequency in MHz. Validated against
ITU Region 2 HF data-authorised bands before QSY is commanded:

| Band | Authorised segments |
|------|---------------------|
| 160m | 1.800–2.000 MHz |
| 80m  | 3.525–4.000 MHz |
| 60m  | Channel plan only |
| 40m  | 7.025–7.300 MHz |
| 30m  | 10.100–10.150 MHz |
| 20m  | 14.025–14.350 MHz |
| 17m  | 18.068–18.168 MHz |
| 15m  | 21.025–21.450 MHz |
| 12m  | 24.890–24.990 MHz |
| 10m  | 28.000–29.700 MHz |

### Bandwidth

BW500 / BW2300 / BW2750 — sent to VARA after attaching the port.

### Allowed Stations

Sysop-managed allowlist in the sidebar. Add, toggle, or remove
callsigns. Click a callsign to populate the connect field.

### Connection workflow

1. Select frequency → rig QSYs immediately
2. Select bandwidth
3. Enter remote callsign → click Connect
4. Daemon: login → NODE → ATT 3 → BW command → C CALLSIGN
5. "Channel Busy" is normal — VARA waits for a clear channel
6. Status bar shows CONNECTED + remote callsign + live SNR

### Status indicators

| Status | Meaning |
|--------|---------|
| IDLE | No session |
| CONNECTING | Logging in and attaching VARA port |
| ATTACHED | Port attached, connect command sent |
| CONNECTED | VARA link up — data flowing |
| BUSY | Waiting for clear channel |

Click **Disconnect** or type `D` to cleanly release the VARA port.

---

## 14. Maintenance Reference

**URL:** `bpq-maintenance.html`

Sysop reference and diagnostic tool. Shows system paths, daemon
status, nginx/PHP configuration, database tables, BPQ connection
test, log diagnostics, cache management, and debug commands.

---

## 15. Settings

Accessible from the gear icon in the nav bar. Requires authentication.

- **Station** — callsign, grid square, latitude/longitude, NWS zone
- **BPQ** — host, telnet port, sysop password
- **Forwarding partners** — import from linmail.cfg or add manually

---

## 16. Automation Scripts

### Daemons (always running)

| Service | Port | Purpose |
|---------|------|---------|
| `bpq-chat` | WebSocket 8763 | BPQ Chat bridge |
| `bpq-aprs` | — | APRS-IS connection |
| `bpq-telnet` | WebSocket 8765 | Telnet terminal bridge |
| `bpq-vara` | WebSocket 8767 | VARA HF terminal bridge |

Check all daemons:
```bash
sudo systemctl status bpq-chat bpq-aprs bpq-telnet bpq-vara
```

### Optional scripts

| Script | Purpose |
|--------|---------|
| `prop-scheduler.py` | Adjusts forwarding schedules based on propagation |
| `storm-monitor.py` | Forces 80m during geomagnetic storms |
| `connect-watchdog.py` | Cron: restarts failed daemons |
| `vara-callsign-validator.py` | Validates VARA inbound callsigns |

---

## 17. Security

- Admin pages restricted to LAN IP ranges by nginx
- VARA HF terminal requires sysop password before page renders
- WebSocket endpoints (`/ws/telnet`, `/ws/vara`) restricted by IP allowlist
- Bot/scanner detection map in nginx blocks common probes
- Run `sudo certbot --nginx -d your-domain.com` for HTTPS/WSS
- Remove `install-check.php` after installation is verified

---

## 18. Troubleshooting

### No data on dashboard pages
1. `sudo systemctl status linbpq` — confirm BPQ is running
2. Check `config.php` `paths.logs` points to where BPQ writes log files
3. Run `http://your-server/install-check.php`

### Telnet Client — cannot connect
```bash
sudo systemctl status bpq-telnet
tail -30 /var/www/html/bpq/logs/bpq-telnet-daemon.log
nc -zv 127.0.0.1 8010
```

### VARA Terminal — login fails
```bash
tail -30 /var/www/html/bpq/logs/bpq-vara-daemon.log
```
Check password in `vara-api.php` matches your BPQ `USER=` line.

### VARA Terminal — "Channel Busy"
Normal on HF. VARA is waiting for a clear channel. If persistent,
confirm the frequency is clear and the radio is on USB.

### NetROM sidebar empty
```bash
curl -s http://localhost/bpq-nodes-api.php | head -20
grep BPQ_USER /var/www/html/bpq/bpq-nodes-api.php
```

### Daemons not starting after reboot
```bash
sudo systemctl daemon-reload
sudo systemctl enable bpq-chat bpq-aprs bpq-telnet bpq-vara
sudo systemctl restart bpq-chat bpq-aprs bpq-telnet bpq-vara
```

### flrig QSY not working
- Confirm flrig is running: `pgrep flrig`
- Check host/port in `vara-api.php` match your flrig settings
- Test: `curl http://FLRIG_HOST:12345/RPC2`

---

*BPQ Dashboard v1.5.6 — April 2026*
*See [CHANGELOG.md](CHANGELOG.md) for full version history.*
