# Phase 1 — DB-backed RF Connections — Deployment Guide

This documents how to deploy the DB-backed RF Connections optimization
introduced in v1.5.12. After deployment, the RF Connections page fetches
pre-parsed VARA HF sessions from MySQL instead of parsing the VARA log
file on every page load.

## What changed

| Component | Path | Purpose |
|---|---|---|
| Schema | `data/rf-connections-schema.sql` | Two new tables: `rf_connections`, `rf_parser_state` |
| Parser | `scripts/parse-vara-log.py` | Reads VARA log incrementally, writes session rows |
| API | `rf-connections-api.php` | JSON endpoint for the page to fetch sessions |
| HTML | `bpq-rf-connections.html` | Modified to try API first, fall back to log parsing |

## Prerequisites

- MariaDB / MySQL already running and accessible
- Database credentials configured in `db-config.php` or `config.php`'s `db` section (updated 2026-09-26: the RF Connections API uses `tprfn-db.php`, which reads them from there)
- LinBPQ writing to a VARA log file (`/var/www/<dashboard>/logs/YOURCALL.vara`
  or similar)
- Python 3 with `pymysql` installed

### MySQL privileges for the app user

The parser only needs **standard CRUD privileges** — SELECT, INSERT, UPDATE,
DELETE. It does NOT need DROP, ALTER, or CREATE (the schema is applied once
by an admin user, not by the parser).

If your app user (typical: `bpqdash_user` or `bpqdash_user`) only has CRUD,
the parser handles that correctly:
- `--reset` uses `DELETE FROM` not `TRUNCATE` (TRUNCATE needs DROP)
- The optional `AUTO_INCREMENT` reset is wrapped in a try/except — it's
  cosmetic only; the parser continues if ALTER privilege is missing

If you see errors like "DROP command denied" or "ALTER command denied",
that means you're running a version older than this fix. Pull the latest
`parse-vara-log.py`.

## Deployment steps

### 1. Install Python dependencies

```bash
sudo apt install -y python3-pymysql
# Or, if not in your repo:
# sudo pip install pymysql --break-system-packages
```

### 2. Apply the schema

```bash
# Adjust path if your dashboard lives elsewhere
sudo mysql -u root -p bpqdash < /var/www/bpq-dashboard/data/rf-connections-schema.sql

# Verify the tables exist
sudo mysql -u root -p bpqdash -e "SHOW TABLES LIKE 'rf_%'"
# Expected output:
#   rf_connections
#   rf_parser_state
```

### 3. Deploy the parser

```bash
# The parser script is already in the dashboard's scripts/ directory.
# Make it executable:
sudo chmod +x /var/www/bpq-dashboard/scripts/parse-vara-log.py

# Test parsing in dry-run mode first (no DB writes)
sudo -u www-data python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py \
    --dry-run --verbose --log /var/www/bpq-dashboard/logs/YOURCALL.vara | head -20
```

### 4. Initial backfill

```bash
# This reads the entire VARA log and writes all sessions to the table.
# Idempotent — safe to run multiple times. Use --reset to wipe and re-do.
sudo -u www-data python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py \
    --reset --verbose --log /var/www/bpq-dashboard/logs/YOURCALL.vara

# Expected output:
#   RESETTING: clearing rf_parser_state and rf_connections...
#   ... session lines ...
#   Parsed N new lines from /var/www/bpq-dashboard/logs/YOURCALL.vara
#     Sessions emitted: M
```

Verify the table got populated:

```bash
sudo mysql -u root -p bpqdash -e "
    SELECT COUNT(*) AS total,
           MIN(session_start) AS earliest,
           MAX(session_start) AS latest
    FROM rf_connections"
```

### 5. Schedule incremental updates

The parser should run every few minutes to ingest new sessions as the
VARA log grows. Add to www-data's crontab:

```bash
sudo crontab -u www-data -e
```

Add this line:

```cron
# RF Connections — incremental VARA log → MySQL every 5 minutes
*/5 * * * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py --log /var/www/bpq-dashboard/logs/YOURCALL.vara >> /var/log/parse-vara-log.log 2>&1
```

Create the log file with correct ownership:

```bash
sudo touch /var/log/parse-vara-log.log
sudo chown www-data:www-data /var/log/parse-vara-log.log
```

### 6. Verify the API endpoint

```bash
# Sessions endpoint
curl -s 'http://localhost/rf-connections-api.php?action=sessions&days=7&limit=5' | python3 -m json.tool

# Stats endpoint
curl -s 'http://localhost/rf-connections-api.php?action=stats&days=30' | python3 -m json.tool

# Parser status — when did the parser last run?
curl -s 'http://localhost/rf-connections-api.php?action=parser_status' | python3 -m json.tool
```

Expected responses include `"ok": true` with `data` containing the requested
information.

### 7. Reload the dashboard page

Open `/bpq-rf-connections.html` in your browser and hard-refresh
(`Ctrl+Shift+R`). The status pill at the top right should show:

> `N connections (DB)`

The `(DB)` suffix indicates the API path was used. If you see just `N
connections` without `(DB)`, the page fell back to legacy log parsing —
check the browser console for `[RF API]` warning messages.

## Rollback

If anything goes wrong, the page degrades gracefully — without any code
changes — by setting one flag.

### Quick disable (no file changes needed)

The fastest rollback: drop the API table so the API returns 503 and
the page falls back to the legacy parser:

```sql
-- The page automatically falls back when the API errors
DROP TABLE rf_connections;
DROP TABLE rf_parser_state;
```

The page detects the API error and uses the legacy VARA log parser.

### Code disable

Open `bpq-rf-connections.html`, find this line near the top of the script:

```javascript
const USE_RF_API = true;
```

Change it to `false`, save, hard-refresh. The page always uses the legacy
parser regardless of API availability.

### Stop the cron job

```bash
sudo crontab -u www-data -e
# Comment out or delete the parse-vara-log.py line
```

## Monitoring

### Check parser health

```bash
# Last 50 lines of parser log
tail -50 /var/log/parse-vara-log.log

# How recent is the last parse?
sudo mysql -u root -p bpqdash -e "
    SELECT log_file,
           last_parsed_at,
           sessions_total,
           TIMESTAMPDIFF(MINUTE, last_parsed_at, NOW()) AS minutes_ago
    FROM rf_parser_state"
```

### Table growth

```bash
# Sessions per day for the last 7 days
sudo mysql -u root -p bpqdash -e "
    SELECT DATE(session_start) AS day, COUNT(*) AS sessions
    FROM rf_connections
    WHERE session_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(session_start)
    ORDER BY day DESC"
```

### Disk usage

The table is tiny — at typical traffic (~100 sessions/day), it's about
3 MB/year. No archival concerns.

```bash
sudo mysql -u root -p bpqdash -e "
    SELECT table_name,
           ROUND(data_length/1024/1024, 2) AS data_mb,
           ROUND(index_length/1024/1024, 2) AS index_mb,
           table_rows
    FROM information_schema.tables
    WHERE table_schema = 'bpqdash' AND table_name LIKE 'rf_%'"
```

## Expected improvement

| Metric | Before | After (estimate) |
|---|---|---|
| Initial page load | 5-15 sec | < 1 sec |
| Time-range filter | 0.5-2 sec | < 100 ms |
| Memory footprint (JS heap) | 30-100 MB | 5-10 MB |
| Bytes transferred on page load | 5-50 MB (logs) | 50-200 KB (JSON) |

Actual numbers depend on traffic volume and log file sizes. Measure with
browser DevTools Network tab before/after.

## Troubleshooting

### Page still shows "N connections" without "(DB)" suffix

The API path is failing silently. Open browser DevTools console, look for
`[RF API]` messages. Most common causes:

- **HTTP 503** — schema not applied. Re-run step 2.
- **HTTP 500** — DB connection failed. Check the credentials in `db-config.php` (or `config.php`'s `db` section).
- **Empty sessions array** — parser hasn't run yet. Run step 4 backfill.

### Parser cron job not running

```bash
# Check cron is alive
sudo systemctl status cron

# Check the log
tail -50 /var/log/parse-vara-log.log

# Manual test
sudo -u www-data python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py --verbose
```

### "Duplicate entry" errors in parser log

This is harmless — `INSERT IGNORE` is used so re-processing the same
sessions is safe. The errors mean the same session was attempted twice
(usually because the parser was reset but log offset wasn't).

### Frequencies showing as "Unknown" for API-loaded sessions

Frequency context comes from BBS log RADIO commands which are still
parsed client-side. If BBS logs aren't loading (check Network tab), all
API sessions will show band=Unknown until BBS logs become available.
This is expected behavior — the API path is a connection-list optimization,
not a complete data replacement.

## Future phases

- **Phase 2:** Add `rf_daily_stats` materialized view for the heatmap if
  `GROUP BY hour, band` queries become slow at scale (not yet implemented)
- **Phase 3 — Forwarded Messages, done 2026-09-16:** message parsing (FA/FC
  BBS-log proposal lines) moved server-side into `rf_messages`. Frequency
  parsing was already effectively server-side before this (the parser fills
  `freq_khz`/`band` from BBS-log RADIO/Incoming-Connect lines at ingest
  time — client-side `enrichConnectionsWithFrequency()` is a gap-filler for
  the minority of rows missing it, not the primary source), so Phase 3 as
  originally scoped is now complete. See "Phase 3 deployment" below.
- **Phase 4:** Apply same pattern to Connect Log and Message Monitor
  pages if their load times warrant it (not yet implemented)

## Phase 3 deployment — Forwarded Messages

Same pattern as the Phase 1 steps above, applied to the new `rf_messages`
table and `?action=messages` endpoint. Do this *after* Phase 1 is already
deployed and healthy.

### 1. Apply the schema update

The schema file is safe to re-run — `CREATE TABLE IF NOT EXISTS` won't
touch `rf_connections`/`rf_parser_state` if they already exist, it'll just
add the new `rf_messages` table.

```bash
sudo mysql -u root -p bpqdash < /var/www/bpq-dashboard/data/rf-connections-schema.sql
sudo mysql -u root -p bpqdash -e "SHOW TABLES LIKE 'rf_messages'"
```

### 2. Deploy the updated parser and API

```bash
sudo cp parse-vara-log.py /var/www/bpq-dashboard/scripts/parse-vara-log.py
sudo cp rf-connections-api.php /var/www/bpq-dashboard/rf-connections-api.php
```

### 3. Backfill message history

```bash
# Dry-run first to sanity check the message count looks reasonable
sudo -u www-data python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py \
    --dry-run --verbose --log /var/www/bpq-dashboard/logs/k1ajd.vara

# Real run — messages have no separate offset/checkpoint, so a normal
# (non --reset) run already backfills everything within the lookback
# window. For full history, use --reset (also re-parses sessions from
# scratch, same as the original Phase 1 backfill).
sudo -u www-data python3 /var/www/bpq-dashboard/scripts/parse-vara-log.py \
    --reset --verbose --log /var/www/bpq-dashboard/logs/k1ajd.vara
```

Verify:

```bash
sudo mysql -u root -p bpqdash -e "
    SELECT COUNT(*) AS total, MIN(msg_time) AS earliest, MAX(msg_time) AS latest
    FROM rf_messages"
```

### 4. Deploy the updated HTML page

```bash
sudo cp bpq-rf-connections.html /var/www/bpq-dashboard/bpq-rf-connections.html
```

Hard-refresh the page. Open the browser console and look for:

```
[Msg API] Loaded N messages from DB
```

If you instead see `[Msg API] HTTP 503` or `Malformed response`, the
schema step (1) didn't complete — the page still works, just falls back
to parsing messages from BBS logs client-side like before.

### Rollback

Same two options as Phase 1:

```javascript
// In bpq-rf-connections.html, near the top of the script:
const USE_MSG_API = true;   // change to false
```

or drop the table to force the same graceful fallback:

```sql
DROP TABLE rf_messages;
```

`USE_RF_API`/`rf_connections` are untouched by either of these — Phase 1
and Phase 3 fail independently of each other.

