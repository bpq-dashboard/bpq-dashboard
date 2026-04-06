#!/usr/bin/env python3
"""
vara-callsign-validator.py — VARA HF Callsign Validation Proxy
Part of BPQ Dashboard Suite

Sits between BPQ32 (Linux) and VARA HF (Windows) as a transparent TCP proxy.
When VARA reports an incoming connection, the callsign is validated against
ITU Radio Regulations Appendix 42 format rules. Invalid callsigns are
immediately disconnected before BPQ32 ever sees the session.

Architecture:

    VARA HF on Windows  (10.0.0.213 :9025/:9026)
            ^  outbound TCP from Linux
    [this proxy on Linux, listening on 0.0.0.0:9025/:9026]
            ^  BPQ32 connects to localhost
    BPQ32 on Linux

No changes required on the Windows machine or in VARA HF settings.
BPQ32's VARA port config points to 127.0.0.1 on ports 9025/9026.

Dependencies:
    Python 3.7+  (no third-party packages required)
"""

import socket
import threading
import logging
import re
import sys

# =============================================================================
# CONFIGURATION
# =============================================================================

VARA_HOST      = "10.0.0.213"
VARA_CMD_PORT  = 9025
VARA_DATA_PORT = 9026

PROXY_CMD_PORT  = 9025
PROXY_DATA_PORT = 9026

LOG_FILE = "/var/log/vara-validator.log"

ALLOWLIST: set = set()
DENYLIST:  set = {"NOCALL", "TEST", "N0CALL", "INVALID"}

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
    except PermissionError:
        pass
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
    srv.bind(("0.0.0.0", port))
    srv.listen(5)
    log.info(f"{label} listening on 0.0.0.0:{port}")
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
    log.info(f"  BPQ32 connects to:    0.0.0.0:{PROXY_CMD_PORT} / :{PROXY_DATA_PORT}")
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
