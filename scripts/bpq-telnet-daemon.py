#!/usr/bin/env python3
"""
bpq-telnet-daemon.py — WebSocket to TCP Telnet proxy daemon
BPQ Dashboard v1.5.6

Bridges browser WebSocket connections to TCP telnet targets.
Supports multiple simultaneous sessions.
Reads config from /var/www/html/bpq/config.php via JSON sidecar.

Run as: python3 /var/www/html/bpq/scripts/bpq-telnet-daemon.py
         python3 /var/www/html/bpq/scripts/bpq-telnet-daemon.py  (generic)
Service: bpq-telnet.service
"""

import asyncio
import base64
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

# YAPP file transfer protocol (in same scripts/ directory)
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from yapp_protocol import YappTransfer, YappState

# ── Configuration ──────────────────────────────────────────────────
# Auto-detect web root — works across installs
import os as _os
_candidates = ['/var/www/html/bpq', '/var/www/html/bpq', '/var/www/bpq']
WEB_ROOT = next((p for p in _candidates if _os.path.isdir(p)), '/var/www/html/bpq')

STATE_DIR  = WEB_ROOT + '/cache/telnet-sessions'
DAEMON_FILE= STATE_DIR + '/telnet-daemon.json'
LOG_FILE   = WEB_ROOT + '/logs/bpq-telnet-daemon.log'
YAPP_DIR   = WEB_ROOT + '/yapp-files'   # received YAPP files land here
WS_HOST    = '127.0.0.1'
WS_PORT    = 8765          # WebSocket port — nginx proxies /ws/telnet here
MAX_SESSIONS = 10          # Max simultaneous telnet sessions
SESSION_TIMEOUT = 3600     # 1 hour idle timeout
YAPP_MAX_SIZE = 10 * 1024 * 1024   # 10 MB cap on file transfers

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
Path(YAPP_DIR).mkdir(parents=True, exist_ok=True)

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

    def __init__(self, session_id, ws, host, port, fbb_mode=False,
                 username='', password=''):
        self.session_id = session_id
        self.ws         = ws
        self.host       = host
        self.port       = port
        self.fbb_mode   = fbb_mode      # True for transparent FBB-mode connection
        self.username   = username
        self.password   = password
        self.reader     = None
        self.writer     = None
        self.connected  = False
        self.started    = time.time()
        self.last_activity = time.time()
        self.bytes_rx   = 0
        self.bytes_tx   = 0
        # ── YAPP state ──
        # When yapp is not None, all TCP traffic gets routed through it
        # instead of being decoded as text. The transfer's take_out()
        # bytes get sent to TCP; incoming TCP bytes get fed in.
        self.yapp        = None    # YappTransfer or None
        self.yapp_start_at = 0     # for timeout detection
        # ── Files command capture ──
        # Temporary buffer used by yapp_list_files() to capture the
        # BBS `Files` command response. When _files_capture_active is
        # True, tcp_to_ws() routes incoming bytes here instead of
        # forwarding to the browser.
        self._files_capture = None
        self._files_capture_active = False

    async def connect(self):
        """Open TCP connection to telnet target.

        For FBB mode, also auto-send username\\r and password\\r right
        after connect. FBB mode is a transparent TCP path (no telnet
        IAC negotiation, no echoing) — used by BPQTermTCP and required
        for YAPP file transfers.
        """
        try:
            self.reader, self.writer = await asyncio.wait_for(
                asyncio.open_connection(self.host, self.port),
                timeout=10.0
            )
            self.connected = True
            log.info(f"Session {self.session_id}: connected to "
                     f"{self.host}:{self.port} (fbb={self.fbb_mode})")

            # FBB mode authentication: send user\r then pass\r as soon
            # as the TCP connection opens. Per the BPQ TelnetServer spec
            # for plain TCP / FBB-mode connections, no prompt is sent —
            # the client just unconditionally sends both lines.
            if self.fbb_mode:
                if not self.username or not self.password:
                    log.warning(f"Session {self.session_id}: FBB mode "
                                f"requires username AND password")
                    return False
                # Small delay so BPQ is ready
                await asyncio.sleep(0.3)
                creds = f"{self.username}\r{self.password}\r"
                self.writer.write(creds.encode('ascii', errors='replace'))
                await self.writer.drain()
                log.info(f"Session {self.session_id}: FBB credentials sent "
                         f"for user '{self.username}'")
            return True
        except asyncio.TimeoutError:
            log.warning(f"Session {self.session_id}: connection timeout to "
                        f"{self.host}:{self.port}")
            return False
        except Exception as e:
            log.warning(f"Session {self.session_id}: connect failed: {e}")
            return False

    async def tcp_to_ws(self):
        """Read from TCP telnet, forward to WebSocket.

        When a YAPP transfer is active (self.yapp is not None), TCP
        bytes are fed directly into the YAPP state machine instead of
        being decoded as text. Bytes the YAPP machine wants to send go
        out via the TCP writer. Progress and completion are notified to
        the browser via dedicated WebSocket message types.
        """
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

                    if self.yapp is not None:
                        # YAPP mode — route bytes through protocol
                        await self._yapp_consume(data)
                    elif getattr(self, '_files_capture_active', False):
                        # Capturing `Files` command response — accumulate
                        # in buffer; yapp_list_files() will parse when
                        # complete. Don't forward to browser (would spam
                        # the terminal pane with the listing).
                        self._files_capture.extend(data)
                    else:
                        # Normal text mode. FBB mode is transparent TCP,
                        # no telnet IAC negotiation to strip.
                        if self.fbb_mode:
                            clean = data
                        else:
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
                    # Also check YAPP transfer timeout (30s of no progress)
                    if self.yapp is not None:
                        elapsed = time.time() - self.yapp_start_at
                        if elapsed > 120 and \
                           self.yapp.state not in (YappState.DONE, YappState.ABORT):
                            log.warning(f"Session {self.session_id}: YAPP timeout")
                            await self._yapp_finish(success=False,
                                                     error='Transfer timed out')
                    continue
        except Exception as e:
            log.info(f"Session {self.session_id}: tcp_to_ws ended: {e}")
        finally:
            await self.disconnect()

    async def _yapp_consume(self, data):
        """Feed incoming TCP bytes to the active YAPP transfer."""
        # DEBUG: log raw bytes flowing through YAPP — first 64 bytes
        # of each chunk, hex-encoded, plus state transitions. Disable
        # via log level INFO; this only fires when DEBUG is enabled.
        # Keeping it at INFO temporarily so we can diagnose first-run
        # protocol issues.
        before_state = self.yapp.state.value if self.yapp else 'none'
        hex_in = data[:64].hex()
        log.info(f"YAPP[{self.session_id}] RX {len(data)}B "
                 f"state={before_state} hex={hex_in}"
                 + ('...' if len(data) > 64 else ''))

        out = self.yapp.feed(data)
        if out:
            hex_out = out[:64].hex()
            log.info(f"YAPP[{self.session_id}] TX {len(out)}B "
                     f"state={self.yapp.state.value} hex={hex_out}"
                     + ('...' if len(out) > 64 else ''))
            self.writer.write(out)
            await self.writer.drain()
            self.bytes_tx += len(out)
        else:
            log.info(f"YAPP[{self.session_id}] no response, "
                     f"state={self.yapp.state.value}")
        # Progress update for the browser
        done, total, pct = self.yapp.progress()
        await self.ws.send(json.dumps({
            'type': 'yapp_progress',
            'bytes': done,
            'total': total,
            'percent': pct,
        }))
        # Completion check
        if self.yapp.state == YappState.DONE:
            await self._yapp_finish(success=True)
        elif self.yapp.state == YappState.ABORT:
            await self._yapp_finish(success=False,
                                     error=self.yapp.error_message or 'Aborted')

    async def _yapp_finish(self, success, error=None):
        """Send completion notification to browser and clear YAPP state."""
        if self.yapp is None:
            return
        # Note: receive side already wrote the file in _finalize_receive()
        payload = {
            'type': 'yapp_complete' if success else 'yapp_error',
        }
        if success:
            payload['filename'] = self.yapp.filename or '(unknown)'
            payload['bytes']    = self.yapp.bytes_done
            payload['yappc']    = self.yapp.yappc
            payload['direction'] = self.yapp.direction
            if self.yapp.direction == 'recv' and self.yapp.out_path:
                # Expose a relative URL that nginx serves from web root
                relpath = os.path.relpath(self.yapp.out_path, WEB_ROOT)
                payload['url'] = '/' + relpath
        else:
            payload['message'] = error or 'Transfer failed'
        try:
            await self.ws.send(json.dumps(payload))
        except Exception:
            pass
        log.info(f"Session {self.session_id}: YAPP finished "
                 f"(success={success}, dir={self.yapp.direction}, "
                 f"bytes={self.yapp.bytes_done}, "
                 f"state={self.yapp.state.value}, "
                 f"in_buf={len(self.yapp._in_buf)}B, "
                 f"reason={error or 'normal'})")
        self.yapp = None

    async def yapp_start_send(self, filename, data_b64):
        """Begin sending a file via YAPP. data_b64 is base64-encoded payload."""
        if self.yapp is not None:
            await self.ws.send(json.dumps({
                'type': 'yapp_error',
                'message': 'A YAPP transfer is already in progress',
            }))
            return
        try:
            payload = base64.b64decode(data_b64)
        except Exception as e:
            await self.ws.send(json.dumps({
                'type': 'yapp_error',
                'message': f'Invalid file data: {e}',
            }))
            return
        if len(payload) > YAPP_MAX_SIZE:
            await self.ws.send(json.dumps({
                'type': 'yapp_error',
                'message': f'File too large ({len(payload)} > {YAPP_MAX_SIZE})',
            }))
            return
        # Sanitize filename for transmission
        safe_name = re.sub(r'[^A-Za-z0-9._-]', '_', filename or 'upload.bin')[:64]
        self.yapp = YappTransfer(direction='send', filename=safe_name,
                                  payload=payload, allow_yappc=True,
                                  max_size=YAPP_MAX_SIZE)
        self.yapp.start()
        self.yapp_start_at = time.time()
        out = self.yapp.take_out()
        if out:
            self.writer.write(out)
            await self.writer.drain()
            self.bytes_tx += len(out)
        await self.ws.send(json.dumps({
            'type': 'yapp_started',
            'direction': 'send',
            'filename': safe_name,
            'bytes_total': len(payload),
        }))
        log.info(f"Session {self.session_id}: YAPP send started for "
                 f"'{safe_name}' ({len(payload)} bytes)")

    async def yapp_start_recv(self):
        """Begin watching for an incoming YAPP transfer.

        The peer should already have been told to send the file (via a
        BBS command typed by the user). This puts us into a state where
        the next SI packet from the peer triggers the receive state
        machine.
        """
        if self.yapp is not None:
            await self.ws.send(json.dumps({
                'type': 'yapp_error',
                'message': 'A YAPP transfer is already in progress',
            }))
            return
        # Generate timestamped filename — actual filename comes from HD,
        # but we need a temp path to write into. We'll rename after HD
        # gives us the real filename.
        timestamp = datetime.now().strftime('%Y%m%d-%H%M%S')
        tmp_path = f"{YAPP_DIR}/{timestamp}-pending.bin"
        self.yapp = YappTransfer(direction='recv', out_path=tmp_path,
                                  allow_yappc=True, max_size=YAPP_MAX_SIZE)
        self.yapp.start()
        self.yapp_start_at = time.time()
        await self.ws.send(json.dumps({
            'type': 'yapp_started',
            'direction': 'recv',
        }))
        log.info(f"Session {self.session_id}: YAPP receive armed "
                 f"(temp path: {tmp_path})")

    async def yapp_abort(self):
        if self.yapp is None:
            return
        self.yapp.abort('User cancelled')
        out = self.yapp.take_out()
        if out and self.writer:
            try:
                self.writer.write(out)
                await self.writer.drain()
            except Exception:
                pass
        await self._yapp_finish(success=False, error='User cancelled')

    async def yapp_list_files(self):
        """Issue `Files\\r` to BBS and capture/parse the response.

        BPQ BBS `Files` command returns a list of public files in the
        format:
            <filename> <size>\\r
            <filename> <size>\\r
            ...
            de <CALL>>\\r          ← prompt terminator
        Filenames may contain spaces; size is always the last
        whitespace-separated token on each line.

        Returns a list of dicts: [{'name': str, 'size': int}, ...]
        or sends an error message to the WebSocket on failure.

        Caller must be in BBS state (connected via FBB and typed BBS).
        We can't verify the user is in BBS — the BPQ Node prompt would
        accept Files as unknown and return "Invalid command" which we
        detect and report.
        """
        if not self.connected or not self.writer:
            await self._ws_send({
                'type': 'yapp_files_list',
                'ok': False,
                'error': 'Not connected'
            })
            return
        if self.yapp is not None:
            await self._ws_send({
                'type': 'yapp_files_list',
                'ok': False,
                'error': 'YAPP transfer in progress'
            })
            return

        # Suspend normal RX → text decoding while we capture the listing.
        # We do this by setting a temporary buffer on the session that
        # tcp_to_ws() will fill instead of forwarding bytes to the browser.
        log.info(f"Session {self.session_id}: Files: requesting listing")
        self._files_capture = bytearray()
        self._files_capture_active = True
        try:
            # Send the command
            self.writer.write(b'Files\r')
            await self.writer.drain()

            # Wait for terminator. The listing ends with the BBS prompt
            # which looks like "de <CALL>>" or just "...>". Some BPQ
            # versions may paginate with "--More--" — handle that by
            # sending space to continue.
            end_time = asyncio.get_event_loop().time() + 10.0  # 10s max
            last_size = 0
            stable_since = None
            while asyncio.get_event_loop().time() < end_time:
                await asyncio.sleep(0.15)

                buf = bytes(self._files_capture)

                # Pagination prompt? Send space to continue.
                if b'--More--' in buf or b'-- More --' in buf:
                    log.info(f"Session {self.session_id}: Files: --More-- detected, "
                             f"sending space")
                    try:
                        self.writer.write(b' ')
                        await self.writer.drain()
                    except Exception:
                        pass
                    # Reset stability tracking; let more data flow
                    last_size = len(buf)
                    stable_since = None
                    continue

                # Look for BBS prompt termination — bytes ending in '>\r' or
                # containing 'de <call>>' (callsign varies). The simplest
                # robust check: line ending in '>' as last printable char.
                # Allow a trailing newline/CR after.
                trimmed = buf.rstrip(b'\r\n ')
                if trimmed.endswith(b'>'):
                    log.info(f"Session {self.session_id}: Files: prompt detected, "
                             f"{len(buf)} bytes captured")
                    break

                # Stability check — no new bytes for 1.5 seconds → done anyway
                if len(buf) == last_size:
                    if stable_since is None:
                        stable_since = asyncio.get_event_loop().time()
                    elif asyncio.get_event_loop().time() - stable_since > 1.5:
                        log.info(f"Session {self.session_id}: Files: stable buffer, "
                                 f"{len(buf)} bytes captured")
                        break
                else:
                    last_size = len(buf)
                    stable_since = None
        except Exception as e:
            log.warning(f"Session {self.session_id}: Files: error {e}")
        finally:
            self._files_capture_active = False

        # Parse the captured bytes
        raw = bytes(self._files_capture)
        self._files_capture = None
        files = self._parse_files_listing(raw)

        await self._ws_send({
            'type': 'yapp_files_list',
            'ok': True,
            'files': files,
            'raw_bytes': len(raw),
        })

    def _parse_files_listing(self, raw_bytes):
        """Parse BPQ BBS `Files` output into [{name, size}, ...].

        Format observed: each line is '<filename> <size>' terminated by
        bare \\r. Filename may contain spaces. Size is the LAST
        whitespace token on the line.

        Lines we skip:
          - The trailing BBS prompt (ends in '>')
          - Lines with no size token (don't look like file entries)
          - Lines starting with --More-- (pagination markers)
          - Empty lines

        Returns: [{'name': str, 'size': int}, ...]
        """
        try:
            text = raw_bytes.decode('utf-8', errors='replace')
        except Exception:
            return []
        # Split on either \r\n or \r — both are line breaks in this protocol
        lines = re.split(r'\r\n|\r|\n', text)
        out = []
        for line in lines:
            line = line.strip()
            if not line:
                continue
            # Skip the BBS prompt
            if line.endswith('>'):
                continue
            # Skip pagination
            if '--More--' in line or '-- More --' in line:
                continue
            # Skip lines that don't have a size (last token not all digits)
            parts = line.rsplit(' ', 1)
            if len(parts) != 2:
                continue
            name, size_str = parts
            if not size_str.isdigit():
                continue
            name = name.strip()
            if not name:
                continue
            try:
                size = int(size_str)
            except ValueError:
                continue
            out.append({'name': name, 'size': size})
        return out

    async def _ws_send(self, message):
        """Helper to send a JSON message over the WebSocket."""
        if self.ws:
            try:
                await self.ws.send(json.dumps(message))
            except Exception as e:
                log.warning(f"Session {self.session_id}: ws_send failed: {e}")

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
        """Send text from browser to TCP telnet connection.

        FBB mode uses bare \\r (transparent TCP, no telnet line conversion).
        Regular telnet uses \\r\\n to mimic terminal behavior.
        """
        if not self.connected or not self.writer:
            return
        try:
            line_ending = '\r' if self.fbb_mode else '\r\n'
            self.writer.write((text + line_ending).encode('utf-8', errors='replace'))
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
                fbb_mode = bool(msg.get('fbb', False))

                if not is_allowed(host, port):
                    await websocket.send(json.dumps({
                        'type': 'error',
                        'message': f'Host {host}:{port} not in allowlist'
                    }))
                    continue

                if session and session.connected:
                    await session.disconnect()

                session = TelnetSession(session_id, websocket, host, port,
                                         fbb_mode=fbb_mode,
                                         username=username,
                                         password=password)
                sessions[session_id] = session

                if await session.connect():
                    await websocket.send(json.dumps({
                        'type': 'connected',
                        'host': host,
                        'port': port,
                        'session_id': session_id,
                        'fbb': fbb_mode,
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

            elif action == 'yapp_send':
                if session and session.connected:
                    await session.yapp_start_send(
                        msg.get('filename', 'upload.bin'),
                        msg.get('data', ''),   # base64-encoded
                    )
                else:
                    await websocket.send(json.dumps({
                        'type': 'yapp_error',
                        'message': 'Not connected'
                    }))

            elif action == 'yapp_recv':
                if session and session.connected:
                    await session.yapp_start_recv()
                else:
                    await websocket.send(json.dumps({
                        'type': 'yapp_error',
                        'message': 'Not connected'
                    }))

            elif action == 'yapp_abort':
                if session:
                    await session.yapp_abort()

            elif action == 'yapp_list_files':
                if session and session.connected:
                    await session.yapp_list_files()
                else:
                    await websocket.send(json.dumps({
                        'type': 'yapp_files_list',
                        'ok': False,
                        'error': 'Not connected'
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
