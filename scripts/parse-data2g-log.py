#!/usr/bin/env python3
"""
parse-data2g-log.py — Data2G HF modem calls into rf_connections (v1.5.56)

Reads Data2G's log (default /var/log/data2g/data2g.log, config.php
data2g.log: data2g-host's or data2g-web's output, which the data2g service
appends there) and writes one row per call to rf_connections with
modem = 'data2g', next to the VARA and Mercury rows. RF Connections shows
them in Recent connections, the partner strips, the 7-day history, the
activity chart and the Station Map. The headline numbers stay VARA-only
unless config.php data2g.in_headlines = true.

What one row is (lines as Data2G 0.5 writes them):
  outgoing  "command: CONNECT N0CALL-7 W1ABC"     LinBPQ placed a call
            "RX CONNECT_ACK <mode>"               the link is up
            "DISCONNECTED NO_ANSWER" / BUSY / NOT_LISTENING / REFUSED / VERSION
                                                  -> failed (no_answer, busy ...)
  incoming  "RX CONNECT W1ABC>N0CALL-7 <mode>" for one of our calls (the
            MYCALL list LinBPQ sent), then "TX CONNECT_ACK"   the link is up
            "refusing CONNECT from W1ABC: BUSY"   -> failed, incoming_failed
            (a caller's repeated tries within 2 minutes make one row).
            Data2G logs every CONNECT it hears, so one counts as ours only
            when the next line answers or refuses it.
  the end   "session 95 s: tx 1085 B acked (835 bps ...), rx 380 B (292 bps ...)"
            then "DISCONNECTED CLIENT" (we hung up) / PEER (they did) /
            LINK_FAILED ... / PEER_RECONNECTED / "... unconfirmed"
            -> success when anything was received, else failed/no_data.
            "command: ABORT" ends it without a session line: the totals
            then come from the "stats" lines (--stats-interval) or, without
            them, from whether data bursts went either way.
  "command client gone" mid-call (LinBPQ let go) -> aborted, linbpq_restart;
  Data2G starting again mid-call                 -> aborted, modem_restart.
The link mode is the waveform of the data bursts used most (not the
polls and acks) (w48-256l-r5/8 ...); the S/N
is the average of the bursts heard from the other station during the call;
tx_max_bps / rx_max_bps are the best rate of the stats/session lines.

Frequency and bandwidth come from LinBPQ's BBS log, as for Mercury
("Incoming Connect from W1ABC on Port 6 Freq 7102400 Mode VARA",
">W1ABC RADIO 7.100900 ..." for outgoing calls).
v1.5.91: a Winlink user calling the RMS gateway call never reaches the BBS,
so there is no BBS line. Such an incoming call that the gateway log
(CMSAccess_YYYYMMDD.log: "HH:MM:SS n W1ABC Connected to CMS") shows within
2 minutes gets band 'WL2K', as VARA's Winlink sessions do. A call left
without a frequency takes the dial frequency the radio was on while it
transmitted during the call (rig-history.jsonl in paths.datalog, written
every 3 s while transmitting by rig-history-poller), the most common one.

Times: Data2G writes the server's local time without a zone; they are
stored as written, like the other parsers (keep the server on UTC; the
data2g service also sets TZ=UTC).

Incremental: byte offset in rf_parser_state under the log's file name; a
call still open at the end of the file is read again next run (rows are
INSERT IGNORE on session_start + callsign). After logrotate (copytruncate:
the log is smaller than the offset) the rest of <log>.1 is read first.

Usage:
  parse-data2g-log.py                 # normal (cron, via parse-vara-log.py)
  parse-data2g-log.py --dry-run -v    # show the rows, write nothing
  parse-data2g-log.py --reset         # delete Data2G rows + checkpoint, re-read
  parse-data2g-log.py --backfill-link # fill link_sec (time connected) on stored rows (v1.5.64)
  parse-data2g-log.py --backfill-freq # Winlink label + frequency on stored rows (v1.5.91)
  parse-data2g-log.py --log FILE --bbs-log-dir DIR

Exit codes: 0 ok, 1 no log, 2 database, 3 database not upgraded.
"""
import argparse
import collections
import datetime as dt
import importlib.util
import os
import re
import sys

try:
    import pymysql
except ImportError:
    pymysql = None

_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _HERE)
import bpq_db_config  # noqa: E402
import winlink_match as wm  # noqa: E402

# The BBS-log matching, band table and checkpoint helpers are Mercury's.
_spec = importlib.util.spec_from_file_location('parse_mercury_log', os.path.join(_HERE, 'parse-mercury-log.py'))
merc = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(merc)

_DC = bpq_db_config.load_config_section('data2g')
DEFAULT_LOG = _DC.get('log') or '/var/log/data2g/data2g.log'
DB_CONFIG = merc.DB_CONFIG
MODEM = 'data2g'

# 2026-11-15 20:33:10,578 INFO command: MYCALL N0CALL-4 N0CALL-5
RE_LINE = re.compile(r'^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)(?:[,.]\d+)?\s+([A-Z]+)\s+(.*)$')
RE_MYCALL = re.compile(r'^command: MYCALL (.+)$')
RE_BW = re.compile(r'^command: BW(\d+)')
RE_CONNECT_CMD = re.compile(r'^command: CONNECT (\S+) (\S+)')
RE_ABORT_CMD = re.compile(r'^command: ABORT\b')
RE_RX_CONNECT = re.compile(r'^RX CONNECT (\S+)>(\S+)')
RE_RX_ACK = re.compile(r'^RX CONNECT_ACK\b')
RE_TX_ACK = re.compile(r'^TX CONNECT_ACK\b')
RE_REFUSING = re.compile(r'^refusing CONNECT from (\S+): (\S+)')
RE_BURST = re.compile(r'^(TX|RX) b\d+ (\w+) (\S+) x\d+')
RE_SNR = re.compile(r'\| snr (-?[\d.]+) dB')
RE_STATS = re.compile(r'^(session|stats) (\d+) s: tx (\d+) B acked \((\d+) bps[^)]*\), rx (\d+) B \((\d+) bps')
RE_DISCONNECTED = re.compile(r'^DISCONNECTED ?(.*)$')
RE_CLIENT_GONE = re.compile(r'^command client gone')
RE_STARTUP = re.compile(r'^(?:audio: in |commands on )')

NAK_REASON = {'NO_ANSWER': 'no_answer', 'BUSY': 'busy', 'NOT_LISTENING': 'not_listening',
              'REFUSED': 'refused', 'VERSION': 'version_mismatch'}
REFUSAL_GROUP_S = 120


def end_reason_of(why):
    """'CLIENT' -> client, 'LINK_FAILED no reply' -> link_failed, 'CLIENT unconfirmed' -> client_unconfirmed."""
    w = (why or '').strip().split()
    if not w:
        return None
    r = w[0].lower()
    if len(w) > 1 and w[-1].lower() == 'unconfirmed':
        r += '_unconfirmed'
    return r[:24]


class Parser:
    """Turns Data2G log lines into call rows."""

    def __init__(self):
        self.cur = None            # the open call
        self.refused = None        # an incoming call we turned away, grouped by caller
        self.bw = 2300
        self.calls = set()         # our callsigns (LinBPQ's MYCALL list)
        self.rows = []

    # ---- rows
    def _open(self, t, direction, peer, off, line_no):
        self.cur = {'start': t, 'direction': direction, 'callsign': peer.upper()[:16], 'bw': self.bw,
                    'start_offset': off, 'start_line': line_no, 'modes': collections.Counter(),
                    'snr': [], 'data_tx': 0, 'data_rx': 0, 'stats_tx': 0, 'stats_rx': 0,
                    'tx_bps': 0, 'rx_bps': 0}

    def _close(self, t, status=None, reason=None, end_reason=None):
        c, self.cur = self.cur, None
        if not c:
            return
        c['end'] = t
        if end_reason and not c.get('end_reason'):
            c['end_reason'] = end_reason
        if c.get('tx_bytes') is None and (c['stats_tx'] or c['stats_rx']):
            c['tx_bytes'], c['rx_bytes'] = c['stats_tx'], c['stats_rx']   # ABORT: what the stats lines saw
        if c.get('connected_at'):
            if status is None:
                rx = c.get('rx_bytes')
                moved = (rx or 0) > 0 or (rx is None and (c['data_rx'] or c['data_tx']))
                status, reason = ('success', None) if moved else ('failed', 'no_data')
        elif status is None:
            status = 'failed'
            reason = reason or ('incoming_failed' if c['direction'] == 'incoming' else 'no_answer')
        c['status'] = status
        c['failure_reason'] = reason if status != 'success' else None
        if c['modes']:
            best = max(c['modes'].values())
            c['link_mode'] = [m for m, n in c['modes'].items() if n == best][-1][:24]
        if c['snr']:
            c['avg_snr'] = round(sum(c['snr']) / len(c['snr']), 1)
        c['tx_max_bps'] = c['tx_bps'] or None
        c['rx_max_bps'] = c['rx_bps'] or None
        if c.get('connected_at'):                  # v1.5.64: seconds the link was up
            c['link_sec'] = c.get('session_s') or max(0, int((t - c['connected_at']).total_seconds()))
        self.rows.append(c)

    def _flush_refused(self):
        r, self.refused = self.refused, None
        if r:
            r.update(status='failed', failure_reason='incoming_failed')
            self.rows.append(r)

    # ---- lines
    def feed(self, off, line_no, line):
        m = RE_LINE.match(line)
        if not m:
            return
        t = dt.datetime.strptime(m.group(1), '%Y-%m-%d %H:%M:%S')
        msg = m.group(3)
        c = self.cur
        # "RX CONNECT A>B" is logged for every CONNECT heard, ours or not. It
        # is ours only when the very next line answers it ("TX CONNECT_ACK")
        # or turns it away ("refusing CONNECT from A"); otherwise forget it.
        if c and c.get('_pending') and not (RE_TX_ACK.match(msg) or RE_REFUSING.match(msg)):
            self.cur = c = None
        if self.refused and (t - self.refused['end']).total_seconds() > REFUSAL_GROUP_S:
            self._flush_refused()

        # the busiest lines first: bursts during a call
        mm = RE_BURST.match(msg)
        if mm:
            if c and c.get('connected_at'):
                way, kind, mode = mm.groups()
                if kind == 'data':                # data bursts only (polls/acks ride robust modes)
                    c['modes'][mode] += 1
                    if way == 'TX':
                        c['data_tx'] += 1
                    else:
                        c['data_rx'] += 1
                if way == 'RX':
                    s = RE_SNR.search(msg)
                    if s:
                        c['snr'].append(float(s.group(1)))
            return
        if msg.startswith('command: LISTEN') or msg.startswith('TX ID') or msg.startswith('RX ID'):
            return
        mm = RE_STATS.match(msg)
        if mm:
            if c:
                tx, txb, rx, rxb = int(mm.group(3)), int(mm.group(4)), int(mm.group(5)), int(mm.group(6))
                c['tx_bps'] = max(c['tx_bps'], txb)
                c['rx_bps'] = max(c['rx_bps'], rxb)
                if mm.group(1) == 'session':          # the totals
                    c['tx_bytes'], c['rx_bytes'] = tx, rx
                    c['session_s'] = int(mm.group(2))   # Data2G's own count of the time connected
                else:                                  # one interval's worth
                    c['stats_tx'] += tx
                    c['stats_rx'] += rx
            return
        mm = RE_DISCONNECTED.match(msg)
        if mm:
            if c:
                why = mm.group(1).strip()
                first = why.split()[0] if why else ''
                if not c.get('connected_at') and first in NAK_REASON:
                    self._close(t, 'failed', NAK_REASON[first], end_reason_of(why))
                else:
                    self._close(t, end_reason=end_reason_of(why))
            return
        mm = RE_MYCALL.match(msg)
        if mm:
            self.calls = {x.upper() for x in mm.group(1).split()}
            return
        mm = RE_BW.match(msg)
        if mm:
            self.bw = int(mm.group(1))
            return
        mm = RE_CONNECT_CMD.match(msg)
        if mm:
            if c:
                self._close(t, 'aborted', 'superseded')
            self._open(t, 'outgoing', mm.group(2), off, line_no)
            return
        if RE_RX_ACK.match(msg):
            if c and c['direction'] == 'outgoing' and not c.get('connected_at'):
                c['connected_at'] = t
            return
        mm = RE_RX_CONNECT.match(msg)
        if mm:
            caller, callee = mm.group(1).upper(), mm.group(2).upper()
            if self.calls and callee not in self.calls:
                return                                  # someone else's call
            if c:
                return                                  # busy (a "refusing" line follows) or a repeat
            self._open(t, 'incoming', caller, off, line_no)
            self.cur['_pending'] = True
            return
        if RE_TX_ACK.match(msg):
            if c and c['direction'] == 'incoming' and not c.get('connected_at'):
                c['connected_at'] = t
                c.pop('_pending', None)
                if self.refused and self.refused['callsign'] == c['callsign']:
                    self.refused = None                 # their earlier tries got through in the end
            return
        mm = RE_REFUSING.match(msg)
        if mm:
            caller = mm.group(1).upper()[:16]
            why = mm.group(2)
            if c and c.get('_pending') and c['callsign'] == caller:
                self.cur = None                         # not a call of ours after all
            r = self.refused
            if r and r['callsign'] == caller:
                r['end'] = t
                r['tries'] += 1
            else:
                self._flush_refused()
                self.refused = {'start': t, 'end': t, 'direction': 'incoming', 'callsign': caller,
                                'bw': self.bw, 'end_reason': ('refused_' + why.lower())[:24], 'tries': 1,
                                'start_offset': off, 'start_line': line_no}
            return
        if RE_ABORT_CMD.match(msg):
            if c:
                if c.get('connected_at'):
                    self._close(t, end_reason='abort')
                else:
                    self._close(t, 'failed', 'no_answer' if c['direction'] == 'outgoing' else 'incoming_failed',
                                'abort')
            return
        if RE_CLIENT_GONE.match(msg):
            if c:
                self._close(t, 'aborted', 'linbpq_restart')
            return
        if RE_STARTUP.match(msg):
            if c:
                self._close(t, 'aborted', 'modem_restart')
            return

    def finish(self):
        self._flush_refused()


# ------------------------------------------------- Winlink and rig history (v1.5.91)
# v1.5.92: the matching lives in winlink_match.py, shared with VARA and VHF.
def winlink_sources(bbs_dir, rows):
    if not rows:
        return None, None
    dates = wm.dates_between(min(r['start'] for r in rows),
                             max((r.get('end') or r['start']) for r in rows))
    rig = wm.Rig(wm.rig_history_file(), dates) if wm.rig_enabled('data2g') else None
    return wm.Cms(bbs_dir, dates), rig


def enrich_winlink(rows, cms, rig):
    """WL2K label from the gateway log; frequency from the rig history when the BBS log had none."""
    for r in rows:
        if not r.get('freq_khz') and rig is not None:
            f = rig.freq(r['start'], r.get('end'))
            if f:
                r['freq_khz'] = f
                r['band'] = merc.freq_to_band(f)
        if (cms is not None and r['direction'] == 'incoming' and r.get('status') == 'success'
                and not r.get('bbs_port') and cms.match(r['callsign'], r.get('connected_at') or r['start'])):
            r['band'] = 'WL2K'


def backfill_freq(conn, rows):
    """Fill frequency / WL2K on stored rows that have none; never overwrites a frequency."""
    n = 0
    with conn.cursor() as cur:
        for r in rows:
            if not r.get('freq_khz') and r.get('band') != 'WL2K':
                continue
            n += cur.execute("UPDATE rf_connections SET freq_khz = COALESCE(freq_khz, %s), "
                             "band = CASE WHEN %s = 'WL2K' THEN 'WL2K' ELSE COALESCE(band, %s) END "
                             "WHERE modem = %s AND session_start = %s AND callsign = %s "
                             "AND (freq_khz IS NULL OR band IS NULL OR band = '' OR band = 'Unknown')",
                             (r.get('freq_khz'), r.get('band'), r.get('band'), MODEM, r['start'], r['callsign']))
    return n


# ---------------------------------------------------------------- database
def insert_rows(conn, rows):
    n = 0
    link = merc.has_link_sec(conn)
    sql = ("INSERT IGNORE INTO rf_connections (callsign, base_call, direction, modem, session_start, "
           "session_end, duration_sec, freq_khz, band, bandwidth, link_mode, avg_snr_db, tx_bytes, rx_bytes, "
           "tx_max_bps, rx_max_bps, status, failure_reason, end_reason, log_line_no" + (", link_sec" if link else "") + ") "
           "VALUES (%s,%s,%s,'" + MODEM + "',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s" + (",%s" if link else "") + ")")
    with conn.cursor() as cur:
        for r in rows:
            n += cur.execute(sql, row_values(r) + ((r.get('link_sec'),) if link else ()))
    return n


def row_values(r):
    dur = int((r['end'] - r['start']).total_seconds()) if r.get('end') else None
    return (r['callsign'], merc.base_call(r['callsign']), r['direction'], r['start'], r.get('end'),
            dur, r.get('freq_khz'), r.get('band'), r.get('bandwidth'), r.get('link_mode'), r.get('avg_snr'),
            r.get('tx_bytes'), r.get('rx_bytes'), r.get('tx_max_bps'), r.get('rx_max_bps'),
            r['status'], r.get('failure_reason'), r.get('end_reason'), r.get('end_line'))


def db_ready(conn):
    """modem accepts 'data2g' (sql/2026-11-15-rf-connections-data2g.sql)."""
    with conn.cursor() as cur:
        cur.execute("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
                    "AND TABLE_NAME = 'rf_connections' AND COLUMN_NAME = 'modem'")
        r = cur.fetchone()
    return bool(r) and "'data2g'" in str(r[0])


# ---------------------------------------------------------------- main
def parse_files(log, state):
    """Run the parser over what's new. Returns (rows, new_offset, new_line_no)."""
    p = Parser()
    rotated = log + '.1'
    size = os.path.getsize(log)
    plan = []
    if state is None:
        if os.path.exists(rotated):
            plan.append((rotated, 0, 0))
        plan.append((log, 0, 0))
    else:
        off, ln, _ = state
        if size < off:                         # rotated (copytruncate) since last run
            if os.path.exists(rotated) and os.path.getsize(rotated) >= off:
                plan.append((rotated, off, ln))
            plan.append((log, 0, 0))
        else:
            plan.append((log, off, ln))
    end_off, end_ln = 0, 0
    for path, off, ln in plan:
        last_off, line_no = off, ln
        for pos, line in merc.read_from(path, off):
            line_no += 1
            before = len(p.rows)
            p.feed(pos, line_no, line)
            for r in p.rows[before:]:
                r.setdefault('end_line', line_no)
            last_off = pos + len(line.encode('utf-8', 'replace')) + 1
        if path == log:
            end_off, end_ln = last_off, line_no
            if p.cur and p.cur.get('_path') is None:
                end_off, end_ln = p.cur['start_offset'], p.cur['start_line'] - 1
        elif p.cur:
            p.cur['_path'] = path              # a call spanning the rotation
    if p.cur and p.cur.get('_path'):
        p._close(p.cur.get('connected_at') or p.cur['start'])
    # a refusal group still open is written now; a later try of the same
    # caller simply starts another row (rows are keyed by start time)
    p.finish()
    return p.rows, end_off, end_ln


def main():
    ap = argparse.ArgumentParser(description='Parse the Data2G HF log into rf_connections',
                                 formatter_class=argparse.RawDescriptionHelpFormatter, epilog=__doc__)
    ap.add_argument('--log', default=DEFAULT_LOG, help=f'Data2G log (default {DEFAULT_LOG})')
    ap.add_argument('--bbs-log-dir', default=None,
                    help='Folder with LinBPQ log_YYMMDD_BBS.txt (default config.php paths.logs); '
                         'empty string = no frequencies')
    ap.add_argument('--reset', action='store_true', help='Delete Data2G rows and the checkpoint, re-read')
    ap.add_argument('--backfill-link', action='store_true',
                    help='Re-read the log (and <log>.1) and fill link_sec on rows already stored; nothing else changes (v1.5.64)')
    ap.add_argument('--backfill-freq', action='store_true',
                    help='Re-read the log (and <log>.1) and fill the Winlink label and frequency on rows already stored (v1.5.91)')
    ap.add_argument('--dry-run', action='store_true', help="Show what would be written, write nothing")
    ap.add_argument('--verbose', '-v', action='store_true')
    ap.add_argument('--quiet', '-q', action='store_true', help='No output when nothing is new or there is no log')
    args = ap.parse_args()

    log = args.log
    if not os.path.exists(log):
        if not args.quiet:
            sys.stderr.write(f"No Data2G log at {log} — nothing to do\n")
        sys.exit(1)
    bbs_dir = bpq_db_config.default_logs_dir() if args.bbs_log_dir is None else (args.bbs_log_dir or None)
    key = os.path.basename(log)

    conn = None
    if args.dry_run:
        if pymysql is not None:
            try:
                conn = pymysql.connect(**DB_CONFIG)
            except pymysql.MySQLError:
                sys.stderr.write("(no database — dry run reads the whole log)\n")
    else:
        conn = merc.db_connect()
    try:
        if conn is not None and not db_ready(conn):
            if args.dry_run:
                sys.stderr.write("(database not upgraded for Data2G yet — dry run reads the whole log)\n")
                conn.close()
                conn = None
            else:
                sys.stderr.write("ERROR: rf_connections.modem has no 'data2g' yet — run update.sh "
                                 "(or sql/2026-11-15-rf-connections-data2g.sql)\n")
                sys.exit(3)
        if args.reset and not args.dry_run:
            with conn.cursor() as cur:
                cur.execute("DELETE FROM rf_connections WHERE modem = %s", (MODEM,))
                cur.execute("DELETE FROM rf_parser_state WHERE log_file = %s", (key,))
            conn.commit()
            sys.stderr.write("RESET: Data2G rows and checkpoint cleared\n")
        if args.backfill_link and not args.dry_run:
            if not merc.has_link_sec(conn):
                sys.stderr.write("ERROR: rf_connections has no link_sec yet — run update.sh "
                                 "(or sql/2026-11-18-rf-connections-link-sec.sql)\n")
                sys.exit(3)
            rows, _, _ = parse_files(log, None)
            n = merc.backfill_link_sec(conn, MODEM, rows)
            conn.commit()
            print(f"Data2G: {len(rows)} call(s) read, link_sec filled on {n} stored row(s)")
            return
        if args.backfill_freq:
            rows, _, _ = parse_files(log, None)
            inc, radio = merc.load_bbs(bbs_dir, rows)
            merc.enrich(rows, inc, radio)
            enrich_winlink(rows, *winlink_sources(bbs_dir, rows))
            w = sum(1 for r in rows if r.get('band') == 'WL2K')
            f = sum(1 for r in rows if r.get('freq_khz'))
            if args.dry_run:
                for r in rows:
                    if r.get('band') == 'WL2K' or r.get('freq_khz'):
                        print(f"  {r['start']:%Y-%m-%d %H:%M:%S} {r['direction']:<8} {r['callsign']:<10} "
                              f"{r['status']:<7} {r.get('band') or '-':<6} {r.get('freq_khz') or '-'}")
                print(f"Data2G: {len(rows)} call(s) read, {w} Winlink, {f} with a frequency  [DRY RUN]")
                return
            n = backfill_freq(conn, rows)
            conn.commit()
            print(f"Data2G: {len(rows)} call(s) read, {w} Winlink, {f} with a frequency; {n} stored row(s) updated")
            return
        state = None if (args.reset or conn is None) else merc.get_state(conn, key)
        rows, off, ln = parse_files(log, state)
        inc, radio = merc.load_bbs(bbs_dir, rows)
        merc.enrich(rows, inc, radio)
        enrich_winlink(rows, *winlink_sources(bbs_dir, rows))
        if args.verbose or args.dry_run:
            for r in rows:
                print(f"  {r['start']:%Y-%m-%d %H:%M:%S} {r['direction']:<8} {r['callsign']:<10} "
                      f"{r['status']:<7} {r.get('failure_reason') or '':<16} "
                      f"{(str(r['freq_khz']) + ' kHz') if r.get('freq_khz') else '-':<10} "
                      f"bw={r.get('bandwidth')} mode={r.get('link_mode') or '-'} snr={r.get('avg_snr')} "
                      f"tx={r.get('tx_bytes')} rx={r.get('rx_bytes')} end={r.get('end_reason') or '-'}"
                      + (f" tries={r['tries']}" if r.get('tries', 1) > 1 else ''))
        n = 0
        if not args.dry_run:
            n = insert_rows(conn, rows)
            total = ((state[2] if state else 0) + n)
            merc.save_state(conn, key, off, ln, total)
            conn.commit()
        if rows or not args.quiet:
            print(f"Data2G: {len(rows)} call(s) read, {n} new row(s), checkpoint {off} bytes"
                  + ("  [DRY RUN]" if args.dry_run else ''))
    finally:
        if conn is not None:
            conn.close()


if __name__ == '__main__':
    main()
