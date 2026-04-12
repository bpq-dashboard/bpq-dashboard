#!/usr/bin/env python3
"""
bpq-chat-daemon.py — Persistent BPQ telnet connection broker
Stays connected to BPQ chat/terminal and writes output to a shared
message file that bpq-chat.php reads. Accepts commands via a FIFO pipe.

Run as: python3 /var/www/html/bpq/scripts/bpq-chat-daemon.py
Or via systemd service.
"""

import socket, time, threading, json, os, sys, signal, re, select
from pathlib import Path

# ── Config ────────────────────────────────────────────────────────
BPQ_HOST   = 'localhost'
BPQ_PORT   = 8010
BPQ_USER   = 'YOURCALL'
BPQ_PASS   = 'dawgs1958'

STATE_DIR  = '/var/www/html/bpq/cache/chat-sessions'
MSG_FILE   = STATE_DIR + '/chat-messages.json'
CMD_FIFO   = STATE_DIR + '/chat-commands.fifo'
STATE_FILE = STATE_DIR + '/chat-daemon.json'

MAX_MESSAGES = 200   # keep last 200 messages in buffer
RECONNECT_DELAY = 5  # seconds between reconnect attempts

Path(STATE_DIR).mkdir(parents=True, exist_ok=True)

# ── Shared state ──────────────────────────────────────────────────
messages = []
msg_lock = threading.Lock()
connected = False
mode = 'chat'
running = True
seq_counter = 0

# ── Noise filter ──────────────────────────────────────────────────
NOISE = [
    'BPQ32 Telnet Server',
    'Enter ? for list of commands',
    'YOURCALL} Connected',
    'YOURCALL} Connected',
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
    sock.sendall((cmd + '\r\n').encode())

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

    # Login
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

    while running:
        sock = connect_bpq(target_mode)
        if sock:
            # Start reader and command threads
            rt = threading.Thread(target=reader_thread,  args=(sock,), daemon=True)
            ct = threading.Thread(target=command_thread, args=(sock,), daemon=True)
            ht = threading.Thread(target=heartbeat_thread,           daemon=True)
            rt.start()
            ct.start()
            ht.start()
            rt.join()  # Wait for reader to finish (disconnected)
            sock.close()
        if running:
            print(f"Reconnecting in {RECONNECT_DELAY}s...")
            add_message(f"Reconnecting in {RECONNECT_DELAY}s...", 'system')
            time.sleep(RECONNECT_DELAY)

if __name__ == '__main__':
    main()
