#!/usr/bin/env python3
"""
link-probe.py — scheduled test connects to watched stations (v1.5.50)

For each watched station (Admin → Maintenance → Watched stations) with
"Test connect" set to every N hours and a port, this logs in to the local
LinBPQ node (config.php bbs.user / bbs.pass, as poll-mheard.php does),
sends `C <port> <call>`, waits for the node's answer, and records:

  connected  "Connected to KP2L-10"   (+ seconds from C to the answer)
  failed     "Failure with KP2L-10"   (no answer after the node's retries)
  busy       "Busy from …", "Port in use", "…busy…"
  error      no login, no answer from the node within the time limit, …

in the link_probes table, then hangs up at once (closing the telnet
session makes LinBPQ disconnect the link). RF Connections → VHF paths
shows the results next to the hour-by-hour heard history.

THIS TRANSMITS. Use it only with the other station's sysop's agreement: a
Winlink gateway (e.g. an -10 RMS) opens a CMS session for every connect.

Times: the day is cut into N-hour slots from 00:00 UTC, each started
`offset_minutes` (default 7) in, so with 4 h: 00:07, 04:07, 08:07 … UTC.
One try per station per slot.

Usage:
  link-probe.py --due            # what parse-system-logs.py runs every 5 min
  link-probe.py --list           # the schedule and the last results
  link-probe.py --now KP2L-10    # one test connect now (prints the dialogue)
  link-probe.py --due --dry-run  # say what is due, transmit nothing

Settings (config.php, optional):
  'link_probe' => ['enabled' => true, 'offset_minutes' => 7, 'timeout' => 150]
Log: /var/log/bpq-dashboard/link-probe.log (Admin → Log Viewer).
Exit: 0 ok / nothing due, 1 bad use, 2 database, 3 another run is busy.
"""
import argparse
import fcntl
import json
import os
import re
import socket
import sys
import time
from datetime import datetime, timedelta, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402

try:
    import pymysql
except ImportError:
    pymysql = None

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WATCH_FILE = os.path.join(ROOT, 'data', 'system-logs.json')
LOCK_FILE = os.path.join(ROOT, 'cache', 'link-probe.lock')
PROBE_HOURS = (1, 2, 3, 4, 6, 8, 12, 24)
CALL_RE = re.compile(r'^[A-Z0-9]{1,6}(-\d{1,2})?$')

_LP = bpq_db_config.load_config_section('link_probe') or {}


def _cfg_int(key, default, lo, hi):
    try:
        return max(lo, min(int(_LP.get(key, default)), hi))
    except (TypeError, ValueError):
        return default


ENABLED = str(_LP.get('enabled', True)).lower() not in ('false', '0', 'no', 'off')
OFFSET_MIN = _cfg_int('offset_minutes', 7, 0, 59)
TIMEOUT = _cfg_int('timeout', 150, 30, 600)
QUIET = False


def log(msg):
    line = f"{datetime.now(timezone.utc):%Y-%m-%d %H:%M:%S}Z {msg}"
    try:
        with open(bpq_db_config.app_log_path('link-probe.log'), 'a') as f:
            f.write(line + '\n')
    except OSError:
        pass
    if not QUIET:
        print(line)


# ── schedule ────────────────────────────────────────────────────────────────
def targets():
    """Watched stations with a test-connect schedule: [{call, port, every}]."""
    watch = None
    try:
        with open(WATCH_FILE) as f:
            j = json.load(f)
        if isinstance(j, dict) and 'watch' in j:
            watch = j['watch']
    except (OSError, ValueError):
        pass
    if watch is None:
        watch = (bpq_db_config.load_config_section('system_logs') or {}).get('watch') or []
    out, seen = [], set()
    for w in watch if isinstance(watch, list) else []:
        if not isinstance(w, dict):
            continue
        call = str(w.get('call') or '').strip().upper()
        try:
            port = int(w.get('port') or 0)
            every = int(w.get('probe_hours') or 0)
        except (TypeError, ValueError):
            continue
        if not CALL_RE.match(call) or port <= 0 or every not in PROBE_HOURS or (call, port) in seen:
            continue
        seen.add((call, port))
        out.append({'call': call, 'port': port, 'every': every})
    return out


def slot_start(now, every):
    """Start of the current N-hour slot (UTC, naive), offset_minutes in."""
    day = now.replace(hour=0, minute=0, second=0, microsecond=0)
    t = now - day - timedelta(minutes=OFFSET_MIN)
    n = int(t.total_seconds() // (every * 3600))
    return day + timedelta(minutes=OFFSET_MIN) + timedelta(hours=n * every)


# ── database ────────────────────────────────────────────────────────────────
def db():
    if pymysql is None:
        raise RuntimeError('python3-pymysql is not installed')
    cfg = bpq_db_config.load_db_config({'connect_timeout': 10, 'charset': 'utf8mb4', 'autocommit': True})
    return pymysql.connect(**cfg)


def last_probe(conn, call, port):
    with conn.cursor() as cur:
        cur.execute("SELECT probed_at, result, connect_secs FROM link_probes "
                    "WHERE callsign = %s AND port = %s ORDER BY probed_at DESC LIMIT 1", (call, port))
        return cur.fetchone()


def save(conn, at, call, port, result, secs, detail):
    with conn.cursor() as cur:
        cur.execute("INSERT INTO link_probes (probed_at, callsign, port, result, connect_secs, detail) "
                    "VALUES (%s,%s,%s,%s,%s,%s)",
                    (at, call, port, result, None if secs is None else round(secs, 1), (detail or '')[:160]))


# ── the node ────────────────────────────────────────────────────────────────
class Node:
    """Telnet login to the local LinBPQ node (same steps as poll-mheard.php)."""

    def __init__(self, show=False):
        bbs = bpq_db_config.load_config_section('bbs') or {}
        self.user, self.pw = str(bbs.get('user') or ''), str(bbs.get('pass') or '')
        if not self.user or not self.pw or self.user in ('SYSOPUSER', 'YOURCALL') or self.pw == 'CHANGE_THIS_PASSWORD':
            raise RuntimeError('set bbs.user / bbs.pass in config.php')
        host = str(bbs.get('host') or '127.0.0.1')
        self.host = '127.0.0.1' if host in ('', 'localhost') else host
        self.port = int(bbs.get('port') or 8010)
        self.show = show
        self.seen = ''
        self.s = None

    def _iac(self, b):
        out, i = b'', 0
        while i < len(b) - 2:
            if b[i] == 0xFF and b[i + 1] in (0xFB, 0xFD):
                out += bytes([0xFF, 0xFD if b[i + 1] == 0xFB else 0xFB, b[i + 2]])
                i += 3
            else:
                i += 1
        if out:
            self.s.sendall(out)

    @staticmethod
    def _clean(b):
        b = re.sub(rb'\xff[\xfb-\xfe].', b'', b, flags=re.S).replace(b'\xff\xff', b'\xff')
        return re.sub(r'\x1b\[[0-9;]*[A-Za-z]', '', b.decode(errors='replace'))

    def read(self, until=None, max_s=8.0, idle=1.0):
        raw, end, last = b'', time.time() + max_s, time.time()
        while time.time() < end:
            try:
                c = self.s.recv(4096)
                if not c:
                    break
                self._iac(c)
                raw += c
                last = time.time()
                if until and re.search(until, self._clean(raw), re.I | re.M):
                    break
            except socket.timeout:
                if until is None and raw and time.time() - last >= idle:
                    break
        txt = self._clean(raw)
        self.seen = (self.seen + txt)[-600:]
        if self.show and txt:
            sys.stdout.write(txt.replace(self.pw, '***'))
            sys.stdout.flush()
        return txt

    def send(self, t):
        if self.show:
            sys.stdout.write(f"\n>>> {t if t != self.pw else '***'}\n")
        self.s.sendall((t + '\r\n').encode())

    def login(self):
        self.s = socket.create_connection((self.host, self.port), timeout=15)
        self.s.settimeout(0.3)
        if not re.search(r'user:\s*$', self.read(r'user:\s*$', 8), re.I):
            raise RuntimeError('no user: prompt from the node')
        self.send(self.user)
        self.read(r'password:\s*$', 8)
        self.send(self.pw)
        got = ''
        for _ in range(8):                       # the greeting can take a few seconds
            chunk = self.read(None, 2.5, 1.5)
            got += chunk
            if re.search(r'[>}]\s*$', got) or re.search(r'sorry|invalid|incorrect|password:', got, re.I):
                break
            if got and not chunk:                # greeting finished (it may end in an alias list)
                break
        if re.search(r'sorry|invalid|incorrect|password:|user:', got, re.I):
            raise RuntimeError('login refused: check bbs.user / bbs.pass')
        tail = got.rstrip()
        in_bbs = not tail.endswith('}') and (tail.endswith('>') or 'Connected to BBS' in got
                                              or re.search(r'\[[A-Za-z0-9.\-]+\$\]', got))
        if in_bbs:                               # back to the node
            self.send('NODE')
            self.read(r'\}\s*$', 6)

    def close(self):
        try:
            if self.s:
                self.s.close()
        except OSError:
            pass


ANSWER = re.compile(r'(Connected to\s+\S+|Failure with\s+\S+|Busy from\s+\S+|Port in use|'
                    r'Error\s*-\s*[^\r\n]+|Invalid (?:port|call)[^\r\n]*|Sorry[^\r\n]*|Not connected[^\r\n]*)', re.I)


def probe(call, port, show=False):
    """One test connect. Returns (result, seconds or None, detail)."""
    node = Node(show)
    try:
        node.login()
        node.send(f'C {port} {call}')
        t0 = time.time()
        txt = node.read(ANSWER.pattern, TIMEOUT)
        secs = time.time() - t0
        m = ANSWER.search(txt)
        if not m:
            return 'error', None, f'no answer from the node in {TIMEOUT} s'
        ans = re.sub(r'\s+', ' ', m.group(1)).strip()
        if ans.lower().startswith('connected to'):
            banner = txt[m.end():] + node.read(None, 5, 2)   # the far end's greeting, for the record
            first = next((l.strip() for l in banner.splitlines() if l.strip()), '')
            return 'connected', secs, (ans + (' · ' + first if first else ''))[:160]
        if ans.lower().startswith('failure with'):
            return 'failed', secs, ans
        if re.search(r'busy|in use', ans, re.I):
            return 'busy', secs, ans
        return 'error', secs, ans
    except (OSError, RuntimeError) as e:
        tail = re.sub(r'\s+', ' ', node.seen.replace(node.pw, '***') if node.pw else node.seen)[-100:]
        return 'error', None, f'{e}' + (f' (last: {tail})' if tail and not str(e).startswith('set ') else '')
    finally:
        node.close()                            # hanging up ends the link at once


# ── commands ────────────────────────────────────────────────────────────────
def lock():
    """One run at a time. Returns an open file (keep it), or None if busy.
    A lock file another user made (a run with sudo) is shared, not fatal."""
    try:
        os.makedirs(os.path.dirname(LOCK_FILE), exist_ok=True)
        fd = os.open(LOCK_FILE, os.O_RDWR | os.O_CREAT, 0o666)
        try:
            os.chmod(LOCK_FILE, 0o666)
        except OSError:
            pass
        fh = os.fdopen(fd, 'r+')
    except OSError:
        return open(os.devnull)                 # can't lock: carry on unlocked
    try:
        fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        return None
    return fh


def run_one(conn, t, dry_run=False, show=False):
    now = datetime.now(timezone.utc).replace(tzinfo=None, microsecond=0)
    if dry_run:
        log(f"would test-connect {t['call']} on port {t['port']}")
        return
    result, secs, detail = probe(t['call'], t['port'], show)
    save(conn, now, t['call'], t['port'], result, secs, detail)
    log(f"{t['call']} port {t['port']}: {result}" + (f" in {secs:.0f} s" if secs is not None else '') + f" — {detail}")


def main():
    global QUIET
    ap = argparse.ArgumentParser(description='Scheduled test connects to watched stations',
                                 formatter_class=argparse.RawDescriptionHelpFormatter, epilog=__doc__)
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument('--due', action='store_true', help='test the stations whose slot is due (cron)')
    g.add_argument('--now', metavar='CALL', help='test this watched station now')
    g.add_argument('--list', action='store_true', help='show the schedule and last results')
    ap.add_argument('--dry-run', action='store_true', help='say what would be tested; transmit nothing')
    ap.add_argument('--quiet', action='store_true', help='log file only')
    a = ap.parse_args()
    QUIET = a.quiet

    ts = targets()
    if a.list:
        if not ts:
            print('No watched station has "Test connect" set (Admin → Maintenance → Watched stations).')
            return 0
        conn = db()
        now = datetime.now(timezone.utc).replace(tzinfo=None)
        for t in ts:
            lp = last_probe(conn, t['call'], t['port'])
            nxt = slot_start(now, t['every']) + timedelta(hours=t['every'])
            print(f"{t['call']:<10} port {t['port']}  every {t['every']} h  next slot {nxt:%Y-%m-%d %H:%M}Z  last: "
                  + (f"{lp[1]} {lp[0]:%Y-%m-%d %H:%M}Z" + (f" ({lp[2]} s)" if lp[2] is not None else '') if lp else 'never'))
        if not ENABLED:
            print('link_probe.enabled is false in config.php: nothing is sent.')
        return 0

    if not ENABLED and not a.now:
        return 0
    fh = lock()
    if fh is None:
        if not QUIET:
            print('Another link-probe run is busy; try again in a few minutes.')
        return 3
    try:
        conn = db()
    except Exception as e:  # noqa: BLE001
        log(f"database: {e}")
        return 2
    try:
        if a.now:
            call = a.now.strip().upper()
            t = next((x for x in ts if x['call'] == call), None)
            if t is None:
                print(f'{call} is not a watched station with "Test connect" set.')
                return 1
            run_one(conn, t, a.dry_run, show=not QUIET)
            return 0
        now = datetime.now(timezone.utc).replace(tzinfo=None)
        for t in ts:
            start = slot_start(now, t['every'])
            lp = last_probe(conn, t['call'], t['port'])
            if lp and lp[0] >= start:
                continue                        # this slot has been tried
            run_one(conn, t, a.dry_run)
        return 0
    except pymysql.MySQLError as e:
        log(f"database: {e} (run update.sh for the link_probes table)")
        return 2
    finally:
        conn.close()


if __name__ == '__main__':
    sys.exit(main())
