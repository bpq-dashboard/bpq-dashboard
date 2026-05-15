#!/usr/bin/env python3
"""
bpq-vara-daemon.py — WebSocket to BPQ Telnet proxy for VARA HF terminal
BPQ Dashboard v1.5.6

Bridges browser WebSocket connections to BPQ telnet port 8010,
logging in and attaching to Port 3 (VARA HF) for keyboard-to-keyboard
HF sessions via the VARA modem.

BPQ session sequence:
  Login → NODE → ATT 3 → [VARA HF session] → D → BYE

Run as: python3 /var/www/bpq-dashboard/scripts/bpq-vara-daemon.py
Service: bpq-vara.service
"""

import asyncio
import json
import logging
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

# ── Configuration ──────────────────────────────────────────────────
import os as _os
_candidates = ['/var/www/bpq-dashboard', '/var/www/html/bpq', '/var/www/bpq']
WEB_ROOT = next((p for p in _candidates if _os.path.isdir(p)), '/var/www/html/bpq')

STATE_DIR   = WEB_ROOT + '/cache/vara-sessions'
DAEMON_FILE = STATE_DIR + '/vara-daemon.json'
LOG_FILE    = WEB_ROOT + '/logs/bpq-vara-daemon.log'
WS_HOST     = '127.0.0.1'
WS_PORT     = 8767

BPQ_HOST    = '127.0.0.1'
BPQ_PORT    = 8010
BPQ_USER    = 'YOURCALL'        # User configured with default app=BBS in bpq32.cfg.
BPQ_PASS    = 'dawgs1958'    # YOURCALL has full ATT permission via the BBS→NODE
                              # escape path (verified manually 2026-05-01).
                              # SYSOPUSER lands at NODE directly but ATT silently
                              # fails for him; BPQ apparently restricts ATT to
                              # users that came in via BBS.
BPQ_VARA_PORT = 3            # BPQ port number for VARA HF

MAX_SESSIONS = 3
SESSION_TIMEOUT = 7200       # 2 hour idle timeout

# ── Logging ────────────────────────────────────────────────────────
Path(STATE_DIR).mkdir(parents=True, exist_ok=True)
Path(WEB_ROOT + '/logs').mkdir(parents=True, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)s %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout)
    ]
)
log = logging.getLogger('bpq-vara')

# ── VARA status line patterns ──────────────────────────────────────
# BPQ outputs these during VARA HF sessions — we parse and forward as events
VARA_PATTERNS = [
    (re.compile(r'CONNECTED\s+TO\s+(\S+)', re.I),        'connected',    lambda m: {'remote': m.group(1).upper()}),
    (re.compile(r'DISCONNECTED',           re.I),         'disconnected', lambda m: {}),
    (re.compile(r'LINK\s+ESTABLISHED',     re.I),         'link_up',      lambda m: {}),
    (re.compile(r'LINK\s+DISCONNECTED',    re.I),         'link_down',    lambda m: {}),
    (re.compile(r'BUSY\s+CHANNEL',         re.I),         'busy',         lambda m: {}),
    (re.compile(r'Failure\s+with\s+(\S+)', re.I),        'failure',      lambda m: {'remote': m.group(1).upper()}),
    (re.compile(r'CANCELPENDING',           re.I),         'cancelled',    lambda m: {}),
    (re.compile(r'TIMEOUT',                 re.I),         'timeout',      lambda m: {}),
    (re.compile(r'^PENDING\s+(\S+)',        re.I|re.M),    'pending',      lambda m: {'remote': m.group(1).upper()}),
    (re.compile(r'BUFFER\s+(\d+)',          re.I),         'buffer',       lambda m: {'bytes': int(m.group(1))}),
    (re.compile(r'IAMALIVE',               re.I),         'keepalive',    lambda m: {}),
    (re.compile(r'S/N\s*([-\d]+)\s*dB',   re.I),         'snr',          lambda m: {'snr': int(m.group(1))}),
    (re.compile(r'BW(\d+)',                re.I),         'bandwidth',    lambda m: {'bw': f'BW{m.group(1)}'}),
    (re.compile(r'ATTACHED\s+TO\s+PORT',   re.I),         'attached',     lambda m: {}),
    (re.compile(r'PORT\s+IN\s+USE',        re.I),         'port_in_use',  lambda m: {}),
    (re.compile(r'INVALID\s+PORT',         re.I),         'invalid_port', lambda m: {}),
]

# ── Active sessions ────────────────────────────────────────────────
sessions = {}

def write_heartbeat():
    try:
        data = {
            'running': True,
            'pid': os.getpid(),
            'ws_port': WS_PORT,
            'active_sessions': len(sessions),
            'updated': datetime.now(timezone.utc).isoformat(),
            'updated_ts': int(time.time()),
        }
        with open(DAEMON_FILE, 'w') as f:
            json.dump(data, f)
    except Exception as e:
        log.warning(f"Heartbeat write failed: {e}")


class VARASession:
    """Manages one VARA HF session through BPQ telnet."""

    def __init__(self, session_id, ws):
        self.session_id    = session_id
        self.ws            = ws
        self.reader        = None
        self.writer        = None
        self.connected     = False
        self.vara_attached = False
        self.vara_connected = False
        self.remote_call   = None
        self.started       = time.time()
        self.last_activity = time.time()
        self.bytes_rx      = 0
        self.bytes_tx      = 0

    async def send_event(self, evt_type: str, data: dict = {}):
        """Send a JSON status event to the browser."""
        try:
            await self.ws.send(json.dumps({'type': 'event', 'event': evt_type, **data}))
        except Exception:
            pass

    async def send_data(self, text: str):
        """Send raw terminal data to browser."""
        try:
            await self.ws.send(json.dumps({'type': 'data', 'data': text}))
        except Exception:
            pass

    async def read_until(self, pattern: str, timeout: float = 8.0) -> bytes:
        """Read from BPQ until pattern appears in clean (IAC-stripped) buffer."""
        buf = b''
        deadline = asyncio.get_event_loop().time() + timeout
        while asyncio.get_event_loop().time() < deadline:
            try:
                chunk = await asyncio.wait_for(self.reader.read(512), timeout=1.0)
                if not chunk:
                    break
                buf += chunk
                clean = re.sub(b'\xff[\xfb\xfc\xfd\xfe].', b'', buf)
                if pattern.encode() in clean or pattern.lower().encode() in clean.lower():
                    return buf
            except asyncio.TimeoutError:
                continue
        return buf

    def strip_iac(self, data: bytes) -> bytes:
        return re.sub(b'\xff[\xfb\xfc\xfd\xfe].', b'', data)

    def respond_iac(self, data: bytes) -> bytes:
        """Build IAC DO response for any IAC WILL offers."""
        resp = bytearray()
        i = 0
        while i < len(data):
            if data[i] == 0xff and i + 2 < len(data):
                cmd, opt = data[i+1], data[i+2]
                if cmd == 0xfb:   # WILL → DO
                    resp += bytes([0xff, 0xfd, opt])
                elif cmd == 0xfd: # DO → WILL
                    resp += bytes([0xff, 0xfb, opt])
                i += 3
            else:
                i += 1
        return bytes(resp)

    async def connect_bpq(self) -> bool:
        """Open TCP connection to BPQ telnet."""
        try:
            self.reader, self.writer = await asyncio.wait_for(
                asyncio.open_connection(BPQ_HOST, BPQ_PORT), timeout=10.0
            )
            self.connected = True
            log.info(f"Session {self.session_id}: connected to BPQ {BPQ_HOST}:{BPQ_PORT}")
            return True
        except Exception as e:
            log.warning(f"Session {self.session_id}: BPQ connect failed: {e}")
            return False

    async def bpq_login(self) -> bool:
        """Login to BPQ telnet and attach to VARA HF port.
        
        Handles BPQ users with two different default-app configurations:
          - User configured with default app BBS: lands at BBS prompt
            (e.g. 'de USER>'); we send 'NODE' to escape into Node.
          - User configured with default app NODE: lands directly at
            Node (typically prefaced by a node table dump); we proceed
            straight to ATT.
        """
        try:
            # Step 1 — IAC + username
            buf = await self.read_until('user:', 5.0)
            iac = self.respond_iac(buf)
            if iac:
                self.writer.write(iac)
                await self.writer.drain()
            # Drain extra IAC bursts
            drain_end = asyncio.get_event_loop().time() + 0.4
            while asyncio.get_event_loop().time() < drain_end:
                try:
                    extra = await asyncio.wait_for(self.reader.read(64), timeout=0.1)
                    if extra:
                        r = self.respond_iac(extra)
                        if r:
                            self.writer.write(r)
                            await self.writer.drain()
                except asyncio.TimeoutError:
                    break

            self.writer.write((BPQ_USER + '\r\n').encode())
            await self.writer.drain()
            log.info(f"Session {self.session_id}: sent username")

            # Step 2 — password
            await self.read_until('password:', 5.0)
            self.writer.write((BPQ_PASS + '\r\n').encode())
            await self.writer.drain()
            log.info(f"Session {self.session_id}: sent password")

            # Step 3 — detect whether user landed in BBS or NODE
            # BPQ users with default app=BBS land at BBS prompt 'de USER>'.
            # Users with default app=NODE land directly at Node prompt,
            # typically prefaced by a node-table dump that may be quite long
            # (taking several seconds to fully arrive).
            login_buf = b''
            collect_end = asyncio.get_event_loop().time() + 5.0
            last_data_at = asyncio.get_event_loop().time()
            while asyncio.get_event_loop().time() < collect_end:
                try:
                    chunk = await asyncio.wait_for(self.reader.read(2048), timeout=0.5)
                    if chunk:
                        login_buf += chunk
                        last_data_at = asyncio.get_event_loop().time()
                except asyncio.TimeoutError:
                    # No data in 0.5s — if we already have data and idle for >0.8s, done
                    if login_buf and (asyncio.get_event_loop().time() - last_data_at) >= 0.8:
                        break
                    continue
            
            clean = self.strip_iac(login_buf).decode('utf-8', errors='replace')
            
            in_bbs = f'de {BPQ_USER}>' in clean
            # Node detection — any of these patterns indicate we're in Node:
            #   1. ">" within the last 30 chars (clean prompt)
            #   2. "Returned to Node" string
            #   3. Node table content like "BBS:CALL-N" or "NDE:CALL-N" patterns
            #      that BPQ sends when the user lands directly in Node
            import re as _re_node
            has_node_table = bool(_re_node.search(r'(BBS|CHT|NDE|NODE):[A-Z0-9]+-\d+', clean))
            in_node = (not in_bbs) and (
                '>' in clean[-30:] or
                'Returned to Node' in clean or
                has_node_table
            )
            
            if not in_bbs and not in_node:
                log.warning(f"Session {self.session_id}: login state unclear. Got: {repr(clean[-120:])}")
                return False
            
            if in_bbs:
                log.info(f"Session {self.session_id}: BBS login OK (user landed in BBS)")
                # Step 4a — switch to node from BBS
                self.writer.write(b'NODE\r\n')
                await self.writer.drain()
                buf = await self.read_until('Returned to Node', 5.0)
                clean = self.strip_iac(buf).decode('utf-8', errors='replace')
                if 'Returned to Node' not in clean:
                    log.warning(f"Session {self.session_id}: NODE escape failed. Got: {repr(clean[-60:])}")
                    return False
                # Drain node prompt
                try:
                    await asyncio.wait_for(self.reader.read(64), timeout=1.0)
                except asyncio.TimeoutError:
                    pass
            else:
                log.info(f"Session {self.session_id}: NODE login OK (user landed directly in Node)")
                # Already at node prompt — drain any residual node-table output
                try:
                    extra_drain_end = asyncio.get_event_loop().time() + 1.5
                    while asyncio.get_event_loop().time() < extra_drain_end:
                        try:
                            await asyncio.wait_for(self.reader.read(1024), timeout=0.3)
                        except asyncio.TimeoutError:
                            break
                except Exception:
                    pass
            
            log.info(f"Session {self.session_id}: at node prompt")
            await asyncio.sleep(1.0)  # let BPQ fully settle at node prompt

            # Step 5 — attach to VARA HF port
            self.writer.write(f'ATT {BPQ_VARA_PORT}\r\n'.encode())
            await self.writer.drain()
            # Wait up to 15s for "Ok" — generous margin for the extra TCP hop
            # through the vara-callsign-validator proxy (BPQ → 9025 → validator → 8300).
            # Was 6.0s when BPQ talked direct to VARA, now needs more headroom.
            buf = await self.read_until('Ok', 15.0)
            # DIAGNOSTIC: log raw bytes received so we can see what BPQ actually sent
            log.info(f"Session {self.session_id}: ATT raw response ({len(buf)} bytes): {repr(buf)}")
            clean = self.strip_iac(buf).decode('utf-8', errors='replace').lower()
            if 'port in use' in clean:
                await self.send_event('error', {'message': 'VARA HF port is in use — try again shortly'})
                return False
            if 'invalid port' in clean:
                await self.send_event('error', {'message': f'BPQ Port {BPQ_VARA_PORT} is not a VARA port'})
                return False
            if 'ok' not in clean:
                log.warning(f"Session {self.session_id}: ATT failed. Got: {repr(clean[-60:])}")
                return False

            await asyncio.sleep(0.5)  # let VARA port fully initialise
            self.vara_attached = True
            log.info(f"Session {self.session_id}: attached to VARA HF port {BPQ_VARA_PORT}")
            await self.send_event('attached', {'port': BPQ_VARA_PORT})
            return True

        except Exception as e:
            log.warning(f"Session {self.session_id}: login error: {e}")
            return False

    def parse_vara_status(self, text: str):
        """Check text for VARA status lines, return list of events."""
        events = []
        for pattern, evt_type, extractor in VARA_PATTERNS:
            m = pattern.search(text)
            if m:
                events.append((evt_type, extractor(m)))
        return events

    async def bpq_to_ws(self):
        """Read from BPQ telnet, forward to WebSocket, parse VARA status."""
        try:
            while self.connected:
                try:
                    data = await asyncio.wait_for(self.reader.read(4096), timeout=1.0)
                    if not data:
                        break
                    self.bytes_rx += len(data)
                    self.last_activity = time.time()
                    clean = self.strip_iac(data)
                    if clean:
                        text = clean.decode('utf-8', errors='replace')
                        # Forward raw data to browser
                        await self.send_data(text)
                        # Parse for VARA status events
                        for evt_type, evt_data in self.parse_vara_status(text):
                            await self.send_event(evt_type, evt_data)
                            if evt_type == 'connected':
                                self.vara_connected = True
                                self.remote_call = evt_data.get('remote', '')
                                log.info(f"Session {self.session_id}: VARA connected to {self.remote_call}")
                            elif evt_type in ('disconnected', 'link_down'):
                                self.vara_connected = False
                                self.remote_call = None
                                log.info(f"Session {self.session_id}: VARA disconnected")
                except asyncio.TimeoutError:
                    if time.time() - self.last_activity > SESSION_TIMEOUT:
                        log.info(f"Session {self.session_id}: idle timeout")
                        break
                    continue
        except Exception as e:
            log.info(f"Session {self.session_id}: bpq_to_ws ended: {e}")
        finally:
            await self.disconnect()

    async def send_to_bpq(self, text: str):
        """Send text from browser to BPQ telnet."""
        if not self.connected or not self.writer:
            return
        try:
            self.writer.write((text + '\r\n').encode('utf-8', errors='replace'))
            await self.writer.drain()
            self.bytes_tx += len(text)
            self.last_activity = time.time()
        except Exception as e:
            log.warning(f"Session {self.session_id}: send failed: {e}")
            await self.disconnect()

    async def disconnect(self):
        """Release VARA port and close BPQ connection cleanly."""
        if not self.connected:
            return
        self.connected = False
        try:
            if self.writer and self.vara_attached:
                # D releases VARA port, BYE closes telnet
                self.writer.write(b'D\r\nBYE\r\n')
                await self.writer.drain()
                await asyncio.sleep(0.8)  # let BPQ fully release VARA port
        except Exception:
            pass
        try:
            if self.writer:
                self.writer.close()
                await self.writer.wait_closed()
        except Exception:
            pass
        self.vara_attached  = False
        self.vara_connected = False
        log.info(f"Session {self.session_id}: disconnected (rx={self.bytes_rx} tx={self.bytes_tx})")
        try:
            await self.ws.send(json.dumps({'type': 'disconnected'}))
        except Exception:
            pass
        sessions.pop(self.session_id, None)
        write_heartbeat()


async def handle_websocket(websocket, path=None):
    """Handle incoming WebSocket connection from browser."""
    session_id = f"v{int(time.time()*1000) % 100000}"
    log.info(f"WebSocket connected: {session_id}")

    if len(sessions) >= MAX_SESSIONS:
        await websocket.send(json.dumps({
            'type': 'error',
            'message': f'Max sessions ({MAX_SESSIONS}) reached'
        }))
        return

    session = None
    try:
        async for raw_msg in websocket:
            try:
                msg = json.loads(raw_msg)
            except json.JSONDecodeError:
                continue

            action = msg.get('action', '')

            if action == 'connect':
                # Connect to BPQ and attach VARA HF port
                if session and session.connected:
                    await session.disconnect()

                session = VARASession(session_id, websocket)
                sessions[session_id] = session

                await websocket.send(json.dumps({'type': 'connecting'}))

                if not await session.connect_bpq():
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': 'Could not connect to BPQ telnet'
                    }))
                    sessions.pop(session_id, None)
                    continue

                if not await session.bpq_login():
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': 'BPQ login or VARA attach failed — check daemon log'
                    }))
                    await session.disconnect()
                    sessions.pop(session_id, None)
                    continue

                await websocket.send(json.dumps({
                    'type': 'connected',
                    'session_id': session_id,
                    'vara_port': BPQ_VARA_PORT
                }))
                write_heartbeat()
                asyncio.ensure_future(session.bpq_to_ws())

            elif action == 'send':
                if session and session.connected:
                    await session.send_to_bpq(msg.get('data', ''))
                else:
                    await websocket.send(json.dumps({'type': 'error', 'message': 'Not connected'}))

            elif action == 'disconnect':
                if session:
                    await session.disconnect()
                break

            elif action == 'ping':
                await websocket.send(json.dumps({'type': 'pong'}))

    except Exception as e:
        log.info(f"Session {session_id}: WebSocket closed: {e}")
    finally:
        if session:
            await session.disconnect()
        sessions.pop(session_id, None)
        write_heartbeat()
        log.info(f"Session {session_id}: cleaned up")


async def heartbeat_loop():
    while True:
        write_heartbeat()
        await asyncio.sleep(30)


async def main():
    try:
        import websockets
    except ImportError:
        log.error("websockets not installed. Run: pip3 install websockets --break-system-packages")
        sys.exit(1)

    log.info(f"BPQ VARA Daemon starting on {WS_HOST}:{WS_PORT}")
    write_heartbeat()
    log.info(f"websockets version: {websockets.__version__}")

    async with websockets.serve(
        handle_websocket,
        WS_HOST,
        WS_PORT,
        ping_interval=None,
        ping_timeout=None,
    ):
        log.info(f"VARA daemon ready — ws://{WS_HOST}:{WS_PORT}")
        await asyncio.gather(asyncio.Future(), heartbeat_loop())


if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        log.info("VARA daemon stopped")
