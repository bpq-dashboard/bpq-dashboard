# BPQ Dashboard

**Version 1.5.7** — A web dashboard for amateur radio BPQ packet nodes.

A self-hosted, locally-run web interface for monitoring a BPQ32 / LinBPQ
packet radio node. Shows live RF connections, BBS messages, station
heatmaps, partner-quality scoring, traffic statistics, system logs, NWS
weather alerts, and APRS data — all in one place. Designed for sysops
who want more visibility into their node than the built-in BPQ pages
provide.

This is the **generic redistributable cut** — any licensed amateur
radio operator can install and run it on their own server. There are
no calls home, no telemetry, no required cloud accounts; everything
runs on your machine and the data stays there.

## What's in the box

### Dashboard pages

| Page | What it shows |
|---|---|
| `bpq-rf-connections.html` | Live and historical RF sessions; station map; partner quality; forwarding analysis |
| `bpq-system-logs.html` | BBS, VARA, Telnet, and Datalog files with filtering and search |
| `bpq-connect-log.html` | Per-callsign connection history and patterns |
| `bpq-traffic.html` | Hourly/daily traffic charts, frequency-of-use, top stations |
| `bpq-email-monitor.html` | BPQMail / Winlink message activity |
| `bbs-messages.html` | Browse, read, and post BBS messages from the web |
| `bpq-telnet.html` | Web-based BPQ telnet terminal |
| `bpq-chat.html` | Web-based BPQ chat client |
| `bpq-aprs.html` | APRS station map and packet history |
| `bpq-vara.html` | VARA modem activity, sessions, and call validator |
| `nws-dashboard.html` | NWS severe weather alerts with one-click BBS posting; includes a Norman OK convective quick-view |
| `hub-ops.html` | Multi-station hub operations view |
| `firewall-status.html` | Live iptables / firewall state |
| `system-audit.html` | Health check of all dashboard services and dependencies |
| `bpq-maintenance.html` | Sysop tools: stop/start services, view recent changes |
| `log-viewer.html` | General-purpose log file viewer |

### Background daemons (optional)

| Daemon | Purpose |
|---|---|
| `bpq-telnet-daemon.py` | Persistent BPQ telnet bridge for the web terminal |
| `bpq-chat-daemon.py` | Persistent BPQ chat bridge for the web chat client |
| `bpq-aprs-daemon.py` | Captures APRS packets from BPQ into a queryable log |
| `bpq-vara-daemon.py` | VARA modem session logger |
| `connect-watchdog.py` | Auto-blocks repeated failed connect attempts |
| `storm-monitor.py` | Geomagnetic storm watch; forces 80m-only forwarding during Kp ≥ 5 |
| `prop-scheduler.py` | Adjusts BPQ HF forwarding schedule based on solar/seasonal data |

You can run the dashboard pages with no daemons at all — the daemons
add real-time and HF-optimization features.

## Requirements

- **Linux server** running Debian, Ubuntu, or Raspberry Pi OS
  (the installer is `apt`-based)
- **Web server**: nginx or Apache (installer detects either, or installs
  nginx if neither is present)
- **PHP 8.0 or later** with FPM, plus the curl, json, mbstring, xml,
  mysql, sqlite3, and gd extensions
- **Python 3.8 or later** with `requests`, `pexpect`, and `pyserial`
  (for the helper daemons only)
- **MariaDB or MySQL** (optional — features degrade gracefully without it)
- **A running BPQ32 / LinBPQ node** to monitor

## Quick install

```bash
sudo bash install.sh
```

The installer is interactive and walks you through every step. It
takes 5-15 minutes depending on what's already on your system.

See `INSTALL.md` for the full installation guide.

## Configuration

After install, your configuration lives at:

    /var/www/bpq-dashboard/config.php

Edit this file to change your callsign, BPQ credentials, station
coordinates, or any other setting. Changes are picked up on the next
page load — no restart needed.

A documented template is at `config.php.example`.

## Lineage

This is `v1.5.7-generic`, derived from a private operator's `v1.5.6`
build. The genericization pass removed all operator-specific
identifiers, callsigns, IP addresses, passwords, and geographic
coordinates. See `CHANGELOG.md` for what changed.

The dashboard was originally developed for one operator's station and
then refactored for general distribution; if you notice anything in
the code that still looks operator-specific, that's a bug — please
report it.

## License

This software is distributed for use by licensed amateur radio
operators. Use it on your own station, modify it freely, share it with
other hams. No warranty of any kind.

## Support

This is community-maintained software. The best places to ask for help:

- BPQ mailing list (BPQ32 Yahoo group, now on groups.io)
- Your local digital packet community

When asking for help, include the install log
(`/tmp/bpq-dashboard-install-*.log`) and the output of:

```bash
curl -s http://localhost/system-audit.html | grep -i error
```

73 — enjoy the dashboard.
