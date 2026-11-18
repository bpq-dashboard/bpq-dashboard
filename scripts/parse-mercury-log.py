#!/usr/bin/env python3
"""
parse-mercury-log.py — Mercury HF modem calls into rf_connections (v1.5.46)

Reads Mercury's own log (default /var/log/mercury/mercury.log, config.php
mercury.log) and writes one row per call to the rf_connections table with
modem = 'mercury', next to the VARA rows that parse-vara-log.py writes.
RF Connections shows them in Recent connections, the partner strips, the
7-day history and the activity chart. The headline numbers stay VARA-only
unless config.php mercury.in_headlines = true.

What one row is:
  incoming  "Incoming connection from W1ABC on N0CALL-7 (pending)" starts it.
            "Incoming connection cancelled" before a link   -> failed, incoming_failed
            "Connected to W1ABC" ... "Disconnected"          -> success, or
                                       failed/no_data when nothing was received
  outgoing  "Command received: CONNECT N0CALL-7 W1ABC" (LinBPQ placed a call)
            "Disconnected" without "Connected to"           -> failed, no_answer
  Mercury's timing lines add the link mode (DATAC16 ...), bytes each way and
  how the link ended (peer_ack, rx_disconnect, timeout).

Frequency and bandwidth come from LinBPQ's BBS log (log_YYMMDD_BBS.txt):
  "Incoming Connect from W1ABC on Port 5 Freq 7102400 Mode VARA[ 500]"
  ">W1ABC RADIO 7.100900 PKT-U"  (outgoing; the dial frequency)
Bandwidth is otherwise the last BW command LinBPQ sent (BW2300 / BW500).

Incremental: the byte offset reached is kept in rf_parser_state under the
log's file name. A call still open at the end of the file is read again
next run (rows are INSERT IGNORE on session_start + callsign). When the
log has rotated (it is smaller than the offset), the rest of <log>.1 is
read first. The first run reads <log>.1 and then the log.

Usage:
  parse-mercury-log.py                 # normal (cron, via parse-vara-log.py)
  parse-mercury-log.py --dry-run -v    # show the rows, write nothing
  parse-mercury-log.py --reset         # delete Mercury rows + checkpoint, re-read
  parse-mercury-log.py --backfill-link # fill link_sec (time connected) on stored rows (v1.5.64)
  parse-mercury-log.py --log FILE --bbs-log-dir DIR

parse-vara-log.py runs this after itself every 5 minutes when Mercury's log
is there, so no extra cron line is needed. Exit codes: 0 ok, 1 no log,
2 database, 3 database not upgraded (run update.sh / the migration).
"""
import argparse
import bisect
import datetime as dt
import os
import re
import sys

try:
    import pymysql
except ImportError:
    pymysql = None

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402

_MC = bpq_db_config.load_config_section('mercury')
DEFAULT_LOG = _MC.get('log') or '/var/log/mercury/mercury.log'

DB_CONFIG = bpq_db_config.load_db_config({
    'connect_timeout': 10,
    'charset': 'utf8mb4',
    'autocommit': False,
})

# 2026-11-14 10:53:12.765Z [+4.018s] [INF] [tcp-ctl] Command received: BW2300
RE_LINE = re.compile(
    r'^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:\.\d+)?(Z?)\s+'
    r'(?:\[\+[\d.]+s\]\s+)?\[(\w{3})\]\s+\[([^\]]*)\]\s?(.*)$')
RE_INCOMING = re.compile(r'^Incoming connection from (\S+) on (\S+)')
RE_CANCELLED = re.compile(r'^Incoming connection (?:cancelled|canceled|failed|rejected)')
RE_CONNECT_CMD = re.compile(r'^Command received: CONNECT (\S+) (\S+)')
RE_BW_CMD = re.compile(r'^Command received: BW(\d+)')
RE_CONNECTED = re.compile(r'^Connected to (\S+)')
RE_DISCONNECTED = re.compile(r'^Disconnected\b')
RE_TMG_CONNECT = re.compile(r'^connect mode=(\S+)')
RE_TMG_DISC = re.compile(r'^disconnect reason=(\S+)(?: tx_bytes=(\d+))?(?: rx_bytes=(\d+))?')
RE_STARTUP = re.compile(r'^Mercury \d')
RE_CTL_GONE = re.compile(r'^Control client (?:disconnected|closed)')

# LinBPQ BBS log
RE_BBS_IN = re.compile(
    r'^(\d{6}) (\d\d):(\d\d):(\d\d) \|\S+\s+Incoming Connect from (\S+) on Port (\d+)'
    r' Freq (\d+) Mode (\S+)(?: (\d+))?')
RE_BBS_RADIO = re.compile(r'^(\d{6}) (\d\d):(\d\d):(\d\d) >(\S+)\s+RADIO (\d+\.\d+)')

# Times are stored as written, like parse-vara-log.py does: Mercury's log
# (log_utc = true writes UTC, marked Z) and LinBPQ's BBS log are matched
# on the same clock. Keep both on UTC (a server clock on UTC does that).


def freq_to_band(freq_khz):
    """Same table as parse-vara-log.py."""
    if not freq_khz:
        return None
    f = freq_khz
    for lo, hi, name in ((1800, 2000, '160m'), (3500, 4000, '80m'), (5300, 5450, '60m'),
                         (7000, 7300, '40m'), (10100, 10150, '30m'), (14000, 14350, '20m'),
                         (18068, 18168, '17m'), (21000, 21450, '15m'), (24890, 24990, '12m'),
                         (28000, 29700, '10m')):
        if lo <= f <= hi:
            return name
    return 'Unknown'


def base_call(call):
    return (call or '').upper().split('-')[0][:10]


# ---------------------------------------------------------------- reading
def read_from(path, offset):
    """Yield (line_start_offset, line_text) from offset to the end of the
    last complete line."""
    with open(path, 'rb') as f:
        f.seek(offset)
        pos = offset
        for raw in f:
            if not raw.endswith(b'\n'):
                break                      # half-written last line: next run
            yield pos, raw.decode('utf-8', 'replace').rstrip('\r\n')
            pos += len(raw)


class Parser:
    """Turns Mercury log lines into call rows."""

    def __init__(self):
        self.cur = None          # the open call
        self.bw = 2300           # last BW command
        self.rows = []

    def _ts(self, m):
        return dt.datetime.strptime(m.group(1), '%Y-%m-%d %H:%M:%S')

    def _close(self, t, status=None, reason=None, end_reason=None):
        c = self.cur
        self.cur = None
        if not c:
            return
        c['end'] = t
        if c.get('connected_at'):
            rx = c.get('rx_bytes') or 0
            if status is None:
                status, reason = ('success', None) if rx > 0 else ('failed', 'no_data')
        else:
            if status is None:
                status = 'failed'
                reason = 'incoming_failed' if c['direction'] == 'incoming' else 'no_answer'
        c['status'] = status
        c['failure_reason'] = reason if status != 'success' else None
        if end_reason and not c.get('end_reason'):
            c['end_reason'] = end_reason
        if c.get('connected_at'):                  # v1.5.64: seconds the link was up
            c['link_sec'] = max(0, int((t - c['connected_at']).total_seconds()))
        self.rows.append(c)

    def _open(self, t, direction, peer, off, line_no):
        self.cur = {'start': t, 'direction': direction, 'callsign': peer.upper()[:16],
                    'bw': self.bw, 'start_offset': off, 'start_line': line_no}

    def feed(self, off, line_no, line):
        m = RE_LINE.match(line)
        if not m:
            return
        t = self._ts(m)
        lvl, tag, msg = m.group(3), m.group(4), m.group(5)
        c = self.cur
        # v1.5.55: "Disconnected" can come just BEFORE the TMG "disconnect
        # reason=… tx_bytes=… rx_bytes=…" line (seen 2026-11-15 17:49:10,
        # an ABORT after LinBPQ's DISCONNECT). The call is held open after
        # "Disconnected" until that line, or any other line, arrives.
        if c and c.get('_disc_at') and not (lvl == 'TMG' and RE_TMG_DISC.match(msg)):
            self._close(c['_disc_at'])
            c = None
        if lvl == 'TMG':
            if not c:
                return
            mm = RE_TMG_CONNECT.match(msg)
            if mm:
                c['link_mode'] = mm.group(1)[:12]
                return
            mm = RE_TMG_DISC.match(msg)
            if mm:
                c['end_reason'] = mm.group(1)[:24]
                if mm.group(2) is not None:
                    c['tx_bytes'] = int(mm.group(2))
                if mm.group(3) is not None:
                    c['rx_bytes'] = int(mm.group(3))
                if c.get('_disc_at'):
                    self._close(c['_disc_at'])
            return
        if RE_STARTUP.match(msg) and tag == 'main':
            if c:
                self._close(t, 'aborted', 'modem_restart')
            return
        mm = RE_BW_CMD.match(msg)
        if mm:
            self.bw = int(mm.group(1))
            return
        mm = RE_INCOMING.match(msg)
        if mm:
            if c:
                self._close(t)
            self._open(t, 'incoming', mm.group(1), off, line_no)
            return
        mm = RE_CONNECT_CMD.match(msg)
        if mm:
            if c:
                self._close(t)
            self._open(t, 'outgoing', mm.group(2), off, line_no)
            return
        if RE_CANCELLED.match(msg):
            if c and not c.get('connected_at'):
                self._close(t, 'failed', 'incoming_failed' if c['direction'] == 'incoming' else 'no_answer')
            return
        mm = RE_CONNECTED.match(msg)
        if mm:
            if not c:
                self._open(t, 'unknown', mm.group(1), off, line_no)
                c = self.cur
            c['connected_at'] = t
            return
        if RE_DISCONNECTED.match(msg):
            if c:
                if c.get('end_reason') is not None or c.get('rx_bytes') is not None:
                    self._close(t)               # TMG line already seen
                else:
                    c['_disc_at'] = t            # wait one line for the TMG line
            return
        if RE_CTL_GONE.match(msg) and c:
            # LinBPQ let go of Mercury (restart) in the middle of a call
            self._close(t, 'aborted', 'linbpq_restart')

    def finish(self):
        """End of input: a call waiting only for its TMG line is closed."""
        if self.cur and self.cur.get('_disc_at'):
            self._close(self.cur['_disc_at'])

    def open_offset(self):
        return self.cur['start_offset'] if self.cur else None


# ---------------------------------------------------------------- BBS log
def load_bbs(bbs_dir, rows):
    """Incoming-connect and RADIO events around the rows' dates."""
    if not bbs_dir or not os.path.isdir(bbs_dir) or not rows:
        return [], []
    days = set()
    for r in rows:
        for d in (r['start'], r.get('end') or r['start']):
            days.add(d.strftime('%y%m%d'))
    inc, radio = [], []
    for day in sorted(days):
        p = os.path.join(bbs_dir, f'log_{day}_BBS.txt')
        try:
            fh = open(p, 'r', encoding='utf-8', errors='replace')
        except OSError:
            continue
        with fh:
            for line in fh:
                if 'Incoming Connect' not in line and 'RADIO' not in line:
                    continue
                m = RE_BBS_IN.match(line)
                if m:
                    t = _bbs_ts(m)
                    if t:
                        bw = 500 if (m.group(9) == '500') else None
                        inc.append((t, base_call(m.group(5)), int(m.group(6)),
                                    round(int(m.group(7)) / 1000.0, 2), bw))
                    continue
                m = RE_BBS_RADIO.match(line)
                if m:
                    t = _bbs_ts(m)
                    if t:
                        radio.append((t, base_call(m.group(5)), round(float(m.group(6)) * 1000, 2)))
    inc.sort(key=lambda e: e[0])
    radio.sort(key=lambda e: e[0])
    return inc, radio


def _bbs_ts(m):
    try:
        return dt.datetime.strptime(m.group(1) + m.group(2) + m.group(3) + m.group(4), '%y%m%d%H%M%S')
    except ValueError:
        return None


def _near(events, t, base, before, after):
    keys = [e[0] for e in events]
    i = bisect.bisect_left(keys, t - dt.timedelta(seconds=before))
    best = None
    while i < len(events) and events[i][0] <= t + dt.timedelta(seconds=after):
        if events[i][1] == base:
            if best is None or abs((events[i][0] - t).total_seconds()) < abs((best[0] - t).total_seconds()):
                best = events[i]
        i += 1
    return best


def enrich(rows, inc, radio):
    for r in rows:
        b = base_call(r['callsign'])
        r['freq_khz'] = None
        r['bandwidth'] = r.get('bw')
        if r['direction'] == 'outgoing':
            e = _near(radio, r['start'], b, 180, 5)
            if e:
                r['freq_khz'] = e[2]
        anchor = r.get('connected_at') or r['start']
        e = _near(inc, anchor, b, 60, 60)
        if e:
            r['freq_khz'] = e[3]
            if e[4]:
                r['bandwidth'] = e[4]
            r['bbs_port'] = e[2]
        r['band'] = freq_to_band(r['freq_khz'])


# ---------------------------------------------------------------- database
def db_connect():
    if pymysql is None:
        sys.stderr.write("ERROR: python3-pymysql is not installed\n")
        sys.exit(2)
    try:
        bpq_db_config.warn_if_no_password(DB_CONFIG)
        return pymysql.connect(**DB_CONFIG)
    except pymysql.MySQLError as e:
        sys.stderr.write(f"ERROR: Database connection failed: {e}\n")
        sys.exit(2)


def has_columns(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'")
        cols = {r[0] for r in cur.fetchall()}
    return {'modem', 'link_mode', 'end_reason'} <= cols


def get_state(conn, key):
    with conn.cursor() as cur:
        cur.execute("SELECT last_offset, last_line_no, sessions_total FROM rf_parser_state "
                    "WHERE log_file = %s", (key,))
        r = cur.fetchone()
    return (int(r[0]), int(r[1]), int(r[2])) if r else None


def save_state(conn, key, offset, line_no, total):
    with conn.cursor() as cur:
        cur.execute("INSERT INTO rf_parser_state (log_file, last_offset, last_line_no, sessions_total) "
                    "VALUES (%s,%s,%s,%s) ON DUPLICATE KEY UPDATE last_offset=VALUES(last_offset), "
                    "last_line_no=VALUES(last_line_no), sessions_total=VALUES(sessions_total)",
                    (key, offset, line_no, total))


def has_link_sec(conn):
    """rf_connections.link_sec exists (sql/2026-11-18-rf-connections-link-sec.sql, v1.5.64)."""
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
                    "AND TABLE_NAME = 'rf_connections' AND COLUMN_NAME = 'link_sec'")
        return cur.fetchone()[0] > 0


def backfill_link_sec(conn, modem, rows):
    """v1.5.64: fill link_sec on rows already stored (matched on start + callsign), never overwriting."""
    n = 0
    with conn.cursor() as cur:
        for r in rows:
            if r.get('link_sec') is None:
                continue
            n += cur.execute("UPDATE rf_connections SET link_sec = %s WHERE modem = %s AND session_start = %s "
                             "AND callsign = %s AND link_sec IS NULL", (r['link_sec'], modem, r['start'], r['callsign']))
    return n


def insert_rows(conn, rows):
    n = 0
    link = has_link_sec(conn)
    sql = ("INSERT IGNORE INTO rf_connections (callsign, base_call, direction, modem, session_start, "
           "session_end, duration_sec, freq_khz, band, bandwidth, link_mode, tx_bytes, rx_bytes, "
           "status, failure_reason, end_reason, log_line_no" + (", link_sec" if link else "") + ") "
           "VALUES (%s,%s,%s,'mercury',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s" + (",%s" if link else "") + ")")
    with conn.cursor() as cur:
        for r in rows:
            n += cur.execute(sql, row_values(r) + ((r.get('link_sec'),) if link else ()))
    return n


def row_values(r):
    dur = int((r['end'] - r['start']).total_seconds()) if r.get('end') else None
    return (r['callsign'], base_call(r['callsign']), r['direction'], r['start'], r.get('end'),
            dur, r.get('freq_khz'), r.get('band'), r.get('bandwidth'), r.get('link_mode'),
            r.get('tx_bytes'), r.get('rx_bytes'), r['status'], r.get('failure_reason'),
            r.get('end_reason'), r.get('end_line'))


# ---------------------------------------------------------------- main
def parse_files(log, state):
    """Run the parser over what's new. Returns (rows, new_offset, new_line_no)."""
    p = Parser()
    rotated = log + '.1'
    size = os.path.getsize(log)
    plan = []                                  # (path, offset, line_no)
    if state is None:
        if os.path.exists(rotated):
            plan.append((rotated, 0, 0))
        plan.append((log, 0, 0))
    else:
        off, ln, _ = state
        if size < off:                         # rotated since last run
            if os.path.exists(rotated) and os.path.getsize(rotated) >= off:
                plan.append((rotated, off, ln))
            plan.append((log, 0, 0))
        else:
            plan.append((log, off, ln))
    end_off, end_ln = 0, 0
    for path, off, ln in plan:
        last_off, line_no = off, ln
        for pos, line in read_from(path, off):
            line_no += 1
            before = len(p.rows)
            p.feed(pos, line_no, line)
            for r in p.rows[before:]:
                r['end_line'] = line_no
            last_off = pos + len(line.encode('utf-8', 'replace')) + 1
        if path == log:
            end_off, end_ln = last_off, line_no
            if p.cur and p.cur.get('start_line') is not None and p.cur.get('_path', log) == log:
                # call still open: come back to its first line next time
                end_off, end_ln = p.cur['start_offset'], p.cur['start_line'] - 1
        else:
            if p.cur:                           # a call that spans the rotation
                p.cur['_path'] = path
    if p.cur and p.cur.get('_path') and p.cur['_path'] != log:
        # open call began in the rotated file: can't come back to it, so
        # end it at the last line we saw.
        p._close(p.cur.get('connected_at') or p.cur['start'])
    return p.rows, end_off, end_ln


def main():
    ap = argparse.ArgumentParser(description='Parse the Mercury HF log into rf_connections',
                                 formatter_class=argparse.RawDescriptionHelpFormatter, epilog=__doc__)
    ap.add_argument('--log', default=DEFAULT_LOG, help=f'Mercury log (default {DEFAULT_LOG})')
    ap.add_argument('--bbs-log-dir', default=None,
                    help='Folder with LinBPQ log_YYMMDD_BBS.txt (default config.php paths.logs); '
                         'empty string = no frequencies')
    ap.add_argument('--reset', action='store_true', help='Delete Mercury rows and the checkpoint, re-read')
    ap.add_argument('--backfill-link', action='store_true',
                    help='Re-read the log (and <log>.1) and fill link_sec on rows already stored; nothing else changes (v1.5.64)')
    ap.add_argument('--dry-run', action='store_true', help="Show what would be written, write nothing")
    ap.add_argument('--verbose', '-v', action='store_true')
    ap.add_argument('--quiet', '-q', action='store_true', help='No output when nothing is new or there is no log')
    args = ap.parse_args()

    log = args.log
    if not os.path.exists(log):
        if not args.quiet:
            sys.stderr.write(f"No Mercury log at {log} — nothing to do\n")
        sys.exit(1)
    bbs_dir = bpq_db_config.default_logs_dir() if args.bbs_log_dir is None else (args.bbs_log_dir or None)
    key = os.path.basename(log)

    if args.dry_run:
        conn = None                            # a dry run reads from the start if it can't
        if pymysql is not None:                # reach the database
            try:
                conn = pymysql.connect(**DB_CONFIG)
            except pymysql.MySQLError:
                sys.stderr.write("(no database — dry run reads the whole log)\n")
    else:
        conn = db_connect()
    try:
        if conn is not None and not has_columns(conn):
            sys.stderr.write("ERROR: rf_connections has no modem/link_mode/end_reason columns yet — "
                             "run update.sh (or sql/2026-11-14-rf-connections-modem.sql)\n")
            sys.exit(3)
        if args.reset and not args.dry_run:
            with conn.cursor() as cur:
                cur.execute("DELETE FROM rf_connections WHERE modem = 'mercury'")
                cur.execute("DELETE FROM rf_parser_state WHERE log_file = %s", (key,))
            conn.commit()
            sys.stderr.write("RESET: Mercury rows and checkpoint cleared\n")
        if args.backfill_link and not args.dry_run:
            if not has_link_sec(conn):
                sys.stderr.write("ERROR: rf_connections has no link_sec yet — run update.sh "
                                 "(or sql/2026-11-18-rf-connections-link-sec.sql)\n")
                sys.exit(3)
            rows, _, _ = parse_files(log, None)
            n = backfill_link_sec(conn, 'mercury', rows)
            conn.commit()
            print(f"Mercury: {len(rows)} call(s) read, link_sec filled on {n} stored row(s)")
            return
        state = None if (args.reset or conn is None) else get_state(conn, key)
        rows, off, ln = parse_files(log, state)
        inc, radio = load_bbs(bbs_dir, rows)
        enrich(rows, inc, radio)
        if args.verbose or args.dry_run:
            for r in rows:
                print(f"  {r['start']:%Y-%m-%d %H:%M:%S} {r['direction']:<8} {r['callsign']:<10} "
                      f"{r['status']:<7} {r.get('failure_reason') or '':<16} "
                      f"{(str(r['freq_khz']) + ' kHz') if r.get('freq_khz') else '-':<10} "
                      f"bw={r.get('bandwidth')} mode={r.get('link_mode') or '-'} "
                      f"tx={r.get('tx_bytes')} rx={r.get('rx_bytes')} end={r.get('end_reason') or '-'}")
        n = 0
        if not args.dry_run:
            n = insert_rows(conn, rows)
            total = ((state[2] if state else 0) + n)
            save_state(conn, key, off, ln, total)
            conn.commit()
        if rows or not args.quiet:
            print(f"Mercury: {len(rows)} call(s) read, {n} new row(s), checkpoint {off} bytes"
                  + ("  [DRY RUN]" if args.dry_run else ''))
    finally:
        if conn is not None:
            conn.close()


if __name__ == '__main__':
    main()
