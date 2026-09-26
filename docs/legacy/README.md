# Legacy Documentation

This directory contains feature-specific and historical documentation that
has been superseded by the consolidated **User Guide** (`USER-GUIDE.md`)
and **Installation Manual** (`USER-MANUAL.md`) in the project root.

These files are kept here because they contain deeper technical detail,
specific workflows, or historical decisions that may still be useful as
reference material. They are not maintained — content reflects the project
state when each was written.

## For current documentation, use these instead

- **User-focused features** → `../../USER-GUIDE.md` (root) — how to use every page
- **Installation & troubleshooting** → `../../USER-MANUAL.md` (root) — setting up and diagnosing
- **Release history** → `../../CHANGELOG.md` (root) — what's new in each version
- **Quick install** → `../../README.md` (root) — TL;DR setup summary

## What's here

| File | Era | Content |
|---|---|---|
| `NWS-WEATHER-README.md` | v1.4.2 | Deep-dive on the NWS alert scripts including Linux/Windows setup variations, systemd service configuration, regional filtering syntax, and BBS auto-posting. The User Guide §18 covers the dashboard view; this document has standalone-script details. |
| `INSTALL.md` | v1.5.x | Older generic install guide. Superseded by USER-MANUAL §4. |
| `POST-INSTALL-TEST.md` | v1.5.11 | Manual install verification steps. Superseded by `install-check.php` page in the dashboard itself. |
| `PUBLIC-DEPLOYMENT.md` | v1.5.x | Hardening guide for internet-facing dashboards. Superseded by USER-MANUAL §11. |
| `TROUBLESHOOTING.md` | v1.5.11 | General troubleshooting steps. Superseded by USER-MANUAL §12. |
| `DATA-ARCHIVAL.md` | 2025 | Analysis of dashboard data files and archival strategy. The User Manual §10.6 has the operational cron job; this doc explains the rationale. |

## When might you actually want these?

- **Specific subsystem deep-dive** that the User Guide summarizes
- **Historical context** — why some design decisions were made
- **Pre-consolidation install steps** — if comparing with the current Installation Manual

For day-to-day usage, the four files in the project root (`USER-GUIDE.md`, `USER-MANUAL.md`, `README.md`, `CHANGELOG.md`) cover everything you need.
