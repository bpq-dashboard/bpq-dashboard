#!/usr/bin/env python3
"""
parse-system-logs.py — Parses BPQ's system/RMS/TCP logs plus three live-
snapshot files into the DB tables that back bpq-system-logs.html's stats
cards, charts, error breakdown, and top-stations panel.

Six independent sub-parsers, all run together each invocation unless
narrowed with --only:

  system    log_YYMMDD_BBS.txt      -> sys_events, sys_station_daily,
                                        sys_internet_stations
  rms       CMSAccess_YYYYMMDD.log  -> rms_connections, rms_login_failures
  tcp       log_YYMMDD_TCP.txt      -> tcp_events
  nodesrtt  nodesrtt.txt (snapshot) -> net_nodes_rtt
  mheard    MHSave.txt (snapshot)   -> net_mheard
  rtknown   RTKnown.txt (snapshot)  -> net_rtknown

SCOPE: this backs the page's STATS/CHARTS ONLY. The raw log viewer and
its search/filter UI keep reading the log files directly, unchanged —
these tables store one row per *classified event* (a connection, a
message, an error, ...), not one row per raw line. The sole exception is
sys_events.raw_line, populated only for event_type='error', so the
Recent Errors list and "find in viewer" keep working without needing
every line stored.

The three daily-rotated log sub-parsers (system/rms/tcp) are "since
window" re-scans of whichever files fall in the window — the same
approach build_forwarded_messages()/build_freq_lookup() already use
against this same BBS-log family in parse-vara-log.py — not byte-offset
tracked. Default window is the trailing 2 days (covers UTC midnight
rollover + any gap since the last cron run); --backfill re-scans
everything found. Every insert is idempotent (UNIQUE KEY per
source_file+log_line_no[+event_type]), so re-scanning the same window
repeatedly is always safe and is how `medium`-style backfills would work
here too if a future column needs it.

The three snapshot sub-parsers (nodesrtt/mheard/rtknown) replace their
whole table every run (DELETE + INSERT — see run_snapshot()'s comment
for why not TRUNCATE) — BPQ itself rewrites those
files with current state only, so there's no history to preserve.

Usage:
  parse-system-logs.py                     # cron mode, trailing 2 days, all 6 sources
  parse-system-logs.py --days 7            # trailing week
  parse-system-logs.py --date 260920       # one specific date (system/rms/tcp only)
  parse-system-logs.py --backfill          # full history found in --logs-dir
  parse-system-logs.py --only system,rms   # skip tcp/nodesrtt/mheard/rtknown
  parse-system-logs.py --dry-run --verbose

Configuration:
  Same BPQ_DB_* env vars / --db-* flags as parse-vara-log.py /
  parse-traffic-report.py — same DB, same tprfn_app credentials.

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
from pathlib import Path

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
ALL_SOURCES = ['system', 'rms', 'tcp', 'nodesrtt', 'mheard', 'rtknown']
DAILY_SOURCES = ['system', 'rms', 'tcp']  # the "since window" re-scanned ones

# DB credentials: see scripts/bpq_db_config.py (2026-09-26). Nothing
# secret is hard-coded here any more — it comes from --db-* flags, BPQ_DB_*
# env vars, db-config.php, or config.php's db section, in that order.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402
DB_CONFIG = bpq_db_config.load_db_config({
    'charset': 'utf8mb4',
    'autocommit': False,
})


def base_call(callsign):
    """Strip SSID suffix (-N) to get the base callsign."""
    return re.sub(r'-\d+$', '', callsign)


# ─── Shared timestamp parsing ───────────────────────────────────────────────
#
# log_YYMMDD_BBS.txt and log_YYMMDD_TCP.txt both use BPQ's standard
# "YYMMDD HH:MM:SS <rest>" line prefix (same format parse-vara-log.py's
# RE_BBS_TIMESTAMP matches against these same files). CMSAccess logs omit
# the date (it's in the filename instead, like ConnectLog_YYMMDD.log) and
# use a slightly different prefix shape — "HH:MM:SS NN <rest>".

RE_DATED_TS = re.compile(r'^(\d{2})(\d{2})(\d{2})\s+(\d{2}):(\d{2}):(\d{2})\s')
RE_UNDATED_TS = re.compile(r'^(\d{2}):(\d{2}):(\d{2})\s')


def parse_dated_ts(line):
    """'YYMMDD HH:MM:SS ...' -> datetime, or None."""
    m = RE_DATED_TS.match(line)
    if not m:
        return None
    yy, mo, dd, hh, mi, ss = (int(g) for g in m.groups())
    try:
        return dt.datetime(2000 + yy, mo, dd, hh, mi, ss)
    except ValueError:
        return None


# ─── System log (log_YYMMDD_BBS.txt) ────────────────────────────────────────
#
# Ported line-for-line from bpq-system-logs.html's parseLogStats() (see
# that function for the original). One deliberate omission: the original
# JS's message-direction cascade opens with an `if (line includes
# "routing trace to YOURCALL")` check that compares an already-lowercased
# line against the literal uppercase substring "YOURCALL" — this can
# never match (a lowercased string can't contain an uppercase substring),
# so it's dead code in the original, left out here since including a
# no-op branch would just be noise. The two branches that DO fire
# (forwarding-keyword, received-keyword) are ported as-is.

FILENAME_RE_SYSTEM = re.compile(r'^log_(\d{6})_BBS$')

RE_MSG = re.compile(r'Msg\s+(\d+)', re.I)
RE_REJECTED = re.compile(r'rejected\s+by\s+bid', re.I)
RE_CALLSIGN_TOUCH = re.compile(r'[|<>?]([A-Z0-9-]+)\s')
RE_INTERNET_INCOMING = re.compile(
    r'^(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+\|([A-Z0-9-]+)\s+'
    r'Incoming Connect from ([A-Z0-9-]+)', re.I)

RE_VARA_BITRATE_PATTERNS = [
    re.compile(r'VARA[})]\s+BITRATE\s+\(\d+\)\s+(\d{1,5})\s+bps', re.I),
    re.compile(r'VARA[})]\s+BITRATE\s+\(\d+\)\s+(\d{1,5})', re.I),
    re.compile(r'(\d{1,5})\s+bps', re.I),
    re.compile(r'BITRATE\s+\(\d+\)\s+(\d{1,5})', re.I),
]


def classify_error(line):
    """Port of the original's error-category cascade. Returns a string."""
    if re.search(r'port\s+in\s+use', line, re.I):
        return 'rf_failure'
    if 'disappeared' in line.lower():
        return 'node_disappeared'
    if re.search(r'protocol\s+error|invalid\s+binary|invalid\s+block', line, re.I):
        return 'protocol_error'
    if re.search(r'timeout|timed\s+out', line, re.I):
        return 'timeout'
    if re.search(r'connect\s+failed|connection\s+failed|failed\s+to\s+connect|'
                 r'no\s+response|busy', line, re.I):
        return 'connect_failure'
    return 'other'


def extract_vara_bitrate(line):
    """Returns int bps if this is a valid VARA BITRATE line, else None."""
    if 'VARA}' not in line and 'VARA)' not in line:
        return None
    if 'BITRATE' not in line.upper():
        return None
    for pattern in RE_VARA_BITRATE_PATTERNS:
        m = pattern.search(line)
        if m:
            bps = int(m.group(1))
            if 18 <= bps <= 100000:
                return bps
            return None  # matched but out of plausible range — don't try weaker patterns
    return None


def parse_system_log_file(path, line_no_offset=0):
    """Parse one log_YYMMDD_BBS.txt file.

    Returns (events, station_days, internet_stations):
      events           — list of dicts for sys_events
      station_days     — dict {callsign: {events, connections, messages_tx, messages_rx}}
                          for THIS FILE (one calendar day — caller merges
                          this into the day's sys_station_daily row)
      internet_stations — dict {base_callsign: {last_connect, count}} for THIS FILE
    """
    events = []
    station_days = {}
    internet_stations = {}

    with open(path, 'r', encoding='utf-8', errors='replace') as f:
        for line_no, line in enumerate(f, start=1):
            line = line.rstrip('\r\n')
            if not line:
                continue

            ts = parse_dated_ts(line)
            if ts is None:
                continue  # not a real log line (e.g. a maintenance-mode
                          # HTML page served at this path) — the anchored
                          # timestamp regex naturally rejects anything
                          # that isn't real BPQ log output, no separate
                          # file-level validation needed

            callsign = None
            cm = RE_CALLSIGN_TOUCH.search(line)
            if cm:
                callsign = cm.group(1).upper()

            # -- connection --
            if '*** CONNECTED' in line:
                events.append({'event_time': ts, 'event_type': 'connection',
                               'callsign': callsign, 'log_line_no': line_no})

            # -- message (forwarded/received/unclassified) --
            if RE_MSG.search(line):
                direction = 'unknown'
                if '>' in line:
                    direction = 'out'
                elif '<' in line:
                    direction = 'in'
                else:
                    ll = line.lower()
                    if (ll.find('forwarding') >= 0 or ll.find('forward to') >= 0 or
                            ll.find('routing to') >= 0 or ll.find('route to') >= 0 or
                            ll.find('trace to') >= 0 or ll.find('sending to') >= 0 or
                            ll.find('sent to') >= 0 or
                            (' to ' in ll and ('via' in ll or 'routing' in ll))):
                        direction = 'out'
                    elif (ll.find('received from') >= 0 or
                            ('from ' in ll and 'msg' in ll) or
                            ll.find('arrived from') >= 0 or
                            ll.find('incoming from') >= 0):
                        direction = 'in'
                events.append({'event_time': ts, 'event_type': 'message',
                               'direction': direction, 'callsign': callsign,
                               'log_line_no': line_no})

            # -- rejected message --
            if RE_REJECTED.search(line):
                events.append({'event_time': ts, 'event_type': 'message_rejected',
                               'callsign': callsign, 'log_line_no': line_no})

            # -- error (and category) --
            # Trigger substrings match the original exactly: Error/error/
            # ERROR/Timeout/timeout/TIMEOUT/Failed/failed/FAILED. Note
            # this means a line containing only "timed out" (no literal
            # "timeout" substring) does NOT trigger here, even though
            # classify_error()'s timeout branch checks for "timed out" —
            # that branch is only reachable via one of the trigger words
            # above already being present. Inherited quirk from the
            # original JS, kept for parity rather than "fixed", since a
            # widened trigger would produce error counts that don't match
            # the raw-log-viewer's own highlighting of what counts as an
            # error line.
            if re.search(r'error|timeout|failed', line, re.I):
                events.append({'event_time': ts, 'event_type': 'error',
                               'error_category': classify_error(line),
                               'callsign': callsign, 'raw_line': line,
                               'log_line_no': line_no})

            # -- VARA bitrate --
            bps = extract_vara_bitrate(line)
            if bps is not None:
                events.append({'event_time': ts, 'event_type': 'vara_bitrate',
                               'bitrate_bps': bps, 'callsign': callsign,
                               'log_line_no': line_no})

            # -- per-station daily rollup (any line with a leading-symbol callsign) --
            if callsign:
                d = station_days.setdefault(
                    callsign, {'events': 0, 'connections': 0,
                               'messages_tx': 0, 'messages_rx': 0})
                d['events'] += 1
                if '*** CONNECTED' in line:
                    d['connections'] += 1
                if 'Msg ' in line:
                    if '>' in line:
                        d['messages_tx'] += 1
                    else:  # '<', '?', or unmarked all default to Rx, same as original
                        d['messages_rx'] += 1

            # -- internet (non-RF) connected stations --
            im = RE_INTERNET_INCOMING.match(line)
            if im and 'Freq' not in line:
                from_call = base_call(im.group(6).upper())
                s = internet_stations.setdefault(
                    from_call, {'last_connect': ts, 'count': 0})
                s['count'] += 1
                if ts > s['last_connect']:
                    s['last_connect'] = ts

    return events, station_days, internet_stations


# ─── RMS / CMSAccess log (CMSAccess_YYYYMMDD.log) ──────────────────────────
#
# Sessions are paired Connect->Disconnect by simple sequential proximity
# (last-opened-not-yet-closed per file) — an improvement over the
# original JS, which summed Disconnect byte counts globally with no
# per-station attribution at all, because the Disconnect line itself
# doesn't carry a callsign. This assumes a single RMS/CMS session active
# at a time, which matches how BPQ's CMS gateway actually operates.

FILENAME_RE_RMS = re.compile(r'^CMSAccess_(\d{4})(\d{2})(\d{2})$')

RE_RMS_CONNECT = re.compile(
    r'^\d{2}:\d{2}:\d{2}\s+\d+\s+([A-Z0-9-]+)\s+Connected to CMS', re.I)
RE_RMS_DISCONNECT = re.compile(
    r'Disconnected\.\s+Bytes Sent = (\d+)\s+Bytes Received (\d+)'
    r'(?:\s+Time\s+(\d+)\s+Seconds)?', re.I)


def parse_rms_log_file(path, file_date):
    """Parse one CMSAccess_YYYYMMDD.log file.

    Returns (connections, login_failures) — lists of dicts.
    """
    connections = []
    login_failures = []
    open_session = None  # {'callsign', 'connect_time', 'log_line_no'}

    with open(path, 'r', encoding='utf-8', errors='replace') as f:
        for line_no, line in enumerate(f, start=1):
            line = line.rstrip('\r\n')
            if not line:
                continue

            tm = RE_UNDATED_TS.match(line)
            if not tm:
                continue  # not a real log line — same anchored-regex safety net as above
            hh, mi, ss = (int(g) for g in tm.groups())
            try:
                ts = dt.datetime(file_date.year, file_date.month, file_date.day,
                                 hh, mi, ss)
            except ValueError:
                continue

            cm = RE_RMS_CONNECT.match(line)
            if cm:
                if open_session:
                    # Previous session never saw a Disconnect line — flush
                    # it with nulls rather than silently dropping it.
                    connections.append({**open_session, 'disconnect_time': None,
                                        'bytes_sent': None, 'bytes_received': None,
                                        'duration_sec': None})
                open_session = {
                    'callsign': base_call(cm.group(1).upper()),
                    'connect_time': ts,
                    'log_line_no': line_no,
                }
                continue

            dm = RE_RMS_DISCONNECT.search(line)
            if dm:
                bytes_sent = int(dm.group(1))
                bytes_recv = int(dm.group(2))
                dur = int(dm.group(3)) if dm.group(3) else None
                if open_session:
                    connections.append({**open_session, 'disconnect_time': ts,
                                        'bytes_sent': bytes_sent,
                                        'bytes_received': bytes_recv,
                                        'duration_sec': dur})
                    open_session = None
                else:
                    # Disconnect with no matching Connect this file (session
                    # opened before this file's window) — still record the
                    # byte counts, just without a paired connect_time.
                    connections.append({
                        'callsign': None, 'connect_time': ts,
                        'disconnect_time': ts, 'bytes_sent': bytes_sent,
                        'bytes_received': bytes_recv, 'duration_sec': dur,
                        'log_line_no': line_no,
                    })
                continue

            if 'Login failed' in line:
                login_failures.append({'event_time': ts, 'raw_line': line,
                                       'log_line_no': line_no})

    if open_session:
        connections.append({**open_session, 'disconnect_time': None,
                            'bytes_sent': None, 'bytes_received': None,
                            'duration_sec': None})

    # Rows with no callsign (orphan Disconnects) aren't useful without one —
    # matches the original's behavior of not being able to attribute these.
    connections = [c for c in connections if c['callsign']]

    return connections, login_failures


# ─── TCP log (log_YYMMDD_TCP.txt) ──────────────────────────────────────────

FILENAME_RE_TCP = re.compile(r'^log_(\d{6})_TCP$')


def parse_tcp_log_file(path):
    """Parse one log_YYMMDD_TCP.txt file. Returns a list of event dicts."""
    events = []
    with open(path, 'r', encoding='utf-8', errors='replace') as f:
        for line_no, line in enumerate(f, start=1):
            line = line.rstrip('\r\n')
            if not line:
                continue
            ts = parse_dated_ts(line)
            if ts is None:
                continue

            if 'Incoming POP3 Connect' in line:
                events.append({'event_time': ts, 'event_type': 'pop3', 'log_line_no': line_no})
            if 'Incoming SMTP Connect' in line:
                events.append({'event_time': ts, 'event_type': 'smtp', 'log_line_no': line_no})
            if 'Incoming NNTP Connect' in line:
                events.append({'event_time': ts, 'event_type': 'nntp', 'log_line_no': line_no})
            if '530 Authentication required' in line or 'Authentication failed' in line:
                events.append({'event_time': ts, 'event_type': 'auth_fail', 'log_line_no': line_no})
    return events


# ─── Live snapshots: nodesrtt.txt / MHSave.txt / RTKnown.txt ──────────────

RE_NODESRTT = re.compile(r'^([A-Z0-9:/-]+)\s+RTT=([0-9.]+)\s+Frames\s*=\s*(\d+)', re.I)

RE_MHEARD_PORT = re.compile(r'^Port:(\d+)')
RE_MHEARD_ENTRY = re.compile(
    r'^(\d+)\s+(\d+)\s+(\S+)\s+([+!])?\s*(\w{3})\s+(\d{1,2})\s+(\d{1,2}:\d{2}:\d{2})'
    r'\s+\|([^|]*)\|(.*)$')
MONTHS = {'Jan': 1, 'Feb': 2, 'Mar': 3, 'Apr': 4, 'May': 5, 'Jun': 6,
          'Jul': 7, 'Aug': 8, 'Sep': 9, 'Oct': 10, 'Nov': 11, 'Dec': 12}

RE_RTKNOWN = re.compile(r'^(\S+)\s+(\d+)$')


def parse_nodesrtt_file(path):
    """Returns a list of dicts for net_nodes_rtt."""
    rows = []
    with open(path, 'r', encoding='utf-8', errors='replace') as f:
        for line in f:
            m = RE_NODESRTT.match(line.strip())
            if not m:
                continue
            node_call, rtt, frames = m.groups()
            if ':' in node_call:
                node_call = node_call.split(':', 1)[1]
            rows.append({
                'callsign': base_call(node_call.upper()),
                'full_call': node_call.upper(),
                'rtt_sec': float(rtt),
                'frames': int(frames),
            })
    return rows


def parse_mheard_file(path):
    """Returns a list of dicts for net_mheard."""
    rows = []
    current_port = 0
    now = dt.datetime.utcnow()
    with open(path, 'r', encoding='utf-8', errors='replace') as f:
        for line in f:
            line = line.strip()
            pm = RE_MHEARD_PORT.match(line)
            if pm:
                current_port = int(pm.group(1))
                continue
            if not line or current_port < 1 or current_port > 5:
                continue
            m = RE_MHEARD_ENTRY.match(line)
            if not m:
                continue
            (_ts, byte_str, callsign, status, mon, day, time_str,
             grid, freq) = m.groups()
            month_num = MONTHS.get(mon, 1)
            hh, mi, ss = (int(x) for x in time_str.split(':'))
            try:
                heard_at = dt.datetime(now.year, month_num, int(day), hh, mi, ss)
                if heard_at > now:
                    heard_at = dt.datetime(now.year - 1, month_num, int(day), hh, mi, ss)
            except ValueError:
                continue
            rows.append({
                'port': current_port,
                'callsign': callsign.strip().upper(),
                'heard_at': heard_at,
                'bytes': int(byte_str),
                'status': 'connected' if status == '+' else ('failed' if status == '!' else 'none'),
                'grid': grid.strip() or None,
                'freq_mhz': float(freq) if freq.strip() else None,
            })
    return rows


def parse_rtknown_file(path):
    """Returns a list of dicts for net_rtknown."""
    rows = []
    with open(path, 'r', encoding='utf-8', errors='replace') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            m = RE_RTKNOWN.match(line)
            if not m:
                continue
            callsign, timestamp = m.groups()
            if len(callsign) < 2:
                continue
            rows.append({
                'callsign': callsign.upper(),
                'last_heard': dt.datetime.utcfromtimestamp(int(timestamp)),
            })
    return rows


# ─── Database ────────────────────────────────────────────────────────────────

def db_connect():
    try:
        bpq_db_config.warn_if_no_password(DB_CONFIG)
        return pymysql.connect(**DB_CONFIG)
    except pymysql.MySQLError as e:
        sys.stderr.write(f"ERROR: Database connection failed: {e}\n")
        sys.stderr.write("Check credentials in $BPQ_DB_* env vars or use --db-* flags.\n")
        sys.exit(2)


def save_parser_state(conn, source, files_processed, rows_written, dry_run=False):
    if dry_run:
        return
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO sys_logs_parser_state (source, files_processed, files_total, rows_total) "
            "VALUES (%s, %s, %s, %s) "
            "ON DUPLICATE KEY UPDATE "
            "files_processed = VALUES(files_processed), "
            "files_total = files_total + VALUES(files_processed), "
            "rows_total = rows_total + VALUES(rows_total)",
            (source, files_processed, files_processed, rows_written)
        )


# ─── Orchestration helpers ──────────────────────────────────────────────────

def dates_for_window(days):
    today = dt.datetime.now(dt.timezone.utc).date()
    return [today - dt.timedelta(days=i) for i in range(days)]


def discover_dates(logs_dir, pattern, group_to_date):
    found = []
    for path in glob.glob(os.path.join(logs_dir, pattern)):
        m = re.match(group_to_date[0], os.path.basename(Path(path).stem))
        if not m:
            continue
        found.append(group_to_date[1](m))
    return sorted(set(found))


# ─── Sub-parser runners ─────────────────────────────────────────────────────

def run_system(conn, logs_dir, dates, verbose, dry_run):
    files_processed, rows_written = 0, 0
    for date in dates:
        filename = f"log_{date.strftime('%y%m%d')}_BBS.txt"
        path = os.path.join(logs_dir, filename)
        if not os.path.isfile(path):
            if verbose:
                sys.stdout.write(f"  [system] {filename}: not found, skipping\n")
            continue

        events, station_days, internet_stations = parse_system_log_file(path)

        if not dry_run:
            with conn.cursor() as cur:
                for e in events:
                    cur.execute(
                        "INSERT IGNORE INTO sys_events "
                        "(event_time, event_type, direction, error_category, "
                        " callsign, bitrate_bps, raw_line, source_file, log_line_no) "
                        "VALUES (%(event_time)s, %(event_type)s, %(direction)s, "
                        "%(error_category)s, %(callsign)s, %(bitrate_bps)s, "
                        "%(raw_line)s, %(source_file)s, %(log_line_no)s)",
                        {**{'direction': None, 'error_category': None,
                            'callsign': None, 'bitrate_bps': None, 'raw_line': None},
                         **e, 'source_file': filename}
                    )
                for callsign, d in station_days.items():
                    cur.execute(
                        "INSERT INTO sys_station_daily "
                        "(activity_date, callsign, events, connections, messages_tx, messages_rx) "
                        "VALUES (%s, %s, %s, %s, %s, %s) "
                        "ON DUPLICATE KEY UPDATE "
                        "events = VALUES(events), connections = VALUES(connections), "
                        "messages_tx = VALUES(messages_tx), messages_rx = VALUES(messages_rx)",
                        (date, callsign, d['events'], d['connections'],
                         d['messages_tx'], d['messages_rx'])
                    )
                for callsign, s in internet_stations.items():
                    cur.execute(
                        "INSERT INTO sys_internet_stations (callsign, last_connect, connect_count) "
                        "VALUES (%s, %s, %s) "
                        "ON DUPLICATE KEY UPDATE "
                        "last_connect = GREATEST(last_connect, VALUES(last_connect)), "
                        "connect_count = connect_count + VALUES(connect_count)",
                        (callsign, s['last_connect'], s['count'])
                    )

        files_processed += 1
        rows_written += len(events)
        if verbose:
            sys.stdout.write(f"  [system] {filename}: {len(events)} events, "
                             f"{len(station_days)} stations touched\n")

    if not dry_run:
        save_parser_state(conn, 'system', files_processed, rows_written, dry_run)
    return files_processed, rows_written


def run_rms(conn, logs_dir, dates, verbose, dry_run):
    files_processed, rows_written = 0, 0
    for date in dates:
        filename = f"CMSAccess_{date.strftime('%Y%m%d')}.log"
        path = os.path.join(logs_dir, filename)
        if not os.path.isfile(path):
            if verbose:
                sys.stdout.write(f"  [rms] {filename}: not found, skipping\n")
            continue

        connections, login_failures = parse_rms_log_file(path, date)

        if not dry_run:
            with conn.cursor() as cur:
                for c in connections:
                    cur.execute(
                        "INSERT IGNORE INTO rms_connections "
                        "(callsign, connect_time, disconnect_time, bytes_sent, "
                        " bytes_received, duration_sec, source_file, log_line_no) "
                        "VALUES (%(callsign)s, %(connect_time)s, %(disconnect_time)s, "
                        "%(bytes_sent)s, %(bytes_received)s, %(duration_sec)s, "
                        "%(source_file)s, %(log_line_no)s)",
                        {**c, 'source_file': filename}
                    )
                for lf in login_failures:
                    cur.execute(
                        "INSERT IGNORE INTO rms_login_failures "
                        "(event_time, raw_line, source_file, log_line_no) "
                        "VALUES (%(event_time)s, %(raw_line)s, %(source_file)s, %(log_line_no)s)",
                        {**lf, 'source_file': filename}
                    )

        files_processed += 1
        rows_written += len(connections) + len(login_failures)
        if verbose:
            sys.stdout.write(f"  [rms] {filename}: {len(connections)} sessions, "
                             f"{len(login_failures)} login failures\n")

    if not dry_run:
        save_parser_state(conn, 'rms', files_processed, rows_written, dry_run)
    return files_processed, rows_written


def run_tcp(conn, logs_dir, dates, verbose, dry_run):
    files_processed, rows_written = 0, 0
    for date in dates:
        filename = f"log_{date.strftime('%y%m%d')}_TCP.txt"
        path = os.path.join(logs_dir, filename)
        if not os.path.isfile(path):
            if verbose:
                sys.stdout.write(f"  [tcp] {filename}: not found, skipping\n")
            continue

        events = parse_tcp_log_file(path)

        if not dry_run:
            with conn.cursor() as cur:
                for e in events:
                    cur.execute(
                        "INSERT IGNORE INTO tcp_events (event_time, event_type, source_file, log_line_no) "
                        "VALUES (%(event_time)s, %(event_type)s, %(source_file)s, %(log_line_no)s)",
                        {**e, 'source_file': filename}
                    )

        files_processed += 1
        rows_written += len(events)
        if verbose:
            sys.stdout.write(f"  [tcp] {filename}: {len(events)} events\n")

    if not dry_run:
        save_parser_state(conn, 'tcp', files_processed, rows_written, dry_run)
    return files_processed, rows_written


def run_snapshot(conn, logs_dir, source, filename, parse_fn, table, columns,
                 verbose, dry_run):
    """Shared runner for the three snapshot sources — DELETE + INSERT
    (not TRUNCATE — see the comment below)."""
    path = os.path.join(logs_dir, filename)
    if not os.path.isfile(path):
        if verbose:
            sys.stdout.write(f"  [{source}] {filename}: not found, skipping\n")
        return 0, 0

    rows = parse_fn(path)
    now = dt.datetime.utcnow()
    for r in rows:
        r['polled_at'] = now

    if not dry_run:
        with conn.cursor() as cur:
            # DELETE rather than TRUNCATE: TRUNCATE is DDL and requires
            # the DROP privilege (which tprfn_app correctly doesn't have —
            # an app DB user shouldn't be able to drop tables), and it
            # also causes an implicit commit in MySQL/InnoDB even with
            # autocommit off, meaning a failure partway through the INSERT
            # loop below couldn't be rolled back past that point. DELETE
            # needs only the DELETE privilege the app user already has,
            # and participates properly in the surrounding transaction —
            # a failure below now rolls this back too, leaving the
            # previous snapshot intact instead of an empty table. These
            # tables are small (dozens to low hundreds of rows), so
            # DELETE's usual downside (slower than TRUNCATE on huge
            # tables) doesn't apply here.
            cur.execute(f"DELETE FROM {table}")
            col_list = ', '.join(columns)
            placeholders = ', '.join(f'%({c})s' for c in columns)
            for r in rows:
                cur.execute(
                    f"INSERT IGNORE INTO {table} ({col_list}) VALUES ({placeholders})", r
                )
        save_parser_state(conn, source, 1, len(rows), dry_run)

    if verbose:
        sys.stdout.write(f"  [{source}] {filename}: {len(rows)} rows (snapshot replaced)\n")
    return 1, len(rows)


# ─── Main ───────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser(
        description='Parse BPQ system/RMS/TCP logs + live snapshots into the system-logs tables',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )
    ap.add_argument('--logs-dir', default=DEFAULT_LOGS_DIR,
                    help=f'Directory containing all six log/snapshot sources (default: {DEFAULT_LOGS_DIR})')
    ap.add_argument('--days', type=int, default=2,
                    help='Re-parse the trailing N days for system/rms/tcp, today inclusive (default: 2)')
    ap.add_argument('--date',
                    help='Parse one specific date only, as YYMMDD, for system/rms/tcp (overrides --days)')
    ap.add_argument('--backfill', action='store_true',
                    help='Re-parse every system/rms/tcp file found in --logs-dir, ignoring --days/--date')
    ap.add_argument('--only',
                    help='Comma-separated subset of sources to run: ' + ','.join(ALL_SOURCES))
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

    sources = ALL_SOURCES
    if args.only:
        sources = [s.strip() for s in args.only.split(',') if s.strip()]
        unknown = set(sources) - set(ALL_SOURCES)
        if unknown:
            sys.stderr.write(f"ERROR: unknown source(s) in --only: {', '.join(unknown)}\n")
            sys.exit(1)

    if args.backfill:
        # Discover from whichever daily source has the most history —
        # each sub-parser only opens the files that actually exist for
        # its own filename pattern, so an overly-wide date range here
        # just means some no-op "not found, skipping" lines.
        all_found = set()
        all_found |= set(discover_dates(args.logs_dir, 'log_??????_BBS.txt',
                         (FILENAME_RE_SYSTEM, lambda m: dt.date(2000 + int(m.group(1)[0:2]),
                                                                 int(m.group(1)[2:4]), int(m.group(1)[4:6])))))
        all_found |= set(discover_dates(args.logs_dir, 'CMSAccess_????????.log',
                         (FILENAME_RE_RMS, lambda m: dt.date(int(m.group(1)), int(m.group(2)), int(m.group(3))))))
        all_found |= set(discover_dates(args.logs_dir, 'log_??????_TCP.txt',
                         (FILENAME_RE_TCP, lambda m: dt.date(2000 + int(m.group(1)[0:2]),
                                                              int(m.group(1)[2:4]), int(m.group(1)[4:6])))))
        dates = sorted(all_found)
        if not dates:
            sys.stderr.write(f"No system/rms/tcp log files found in {args.logs_dir}\n")
    elif args.date:
        try:
            dates = [dt.datetime.strptime(args.date, '%y%m%d').date()]
        except ValueError:
            sys.stderr.write(f"ERROR: --date must be YYMMDD, got: {args.date}\n")
            sys.exit(1)
    else:
        dates = dates_for_window(args.days)

    conn = db_connect()
    totals = {}

    try:
        if 'system' in sources:
            totals['system'] = run_system(conn, args.logs_dir, dates, args.verbose, args.dry_run)
        if 'rms' in sources:
            totals['rms'] = run_rms(conn, args.logs_dir, dates, args.verbose, args.dry_run)
        if 'tcp' in sources:
            totals['tcp'] = run_tcp(conn, args.logs_dir, dates, args.verbose, args.dry_run)
        if 'nodesrtt' in sources:
            totals['nodesrtt'] = run_snapshot(
                conn, args.logs_dir, 'nodesrtt', 'nodesrtt.txt', parse_nodesrtt_file,
                'net_nodes_rtt', ['callsign', 'full_call', 'rtt_sec', 'frames', 'polled_at'],
                args.verbose, args.dry_run)
        if 'mheard' in sources:
            totals['mheard'] = run_snapshot(
                conn, args.logs_dir, 'mheard', 'MHSave.txt', parse_mheard_file,
                'net_mheard', ['port', 'callsign', 'heard_at', 'bytes', 'status',
                              'grid', 'freq_mhz', 'polled_at'],
                args.verbose, args.dry_run)
        if 'rtknown' in sources:
            totals['rtknown'] = run_snapshot(
                conn, args.logs_dir, 'rtknown', 'RTKnown.txt', parse_rtknown_file,
                'net_rtknown', ['callsign', 'last_heard', 'polled_at'],
                args.verbose, args.dry_run)

        if not args.dry_run:
            conn.commit()

        sys.stdout.write("Done:\n")
        for source, (files, rows) in totals.items():
            sys.stdout.write(f"  {source:10s} {files} file(s), {rows} row(s)\n")
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
