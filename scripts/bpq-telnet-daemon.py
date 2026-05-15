#!/usr/bin/env python3
"""
bpq-telnet-daemon.py — WebSocket to TCP Telnet proxy daemon
BPQ Dashboard v1.5.6

Bridges browser WebSocket connections to TCP telnet targets.
Supports multiple simultaneous sessions.
Reads config from /var/www/html/bpq/config.php via JSON sidecar.

Run as: python3 /var/www/bpq-dashboard/scripts/bpq-telnet-daemon.py  (BPQHOST)
         python3 /var/www/html/bpq/scripts/bpq-telnet-daemon.py  (generic)
Service: bpq-telnet.service
"""

import asyncio
import json
import logging
import os
import re
import signal
import socket
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

# ── Configuration ──────────────────────────────────────────────────
# Auto-detect web root — works for both BPQHOST and generic installs
import os as _os
_candidates = ['/var/www/bpq-dashboard', '/var/www/html/bpq', '/var/www/bpq']
WEB_ROOT = next((p for p in _candidates if _os.path.isdir(p)), '/var/www/html/bpq')

STATE_DIR  = WEB_ROOT + '/cache/telnet-sessions'
DAEMON_FILE= STATE_DIR + '/telnet-daemon.json'
LOG_FILE   = WEB_ROOT + '/logs/bpq-telnet-daemon.log'
WS_HOST    = '127.0.0.1'
WS_PORT    = 8765          # WebSocket port — nginx proxies /ws/telnet here
MAX_SESSIONS = 10          # Max simultaneous telnet sessions
SESSION_TIMEOUT = 3600     # 1 hour idle timeout

# Allowed telnet targets — prevents open relay abuse
ALLOWED_HOSTS = [
    '127.0.0.1',
    'localhost',
    'server.winlink.org',
    'cms.winlink.org',
]
ALLOWED_PORTS = [23, 8010, 8011, 8008, 8772]  # telnet, BPQ, FBB, WL2K

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
log = logging.getLogger('bpq-telnet')

# ── Active sessions registry ───────────────────────────────────────
sessions = {}   # session_id → TelnetSession

def write_heartbeat():
    try:
        data = {
            'running': True,
            'pid': os.getpid(),
            'started': datetime.now(timezone.utc).isoformat(),
            'ws_port': WS_PORT,
            'active_sessions': len(sessions),
            'updated': datetime.now(timezone.utc).isoformat(),
        }
        with open(DAEMON_FILE, 'w') as f:
            json.dump(data, f)
    except Exception as e:
        log.warning(f"Heartbeat write failed: {e}")

def is_allowed(host, port):
    """Check if target host/port is in allowlist."""
    host = host.strip().lower()
    if port not in ALLOWED_PORTS:
        return False
    # Allow localhost always
    if host in ('127.0.0.1', 'localhost', '::1'):
        return True
    # Check allowlist
    for allowed in ALLOWED_HOSTS:
        if host == allowed.lower():
            return True
    # Allow LAN ranges
    try:
        parts = list(map(int, host.split('.')))
        if len(parts) == 4:
            if parts[0] == 10: return True
            if parts[0] == 192 and parts[1] == 168: return True
            if parts[0] == 172 and 16 <= parts[1] <= 31: return True
    except: pass
    return False

class TelnetSession:
    """Manages a single telnet TCP connection."""

    def __init__(self, session_id, ws, host, port):
        self.session_id = session_id
        self.ws         = ws
        self.host       = host
        self.port       = port
        self.reader     = None
        self.writer     = None
        self.connected  = False
        self.started    = time.time()
        self.last_activity = time.time()
        self.bytes_rx   = 0
        self.bytes_tx   = 0

    async def connect(self):
        """Open TCP connection to telnet target."""
        try:
            self.reader, self.writer = await asyncio.wait_for(
                asyncio.open_connection(self.host, self.port),
                timeout=10.0
            )
            self.connected = True
            log.info(f"Session {self.session_id}: connected to {self.host}:{self.port}")
            return True
        except asyncio.TimeoutError:
            log.warning(f"Session {self.session_id}: connection timeout to {self.host}:{self.port}")
            return False
        except Exception as e:
            log.warning(f"Session {self.session_id}: connect failed: {e}")
            return False

    async def tcp_to_ws(self):
        """Read from TCP telnet, forward to WebSocket."""
        try:
            while self.connected:
                try:
                    data = await asyncio.wait_for(
                        self.reader.read(4096), timeout=1.0
                    )
                    if not data:
                        break
                    self.bytes_rx += len(data)
                    self.last_activity = time.time()
                    # Strip telnet IAC negotiation sequences for clean display
                    clean = self.strip_telnet_iac(data)
                    if clean:
                        msg = json.dumps({
                            'type': 'data',
                            'data': clean.decode('utf-8', errors='replace')
                        })
                        await self.ws.send(msg)
                except asyncio.TimeoutError:
                    # Check idle timeout
                    if time.time() - self.last_activity > SESSION_TIMEOUT:
                        log.info(f"Session {self.session_id}: idle timeout")
                        break
                    continue
        except Exception as e:
            log.info(f"Session {self.session_id}: tcp_to_ws ended: {e}")
        finally:
            await self.disconnect()

    def strip_telnet_iac(self, data):
        """Strip telnet IAC option negotiation bytes."""
        result = bytearray()
        i = 0
        while i < len(data):
            if data[i] == 255:  # IAC
                if i + 1 < len(data):
                    cmd = data[i+1]
                    if cmd in (251, 252, 253, 254):  # WILL/WONT/DO/DONT
                        i += 3  # skip IAC + cmd + option
                        continue
                    elif cmd == 255:  # escaped IAC
                        result.append(255)
                        i += 2
                        continue
                i += 2
            else:
                result.append(data[i])
                i += 1
        return bytes(result)

    async def bpq_login(self, username, password):
        """Handle BPQ telnet login.
        BPQ sends IAC WILL SGA (ff fb 03) + IAC WILL ECHO (ff fb 01) before user: prompt.
        Must respond with DO SGA + DO ECHO before BPQ will accept username.
        """
        try:
            async def read_until(pattern, timeout=8.0):
                buf = b''
                deadline = asyncio.get_event_loop().time() + timeout
                while asyncio.get_event_loop().time() < deadline:
                    try:
                        chunk = await asyncio.wait_for(
                            self.reader.read(512), timeout=1.0)
                        if not chunk:
                            break
                        buf += chunk
                        clean = self.strip_telnet_iac(buf)
                        log.info(f"Session {self.session_id}: "
                                 f"read_until({pattern}) buf={repr(clean[-30:])}")
                        if pattern in clean.lower():
                            break
                    except asyncio.TimeoutError:
                        continue
                return buf

            # Step 1: read initial prompt (contains IAC negotiations + user:)
            user_buf = await read_until(b'user:')
            log.info(f"Session {self.session_id}: user_buf raw={repr(user_buf[:80])}")

            # Respond to each IAC WILL with DO — required before BPQ accepts input
            iac_resp = b''
            i = 0
            while i < len(user_buf):
                if user_buf[i] == 0xff and i + 2 < len(user_buf):
                    cmd = user_buf[i+1]
                    opt = user_buf[i+2]
                    if cmd == 0xfb:   # WILL -> send DO
                        iac_resp += bytes([0xff, 0xfd, opt])
                    elif cmd == 0xfd: # DO -> send WILL
                        iac_resp += bytes([0xff, 0xfb, opt])
                    i += 3
                else:
                    i += 1
            if iac_resp:
                self.writer.write(iac_resp)
                await self.writer.drain()
                log.info(f"Session {self.session_id}: sent IAC response {repr(iac_resp)}")
                # Drain ALL follow-up IAC bytes BPQ sends — arrives in multiple packets
                while True:
                    try:
                        extra = await asyncio.wait_for(self.reader.read(64), timeout=0.3)
                        if not extra:
                            break
                        log.info(f"Session {self.session_id}: post-IAC drain={repr(extra)}")
                    except asyncio.TimeoutError:
                        break  # nothing more coming — safe to proceed

            # Step 2: send username with CRLF
            self.writer.write((username + '\r\n').encode())
            await self.writer.drain()
            log.info(f"Session {self.session_id}: sent username")

            # Step 3: wait for password: prompt
            pass_buf = await read_until(b'password:')
            log.info(f"Session {self.session_id}: pass_buf raw={repr(pass_buf[:80])}")

            # Step 4: send password with CRLF
            self.writer.write((password + '\r\n').encode())
            await self.writer.drain()
            log.info(f"Session {self.session_id}: sent password")

            # Step 5: read welcome banner up to node prompt
            banner = await read_until(b'>', timeout=5.0)
            if banner:
                clean = self.strip_telnet_iac(banner)
                if clean.strip():
                    msg = json.dumps({'type': 'data', 'data': clean.decode('utf-8', errors='replace')})
                    await self.ws.send(msg)

            log.info(f"Session {self.session_id}: BPQ login complete")
            return True

        except Exception as e:
            log.warning(f"Session {self.session_id}: login error: {e}")
            return False

    async def send_to_tcp(self, text):
        """Send text from browser to TCP telnet connection."""
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
        """Close TCP connection."""
        if not self.connected:
            return
        self.connected = False
        try:
            if self.writer:
                self.writer.close()
                await self.writer.wait_closed()
        except: pass
        log.info(f"Session {self.session_id}: disconnected "
                 f"(rx={self.bytes_rx} tx={self.bytes_tx})")
        # Notify browser
        try:
            await self.ws.send(json.dumps({'type': 'disconnected'}))
        except: pass
        sessions.pop(self.session_id, None)
        write_heartbeat()


async def handle_websocket(websocket, path=None):
    """Handle incoming WebSocket connection from browser."""
    session_id = f"t{int(time.time()*1000) % 100000}"
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
                host = msg.get('host', '127.0.0.1')
                port = int(msg.get('port', 8010))
                username = msg.get('username', '')
                password = msg.get('password', '')

                if not is_allowed(host, port):
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': f'Host {host}:{port} not in allowlist'
                    }))
                    continue

                if session and session.connected:
                    await session.disconnect()

                session = TelnetSession(session_id, websocket, host, port)
                sessions[session_id] = session

                if await session.connect():
                    await websocket.send(json.dumps({
                        'type': 'connected',
                        'host': host,
                        'port': port,
                        'session_id': session_id
                    }))
                    write_heartbeat()
                    # Start reading from TCP in background — BPQ handles login itself
                    asyncio.ensure_future(session.tcp_to_ws())
                else:
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': f'Could not connect to {host}:{port}'
                    }))
                    sessions.pop(session_id, None)

            elif action == 'send':
                if session and session.connected:
                    await session.send_to_tcp(msg.get('data', ''))
                else:
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': 'Not connected'
                    }))

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
    """Write heartbeat every 30 seconds."""
    while True:
        write_heartbeat()
        await asyncio.sleep(30)


async def main():
    try:
        import websockets
        import websockets.server
    except ImportError:
        log.error("websockets library not installed. Run: pip3 install websockets --break-system-packages")
        sys.exit(1)

    log.info(f"BPQ Telnet Daemon starting on {WS_HOST}:{WS_PORT}")
    write_heartbeat()

    # Support both websockets v10 and v11+ API
    ws_ver = tuple(int(x) for x in websockets.__version__.split('.')[:2])
    log.info(f"websockets version: {websockets.__version__}")

    if ws_ver >= (11, 0):
        # New API — serve() returns an async context manager
        async with websockets.serve(handle_websocket, WS_HOST, WS_PORT,
                                    ping_interval=30, ping_timeout=10):
            log.info(f"Telnet daemon ready — ws://{WS_HOST}:{WS_PORT}")
            await asyncio.gather(
                asyncio.Future(),
                heartbeat_loop(),
            )
    else:
        # Old API
        async with websockets.serve(handle_websocket, WS_HOST, WS_PORT):
            log.info(f"Telnet daemon ready — ws://{WS_HOST}:{WS_PORT}")
            await asyncio.gather(
                asyncio.Future(),
                heartbeat_loop(),
            )

if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        log.info("Telnet daemon stopped")
