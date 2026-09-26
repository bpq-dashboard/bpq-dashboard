# Traffic Report — DB-backed migration — Deployment Guide

This documents how to deploy the DB-backed Traffic Report migration
built 2026-09-20. After deployment, `bpq-traffic.html` fetches
pre-parsed BPQ traffic accounting from MySQL instead of fetching and
parsing every `Traffic_YYMMDD.txt` file client-side on every page load.

## What changed

| Component | Path | Purpose |
|---|---|---|
| Schema | `data/traffic-schema.sql` | Two new tables: `traffic_stats`, `traffic_parser_state` |
| Parser | `scripts/parse-traffic-report.py` | Reads `Traffic_YYMMDD.txt` files, writes/replaces per-station-per-day rows |
| API | `traffic-api.php` | JSON endpoint for the page to fetch station totals |
| HTML | `bpq-traffic.html` | Modified to try the API first, fall back to file parsing |

This is a **new table, new parser, new API file** — not an extension of
`rf_connections`/`rf_parser_state`/`rf-connections-api.php`. Those are
scoped to VARA HF connections only; `Traffic_YYMMDD.txt` covers every
connection type BPQ handles (VARA HF, packet/AX.25, Telnet) plus
rejected-message counts nothing else in this project tracks. See the
2026-09-20 (2) entry in `docs/SESSION-JOURNAL.md` for the full reasoning.

## Prerequisites

- MariaDB/MySQL already running, `tprfn` database already exists
- Database credentials configured in `db-config.php` (or `config.php`'s
  `db` section). The parser reads the same file via
  `scripts/bpq_db_config.py`; `BPQ_DB_*` env vars / `--db-*` flags override it.
  (Updated 2026-09-26; credentials used to be hard-coded in `tprfn-db.php`.)
- BPQ32 writing `Traffic_YYMMDD.txt` files to the dashboard's `logs/`
  directory (already required for the legacy client-side path, so this
  should already be true if the page has ever shown data)
- Python 3 with `pymysql` — already installed if `parse-vara-log.py` is
  running (same dependency); check with:
  ```bash
  python3 -c "import pymysql; print('OK')"
  ```
  If missing: `sudo apt install -y python3-pymysql`

### MySQL access

This server uses socket auth for the root/admin connection (see
`docs/SESSION-JOURNAL.md`, 2026-09-19 entry: `sudo mysql tprfn`, no
`-p`, no root password needed) — every command below uses that form.
Adjust if your server differs.

The parser only needs standard CRUD privileges (SELECT, INSERT, UPDATE,
DELETE) against `traffic_stats`/`traffic_parser_state` — same as
`parse-vara-log.py` needs against `rf_connections`/`rf_parser_state`.
The schema itself is applied once via the admin/root connection, not by
the parser.

## Deployment steps

### 1. Diff the incoming files against what's live

Standard practice for this project (see `docs/SESSION-JOURNAL.md`
throughout) — before copying anything, diff each new/changed file
against the current production copy so nothing gets silently clobbered:

```bash
diff /var/www/tprfn/bpq-traffic.html            /path/to/new/bpq-traffic.html
# traffic-api.php, scripts/parse-traffic-report.py, data/traffic-schema.sql
# are all new files — no live copy to diff against.
```

### 2. Apply the schema

```bash
sudo mysql tprfn < /var/www/tprfn/data/traffic-schema.sql

# Verify the tables exist
sudo mysql tprfn -e "SHOW TABLES LIKE 'traffic_%'"
# Expected output:
#   traffic_stats
#   traffic_parser_state
```

### 3. Deploy the files

```bash
sudo cp traffic-api.php bpq-traffic.html /var/www/tprfn/
sudo cp scripts/parse-traffic-report.py /var/www/tprfn/scripts/

sudo chown www-data:www-data /var/www/tprfn/traffic-api.php \
    /var/www/tprfn/bpq-traffic.html \
    /var/www/tprfn/scripts/parse-traffic-report.py

sudo chmod +x /var/www/tprfn/scripts/parse-traffic-report.py
```

### 4. Test parsing in dry-run mode first

```bash
sudo -u www-data python3 /var/www/tprfn/scripts/parse-traffic-report.py \
    --dry-run --verbose --backfill
```

Confirm the file count and per-file station-row counts look sane before
writing anything for real.

### 5. Initial backfill

```bash
# Reads every Traffic_*.txt file present in logs/ and writes all rows.
# Idempotent — safe to re-run; each run fully replaces that day's rows.
sudo -u www-data python3 /var/www/tprfn/scripts/parse-traffic-report.py \
    --backfill --verbose

# Expected output ends with:
#   Processed N file(s), wrote M station-day row(s)
```

Verify the table got populated:

```bash
sudo mysql tprfn -e "
    SELECT COUNT(*) AS total_rows,
           COUNT(DISTINCT callsign) AS stations,
           MIN(station_date) AS earliest,
           MAX(station_date) AS latest
    FROM traffic_stats"
```

### 6. Schedule incremental updates — every 5 minutes

Matches `parse-vara-log.py`'s existing cron cadence exactly.

```bash
sudo crontab -u www-data -e
```

Add this line:

```cron
# Traffic Report — Traffic_YYMMDD.txt → MySQL every 5 minutes
*/5 * * * * /usr/bin/python3 /var/www/tprfn/scripts/parse-traffic-report.py --days 8 >> /var/log/parse-traffic-report.log 2>&1
```

**Use `--days 8`** (corrected 2026-09-26). The parser's default window is
today + yesterday, which assumes BPQ writes one `Traffic_YYMMDD.txt` per
day. On K1AJD's station BPQ writes **one per week**, named for the week's
start date and written the following Saturday at 06:00 (e.g.
`Traffic_260920.txt` appeared 2026-09-26). With the default window, cron
never found a file: every run logged `Processed 0 file(s)`, and nothing
after the initial backfill was ever loaded. `--days 8` always covers the
latest weekly file, and re-reading it is harmless (rows are replaced,
not duplicated). If your BPQ writes daily files, `--days 8` still works.
`--backfill` is only for the one-time initial load in step 5.

Create the log file with correct ownership:

```bash
sudo touch /var/log/parse-traffic-report.log
sudo chown www-data:www-data /var/log/parse-traffic-report.log
```

### 7. Verify the API endpoint

```bash
curl -s 'http://localhost/traffic-api.php?action=stations&days=28' | python3 -m json.tool
curl -s 'http://localhost/traffic-api.php?action=parser_status' | python3 -m json.tool
curl -s 'http://localhost/traffic-api.php?action=all&days=28' | python3 -m json.tool
```

Expected responses include `"ok": true` with `data` containing the
requested information.

### 8. Reload the dashboard page

Open `/bpq-traffic.html` in your browser and hard-refresh
(`Ctrl+Shift+R`). The status pill at the top right should show:

> `N stations (28 days) (DB)`

The `(DB)` suffix indicates the API path was used. If you see the old
"N reports" phrasing instead, the page fell back to legacy file
parsing — check the browser console for `[Traffic API]` warning
messages.

## Rollback

Same two-tier approach as the RF Connections migration.

### Quick disable (no file changes needed)

```sql
DROP TABLE traffic_stats;
DROP TABLE traffic_parser_state;
```

The API then returns 503, and the page automatically falls back to the
legacy `Traffic_YYMMDD.txt` file parser — no code change needed.

### Code disable

Open `bpq-traffic.html`, find this line near the top of the script:

```javascript
const USE_TRAFFIC_API = true;
```

Change it to `false`, save, hard-refresh. The page always uses the
legacy file parser regardless of API/table availability.

### Stop the cron job

```bash
sudo crontab -u www-data -e
# Comment out or delete the parse-traffic-report.py line
```

## Monitoring

### Check parser health

```bash
tail -50 /var/log/parse-traffic-report.log

sudo mysql tprfn -e "
    SELECT last_parsed_at,
           files_processed,
           files_total,
           rows_total,
           TIMESTAMPDIFF(MINUTE, last_parsed_at, NOW()) AS minutes_ago
    FROM traffic_parser_state"
```

`minutes_ago` should never exceed ~5-10 once the cron job is running.

### Table growth

```bash
sudo mysql tprfn -e "
    SELECT station_date, COUNT(*) AS stations
    FROM traffic_stats
    WHERE station_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY station_date
    ORDER BY station_date DESC"
```

### Disk usage

Same order of magnitude as `rf_connections` — one small row per station
per day, not per connection. Expect low single-digit MB/year even with
dozens of active stations.

```bash
sudo mysql tprfn -e "
    SELECT table_name,
           ROUND(data_length/1024/1024, 2) AS data_mb,
           ROUND(index_length/1024/1024, 2) AS index_mb,
           table_rows
    FROM information_schema.tables
    WHERE table_schema = 'tprfn' AND table_name LIKE 'traffic_%'"
```

## Troubleshooting

### Page shows "N reports" instead of "(DB)"

The API path is failing silently. Open browser DevTools console, look
for `[Traffic API]` messages. Most common causes:

- **HTTP 503** — schema not applied. Re-run step 2.
- **HTTP 500** — DB connection failed. Check the credentials in `db-config.php` (or `config.php`'s `db` section).
- **Empty stations array** — parser hasn't run yet, or hasn't run for
  the requested `days` window. Run step 5 backfill, or widen `--days`.

### Parser cron job not running

```bash
sudo systemctl status cron
tail -50 /var/log/parse-traffic-report.log
sudo crontab -u www-data -l   # confirm the line is actually present
```

### Numbers look different from what the page used to show

Two known, deliberate carry-overs from the legacy behavior — not bugs
in the migration (see `docs/SESSION-JOURNAL.md`, 2026-09-20 (2) entry
for full detail):

- The top "Messages" card and Message Distribution chart only ever
  summed Personal + Bulletin messages, excluding the Traffic/NTS (T)
  category. This is preserved exactly as it was, not fixed.
- `bpq-traffic.html`'s connection/message/byte counts have only ever
  reflected whatever BPQ itself reports in `Traffic_YYMMDD.txt` — this
  migration doesn't change *what* is counted, only *where it's read
  from*. If numbers look different from before the migration, the
  first thing to check is whether `--backfill` actually covered the
  same date range the page is currently requesting (`days` param).

### `--backfill` seems slow or touches far more files than expected

`Traffic_YYMMDD.txt` files may go back much further than expected if
they've never been rotated/archived (the VARA log backfill on
2026-09-19 covered data back to 2025-12-27 for reference). Check the
actual count first: `ls /var/www/tprfn/logs/Traffic_*.txt | wc -l`.
Each file is small (a handful of station lines), so even a year's
worth should complete quickly — if it doesn't, check for a stray very
large file with `ls -la`.
