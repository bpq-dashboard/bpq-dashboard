#!/usr/bin/env python3
"""
bpq-aprs-daemon.py — Persistent APRS-IS connection daemon for BPQ Dashboard
Stays connected to APRS-IS 24/7, parses packets, writes cache files.
Managed by systemd as bpq-aprs.service
"""

import socket
import json
import time
import re
import os
import fcntl
import threading
import logging
import math
from datetime import datetime

# ── Config ─────────────────────────────────────────────────────────
APRS_HOST    = 'rotate.aprs2.net'
APRS_PORT    = 14580
APRS_CALL    = 'YOURCALL-1'
APRS_PASS    = '15769'
APRS_FILTER  = 'r/0.0000/-0.0000/300'
APRS_VERSION = 'BPQ-Dashboard 1.0'   # Must be: name SPACE version

CACHE_DIR    = '/var/www/bpqdash/cache/aprs'
STATIONS_FILE= os.path.join(CACHE_DIR, 'stations.json')
HISTORY_FILE = os.path.join(CACHE_DIR, 'history.json')
MESSAGES_FILE= os.path.join(CACHE_DIR, 'messages.json')
DAEMON_FILE  = os.path.join(CACHE_DIR, 'aprs-daemon.json')
LOCK_FILE    = '/tmp/bpq-aprs-daemon.lock'

MAX_STATIONS  = 1000
MAX_MESSAGES  = 200
MAX_AGE_HRS   = 12          # keep stations heard within 12 hours
TRACK_MAX_PTS = 500         # max track points per station
SAVE_INTERVAL = 15          # write cache every N seconds
KEEPALIVE_INT = 180         # send keepalive every 3 minutes
RECONNECT_WAIT= 30          # seconds before reconnect attempt

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
sock      = None
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
    global sock, connected
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

            if 'unverified' in resp.lower():
                log.warning('Login unverified — check passcode')
            
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

    # Start reader (blocks forever)
    log.info('Reader starting')
    reader_thread()
