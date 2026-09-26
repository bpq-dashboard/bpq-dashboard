#!/usr/bin/env python3
"""
parse-traffic-report.py — Parses BPQ's Traffic_YYMMDD.txt reports into
the traffic_stats table.

Reads BPQ32's own daily per-station traffic accounting files (one file per
UTC day, rewritten by BPQ throughout that day as a running cumulative
total) and writes one row per (date, callsign) to traffic_stats.

Unlike parse-vara-log.py (an append-only log, tracked by byte offset),
Traffic_YYMMDD.txt is a small daily snapshot file that BPQ overwrites in
place — there's nothing to offset-track. Instead, this script just
re-reads whichever days are asked for and fully replaces each station's
row for that day. Re-running is always safe: today's file changes
throughout the day, so re-parsing it is expected, not a special case.

Usage:
  # Normal / cron mode — re-parse today + yesterday (catches UTC midnight
  # rollover and covers any gap since the last run)
  parse-traffic-report.py

  # Re-parse a specific trailing window
  parse-traffic-report.py --days 7

  # One specific date
  parse-traffic-report.py --date 260920

  # Backfill — parse every Traffic_*.txt file found in the logs directory
  parse-traffic-report.py --backfill

  # Dry-run — show what would be written, don't actually INSERT
  parse-traffic-report.py --dry-run

Configuration:
  Credentials default to the same as tprfn-db.php. Override via env vars:
    BPQ_DB_HOST, BPQ_DB_NAME, BPQ_DB_USER, BPQ_DB_PASS, BPQ_DB_PORT
  Or command-line flags above.

Exit codes:
  0  success (zero or more rows parsed)
  1  config error (missing credentials, logs directory not found)
  2  database error (connection failed, schema missing)
"""

import argparse
import datetime as dt
import glob
import os
import re
import sys

try:
    import pymysql
except ImportError:
    sys.stderr.write(
        "Missing dependency: pymysql\n"
        "Install: sudo apt install python3-pymysql\n"
        "    or: pip install pymysql --break-system-packages\n"
    )
    sys.exit(1)


# ─── Configuration ──────────────────────────────────────────────────────────

# config.php paths.logs, else <web root>/logs (2026-09-26; was hard-coded).
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402
DEFAULT_LOGS_DIR = bpq_db_config.default_logs_dir()

# Match tprfn-db.php defaults
# DB credentials: see scripts/bpq_db_config.py (2026-09-26). Nothing
# secret is hard-coded here any more — it comes from --db-* flags, BPQ_DB_*
# env vars, db-config.php, or config.php's db section, in that order.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402
DB_CONFIG = bpq_db_config.load_db_config({
    'charset': 'utf8mb4',
    'autocommit': False,
})


# ─── Traffic file parsing ───────────────────────────────────────────────────
#
# One line per station, format (whitespace-separated):
#   [(B)] CALLSIGN  connIn  connOut  msgRx(P/B/T)  msgTx(P/B/T)
#         bytesRx(P/B/T)  bytesTx(P/B/T)  rejIn(P/B/T)  rejOut(P/B/T)
#
# e.g.:
#   (B) N4SD-7      12   9   3/1/0   2/0/1   4521/812/0   3011/0/440   0/0/0   0/0/0
#
# Ported directly from bpq-traffic.html's client-side parseTrafficFile()
# regex — same field order, same P/B/T sub-format.

LINE_RE = re.compile(
    r'^\s*(\(B\))?\s*([A-Z0-9]{3,8})\s+(\d+)\s+(\d+)\s+'
    r'(\d+/\d+/\d+)\s+(\d+/\d+/\d+)\s+(\d+/\d+/\d+)\s+'
    r'(\d+/\d+/\d+)\s+(\d+/\d+/\d+)\s+(\d+/\d+/\d+)',
    re.IGNORECASE
)

FILENAME_RE = re.compile(r'^Traffic_(\d{2})(\d{2})(\d{2})\.txt$')


def parse_pbt(s):
    """'3/1/0' -> (3, 1, 0). Malformed input -> (0, 0, 0)."""
    m = re.match(r'(\d+)/(\d+)/(\d+)', s)
    if not m:
        return (0, 0, 0)
    return (int(m.group(1)), int(m.group(2)), int(m.group(3)))


def parse_traffic_file(text):
    """Parse one Traffic_YYMMDD.txt file's contents.

    Returns a list of dicts, one per station line, with keys matching
    traffic_stats columns (minus station_date/source_file, added by the
    caller since they're per-file, not per-line).
    """
    rows = []
    for line in text.splitlines():
        m = LINE_RE.match(line)
        if not m:
            continue
        is_bbs = bool(m.group(1))
        callsign = m.group(2).upper()
        conn_in = int(m.group(3))
        conn_out = int(m.group(4))
        msg_rx = parse_pbt(m.group(5))
        msg_tx = parse_pbt(m.group(6))
        bytes_rx = parse_pbt(m.group(7))
        bytes_tx = parse_pbt(m.group(8))
        rej_in = parse_pbt(m.group(9))
        rej_out = parse_pbt(m.group(10))

        rows.append({
            'callsign': callsign, 'is_bbs': is_bbs,
            'conn_in': conn_in, 'conn_out': conn_out,
            'msg_rx_p': msg_rx[0], 'msg_rx_b': msg_rx[1], 'msg_rx_t': msg_rx[2],
            'msg_tx_p': msg_tx[0], 'msg_tx_b': msg_tx[1], 'msg_tx_t': msg_tx[2],
            'bytes_rx_p': bytes_rx[0], 'bytes_rx_b': bytes_rx[1], 'bytes_rx_t': bytes_rx[2],
            'bytes_tx_p': bytes_tx[0], 'bytes_tx_b': bytes_tx[1], 'bytes_tx_t': bytes_tx[2],
            'rej_in_p': rej_in[0], 'rej_in_b': rej_in[1], 'rej_in_t': rej_in[2],
            'rej_out_p': rej_out[0], 'rej_out_b': rej_out[1], 'rej_out_t': rej_out[2],
        })
    return rows


# ─── Database ────────────────────────────────────────────────────────────────

def db_connect():
    """Open MySQL connection. Exits with code 2 if connection fails."""
    try:
        bpq_db_config.warn_if_no_password(DB_CONFIG)
        return pymysql.connect(**DB_CONFIG)
    except pymysql.MySQLError as e:
        sys.stderr.write(f"ERROR: Database connection failed: {e}\n")
        sys.stderr.write(
            "Check credentials in $BPQ_DB_* env vars or use --db-* flags.\n"
        )
        sys.exit(2)


UPSERT_SQL = """
    INSERT INTO traffic_stats
        (station_date, callsign, is_bbs, conn_in, conn_out,
         msg_rx_p, msg_rx_b, msg_rx_t, msg_tx_p, msg_tx_b, msg_tx_t,
         bytes_rx_p, bytes_rx_b, bytes_rx_t, bytes_tx_p, bytes_tx_b, bytes_tx_t,
         rej_in_p, rej_in_b, rej_in_t, rej_out_p, rej_out_b, rej_out_t,
         source_file)
    VALUES
        (%(station_date)s, %(callsign)s, %(is_bbs)s, %(conn_in)s, %(conn_out)s,
         %(msg_rx_p)s, %(msg_rx_b)s, %(msg_rx_t)s, %(msg_tx_p)s, %(msg_tx_b)s, %(msg_tx_t)s,
         %(bytes_rx_p)s, %(bytes_rx_b)s, %(bytes_rx_t)s, %(bytes_tx_p)s, %(bytes_tx_b)s, %(bytes_tx_t)s,
         %(rej_in_p)s, %(rej_in_b)s, %(rej_in_t)s, %(rej_out_p)s, %(rej_out_b)s, %(rej_out_t)s,
         %(source_file)s)
    ON DUPLICATE KEY UPDATE
        is_bbs      = VALUES(is_bbs),
        conn_in     = VALUES(conn_in),     conn_out    = VALUES(conn_out),
        msg_rx_p    = VALUES(msg_rx_p),    msg_rx_b    = VALUES(msg_rx_b),    msg_rx_t    = VALUES(msg_rx_t),
        msg_tx_p    = VALUES(msg_tx_p),    msg_tx_b    = VALUES(msg_tx_b),    msg_tx_t    = VALUES(msg_tx_t),
        bytes_rx_p  = VALUES(bytes_rx_p),  bytes_rx_b  = VALUES(bytes_rx_b),  bytes_rx_t  = VALUES(bytes_rx_t),
        bytes_tx_p  = VALUES(bytes_tx_p),  bytes_tx_b  = VALUES(bytes_tx_b),  bytes_tx_t  = VALUES(bytes_tx_t),
        rej_in_p    = VALUES(rej_in_p),    rej_in_b    = VALUES(rej_in_b),    rej_in_t    = VALUES(rej_in_t),
        rej_out_p   = VALUES(rej_out_p),   rej_out_b   = VALUES(rej_out_b),   rej_out_t   = VALUES(rej_out_t),
        source_file = VALUES(source_file)
"""
# Full-replace on every column (not a merge/COALESCE like
# upsert_session() in parse-vara-log.py) — each row in the source file IS
# the complete cumulative total for that station on that day as of parse
# time, so the freshest parse should always win outright, including
# re-parsing today's file as it grows throughout the day.


def upsert_traffic_row(conn, station_date, row, source_file, dry_run=False):
    params = dict(row)
    params['station_date'] = station_date
    params['source_file'] = source_file
    params['is_bbs'] = 1 if row['is_bbs'] else 0
    if not dry_run:
        with conn.cursor() as cur:
            cur.execute(UPSERT_SQL, params)


def save_parser_state(conn, files_processed, rows_written, dry_run=False):
    if dry_run:
        return
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO traffic_parser_state (id, files_processed, files_total, rows_total) "
            "VALUES (1, %s, %s, %s) "
            "ON DUPLICATE KEY UPDATE "
            "files_processed = VALUES(files_processed), "
            "files_total = files_total + VALUES(files_processed), "
            "rows_total = rows_total + VALUES(rows_total)",
            (files_processed, files_processed, rows_written)
        )


# ─── Orchestration ───────────────────────────────────────────────────────────

def filename_for_date(date):
    return f"Traffic_{date.strftime('%y%m%d')}.txt"


def dates_for_window(days):
    """Today back through `days`-1 days ago, UTC — e.g. days=2 gives
    [today, yesterday], which is the default cron-friendly window (covers
    midnight UTC rollover and a short gap since the last run)."""
    today = dt.datetime.now(dt.timezone.utc).date()
    return [today - dt.timedelta(days=i) for i in range(days)]


def discover_backfill_dates(logs_dir):
    """Every Traffic_YYMMDD.txt file actually present in logs_dir,
    oldest first."""
    found = []
    for path in glob.glob(os.path.join(logs_dir, 'Traffic_??????.txt')):
        m = FILENAME_RE.match(os.path.basename(path))
        if not m:
            continue
        yy, mm, dd = (int(g) for g in m.groups())
        found.append(dt.date(2000 + yy, mm, dd))
    return sorted(found)


def main():
    ap = argparse.ArgumentParser(
        description='Parse BPQ Traffic_YYMMDD.txt reports into traffic_stats',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )
    ap.add_argument('--logs-dir', default=DEFAULT_LOGS_DIR,
                    help=f'Directory containing Traffic_YYMMDD.txt files (default: {DEFAULT_LOGS_DIR})')
    ap.add_argument('--days', type=int, default=2,
                    help='Re-parse the trailing N days, today inclusive (default: 2)')
    ap.add_argument('--date',
                    help='Parse one specific date only, as YYMMDD (overrides --days)')
    ap.add_argument('--backfill', action='store_true',
                    help='Parse every Traffic_*.txt file found in --logs-dir, ignoring --days/--date')
    ap.add_argument('--dry-run', action='store_true',
                    help="Show what would be written, don't actually INSERT")
    ap.add_argument('--verbose', '-v', action='store_true',
                    help='Print each file as processed')
    ap.add_argument('--db-host')
    ap.add_argument('--db-port', type=int)
    ap.add_argument('--db-name')
    ap.add_argument('--db-user')
    ap.add_argument('--db-pass')
    args = ap.parse_args()

    for key, val in [('host', args.db_host), ('port', args.db_port),
                     ('database', args.db_name), ('user', args.db_user),
                     ('password', args.db_pass)]:
        if val is not None:
            DB_CONFIG[key] = val

    if not os.path.isdir(args.logs_dir):
        sys.stderr.write(f"ERROR: logs directory not found: {args.logs_dir}\n")
        sys.exit(1)

    if args.backfill:
        dates = discover_backfill_dates(args.logs_dir)
        if not dates:
            sys.stderr.write(f"No Traffic_*.txt files found in {args.logs_dir}\n")
    elif args.date:
        try:
            dates = [dt.datetime.strptime(args.date, '%y%m%d').date()]
        except ValueError:
            sys.stderr.write(f"ERROR: --date must be YYMMDD, got: {args.date}\n")
            sys.exit(1)
    else:
        dates = dates_for_window(args.days)

    conn = db_connect()
    files_processed = 0
    rows_written = 0

    try:
        for date in dates:
            filename = filename_for_date(date)
            path = os.path.join(args.logs_dir, filename)
            if not os.path.isfile(path):
                if args.verbose:
                    sys.stdout.write(f"  {filename}: not found, skipping\n")
                continue

            with open(path, 'r', errors='replace') as f:
                text = f.read()

            rows = parse_traffic_file(text)
            for row in rows:
                upsert_traffic_row(conn, date, row, filename, dry_run=args.dry_run)

            files_processed += 1
            rows_written += len(rows)
            if args.verbose:
                sys.stdout.write(f"  {filename}: {len(rows)} station rows\n")

        if not args.dry_run:
            save_parser_state(conn, files_processed, rows_written, dry_run=args.dry_run)
            conn.commit()

        sys.stdout.write(
            f"Processed {files_processed} file(s), wrote {rows_written} station-day row(s)\n"
        )
        if args.dry_run:
            sys.stdout.write("  [DRY RUN — no DB writes performed]\n")
    except pymysql.MySQLError as e:
        conn.rollback()
        sys.stderr.write(f"ERROR: database error, rolled back: {e}\n")
        sys.exit(2)
    finally:
        conn.close()


if __name__ == '__main__':
    main()
