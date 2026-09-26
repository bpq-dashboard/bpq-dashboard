# System Logs DB migration — bpq-system-logs.html

Moves `bpq-system-logs.html`'s stats cards, error breakdown, Event Types /
Hourly Activity charts, Top Stations, RMS card, TCP card, NodesRTT/
MHeard/RTKnown panels off client-side log parsing and onto pre-parsed
DB tables — same pattern as the `rf_connections`/`rf_messages` (RF
Connections page) and `traffic_stats` (Traffic page) migrations.

**Scope: stats/charts only.** The raw log viewer and its search/filter
UI (`displayLogs()`, `applyFilters()`, `searchForError()`) keep reading
`log_YYMMDD_BBS.txt` directly, unchanged — this was a deliberate call
(see chat/session notes) given the volume involved (millions of raw
lines vs. thousands of classified events) and because the viewer was
never the slow part; the client-side stats re-computation on every load
was.

## Why

Previously, loading this page meant fetching and parsing up to 365 days
/ 100,000 lines of `log_YYMMDD_BBS.txt` client-side on every visit
(`parseLogStats()`, ~480 lines of regex classification), plus three more
separate fetch+parse passes for `loadNodesRTT()`, `loadRMSLogs()`
(CMSAccess logs), and `loadTCPLogs()`. All of that now runs once,
incrementally, server-side via a cron job, and the page does one API
round-trip instead.

## What changed

| File | What changed |
|---|---|
| `data/system-logs-schema.sql` | **New** — 10 tables: `sys_events` (classified system-log events), `sys_station_daily` (per-day per-station rollup), `sys_internet_stations` (cumulative non-RF connects), `rms_connections`, `rms_login_failures`, `tcp_events`, `net_nodes_rtt`/`net_mheard`/`net_rtknown` (live-snapshot tables — see below), `sys_logs_parser_state`. |
| `scripts/parse-system-logs.py` | **New** — standalone parser (kept separate from `parse-vara-log.py`, matching `parse-traffic-report.py`'s precedent), six sub-parsers: `system`, `rms`, `tcp` (all "since window" re-scans, idempotent via UNIQUE keys, same pattern as `build_forwarded_messages()`) and `nodesrtt`, `mheard`, `rtknown` (live snapshots — DELETE + re-INSERT every run). |
| `system-logs-api.php` | **New** — 11 endpoints (`stats`, `hourly`, `top_stations`, `station_detail`, `errors`, `rms`, `tcp`, `nodesrtt`, `mheard`, `rtknown`, `internet_stations`, `all`). |
| `bpq-system-logs.html` | API-first-with-fallback wired in for: the main stats path (`loadSystemLogsStatsFromApi()`, called from `loadSystemLogs()` and `setTimeRange()`), and independently for MHeard and RTKnown (their own tabs/refresh buttons). Raw log fetching for the viewer (`allLogs`) is untouched and still runs every load regardless. |

## A note on what "one row per event" means here

Like `rf_messages`, these tables store one row per **classified event**
(a connection, a message, an error, a VARA bitrate sample, ...), not one
row per raw log line — `log_YYMMDD_BBS.txt` alone runs to millions of
lines over a year of history, so a line-for-line copy into MySQL was
never the goal. `sys_events.raw_line` is the one exception, and it's
populated **only** for `event_type = 'error'`, which is what keeps the
Recent Errors list and "click an error to find it in the viewer"
feature working without needing every line stored.

`parse-system-logs.py` is a line-for-line port of the existing
client-side parsing logic in `bpq-system-logs.html`'s `parseLogStats()`
and friends, including two inherited quirks kept for parity rather than
"fixed" (see the parser's comments for both):
- A dead branch in the original's message-direction cascade (compares an
  already-lowercased line against the literal uppercase substring
  `"YOURCALL"`, which can never match) is simply omitted rather than
  ported as always-false code.
- The error-detection trigger (`Error`/`Timeout`/`Failed` substrings,
  case-insensitive) doesn't match `"timed out"` on its own — only
  literal `"timeout"` — even though the *classification* cascade
  separately checks for `"timed out"`. That classification branch is
  therefore only reachable when one of the other trigger words is also
  present on the same line. This is how the original behaves; changing
  it would make the DB-backed error counts disagree with what the raw
  viewer highlights as an error line.

## Live-snapshot tables: `net_nodes_rtt` / `net_mheard` / `net_rtknown`

`nodesrtt.txt`, `MHSave.txt`, and `RTKnown.txt` are rewritten by BPQ in
place with **current state only** — there's no history to preserve, so
these three tables are fully replaced (`DELETE` + `INSERT`) on every
parser run, not incrementally appended to. `region` (RTKnown) stays a
client-side derivation (`getRegionFromCallsign()`), same as before —
that's presentation logic, not raw data.

One caveat worth knowing: earlier drafts of this parser used `TRUNCATE`
to clear these tables before each poll, but that requires the `DROP`
privilege, which a correctly-scoped app DB user (`SELECT`/`INSERT`/
`UPDATE`/`DELETE` only, no `DROP`/`ALTER`) won't have — if you see
`DROP command denied to user ... for table net_nodes_rtt` (or
`net_mheard`/`net_rtknown`), you're on an older copy of this script;
the current version uses `DELETE FROM` instead, which only needs the
`DELETE` privilege and participates properly in the surrounding
transaction (a failure partway through repopulating one of these small
tables now rolls back cleanly, leaving the previous snapshot intact,
rather than TRUNCATE's implicit-commit-then-empty-table behavior).

## RMS session pairing — a deliberate improvement over the original

The original JS summed `Disconnected. Bytes Sent=X Received=Y` lines
globally, with no per-station attribution at all, because that line
doesn't carry a callsign. `parse-system-logs.py` pairs each Disconnect
with the most recently opened, not-yet-closed Connect in the same file
(simple sequential proximity) — a reasonable assumption given BPQ's CMS
gateway handles one RMS session at a time, and it's the same "last
opened, not yet closed" pattern `parse-vara-log.py`'s `SessionParser`
already uses for VARA sessions. This means `rms_connections` has real
per-station byte/duration data the original page never had.

## Deploy

```bash
# 1. Schema
sudo mysql tprfn < data/system-logs-schema.sql
sudo mysql tprfn -e "SHOW TABLES LIKE 'sys_%'; SHOW TABLES LIKE 'rms_%'; SHOW TABLES LIKE 'tcp_%'; SHOW TABLES LIKE 'net_%'"

# 2. Deploy the parser and API
sudo cp scripts/parse-system-logs.py /var/www/tprfn/scripts/parse-system-logs.py
sudo cp system-logs-api.php /var/www/tprfn/system-logs-api.php
sudo chown www-data:www-data /var/www/tprfn/system-logs-api.php
sudo chown www-data:www-data /var/www/tprfn/scripts/parse-system-logs.py   # match your cron user

# 3. Dry-run the backfill first
sudo -u www-data python3 /var/www/tprfn/scripts/parse-system-logs.py \
    --logs-dir /var/www/tprfn/logs --backfill --dry-run --verbose

# 4. Real backfill (full history)
sudo -u www-data python3 /var/www/tprfn/scripts/parse-system-logs.py \
    --logs-dir /var/www/tprfn/logs --backfill --verbose

# 5. Deploy the updated page
sudo cp bpq-system-logs.html /var/www/tprfn/bpq-system-logs.html
sudo chown www-data:www-data /var/www/tprfn/bpq-system-logs.html
```

`--backfill` re-scans every `log_YYMMDD_BBS.txt` / `CMSAccess_*.log` /
`log_YYMMDD_TCP.txt` found in `--logs-dir` — for a full year of history
at ~19K lines/day (based on this station's actual log volume), expect
this to take a while and be I/O-bound; `--verbose` shows per-file
progress so you can confirm it's making progress rather than hung.

### Cron

Add alongside the existing `parse-vara-log.py`/`parse-traffic-report.py`
entries (`crontab -u www-data -e`):

```cron
*/5 * * * * /usr/bin/python3 /var/www/tprfn/scripts/parse-system-logs.py \
    --logs-dir /var/www/tprfn/logs >> /var/log/parse-system-logs.log 2>&1
```

Default mode (no `--backfill`/`--date`) re-scans the trailing 2 days for
`system`/`rms`/`tcp` and re-polls all three snapshots every run — cheap
enough for a 5-minute cadence, same interval as the other two parsers.

```bash
sudo touch /var/log/parse-system-logs.log
sudo chown www-data:www-data /var/log/parse-system-logs.log
```

## Verify

1. `sudo mysql tprfn -e "SELECT event_type, COUNT(*) FROM sys_events GROUP BY event_type"` — should show non-zero counts across connection/message/error/vara_bitrate rows (message_rejected may legitimately be zero if you haven't had any BID rejections recently).
2. Open `bpq-system-logs.html`, hard-refresh, and check the status line under the log viewer reads `(DB)` — same convention as the RF Connections and Traffic pages use to confirm the fast path is active.
3. Toggle the Error Details panel open — Recent Errors should show real log lines, clickable to jump to that line in the raw viewer below (still works, since `searchForError()` operates on the unchanged raw-viewer machinery).
4. Open the All Stations modal — should show every station touched in the window, not just the top 5 on the main panel (confirms the `station_limit=2000` param is taking effect and not getting shadowed by `action_errors()`'s own `limit`).
5. Click the MHeard and RTKnown tabs — their own status lines should also show `(DB)`.
6. Change the 1D/7D/30D range buttons — should re-fetch from the API (network tab shows a new `system-logs-api.php?action=all&days=...` request) rather than re-filtering in-browser.

## Rollback

Restore the three files from backup/git. `USE_SYSTEM_LOGS_API = false`
near the top of `bpq-system-logs.html`'s script also forces the legacy
file-parsing path without a full revert, useful for isolating whether a
problem is in the new DB path or pre-existing.
