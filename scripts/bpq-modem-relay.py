#!/usr/bin/env python3
# =============================================================================
# bpq-modem-relay.py — watch-only relay for the modems' own monitors (v1.5.97)
#
# Admin > Modems shows Mercury's waterfall and Data2G's monitor without
# opening the station's desktop. Both modems serve their monitor over a
# WebSocket that ALSO takes commands (Mercury: audio, PTT, TX level; Data2G:
# abort, monitor, settings), so the browser is never connected to them.
# This relay is: it connects to the modem on 127.0.0.1, keeps what the
# monitor shows, and hands it to admin/modems-api.php over plain HTTP on
# 127.0.0.1 only. It sends the modems nothing but Data2G's own "hello"
# (where to start, 5 rows a second); no command ever goes back.
#
#   GET /mercury?port=8403&since=N
#   GET /data2g?port=8520&since=N      header X-Data2G-Session: <token>
#   GET /health
#
# A modem is only connected while someone is watching: 30 s after the last
# request the connection is closed again (Mercury logs every client).
# Python standard library only (its own small WebSocket client).
#
# v1.5.98: started by systemd on the first request (bpq-modem-relay.socket
# listens on 127.0.0.1:8769 and hands the socket over), and exits after
# 10 minutes with nobody watching, so it uses nothing on a station where the
# Modems page isn't open. Run by hand it listens itself: --port 8769.
# =============================================================================
import argparse, asyncio, base64, collections, hashlib, json, os, socket, struct, sys, time
from urllib.parse import urlsplit, parse_qs

IDLE_CLOSE = 30          # s without a request: drop the modem connection
IDLE_EXIT = 600          # s without a request: exit (systemd starts us again on the next one)
ROWS_KEPT = 240          # waterfall rows kept for late or slow viewers
MERCURY_MAX_FPS = 8      # rows a second kept from Mercury (it sends more)
MERCURY_MAGIC = 0x4D435259
MERCURY_TOP_HZ = 3100    # what Mercury's own page shows
DB_LO, DB_HI = -120.0, 0.0
STATUS_KEYS = ('snr', 'bitrate', 'direction', 'sync', 'user_callsign', 'dest_callsign',
               'client_tcp_connected', 'bytes_transmitted', 'bytes_received')


def log(msg):
    sys.stdout.write(time.strftime('%Y-%m-%d %H:%M:%S ') + msg + '\n')
    sys.stdout.flush()


# ── a minimal WebSocket client (RFC 6455): enough to read a monitor ─────────
class WsError(Exception):
    pass


async def ws_connect(port, path, headers=None, timeout=5):
    r, w = await asyncio.wait_for(asyncio.open_connection('127.0.0.1', port), timeout)
    key = base64.b64encode(os.urandom(16)).decode()
    req = (f'GET {path} HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nUpgrade: websocket\r\n'
           f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n')
    for k, v in (headers or {}).items():
        req += f'{k}: {v}\r\n'
    w.write((req + '\r\n').encode())
    await w.drain()
    head = await asyncio.wait_for(r.readuntil(b'\r\n\r\n'), timeout)
    status = head.split(b'\r\n', 1)[0].decode('latin-1')
    code = int(status.split()[1]) if len(status.split()) > 1 and status.split()[1].isdigit() else 0
    if code != 101:
        w.close()
        raise WsError(f'HTTP {code}')
    want = base64.b64encode(hashlib.sha1((key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest())
    if want not in head:
        w.close()
        raise WsError('bad handshake')
    return r, w


async def ws_send_text(w, text):
    data = text.encode()
    mask = os.urandom(4)
    n = len(data)
    hdr = bytes([0x81]) + (bytes([0x80 | n]) if n < 126 else bytes([0x80 | 126]) + struct.pack('>H', n))
    w.write(hdr + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))
    await w.drain()


async def ws_recv(r, w):
    """One whole message: (opcode, bytes). Answers pings itself."""
    buf, op0 = b'', None
    while True:
        b1, b2 = await r.readexactly(2)
        fin, op, n = b1 & 0x80, b1 & 0x0F, b2 & 0x7F
        if n == 126:
            n = struct.unpack('>H', await r.readexactly(2))[0]
        elif n == 127:
            n = struct.unpack('>Q', await r.readexactly(8))[0]
        if n > 8 * 1024 * 1024:
            raise WsError('frame too large')
        mask = await r.readexactly(4) if b2 & 0x80 else None
        data = await r.readexactly(n)
        if mask:
            data = bytes(b ^ mask[i % 4] for i, b in enumerate(data))
        if op == 0x8:
            raise WsError('closed by the modem')
        if op == 0x9:                                   # ping -> pong
            m = os.urandom(4)
            w.write(bytes([0x8A, 0x80 | len(data)]) + m + bytes(b ^ m[i % 4] for i, b in enumerate(data)))
            continue
        if op == 0xA:
            continue
        if op in (0x1, 0x2):
            op0 = op
        buf += data
        if fin:
            return op0, buf


# ── one watched modem ────────────────────────────────────────────────────────
class Feed:
    def __init__(self, kind, port, token=''):
        self.kind, self.port, self.token = kind, port, token
        self.rows = collections.deque(maxlen=ROWS_KEPT)   # (seq, dict)
        self.seq = 0
        self.state = {}
        self.bursts = collections.deque(maxlen=60)
        self.error = ''
        self.connected = False
        self.last_req = time.monotonic()
        self.task = None
        self.d2g_seq = {'b': 0, 'd': 0, 'c': 0, 'w': 0}
        self._last_row_t = 0.0

    def touch(self):
        self.last_req = time.monotonic()
        if not self.task or self.task.done():
            self.task = asyncio.ensure_future(self.run())

    async def run(self):
        backoff = 2
        while time.monotonic() - self.last_req < IDLE_CLOSE:
            w = None
            try:
                if self.kind == 'mercury':
                    r, w = await ws_connect(self.port, '/websocket')
                else:
                    if not self.token:
                        raise WsError('log in')
                    r, w = await ws_connect(self.port, '/ws', {'Cookie': f'data2g_session={self.token}'})
                    hello = dict(cmd='hello', fmt='text', fps=5, **self.d2g_seq)
                    await ws_send_text(w, json.dumps(hello))
                self.connected, self.error, backoff = True, '', 2
                log(f'{self.kind}:{self.port} connected')
                while time.monotonic() - self.last_req < IDLE_CLOSE:
                    try:
                        op, data = await asyncio.wait_for(ws_recv(r, w), 5)
                    except asyncio.TimeoutError:
                        continue
                    if self.kind == 'mercury':
                        self.mercury_msg(op, data)
                    else:
                        self.data2g_msg(data)
                log(f'{self.kind}:{self.port} nobody watching: closed')
            except (OSError, asyncio.IncompleteReadError, asyncio.TimeoutError, WsError, ValueError) as e:
                msg = str(e) or e.__class__.__name__
                if isinstance(e, ConnectionRefusedError):
                    msg = 'not listening (monitor switched off, or the modem is not running)'
                elif msg == 'HTTP 401':
                    msg = 'log in'
                elif msg == 'HTTP 503':
                    msg = 'no monitor password set on the modem'
                self.error = msg
                if msg != 'log in':
                    log(f'{self.kind}:{self.port} {msg}')
            finally:
                self.connected = False
                if w:
                    try:
                        w.close()
                    except Exception:
                        pass
            if self.error == 'log in':
                return
            await asyncio.sleep(backoff)
            backoff = min(backoff * 2, 20)

    def add_row(self, row):
        self.seq += 1
        self.rows.append((self.seq, row))

    # Mercury: binary spectrum frames + JSON status
    def mercury_msg(self, op, data):
        if op == 0x1:
            try:
                m = json.loads(data)
            except ValueError:
                return
            if isinstance(m, dict) and m.get('type') == 'status':
                self.state = {k: m.get(k) for k in STATUS_KEYS}
                self.state['t'] = time.time()
            return
        if len(data) < 8:
            return
        magic, fft, rate = struct.unpack('<IHH', data[:8])
        if magic != MERCURY_MAGIC or fft == 0 or len(data) < 8 + fft * 4 or rate == 0:
            return
        now = time.monotonic()
        if now - self._last_row_t < 1.0 / MERCURY_MAX_FPS:
            return
        self._last_row_t = now
        vals = struct.unpack(f'<{fft}f', data[8:8 + fft * 4])
        bins = max(1, min(fft, round(MERCURY_TOP_HZ / (rate / 2) * fft)))
        width = min(512, bins)
        out = bytearray(width)
        for i in range(width):
            a, b = i * bins // width, max(i * bins // width + 1, (i + 1) * bins // width)
            v = max(vals[a:b])
            out[i] = max(0, min(255, int((v - DB_LO) * 255 / (DB_HI - DB_LO))))
        self.add_row({'b': base64.b64encode(bytes(out)).decode(), 'tx': self.state.get('direction') == 'tx'})

    # Data2G: JSON state every 100 ms with what is new since our seq
    def data2g_msg(self, data):
        try:
            s = json.loads(data)
        except ValueError:
            return
        if not isinstance(s, dict):
            return
        for r in s.get('rows') or []:
            if isinstance(r, dict) and isinstance(r.get('bins'), str):
                self.add_row({'b': r['bins'], 'tx': bool(r.get('tx')), 'peak': r.get('peak')})
        for b in s.get('bursts') or []:
            if isinstance(b, dict):
                self.bursts.append({k: b.get(k) for k in ('t', 'mode', 'snr', 'result')})
        keep = {k: s.get(k) for k in ('station', 'running', 'link', 'busy', 'ptt', 'dial', 'counters', 'monitoring')
                if k in s}
        if s.get('constellation') is not None:
            keep['constellation'] = s.get('constellation')
        elif 'constellation' in self.state:
            keep['constellation'] = self.state['constellation']
        keep['t'] = time.time()
        self.state = keep
        if isinstance(s.get('seq'), dict):
            self.d2g_seq.update({k: int(v) for k, v in s['seq'].items() if k in self.d2g_seq and isinstance(v, (int, float))})

    def snapshot(self, since):
        rows = [dict(r, s=n) for n, r in self.rows if n > since]
        if since > self.seq:                      # relay restarted: send what we have
            rows = [dict(r, s=n) for n, r in self.rows]
        return {'ok': True, 'connected': self.connected, 'error': self.error, 'seq': self.seq,
                'rows': rows[-120:], 'state': self.state,
                'bursts': list(self.bursts)[-30:] if self.kind == 'data2g' else []}


FEEDS = {}
LAST_REQUEST = [time.monotonic()]


def feed_for(kind, port, token=''):
    key = (kind, port)
    f = FEEDS.get(key)
    if f is None:
        f = FEEDS[key] = Feed(kind, port, token)
    if kind == 'data2g' and token and token != f.token:
        f.token, f.error = token, ''
        f.rows.clear(); f.bursts.clear(); f.state = {}     # nothing from another login's session
        f.d2g_seq = {'b': 0, 'd': 0, 'c': 0, 'w': 0}
        if f.task and not f.task.done():
            f.task.cancel()
        f.task = None
    return f


async def handle(reader, writer):
    try:
        head = await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'), 5)
    except Exception:
        writer.close()
        return
    lines = head.decode('latin-1').split('\r\n')
    parts = lines[0].split()
    hdrs = {}
    for l in lines[1:]:
        if ':' in l:
            k, v = l.split(':', 1)
            hdrs[k.strip().lower()] = v.strip()
    code, body = 404, {'ok': False, 'error': 'not found'}
    LAST_REQUEST[0] = time.monotonic()
    if len(parts) >= 2 and parts[0] == 'GET':
        u = urlsplit(parts[1])
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        try:
            port = int(q.get('port', '0'))
            since = int(q.get('since', '0'))
        except ValueError:
            port, since = 0, 0
        if u.path == '/health':
            code, body = 200, {'ok': True, 'feeds': [f'{k}:{p}' for k, p in FEEDS]}
        elif u.path in ('/mercury', '/data2g'):
            if not 1 <= port <= 65535:
                code, body = 400, {'ok': False, 'error': 'bad port'}
            else:
                tok = hdrs.get('x-data2g-session', '')
                if tok and not all(c in '0123456789abcdef' for c in tok.lower()):
                    tok = ''
                f = feed_for(u.path[1:], port, tok)
                f.touch()
                code, body = 200, f.snapshot(since)
    data = json.dumps(body).encode()
    writer.write(f'HTTP/1.1 {code} X\r\nContent-Type: application/json\r\nContent-Length: {len(data)}\r\n'
                 f'Cache-Control: no-store\r\nConnection: close\r\n\r\n'.encode() + data)
    try:
        await writer.drain()
    finally:
        writer.close()


def systemd_socket():
    """The listening socket systemd passed us (socket activation), or None."""
    try:
        if int(os.environ.get('LISTEN_PID', '0')) != os.getpid() or int(os.environ.get('LISTEN_FDS', '0')) < 1:
            return None
    except ValueError:
        return None
    return socket.socket(fileno=3)


async def idle_exit(on_demand):
    while True:
        await asyncio.sleep(30)
        if on_demand and time.monotonic() - LAST_REQUEST[0] > IDLE_EXIT:
            log(f'nobody has looked for {IDLE_EXIT // 60} minutes: stopping (started again on the next request)')
            for f in FEEDS.values():
                if f.task and not f.task.done():
                    f.task.cancel()
            return


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--port', type=int, default=8769)
    a = ap.parse_args()
    sock = systemd_socket()
    if sock is not None:
        srv = await asyncio.start_server(handle, sock=sock)
        log('modem relay started on request (watch-only)')
    else:
        srv = await asyncio.start_server(handle, '127.0.0.1', a.port)
        log(f'modem relay listening on 127.0.0.1:{a.port} (watch-only)')
    async with srv:
        await idle_exit(sock is not None)


if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
