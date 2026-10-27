#!/usr/bin/env python3
"""
bpq-aprs-daemon.py — Persistent APRS-IS connection daemon for BPQ Dashboard
Stays connected to APRS-IS 24/7, parses packets, writes cache files.
Managed by systemd as bpq-aprs.service

Beacon (2026-10-16): when the sysop switches it on in the APRS Map's
BBS Info editor (data/stations/bbs-info.json), the daemon also sends the
BBS's position to APRS-IS every N minutes — as an object ("TPRFN BBS") or
as the login callsign's own position — so it shows on aprs.fi. Off by
default; needs a verified login (real passcode, not -1) and a marker
position. Status goes into cache/aprs/aprs-daemon.json ('beacon').
"""

import socket
import json
import time
import re
import os
import sys
import fcntl
import threading
import logging
import math
from datetime import datetime

# ── Config ─────────────────────────────────────────────────────────
APRS_HOST    = 'rotate.aprs2.net'
APRS_PORT    = 14580
# Test hook only: BPQ_APRS_SERVER=host:port points the daemon elsewhere.
if os.environ.get('BPQ_APRS_SERVER', '').count(':') == 1:
    APRS_HOST, _p = os.environ['BPQ_APRS_SERVER'].split(':')
    APRS_PORT = int(_p)
# Login + filter come from config.php 'aprs' (and 'station') — nothing
# station-specific is hard-coded here any more (2026-09-26).
#   'aprs' => ['callsign' => 'YOURCALL-1', 'passcode' => 'YOUR_PASSCODE', 'filter' => '']
# passcode -1 = receive-only. Empty filter = r/<station lat>/<lon>/80
# (80 km ~ the page's 50-mile circle; exact position stays server-side).
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402
_APRS = bpq_db_config.load_config_section('aprs')
_STN  = bpq_db_config.load_config_section('station')
APRS_CALL    = str(_APRS.get('callsign') or 'YOURCALL-1').upper()
APRS_PASS    = str(_APRS.get('passcode') or '-1')
APRS_FILTER  = str(_APRS.get('filter') or '').strip() or (
    f"r/{float(_STN.get('lat') or 0):.4f}/{float(_STN.get('lon') or 0):.4f}/80")
APRS_VERSION = 'BPQ-Dashboard 1.0'   # Must be: name SPACE version

# Same folder bpq-aprs.php reads: <web root>/cache/aprs
CACHE_DIR    = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'cache', 'aprs')
STATIONS_FILE= os.path.join(CACHE_DIR, 'stations.json')
HISTORY_FILE = os.path.join(CACHE_DIR, 'history.json')
MESSAGES_FILE= os.path.join(CACHE_DIR, 'messages.json')
DAEMON_FILE  = os.path.join(CACHE_DIR, 'aprs-daemon.json')
LOCK_FILE    = os.environ.get('BPQ_APRS_LOCK') or '/tmp/bpq-aprs-daemon.lock'
# Beacon settings, written by station-storage.php (saveBbsInfo)
BBS_INFO_FILE = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                             'data', 'stations', 'bbs-info.json')
BEACON_STATE  = os.path.join(CACHE_DIR, 'beacon-state.json')

MAX_STATIONS  = 1000
MAX_MESSAGES  = 200
MAX_AGE_HRS   = 12          # keep stations heard within 12 hours
TRACK_MAX_PTS = 500         # max track points per station
SAVE_INTERVAL = 15          # write cache every N seconds
KEEPALIVE_INT = 180         # send keepalive every 3 minutes
RECONNECT_WAIT= 30          # seconds before reconnect attempt
BEACON_CHECK  = 30          # look at bbs-info.json every N seconds
BEACON_MIN_INT= 10          # minutes; never beacon more often than this
BEACON_CHANGE_GAP = 120     # seconds; an edited beacon goes out after this gap

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)s %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
log = logging.getLogger('bpq-aprs')

# ── State ───────────────────────────────────────────────────────────
stations  = {}   # call -> station dict
history   = {}   # call -> [position points]
messages  = []   # list of message dicts
lock      = threading.Lock()
connected = False
verified  = False   # APRS-IS said "verified" (a real passcode) — beacons need it
sock      = None
send_lock = threading.Lock()   # keepalive + beacon share the socket
beacon_status = {'enabled': False}
stats     = {'packets': 0, 'positions': 0, 'messages': 0, 'start': time.time()}

# ── Ensure cache dir ────────────────────────────────────────────────
os.makedirs(CACHE_DIR, exist_ok=True)

# ── Single instance lock ────────────────────────────────────────────
lock_fd = open(LOCK_FILE, 'w')
try:
    fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
except IOError:
    log.error('Another instance is running. Exiting.')
    exit(1)

# ── APRS Packet Parser ──────────────────────────────────────────────
def parse_packet(line):
    line = line.strip()
    if not line or line.startswith('#'):
        return None

    m = re.match(r'^([A-Z0-9\-\/]+)>([^:,]+)([^:]*):(.*)$', line, re.I)
    if not m:
        return None

    frm     = m.group(1).upper()
    path    = m.group(3).strip(',')
    payload = m.group(4)

    if not frm or len(frm) > 12:
        return None

    # Message packet
    if payload and payload[0] == ':':
        mm = re.match(r'^:([A-Z0-9\-\/\s]{1,9})\s*:(.+)$', payload)
        if mm:
            msg_dest = mm.group(1).strip()
            msg_text = re.sub(r'\{[0-9]+\}$', '', mm.group(2)).strip()
            return {
                'call': frm, 'type': 'msg', 'isMsg': True,
                'msgDest': msg_dest, 'msgText': msg_text,
                'path': path, 'ts': int(time.time())
            }
        return None

    # Position — find DDmm.mmN/DDDmm.mmW pattern anywhere in payload
    pos = re.search(r'(\d{2})(\d{2}\.\d+)([NS])(.)(\d{3})(\d{2}\.\d+)([EW])(.?)', payload)
    if not pos:
        return None

    lat = int(pos.group(1)) + float(pos.group(2)) / 60.0
    lon = int(pos.group(5)) + float(pos.group(6)) / 60.0
    if pos.group(3) == 'S': lat = -lat
    if pos.group(7) == 'W': lon = -lon

    if not (-90 <= lat <= 90) or not (-180 <= lon <= 180):
        return None

    symtbl  = pos.group(4)
    symbol  = pos.group(8) or '>'
    comment = payload[pos.end():].strip()[:200]

    wx      = symbol == '_' or bool(re.search(r'_\d{3}/', payload))
    mobile  = symbol in ['>', 'k', 'j', 'v', 'u', '<']
    stype   = 'wx' if wx else ('mobile' if mobile else 'fixed')

    speed = course = altitude = None
    wx_data = {}

    # Speed/course for mobile stations
    cse = re.match(r'^(\d{3})\/(\d{3})', comment)
    if cse and not wx:
        course  = int(cse.group(1))
        speed   = round(int(cse.group(2)) * 1.15078, 1)  # knots to mph
        comment = comment[7:]

    # Altitude
    alt = re.search(r'\/A=(\d{6})', comment)
    if alt:
        altitude = int(alt.group(1))  # feet (keep imperial)
        comment  = comment.replace(alt.group(0), '')

    # WX fields — all kept in imperial units
    if wx:
        def _int(m): 
            try: return int(m)
            except: return None

        m = re.search(r'_(\d{3})\/(\d{3})', payload)
        if m:
            wx_data['wind_dir']   = _int(m.group(1))
            wx_data['wind_speed'] = _int(m.group(2))  # mph

        m = re.search(r'g(\d{3})', payload)
        if m: wx_data['gust'] = _int(m.group(1))  # mph

        m = re.search(r't([\-0-9]{3})', payload)
        if m:
            try: wx_data['temp_f'] = int(m.group(1))
            except: pass

        m = re.search(r'r(\d{3})', payload)
        if m: wx_data['rain_1h'] = round(_int(m.group(1)) / 100, 2)  # inches

        m = re.search(r'p(\d{3})', payload)
        if m: wx_data['rain_24h'] = round(_int(m.group(1)) / 100, 2)  # inches

        m = re.search(r'P(\d{3})', payload)
        if m: wx_data['rain_midnight'] = round(_int(m.group(1)) / 100, 2)  # inches

        m = re.search(r'h(\d{2})', payload)
        if m: wx_data['humidity'] = _int(m.group(1))  # %

        m = re.search(r'b(\d{5})', payload)
        if m: wx_data['pressure'] = round(_int(m.group(1)) / 10, 1)  # mbar

        m = re.search(r'[Ll](\d{3,4})', payload)
        if m: wx_data['luminosity'] = _int(m.group(1))  # W/m2

        m = re.search(r's(\d{3})', payload)
        if m: wx_data['snow'] = round(_int(m.group(1)) / 10, 1)  # inches

    return {
        'call':     frm,
        'path':     path,
        'lat':      round(lat, 6),
        'lon':      round(lon, 6),
        'symbol':   symbol,
        'symtbl':   symtbl,
        'comment':  comment.strip(),
        'type':     stype,
        'wx':       wx,
        'wx_data':  wx_data,
        'speed':    speed,
        'course':   course,
        'altitude': altitude,
        'isMsg':    False,
        'ts':       int(time.time()),
    }

# ── State update ────────────────────────────────────────────────────
def update_state(pkt):
    global messages
    with lock:
        if pkt['isMsg']:
            messages.insert(0, pkt)
            messages = messages[:MAX_MESSAGES]
            stats['messages'] += 1
        else:
            call = pkt['call']
            # Update station
            stations[call] = pkt

            # Update track history
            if call not in history:
                history[call] = []
            hist = history[call]
            # Only add if position changed
            if not hist or hist[-1]['lat'] != pkt['lat'] or hist[-1]['lon'] != pkt['lon']:
                hist.append({
                    'lat':   pkt['lat'],
                    'lon':   pkt['lon'],
                    'ts':    pkt['ts'],
                    'speed': pkt['speed'],
                    'path':  pkt['path'],
                })
                history[call] = hist[-TRACK_MAX_PTS:]

            stats['positions'] += 1

        stats['packets'] += 1

def prune_old():
    """Remove stations older than MAX_AGE_HRS"""
    cutoff = time.time() - (MAX_AGE_HRS * 3600)
    with lock:
        old = [c for c,s in stations.items() if s.get('ts',0) < cutoff]
        for c in old:
            del stations[c]
            history.pop(c, None)
        if old:
            log.info(f'Pruned {len(old)} old stations')

# ── Cache writer ────────────────────────────────────────────────────
def save_cache():
    while True:
        try:
            time.sleep(SAVE_INTERVAL)
            prune_old()
            with lock:
                st_copy  = dict(stations)
                hi_copy  = dict(history)
                ms_copy  = list(messages)

            # Write atomically
            for path, data in [
                (STATIONS_FILE, st_copy),
                (HISTORY_FILE,  hi_copy),
                (MESSAGES_FILE, ms_copy),
            ]:
                tmp = path + '.tmp'
                with open(tmp, 'w') as f:
                    json.dump(data, f)
                os.replace(tmp, path)

            # Write daemon heartbeat
            daemon_state = {
                'connected': connected,
                'ts':        int(time.time()),
                'stations':  len(st_copy),
                'packets':   stats['packets'],
                'positions': stats['positions'],
                'messages':  stats['messages'],
                'uptime':    int(time.time() - stats['start']),
                'call':      APRS_CALL,
                'verified':  verified,
                'beacon':    dict(beacon_status),
            }
            tmp = DAEMON_FILE + '.tmp'
            with open(tmp, 'w') as f:
                json.dump(daemon_state, f)
            os.replace(tmp, DAEMON_FILE)

        except Exception as e:
            log.error(f'Cache save error: {e}')

# ── Load existing cache on startup ──────────────────────────────────
def load_cache():
    global messages
    for fpath, target, name in [
        (STATIONS_FILE, stations,  'stations'),
        (HISTORY_FILE,  history,   'history'),
    ]:
        if os.path.exists(fpath):
            try:
                with open(fpath) as f:
                    data = json.load(f)
                target.update(data)
                log.info(f'Loaded {len(data)} {name} from cache')
            except Exception as e:
                log.warning(f'Could not load {name}: {e}')

    if os.path.exists(MESSAGES_FILE):
        try:
            with open(MESSAGES_FILE) as f:
                messages = json.load(f)
            log.info(f'Loaded {len(messages)} messages from cache')
        except Exception as e:
            log.warning(f'Could not load messages: {e}')

# ── APRS-IS connection ──────────────────────────────────────────────
def connect_aprs():
    global sock, connected, verified
    while True:
        try:
            log.info(f'Connecting to {APRS_HOST}:{APRS_PORT}')
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.settimeout(30)
            s.connect((APRS_HOST, APRS_PORT))

            # Read banner
            banner = s.recv(512).decode('utf-8', errors='replace').strip()
            log.info(f'Banner: {banner}')

            # Login
            login = f'user {APRS_CALL} pass {APRS_PASS} vers {APRS_VERSION} filter {APRS_FILTER}\r\n'
            s.sendall(login.encode())

            # Read login response
            resp = s.recv(512).decode('utf-8', errors='replace').strip()
            log.info(f'Login: {resp}')

            verified = bool(re.search(r'\bverified\b', resp, re.I)) and 'unverified' not in resp.lower()
            if not verified:
                log.warning('Login unverified — check passcode (receive-only; no beacons)')

            s.settimeout(60)
            sock = s
            connected = True
            log.info(f'Connected and authenticated as {APRS_CALL}')
            return True

        except Exception as e:
            log.error(f'Connect failed: {e}. Retrying in {RECONNECT_WAIT}s')
            connected = False
            try: s.close()
            except: pass
            time.sleep(RECONNECT_WAIT)

def keepalive_thread():
    """Send periodic keepalive to prevent APRS-IS timeout"""
    while True:
        time.sleep(KEEPALIVE_INT)
        if connected and sock:
            try:
                with send_lock:
                    sock.sendall(b'#keepalive\r\n')
                log.debug('Keepalive sent')
            except:
                pass

def reader_thread():
    """Main packet reading loop"""
    global connected, sock
    buf = ''
    while True:
        if not connected or not sock:
            time.sleep(1)
            continue
        try:
            data = sock.recv(4096)
            if not data:
                raise ConnectionError('Server closed connection')
            
            buf += data.decode('utf-8', errors='replace')
            while '\n' in buf:
                line, buf = buf.split('\n', 1)
                line = line.strip()
                if not line:
                    continue
                if line.startswith('#'):
                    log.debug(f'Server: {line}')
                    continue
                pkt = parse_packet(line)
                if pkt:
                    update_state(pkt)

        except socket.timeout:
            # Normal — just no data, send keepalive
            if connected and sock:
                try:
                    with send_lock:
                        sock.sendall(b'#keepalive\r\n')
                except:
                    connected = False
        except Exception as e:
            log.error(f'Reader error: {e}')
            connected = False
            try: sock.close()
            except: pass
            sock = None
            time.sleep(2)
            connect_aprs()
            buf = ''

# ── Beacon (2026-10-16) ─────────────────────────────────────────────
BEACON_TOCALL = 'APRS'
_OBJ_NAME_RE  = re.compile(r'^[A-Za-z0-9 .\-/]{1,9}$')

def aprs_lat(lat):
    """41.729167 -> '4143.75N' (APRS ddmm.mm, 0.01' ~ 18 m)."""
    h = 'N' if lat >= 0 else 'S'
    lat = abs(lat)
    d = int(lat)
    m = round((lat - d) * 60, 2)
    if m >= 60:
        d, m = d + 1, 0.0
    return f'{d:02d}{m:05.2f}{h}'

def aprs_lon(lon):
    """-72.708333 -> '07242.50W'."""
    h = 'E' if lon >= 0 else 'W'
    lon = abs(lon)
    d = int(lon)
    m = round((lon - d) * 60, 2)
    if m >= 60:
        d, m = d + 1, 0.0
    return f'{d:03d}{m:05.2f}{h}'

def clean_comment(text):
    """Printable ASCII only, none of | ~ (APRS-IS reserved), 43 chars max."""
    t = ''.join(ch for ch in str(text or '') if 32 <= ord(ch) < 127 and ch not in '|~')
    return ' '.join(t.split())[:43]

def read_beacon_config():
    """Beacon settings from bbs-info.json -> dict, or (None, reason)."""
    try:
        with open(BBS_INFO_FILE) as f:
            info = json.load(f)
    except FileNotFoundError:
        return None, 'off'
    except Exception as e:
        return None, f'bbs-info.json unreadable: {e}'
    if not isinstance(info, dict) or not info.get('beacon_enabled'):
        return None, 'off'
    try:
        lat = float(info.get('lat')); lon = float(info.get('lon'))
    except (TypeError, ValueError):
        return None, 'no marker position set (BBS Info → Marker position)'
    if not (-90 <= lat <= 90 and -180 <= lon <= 180) or (lat == 0 and lon == 0):
        return None, 'marker position out of range'
    btype = 'position' if info.get('beacon_type') == 'position' else 'object'
    name  = str(info.get('beacon_name') or '').rstrip()
    if btype == 'object' and not _OBJ_NAME_RE.match(name or ''):
        return None, 'object name must be 1-9 letters, digits, space . - /'
    try:
        interval = int(info.get('beacon_interval') or 30)
    except (TypeError, ValueError):
        interval = 30
    interval = max(BEACON_MIN_INT, min(120, interval))
    return {
        'type': btype, 'name': name, 'lat': lat, 'lon': lon,
        'comment': clean_comment(info.get('beacon_comment')),
        'interval': interval,
    }, None

def build_beacon(cfg, kill=False):
    """APRS-IS line for the beacon (without CRLF)."""
    pos = aprs_lat(cfg['lat']) + '/' + aprs_lon(cfg['lon']) + 'B'   # '/B' = BBS symbol
    if cfg['type'] == 'object':
        ts = time.strftime('%d%H%Mz', time.gmtime())
        body = ';' + cfg['name'].ljust(9)[:9] + ('_' if kill else '*') + ts + pos + cfg['comment']
    else:
        body = '=' + pos + cfg['comment']        # '=' = position, messaging capable
    return f'{APRS_CALL}>{BEACON_TOCALL},TCPIP*:{body}'

def _load_beacon_state():
    try:
        with open(BEACON_STATE) as f:
            d = json.load(f)
            return d if isinstance(d, dict) else {}
    except Exception:
        return {}

def _save_beacon_state(st):
    try:
        tmp = BEACON_STATE + '.tmp'
        with open(tmp, 'w') as f:
            json.dump(st, f)
        os.replace(tmp, BEACON_STATE)
    except Exception as e:
        log.warning(f'Beacon state not saved: {e}')

def _send_line(line):
    if not (connected and sock):
        raise ConnectionError('not connected to APRS-IS')
    with send_lock:
        sock.sendall((line + '\r\n').encode('ascii'))

def beacon_tick(state, now=None):
    """One beacon check. Returns the (possibly updated) state."""
    now = now or time.time()
    cfg, why = read_beacon_config()
    last = state.get('last') or {}          # what we last sent: {type,name,lat,lon,comment,ts,packet}

    # An object that is no longer beaconed (switched off, renamed, or
    # changed to position mode): kill it once so aprs.fi drops it.
    if last.get('type') == 'object' and not last.get('killed'):
        gone = cfg is None or cfg['type'] != 'object' or cfg['name'] != last.get('name')
        if gone and connected and verified:
            try:
                line = build_beacon(dict(last), kill=True)
                _send_line(line)
                log.info(f'Beacon: object killed: {line}')
                last['killed'] = True
                state['last'] = last
                _save_beacon_state(state)
            except Exception as e:
                log.warning(f'Beacon: kill not sent: {e}')

    if cfg is None:
        beacon_status.clear()
        beacon_status.update({'enabled': False, 'state': why if why != 'off' else 'off',
                              'last_sent': last.get('ts'), 'last_packet': last.get('packet')})
        return state

    beacon_status.clear()
    beacon_status.update({'enabled': True, 'type': cfg['type'], 'name': cfg['name'],
                          'interval': cfg['interval'], 'last_sent': last.get('ts'),
                          'last_packet': last.get('packet')})
    if APRS_PASS in ('', '-1'):
        beacon_status['state'] = "receive-only login (config.php 'aprs' passcode is -1)"
        return state
    if not connected:
        beacon_status['state'] = 'waiting for APRS-IS connection'
        return state
    if not verified:
        beacon_status['state'] = 'APRS-IS login not verified (check the passcode)'
        return state

    same = (last.get('type') == cfg['type'] and last.get('name') == cfg['name']
            and last.get('lat') == cfg['lat'] and last.get('lon') == cfg['lon']
            and last.get('comment') == cfg['comment'] and not last.get('killed'))
    since = now - float(last.get('ts') or 0)
    due = since >= cfg['interval'] * 60 if same else since >= BEACON_CHANGE_GAP
    if not due:
        nxt = (cfg['interval'] * 60 if same else BEACON_CHANGE_GAP) - since
        beacon_status['state'] = 'ok'
        beacon_status['next_in'] = int(max(0, nxt))
        return state
    try:
        line = build_beacon(cfg)
        _send_line(line)
        log.info(f'Beacon sent: {line}')
        state['last'] = dict(cfg, ts=int(now), packet=line, killed=False)
        _save_beacon_state(state)
        beacon_status.update({'state': 'ok', 'last_sent': int(now), 'last_packet': line,
                              'next_in': cfg['interval'] * 60})
    except Exception as e:
        beacon_status['state'] = f'send failed: {e}'
        log.warning(f'Beacon not sent: {e}')
    return state

def beacon_thread():
    state = _load_beacon_state()
    time.sleep(10)   # let the login settle
    while True:
        try:
            state = beacon_tick(state)
        except Exception as e:
            log.error(f'Beacon error: {e}')
        time.sleep(BEACON_CHECK)

# ── Main ────────────────────────────────────────────────────────────
if __name__ == '__main__':
    log.info('BPQ APRS Daemon starting')
    load_cache()

    # Start cache writer thread
    t_cache = threading.Thread(target=save_cache, daemon=True)
    t_cache.start()

    # Connect
    connect_aprs()

    # Start keepalive thread
    t_ka = threading.Thread(target=keepalive_thread, daemon=True)
    t_ka.start()

    # Beacon (off unless switched on in BBS Info)
    t_bc = threading.Thread(target=beacon_thread, daemon=True)
    t_bc.start()

    # Start reader (blocks forever)
    log.info('Reader starting')
    reader_thread()
