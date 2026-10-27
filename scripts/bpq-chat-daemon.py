#!/usr/bin/env python3
"""
bpq-chat-daemon.py — Persistent BPQ telnet connection broker
Stays connected to BPQ chat/terminal and writes output to a shared
message file that bpq-chat.php reads. Accepts commands via a FIFO pipe.

Run as: python3 /var/www/bpq-dashboard/scripts/bpq-chat-daemon.py
Or via systemd service.
"""

import socket, time, threading, json, os, sys, signal, re, select, subprocess
from pathlib import Path

# ── Self-locate relative to this file, not a hardcoded deployment path ──
# The previous version hardcoded '/var/www/bpq-dashboard/...' — the
# generic template's path, not every server's actual web folder.
# That mismatch made the daemon crash on startup here (PermissionError
# trying to create '/var/www/bpq-dashboard', which doesn't exist and
# www-data can't create at that level). Deriving it from the script's
# own location instead means this keeps working correctly even if the
# dashboard is ever moved or reinstalled somewhere else.
SCRIPT_DIR      = os.path.dirname(os.path.abspath(__file__))
DASHBOARD_ROOT  = os.path.dirname(SCRIPT_DIR)  # scripts/ -> dashboard root
BBS_CONFIG_HELPER = os.path.join(SCRIPT_DIR, 'print-bbs-config.php')

# ── Config: BPQ telnet credentials, read from config.php ────────────
# Previously hardcoded here as a second, separate copy of the same
# credentials config.php already holds in its 'bbs' section — which is
# exactly how this drifted out of sync and broke. Reading them from the
# one shared config file means there's only ever one place to update.
# Fails loudly and refuses to start rather than silently falling back
# to placeholder credentials if anything goes wrong.
def load_bbs_config():
    try:
        result = subprocess.run(['php', BBS_CONFIG_HELPER],
                                 capture_output=True, text=True, timeout=10)
        if result.returncode != 0:
            raise RuntimeError(result.stderr.strip() or
                                f"php exited {result.returncode}")
        cfg = json.loads(result.stdout)
        if not cfg.get('user') or not cfg.get('pass'):
            raise RuntimeError(
                "config.php's 'bbs' section is missing user/pass")
        return cfg
    except Exception as e:
        sys.stderr.write(
            f"FATAL: could not load BPQ credentials via "
            f"{BBS_CONFIG_HELPER}: {e}\n"
            f"Check config.php's 'bbs' section (host/port/user/pass) "
            f"and that 'php' is on PATH for this service's user.\n")
        sys.exit(1)

_bbs_cfg = load_bbs_config()
BPQ_HOST   = _bbs_cfg['host']
BPQ_PORT   = int(_bbs_cfg['port'])
BPQ_USER   = _bbs_cfg['user']
BPQ_PASS   = _bbs_cfg['pass']

STATE_DIR  = os.path.join(DASHBOARD_ROOT, 'cache', 'chat-sessions')
MSG_FILE   = STATE_DIR + '/chat-messages.json'
CMD_FIFO   = STATE_DIR + '/chat-commands.fifo'
STATE_FILE = STATE_DIR + '/chat-daemon.json'

MAX_MESSAGES = 200   # keep last 200 messages in buffer
RECONNECT_BASE_DELAY = 5    # seconds before the first retry after a drop
RECONNECT_MAX_DELAY  = 300  # cap backoff at 5 minutes
MIN_STABLE_SECONDS   = 30   # a connection must last this long to reset backoff

# How long the socket can sit with nothing sent before we send a keepalive.
# BPQ's telnet/chat server appears to idle-time-out silent connections —
# every time that happens, this daemon's old flat 5s-forever reconnect loop
# did a full NODE->CHAT rejoin, which other stations on the linked BPQ Chat
# network see as a leave/join event. Sending a no-op periodically keeps the
# session alive so that never triggers in the first place. 240s is a
# conservative guess — tighten if BPQ's real idle timeout turns out to be
# shorter, there's no harm in keeping alive more often than necessary.
KEEPALIVE_INTERVAL = 240
# NOTE (2026-10-24): this keeps the TCP/telnet link alive only. The chat
# server's own 2-hour idle disconnect ignores it — see rejoin_chat() below.

Path(STATE_DIR).mkdir(parents=True, exist_ok=True)

# ── Shared state ──────────────────────────────────────────────────
messages = []
msg_lock = threading.Lock()
connected = False
mode = 'chat'
running = True
seq_counter = 0
last_send_time = 0.0  # updated on every real send AND every keepalive

# ── Noise filter ──────────────────────────────────────────────────
NOISE = [
    'YOURCALL BPQ32 Telnet Server',
    'Enter ? for list of commands',
    'YOURCALL-4} Connected to BBS',
    'YOURCALL-4} Connected to CHAT',
    'Returned to Node',
    '73 de YOURCALL',
    'de YOURCALL>',
    'de YOURCALL',
]

def is_noise(line):
    line = line.strip()
    if not line: return True
    for n in NOISE:
        if n.lower() in line.lower(): return True
    # Strip BPQ version tags [BPQ-x.x.x.x]
    if re.match(r'^\[BPQ', line): return True
    return False

def strip_telnet(data):
    """Strip telnet IAC negotiation bytes."""
    result = bytearray()
    i = 0
    while i < len(data):
        if data[i] == 0xff and i+2 < len(data):
            i += 3  # skip IAC + command + option
        elif data[i] == 0xff and i+1 < len(data) and data[i+1] == 0xff:
            result.append(0xff)
            i += 2
        else:
            result.append(data[i])
            i += 1
    return result.decode('utf-8', errors='replace')

# ── Message storage ───────────────────────────────────────────────
def add_message(text, cls='other'):
    global messages
    text = text.strip()
    if not text or is_noise(text): return
    global seq_counter
    seq_counter += 1
    msg = {
        'seq':     seq_counter,
        'ts':      time.strftime('%H:%M:%S', time.gmtime()),
        'unix_ts': time.time(),
        'text':    text,
        'cls':     cls,
    }
    with msg_lock:
        messages.append(msg)
        if len(messages) > MAX_MESSAGES:
            messages = messages[-MAX_MESSAGES:]
    save_messages()

def save_messages():
    try:
        Path(STATE_DIR).mkdir(parents=True, exist_ok=True)
        tmp = MSG_FILE + '.tmp'
        with open(tmp, 'w') as f:
            json.dump(messages[-200:], f)
        os.replace(tmp, MSG_FILE)
    except Exception as e:
        print(f"save_messages error: {e}", flush=True)

def save_state(conn, m):
    try:
        Path(STATE_DIR).mkdir(parents=True, exist_ok=True)
        with open(STATE_FILE, 'w') as f:
            json.dump({'connected': conn, 'mode': m, 'pid': os.getpid(),
                       'updated': time.time()}, f)
    except Exception as e:
        print(f"save_state error: {e}", flush=True)

# ── BPQ connection ────────────────────────────────────────────────
def bpq_recv(sock, expect, timeout=10):
    buf = b''
    start = time.time()
    while time.time() - start < timeout:
        ready = select.select([sock], [], [], 0.3)
        if ready[0]:
            try:
                chunk = sock.recv(4096)
                if not chunk:
                    break
                buf += chunk
                if expect.lower() in strip_telnet(buf).lower():
                    break
            except Exception:
                break
        time.sleep(0.05)
    return strip_telnet(buf)

def bpq_send(sock, cmd):
    global last_send_time
    sock.sendall((cmd + '\r\n').encode())
    last_send_time = time.time()

def connect_bpq(target_mode):
    global connected, mode
    print(f"Connecting to BPQ {BPQ_HOST}:{BPQ_PORT}...")
    add_message(f"Connecting to BPQ node...", 'system')

    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(15)
    try:
        sock.connect((BPQ_HOST, BPQ_PORT))
    except Exception as e:
        add_message(f"Connection failed: {e}", 'error')
        return None

    # Login. 2026-10-05: a send can fail when LinBPQ is restarting at the
    # same moment (e.g. after a password rotation); that used to raise out
    # of here and kill the daemon (exit 1). Treat it as a failed attempt so
    # main() backs off and retries.
    try:
        print('Waiting for user prompt...', flush=True)
        bpq_recv(sock, 'user:', 8)
        print('Sending username...', flush=True)
        bpq_send(sock, BPQ_USER)
        print('Waiting for password...', flush=True)
        bpq_recv(sock, 'password:', 8)
        print('Sending password...', flush=True)
        bpq_send(sock, BPQ_PASS)
        r = bpq_recv(sock, BPQ_USER+'>', 10)

        if 'invalid' in r.lower() or 'failed' in r.lower():
            print('Login failed — check config.php bbs.user / bbs.pass', flush=True)
            add_message("Login failed", 'error')
            sock.close()
            return None

        if target_mode == 'chat':
            bpq_send(sock, 'NODE')
            bpq_recv(sock, 'Returned to Node', 6)
            time.sleep(0.3)
            bpq_send(sock, 'CHAT')
            r = bpq_recv(sock, 'BPQChatServer', 8)
            if 'BPQChatServer' not in r:
                add_message("Failed to enter chat", 'error')
                sock.close()
                return None
            add_message("─── Connected to BPQ Chat ───", 'system')
    except OSError as e:
        print(f"Connection lost during login: {e.__class__.__name__}: {e}", flush=True)
        add_message(f"Connection lost during login — will retry", 'error')
        try: sock.close()
        except Exception: pass
        return None

    connected = True
    mode = target_mode
    save_state(True, target_mode)
    sock.settimeout(None)
    return sock

# ── Reader thread ─────────────────────────────────────────────────
def heartbeat_thread():
    """Update state file every 15 seconds so PHP knows daemon is alive."""
    global connected, running, mode
    while running:
        if connected:
            save_state(True, mode)
        time.sleep(15)

def keepalive_thread(sock):
    """Send a Telnet NOP whenever the socket's been idle too long.

    A raw IAC NOP (0xFF 0xF1) is a protocol-level no-op — real telnet
    stacks are required to absorb it silently, so it never shows up as
    a blank line/message in the chat room itself, unlike sending a bare
    '\\r\\n'. Only sent when nothing real has gone out recently, so it
    never interferes with normal typing.
    """
    global running, connected, last_send_time
    while running and connected:
        time.sleep(10)
        if not connected:
            break
        idle_for = time.time() - last_send_time
        if idle_for >= KEEPALIVE_INTERVAL:
            try:
                sock.sendall(b'\xff\xf1')  # IAC NOP
                last_send_time = time.time()
                print(f"Sent keepalive (idle {idle_for:.0f}s)", flush=True)
            except Exception as e:
                print(f"Keepalive send failed: {e}", flush=True)
                break

# ── Rejoin chat after BPQ's idle disconnect ───────────────────────
# 2026-10-24: LinBPQ's chat server drops any local chat user who hasn't
# sent a real chat line for 2 hours (HanksRT.c: "*** Disconnected - Idle
# time exceeded", Idle > 7200). Commands like /U don't count, and the
# telnet NOP keepalive above never reaches the chat server. The telnet
# socket stays up, so this daemon used to sit at the *node* prompt still
# reporting "connected": /U got no answer (Online panel empty), and
# station messages went to the node instead of the chat. "Returned to
# Node" is in NOISE, so nothing showed on the page. Now we spot it and
# type CHAT again.
KICK_RE = re.compile(r'^(?:\*\*\*\s*Disconnected - Idle time exceeded'
                     r'|(?:[A-Z0-9\-]+\}\s*)?Returned to Node\b)', re.I)
REJOIN_MIN_INTERVAL = 60   # never retry more often than this
_last_rejoin = 0.0

def rejoin_chat(sock):
    global _last_rejoin
    if time.time() - _last_rejoin < REJOIN_MIN_INTERVAL:
        return
    _last_rejoin = time.time()
    def _go():
        time.sleep(2)
        if not connected:
            return
        try:
            print('Dropped out of chat (idle timeout / returned to node) - re-entering', flush=True)
            bpq_send(sock, 'CHAT')
            add_message("─── Re-entered chat after BPQ idle disconnect ───", 'system')
        except Exception as e:
            print(f'Rejoin send failed: {e}', flush=True)
    threading.Thread(target=_go, daemon=True).start()

def reader_thread(sock):
    """Continuously read from BPQ socket and store messages."""
    global connected, running
    buf = ''
    while running and connected:
        try:
            ready = select.select([sock], [], [], 1.0)
            if not ready[0]:
                continue
            data = sock.recv(4096)
            if not data:
                print('Socket closed by remote', flush=True)
                break
            text = strip_telnet(data)
            buf += text
            while '\n' in buf:
                line, buf = buf.split('\n', 1)
                line = line.replace('\r','').strip()
                if line:
                    if mode == 'chat' and KICK_RE.match(line):
                        rejoin_chat(sock)
                    add_message(line, 'other')
        except Exception as e:
            print(f'Reader error: {e}', flush=True)
            break
    connected = False
    save_state(False, mode)
    add_message("─── Disconnected ───", 'system')

# ── Command reader thread ─────────────────────────────────────────
def command_thread(sock):
    """Read commands from queue file and send to BPQ."""
    global running
    CMD_QUEUE = STATE_DIR + '/chat-cmd-queue.json'
    CMD_LOCK  = STATE_DIR + '/chat-cmd-queue.lock'

    while running and connected:
        try:
            if os.path.exists(CMD_QUEUE):
                # Read and clear queue atomically
                with open(CMD_LOCK, 'w') as lf:
                    import fcntl
                    fcntl.flock(lf, fcntl.LOCK_EX)
                    try:
                        with open(CMD_QUEUE, 'r') as f:
                            queue = json.load(f)
                        os.unlink(CMD_QUEUE)
                    finally:
                        fcntl.flock(lf, fcntl.LOCK_UN)

                for item in queue:
                    cmd = item.get('cmd', '').strip()
                    if cmd:
                        print(f"CMD: {cmd}", flush=True)
                        bpq_send(sock, cmd)
                        time.sleep(0.2)
        except Exception as e:
            pass
        time.sleep(0.5)

# ── Main loop ─────────────────────────────────────────────────────
def main():
    global running, connected
    target_mode = sys.argv[1] if len(sys.argv) > 1 else 'chat'

    # ── Single instance lock ──────────────────────────────────────
    LOCK_FILE = STATE_DIR + '/chat-daemon.lock'
    import fcntl
    lock_fd = open(LOCK_FILE, 'w')
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except IOError:
        print("Another daemon instance is already running — exiting", flush=True)
        sys.exit(0)
    lock_fd.write(str(os.getpid()))
    lock_fd.flush()

    def handle_signal(sig, frame):
        global running
        print("Shutting down...", flush=True)
        running = False
        save_state(False, target_mode)
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_UN)
            os.unlink(LOCK_FILE)
        except: pass
        sys.exit(0)

    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)

    consecutive_failures = 0

    while running:
        connect_started = time.time()
        try:
            sock = connect_bpq(target_mode)
        except Exception as e:   # 2026-10-05: never let one bad attempt kill the daemon
            print(f"Connect attempt failed: {e.__class__.__name__}: {e}", flush=True)
            sock = None
        if sock:
            # Start reader, command, heartbeat, and keepalive threads
            rt = threading.Thread(target=reader_thread,   args=(sock,), daemon=True)
            ct = threading.Thread(target=command_thread,  args=(sock,), daemon=True)
            ht = threading.Thread(target=heartbeat_thread,             daemon=True)
            kt = threading.Thread(target=keepalive_thread, args=(sock,), daemon=True)
            rt.start()
            ct.start()
            ht.start()
            kt.start()
            rt.join()  # Wait for reader to finish (disconnected)
            sock.close()

            # A connection that held for a reasonable stretch counts as a
            # real success — reset backoff so a later, unrelated blip
            # still recovers quickly instead of inheriting a long delay
            # from an earlier bad run.
            if time.time() - connect_started >= MIN_STABLE_SECONDS:
                consecutive_failures = 0
            else:
                consecutive_failures += 1
        else:
            consecutive_failures += 1

        if running:
            delay = min(RECONNECT_BASE_DELAY * (2 ** max(0, consecutive_failures - 1)),
                        RECONNECT_MAX_DELAY)
            print(f"Reconnecting in {delay}s... "
                  f"(consecutive failures: {consecutive_failures})")
            add_message(f"Reconnecting in {delay}s...", 'system')
            time.sleep(delay)

if __name__ == '__main__':
    main()
