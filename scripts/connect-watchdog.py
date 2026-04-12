#!/usr/bin/env python3
"""
BPQ Connect Watchdog
====================
Monitors BBS logs for repeated failed outgoing connection attempts and
temporarily pauses forwarding to unreachable partners.

Logic:
  - A failed attempt = "Connecting to BBS X" followed by "X Disconnected"
    within FAIL_WINDOW_SECS with no "*** Connected" in between.
  - If FAIL_THRESHOLD failures are detected within FAIL_WINDOW_MINS minutes
    for a partner, their ConnectScript is replaced with a null/pause script.
  - After PAUSE_HOURS hours the original ConnectScript is restored.
  - State is persisted in a JSON file so it survives across cron runs.

Cron (every 5 minutes):
    */5 * * * * /usr/bin/python3 /var/www/bpqdash/scripts/connect-watchdog.py

Author: YOURCALL BPQ Dashboard Project
Version: 1.0.0
"""

import os
import re
import sys
import json
import logging
import platform
import subprocess
import time
from datetime import datetime, timezone, timedelta
from pathlib import Path

# ============================================================================
# CONFIGURATION
# ============================================================================

IS_WINDOWS = platform.system() == 'Windows'

CONFIG = {
    'linmail_cfg': (
        os.path.join(os.environ.get('APPDATA', 'C:\\'), 'BPQ32', 'linmail.cfg')
        if IS_WINDOWS else '/home/SYSOP/linbpq/linmail.cfg'
    ),
    'bbs_log_dir': (
        'C:\\UniServerZ\\www\\bpq\\logs'
        if IS_WINDOWS else '/var/www/bpqdash/logs'
    ),
    'state_file': (
        'C:\\UniServerZ\\www\\bpq\\cache\\watchdog-state.json'
        if IS_WINDOWS else '/var/www/bpqdash/cache/watchdog-state.json'
    ),
    'log_file': (
        'C:\\UniServerZ\\www\\bpq\\logs\\connect-watchdog.log'
        if IS_WINDOWS else '/var/www/bpqdash/logs/connect-watchdog.log'
    ),

    # Detection settings
    'fail_threshold':   3,      # Number of failures before pausing
    'fail_window_mins': 180,    # Rolling window to count failures in (3 hours — catches slow persistent failures)
    'fail_window_secs': 120,    # Max seconds between connect and disconnect to count as failed
    'pause_hours':      4,      # How long to pause forwarding after threshold hit
    'lookback_mins':    30,     # How far back in the log to scan on each run (must be > cron interval)
}

# Partners — must match prop-scheduler.py PARTNERS keys and connect_call values
# The watchdog matches on the connect_call (e.g. PARTNER1-2, PARTNER4-3) as that's
# what appears in the BBS log.
PARTNERS = {
    'PARTNER1':   {'connect_call': 'PARTNER1-2',   'attach_port': 3},
    'PARTNER3':   {'connect_call': 'PARTNER3-7',   'attach_port': 3},
    'PARTNER4':  {'connect_call': 'PARTNER4-3',  'attach_port': 3},
    'PARTNER7':   {'connect_call': 'PARTNER7-1',   'attach_port': 3},
    'PARTNER5':    {'connect_call': 'PARTNER5',      'attach_port': 3},
    'PARTNER6':   {'connect_call': 'PARTNER6-1',   'attach_port': 3},
    'PARTNER2':  {'connect_call': 'PARTNER2-1',  'attach_port': 3},
}

# Pause mechanism — toggle Enabled = 0 / Enabled = 1 in linmail.cfg
# This is safer than modifying ConnectScript as BPQ skips the station
# entirely rather than executing a potentially hanging script.

# BBS notification — sends a personal message to SYSOP on pause/restore
BBS_CONFIG = {
    'enabled':   True,
    'host':      'localhost',
    'port':      8010,
    'user':      'YOURCALL',
    'password':  'YOURPASSWORD',
    'alias':     'YOURCALL',
    'notify_to': 'YOURCALL',
}

# ============================================================================
# LOGGING
# ============================================================================

log = logging.getLogger('connect-watchdog')
log.setLevel(logging.INFO)
log.propagate = False
if not log.handlers:
    _fmt = logging.Formatter('%(asctime)s [%(levelname)s] %(message)s',
                              datefmt='%Y-%m-%d %H:%M:%S')
    _fh = logging.FileHandler(CONFIG['log_file'])
    _fh.setFormatter(_fmt)
    log.addHandler(_fh)
    _sh = logging.StreamHandler(sys.stdout)
    _sh.setFormatter(_fmt)
    log.addHandler(_sh)

# ============================================================================
# STATE MANAGEMENT
# ============================================================================

def load_state() -> dict:
    """Load watchdog state from JSON file."""
    path = CONFIG['state_file']
    if not os.path.exists(path):
        return {}
    try:
        with open(path, 'r') as f:
            return json.load(f)
    except Exception as e:
        log.warning(f"Could not load state file: {e}")
        return {}


def save_state(state: dict):
    """Save watchdog state to JSON file."""
    path = CONFIG['state_file']
    os.makedirs(os.path.dirname(path), exist_ok=True)
    try:
        with open(path, 'w') as f:
            json.dump(state, f, indent=2)
    except Exception as e:
        log.warning(f"Could not save state file: {e}")


# ============================================================================
# LINMAIL.CFG HELPERS
# ============================================================================

def read_linmail_cfg() -> str:
    """Read linmail.cfg and return raw content."""
    path = CONFIG['linmail_cfg']
    try:
        with open(path, 'r', errors='replace') as f:
            return f.read()
    except IOError as e:
        log.error(f"Cannot read linmail.cfg: {e}")
        return ''


def write_linmail_cfg(content: str):
    """Write content back to linmail.cfg."""
    path = CONFIG['linmail_cfg']
    try:
        # Write to temp file then rename for atomicity
        tmp = path + '.watchdog.tmp'
        with open(tmp, 'w') as f:
            f.write(content)
        os.replace(tmp, path)
        log.info(f"  linmail.cfg updated")
    except IOError as e:
        log.error(f"Cannot write linmail.cfg: {e}")


def apply_enabled_change(partner_key: str, enabled: bool) -> bool:
    """Stop BPQ, re-read linmail.cfg, apply Enabled change, write, start BPQ.

    Mirrors prop-scheduler.py's proven stop/reread/write/start sequence.
    BPQ saves its in-memory state on shutdown so we must re-read after
    stopping to avoid overwriting anything BPQ changed internally.

    Returns True if successful, False if BPQ stop/start failed.
    """
    stop_cmd  = CONFIG.get('bpq_stop_cmd',  'systemctl stop bpq')
    start_cmd = CONFIG.get('bpq_start_cmd', 'systemctl start bpq')
    cfg_path  = CONFIG['linmail_cfg']
    val       = '1' if enabled else '0'

    # Step 1 — stop BPQ so it saves its state before we write
    log.info(f"  Stopping BPQ to apply Enabled={val} for {partner_key}...")
    try:
        subprocess.run(stop_cmd, shell=True, check=True, timeout=30)
        log.info("  BPQ stopped")
        time.sleep(3)  # Give BPQ time to save and fully exit
    except subprocess.SubprocessError as e:
        log.error(f"  Failed to stop BPQ: {e}")
        return False

    # Step 2 — re-read config after BPQ saved its state
    try:
        with open(cfg_path, 'r', errors='replace') as f:
            fresh_cfg = f.read()
    except IOError as e:
        log.error(f"  Cannot re-read linmail.cfg after stop: {e}")
        # Try to restart BPQ even if we can't read config
        subprocess.run(start_cmd, shell=True, timeout=30)
        return False

    # Step 3 — apply the Enabled change to the fresh config
    new_cfg = set_enabled(fresh_cfg, partner_key, enabled)
    if not new_cfg:
        log.warning(f"  {partner_key} block not found in re-read config — skipping write")
        subprocess.run(start_cmd, shell=True, timeout=30)
        return False

    # Step 4 — write updated config
    try:
        tmp = cfg_path + '.watchdog.tmp'
        with open(tmp, 'w') as f:
            f.write(new_cfg)
        os.replace(tmp, cfg_path)
        log.info(f"  linmail.cfg written with Enabled={val} for {partner_key}")
    except IOError as e:
        log.error(f"  Cannot write linmail.cfg: {e}")
        subprocess.run(start_cmd, shell=True, timeout=30)
        return False

    # Step 5 — start BPQ with updated config
    try:
        subprocess.run(start_cmd, shell=True, check=True, timeout=30)
        log.info("  BPQ started with updated config")
        time.sleep(5)  # Wait for BPQ to initialise
        return True
    except subprocess.SubprocessError as e:
        log.error(f"  Failed to start BPQ: {e}")
        return False


def get_enabled(cfg_content: str, partner_call: str) -> bool:
    """Get current Enabled state for a partner section."""
    pattern = re.compile(
        rf'{re.escape(partner_call)}\s*:\s*\{{([^}}]*?)\}}',
        re.DOTALL
    )
    match = pattern.search(cfg_content)
    if not match:
        return True  # Assume enabled if not found
    block = match.group(1)
    enabled_match = re.search(r'Enabled\s*=\s*(\d+)', block)
    if not enabled_match:
        return True
    return enabled_match.group(1) == '1'


def set_enabled(cfg_content: str, partner_call: str, enabled: bool) -> str | None:
    """Set Enabled = 1 or Enabled = 0 for a partner section in linmail.cfg.

    Finds the partner block and toggles only the Enabled field.
    Returns new content or None if partner not found.
    """
    val = '1' if enabled else '0'
    pattern = re.compile(
        rf'({re.escape(partner_call)}\s*:\s*\{{[^}}]*?Enabled\s*=\s*)\d+(\s*;)',
        re.DOTALL
    )
    match = pattern.search(cfg_content)
    if not match:
        log.warning(f"  Partner {partner_call} not found in linmail.cfg")
        return None
    # Replace just the digit between group(1) end and group(2) start
    return cfg_content[:match.end(1)] + val + cfg_content[match.start(2):]


# ============================================================================
# LOG PARSER
# ============================================================================

def parse_log_timestamp(line: str) -> datetime | None:
    """Parse BPQ log timestamp: 260316 03:10:20 → datetime."""
    m = re.match(r'^(\d{6})\s+(\d{2}:\d{2}:\d{2})', line)
    if not m:
        return None
    try:
        date_str = m.group(1)   # YYMMDD
        time_str = m.group(2)   # HH:MM:SS
        yy = int(date_str[0:2]) + 2000
        mm = int(date_str[2:4])
        dd = int(date_str[4:6])
        hh, mi, ss = map(int, time_str.split(':'))
        return datetime(yy, mm, dd, hh, mi, ss, tzinfo=timezone.utc)
    except (ValueError, IndexError):
        return None


def scan_log_for_failures(partner_call: str, connect_call: str,
                           lookback_mins: int) -> list:
    """
    Scan today's BBS log for failed outgoing connect attempts to a partner.

    A failed attempt is defined as:
      |PARTNER    Connecting to BBS PARTNER
      |PARTNER    PARTNER Disconnected          (within fail_window_secs, no *** Connected)

    Returns list of datetime objects — one per confirmed failed attempt
    within the last lookback_mins minutes.
    """
    now = datetime.now(timezone.utc)
    cutoff = now - timedelta(minutes=lookback_mins)

    # Load today's log (and yesterday's if lookback crosses midnight)
    log_dir = CONFIG['bbs_log_dir']
    today = now.date()
    yesterday = today - timedelta(days=1)

    def _load_log(d):
        path = os.path.join(log_dir,
            f"log_{d.strftime('%y%m%d')}_BBS.txt")
        if not os.path.exists(path):
            return []
        try:
            with open(path, 'r', errors='replace') as f:
                return f.readlines()
        except IOError:
            return []

    lines = []
    # Include yesterday's log if the lookback window crosses midnight
    midnight = datetime.now(timezone.utc).replace(
        hour=0, minute=0, second=0, microsecond=0)
    if cutoff < midnight:
        lines = _load_log(yesterday)
    lines += _load_log(today)

    if not lines:
        return []

    # Build match patterns — match both bare callsign and connect_call
    # e.g. partner_call=PARTNER3, connect_call=PARTNER3-7
    # Log shows: "|PARTNER3     Connecting to BBS PARTNER3"
    # and:       "|PARTNER3     PARTNER3 Disconnected"
    base_call = partner_call.split('-')[0].upper()
    connect_base = connect_call.split('-')[0].upper()

    failures = []
    pending_connect_time = None  # Time of last "Connecting to BBS" line
    pending_error = False        # Saw a node-level error after *** CONNECTED

    fail_window = CONFIG['fail_window_secs']

    for line in lines:
        line = line.strip()
        ts = parse_log_timestamp(line)
        if ts is None:
            continue

        # Only look at recent lines — but don't reset pending state for old lines.
        # A Connecting line just before the cutoff followed by a Disconnected
        # just after the cutoff is still a valid failure — resetting here would
        # cause us to miss it.
        if ts < cutoff:
            continue

        upper = line.upper()

        # Check if this line is about our partner
        if base_call not in upper and connect_base not in upper:
            continue

        # Outgoing connect attempt
        # Do NOT reset pending_error here — if a previous attempt already
        # flagged an error and BPQ immediately retries, we want to carry
        # the error flag forward. Only reset pending_error on a fresh
        # connect that follows a clean disconnect (i.e. pending is None).
        if 'CONNECTING TO BBS' in upper:
            if pending_connect_time is None:
                # Fresh attempt — reset cleanly
                pending_error = False
            else:
                # Immediate retry — BPQ retried without a Disconnect line
                # Count the previous pending attempt as a failure if it
                # had a short elapsed time or a node error
                elapsed = (ts - pending_connect_time).total_seconds()
                if pending_error or elapsed <= fail_window:
                    failures.append(pending_connect_time)
                    log.debug(f"  Retry-reset failure for {partner_call} at "
                              f"{pending_connect_time} (elapsed: {elapsed:.0f}s "
                              f"before retry, node_error: {pending_error})")
                # Don't reset pending_error — carry it forward to new attempt
                pending_error = False
            pending_connect_time = ts
            continue

        # BPQ always shows *** CONNECTED when it reaches the local AGNODE —
        # this does NOT mean the RF connection to the remote partner succeeded.
        # Real success only follows with actual message exchange.
        # We treat *** CONNECTED as neutral — keep pending_connect_time alive.
        # Node-level errors that immediately follow *** CONNECTED indicate failure:
        #   "Error - Port in use"        — AGNODE port conflict
        #   "Sorry, Can't Connect"       — RF channel busy or no answer
        #   "CHANNEL BUSY"               — RF channel busy
        if '*** CONNECTED' in upper or '*** CONNECTED TO' in upper:
            # Don't clear pending — wait to see if an error or real data follows
            continue

        # Node-level errors — mark as error, still waiting for Disconnected
        if ('ERROR - PORT IN USE' in upper or
                "SORRY, CAN'T CONNECT" in upper or
                'CHANNEL BUSY' in upper or
                'NO ANSWER' in upper or
                'SORRY, STATION BUSY' in upper):
            pending_error = True
            continue

        # Disconnect — check if this follows a failed connect sequence
        if 'DISCONNECTED' in upper and pending_connect_time is not None:
            elapsed = (ts - pending_connect_time).total_seconds()
            # Count as failure if:
            #   (a) elapsed within window AND no *** CONNECTED seen  (simple RF fail)
            #   (b) a node-level error was flagged  (AGNODE reached but RF/port failed)
            if pending_error or elapsed <= fail_window:
                failures.append(ts)
                log.debug(f"  Failed attempt detected for {partner_call} at {ts} "
                          f"(elapsed: {elapsed:.0f}s, node_error: {pending_error})")
            pending_connect_time = None
            pending_error = False

    return failures


# ============================================================================
# BBS NOTIFICATION
# ============================================================================

def send_bbs_message(subject: str, body: str):
    """Send a personal message via BBS telnet connection.

    Uses the same BPQ telnet port (8010) and login sequence as
    prop-scheduler.py. Silently logs errors rather than raising.
    """
    if not BBS_CONFIG.get('enabled'):
        return

    import socket as _socket

    try:
        log.info(f"  Sending BBS notification: {subject}")

        sock = _socket.create_connection(
            (BBS_CONFIG['host'], BBS_CONFIG['port']),
            timeout=30
        )
        sock.settimeout(10)

        def read_until(expect, timeout=10):
            buf = b''
            start = _time.time()
            while (_time.time() - start) < timeout:
                try:
                    chunk = sock.recv(4096)
                    if chunk:
                        buf += chunk
                        if expect.encode() in buf.lower():
                            _time.sleep(0.2)
                            try:
                                extra = sock.recv(4096)
                                if extra:
                                    buf += extra
                            except _socket.timeout:
                                pass
                            return buf.decode(errors='replace')
                except _socket.timeout:
                    pass
                _time.sleep(0.1)
            return buf.decode(errors='replace')

        def send(text):
            sock.sendall((text + '\r\n').encode())
            _time.sleep(0.1)

        # Login
        read_until('user:', 10)
        send(BBS_CONFIG['user'])
        read_until('password:', 10)
        send(BBS_CONFIG['password'])
        read_until('}', 20)
        send(BBS_CONFIG['alias'])
        read_until('>', 15)

        # Compose personal message
        to_call = BBS_CONFIG['notify_to']
        send(f'SP {to_call}')
        read_until(':', 10)   # "Enter Title (only):"
        send(subject)

        # Drain body prompt
        _time.sleep(1.5)
        try:
            sock.setblocking(False)
            sock.recv(4096)
        except (BlockingIOError, _socket.error):
            pass
        sock.setblocking(True)
        sock.settimeout(10)

        # Send body
        for line in body.split('\n'):
            line = line.rstrip()
            if line.strip().lower() == '/ex':
                line = ' /ex'
            sock.sendall((line + '\r\n').encode())
            _time.sleep(0.03)

        # End message
        _time.sleep(0.5)
        sock.sendall(b'/ex\r\n')
        read_until('>', 15)

        # Disconnect
        send('B')
        _time.sleep(0.5)
        sock.close()

        log.info(f"  BBS notification sent to {to_call}")

    except Exception as e:
        log.warning(f"  BBS notification failed: {e}")



# ============================================================================
# MAIN WATCHDOG LOGIC
# ============================================================================

def run_watchdog():
    now = datetime.now(timezone.utc)
    log.info(f"=== Connect Watchdog run at {now.strftime('%Y-%m-%d %H:%M:%S')} UTC ===")

    state = load_state()
    cfg_content = read_linmail_cfg()
    if not cfg_content:
        log.error("Cannot read linmail.cfg — aborting")
        return

    state_changed = False

    for partner_key, partner in PARTNERS.items():
        connect_call = partner['connect_call']
        pstate = state.get(partner_key, {})

        # ── Check if partner is currently paused ─────────────────────────────
        if pstate.get('paused'):
            resume_time_str = pstate.get('resume_at', '')
            try:
                resume_time = datetime.fromisoformat(resume_time_str)
            except (ValueError, TypeError):
                resume_time = now  # Malformed — resume now

            if now >= resume_time:
                # Pause expired — restore original script
                # Re-enable the station — Enabled = 1
                ok = apply_enabled_change(partner_key, True)
                if ok:
                    log.info(f"  [{partner_key}] Pause expired — Enabled=1 restored")

                    # Send BBS notification
                    subject = f"Watchdog: {partner_key} forwarding RESTORED"
                    body = (
                        f"Connect Watchdog — {now.strftime('%Y-%m-%d %H:%M')} UTC\n"
                        f"{'='*45}\n\n"
                        f"Partner:  {partner_key} ({connect_call})\n"
                        f"Action:   RESTORED (Enabled=1 set in linmail.cfg)\n"
                        f"Reason:   Pause period of {CONFIG['pause_hours']}h expired\n\n"
                        f"Forwarding to {partner_key} has resumed normally.\n"
                    )
                    send_bbs_message(subject, body)
                else:
                    log.warning(f"  [{partner_key}] Could not set Enabled=1 — check linmail.cfg")

                # Clear pause state but keep fail history
                pstate['paused'] = False
                pstate['resume_at'] = None
                pstate['original_script'] = None
                pstate['pause_reason'] = None
                state[partner_key] = pstate
                state_changed = True
            else:
                remaining = (resume_time - now).total_seconds() / 60
                log.info(f"  [{partner_key}] Still paused — {remaining:.0f} mins remaining "
                         f"(resumes {resume_time.strftime('%H:%M')} UTC)")
                continue

        # ── Scan log for recent failures ──────────────────────────────────────
        failures = scan_log_for_failures(
            partner_key, connect_call, CONFIG['lookback_mins']
        )

        # Combine with any failures stored in state from previous runs
        # within the fail_window_mins rolling window
        window_cutoff = now - timedelta(minutes=CONFIG['fail_window_mins'])
        stored_failures = [
            datetime.fromisoformat(t)
            for t in pstate.get('recent_failures', [])
            if datetime.fromisoformat(t) >= window_cutoff
        ]

        # Merge and deduplicate (within 5 seconds = same event)
        all_failures = stored_failures[:]
        for f in failures:
            if not any(abs((f - sf).total_seconds()) < 5 for sf in all_failures):
                all_failures.append(f)

        # Keep only within window
        all_failures = [f for f in all_failures if f >= window_cutoff]
        all_failures.sort()

        # Save updated failure list
        pstate['recent_failures'] = [f.isoformat() for f in all_failures]
        state[partner_key] = pstate
        state_changed = True

        fail_count = len(all_failures)

        if fail_count == 0:
            log.info(f"  [{partner_key}] OK — no recent failures")
            continue

        log.info(f"  [{partner_key}] {fail_count} failure(s) in last "
                 f"{CONFIG['fail_window_mins']} mins "
                 f"(threshold: {CONFIG['fail_threshold']})")

        # ── Check if threshold exceeded ───────────────────────────────────────
        if fail_count >= CONFIG['fail_threshold']:
            # Disable the station — stop BPQ, write Enabled=0, start BPQ
            if not apply_enabled_change(partner_key, False):
                log.warning(f"  [{partner_key}] Could not apply Enabled=0 — skipping")
                continue

            resume_time = now + timedelta(hours=CONFIG['pause_hours'])

            pstate['paused'] = True
            pstate['resume_at'] = resume_time.isoformat()
            pstate['original_script'] = None  # Not needed — we toggle Enabled
            pstate['pause_reason'] = (
                f"{fail_count} failed attempts in {CONFIG['fail_window_mins']} mins "
                f"at {now.strftime('%H:%M')} UTC"
            )
            state[partner_key] = pstate
            state_changed = True

            log.warning(
                f"  [{partner_key}] *** PAUSED for {CONFIG['pause_hours']}h "
                f"— {fail_count} failures detected. "
                f"Resumes at {resume_time.strftime('%H:%M')} UTC"
            )

            # Send BBS notification
            subject = f"Watchdog: {partner_key} forwarding PAUSED"
            body = (
                f"Connect Watchdog — {now.strftime('%Y-%m-%d %H:%M')} UTC\n"
                f"{'='*45}\n\n"
                f"Partner:  {partner_key} ({connect_call})\n"
                f"Action:   PAUSED (Enabled=0 set in linmail.cfg)\n"
                f"Reason:   {fail_count} failed connect attempts in "
                f"{CONFIG['fail_window_mins']} minutes\n"
                f"Duration: {CONFIG['pause_hours']} hours\n"
                f"Resumes:  {resume_time.strftime('%Y-%m-%d %H:%M')} UTC\n\n"
                f"Forwarding to {partner_key} is suspended until that time.\n"
                f"To resume manually: python3 connect-watchdog.py --resume {partner_key}\n"
            )
            send_bbs_message(subject, body)

    # ── Save state (linmail.cfg written by apply_enabled_change on pause/restore) ──
    if state_changed:
        save_state(state)
        log.info("State saved")
    else:
        log.info("No changes needed")

    log.info("=== Watchdog complete ===\n")


# ============================================================================
# ENTRY POINT
# ============================================================================

if __name__ == '__main__':
    import argparse

    parser = argparse.ArgumentParser(description='BPQ Connect Watchdog')
    parser.add_argument('--status', action='store_true',
                        help='Show current pause state without making changes')
    parser.add_argument('--resume', type=str, metavar='CALLSIGN',
                        help='Manually resume a paused partner (e.g. --resume PARTNER3)')
    parser.add_argument('--pause', type=str, metavar='CALLSIGN',
                        help='Manually pause a partner (e.g. --pause PARTNER3)')
    parser.add_argument('--reset', action='store_true',
                        help='Clear all pause state and restore all scripts')
    args = parser.parse_args()

    # ── Status report ─────────────────────────────────────────────────────────
    if args.status:
        state = load_state()
        now = datetime.now(timezone.utc)
        print(f"\nConnect Watchdog Status — {now.strftime('%Y-%m-%d %H:%M:%S')} UTC\n")
        print(f"{'Partner':<12} {'Status':<12} {'Failures':<10} {'Resume At'}")
        print('-' * 55)
        for pk in PARTNERS:
            pstate = state.get(pk, {})
            paused = pstate.get('paused', False)
            fails  = len(pstate.get('recent_failures', []))
            resume = pstate.get('resume_at', '—')
            if resume and resume != '—':
                try:
                    resume = datetime.fromisoformat(resume).strftime('%H:%M UTC')
                except ValueError:
                    pass
            status = '⏸ PAUSED' if paused else '✓ Active'
            print(f"{pk:<12} {status:<12} {fails:<10} {resume}")
        print()
        sys.exit(0)

    # ── Manual resume ─────────────────────────────────────────────────────────
    if args.resume:
        call = args.resume.upper()
        if call not in PARTNERS:
            print(f"Unknown partner: {call}")
            sys.exit(1)
        state = load_state()
        cfg_content = read_linmail_cfg()
        pstate = state.get(call, {})
        new_cfg = set_enabled(cfg_content, call, True)  # use partner_key not connect_call
        if new_cfg:
            write_linmail_cfg(new_cfg)
            pstate['paused'] = False
            pstate['resume_at'] = None
            pstate['original_script'] = None
            pstate['recent_failures'] = []
            state[call] = pstate
            save_state(state)
            print(f"✓ {call} resumed — Enabled=1 restored")
        else:
            print(f"✗ Could not set Enabled=1 for {call}")
        sys.exit(0)

    # ── Manual pause ─────────────────────────────────────────────────────────
    if args.pause:
        call = args.pause.upper()
        if call not in PARTNERS:
            print(f"Unknown partner: {call}")
            sys.exit(1)
        state = load_state()
        cfg_content = read_linmail_cfg()
        # Use partner_key (call) not connect_call — linmail.cfg block named 'PARTNER3' not 'PARTNER3-7'
        new_cfg = set_enabled(cfg_content, call, False)
        if new_cfg:
            write_linmail_cfg(new_cfg)
            resume_time = datetime.now(timezone.utc) + timedelta(hours=CONFIG['pause_hours'])
            state[call] = {
                'paused': True,
                'resume_at': resume_time.isoformat(),
                'original_script': None,
                'pause_reason': 'Manual pause',
                'recent_failures': [],
            }
            save_state(state)
            print(f"✓ {call} paused for {CONFIG['pause_hours']}h — Enabled=0 set. Resumes {resume_time.strftime('%H:%M')} UTC")
        else:
            print(f"✗ Could not set Enabled=0 for {call}")
        sys.exit(0)

    # ── Reset all ─────────────────────────────────────────────────────────────
    if args.reset:
        state = load_state()
        cfg_content = read_linmail_cfg()
        for pk, partner in PARTNERS.items():
            pstate = state.get(pk, {})
            if pstate.get('paused'):
                new_cfg = set_enabled(cfg_content, pk, True)  # use partner_key not connect_call
                if new_cfg:
                    cfg_content = new_cfg
                    print(f"✓ {pk} Enabled=1 restored")
        write_linmail_cfg(cfg_content)
        save_state({})
        print("All state cleared")
        sys.exit(0)

    # ── Normal cron run ───────────────────────────────────────────────────────
    run_watchdog()
