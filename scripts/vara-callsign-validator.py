#!/usr/bin/env python3
"""
vara-callsign-validator.py — VARA HF Callsign Validation Proxy
Part of BPQ Dashboard Suite

Sits between BPQ32 and VARA HF as a transparent TCP proxy.
When VARA reports an incoming connection, the callsign is validated against
ITU Radio Regulations Appendix 42 format rules. Invalid callsigns are
immediately disconnected before BPQ32 ever sees the session.

Architecture:

    VARA HF on BPQHOST via Wine  (127.0.0.1 :8300/:8301)
            ^  outbound TCP from validator (loopback)
    [this proxy on Linux, listening on 127.0.0.1:9025/:9026 (config.php)]
            ^  BPQ32 connects to validator
    BPQ32 on Linux

BPQ32's VARA port config points to 127.0.0.1 on ports 9025/9026
(the validator's listener), and the validator forwards to
127.0.0.1:8300/8301 where Wine-VARA-HF actually listens.

History: pre-2026-05-01 VARA HF ran on a separate Windows machine
at 192.168.1.100 on ports 9025/9026. After migrating VARA HF to run
locally on BPQHOST under Wine, the validator's upstream config
was changed from 192.168.1.100:9025/9026 to 127.0.0.1:8300/8301.

Dependencies:
    Python 3.7+  (no third-party packages required)
"""

import socket
import threading
import logging
import re
import sys

# =============================================================================
# CONFIGURATION  (v1.5.19: from config.php 'vara_validator'; was hard-coded
# for one station, ran as User=pi from /usr/local/bin, logged where the web
# user couldn't write)
# =============================================================================
#   'vara_validator' => [
#       'vara_host'       => '127.0.0.1',  // where VARA HF listens (its command port;
#       'vara_cmd_port'   => 8300,         //   data = vara_data_port, default +1)
#       'listen_host'     => '127.0.0.1',  // where BPQ connects: its VARA port ADDR
#       'listen_cmd_port' => 9025,         //   (data = listen_data_port, default +1)
#       'allow' => [], 'deny' => ['NOCALL', 'TEST', 'N0CALL', 'INVALID'],
#   ],
# Use listen_host '0.0.0.0' only if BPQ runs on another machine.

import os as _os
sys.path.insert(0, _os.path.dirname(_os.path.abspath(__file__)))
try:
    import bpq_db_config as _cfgmod   # noqa: E402
    _VC = _cfgmod.load_config_section('vara_validator') or {}
    _LOGDIR = _cfgmod.app_log_dir()
except Exception:                      # running outside a dashboard folder
    _VC, _LOGDIR = {}, '/var/log/bpq-dashboard'


def _int(key, default):
    try:
        return int(_VC.get(key, default))
    except (TypeError, ValueError):
        return default


VARA_HOST      = str(_VC.get('vara_host') or '127.0.0.1')
VARA_CMD_PORT  = _int('vara_cmd_port', 8300)
VARA_DATA_PORT = _int('vara_data_port', VARA_CMD_PORT + 1)

LISTEN_HOST     = str(_VC.get('listen_host') or '127.0.0.1')
PROXY_CMD_PORT  = _int('listen_cmd_port', 9025)
PROXY_DATA_PORT = _int('listen_data_port', PROXY_CMD_PORT + 1)

LOG_FILE = _os.path.join(_LOGDIR, 'vara-validator.log')

ALLOWLIST: set = {str(c).strip().upper() for c in (_VC.get('allow') or []) if str(c).strip()}
DENYLIST:  set = {str(c).strip().upper() for c in (_VC.get('deny') or ['NOCALL', 'TEST', 'N0CALL', 'INVALID']) if str(c).strip()}

# =============================================================================
# ITU CALLSIGN VALIDATION
# =============================================================================

CALLSIGN_RE = re.compile(r'^[A-Z]{1,2}[0-9][A-Z]{1,3}$')
SSID_RE     = re.compile(r'-\d{1,2}$')
PORTABLE_RE = re.compile(r'/.+$')


def validate_callsign(raw: str):
    if not raw or not raw.strip():
        return False, "empty callsign"
    call = raw.strip().upper()
    call = PORTABLE_RE.sub('', call)
    call = SSID_RE.sub('', call)
    if not call:
        return False, f"nothing left after stripping from '{raw}'"
    if call in DENYLIST:
        return False, f"'{call}' is on the denylist"
    if call in ALLOWLIST:
        return True, call
    if len(call) < 3:
        return False, f"'{call}' too short"
    if len(call) > 7:
        return False, f"'{call}' too long"
    if not call.isalnum():
        return False, f"'{call}' contains non-alphanumeric characters"
    if not CALLSIGN_RE.match(call):
        return False, f"'{call}' does not match ITU format (prefix + digit + suffix)"
    return True, call


# =============================================================================
# LOGGING
# =============================================================================

def setup_logging():
    logger = logging.getLogger("vara-validator")
    logger.setLevel(logging.DEBUG)
    fmt = logging.Formatter(
        "%(asctime)s UTC  %(levelname)-8s  %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S"
    )
    try:
        fh = logging.FileHandler(LOG_FILE)
        fh.setFormatter(fmt)
        logger.addHandler(fh)
    except OSError:
        pass   # journal only
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)
    logger.addHandler(sh)
    return logger

log = setup_logging()


# =============================================================================
# PIPE HELPER
# =============================================================================

def pipe(src, dst, label):
    """Forward bytes from src socket to dst socket until either closes."""
    try:
        while True:
            data = src.recv(4096)
            if not data:
                break
            dst.sendall(data)
    except Exception as e:
        log.debug(f"{label} pipe ended: {e}")
    finally:
        for s in (src, dst):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except Exception:
                pass
            try:
                s.close()
            except Exception:
                pass


# =============================================================================
# COMMAND CHANNEL HANDLER  (port 9025)
# =============================================================================

def handle_cmd(bpq_sock, addr):
    log.info(f"CMD  BPQ connected from {addr}")

    try:
        vara_sock = socket.create_connection((VARA_HOST, VARA_CMD_PORT), timeout=5)
    except Exception as e:
        log.error(f"CMD  Cannot connect to VARA at {VARA_HOST}:{VARA_CMD_PORT} — {e}")
        bpq_sock.close()
        return

    log.info(f"CMD  Proxy established BPQ <-> VARA {VARA_HOST}:{VARA_CMD_PORT}")
    vara_sock.settimeout(None)
    bpq_sock.settimeout(None)

    # BPQ->VARA forwarding in background thread
    t_bpq_to_vara = threading.Thread(
        target=pipe,
        args=(bpq_sock, vara_sock, "CMD BPQ->VARA"),
        daemon=True
    )
    t_bpq_to_vara.start()

    # VARA->BPQ: inspect line by line
    buf = b""
    try:
        while True:
            chunk = vara_sock.recv(4096)
            if not chunk:
                break
            buf += chunk

            while True:
                # Find next line ending
                cr = buf.find(b"\r")
                lf = buf.find(b"\n")
                if cr == -1 and lf == -1:
                    break  # no complete line yet

                # Pick whichever comes first
                if cr == -1:
                    pos, sep = lf, b"\n"
                elif lf == -1:
                    pos, sep = cr, b"\r"
                elif lf == cr + 1:
                    pos, sep = cr, b"\r\n"
                elif cr < lf:
                    pos, sep = cr, b"\r"
                else:
                    pos, sep = lf, b"\n"

                line_bytes = buf[:pos]
                buf = buf[pos + len(sep):]
                line = line_bytes.decode(errors='replace').strip()
                full_line = line_bytes + sep

                if line.upper().startswith("CONNECTED "):
                    parts = line.split()
                    if len(parts) >= 2:
                        raw_call = parts[1]
                        ok, result = validate_callsign(raw_call)
                        if ok:
                            log.info(f"CMD  ACCEPTED  {raw_call!r:15}  (validated as {result})  raw='{line}'")
                            bpq_sock.sendall(full_line)
                        else:
                            log.warning(f"CMD  REJECTED  {raw_call!r:15}  reason: {result}  raw='{line}'")
                            try:
                                vara_sock.sendall(b"DISCONNECT\r\n")
                            except Exception:
                                pass
                            try:
                                bpq_sock.sendall(b"DISCONNECTED\r\n")
                            except Exception:
                                pass
                    else:
                        log.warning(f"CMD  Malformed CONNECTED line: '{line}' — passing through")
                        bpq_sock.sendall(full_line)
                else:
                    # Log all VARA command traffic at DEBUG level
                    if line:
                        log.debug(f"CMD  VARA->BPQ: '{line}'")
                    bpq_sock.sendall(full_line)

    except Exception as e:
        log.debug(f"CMD  VARA->BPQ ended: {e}")
    finally:
        for s in (vara_sock, bpq_sock):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except Exception:
                pass
            try:
                s.close()
            except Exception:
                pass
        log.info(f"CMD  Session closed for {addr}")


# =============================================================================
# DATA CHANNEL HANDLER  (port 9026)
# =============================================================================

def handle_data(bpq_sock, addr):
    log.info(f"DATA BPQ connected from {addr}")

    try:
        vara_sock = socket.create_connection((VARA_HOST, VARA_DATA_PORT), timeout=5)
    except Exception as e:
        log.error(f"DATA Cannot connect to VARA at {VARA_HOST}:{VARA_DATA_PORT} — {e}")
        bpq_sock.close()
        return

    log.info(f"DATA Proxy established BPQ <-> VARA {VARA_HOST}:{VARA_DATA_PORT}")
    vara_sock.settimeout(None)
    bpq_sock.settimeout(None)

    t1 = threading.Thread(target=pipe, args=(bpq_sock, vara_sock, "DATA BPQ->VARA"), daemon=True)
    t2 = threading.Thread(target=pipe, args=(vara_sock, bpq_sock, "DATA VARA->BPQ"), daemon=True)
    t1.start()
    t2.start()
    t1.join()
    t2.join()
    log.info(f"DATA Session closed for {addr}")


# =============================================================================
# LISTENER
# =============================================================================

def start_listener(port, handler, label):
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind((LISTEN_HOST, port))
    srv.listen(5)
    log.info(f"{label} listening on {LISTEN_HOST}:{port}")
    while True:
        try:
            client_sock, addr = srv.accept()
            t = threading.Thread(target=handler, args=(client_sock, addr), daemon=True)
            t.start()
        except Exception as e:
            log.error(f"{label} accept error: {e}")


# =============================================================================
# MAIN
# =============================================================================

if __name__ == "__main__":
    log.info("=" * 60)
    log.info("VARA Callsign Validation Proxy starting")
    log.info(f"  BPQ32 connects to:    {LISTEN_HOST}:{PROXY_CMD_PORT} / :{PROXY_DATA_PORT}")
    log.info(f"  Forwarding to VARA:   {VARA_HOST}:{VARA_CMD_PORT} / :{VARA_DATA_PORT}")
    log.info(f"  Allowlist entries: {len(ALLOWLIST)}")
    log.info(f"  Denylist entries:  {len(DENYLIST)}")
    log.info("=" * 60)

    # Data channel listener in background thread
    t_data = threading.Thread(
        target=start_listener,
        args=(PROXY_DATA_PORT, handle_data, "DATA"),
        daemon=True
    )
    t_data.start()

    # Command channel in main thread
    start_listener(PROXY_CMD_PORT, handle_cmd, "CMD ")
