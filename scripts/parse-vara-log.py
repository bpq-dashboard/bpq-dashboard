#!/usr/bin/env python3
"""
parse-vara-log.py — Parses VARA HF log file into rf_connections table

Reads the appended-to VARA log (e.g. /var/www/<dashboard>/logs/<call>.vara),
extracts complete sessions, and writes one row per session to the
rf_connections MySQL/MariaDB table.

Designed for incremental operation: tracks last-read byte offset and line
number in rf_parser_state, so a cron job running every few minutes only
processes new lines.

Usage:
  # Initial backfill — clear state, re-read whole file from scratch
  parse-vara-log.py --reset

  # Incremental — read only new lines since last run (the normal mode)
  parse-vara-log.py

  # Dry-run — show what would be written, don't actually INSERT
  parse-vara-log.py --dry-run

  # Override config path or log file
  parse-vara-log.py --log /path/to/yourcall.vara
  parse-vara-log.py --db-host localhost --db-name tprfn --db-user tprfn_app

Configuration:
  Credentials default to the same as tprfn-db.php. Override via env vars:
    BPQ_DB_HOST, BPQ_DB_NAME, BPQ_DB_USER, BPQ_DB_PASS, BPQ_DB_PORT
  Or command-line flags above.

Exit codes:
  0  success (zero or more sessions parsed)
  1  config error (missing credentials, log file not found)
  2  database error (connection failed, schema missing)
  3  parser error (unexpected log format)
"""

import argparse
import bisect
import datetime as dt
import os
import re
import sys
from collections import defaultdict
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

# Default VARA log = config.php paths.logs + logs.vara_file (2026-09-26;
# was hard-coded to this station's file). None -> --log is required.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402
_vf = bpq_db_config.load_config_section('logs').get('vara_file')
DEFAULT_LOG_PATH = os.path.join(bpq_db_config.default_logs_dir(), _vf) if _vf else None

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


# ─── Log line parsing ───────────────────────────────────────────────────────
#
# VARA HF logs come in two date formats:
#   New: "Mar 25 17:44:39 H-K1AJD-7 VARAHF ..."  (Mon DD HH:MM:SS)
#   Old: "251226 00:06:02 <NP4JN ..."             (YYMMDD HH:MM:SS)
#
# Session structure (in order of appearance for one session):
#   1. "Connecting to <CALL>... <N>/<M>"     — outgoing attempt
#      OR
#      "VARAHF <CALL> connected VARA HF"     — incoming connection
#      OR
#      "Connected to <CALL> VARA HF"         — outgoing succeeded
#   2. "<CALL> Average S/N: <SNR> dB"        — at session end
#   3. "Disconnected TX: N Bytes (Max: N bps) RX: N Bytes (Max: N bps) Session Time: M:S"
#
# Failures interrupt this sequence:
#   "Incoming Connection failed"
#   "PORT session" / "Port in use" / "Session Attached"
#
# ────────────────────────────────────────────────────────────────────────────

MONTH_MAP = {
    'Jan': 1, 'Feb': 2, 'Mar': 3, 'Apr': 4, 'May': 5, 'Jun': 6,
    'Jul': 7, 'Aug': 8, 'Sep': 9, 'Oct': 10, 'Nov': 11, 'Dec': 12,
}

RE_TIMESTAMP_NEW = re.compile(r'^([A-Za-z]{3})\s+(\d{1,2})\s+(\d{2}):(\d{2}):(\d{2})')
RE_TIMESTAMP_OLD = re.compile(r'^(\d{2})(\d{2})(\d{2})\s+(\d{2}):(\d{2}):(\d{2})')

# Session events
RE_CONNECTING        = re.compile(r'Connecting to ([A-Z0-9-]+)\.\.\.\s+(\d+)/(\d+)', re.I)
RE_CONNECTED_OUT     = re.compile(r'Connected to ([A-Z0-9-]+)\s+VARA HF', re.I)
RE_INCOMING          = re.compile(r'VARAHF\s+([A-Z0-9-]+)\s+connected\s+VARA HF', re.I)
RE_SNR               = re.compile(r'([A-Z0-9-]+)\s+Average S/N:\s+([-\d.]+)\s+dB', re.I)
RE_DISCONNECTED      = re.compile(
    r'Disconnected\s+TX:\s+(\d+)\s+Bytes\s+\(Max:\s+(\d+)\s+bps\)\s+'
    r'RX:\s+(\d+)\s+Bytes\s+\(Max:\s+(\d+)\s+bps\)\s+'
    r'Session Time:\s+(\d+):(\d+)', re.I)
RE_INCOMING_FAILED   = re.compile(r'Incoming Connection failed', re.I)
RE_PORT_BUSY         = re.compile(r'PORT\s+session|Port\s+in\s+use|Session\s+Attached', re.I)
RE_BANDWIDTH         = re.compile(r'Bandwidth\s+set\s+to\s+(\d+)', re.I)

# ─── BBS log patterns (separate file, parsed for frequency enrichment) ──────
#
# BPQ's BBS log records the frequency for each VARA connection in two forms:
#
# 1. INCOMING — when a remote station connects to us, BPQ logs:
#       260107 00:10:49 |KK4DIV-1 Incoming Connect from KK4DIV on Port 3 Freq 7104700 Mode VARA
#    Captures: timestamp, our SSID-qualified callsign, base call, freq in Hz
#
# 2. OUTGOING — when we initiate a connection, BPQ logs a RADIO command
#    just before the connect:
#       260107 00:33:22 >K7EK RADIO 3.597000
#    Captures: timestamp, base call of remote partner, freq in MHz
#
# Both timestamps are in BPQ's BBS-log format: "YYMMDD HH:MM:SS"
RE_BBS_TIMESTAMP     = re.compile(r'^(\d{2})(\d{2})(\d{2})\s+(\d{2}):(\d{2}):(\d{2})\s')
RE_BBS_INCOMING_FREQ = re.compile(
    r'\|([A-Z0-9-]+)\s+Incoming Connect from ([A-Z0-9-]+).*Freq\s+(\d+)\s+Mode\s+VARA',
    re.I)
RE_BBS_OUTGOING_FREQ = re.compile(
    r'>([A-Z0-9-]+)\s+RADIO\s+([\d.]+)',
    re.I)

# 3. CHANNEL BUSY — BPQ's BBS log records a distinct rejection when the
#    forwarding partner reports the channel is occupied, e.g.:
#       260114 08:22:10 <K7EK      Sorry, Can't Connect - Channel is busy
#    This is a BBS-log-only signal — VARA's own log has no equivalent
#    message, so this can only be detected here, not from the .vara file.
#    (Matches the legacy in-browser parser's "channelBusyMatch" regex in
#    bpq-rf-connections.html.)
RE_BBS_CHANNEL_BUSY = re.compile(
    r'<([A-Z0-9-]+)\s+Sorry.*Channel.*busy',
    re.I)

# 4. FBB MESSAGE PROPOSALS — BPQ's BBS log records each forwarded-message
#    proposal line, used to populate the "Forwarded Messages" panel.
#    Two line formats (matches the legacy in-browser parser's faMatch/
#    fcMatch regexes in bpq-rf-connections.html exactly):
#
#    FA/FB format:
#      "260204 00:02:11 >F1UBL    FA B LU9DCE WW NEWS 6938_LU9DCE 7569"
#       date   time     dir partner  FA type from  to   category    bid       size
#
#    FC format (compressed):
#      "260227 02:41:17 >N3HYM     FC EM ARLD008 4814 2388 NC8Q ARL DX B"
#       date   time     dir partner   FC EM bid    origSz compSz from to  category type
#
#    dir is '>' (we're sending/proposing outbound = 'out') or '<'/'|'
#    (we're receiving/being proposed to = 'in') — matches the JS convention
#    where dir === '>' ? 'out' : 'in'.
RE_BBS_MSG_FA = re.compile(
    r'^(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+([><])([A-Z0-9-]*)\s+F[ABF]\s+([BPT])\s+'
    r'([A-Z0-9]+)\s+([A-Z0-9@#._-]+)\s+([A-Z0-9@#._-]+)\s+([A-Z0-9_.-]+)\s+(\d+)',
    re.I)
RE_BBS_MSG_FC = re.compile(
    r'^(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+([><])([A-Z0-9-]*)\s+FC\s+EM\s+'
    r'([A-Z0-9_.-]+)\s+(\d+)\s+(\d+)\s+([A-Z0-9]+)\s+([A-Z0-9@#._-]+)\s+([A-Z0-9@#._-]+)\s+([BPT])',
    re.I)

# 5. CONNECT-MODE LOOKUP — BPQ writes a *separate* log
#    (ConnectLog_YYMMDD.log, one file per day) recording every connect
#    to any BPQ application from any port, tagged with its medium:
#
#      "00:23:38 Call from N3MEL-2 to K1AJD-7 Mode VARA"
#      "00:24:10 Call from N3MEL-7 at Node N3MEL-11 to K1AJD-7 Mode NETROM"
#
#    This is the only log that reliably distinguishes VARA HF (RF) from
#    NET/ROM, AX.25 (non-HF/local), and Telnet connections. Neither the
#    BBS forwarding log (FA/FC lines, matched above) nor the VARA log
#    itself record this — FA/FC lines fire identically regardless of
#    which port/medium carried the session, which is why the RF
#    Connections page's Forwarded Messages panel previously couldn't
#    tell a real VARA HF forwarding partner (e.g. N3MEL-2) apart from a
#    same-base-callsign station reached over NET/ROM (e.g. N3MEL-7).
#
#    Mirrors the regex already proven against real ConnectLog data by
#    the client-side parser in bpq-connect-log.html (parseLine()) —
#    matched as two independent searches rather than one combined
#    pattern, same as that parser, since "at Node ..." is optional and
#    line layout has some variance.
RE_CONNECTLOG_TIME = re.compile(r'^(\d{2}):(\d{2}):(\d{2})\s+Call from\s+(\S+)', re.I)
RE_CONNECTLOG_MODE = re.compile(r'Mode\s+(\S+)', re.I)

# Maximum time a session can plausibly be in-flight before we give up
# waiting for its Disconnect line. VARA HF sessions are typically a few
# minutes; the longest legitimate sessions seen in real logs are 30-40
# minutes. 60 minutes is safely beyond any legitimate session.
#
# This catches the rare case where VARA never writes a Disconnected line
# (e.g., process killed, system crashed). The session gets recorded as
# 'failed/incomplete_no_disconnect' so it appears in the dashboard.
STUCK_SESSION_TIMEOUT_SEC = 60 * 60


def parse_timestamp(line, fallback_year=None):
    """Parse the leading timestamp of a log line.

    Returns a tuple (datetime, format_used) or (None, None) if the line
    has no recognized timestamp prefix.

    'New' format ("Mar 25 17:44:39") has no year — we infer it: if the
    parsed month is greater than the current month, assume previous year
    (matches the JS logic).

    'Old' format ("251226 00:06:02") has YY which we expand to 2000+YY.
    """
    now = dt.datetime.utcnow()
    if fallback_year is None:
        fallback_year = now.year

    m = RE_TIMESTAMP_NEW.match(line)
    if m:
        mon, day, h, mi, s = m.group(1), m.group(2), m.group(3), m.group(4), m.group(5)
        month = MONTH_MAP.get(mon)
        if not month:
            return (None, None)
        year = fallback_year
        # Handle year rollover: if parsed month is later than current, must be last year
        if month > now.month:
            year -= 1
        try:
            return (dt.datetime(year, month, int(day),
                                int(h), int(mi), int(s)), 'new')
        except ValueError:
            return (None, None)

    m = RE_TIMESTAMP_OLD.match(line)
    if m:
        yy, mm, dd, hh, mi, ss = (m.group(i) for i in range(1, 7))
        try:
            return (dt.datetime(2000 + int(yy), int(mm), int(dd),
                                int(hh), int(mi), int(ss)), 'old')
        except ValueError:
            return (None, None)

    return (None, None)


def freq_to_band(freq_khz):
    """Map a frequency in kHz to amateur band name. Returns 'Unknown' if
    outside HF amateur allocations.

    Frequencies are KHz integers (e.g. 14105 = 14.105 MHz).
    """
    if not freq_khz:
        return None
    f = freq_khz
    if 1800 <= f <= 2000:    return '160m'
    if 3500 <= f <= 4000:    return '80m'
    if 5300 <= f <= 5450:    return '60m'
    if 7000 <= f <= 7300:    return '40m'
    if 10100 <= f <= 10150:  return '30m'
    if 14000 <= f <= 14350:  return '20m'
    if 18068 <= f <= 18168:  return '17m'
    if 21000 <= f <= 21450:  return '15m'
    if 24890 <= f <= 24990:  return '12m'
    if 28000 <= f <= 29700:  return '10m'
    return 'Unknown'


def build_freq_lookup(bbs_log_dir, since=None, until=None, verbose=False):
    """Walk BBS log files and build a lookup of session frequencies.

    BPQ's BBS log records frequency for each VARA connection. We pre-scan
    the relevant log files once and produce a list of (timestamp, base_call,
    freq_khz) entries that the parser uses to enrich rf_connections rows.

    Args:
        bbs_log_dir: directory containing BBS log files (YYMMDD.log)
        since: datetime — earliest timestamp we care about (None = no limit)
        until: datetime — latest timestamp we care about (None = no limit)
        verbose: print progress to stderr

    Returns:
        List of (timestamp, base_call, freq_khz) tuples, sorted by timestamp.
        Each entry represents one frequency-setting event in the BBS log.
    """
    bbs_log_dir = Path(bbs_log_dir)
    if not bbs_log_dir.is_dir():
        if verbose:
            sys.stderr.write(
                f"BBS log dir not found: {bbs_log_dir} — skipping freq enrichment\n")
        return []

    # BPQ BBS logs are named log_YYMMDD_BBS.txt (e.g. log_260526_BBS.txt).
    log_files = list(bbs_log_dir.glob('log_*_BBS.txt'))

    # Filter by date encoded in the filename. The YYMMDD lives at chars
    # 4-9 of the stem "log_YYMMDD_BBS".
    def file_date(path):
        m = re.match(r'^log_(\d{6})_BBS$', path.stem)
        if not m:
            return None
        try:
            return dt.datetime.strptime(m.group(1), '%y%m%d').date()
        except ValueError:
            return None

    if since:
        since_date = since.date()
        log_files = [f for f in log_files
                     if (file_date(f) is None or file_date(f) >= since_date)]

    log_files.sort()

    if verbose:
        sys.stderr.write(
            f"Scanning {len(log_files)} BBS log files for frequencies...\n")

    freq_events = []
    files_scanned = 0
    lines_scanned = 0

    for log_file in log_files:
        try:
            with open(log_file, 'r', encoding='utf-8', errors='replace') as f:
                files_scanned += 1
                for line in f:
                    lines_scanned += 1
                    # Quick reject: must have a timestamp prefix AND
                    # either "Freq" or "RADIO" to be interesting
                    if 'Freq ' not in line and 'RADIO ' not in line:
                        continue

                    tsm = RE_BBS_TIMESTAMP.match(line)
                    if not tsm:
                        continue
                    yy, mm, dd, hh, mi, ss = (int(tsm.group(i)) for i in range(1, 7))
                    try:
                        ts = dt.datetime(2000 + yy, mm, dd, hh, mi, ss)
                    except ValueError:
                        continue

                    if since and ts < since:
                        continue
                    if until and ts > until:
                        continue

                    # INCOMING: "|CALL-N Incoming Connect from CALL on Port N Freq XXXXXXX Mode VARA"
                    im = RE_BBS_INCOMING_FREQ.search(line)
                    if im:
                        base = base_call(im.group(2))
                        try:
                            freq_hz = int(im.group(3))
                            # Round to nearest kHz (preserves 1-decimal MHz display)
                            freq_khz = int(round(freq_hz / 1000.0))
                            freq_events.append((ts, base, freq_khz))
                        except ValueError:
                            pass
                        continue

                    # OUTGOING: ">CALL RADIO X.XXXXXX"
                    om = RE_BBS_OUTGOING_FREQ.search(line)
                    if om:
                        base = base_call(om.group(1))
                        try:
                            freq_mhz = float(om.group(2))
                            freq_khz = int(round(freq_mhz * 1000))
                            freq_events.append((ts, base, freq_khz))
                        except ValueError:
                            pass
        except (OSError, IOError):
            continue

    # Sort by timestamp for efficient lookup later
    freq_events.sort(key=lambda x: x[0])

    if verbose:
        sys.stderr.write(
            f"  scanned {files_scanned} files, {lines_scanned} lines, "
            f"captured {len(freq_events)} freq events\n")

    return freq_events


def lookup_freq_for_session(freq_events, session_start, base_callsign,
                            tolerance_sec=120):
    """Find the freq_khz for a session given its start time + base call.

    Searches freq_events (sorted by timestamp) for an entry matching
    base_callsign within tolerance_sec of session_start. Returns the
    freq_khz of the closest match, or None if no match found.

    The freq event almost always occurs WITHIN ±2 minutes of the session
    start — for incoming, the BBS-log "Incoming Connect" line is written
    during the VARA-log "Connected to" event. For outgoing, the BBS-log
    "RADIO" command is written 1-30 seconds before the VARA-log Connect.
    """
    if not freq_events or not session_start or not base_callsign:
        return None

    best = None
    best_diff = tolerance_sec + 1

    # Linear scan with early termination. freq_events is sorted by ts.
    # Find approximate position then scan a small window around it.
    for ts, base, freq_khz in freq_events:
        diff = abs((ts - session_start).total_seconds())
        if diff > tolerance_sec:
            # If we're already past the window AND we've moved beyond,
            # we can stop. But since base call must also match, just
            # keep going — the sort doesn't help us short-circuit on
            # non-matches.
            continue
        if base != base_callsign:
            continue
        if diff < best_diff:
            best = freq_khz
            best_diff = diff

    return best


def build_channel_busy_lookup(bbs_log_dir, since=None, until=None, verbose=False):
    """Walk BBS log files and build a lookup of "Channel is busy" rejections.

    Mirrors build_freq_lookup()'s file-walking approach, but captures a
    different signal: the BBS log's "Sorry, Can't Connect - Channel is
    busy" rejection message. VARA's own log has no equivalent — this
    failure mode can only be seen from the BBS log, which is why the
    DB-backed parser previously had no way to classify it at all (see
    CHANGELOG / session journal entry on the Channel Busy bug).

    Args:
        bbs_log_dir: directory containing BBS log files (YYMMDD.log)
        since: datetime — earliest timestamp we care about (None = no limit)
        until: datetime — latest timestamp we care about (None = no limit)
        verbose: print progress to stderr

    Returns:
        List of (timestamp, base_call) tuples, sorted by timestamp.
    """
    bbs_log_dir = Path(bbs_log_dir)
    if not bbs_log_dir.is_dir():
        if verbose:
            sys.stderr.write(
                f"BBS log dir not found: {bbs_log_dir} — skipping channel-busy enrichment\n")
        return []

    log_files = list(bbs_log_dir.glob('log_*_BBS.txt'))

    def file_date(path):
        m = re.match(r'^log_(\d{6})_BBS$', path.stem)
        if not m:
            return None
        try:
            return dt.datetime.strptime(m.group(1), '%y%m%d').date()
        except ValueError:
            return None

    if since:
        since_date = since.date()
        log_files = [f for f in log_files
                     if (file_date(f) is None or file_date(f) >= since_date)]

    log_files.sort()

    if verbose:
        sys.stderr.write(
            f"Scanning {len(log_files)} BBS log files for channel-busy events...\n")

    busy_events = []
    files_scanned = 0
    lines_scanned = 0

    for log_file in log_files:
        try:
            with open(log_file, 'r', encoding='utf-8', errors='replace') as f:
                files_scanned += 1
                for line in f:
                    lines_scanned += 1
                    # Quick reject before the more expensive regex/timestamp work
                    if 'busy' not in line.lower():
                        continue

                    tsm = RE_BBS_TIMESTAMP.match(line)
                    if not tsm:
                        continue
                    yy, mm, dd, hh, mi, ss = (int(tsm.group(i)) for i in range(1, 7))
                    try:
                        ts = dt.datetime(2000 + yy, mm, dd, hh, mi, ss)
                    except ValueError:
                        continue

                    if since and ts < since:
                        continue
                    if until and ts > until:
                        continue

                    bm = RE_BBS_CHANNEL_BUSY.search(line)
                    if bm:
                        busy_events.append((ts, base_call(bm.group(1))))
        except (OSError, IOError):
            continue

    busy_events.sort(key=lambda x: x[0])

    if verbose:
        sys.stderr.write(
            f"  scanned {files_scanned} files, {lines_scanned} lines, "
            f"captured {len(busy_events)} channel-busy events\n")

    return busy_events


def lookup_channel_busy_for_session(busy_events, session_start, base_callsign,
                                    tolerance_sec=120):
    """Return True if a "Channel is busy" rejection for base_callsign
    occurred within tolerance_sec of session_start.

    Same matching approach as lookup_freq_for_session(): a linear scan
    with a time-window + callsign match, since busy_events is typically
    small (a handful of events per day) and only scanned per failed
    session, not per line.
    """
    if not busy_events or not session_start or not base_callsign:
        return False

    for ts, base in busy_events:
        if base != base_callsign:
            continue
        if abs((ts - session_start).total_seconds()) <= tolerance_sec:
            return True

    return False


def build_connect_mode_lookup(bbs_log_dir, since=None, until=None, verbose=False):
    """Walk BPQ's ConnectLog_YYMMDD.log files and build an indexed lookup
    of connect events -> medium (VARA / NETROM / AX.25 / TELNET / ...).

    Used by build_forwarded_messages() to tag each FA/FC message
    proposal with the medium of the connection that (most likely)
    carried it, so the RF Connections page can filter its Forwarded
    Messages panel down to actual RF (VARA HF) traffic instead of
    everything the BBS forwarding log recorded regardless of port.

    Returns a dict {'exact': {...}, 'base': {...}} — see
    lookup_mode_for_message() for how it's used. Each of 'exact'/'base'
    maps a callsign (exact-with-SSID, or base-call-only, respectively)
    to a pair of parallel lists (timestamps, modes), each sorted by
    timestamp. This indexed-by-callsign shape (rather than one flat
    list) is deliberate: a full ConnectLog history easily runs to a
    couple hundred thousand events (every connect to every BPQ app, on
    every port, from every station, over the retention window) — a
    linear scan of the whole thing for every one of possibly thousands
    of FA/FC messages is O(messages × events) and made a full --reset
    backfill run effectively hang in practice. Indexed by callsign +
    bisected by timestamp, each lookup is O(log n) against only that
    callsign's own events.
    """
    bbs_log_dir = Path(bbs_log_dir)
    empty = {'exact': {}, 'base': {}}
    if not bbs_log_dir.is_dir():
        if verbose:
            sys.stderr.write(
                f"ConnectLog dir not found: {bbs_log_dir} — skipping "
                f"medium enrichment (messages will keep medium=NULL, "
                f"i.e. 'unknown', not 'non-RF')\n")
        return empty

    log_files = list(bbs_log_dir.glob('ConnectLog_*.log'))

    def file_date(path):
        m = re.match(r'^ConnectLog_(\d{6})$', path.stem)
        if not m:
            return None
        try:
            return dt.datetime.strptime(m.group(1), '%y%m%d').date()
        except ValueError:
            return None

    if since:
        since_date = since.date()
        log_files = [f for f in log_files
                     if (file_date(f) is None or file_date(f) >= since_date)]

    log_files.sort()

    if verbose:
        sys.stderr.write(
            f"Scanning {len(log_files)} ConnectLog files for connect mode...\n")

    by_exact = defaultdict(list)  # callsign -> [(ts, mode), ...]
    by_base = defaultdict(list)   # base_call -> [(ts, mode), ...]
    files_scanned = 0
    event_count = 0

    for log_file in log_files:
        fdate = file_date(log_file)
        if fdate is None:
            continue
        try:
            with open(log_file, 'r', encoding='utf-8', errors='replace') as f:
                files_scanned += 1
                for line in f:
                    line = line.strip()
                    if not line or 'Call from' not in line:
                        continue
                    tm = RE_CONNECTLOG_TIME.match(line)
                    if not tm:
                        continue
                    hh, mm, ss, callsign = tm.groups()
                    mode_m = RE_CONNECTLOG_MODE.search(line)
                    if not mode_m:
                        continue  # no Mode field — can't classify, skip
                    mode = mode_m.group(1).upper()
                    try:
                        ts = dt.datetime(fdate.year, fdate.month, fdate.day,
                                         int(hh), int(mm), int(ss))
                    except ValueError:
                        continue
                    if since and ts < since:
                        continue
                    if until and ts > until:
                        continue
                    callsign = callsign.upper()
                    by_exact[callsign].append((ts, mode))
                    by_base[base_call(callsign)].append((ts, mode))
                    event_count += 1
        except (OSError, IOError):
            continue

    def freeze(d):
        # Sort each callsign's events by timestamp once, then split into
        # parallel (times, modes) lists so lookup can bisect on `times`
        # directly without re-deriving it on every call.
        out = {}
        for call, pairs in d.items():
            pairs.sort(key=lambda p: p[0])
            out[call] = ([p[0] for p in pairs], [p[1] for p in pairs])
        return out

    index = {'exact': freeze(by_exact), 'base': freeze(by_base)}

    if verbose:
        sys.stderr.write(
            f"  scanned {files_scanned} ConnectLog files, "
            f"{event_count} connect events, "
            f"{len(index['exact'])} distinct callsigns\n")

    return index


def _nearest_mode(times, modes, msg_time, tolerance_sec):
    """Binary-search `times` (sorted) for the entry closest to msg_time,
    within tolerance_sec. Returns the matching mode, or None."""
    if not times:
        return None
    # Prefer the most recent connect at or just before the message (the
    # session that carried it starts with that connect). A few seconds of
    # slack covers ConnectLog/BBS-log clock rounding. Only if there is no
    # preceding connect in the window do we fall back to the nearest one
    # after (2026-10-06 — previously a later connect over a different
    # medium could win just by being closer in time).
    j = bisect.bisect_right(times, msg_time + dt.timedelta(seconds=5)) - 1
    if j >= 0 and (msg_time - times[j]).total_seconds() <= tolerance_sec:
        return modes[j]
    i = bisect.bisect_left(times, msg_time)
    best_mode, best_diff = None, None
    # The nearest entry is always immediately before or after the
    # insertion point — only these two candidates need checking.
    for idx in (i - 1, i):
        if 0 <= idx < len(times):
            diff = abs((times[idx] - msg_time).total_seconds())
            if diff <= tolerance_sec and (best_diff is None or diff < best_diff):
                best_diff, best_mode = diff, modes[idx]
    return best_mode


def lookup_mode_for_message(connect_index, msg_time, partner_raw,
                            partner_base, tolerance_sec=1800):
    """Find the medium that most likely carried a given FA/FC message
    proposal.

    Tries an exact callsign+SSID match (partner_raw, e.g. 'N3MEL-7')
    within the tolerance window first — that's the strongest signal,
    since the FA/FC line's partner field is the exact SSID of the
    station we were connected to for that proposal. Falls back to a
    base-callsign match (any SSID of the same station) if no exact
    match is found, in case the BBS log and ConnectLog don't always
    agree on SSID formatting.

    A 30-minute default tolerance is deliberately wide — unlike VARA
    session frequency correlation (which needs a tight window because
    a station can retune), a single BBS forwarding session can stay
    connected and exchange several FA/FC proposals over several
    minutes, all correlating back to one Connect event at the start.

    connect_index is the {'exact': {...}, 'base': {...}} dict from
    build_connect_mode_lookup() — each lookup here is O(log n) against
    only the relevant callsign's own events, not a scan of the whole
    ConnectLog history.

    Returns the mode string (e.g. 'VARA', 'NETROM', 'AX.25', 'TELNET'),
    or None if no connect event is found within the window. Callers
    MUST treat None as "medium unknown" — not as evidence of non-RF —
    so that gaps in ConnectLog retention/coverage don't silently hide
    real RF messages.
    """
    if not connect_index or not msg_time:
        return None

    exact_idx = connect_index.get('exact') or {}
    base_idx = connect_index.get('base') or {}

    if partner_raw and partner_raw in exact_idx:
        times, modes = exact_idx[partner_raw]
        mode = _nearest_mode(times, modes, msg_time, tolerance_sec)
        if mode is not None:
            return mode

    if partner_base and partner_base in base_idx:
        times, modes = base_idx[partner_base]
        return _nearest_mode(times, modes, msg_time, tolerance_sec)

    return None


# ─── Outbound-session medium (added 2026-10-06) ──────────────────────────
# ConnectLog only records *incoming* connects ("Call from X ... Mode M"),
# so messages exchanged during a session WE started (our BBS connecting
# out to a partner) never matched anything there and were stored with
# medium=NULL — which the RF page counts as RF. Real example: every
# outbound session to KE0GB on 2026-10-06 ran the connect script
# "C 2 COSCO" (port 2 → KE0GB's COSCO node → BBS), with no VARA session
# at all, yet all of its messages showed '?' and were counted as RF.
#
# Fix: follow each outbound BBS session in the BBS log itself —
#
#   "261006 22:26:38 |KE0GB     Connecting to BBS KE0GB"     session start
#   "261006 22:26:38 >KE0GB     C 2 COSCO"                   first hop: port 2
#   "261006 22:26:40 |KE0GB     KE0GB Disconnected"          session end
#
# — and resolve the medium of every FA/FC line inside it from the first
# connect-script hop: the port's driver in bpq32.cfg (VARA / BPQAXIP /
# TELNET / KISS ...), "C <call>" with no port = NET/ROM routing, and an
# overlapping outgoing VARA session in rf_connections as the fallback
# when the port can't be classified.

RE_BBS_LINE = re.compile(r'^(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+([|<>])(\S+)\s+(.*?)\s*$')
RE_SCRIPT_ATTACH = re.compile(r'^ATTACH\s+(\d+)\b', re.I)
RE_SCRIPT_C_PORT = re.compile(r'^C(?:ONNECT)?\s+(\d+)\s+\S+', re.I)
RE_SCRIPT_C_CALL = re.compile(r'^C(?:ONNECT)?\s+([A-Z0-9][A-Z0-9:-]*)\s*$', re.I)

# Max time an outbound BBS session is held open waiting for its
# "Disconnected" line before we stop attributing lines to it.
OUTBOUND_SESSION_MAX_SEC = 2 * 60 * 60


def driver_to_medium(driver, port_id=''):
    """Map a bpq32.cfg PORT block's DRIVER/DLLNAME/TYPE to a medium tag."""
    d = (driver or '').upper()
    pid = (port_id or '').upper()
    if 'VARA' in d or (not d and 'VARA' in pid):
        return 'VARA'
    if 'AXIP' in d or 'AXUDP' in d:
        return 'AXIP'
    if 'TELNET' in d:
        return 'TELNET'
    if 'ARDOP' in d:
        return 'ARDOP'
    if 'KISS' in d or 'UZ7HO' in d or 'AGW' in d or 'ASYNC' in d or 'SCS' in d:
        return 'AX.25'
    return None


def parse_bpq_port_map(cfg_path, verbose=False):
    """Read bpq32.cfg and return {port_number: medium} for every PORT
    block whose driver we can classify. Missing/unreadable file → {}
    (outbound messages then fall back to VARA-session overlap)."""
    port_map = {}
    if not cfg_path or not os.path.isfile(cfg_path):
        if verbose:
            sys.stderr.write(f"bpq32.cfg not found at {cfg_path!r} — outbound "
                             f"port mediums will use VARA-session overlap only\n")
        return port_map
    try:
        with open(cfg_path, 'r', encoding='utf-8', errors='replace') as f:
            lines = f.readlines()
    except OSError as e:
        sys.stderr.write(f"WARNING: can't read {cfg_path}: {e} — outbound port "
                         f"mediums will use VARA-session overlap only\n")
        return port_map

    in_port, seq = False, 0
    cur = {}
    in_comment = False
    for raw in lines:
        line = raw.split(';', 1)[0].strip()
        if in_comment:
            if '*/' in line:
                in_comment = False
            continue
        if line.startswith('/*'):
            in_comment = '*/' not in line
            continue
        if not line:
            continue
        up = line.upper()
        if up == 'PORT':
            in_port, cur = True, {}
            seq += 1
            continue
        if up == 'ENDPORT':
            if in_port:
                num = cur.get('PORTNUM')
                try:
                    num = int(num) if num is not None else seq
                except ValueError:
                    num = seq
                medium = driver_to_medium(
                    cur.get('DRIVER') or cur.get('DLLNAME') or cur.get('TYPE'),
                    cur.get('ID'))
                # TYPE=ASYNC with PROTOCOL=KISS is a plain KISS TNC
                if medium is None and (cur.get('PROTOCOL') or '').upper() == 'KISS':
                    medium = 'AX.25'
                if medium:
                    port_map[num] = medium
            in_port = False
            continue
        if in_port and '=' in line:
            k, v = line.split('=', 1)
            k = k.strip().upper()
            if k not in cur:  # first occurrence wins (e.g. TYPE vs nested CONFIG lines)
                cur[k] = v.strip()
    if verbose:
        sys.stderr.write(f"bpq32.cfg port map: {port_map}\n")
    return port_map


def load_outgoing_vara_sessions(conn, since=None):
    """{base_call: [(start, end), ...]} of successful outgoing VARA
    sessions from rf_connections, for the outbound-medium fallback."""
    out = defaultdict(list)
    if conn is None:
        return out
    sql = ("SELECT base_call, session_start, "
           "COALESCE(session_end, session_start + INTERVAL 30 MINUTE) "
           "FROM rf_connections WHERE direction = 'outgoing' AND status = 'success'")
    params = ()
    if since:
        sql += " AND session_start >= %s"
        params = (since - dt.timedelta(days=1),)
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            for row in cur.fetchall():
                if isinstance(row, dict):
                    row = list(row.values())
                b, s, e = row[0], row[1], row[2]
                if b and s:
                    out[str(b).upper()].append((s, e or s))
    except Exception as e:  # never let enrichment break the parser run
        sys.stderr.write(f"WARNING: couldn't load VARA sessions for outbound "
                         f"medium fallback: {e}\n")
    return out


def resolve_outbound_medium(sess, partner_base, vara_sessions, port_map):
    """Medium for a message exchanged inside an outbound BBS session."""
    if 'medium' in sess:
        return sess['medium']
    port = sess.get('port')
    medium = None
    if port is not None and port in port_map:
        medium = port_map[port]
    elif sess.get('netrom'):
        medium = 'NETROM'
    if medium is None and vara_sessions:
        slack = dt.timedelta(minutes=2)
        b_start = sess['start']
        b_end = sess.get('end') or (b_start + dt.timedelta(minutes=30))
        for v_start, v_end in vara_sessions.get(partner_base, ()):
            if v_start - slack <= b_end and b_start <= v_end + slack:
                medium = 'VARA'
                break
    if medium is None and port is not None:
        medium = f'PORT{port}'  # unclassified port, and no VARA session → not RF
    # Only cache once the first hop is known; before that the script may
    # still be arriving (cache would freeze a premature None).
    if port is not None or sess.get('netrom') or medium is not None:
        sess['medium'] = medium
    return medium


# ─── FBB exchange tracking (added 2026-10-06) ────────────────────────────
# Any BBS-log line for a partner: "261005 06:49:00 <KE4QCM    FS ++".
# Direction chars: '>' we sent, '<' partner sent, '|' status, '?' trace.
RE_BBS_PROTO = re.compile(r'^(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+([|<>?])(\S+)\s+(.*?)\s*$')
# Reply to a batch of offers — one code per offer, in order:
#   "FS ++"  "FS -Y"  "FS ---+"  "FS !1200+"   (FBB '+-=' and B2F 'YNL...')
RE_FBB_FS = re.compile(r'^FS\s+(\S+)', re.I)
# A transfer is only confirmed by a turn change within this long of the
# acceptance; anything later is left as "accepted, delivery unconfirmed".
DELIVERY_CONFIRM_MAX_SEC = 10 * 60
FS_CODE_MAP = {
    '+': 'A', 'Y': 'A',              # accepted
    '-': 'R', 'N': 'R', 'R': 'R',    # rejected (already have it / refused)
    '=': 'D', 'L': 'D', 'H': 'D',    # deferred (later / held)
}


def _apply_fs_codes(codes):
    """'-+=' / 'YN!123' → ['R','A','D', ...] — one status per offer.
    'A'/'!' followed by digits = accepted with a resume offset; the digits
    belong to that one code. Unknown codes → 'E' (error/other)."""
    out, i, codes = [], 0, codes.upper()
    while i < len(codes):
        c = codes[i]
        i += 1
        if c in '!A':
            while i < len(codes) and codes[i].isdigit():
                i += 1
            out.append('A')
        else:
            out.append(FS_CODE_MAP.get(c, 'E'))
    return out


def build_forwarded_messages(bbs_log_dir, since=None, until=None, verbose=False,
                             connect_events=None, vara_sessions=None,
                             port_map=None):
    """Walk BBS log files and extract FBB message-forwarding proposals.

    Populates the "Forwarded Messages" panel server-side instead of the
    client re-parsing the same BBS logs on every page load. Mirrors the
    legacy in-browser parser's faMatch/fcMatch regex logic exactly (see
    RE_BBS_MSG_FA / RE_BBS_MSG_FC above), including its dedup approach —
    a (bid, direction) pair is only emitted once per call, same as the
    JS `seenBIDs` Set keyed on `bid + '_' + dir`.

    Unlike build_freq_lookup()/build_channel_busy_lookup() (which return
    small in-memory correlation lists), this returns full row dicts ready
    for upsert_message() — messages are a standalone table, not just an
    enrichment signal for VARA sessions.

    Args:
        bbs_log_dir: directory containing BBS log files (YYMMDD.log)
        since: datetime — earliest timestamp we care about (None = no limit)
        until: datetime — latest timestamp we care about (None = no limit)
        verbose: print progress to stderr
        connect_events: optional {'exact': {...}, 'base': {...}} index
            from build_connect_mode_lookup(),
            used to tag each message with the medium (VARA/NETROM/
            AX.25/TELNET/...) of the connection that carried it — see
            lookup_mode_for_message(). Pass None/omit to leave medium
            unset (NULL), e.g. when ConnectLog isn't available — NULL
            means "unknown", not "non-RF", and is never filtered out.

    Returns:
        List of dicts with keys: bid, direction, partner, msg_type,
        from_call, to_area, category, size_bytes, msg_time,
        log_line_no, medium.
    """
    bbs_log_dir = Path(bbs_log_dir)
    if not bbs_log_dir.is_dir():
        if verbose:
            sys.stderr.write(
                f"BBS log dir not found: {bbs_log_dir} — skipping message enrichment\n")
        return []

    log_files = list(bbs_log_dir.glob('log_*_BBS.txt'))

    def file_date(path):
        m = re.match(r'^log_(\d{6})_BBS$', path.stem)
        if not m:
            return None
        try:
            return dt.datetime.strptime(m.group(1), '%y%m%d').date()
        except ValueError:
            return None

    if since:
        since_date = since.date()
        log_files = [f for f in log_files
                     if (file_date(f) is None or file_date(f) >= since_date)]

    log_files.sort()

    if verbose:
        sys.stderr.write(
            f"Scanning {len(log_files)} BBS log files for message proposals...\n")

    messages = []
    files_scanned = 0
    lines_scanned = 0
    port_map = port_map or {}
    vara_sessions = vara_sessions or {}
    # Open BBS sessions keyed by the exact partner token in column 3
    # (e.g. 'KE0GB', 'KE0GB-1'). Persists across files so a session that
    # straddles 00:00 UTC keeps its state.
    #   {'type': 'out', 'start': dt, 'port': int|None, 'netrom': bool,
    #    'hop_done': bool, ['medium': cached]}   or   {'type': 'in', 'start': dt}
    sessions = {}
    proto = {}    # per-partner-token FBB exchange state (offers awaiting FS, etc.)
    by_key = {}   # (bid, direction, partner_base) -> message dict

    def _line_ts(date, hh, mm, ss):
        try:
            return dt.datetime(2000 + int(date[0:2]), int(date[2:4]), int(date[4:6]),
                               int(hh), int(mm), int(ss))
        except ValueError:
            return None

    for log_file in log_files:
        try:
            with open(log_file, 'r', encoding='utf-8', errors='replace') as f:
                files_scanned += 1
                for line_no, line in enumerate(f, start=1):
                    lines_scanned += 1

                    # ── Outbound/inbound BBS session tracking ────────────
                    # Cheap pre-filter: status lines carry '|', and only
                    # '>' lines can be connect-script commands.
                    # Case-insensitive: some connect scripts are written
                    # lowercase ("c 2 GYBBBS") — 2026-10-06 fix.
                    line_up = line.upper()
                    if '|' in line[:20] or ('>' in line[:20] and
                                            (' C ' in line_up or ' ATTACH' in line_up
                                             or ' CONNECT ' in line_up)):
                        bl = RE_BBS_LINE.match(line)
                        if bl:
                            b_date, b_h, b_m, b_s, b_dir, b_key, b_rest = bl.groups()
                            b_key = b_key.upper()
                            if b_dir == '|':
                                if b_rest.upper().startswith('CONNECTING TO BBS'):
                                    ts = _line_ts(b_date, b_h, b_m, b_s)
                                    if ts:
                                        sessions[b_key] = {'type': 'out', 'start': ts,
                                                           'port': None, 'netrom': False,
                                                           'hop_done': False}
                                elif 'INCOMING CONNECT FROM' in b_rest.upper():
                                    ts = _line_ts(b_date, b_h, b_m, b_s)
                                    if ts:
                                        sessions[b_key] = {'type': 'in', 'start': ts}
                                elif b_rest.upper().endswith('DISCONNECTED'):
                                    sessions.pop(b_key, None)
                            elif b_dir == '>':
                                s = sessions.get(b_key)
                                if s and s['type'] == 'out' and not s['hop_done']:
                                    ma = RE_SCRIPT_ATTACH.match(b_rest)
                                    mp = None if ma else RE_SCRIPT_C_PORT.match(b_rest)
                                    mc = None if (ma or mp) else RE_SCRIPT_C_CALL.match(b_rest)
                                    if ma:
                                        s['port'] = int(ma.group(1))  # later "C <call>" uses it
                                    elif mp:
                                        s['port'] = int(mp.group(1))
                                        s['hop_done'] = True
                                    elif mc:
                                        if s['port'] is None:
                                            s['netrom'] = True
                                        s['hop_done'] = True

                    # ── FBB protocol tracking (2026-10-06) ───────────────
                    # Follow each offer → reply → transfer so every message
                    # gets an accept status and a delivered/received time.
                    # See FS_CODE_MAP / _apply_fs_codes() above.
                    pl = RE_BBS_PROTO.match(line)
                    if not pl:
                        continue
                    p_date, p_h, p_m, p_s, p_dir, p_key, p_rest = pl.groups()
                    p_key = p_key.upper()
                    p_up = p_rest.upper()
                    p_ts = None

                    if p_dir == '|':
                        if p_up.startswith('UNCOMPRESSING MESSAGE'):
                            st = proto.get(p_key)
                            if st and st['in_await']:
                                p_ts = _line_ts(p_date, p_h, p_m, p_s)
                                m_ = st['in_await'].pop(0)
                                acc = m_.get('_accepted_at')
                                if p_ts and acc and (p_ts - acc).total_seconds() <= DELIVERY_CONFIRM_MAX_SEC:
                                    m_['delivered_time'] = p_ts
                        elif p_up.endswith('DISCONNECTED'):
                            # Offers still waiting for an FS reply will never
                            # get one. Transfers already accepted are kept:
                            # BPQ sometimes logs a *second*, failed connect
                            # to the same partner ("Already Connected") whose
                            # Disconnected line is not the end of the session
                            # still carrying them (seen with VE3CGR 2026-10-05).
                            st = proto.get(p_key)
                            if st:
                                st['out_pending'].clear()
                                st['in_pending'].clear()
                        continue
                    if p_dir not in '<>' or not p_up.startswith('F'):
                        continue

                    p_ts = _line_ts(p_date, p_h, p_m, p_s)
                    if p_ts is None:
                        continue
                    st = proto.setdefault(p_key, {'out_pending': [], 'in_pending': [],
                                                  'out_await': [], 'in_await': []})
                    # Turn change: a protocol line from the partner means it
                    # has received everything we were sending; a protocol
                    # line from us means we've finished receiving (fallback
                    # for messages with no "Uncompressing" line).
                    # Only within DELIVERY_CONFIRM_MAX_SEC of the acceptance —
                    # a line from a much later session proves nothing.
                    for side in (('out_await',) if p_dir == '<' else ('in_await',)):
                        if st[side]:
                            for m_ in st[side]:
                                acc = m_.get('_accepted_at')
                                if acc and (p_ts - acc).total_seconds() <= DELIVERY_CONFIRM_MAX_SEC:
                                    m_['delivered_time'] = p_ts
                            st[side] = []

                    fs = RE_FBB_FS.match(p_rest)
                    if fs:
                        pending = st['out_pending'] if p_dir == '<' else st['in_pending']
                        await_ = st['out_await'] if p_dir == '<' else st['in_await']
                        for m_, status in zip(pending, _apply_fs_codes(fs.group(1))):
                            if not m_:
                                continue  # out-of-window placeholder
                            if m_.get('accept_status') == 'A':
                                # Accepted is final: a later '=' / '-' for the
                                # same message means the partner is already
                                # receiving it, or now has it.
                                if status == 'A' and not m_.get('delivered_time'):
                                    m_['_accepted_at'] = p_ts
                                    await_.append(m_)
                                continue
                            m_['accept_status'] = status
                            if status == 'A':
                                m_['_accepted_at'] = p_ts
                                await_.append(m_)
                        pending.clear()
                        continue

                    fa = RE_BBS_MSG_FA.match(line)
                    fc = None if fa else RE_BBS_MSG_FC.match(line)
                    if not fa and not fc:
                        continue

                    if fa:
                        (date, hh, mm, ss, direction_char, partner, msg_type,
                         from_call, to_area, category, bid, size) = fa.groups()
                        size_bytes = int(size)
                    else:
                        (date, hh, mm, ss, direction_char, partner, bid,
                         orig_size, comp_size, from_call, to_area, category,
                         msg_type) = fc.groups()
                        size_bytes = int(orig_size)

                    msg_time = p_ts
                    direction = 'out' if direction_char == '>' else 'in'
                    partner_raw = partner.upper()
                    partner_base = base_call(partner_raw)
                    pending = st['out_pending'] if direction == 'out' else st['in_pending']

                    if (since and msg_time < since) or (until and msg_time > until):
                        # Outside the window: still occupy its slot in the
                        # batch so FS codes line up, but don't emit a row.
                        pending.append({})
                        continue

                    # One row per (bid, direction, partner) — the same
                    # bulletin offered to EI2GYB and N3HYM is two
                    # deliveries. A re-offer of the same message to the same
                    # partner (e.g. after '=' deferred) updates that row.
                    key = (bid, direction, partner_base)
                    existing = by_key.get(key)
                    if existing is not None:
                        pending.append(existing)
                        continue

                    # `partner_raw` is the exact SSID as logged (e.g.
                    # 'N3MEL-7') — used for the medium lookup below, which
                    # needs the real SSID to match against ConnectLog.
                    # The stored `partner` column stays base-call-only for
                    # the forwarding-partner tile matching, which treats
                    # every SSID of a station as one partner.
                    sess = sessions.get(partner_raw)
                    if sess and sess['type'] == 'out' and \
                            (msg_time - sess['start']).total_seconds() > OUTBOUND_SESSION_MAX_SEC:
                        sessions.pop(partner_raw, None)  # never saw its Disconnected line
                        sess = None
                    if sess and sess['type'] == 'out':
                        # We started this session — ConnectLog has no record
                        # of it; use the connect script / VARA overlap.
                        medium = resolve_outbound_medium(
                            sess, partner_base, vara_sessions, port_map)
                    else:
                        medium = lookup_mode_for_message(
                            connect_events, msg_time, partner_raw, partner_base
                        ) if connect_events else None

                    msg = {
                        'bid': bid,
                        'direction': direction,
                        'partner': partner_base,
                        'msg_type': msg_type.upper(),
                        'from_call': from_call.upper(),
                        'to_area': to_area.upper(),
                        'category': category.upper(),
                        'size_bytes': size_bytes,
                        'msg_time': msg_time,
                        'medium': medium,
                        'log_line_no': line_no,
                        'accept_status': None,
                        'delivered_time': None,
                    }
                    by_key[key] = msg
                    messages.append(msg)
                    pending.append(msg)
        except (OSError, IOError):
            continue

    if verbose:
        n_acc = sum(1 for m_ in messages if m_['accept_status'] == 'A')
        n_rej = sum(1 for m_ in messages if m_['accept_status'] == 'R')
        n_def = sum(1 for m_ in messages if m_['accept_status'] == 'D')
        sys.stderr.write(
            f"  scanned {files_scanned} files, {lines_scanned} lines, "
            f"captured {len(messages)} message events "
            f"({n_acc} accepted, {n_rej} rejected, {n_def} deferred)\n")

    return messages


_MSG_DELIVERY_COLS = None  # cached: does rf_messages have accept_status/delivered_time?


def _has_delivery_columns(conn):
    """True once sql/2026-10-06-rf-messages-delivery.sql has been applied.
    Lets this parser be deployed before the migration without breaking
    the cron job — it just keeps writing the old columns until then."""
    global _MSG_DELIVERY_COLS
    if _MSG_DELIVERY_COLS is None:
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS "
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_messages' "
                    "AND COLUMN_NAME IN ('accept_status', 'delivered_time')")
                row = cur.fetchone()
                n = list(row.values())[0] if isinstance(row, dict) else row[0]
            _MSG_DELIVERY_COLS = (int(n) == 2)
        except Exception:
            _MSG_DELIVERY_COLS = False
        if not _MSG_DELIVERY_COLS:
            sys.stderr.write(
                "NOTE: rf_messages has no accept_status/delivered_time columns yet — "
                "apply sql/2026-10-06-rf-messages-delivery.sql to record deliveries\n")
    return _MSG_DELIVERY_COLS


def upsert_message(conn, msg, dry_run=False):
    """Insert one rf_messages row, or update the existing one for the same
    (bid, direction, partner).

    INSERT ... ON DUPLICATE KEY UPDATE so re-scanning an already-seen window
    (cron's rolling 2-day window, or --rescan-messages-days) refreshes
    medium / accept_status / delivered_time on existing rows. Never
    replaces a known value with NULL (a narrower re-scan that starts
    mid-exchange can't see the reply), and never downgrades an accepted
    message — a later '=' or '-' for the same message to the same partner
    means it was already being / had been received.

    Returns True if a row was inserted or changed (approximate — only used
    for the messages_emitted counter).
    """
    if dry_run:
        return True
    if _has_delivery_columns(conn):
        sql = """
        INSERT INTO rf_messages
            (bid, direction, partner, msg_type, from_call, to_area, category,
             size_bytes, msg_time, log_line_no, medium, accept_status, delivered_time)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            medium = COALESCE(VALUES(medium), medium),
            delivered_time = IF(accept_status = 'A' AND COALESCE(VALUES(accept_status), 'A') <> 'A',
                                delivered_time,
                                COALESCE(VALUES(delivered_time), delivered_time)),
            accept_status = IF(accept_status = 'A', 'A',
                               COALESCE(VALUES(accept_status), accept_status))
        """
        params = (
            msg['bid'], msg['direction'], msg['partner'], msg['msg_type'],
            msg['from_call'], msg['to_area'], msg['category'],
            msg['size_bytes'], msg['msg_time'], msg.get('log_line_no'),
            msg.get('medium'), msg.get('accept_status'), msg.get('delivered_time'),
        )
    else:
        sql = """
        INSERT INTO rf_messages
            (bid, direction, partner, msg_type, from_call, to_area, category,
             size_bytes, msg_time, log_line_no, medium)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            medium = COALESCE(VALUES(medium), medium)
        """
        params = (
            msg['bid'], msg['direction'], msg['partner'], msg['msg_type'],
            msg['from_call'], msg['to_area'], msg['category'],
            msg['size_bytes'], msg['msg_time'], msg.get('log_line_no'),
            msg.get('medium'),
        )
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.rowcount > 0


def build_cms_lookup(bbs_log_dir, our_callsign, since=None, verbose=False):
    """Walk CMSAccess_YYYYMMDD.log files to find WL2K sessions.

    These logs record when a station reaches the Winlink CMS through our
    RMS gateway. A line looks like:

        14:32:18 12345 W2KI Connected to CMS ...

    We capture (timestamp, base_call) for each such line, EXCEPT our own
    callsign (which represents our hub's outbound CMS connection over the
    Internet, not an RF connection).

    Args:
        bbs_log_dir: directory containing CMSAccess_*.log files
        our_callsign: our station base callsign (to skip outbound)
        since: datetime — earliest we care about (None = no limit)
        verbose: print progress to stderr

    Returns:
        List of (timestamp, base_call) tuples sorted by timestamp.
    """
    cms_dir = Path(bbs_log_dir)
    if not cms_dir.is_dir():
        return []

    our_base = base_call(our_callsign).upper() if our_callsign else None

    # CMSAccess logs use a 4-digit year: CMSAccess_YYYYMMDD.log
    cms_files = list(cms_dir.glob('CMSAccess_*.log'))

    def file_date(path):
        m = re.match(r'^CMSAccess_(\d{8})$', path.stem)
        if not m:
            return None
        try:
            return dt.datetime.strptime(m.group(1), '%Y%m%d').date()
        except ValueError:
            return None

    if since:
        since_date = since.date()
        cms_files = [f for f in cms_files
                     if (file_date(f) is None or file_date(f) >= since_date)]

    cms_files.sort()

    # Pattern matches the JS regex used by the dashboard page:
    #   ^HH:MM:SS <number> CALLSIGN Connected to CMS
    cms_re = re.compile(
        r'^(\d{2}):(\d{2}):(\d{2})\s+\d+\s+([A-Z0-9]+-?\d*)\s+Connected to CMS',
        re.I)

    cms_events = []
    for cms_file in cms_files:
        fdate = file_date(cms_file)
        if fdate is None:
            continue
        try:
            with open(cms_file, 'r', encoding='utf-8', errors='replace') as f:
                for line in f:
                    m = cms_re.match(line)
                    if not m:
                        continue
                    hh, mi, ss = int(m.group(1)), int(m.group(2)), int(m.group(3))
                    base = base_call(m.group(4)).upper()
                    # Skip our own hub's outbound CMS connection (Internet, not RF)
                    if our_base and base == our_base:
                        continue
                    try:
                        ts = dt.datetime(fdate.year, fdate.month, fdate.day,
                                         hh, mi, ss)
                    except ValueError:
                        continue
                    if since and ts < since:
                        continue
                    cms_events.append((ts, base))
        except (OSError, IOError):
            continue

    cms_events.sort(key=lambda x: x[0])

    if verbose:
        sys.stderr.write(
            f"Scanned {len(cms_files)} CMS log files, "
            f"captured {len(cms_events)} WL2K sessions\n")

    return cms_events


def is_wl2k_session(cms_events, session_start, base_callsign,
                    tolerance_sec=300):
    """Return True if a CMS (WL2K) session matches this VARA session.

    Searches cms_events for an entry matching base_callsign within
    tolerance_sec (default ±5 min, matching the JS logic) of session_start.
    """
    if not cms_events or not session_start or not base_callsign:
        return False
    for ts, base in cms_events:
        if base != base_callsign:
            continue
        if abs((ts - session_start).total_seconds()) <= tolerance_sec:
            return True
    return False


def find_nearest_freq(freq_events, session_start, tolerance_sec=1800):
    """Find the nearest freq_khz to session_start regardless of callsign.

    Used for WL2K sessions where we infer the operating frequency from
    other RF activity on the band around the same time (±30 min default,
    matching the JS findNearestFrequency). Returns freq_khz or None.
    """
    if not freq_events or not session_start:
        return None
    best = None
    best_diff = tolerance_sec + 1
    for ts, base, freq_khz in freq_events:
        diff = abs((ts - session_start).total_seconds())
        if diff <= tolerance_sec and diff < best_diff:
            best = freq_khz
            best_diff = diff
    return best


def base_call(callsign):
    """Strip SSID suffix (-N) to get the base callsign."""
    return re.sub(r'-\d+$', '', callsign)


# ─── Session accumulator ────────────────────────────────────────────────────


class SessionParser:
    """Stateful accumulator that walks log lines and emits completed
    sessions.

    A "session" is the span from a connect attempt (or incoming connection)
    through to a Disconnected line — including any S/N report in between.
    Failures (Incoming Connection failed, PORT busy) also emit a session
    record with the appropriate status.

    The parser yields session dicts ready for INSERT.
    """

    def __init__(self):
        self.curr = None
        self.bandwidth = None  # Sticky — Bandwidth Set lines persist between sessions

    def start_outgoing(self, ts, callsign):
        # Save any in-progress session as 'aborted' (rare — connection
        # attempts interrupted by another attempt)
        self.curr = {
            'session_start': ts,
            'callsign': callsign,
            'base_call': base_call(callsign),
            'direction': 'outgoing',
            'status': 'unknown',
            'bandwidth': self.bandwidth,
            'confirmed': False,  # set True only after "Connected to" line seen
        }

    def start_incoming(self, ts, callsign):
        self.curr = {
            'session_start': ts,
            'callsign': callsign,
            'base_call': base_call(callsign),
            'direction': 'incoming',
            'status': 'connected',
            'bandwidth': self.bandwidth,
            'confirmed': True,  # incoming connections are confirmed immediately
        }

    def confirm(self):
        """Mark current session as confirmed (Connected line seen).

        Until a session is confirmed, mid-session VARA warnings like
        'VARA is not connected to any App' are ignored — they may be
        background noise during the retry phase, not actual session
        failure indicators.
        """
        if self.curr is not None:
            self.curr['confirmed'] = True

    def record_snr(self, snr):
        if self.curr is None:
            return  # Orphan S/N line — would need WL2K cross-reference, skip for now
        self.curr['avg_snr_db'] = snr

    def emit_failure_incoming(self, ts):
        """Emit a standalone 'failed incoming' record (no curr session)."""
        return {
            'session_start': ts,
            'callsign': 'UNKNOWN',
            'base_call': 'UNKNOWN',
            'direction': 'incoming',
            'status': 'failed',
            'failure_reason': 'incoming_failed',
            'bandwidth': self.bandwidth,
        }

    def complete(self, ts, tx, rx, tx_bps, rx_bps, dur_min, dur_sec):
        """Disconnect line — finalize current session."""
        if self.curr is None:
            return None
        total = tx + rx
        # Match the JS logic: success = bytes transferred OR S/N was recorded
        is_success = total > 0 or self.curr.get('avg_snr_db') is not None
        session = dict(self.curr)
        session.pop('confirmed', None)  # internal flag, not a column
        session['session_end'] = ts
        session['duration_sec'] = dur_min * 60 + dur_sec
        session['tx_bytes'] = tx
        session['rx_bytes'] = rx
        session['tx_max_bps'] = tx_bps
        session['rx_max_bps'] = rx_bps
        session['status'] = 'success' if is_success else 'failed'
        if not is_success:
            session['failure_reason'] = 'no_data'
        self.curr = None
        return session

    def set_bandwidth(self, bw):
        self.bandwidth = bw

    def abort_current(self, reason='aborted'):
        """Force-close current session as failed (e.g. on next connect attempt
        without intervening disconnect). Returns the session or None."""
        if self.curr is None:
            return None
        session = dict(self.curr)
        session.pop('confirmed', None)  # internal flag, not a column
        session['status'] = 'failed'
        session['failure_reason'] = reason
        self.curr = None
        return session


# ─── DB layer ───────────────────────────────────────────────────────────────


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


def get_parser_state(conn, log_filename):
    """Return (last_offset, last_line_no, sessions_total) for the given log
    file. If no row exists, returns (0, 0, 0)."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT last_offset, last_line_no, sessions_total "
            "FROM rf_parser_state WHERE log_file = %s",
            (log_filename,)
        )
        row = cur.fetchone()
    if row is None:
        return (0, 0, 0)
    return (int(row[0]), int(row[1]), int(row[2]))


def save_parser_state(conn, log_filename, offset, line_no, sessions_total):
    """Upsert parser state row."""
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO rf_parser_state (log_file, last_offset, last_line_no, sessions_total) "
            "VALUES (%s, %s, %s, %s) "
            "ON DUPLICATE KEY UPDATE "
            "last_offset = VALUES(last_offset), "
            "last_line_no = VALUES(last_line_no), "
            "sessions_total = VALUES(sessions_total)",
            (log_filename, offset, line_no, sessions_total)
        )


def reset_parser_state(conn, log_filename):
    """Wipe parser state AND rf_connections/rf_messages for a clean re-parse.

    Uses DELETE FROM rather than TRUNCATE because TRUNCATE requires the
    DROP privilege at the MySQL level (it internally drops and recreates
    the table). DELETE only needs DELETE privilege, which the app user
    legitimately should have. The semantic difference doesn't matter for
    our case — we want all rows gone, the table empty.

    Optionally tries to reset AUTO_INCREMENT (cosmetic — keeps IDs starting
    at 1 again). This needs ALTER privilege which the app user may not have;
    we treat it as best-effort and continue silently if denied.

    rf_messages has no offset/checkpoint of its own (build_forwarded_messages()
    always rescans the `since` window fresh each run), so clearing it here
    isn't required for correctness — INSERT IGNORE against its UNIQUE KEY
    makes re-parsing idempotent regardless. It's cleared anyway so --reset
    means what it says: a genuinely clean rebuild of everything the parser
    owns, not just sessions.
    """
    with conn.cursor() as cur:
        cur.execute("DELETE FROM rf_parser_state WHERE log_file = %s", (log_filename,))
        cur.execute("DELETE FROM rf_connections")
        cur.execute("DELETE FROM rf_messages")
    # AUTO_INCREMENT reset is purely cosmetic. Try it — if user lacks ALTER,
    # the table just keeps incrementing IDs from where it left off, which
    # is fine functionally.
    for table in ('rf_connections', 'rf_messages'):
        try:
            with conn.cursor() as cur:
                cur.execute(f"ALTER TABLE {table} AUTO_INCREMENT = 1")
        except pymysql.MySQLError as e:
            sys.stderr.write(
                f"NOTE: couldn't reset AUTO_INCREMENT on {table} ({e}) — harmless, continuing.\n"
            )
    conn.commit()


def upsert_session(conn, session, log_line_no, dry_run=False,
                   freq_events=None, cms_events=None, channel_busy_events=None):
    """Insert a session row, ignoring if it duplicates an existing
    (session_start, callsign) pair. Returns True if inserted.

    Frequency/band enrichment (when freq_events provided), replicating
    the dashboard's client-side decision tree:

      1. Try direct freq lookup from BBS log (RADIO / Incoming Freq lines)
         matching this callsign within ±2 min → use that freq + band.
      2. If no direct freq AND this is an incoming session that succeeded:
         a. If it's in the CMS log (WL2K via our RMS gateway) OR simply
            has no BBS freq entry → mark band='WL2K' and infer freq from
            nearby RF activity (±30 min, any callsign).
      3. Otherwise leave freq/band as-is (NULL).

    Failure-reason reclassification (when channel_busy_events provided):
    a "Channel is busy" rejection in the BBS log has no VARA-log
    equivalent, so it can only be detected here. If a busy event for this
    session's base_call falls within ±2 min of session_start, override
    whatever failure_reason the VARA-log-only classification produced
    (usually 'no_data' or 'superseded') with 'channel_busy' — this is a
    more specific and more accurate reason for the same failure.
    """
    session_start = session.get('session_start')
    base = session.get('base_call')
    direction = session.get('direction')
    status = session.get('status')

    if channel_busy_events and status == 'failed':
        if lookup_channel_busy_for_session(channel_busy_events, session_start, base):
            session['failure_reason'] = 'channel_busy'

    if freq_events and not session.get('freq_khz'):
        # Step 1: direct freq match for this callsign
        freq_khz = lookup_freq_for_session(freq_events, session_start, base)

        if freq_khz:
            session['freq_khz'] = freq_khz
            if not session.get('band'):
                session['band'] = freq_to_band(freq_khz)
        else:
            # Step 2: no direct freq. For successful incoming connections,
            # determine if this is a WL2K (Winlink) session.
            #
            # NOTE: this branch only runs when freq_events is non-empty
            # (i.e. BBS-log enrichment is active). If enrichment is
            # disabled we leave band as-is rather than guessing WL2K.
            is_success = (status == 'success')
            if direction == 'incoming' and is_success:
                # WL2K if: confirmed in CMS log, OR no BBS freq entry at all
                # for this callsign (a direct Winlink HF user reaching CMS
                # through our gateway — the dashboard's heuristic).
                wl2k = False
                if cms_events and is_wl2k_session(cms_events, session_start,
                                                  base):
                    wl2k = True
                else:
                    # Not in CMS log. The dashboard still treats an incoming
                    # success with NO BBS freq entry as a direct WL2K HF user.
                    wl2k = True

                if wl2k:
                    session['band'] = 'WL2K'
                    # Infer frequency from nearby RF activity (±30 min)
                    inferred = find_nearest_freq(freq_events, session_start)
                    if inferred:
                        session['freq_khz'] = inferred

    if dry_run:
        return True
    sql = """
    INSERT IGNORE INTO rf_connections
        (callsign, base_call, direction, session_start, session_end,
         duration_sec, freq_khz, band, bandwidth, avg_snr_db,
         tx_bytes, rx_bytes, tx_max_bps, rx_max_bps,
         status, failure_reason, log_line_no)
    VALUES (%s, %s, %s, %s, %s,
            %s, %s, %s, %s, %s,
            %s, %s, %s, %s,
            %s, %s, %s)
    """
    params = (
        session['callsign'],
        session['base_call'],
        session['direction'],
        session['session_start'],
        session.get('session_end'),
        session.get('duration_sec'),
        session.get('freq_khz'),
        session.get('band'),
        session.get('bandwidth'),
        session.get('avg_snr_db'),
        session.get('tx_bytes'),
        session.get('rx_bytes'),
        session.get('tx_max_bps'),
        session.get('rx_max_bps'),
        session.get('status', 'unknown'),
        session.get('failure_reason'),
        log_line_no,
    )
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.rowcount > 0


# ─── Main loop ──────────────────────────────────────────────────────────────


def parse_log(log_path, conn, dry_run=False, verbose=False, bbs_log_dir=None,
              our_callsign=None, messages_days=None, bpq_cfg=None):
    """Walk through new lines of the VARA log, emitting sessions.

    Resumes from the saved checkpoint (rf_parser_state). After parsing,
    saves the new checkpoint so the next run is incremental.

    If bbs_log_dir is given, also walks the BBS log files in that
    directory to capture frequency-setting events. These are matched
    to VARA sessions by (timestamp, base_callsign) and used to
    populate freq_khz and band on the rf_connections rows.

    our_callsign is used to skip our own outbound CMS connections when
    scanning the CMSAccess logs for WL2K sessions. If None, it's derived
    from the VARA log filename (e.g. "k1ajd.vara" -> "K1AJD").
    """
    log_filename = os.path.basename(log_path)
    last_offset, last_line_no, sessions_total = get_parser_state(conn, log_filename)

    # Derive our callsign from the VARA log filename if not given
    if our_callsign is None:
        stem = os.path.splitext(log_filename)[0]
        # Strip common suffixes/SSIDs: "k1ajd", "k1ajd-7", "k1ajd_vara"
        stem = re.split(r'[-_]', stem)[0]
        our_callsign = stem.upper() if stem else None

    # Sanity check — if file shrank (rotated?), restart from beginning
    file_size = os.path.getsize(log_path)
    if file_size < last_offset:
        sys.stderr.write(
            f"WARNING: log file {log_path} is smaller than last checkpoint "
            f"({file_size} < {last_offset}); restarting from beginning\n"
        )
        last_offset = 0
        last_line_no = 0

    # ─── Build frequency + CMS lookups from BBS/CMS logs ──────────────────
    # Scanning these logs is cheap enough (~50-100ms typical) to do every
    # cron run.
    freq_events = []
    cms_events = []
    channel_busy_events = []
    forwarded_messages = []
    connect_mode_events = None
    port_map = {}
    msg_since = None
    if bbs_log_dir:
        # Determine date range: for full reset (last_offset=0), scan all.
        # For incremental, only scan logs from the last 2 days (to catch
        # any session that straddles midnight).
        since = None
        if last_offset > 0:
            since = dt.datetime.utcnow() - dt.timedelta(days=2)
        freq_events = build_freq_lookup(bbs_log_dir, since=since,
                                        verbose=verbose)
        cms_events = build_cms_lookup(bbs_log_dir, our_callsign, since=since,
                                      verbose=verbose)
        channel_busy_events = build_channel_busy_lookup(bbs_log_dir, since=since,
                                                         verbose=verbose)
        # Connect-mode events (VARA/NETROM/AX.25/TELNET per connection) —
        # read from ConnectLog_YYMMDD.log in the same directory. Used
        # only to tag forwarded messages below with their medium; not
        # consumed by session parsing itself.
        # Messages window: normally the same `since`, but
        # --rescan-messages-days widens it (re-tags medium on older rows
        # without touching rf_connections / the VARA checkpoint).
        msg_since = since
        if messages_days:
            msg_since = dt.datetime.utcnow() - dt.timedelta(days=messages_days)
        connect_mode_events = build_connect_mode_lookup(bbs_log_dir, since=msg_since,
                                                         verbose=verbose)
        port_map = parse_bpq_port_map(bpq_cfg, verbose=verbose)
        # Forwarded messages are built AFTER the session loop below (see
        # "Upsert forwarded messages") so the outbound VARA-overlap
        # fallback can see sessions committed in this same run.

    parser = SessionParser()
    sessions_emitted = 0
    sessions_attempted = 0

    # Track the file offset and line number of the START of the current
    # in-progress session (if any). If the parser hits EOF while a session
    # is still open (e.g., cron ran between Connect and Disconnect lines),
    # we rewind the checkpoint to here so the next run re-reads those lines
    # and has a chance to see the Disconnect.
    #
    # INSERT IGNORE on rf_connections makes rewinds safe — any sessions
    # already committed won't be duplicated.
    pending_session_offset = None
    pending_session_line_no = None

    # Use binary mode + readline so f.tell() gives accurate byte offsets
    # for each line. The default for-loop on a text file uses buffered
    # iteration and f.tell() doesn't track line-by-line position correctly.
    with open(log_path, 'rb') as f:
        f.seek(last_offset)
        line_no = last_line_no

        while True:
            line_start_offset = f.tell()
            raw = f.readline()
            if not raw:
                break  # EOF
            line_no += 1
            try:
                line = raw.decode('utf-8', errors='replace').rstrip('\r\n')
            except Exception:
                continue
            if not line:
                continue

            ts, fmt = parse_timestamp(line)
            if ts is None:
                continue  # Unknown line format — skip

            # ── Detect events in order of specificity ────────────────────

            # Bandwidth setting (sticky, persists across sessions)
            m = RE_BANDWIDTH.search(line)
            if m:
                parser.set_bandwidth(int(m.group(1)))
                continue

            # Incoming connection failure — these are FAILURE COUNTERS,
            # not sessions. The original JS parser tracked them with
            # stats.failedIncoming++ and never added them to the connections
            # array. We follow the same logic: close any in-progress session
            # (if one was attempting at the time) but do NOT emit a synthetic
            # UNKNOWN session row, which would clutter the Recent Connections
            # table with "UNKNOWN" entries.
            if RE_INCOMING_FAILED.search(line):
                # If there's an in-progress session, close it as superseded
                aborted = parser.abort_current('superseded')
                if aborted:
                    sessions_attempted += 1
                    if upsert_session(conn, aborted, line_no, dry_run, freq_events, cms_events, channel_busy_events):
                        sessions_emitted += 1
                # Don't emit a row for the bare failure event itself.
                # (A future enhancement could store these in a separate
                # rf_failure_events table for counter purposes.)
                pending_session_offset = None
                pending_session_line_no = None
                continue

            # PORT busy / session in use — tracked as failures
            if RE_PORT_BUSY.search(line):
                aborted = parser.abort_current('port_in_use')
                if aborted:
                    sessions_attempted += 1
                    if upsert_session(conn, aborted, line_no, dry_run, freq_events, cms_events, channel_busy_events):
                        sessions_emitted += 1
                pending_session_offset = None
                pending_session_line_no = None
                continue

            # Note: "VARA is not connected to any App via TCP Port 9025"
            # warnings used to be treated as session-end signals, but real-
            # world data shows these warnings appear periodically as VARA
            # noise EVEN DURING legitimate, successful sessions. Acting on
            # them caused false-positive failed records that overwrote
            # legitimate successes (via UNIQUE constraint on session_start
            # + callsign + INSERT IGNORE). Removed entirely.
            #
            # The Connected→Disconnected pattern remains THE source of truth.
            # For sessions where the Disconnected line never arrives:
            #   - Cron-boundary case: the rewind logic at EOF handles it
            #   - True hung session: stuck-session timeout below catches it
            #     after STUCK_SESSION_TIMEOUT_SEC of log-time inactivity

            # Stuck-session timeout — if the current line's timestamp is
            # more than STUCK_SESSION_TIMEOUT_SEC ahead of the in-progress
            # session's start, the session is effectively dead. Record it
            # as incomplete rather than waiting forever for a Disconnect
            # that will never arrive.
            if (parser.curr is not None
                    and parser.curr.get('session_start') is not None
                    and (ts - parser.curr['session_start']).total_seconds()
                        > STUCK_SESSION_TIMEOUT_SEC):
                session = dict(parser.curr)
                session.pop('confirmed', None)  # internal flag, not a column
                session['session_end'] = ts
                session['duration_sec'] = STUCK_SESSION_TIMEOUT_SEC
                session['status'] = 'failed'
                session['failure_reason'] = 'incomplete_no_disconnect'
                parser.curr = None
                sessions_attempted += 1
                if upsert_session(conn, session, line_no, dry_run, freq_events, cms_events, channel_busy_events):
                    sessions_emitted += 1
                    if verbose:
                        sys.stderr.write(
                            f"  [{line_no}] stuck-session timeout: "
                            f"{session['callsign']} {session['direction']}\n"
                        )
                pending_session_offset = None
                pending_session_line_no = None
                # Fall through — this line might be a NEW session start

            # Connecting (outgoing attempt)
            m = RE_CONNECTING.search(line)
            if m:
                callsign, attempt, max_attempts = m.group(1), m.group(2), m.group(3)
                if attempt == '1':
                    # New attempt — close any prior orphan
                    aborted = parser.abort_current('superseded')
                    if aborted:
                        sessions_attempted += 1
                        if upsert_session(conn, aborted, line_no, dry_run, freq_events, cms_events, channel_busy_events):
                            sessions_emitted += 1
                    parser.start_outgoing(ts, callsign)
                    # Remember where this session started — if we hit EOF
                    # before seeing its Disconnect, we'll rewind here.
                    pending_session_offset = line_start_offset
                    pending_session_line_no = line_no - 1
                # Subsequent retries don't start a new session
                continue

            # Connected (outgoing success)
            m = RE_CONNECTED_OUT.search(line)
            if m:
                # If we already have a curr matching this callsign, keep it
                # (parsed from the earlier Connecting line). If not, create one.
                callsign = m.group(1)
                if parser.curr is None or parser.curr.get('callsign') != callsign:
                    parser.start_outgoing(ts, callsign)
                    # No earlier Connecting line seen — pin pending here
                    if pending_session_offset is None:
                        pending_session_offset = line_start_offset
                        pending_session_line_no = line_no - 1
                # Mark the session confirmed — this protects against false
                # "app-gone" closures during retry-phase noise.
                parser.confirm()
                continue

            # Incoming connection
            m = RE_INCOMING.search(line)
            if m:
                aborted = parser.abort_current('superseded')
                if aborted:
                    sessions_attempted += 1
                    if upsert_session(conn, aborted, line_no, dry_run, freq_events, cms_events, channel_busy_events):
                        sessions_emitted += 1
                parser.start_incoming(ts, m.group(1))
                pending_session_offset = line_start_offset
                pending_session_line_no = line_no - 1
                continue

            # Average S/N
            m = RE_SNR.search(line)
            if m:
                try:
                    snr = float(m.group(2))
                    parser.record_snr(snr)
                except ValueError:
                    pass
                continue

            # Disconnected — completes the current session
            m = RE_DISCONNECTED.search(line)
            if m:
                tx = int(m.group(1))
                tx_bps = int(m.group(2))
                rx = int(m.group(3))
                rx_bps = int(m.group(4))
                dur_min = int(m.group(5))
                dur_sec = int(m.group(6))
                session = parser.complete(ts, tx, rx, tx_bps, rx_bps,
                                          dur_min, dur_sec)
                if session:
                    sessions_attempted += 1
                    if upsert_session(conn, session, line_no, dry_run, freq_events, cms_events, channel_busy_events):
                        sessions_emitted += 1
                        if verbose:
                            sys.stderr.write(
                                f"  [{line_no}] session: {session['callsign']} "
                                f"{session['direction']} {session['status']} "
                                f"tx={tx} rx={rx} snr={session.get('avg_snr_db')}\n"
                            )
                # Session completed — clear the pending checkpoint
                pending_session_offset = None
                pending_session_line_no = None
                continue

        # Track final position
        new_offset = f.tell()
        new_line_no = line_no

    # If we hit EOF with a session still in-flight (Connect/Connected seen
    # but no Disconnect yet), rewind the checkpoint to where that session
    # STARTED. The next parser run will re-read those lines, hopefully now
    # with the Disconnect line present in the log. INSERT IGNORE makes the
    # re-read safe — already-committed sessions won't duplicate.
    #
    # This fixes the bug where a session straddling a parser-invocation
    # boundary (e.g., cron ran between Connect and Disconnect) would be
    # silently dropped because `parser.curr` state doesn't persist across
    # process invocations.
    rewound = False
    if pending_session_offset is not None and parser.curr is not None:
        if verbose or True:  # always log this — it's diagnostically important
            sys.stderr.write(
                f"NOTE: in-flight session at EOF — rewinding checkpoint from "
                f"offset {new_offset} (line {new_line_no}) back to "
                f"{pending_session_offset} (line {pending_session_line_no}) "
                f"so next run can re-process and find the Disconnect line.\n"
            )
        new_offset = pending_session_offset
        new_line_no = pending_session_line_no
        rewound = True

    # Upsert forwarded messages — independent of VARA session parsing (pure
    # BBS-log data), so this doesn't participate in the offset/checkpoint
    # tracking above; it just re-scans the same `since` window every run,
    # same as channel_busy_events. The UNIQUE KEY on (bid, direction) makes
    # this idempotent regardless of how much the window overlaps prior runs.
    if bbs_log_dir:
        vara_sessions = load_outgoing_vara_sessions(
            conn, since=msg_since)
        forwarded_messages = build_forwarded_messages(
            bbs_log_dir, since=msg_since, verbose=verbose,
            connect_events=connect_mode_events,
            vara_sessions=vara_sessions, port_map=port_map)

    messages_emitted = 0
    for msg in forwarded_messages:
        if upsert_message(conn, msg, dry_run):
            messages_emitted += 1
    if not dry_run and forwarded_messages:
        conn.commit()

    # Persist parser state
    sessions_total_new = sessions_total + sessions_emitted
    if not dry_run:
        save_parser_state(conn, log_filename, new_offset, new_line_no,
                          sessions_total_new)
        conn.commit()

    return {
        'sessions_attempted': sessions_attempted,
        'sessions_emitted': sessions_emitted,
        'sessions_total': sessions_total_new,
        'messages_emitted': messages_emitted,
        'lines_read': new_line_no - last_line_no,
        'offset_start': last_offset,
        'offset_end': new_offset,
        'rewound': rewound,
    }


def main():
    ap = argparse.ArgumentParser(
        description='Parse VARA HF log into rf_connections table',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )
    ap.add_argument('--log', default=DEFAULT_LOG_PATH,
                    help=f'Path to VARA log file (default: {DEFAULT_LOG_PATH})')
    ap.add_argument('--reset', action='store_true',
                    help='Wipe parser state AND rf_connections, re-parse from scratch')
    ap.add_argument('--dry-run', action='store_true',
                    help='Show what would be written, don\'t actually INSERT')
    ap.add_argument('--verbose', '-v', action='store_true',
                    help='Print each session as parsed')
    ap.add_argument('--db-host')
    ap.add_argument('--db-port', type=int)
    ap.add_argument('--db-name')
    ap.add_argument('--db-user')
    ap.add_argument('--db-pass')
    ap.add_argument('--bbs-log-dir',
                    help='Directory containing BBS log_YYMMDD_BBS.txt files '
                         '(for frequency enrichment) and CMSAccess_*.log '
                         'files (for WL2K detection). Default: same dir as '
                         'the VARA log file. Pass empty string to disable.')
    ap.add_argument('--bpq-cfg',
                    help='Path to bpq32.cfg, used to tell which port an '
                         'outbound forwarding connect script used (VARA / '
                         'AXIP / TELNET / KISS). Default: <config.php '
                         'paths.linbpq>/bpq32.cfg. Optional — without it, '
                         'outbound messages fall back to VARA-session overlap.')
    ap.add_argument('--rescan-messages-days', type=int, metavar='N',
                    help='Re-scan the last N days of BBS logs for forwarded '
                         'messages and re-tag their medium (delivery method). '
                         'Does not touch rf_connections or the VARA log '
                         'checkpoint — use once after upgrading.')
    ap.add_argument('--my-callsign',
                    help='Your station base callsign (e.g. W1AW). Used to '
                         'skip your own outbound CMS connections in WL2K '
                         'detection. Default: derived from VARA log filename.')
    args = ap.parse_args()

    # Override DB config from flags
    for key, val in [('host', args.db_host), ('port', args.db_port),
                     ('database', args.db_name), ('user', args.db_user),
                     ('password', args.db_pass)]:
        if val is not None:
            DB_CONFIG[key] = val

    log_path = args.log
    if not log_path:
        sys.stderr.write("ERROR: no VARA log configured — pass --log, or set "
                         "paths.logs + logs.vara_file in config.php\n")
        sys.exit(1)
    if not os.path.exists(log_path):
        sys.stderr.write(f"ERROR: log file not found: {log_path}\n")
        sys.exit(1)

    # Determine BBS log directory
    if args.bbs_log_dir is None:
        # Default: same directory as the VARA log
        bbs_log_dir = os.path.dirname(os.path.abspath(log_path))
    elif args.bbs_log_dir == '':
        # Explicitly disabled
        bbs_log_dir = None
    else:
        bbs_log_dir = args.bbs_log_dir

    conn = db_connect()

    try:
        if args.reset:
            sys.stderr.write(
                "RESETTING: clearing rf_parser_state and rf_connections...\n"
            )
            if not args.dry_run:
                reset_parser_state(conn, os.path.basename(log_path))

        result = parse_log(log_path, conn, dry_run=args.dry_run,
                           verbose=args.verbose, bbs_log_dir=bbs_log_dir,
                           our_callsign=args.my_callsign,
                           messages_days=args.rescan_messages_days,
                           bpq_cfg=args.bpq_cfg or bpq_db_config.default_linbpq_path('bpq32.cfg'))

        sys.stdout.write(
            f"Parsed {result['lines_read']} new lines from {log_path}\n"
            f"  Sessions emitted: {result['sessions_emitted']}"
            f" (attempted: {result['sessions_attempted']}, "
            f"running total: {result['sessions_total']})\n"
            f"  Messages emitted: {result['messages_emitted']}\n"
            f"  Bytes read:       {result['offset_start']} → {result['offset_end']}\n"
        )
        if args.dry_run:
            sys.stdout.write("  [DRY RUN — no DB writes performed]\n")
    finally:
        conn.close()


if __name__ == '__main__':
    main()
