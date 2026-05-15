# Changelog

## v1.5.7-generic — 2026-05-15

First public-redistributable release. Derived from a private operator's
v1.5.6 build through a comprehensive genericization pass.

### Removed (operator-specific)

- `rf-power-monitor.html` — depended on a specific WaveNode WN-2 power
  meter setup not available to other operators
- `rig-status.html` — depended on a specific flrig configuration
- All WaveNode-specific helpers: `wavenode-reader.py`, `wavenode-api.php`,
  `fetch-wavenode.sh`, `wavenode-install.bat`, `wavenode-sync.sh`,
  `cron/wavenode-archive`
- Windows-only NWS monitor stack (`nws-monitor.bat`, `.ps1`, `.sh`,
  `.service`) — was tied to a specific dual-machine shack architecture
- Operator-specific BPQ config `bpq32.cfg` — new sysops will use their
  own
- Operator-specific helper scripts: `sync-datalog.bat`,
  `sync-datalog-sshkey.bat`, `sync-bpq-logs.sh`, `restore-radio2.sh`,
  `fetch-vara.bat`, `github-push.sh`
- Stale PDF manuals (v1.4.2, v1.5.2, v1.5.6) — replaced by `README.md`,
  `INSTALL.md`, and `TROUBLESHOOTING.md`
- Private session journal (developer notes only)
- Third-party contributed scripts in `archives/n5mdt/` that weren't ours
  to redistribute

### Renamed (genericization)

| Old name | New name |
|---|---|
| `tprfn-db.php` | `bpqdash-db.php` |
| `tprfn-hub-report.php` | `hub-report.php` |
| `tprfn.conf` | `bpq-dashboard.conf` |
| `nginx-tprfn.conf` | `nginx-bpq-dashboard.conf` |
| PHP function `tprfn_db()` | `bpqdash_db()` |
| PHP function `tprfn_query()` | `bpqdash_query()` |
| PHP function `tprfn_execute()` | `bpqdash_execute()` |
| PHP function `tprfn_query_one()` | `bpqdash_query_one()` |
| PHP function `tprfn_insert_session()` | `bpqdash_insert_session()` |
| PHP function `tprfn_upsert_station()` | `bpqdash_upsert_station()` |
| PHP function `tprfn_db_available()` | `bpqdash_db_available()` |
| PHP function `tprfn_duration_to_secs()` | `bpqdash_duration_to_secs()` |
| PHP function `tprfn_db_write_sessions()` | `bpqdash_db_write_sessions()` |
| Web root `/var/www/tprfn/` | `/var/www/bpq-dashboard/` |
| Database `tprfn` | `bpqdash` |
| DB user `tprfn_app` | `bpqdash` |
| DOM id `tprfnHubList` | `hubList` |
| JS variable `DEFAULT_TPRFN_HUB_STATIONS` | `DEFAULT_HUB_STATIONS` |
| JS function `updateTPRFNHubStations` | `updateHubStations` |
| Constants `TPRFN_DB_*` | `BPQDASH_DB_*` |
| nginx rate-limit zones `tprfn_*` | `dashboard_*` |
| Hostname `ARSSYSTEM` | `BPQHOST` |
| Operator callsign (placeholder in code) | `YOURCALL` |
| Operator username (placeholder in code) | `SYSOPUSER` |
| Operator email (placeholder in code) | `sysop@example.com` |
| Hard-coded station coordinates | `0.0, 0.0` (forces user to set) |
| Hard-coded grid square | `AA00aa` placeholder |
| BBS alias `AUGBBS` | `MYBBS` placeholder |
| Hardcoded LAN IPs `10.0.0.x` | `192.168.1.x` (example range) |

### Scrubbed (security)

The v1.5.6 `config.php.example` contained working credentials. The
v1.5.7 template uses `CHANGE_THIS_PASSWORD` / `CHANGE_THIS_DB_PASSWORD`
placeholders.

If you are upgrading from v1.5.6 of the operator-specific build, you
should also **rotate any passwords that were ever in your config.php
or bpq32.cfg files that were committed to a git repository**.

### Documentation

- Brand-new `install.sh` written for hams who have never used Linux —
  long-form explanations of each step, web-server auto-detection
  (nginx or Apache), MariaDB optional, full color-coded output, summary
  at end with the URL to open
- Brand-new `README.md`, `INSTALL.md`, and `TROUBLESHOOTING.md` written
  from a generic-sysop perspective
- Old developer-internal docs (`CODE-REVIEW.md`, `SECURITY-AUDIT.md`,
  `POP3-SMTP-INTEGRATION.md`, `PUBLIC-DEPLOYMENT.md`) removed; their
  useful content rolled into the new docs

### Carried forward unchanged from v1.5.6

- May 14 fix to `bpq-system-logs.html` for Tailwind CDN preflight
  rendering regression
- May 15 audit hardening pass on `nws-dashboard.html` (Tailwind
  preflight resistance + Norman OK convective quick-view modal)
- All feature-level functionality of the dashboard pages

## v1.5.6 and earlier

See the original operator-build changelog (not redistributed).
