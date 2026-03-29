# BPQ Dashboard v1.5.3

A comprehensive monitoring dashboard suite for BPQ32/LinBPQ packet radio nodes.

## Features

- **RF Connections** — VARA metrics, frequency detection, WL2K sessions, station mapping, propagation analysis
- **BBS Messages** — Thunderbird-style client with rules engine, folder management, signatures, multi-select
- **System Logs** — Live log viewer, MHeard stations, routing table
- **Traffic Stats** — Station rankings, efficiency metrics
- **Message Monitor** — BBS forwarding analytics and SMTP/POP server analytics
- **Connect Log** — Connection mode analytics with station mapping
- **NWS Weather** — National Weather Service alerts with BBS posting
- **Connect Watchdog** — Auto-suspends unreachable forwarding partners
- **Propagation Scheduler** — HF schedule optimization based on solar conditions
- **Storm Monitor** — Tiered partner suspension during geomagnetic storms

## Documentation

- [Linux Installation Guide](INSTALL-LINUX.md)
- [Windows Installation Guide](INSTALL-WINDOWS.md)
- [Changelog](CHANGELOG.md)

## Quick Start

1. Copy `config.php.example` to `config.php`
2. Edit your callsign, coordinates, BBS credentials
3. Point the `logs/` directory at your BPQ32/LinBPQ log files
4. Open `health-check.php` in your browser to verify the installation

## Requirements

- PHP 7.4+ with `sockets` and `curl` extensions
- Apache or nginx web server
- BPQ32 (Windows) or LinBPQ (Linux) already running

## Network

Developed for the [TPRFN Network](https://www.tprfn.net) — The Packet Radio RF Forwarding Network.

**K1AJD — Augusta, GA**

## License

MIT License
