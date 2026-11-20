#!/usr/bin/env python3
"""
BPQ Connect Watchdog — v2 (BPQ Dashboard v1.5.35; restarts shared v1.5.81)
==================================================
Pauses forwarding to a hub that stops answering, then tries it again later.

  * Watches the partners on RF Connections → RF Forwarding Partners
    (data/stations/partners.json), each matched to its BBSForwarding block in
    LinBPQ's linmail.cfg by name (N3MEL-2 → block N3MEL).
  * Reads BPQ's BBS log (log_YYMMDD_BBS.txt). An outgoing forwarding attempt
    starts at "Connecting to BBS X" and takes in the ELSE retries of the same
    script. It succeeded if the partner answered: its SID ("[BPQ-6.0…$]"),
    B2/FBB protocol lines (FS / FF / FQ / FA / FC), or "Connected to …". It
    failed if it ended ("X Disconnected") without any of those.
  * After `fail_count` failed attempts IN A ROW (default 2) the partner is
    paused for `pause_hours` (default 6): Enabled = 0 in linmail.cfg. Its mail
    waits in its queue. When the time is up, Enabled = 1 again and counting
    starts over. A good session (ours or the partner calling us) resets the
    count.
  * LinBPQ reads linmail.cfg only at start and writes its own copy when it
    stops, so a change is: stop LinBPQ, edit, start LinBPQ (a few seconds).
    All changes due in one run share one restart, and a run is put off while
    a forwarding session is in progress. A lock (cache/linmail.lock) keeps two
    scripts from editing linmail.cfg at the same time.
  * v1.5.81, fewer restarts: a pause that's over waits up to `batch_mins`
    (default 90) for another change to share its restart (a partner heard
    from again: up to 30 minutes). When a restart does happen, every pause
    ending within the next `batch_mins` ends with it, a little early. And no
    restart while the radio is in use: any BBS session (any station, not
    only the watched partners) or a Mercury or Data2G call in progress puts
    the change off to a later run.
  * State: cache/watchdog-state.json (shown on the RF Forwarding Partners
    tiles). Log: connect-watchdog.log in the dashboard's log folder. A BBS
    message tells you of each pause and resume.

Settings (config.php, all optional):
    'watchdog' => [
        'fail_count'  => 2,      // failed attempts in a row before a pause
        'pause_hours' => 6,      // how long a pause lasts
        'notify'      => true,   // BBS message on pause / resume
        'notify_to'   => '',     // default: station.callsign
        'exclude'     => [],     // partners never to pause, e.g. ['K7EK'] (RMS is never paused, v1.5.77)
        'batch_mins'  => 90,     // v1.5.81: how long a resume may wait / be brought forward to share a restart (0 = off)
    ],
The BBS login for the messages is config.php 'bbs' (user / pass), as used by
the other pages.

Run as root every 5 minutes (it edits linmail.cfg and restarts LinBPQ):
    */5 * * * * /usr/bin/python3 /var/www/bpq-dashboard/scripts/connect-watchdog.py

Commands:
    connect-watchdog.py --status           what it sees, changes nothing
    connect-watchdog.py --dry-run          a normal run without changing anything
    connect-watchdog.py --pause N3MEL [--hours 6]
    connect-watchdog.py --resume N3MEL     (or --resume all)
"""

import argparse
import fcntl
import json
import logging
import os
import re
import socket
import subprocess
import sys
import time
from datetime import datetime, timezone, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402

WEB_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_W = bpq_db_config.load_config_section('watchdog') or {}


def _int(v, d, lo, hi):
    try:
        return max(lo, min(hi, int(v)))
    except (TypeError, ValueError):
        return d


CONFIG = {
    'linmail_cfg':   bpq_db_config.default_linbpq_path('linmail.cfg'),
    'bbs_log_dir':   bpq_db_config.default_logs_dir(),
    'partners_file': os.path.join(WEB_ROOT, 'data', 'stations', 'partners.json'),
    'state_file':    os.path.join(WEB_ROOT, 'cache', 'watchdog-state.json'),
    'lock_file':     os.path.join(WEB_ROOT, 'cache', 'linmail.lock'),
    'log_file':      bpq_db_config.app_log_path('connect-watchdog.log'),
    'fail_count':    _int(_W.get('fail_count'), 2, 1, 20),
    'pause_hours':   _int(_W.get('pause_hours'), 6, 1, 168),
    'notify':        bool(_W.get('notify', True)),
    'notify_to':     str(_W.get('notify_to') or ''),
    # v1.5.77: RMS (the Winlink CMS) is never paused: the BBS collects
    # Winlink mail only when it forwards to RMS
    'exclude':       sorted({str(x).upper() for x in (_W.get('exclude') or []) if str(x).strip()} | {'RMS'}),
    'bpq_stop_cmd':  'systemctl stop bpq',
    'bpq_start_cmd': 'systemctl start bpq',
    'gap_secs':      20,    # quiet time after "Disconnected" that ends an attempt
    'stale_mins':    15,    # an attempt with no end after this long isn't counted
    'busy_mins':     10,    # a session active this recently puts a change off
    'batch_mins':    _int(_W.get('batch_mins'), 90, 0, 360),   # v1.5.81
    'early_wait_mins': 30,  # a partner heard from again waits at most this long
}

log = logging.getLogger('connect-watchdog')
log.setLevel(logging.INFO)
log.propagate = False


def setup_logging(to_file=True, console=True):
    fmt = logging.Formatter('%(asctime)s [%(levelname)s] %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    if console:   # cron runs log to the file only (no mail every 5 minutes)
        sh = logging.StreamHandler(sys.stdout)
        sh.setFormatter(fmt)
        log.addHandler(sh)
    if to_file:
        try:
            fh = logging.FileHandler(CONFIG['log_file'])
            fh.setFormatter(fmt)
            log.addHandler(fh)
        except OSError as e:
            print(f"(log file not writable: {e})", file=sys.stderr)


def now_utc():
    return datetime.now(timezone.utc)


def iso(dt):
    return dt.strftime('%Y-%m-%dT%H:%M:%SZ') if dt else None


def from_iso(s):
    if not s:
        return None
    try:
        return datetime.fromisoformat(str(s).replace('Z', '+00:00'))
    except ValueError:
        return None


# ── state ──────────────────────────────────────────────────────────────────
def load_state():
    try:
        with open(CONFIG['state_file']) as f:
            s = json.load(f)
        if isinstance(s, dict) and isinstance(s.get('partners'), dict):
            return s
    except (OSError, ValueError):
        pass
    return {'version': 2, 'partners': {}}


def save_state(state):
    state['version'] = 2
    state['updated_at'] = iso(now_utc())
    state['settings'] = {'fail_count': CONFIG['fail_count'], 'pause_hours': CONFIG['pause_hours']}
    path = CONFIG['state_file']
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(state, f, indent=2)
    os.chmod(tmp, 0o644)   # the RF Connections page reads it
    os.replace(tmp, path)


# ── partners and linmail.cfg ───────────────────────────────────────────────
def load_partner_list():
    """RF Forwarding Partners (callsigns as saved by the RF Connections page)."""
    try:
        with open(CONFIG['partners_file']) as f:
            data = json.load(f)
    except (OSError, ValueError):
        return []
    out = []
    for c in data if isinstance(data, list) else []:
        c = str(c).strip().upper()
        if re.fullmatch(r'[A-Z0-9]{1,7}(-\d{1,2})?', c) and c not in out:
            out.append(c)
    return out


def read_cfg():
    with open(CONFIG['linmail_cfg'], errors='replace') as f:
        return f.read()


def _block_re(name):
    # "N3MEL :\n{ ... };" — not "KN3MEL :" (no letter/digit/dash before it)
    return re.compile(r'(?<![A-Za-z0-9\-])' + re.escape(name) + r'\s*:\s*\{([^{}]*)\}', re.S)


def block_names(cfg):
    m = re.search(r'BBSForwarding\s*:\s*\{', cfg)
    region = cfg[m.end():] if m else cfg
    return set(re.findall(r'(?m)^\s*([A-Za-z0-9\-]{1,12})\s*:\s*\{', region))


def match_block(call, names):
    """N3MEL-2 → N3MEL (or N3MEL-2 if that's the block's name)."""
    if call in names:
        return call
    base = call.split('-')[0]
    return base if base in names else None


def get_enabled(cfg, name):
    m = _block_re(name).search(cfg)
    if not m:
        return None
    e = re.search(r'\bEnabled\s*=\s*(\d+)', m.group(1))
    return None if not e else e.group(1) == '1'


def set_enabled(cfg, name, on):
    m = _block_re(name).search(cfg)
    if not m:
        return None
    body = m.group(1)
    e = re.search(r'(\bEnabled\s*=\s*)\d+', body)
    if not e:
        return None
    start = m.start(1) + e.end(1)
    end = m.start(1) + e.end()
    return cfg[:start] + ('1' if on else '0') + cfg[end:]


# ── BBS log ────────────────────────────────────────────────────────────────
RE_LINE = re.compile(r'^(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+([|<>?])(\S*)\s*(.*?)\s*$')
RE_SID = re.compile(r'^\[[A-Za-z0-9.\-]+\$?\]')
RE_PROTO = re.compile(r'^(FS\b|FF\b|FQ\b|FA\b|FB\b|FC\b|F>)', re.I)
RE_REACHED = re.compile(r'\bConnected to \S', re.I)
# the partner was called and didn't answer (VARA / AX.25 / NET/ROM wording)
RE_FAILED = re.compile(r"Failure with|Can'?t Connect|Unable to connect|Timeout|Timed out|"
                       r"No answer|Retried out|Link failure|Busy from|Station busy|Connection refused", re.I)


def read_log_lines(since):
    lines = []
    d = since.date()
    end = now_utc().date()
    while d <= end:
        p = os.path.join(CONFIG['bbs_log_dir'], f"log_{d.strftime('%y%m%d')}_BBS.txt")
        try:
            with open(p, errors='replace') as f:
                lines.extend(f.readlines())
        except OSError:
            pass
        d += timedelta(days=1)
    return lines


def partner_events(lines, name, since):
    """[(time, 'ok'|'fail')] for one BBS name, oldest first, plus the time of
    an attempt still in progress (or None).

    v1.5.37: lines are matched on the base call, so the partner's own calls
    in (logged as "KK4DIV-1", the block is "KK4DIV") count as good sessions;
    and an attempt that only failed because OUR port was busy ("Error - Port
    in use", e.g. while another partner's session was ending) or the channel
    was busy isn't the partner's fault and isn't counted either way.

    v1.5.38: a session's age is measured from its last line, not its start, so
    a good 15-minute exchange is no longer thrown away as "never ended"; and
    an attempt is only a failure when it really went on the air and failed:
    a failure message from the modem/node ("Failure with", "Can't connect",
    "Timeout", "Busy", …) or our connect command followed by 20 s or more
    with no answer. "Connecting … Disconnected" in the same second (the
    script had nothing to do at that time, e.g. outside its TIMES window, or
    LinBPQ was just starting) isn't counted."""
    gap = CONFIG['gap_secs']
    events = []
    att = None          # {'start', 'last', 'ok', 'disc', 'busy', 'failmsg', 'sent'}
    base = name.split('-')[0].upper()

    def close(a):
        end = a['disc'] or a['last']
        if a['ok']:
            events.append((end, 'ok'))
        elif a['failmsg']:
            events.append((end, 'fail'))   # a real try failed (even after an ELSE that hit a busy port)
        elif a['busy']:
            pass   # only our own busy port: not counted
        elif a['sent'] and (end - a['start']).total_seconds() >= 20:
            events.append((end, 'fail'))
        # else: never went on the air — not counted

    for raw in lines:
        m = RE_LINE.match(raw.rstrip('\r\n'))
        if not m or m.group(6).upper().split('-')[0] != base:
            continue
        ymd, hh, mi, ss, kind, _, text = m.groups()
        try:
            ts = datetime(2000 + int(ymd[:2]), int(ymd[2:4]), int(ymd[4:6]), int(hh), int(mi), int(ss), tzinfo=timezone.utc)
        except ValueError:
            continue
        if ts < since:
            continue
        # an attempt ends once things go quiet after its "Disconnected"
        if att and att['disc'] and (ts - att['disc']).total_seconds() > gap:
            close(att)
            att = None
        if att and not att['disc'] and (ts - att['last']).total_seconds() > CONFIG['stale_mins'] * 60:
            if att['ok']:
                close(att)   # it had answered: a good session, whatever came after
            att = None       # otherwise silent too long without an end: not counted
        if att:
            att['last'] = ts
        up = text.upper()
        if kind == '|' and up.startswith('CONNECTING TO BBS'):
            if att is None:
                att = {'start': ts, 'last': ts, 'ok': False, 'disc': None, 'busy': False,
                       'failmsg': False, 'sent': False}
            else:
                att['disc'] = None   # the script's ELSE: same attempt
            continue
        if kind == '>' and att and re.match(r'(C|CONNECT)\s+\S', up):
            att['sent'] = True   # our connect command went out
        if kind == '<' and att and RE_FAILED.search(text):
            att['failmsg'] = True
        reached = kind == '<' and (RE_SID.match(text) or RE_PROTO.match(text) or RE_REACHED.search(text)
                                   or up.startswith('ALREADY CONNECTED'))
        if kind == '<' and att and ('PORT IN USE' in up or 'CHANNEL BUSY' in up or 'PORT BUSY' in up):
            att['busy'] = True   # our side was busy: not the partner's fault
            continue
        if kind == '|' and up.endswith('DISCONNECTED'):
            if att:
                att['disc'] = ts
            continue
        if reached:
            if att:
                att['ok'] = True
            elif not (events and events[-1][1] == 'ok' and (ts - events[-1][0]).total_seconds() < 300):
                events.append((ts, 'ok'))   # the partner called us (one per session)
    in_progress = None
    if att:
        if att['disc'] and (now_utc() - att['disc']).total_seconds() > gap:
            close(att)
        elif (now_utc() - att['last']).total_seconds() <= CONFIG['stale_mins'] * 60:
            in_progress = att['start']
    return events, in_progress


def fails_in_a_row(events):
    n = 0
    for _, r in events:
        n = n + 1 if r == 'fail' else 0
    return n


# ── LinBPQ restart with the change ────────────────────────────────────────
def apply_changes(changes, dry_run):
    """changes: [(block, enabled_bool)] → True if written (one restart)."""
    if not changes:
        return True
    desc = ', '.join(f"{b} Enabled={'1' if on else '0'}" for b, on in changes)
    if dry_run:
        log.info(f"(dry run) would stop LinBPQ, set {desc}, start LinBPQ")
        return True
    log.info(f"Stopping LinBPQ to set {desc}")
    try:
        subprocess.run(CONFIG['bpq_stop_cmd'], shell=True, check=True, timeout=60)
    except subprocess.SubprocessError as e:
        log.error(f"Could not stop LinBPQ: {e}")
        return False
    time.sleep(3)   # LinBPQ writes linmail.cfg as it stops
    ok = False
    try:
        path = CONFIG['linmail_cfg']
        cfg = read_cfg()
        for b, on in changes:
            new = set_enabled(cfg, b, on)
            if new is None:
                log.warning(f"{b}: block or Enabled line not found in linmail.cfg — skipped")
            else:
                cfg = new
        st = os.stat(path)
        backup = path + '.watchdog.bak'
        try:
            with open(path, 'rb') as src, open(backup, 'wb') as dst:
                dst.write(src.read())
        except OSError:
            pass
        tmp = path + '.watchdog.tmp'
        with open(tmp, 'w') as f:
            f.write(cfg)
        os.chmod(tmp, st.st_mode & 0o7777)
        try:
            os.chown(tmp, st.st_uid, st.st_gid)
        except OSError:
            pass
        os.replace(tmp, path)
        ok = True
        log.info(f"linmail.cfg written ({desc}); previous copy {backup}")
    except OSError as e:
        log.error(f"Could not write linmail.cfg: {e}")
    finally:
        try:
            subprocess.run(CONFIG['bpq_start_cmd'], shell=True, check=True, timeout=120)
            log.info("LinBPQ started")
        except subprocess.SubprocessError as e:
            log.error(f"Could not start LinBPQ: {e} — start it by hand: {CONFIG['bpq_start_cmd']}")
    return ok


# ── BBS message ────────────────────────────────────────────────────────────
def send_bbs_message(subject, body, dry_run=False):
    if not CONFIG['notify'] or dry_run:
        return
    bbs = bpq_db_config.load_config_section('bbs') or {}
    station = bpq_db_config.load_config_section('station') or {}
    user, pw = str(bbs.get('user') or ''), str(bbs.get('pass') or '')
    to = CONFIG['notify_to'] or str(station.get('callsign') or '') or user
    if not user or not pw or user in ('SYSOPUSER', 'YOURCALL') or pw == 'CHANGE_THIS_PASSWORD' or not to:
        log.info("BBS message skipped: set bbs.user / bbs.pass (and station.callsign) in config.php")
        return
    host = str(bbs.get('host') or '127.0.0.1')
    host = '127.0.0.1' if host in ('', 'localhost') else host
    port = int(bbs.get('port') or 8010)
    # v1.5.38: same login as scripts/poll-mheard.php (answers telnet option
    # requests, CR LF line ends, waits for the output to go quiet), which
    # works on this BBS; the v1.5.35 version never saw a prompt.
    seen = ''
    try:
        s = socket.create_connection((host, port), timeout=15)
        s.settimeout(0.3)

        def iac(b):
            out = b''
            i = 0
            while i < len(b) - 2:
                if b[i] == 0xFF and b[i + 1] in (0xFB, 0xFD):
                    out += bytes([0xFF, 0xFD if b[i + 1] == 0xFB else 0xFB, b[i + 2]])
                    i += 3
                else:
                    i += 1
            if out:
                s.sendall(out)

        def clean(b):
            b = re.sub(rb'\xff[\xfb-\xfe].', b'', b, flags=re.S).replace(b'\xff\xff', b'\xff')
            return re.sub(r'\x1b\[[0-9;]*[A-Za-z]', '', b.decode(errors='replace'))

        def read(until=None, max_s=8.0, idle=1.0):
            nonlocal seen
            raw, end, last = b'', time.time() + max_s, time.time()
            while time.time() < end:
                try:
                    c = s.recv(4096)
                    if not c:
                        break
                    iac(c)
                    raw += c
                    last = time.time()
                    txt = clean(raw)
                    if until and re.search(until, txt.rstrip(), re.I):
                        break
                except socket.timeout:
                    if raw and time.time() - last >= idle:
                        break
            txt = clean(raw)
            seen = (seen + txt)[-300:]
            return txt

        def send(t):
            s.sendall((t + '\r\n').encode())

        read(r'user:$', 8)
        send(user)
        read(r'password:$', 8)
        send(pw)
        # v1.5.45: the same decisions as includes/bbs-login.php, which works on
        # every station: a node greeting (CTEXT) need not end in '}' — K1AJD's
        # ends with a list of node aliases — so after the greeting, unless we
        # are already at the BBS prompt, always send the BBS alias. The old
        # code sent it only after a '}' and gave up with "no BBS prompt".
        def at_bbs(txt):
            tail = txt.rstrip()[-80:]
            if re.search(r'\bde [A-Z0-9/\-]+>$', tail, re.I):
                return True
            return bool((re.search(r'Connected to BBS', txt, re.I) or re.search(r'\[[A-Za-z0-9.\-]+\$\]', txt))
                        and re.search(r'>$', tail))
        got = ''
        for _ in range(8):                        # the greeting can take a few seconds
            got += read(None, 2.5, 1.5)
            if '}' in got or at_bbs(got) or re.search(r'sorry|invalid|incorrect|password:', got, re.I):
                break
        if re.search(r'sorry|invalid|incorrect|password:', got, re.I):
            raise RuntimeError('login refused: check bbs.user / bbs.pass')
        if not at_bbs(got):                       # at the node (or still in its greeting): go to the BBS
            send(str(bbs.get('alias') or 'bbs'))
            got2 = ''
            for _ in range(6):
                got2 += read(None, 2.5, 1.5)
                if at_bbs(got2):
                    break
            got = got2
        if not at_bbs(got):
            raise RuntimeError('no BBS prompt after login')
        send(f'SP {to}')
        read(r':$', 6)
        send(subject[:60])
        read(None, 4, 1.0)
        for ln in body.splitlines():
            send(' /ex' if ln.strip().lower() == '/ex' else ln)
            time.sleep(0.02)
        send('/ex')
        ack = read(r'>$', 12)
        send('B')
        time.sleep(0.5)
        s.close()
        if not re.search(r'Message:\s*\d+|Bid:', ack, re.I):
            log.warning(f"BBS message to {to} may not have been stored (no 'Message: N' reply)")
        log.info(f"BBS message sent to {to}: {subject}")
    except Exception as e:   # noqa: BLE001 — a failed notice must not stop the watchdog
        tail = re.sub(r'\s+', ' ', seen.replace(pw, '***') if pw else seen)[-120:]
        log.warning(f"BBS message not sent: {e} (last text from the BBS: {tail!r})")


# ── main run ──────────────────────────────────────────────────────────────
# ── is the radio in use? (v1.5.81) ───────────────────────────────────────
def bbs_sessions_open(lines, now):
    """Calls with a BBS session open right now (any station): it has had a
    line within busy_mins and no "Disconnected" since its last activity."""
    last, gone = {}, {}
    for raw in lines[-4000:]:
        m = RE_LINE.match(raw.rstrip('\r\n'))
        if not m:
            continue
        ymd, hh, mi, ss, kind, call, text = m.groups()
        if not call:
            continue
        try:
            ts = datetime(2000 + int(ymd[:2]), int(ymd[2:4]), int(ymd[4:6]), int(hh), int(mi), int(ss), tzinfo=timezone.utc)
        except ValueError:
            continue
        c = call.upper()
        last[c] = ts
        gone[c] = kind == '|' and text.upper().endswith('DISCONNECTED')
    lim = CONFIG['busy_mins'] * 60
    return sorted(c for c, t in last.items() if not gone.get(c) and (now - t).total_seconds() <= lim)


def _load_parser(name):
    import importlib.util
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), name)
    spec = importlib.util.spec_from_file_location(name.replace('-', '_').replace('.py', ''), path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def modem_call_open(script, section):
    """'CALL' when the Mercury / Data2G log shows a call still open (calling or
    connected) and the log was written to within busy_mins; else None."""
    if (bpq_db_config.load_config_section(section) or {}).get('enabled') is False:
        return None
    try:
        mod = _load_parser(script)
        path = mod.DEFAULT_LOG
        st = os.stat(path)
    except Exception:
        return None
    if time.time() - st.st_mtime > CONFIG['busy_mins'] * 60:
        return None
    try:
        p = mod.Parser()
        start = max(0, st.st_size - 512 * 1024)
        for n, (off, line) in enumerate(mod.read_from(path, start)):
            if n == 0 and start:
                continue          # probably a partial line
            p.feed(off, n, line)
        c = p.cur
        if not c or c.get('_disc_at'):        # none, or already disconnected
            return None
        return c.get('callsign') or '?'
    except Exception as e:
        log.info(f"{section} log not read for the busy check: {e}")
        return None


def radio_busy(lines, now):
    """Why a LinBPQ restart now would cut someone off: [reason, ...]."""
    why = []
    open_ = bbs_sessions_open(lines, now)
    if open_:
        why.append('BBS session with ' + ', '.join(open_[:4]))
    for script, section, label in (('parse-mercury-log.py', 'mercury', 'Mercury'),
                                   ('parse-data2g-log.py', 'data2g', 'Data2G')):
        c = modem_call_open(script, section)
        if c:
            why.append(f'{label} call with {c}')
    return why


def lock_or_exit():
    os.makedirs(os.path.dirname(CONFIG['lock_file']), exist_ok=True)
    fd = open(CONFIG['lock_file'], 'w')
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        log.info("Another script is changing linmail.cfg — trying again next run")
        sys.exit(0)
    return fd


def survey(state, cfg):
    """Partners as the watchdog sees them: [(call, block, enabled, pstate)]."""
    names = block_names(cfg)
    rows = []
    for call in load_partner_list():
        block = match_block(call, names)
        pst = state['partners'].setdefault(block or call, {})
        pst['call'] = call
        rows.append((call, block, get_enabled(cfg, block) if block else None, pst))
    return rows


def run(dry_run=False):
    _lock = lock_or_exit()   # noqa: F841 — held until exit
    now = now_utc()
    state = load_state()
    try:
        cfg = read_cfg()
    except OSError as e:
        log.error(f"Cannot read {CONFIG['linmail_cfg']}: {e}")
        return 1
    rows = survey(state, cfg)
    if not rows:
        log.info(f"No RF Forwarding Partners ({CONFIG['partners_file']}): nothing to watch")
        save_state(state)
        return 0
    first = state.get('started_at') is None
    if first:
        state['started_at'] = iso(now)   # count only attempts from now on

    pauses, due, upcoming, busy = [], [], [], []   # due: (block, due_at, early)
    for call, block, enabled, pst in rows:
        if not block:
            pst.update(status='not_in_linmail')
            log.info(f"{call}: no forwarding block in linmail.cfg — not watched")
            continue
        if block.upper() in CONFIG['exclude'] or call in CONFIG['exclude']:
            pst.update(status='excluded')
            continue
        since = max(filter(None, [from_iso(state['started_at']), from_iso(pst.get('counting_since')),
                                  now - timedelta(days=2)]))
        events, active = partner_events(read_log_lines(since), block, since)
        if active:
            busy.append(block)
        last_ok = next((t for t, r in reversed(events) if r == 'ok'), None)
        if last_ok:
            pst['last_ok_at'] = iso(last_ok)
        if events:
            pst['last_attempt_at'] = iso(events[-1][0])
            pst['last_result'] = events[-1][1]
        n = fails_in_a_row(events)
        pst['fails_in_a_row'] = n

        if pst.get('paused'):
            paused_at = from_iso(pst.get('paused_at'))
            heard = next((t for t, r in reversed(events) if r == 'ok' and paused_at and t > paused_at), None)
            if heard and enabled is False:
                # v1.5.38: it's back — a good session (usually the partner
                # calling us) since the pause: end the pause now
                pst['early_reason'] = f"good session with {block} at {heard.strftime('%H:%M')} UTC"
                pst.setdefault('resume_due_at', iso(now))
                due.append((block, from_iso(pst['resume_due_at']), True))
                continue
            if enabled is True:
                # someone turned it back on by hand
                log.info(f"{block}: enabled in linmail.cfg by hand — pause cleared")
                pst.update(paused=False, resume_at=None, status='watching', counting_since=iso(now), fails_in_a_row=0)
            elif now >= (from_iso(pst.get('resume_at')) or now):
                due.append((block, from_iso(pst.get('resume_at')) or now, False))
            else:
                pst['status'] = 'paused'
                upcoming.append((block, from_iso(pst.get('resume_at'))))
            continue
        if enabled is False:
            pst['status'] = 'disabled'   # off in BPQ, not by us: leave it alone
            continue
        if enabled is None:
            pst['status'] = 'no_enabled_line'
            continue
        pst['status'] = 'watching'
        if n >= CONFIG['fail_count']:
            pauses.append((block, n))

    # v1.5.81: share restarts. A resume that's due waits (up to batch_mins,
    # or early_wait_mins for a partner heard from again) for another change;
    # once a restart is happening anyway, resumes due soon come along.
    batch = timedelta(minutes=CONFIG['batch_mins'])
    early_wait = timedelta(minutes=min(CONFIG['early_wait_mins'], CONFIG['batch_mins']))
    must = [b for b, at, early in due if now - at >= (early_wait if early else batch)]
    resumes, ahead = [], []
    if pauses or must:
        resumes = [b for b, _, _ in due]
        ahead = [(b, at) for b, at in upcoming if at and at - now <= batch]
        resumes += [b for b, _ in ahead]
    elif due:
        for b, at, early in due:
            left = int(((early_wait if early else batch) - (now - at)).total_seconds() // 60) + 1
            state['partners'][b]['status'] = 'resume_waiting'
            log.info(f"{b}: pause over — waiting up to {left} min more for another change to share the LinBPQ restart")
    ahead_set = {b for b, _ in ahead}

    if pauses or resumes:
        why = ([f"forwarding with {', '.join(busy)}"] if busy else []) + radio_busy(read_log_lines(now - timedelta(hours=2)), now)
        if why:
            for b, _, _ in due:
                state['partners'][b]['status'] = 'resume_waiting'
            log.info(f"Change due ({', '.join([b for b, _ in pauses] + resumes)}) but the radio is in use "
                     f"({'; '.join(why)}) — next run")
            if not dry_run:
                save_state(state)
            return 0

    changes = [(b, False) for b, _ in pauses] + [(b, True) for b in resumes]
    if changes and apply_changes(changes, dry_run):
        for block, n in pauses:
            pst = state['partners'][block]
            until = now + timedelta(hours=CONFIG['pause_hours'])
            if not dry_run:
                pst.update(paused=True, paused_at=iso(now), resume_at=iso(until), status='paused',
                           reason=f"{n} failed connect attempts in a row")
            log.warning(f"{block}: PAUSED for {CONFIG['pause_hours']} h after {n} failed attempts in a row "
                        f"(until {until.strftime('%Y-%m-%d %H:%M')} UTC)")
            send_bbs_message(
                f"Watchdog: {block} forwarding paused {CONFIG['pause_hours']}h",
                f"Connect Watchdog {now.strftime('%Y-%m-%d %H:%M')} UTC\n\n"
                f"{block} ({pst.get('call', block)}) did not answer {n} forwarding attempts in a row.\n"
                f"Forwarding to {block} is paused (Enabled = 0 in linmail.cfg) until\n"
                f"{until.strftime('%Y-%m-%d %H:%M')} UTC. Its messages wait in its queue.\n\n"
                f"Resume now: sudo python3 {os.path.abspath(__file__)} --resume {block}\n", dry_run)
        for block in resumes:
            pst = state['partners'][block]
            was_due = pst.get('resume_at')
            if not dry_run:
                pst.update(paused=False, resume_at=None, status='watching', reason=None,
                           counting_since=iso(now), fails_in_a_row=0, resumed_at=iso(now))
                pst.pop('resume_due_at', None)
            early = pst.pop('early_reason', None)
            if not early and block in ahead_set and was_due:
                mins = int((from_iso(was_due) - now).total_seconds() // 60)
                early = f"{mins} min before its pause was due to end, to share the LinBPQ restart"
            if early:
                log.info(f"{block}: pause ended early ({early}), forwarding enabled again")
            else:
                log.info(f"{block}: pause over — forwarding enabled again")
            send_bbs_message(
                f"Watchdog: {block} forwarding resumed" + (" early" if early else ""),
                f"Connect Watchdog {now.strftime('%Y-%m-%d %H:%M')} UTC\n\n"
                + ((f"{block} is back: {early}. The pause is ended early and\n"
                    if early.startswith('good session') else f"The pause is ended {early}:\n")
                   if early else f"The {CONFIG['pause_hours']} h pause is over:\n")
                + f"forwarding to {block} is enabled again (Enabled = 1). It is paused again\n"
                f"after {CONFIG['fail_count']} failed attempts in a row.\n", dry_run)
    elif not changes and not due:
        log.info("No changes: " + ', '.join(f"{c}={p.get('status')}"
                                            + (f"({p.get('fails_in_a_row', 0)} failed)" if p.get('fails_in_a_row') else '')
                                            for c, _, _, p in rows))
    if not dry_run:
        save_state(state)
    return 0


def cmd_status():
    state = load_state()
    try:
        cfg = read_cfg()
    except OSError as e:
        print(f"Cannot read {CONFIG['linmail_cfg']}: {e}")
        return 1
    rows = survey(state, cfg)
    now = now_utc()
    print(f"Connect Watchdog — {now.strftime('%Y-%m-%d %H:%M')} UTC — pause after {CONFIG['fail_count']} "
          f"failed attempts in a row, for {CONFIG['pause_hours']} h")
    print(f"Partners from {CONFIG['partners_file']}\n")
    print(f"{'Partner':<12} {'Block':<9} {'Enabled':<8} {'Status':<22} {'Failed':<7} {'Last OK (UTC)':<17} Resumes")
    for call, block, en, p in rows:
        since = max(filter(None, [from_iso(state.get('started_at')), from_iso(p.get('counting_since')),
                                  now - timedelta(days=2)]))
        n = fails_in_a_row(partner_events(read_log_lines(since), block, since)[0]) if block else 0
        st = ('resuming next restart' if p.get('status') == 'resume_waiting' else 'paused') if p.get('paused') else ('not in linmail' if not block else ('disabled' if en is False else 'watching'))
        ok = (p.get('last_ok_at') or '-').replace('T', ' ')[:16]
        res = (p.get('resume_at') or '').replace('T', ' ')[:16] if p.get('paused') else ''
        print(f"{call:<12} {block or '-':<9} {('-' if en is None else ('1' if en else '0')):<8} {st:<22} {n:<7} {ok:<17} {res}")


def cmd_manual(target, on, hours):
    _lock = lock_or_exit()   # noqa: F841
    state = load_state()
    cfg = read_cfg()
    rows = survey(state, cfg)
    targets = [r for r in rows if r[1] and (target == 'ALL' or target in (r[0], r[1], r[0].split('-')[0]))]
    if on and target == 'ALL':
        targets = [r for r in targets if r[3].get('paused')]
    if not targets:
        print(f"No such watched partner: {target}")
        return 1
    if not apply_changes([(r[1], on) for r in targets], False):
        return 1
    now = now_utc()
    for call, block, _, p in targets:
        if on:
            p.update(paused=False, resume_at=None, status='watching', reason=None,
                     counting_since=iso(now), fails_in_a_row=0, resumed_at=iso(now))
            print(f"{block}: forwarding enabled")
        else:
            until = now + timedelta(hours=hours)
            p.update(paused=True, paused_at=iso(now), resume_at=iso(until), status='paused', reason='paused by hand')
            print(f"{block}: paused until {until.strftime('%Y-%m-%d %H:%M')} UTC")
    save_state(state)
    return 0


def main():
    ap = argparse.ArgumentParser(description='Pause forwarding to hubs that stop answering.')
    ap.add_argument('--status', action='store_true', help='show what the watchdog sees; change nothing')
    ap.add_argument('--dry-run', action='store_true', help='a normal run that changes nothing')
    ap.add_argument('--pause', metavar='CALL', help='pause a partner now')
    ap.add_argument('--hours', type=int, default=CONFIG['pause_hours'], help='with --pause')
    ap.add_argument('--resume', metavar='CALL', help="enable a paused partner now ('all' for every one)")
    ap.add_argument('--test-notice', action='store_true',
                    help='send a test BBS message to the notice address (checks bbs.user/pass and the login); changes nothing else')
    a = ap.parse_args()
    setup_logging(to_file=not a.status,
                  console=sys.stdout.isatty() or bool(a.status or a.dry_run or a.pause or a.resume or a.test_notice))
    if a.test_notice:                      # v1.5.45
        CONFIG['notify'] = True
        send_bbs_message('Connect watchdog: test notice',
                         'This is a test message from connect-watchdog.py --test-notice.\n'
                         'If you can read it, the watchdog can tell you when it pauses or resumes a partner.')
        return 0
    if a.status:
        cmd_status()
        return 0
    if a.pause:
        return cmd_manual(a.pause.upper(), False, max(1, a.hours))
    if a.resume:
        return cmd_manual(a.resume.upper(), True, 0)
    return run(dry_run=a.dry_run)


if __name__ == '__main__':
    sys.exit(main())
