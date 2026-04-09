#!/usr/bin/env python3
"""
BPQ BBS Geomagnetic Storm Monitor
==================================
Lightweight hourly monitor that checks Kp index and forces all HF
forwarding partners to 80m-only when geomagnetic conditions degrade.
Automatically restores propagation-optimized schedules when storm passes.

Usage:
    python3 storm-monitor.py              # Check and act
    python3 storm-monitor.py --status     # Show current state only
    python3 storm-monitor.py --restore    # Force restore optimized schedules

Cron (every hour):
    0 * * * * /usr/bin/python3 /var/www/bpqdash/scripts/storm-monitor.py >> /var/www/bpqdash/logs/storm-monitor.log 2>&1

Works alongside prop-scheduler.py — this handles fast reactions,
prop-scheduler handles slow optimization every 48 hours.

Author: YOURCALL BPQ Dashboard Project
Version: 1.0.0
"""

import json
import os
import sys
import re
import time
import logging
import argparse
import subprocess
import socket
from datetime import datetime, timezone, timedelta
from urllib.request import urlopen, Request
from urllib.error import URLError

import platform

# ============================================================================
# CONFIGURATION
# ============================================================================

IS_WINDOWS = platform.system() == 'Windows'

CONFIG = {
    'linmail_cfg': (
        os.path.join(os.environ.get('APPDATA', 'C:\\'), 'BPQ32', 'linmail.cfg')
        if IS_WINDOWS else '/home/tony/linbpq/linmail.cfg'
    ),
    'state_file': (
        'C:\\UniServerZ\\www\\bpq\\cache\\storm-state.json'
        if IS_WINDOWS else '/var/www/bpqdash/cache/storm-state.json'
    ),
    'log_file': (
        'C:\\UniServerZ\\www\\bpq\\logs\\storm-monitor.log'
        if IS_WINDOWS else '/var/www/bpqdash/logs/storm-monitor.log'
    ),
    'backup_dir': (
        'C:\\UniServerZ\\www\\bpq\\scripts\\prop-backups'
        if IS_WINDOWS else '/var/www/bpqdash/scripts/prop-backups'
    ),

    # BPQ control — platform-specific
    'bpq_stop_cmd': (
        'net stop BPQ32' if IS_WINDOWS else 'systemctl stop bpq'
    ),
    'bpq_start_cmd': (
        'net start BPQ32' if IS_WINDOWS else 'systemctl start bpq'
    ),

    # BBS notification
    'notify_via_bbs': True,
    'bbs_host': 'localhost',
    'bbs_port': 8010,
    'bbs_user': 'YOURCALL',
    'bbs_pass': 'YOURPASSWORD',
    'bbs_alias': 'bbs',
    'bbs_notify_to': 'YOURCALL',

    # Storm thresholds
    'kp_storm_threshold': 5,    # Kp >= 5 = storm mode (G1 minor storm)
    'kp_restore_threshold': 3,  # Kp < 3 for 2 consecutive checks = restore
    'consecutive_calm': 2,      # Number of calm checks before restoring

    # NOAA data
    'noaa_kp_url': 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json',

    # Partners to switch to 80m during storms — with distance and suspend threshold.
    # distance_mi: great-circle distance from home station (Augusta GA)
    # suspend_kp:  Kp level at which to SUSPEND attempts entirely (not just 80m).
    #              None = never suspend (short-path stations always worth trying).
    # Tiering logic:
    #   Kp 5-6 (G1): all partners → 80m only (existing behaviour)
    #   Kp 6-7 (G2): partners >= 500 mi suspended entirely
    #   Kp 7-8 (G3): partners >= 300 mi suspended entirely
    #   Kp 8+  (G4): all partners suspended except < 200 mi
    'storm_partners': {
        'PARTNER4': {'freq': '3.596000', 'mode': 'PKT-U', 'call': 'PARTNER4-3', 'port': 3, 'distance_mi':  87, 'suspend_kp': None},
        'PARTNER2': {'freq': '3.596000', 'mode': 'PKT-U', 'call': 'PARTNER2-1', 'port': 3, 'distance_mi': 309, 'suspend_kp': 7.0},
        'PARTNER5':   {'freq': '3.596000', 'mode': '',       'call': 'PARTNER5',     'port': 3, 'distance_mi': 374, 'suspend_kp': 7.0},
        'PARTNER3':  {'freq': '3.585000', 'mode': '',       'call': 'PARTNER3-7',  'port': 3, 'distance_mi': 498, 'suspend_kp': 6.0},
        'PARTNER7':  {'freq': '3.596000', 'mode': 'PKT-U', 'call': 'PARTNER7-1',  'port': 3, 'distance_mi': 518, 'suspend_kp': 6.0},
        'PARTNER1':  {'freq': '3.596000', 'mode': 'PKT-U', 'call': 'PARTNER1-2',  'port': 3, 'distance_mi': 571, 'suspend_kp': 6.0},
        'PARTNER6':  {'freq': '3.596000', 'mode': 'PKT-U', 'call': 'PARTNER6-1',  'port': 3, 'distance_mi': 620, 'suspend_kp': 6.0},
    },

    # Suspend all HF partners beyond this distance during extreme storms (G4+, Kp >= 8)
    'suspend_all_beyond_mi': 200,
}


# ============================================================================
# SETTINGS.JSON INTEGRATION
# ============================================================================

PARTNERS_FILE = os.path.normpath(os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', 'data', 'partners.json'
))


def load_partners_json():
    """Load partners.json — shared with prop-scheduler.py."""
    if not os.path.exists(PARTNERS_FILE):
        return None
    try:
        with open(PARTNERS_FILE, 'r') as f:
            data = json.load(f)
        print(f"[partners] Loaded {len(data.get('partners', []))} partner(s) from {PARTNERS_FILE}")
        return data
    except Exception as e:
        print(f"[partners] Warning: could not read partners.json: {e}", file=sys.stderr)
        return None


def load_settings_json():
    settings_path = os.path.normpath(os.path.join(
        os.path.dirname(os.path.abspath(__file__)),
        '..', 'data', 'settings.json'
    ))
    if not os.path.exists(settings_path):
        return None
    try:
        with open(settings_path, 'r') as f:
            return json.load(f)
    except Exception as e:
        print(f"[settings] Warning: could not read settings.json: {e}", file=sys.stderr)
        return None


def apply_settings_json():
    """Overlay CONFIG globals with values from settings.json."""
    global CONFIG

    # Priority 1 — partners.json (hand-editable, shared by prop-scheduler too)
    pdata = load_partners_json()
    if pdata and pdata.get('partners'):
        new_storm = {}
        for p in pdata['partners']:
            call = (p.get('call') or '').upper().strip()
            if not call:
                continue
            if not p.get('active', True):
                continue
            bands = p.get('bands') or {}
            if '80m' not in bands:
                continue  # storm monitor only uses 80m partners
            b80 = bands['80m']
            storm_cfg = p.get('storm', {})
            new_storm[call] = {
                'freq':        b80.get('freq', '3.596000'),
                'mode':        b80.get('mode', 'PKT-U'),
                'call':        p.get('connect_call', call),
                'port':        int(p.get('attach_port', 3)),
                'distance_mi': int(p.get('distance_mi', 0)),
                'suspend_kp':  storm_cfg.get('suspend_kp'),
            }
        if new_storm:
            CONFIG['storm_partners'] = new_storm
            print(f"[partners] Storm partners: {', '.join(new_storm.keys())}")
        home = pdata.get('home', {})
        if home.get('lat'): pass  # storm-monitor doesn't use home lat/lon directly

    # Priority 2 — settings.json (Dashboard UI overrides)
    s = load_settings_json()
    if s is None:
        return

    print("[settings] Loaded settings.json — overriding CONFIG")

    if 'bbs' in s:
        b = s['bbs']
        if b.get('host'):  CONFIG['bbs_host'] = b['host']
        if b.get('port'):  CONFIG['bbs_port'] = int(b['port'])
        if b.get('user'):  CONFIG['bbs_user'] = b['user']
        if b.get('pass'):  CONFIG['bbs_pass'] = b['pass']

    if 'paths' in s:
        p = s['paths']
        if p.get('linmail_cfg'):   CONFIG['linmail_cfg']   = p['linmail_cfg']
        if p.get('bpq_stop_cmd'):  CONFIG['bpq_stop_cmd']  = p['bpq_stop_cmd']
        if p.get('bpq_start_cmd'): CONFIG['bpq_start_cmd'] = p['bpq_start_cmd']
        if p.get('backup_dir'):    CONFIG['backup_dir']    = p['backup_dir']
        if p.get('log_dir'):
            CONFIG['log_file'] = os.path.join(p['log_dir'], 'storm-monitor.log')

    if 'storm_monitor' in s:
        sm = s['storm_monitor']
        if 'kp_storm_threshold'   in sm: CONFIG['kp_storm_threshold']   = float(sm['kp_storm_threshold'])
        if 'kp_restore_threshold' in sm: CONFIG['kp_restore_threshold'] = float(sm['kp_restore_threshold'])
        if 'consecutive_calm'     in sm: CONFIG['consecutive_calm']     = int(sm['consecutive_calm'])

    # Rebuild storm_partners from partners list (keep only those with 80m)
    if 'partners' in s and isinstance(s['partners'], list) and s['partners']:
        new_storm = {}
        for p in s['partners']:
            call = (p.get('call') or '').upper().strip()
            if not call:
                continue
            bands = p.get('bands') or {}
            if '80m' in bands:
                b80 = bands['80m']
                new_storm[call] = {
                    'freq': b80.get('freq', '3.596000'),
                    'mode': b80.get('mode', 'PKT-U'),
                    'call': p.get('connect_call', call),
                    'port': int(p.get('attach_port', 3)),
                }
        if new_storm:
            CONFIG['storm_partners'] = new_storm
            print(f"[settings] Storm partners: {', '.join(new_storm.keys())}")


# ============================================================================
# LOGGING
# ============================================================================

# Configure logger — use named logger with propagate=False to prevent
# duplicate log lines when cron redirects stdout to the same log file.
log = logging.getLogger('storm-monitor')
log.setLevel(logging.INFO)
log.propagate = False  # Don't pass to root logger — prevents duplicate lines

if not log.handlers:
    _fmt = logging.Formatter('%(asctime)s [STORM] %(levelname)s %(message)s')
    _fh  = logging.FileHandler(CONFIG['log_file'])
    _fh.setFormatter(_fmt)
    log.addHandler(_fh)
    _sh  = logging.StreamHandler(sys.stdout)
    _sh.setFormatter(_fmt)
    log.addHandler(_sh)

# ============================================================================
# STATE MANAGEMENT
# ============================================================================

def load_state():
    """Load persistent state."""
    try:
        if os.path.exists(CONFIG['state_file']):
            with open(CONFIG['state_file'], 'r') as f:
                return json.load(f)
    except (json.JSONDecodeError, IOError):
        pass
    return {
        'mode': 'normal',           # 'normal' or 'storm'
        'storm_activated': None,     # ISO timestamp when storm mode activated
        'calm_count': 0,             # Consecutive calm Kp readings
        'last_kp': None,
        'last_check': None,
        'saved_scripts': {},         # Original ConnectScripts before storm override
    }


def save_state(state):
    """Save persistent state."""
    os.makedirs(os.path.dirname(CONFIG['state_file']), exist_ok=True)
    with open(CONFIG['state_file'], 'w') as f:
        json.dump(state, f, indent=2)


# ============================================================================
# KP FETCHER
# ============================================================================

def fetch_kp():
    """Fetch current Kp index from NOAA."""
    try:
        req = Request(CONFIG['noaa_kp_url'], headers={'User-Agent': 'BPQ-StormMonitor/1.0'})
        with urlopen(req, timeout=15) as resp:
            data = json.loads(resp.read().decode())
        if data and len(data) > 1:
            # First row is header, last row is most recent
            kp = float(data[-1][1])
            return kp
    except (URLError, json.JSONDecodeError, ValueError, IndexError) as e:
        log.warning(f"Failed to fetch Kp: {e}")
    return None


# ============================================================================
# LINMAIL.CFG OPERATIONS
# ============================================================================

def read_cfg():
    """Read linmail.cfg content."""
    with open(CONFIG['linmail_cfg'], 'r', errors='replace') as f:
        return f.read()


def get_connect_script(cfg_content, partner_call):
    """Extract current ConnectScript for a partner."""
    pattern = re.compile(
        rf'{re.escape(partner_call)}\s*:\s*\{{[^}}]*?ConnectScript\s*=\s*"([^"]*?)"',
        re.DOTALL
    )
    match = pattern.search(cfg_content)
    return match.group(1) if match else None


def set_connect_script(cfg_content, partner_call, new_script):
    """Replace ConnectScript for a partner."""
    pattern = re.compile(
        rf'({re.escape(partner_call)}\s*:\s*\{{[^}}]*?ConnectScript\s*=\s*")([^"]*?)(")',
        re.DOTALL
    )
    match = pattern.search(cfg_content)
    if match:
        return cfg_content[:match.start(2)] + new_script + cfg_content[match.end(2):]
    return None


def build_storm_script(partner_info):
    """Build an 80m-only connect script for storm mode."""
    attach = f"ATTACH {partner_info['port']}"
    radio = f"RADIO {partner_info['freq']}"
    if partner_info.get('mode'):
        radio += f" {partner_info['mode']}"
    call = partner_info['call']
    return f"{attach}|{radio}|PAUSE|C {call}"


def get_enabled(cfg_content, partner_call):
    """Return True/False for a partner's Enabled flag, or None if not found."""
    pattern = re.compile(
        rf'{re.escape(partner_call)}\s*:\s*\{{[^}}]*?Enabled\s*=\s*(\d)',
        re.DOTALL
    )
    match = pattern.search(cfg_content)
    if match:
        return match.group(1) == '1'
    return None


def set_enabled(cfg_content, partner_call, enabled: bool):
    """Set Enabled = 0 or Enabled = 1 for a partner block.
    Returns updated cfg_content, or None if partner not found.
    """
    val = '1' if enabled else '0'
    pattern = re.compile(
        rf'({re.escape(partner_call)}\s*:\s*\{{[^}}]*?Enabled\s*=\s*)(\d)',
        re.DOTALL
    )
    match = pattern.search(cfg_content)
    if match:
        return cfg_content[:match.start(2)] + val + cfg_content[match.end(2):]
    return None


def apply_config(cfg_content):
    """Stop BPQ, write config, start BPQ."""
    # Stop
    try:
        subprocess.run(CONFIG['bpq_stop_cmd'], shell=True, check=True, timeout=30)
        log.info("BPQ stopped")
        time.sleep(2)
    except subprocess.SubprocessError as e:
        log.error(f"Failed to stop BPQ: {e}")
        return False

    # Re-read what BPQ saved, then apply our changes on top
    saved_cfg = read_cfg()
    # We need to re-apply changes to what BPQ just saved
    # The caller should pass the desired final content
    # But since BPQ may have changed other things, we apply per-partner

    with open(CONFIG['linmail_cfg'], 'w') as f:
        f.write(cfg_content)
    log.info("Config written")

    # Start
    try:
        subprocess.run(CONFIG['bpq_start_cmd'], shell=True, check=True, timeout=30)
        log.info("BPQ started")
        time.sleep(10)  # Wait for telnet port
        return True
    except subprocess.SubprocessError as e:
        log.error(f"Failed to start BPQ: {e}")
        return False


# ============================================================================
# BBS NOTIFICATION
# ============================================================================

def send_bbs_notification(subject, body):
    """Send a personal message via BBS telnet."""
    if not CONFIG.get('notify_via_bbs'):
        return

    try:
        to_call = CONFIG['bbs_notify_to']
        log.info(f"Sending BBS notification to {to_call}...")

        sock = socket.create_connection(
            (CONFIG['bbs_host'], CONFIG['bbs_port']),
            timeout=30
        )
        sock.settimeout(10)

        def read_until(expect, timeout=10):
            buf = b''
            start = time.time()
            while (time.time() - start) < timeout:
                try:
                    chunk = sock.recv(4096)
                    if chunk:
                        buf += chunk
                        if expect.encode() in buf.lower():
                            time.sleep(0.2)
                            try:
                                extra = sock.recv(4096)
                                if extra:
                                    buf += extra
                            except socket.timeout:
                                pass
                            return buf.decode(errors='replace')
                except socket.timeout:
                    pass
                time.sleep(0.1)
            return buf.decode(errors='replace')

        def send(text):
            sock.sendall((text + '\r\n').encode())
            time.sleep(0.1)

        # Login
        read_until('user:', 10)
        send(CONFIG['bbs_user'])
        read_until('password:', 10)
        send(CONFIG['bbs_pass'])
        read_until('}', 20)
        send(CONFIG['bbs_alias'])
        read_until('>', 15)

        # Send message
        send(f'SP {to_call}')
        read_until(':', 10)
        send(subject)

        # Wait for body prompt
        time.sleep(1.5)
        try:
            sock.setblocking(False)
            sock.recv(4096)
        except (BlockingIOError, socket.error):
            pass
        sock.setblocking(True)
        sock.settimeout(10)

        # Send body
        for line in body.split('\n'):
            line = line.rstrip()
            if line.strip().lower() == '/ex':
                line = ' /ex'
            sock.sendall((line + '\r\n').encode())
            time.sleep(0.03)

        time.sleep(0.5)
        sock.sendall(b'/ex\r\n')
        read_until('>', 15)
        send('B')
        time.sleep(0.5)
        sock.close()
        log.info("BBS notification sent")

    except Exception as e:
        log.error(f"Failed to send BBS notification: {e}")


# ============================================================================
# STORM MODE ACTIONS
# ============================================================================

def get_partner_action(info, kp):
    """Determine what action to take for a partner given current Kp.
    Returns: 'normal' | '80m' | 'suspend'
    """
    distance = info.get('distance_mi', 0)
    suspend_kp = info.get('suspend_kp')

    # G4+ (Kp >= 8): suspend everything beyond suspend_all_beyond_mi
    if kp >= 8.0 and distance > CONFIG.get('suspend_all_beyond_mi', 200):
        return 'suspend'

    # Partner-specific suspend threshold
    if suspend_kp is not None and kp >= suspend_kp:
        return 'suspend'

    # G1+ (Kp >= 5): switch to 80m
    if kp >= CONFIG['kp_storm_threshold']:
        return '80m'

    return 'normal'


def activate_storm_mode(state, kp):
    """Tiered storm response — 80m switch and/or suspend by distance and Kp severity."""
    g_level = 'G1' if kp < 6 else ('G2' if kp < 7 else ('G3' if kp < 8 else 'G4+'))
    log.warning(f"STORM MODE ACTIVATED — Kp={kp} ({g_level})")

    cfg_content = read_cfg()

    # Save current ConnectScripts only for partners being switched to 80m
    # (suspended partners use Enabled=0/1 — their scripts don't change)
    saved = {}
    for call, info in CONFIG['storm_partners'].items():
        action = get_partner_action(info, kp)
        if action == '80m':
            script = get_connect_script(cfg_content, call)
            if script:
                saved[call] = script
    state['saved_scripts'] = saved

    # Apply tiered actions
    switched_80m = []
    suspended    = []

    for call, info in CONFIG['storm_partners'].items():
        action = get_partner_action(info, kp)

        if action == 'suspend':
            new_cfg = set_enabled(cfg_content, call, False)
            if new_cfg:
                cfg_content = new_cfg
                suspended.append(call)
                log.warning(f"  {call} SUSPENDED — Enabled=0 ({info['distance_mi']} mi, Kp={kp} >= {info.get('suspend_kp','N/A')})")
            else:
                log.warning(f"  {call} — Enabled field not found in config, skipping suspend")

        elif action == '80m':
            storm_script = build_storm_script(info)
            new_cfg = set_connect_script(cfg_content, call, storm_script)
            if new_cfg:
                cfg_content = new_cfg
                switched_80m.append(call)
                log.info(f"  {call} → 80m only ({info['freq']} MHz, {info['distance_mi']} mi)")

        else:
            log.info(f"  {call} → no action ({info['distance_mi']} mi, within normal range for Kp={kp})")

    # Apply to BPQ
    if apply_config(cfg_content):
        state['mode'] = 'storm'
        state['storm_activated'] = datetime.now(timezone.utc).isoformat()
        state['calm_count'] = 0
        state['suspended_partners'] = suspended
        save_state(state)

        # Build notification
        body = (
            f"GEOMAGNETIC STORM ALERT\n"
            f"========================\n"
            f"Kp Index: {kp} ({g_level} storm)\n"
            f"Time: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}\n\n"
        )

        if switched_80m:
            body += f"SWITCHED TO 80m ({len(switched_80m)} partners):\n"
            for call in switched_80m:
                info = CONFIG['storm_partners'][call]
                body += f"  {call} → {info['freq']} MHz ({info['distance_mi']} mi)\n"
            body += "\n"

        if suspended:
            body += f"SUSPENDED — too distant for reliable HF ({len(suspended)} partners):\n"
            for call in suspended:
                info = CONFIG['storm_partners'][call]
                body += f"  {call} ({info['distance_mi']} mi) — suspended until storm clears\n"
            body += "\n"

        body += (
            f"Suspension thresholds:\n"
            f"  G2 (Kp 6+): partners >= 500 mi suspended\n"
            f"  G3 (Kp 7+): partners >= 300 mi suspended\n"
            f"  G4 (Kp 8+): all partners > 200 mi suspended\n\n"
            f"Will auto-restore when Kp < {CONFIG['kp_restore_threshold']} "
            f"for {CONFIG['consecutive_calm']} consecutive hours."
        )

        subject = f"STORM {g_level} Kp={kp} — {len(suspended)} suspended, {len(switched_80m)} on 80m"
        send_bbs_notification(subject, body)
    else:
        log.error("Failed to apply storm config")


def restore_normal_mode(state, kp):
    """Restore propagation-optimized schedules."""
    log.info(f"STORM PASSED — restoring optimized schedules (Kp={kp})")

    saved_scripts = state.get('saved_scripts', {})
    if not saved_scripts:
        log.warning("No saved scripts to restore — run prop-scheduler.py to regenerate")
        state['mode'] = 'normal'
        save_state(state)
        return

    # Stop BPQ, apply saved scripts
    cfg_content = read_cfg()

    # Stop BPQ first so it saves its current state
    try:
        subprocess.run(CONFIG['bpq_stop_cmd'], shell=True, check=True, timeout=30)
        time.sleep(2)
    except subprocess.SubprocessError as e:
        log.error(f"Failed to stop BPQ: {e}")
        return

    # Re-read after BPQ saved
    cfg_content = read_cfg()

    # Re-enable any partners that were suspended (Enabled=0)
    suspended = state.get('suspended_partners', [])
    for call in suspended:
        new_cfg = set_enabled(cfg_content, call, True)
        if new_cfg:
            cfg_content = new_cfg
            log.info(f"  {call} → Enabled=1 (unsuspended)")
        else:
            log.warning(f"  {call} — Enabled field not found, may need manual check")

    # Restore saved ConnectScripts for 80m-only partners
    for call, script in saved_scripts.items():
        if call in suspended:
            continue  # suspended partners keep their original script, just re-enable
        new_cfg = set_connect_script(cfg_content, call, script)
        if new_cfg:
            cfg_content = new_cfg
            log.info(f"  {call} → ConnectScript restored")

    with open(CONFIG['linmail_cfg'], 'w') as f:
        f.write(cfg_content)

    try:
        subprocess.run(CONFIG['bpq_start_cmd'], shell=True, check=True, timeout=30)
        time.sleep(10)
    except subprocess.SubprocessError as e:
        log.error(f"Failed to start BPQ: {e}")
        return

    state['mode'] = 'normal'
    state['storm_activated'] = None
    state['saved_scripts'] = {}
    state['suspended_partners'] = []
    state['calm_count'] = 0
    save_state(state)

    storm_duration = ''
    if state.get('storm_activated'):
        try:
            activated = datetime.fromisoformat(state['storm_activated'])
            duration = datetime.now(timezone.utc) - activated
            hours = duration.total_seconds() / 3600
            storm_duration = f"\nStorm duration: {hours:.1f} hours"
        except (ValueError, TypeError):
            pass

    body = (
        f"STORM CLEARED\n"
        f"=============\n"
        f"Kp Index: {kp} (quiet)\n"
        f"Time: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}\n"
        f"{storm_duration}\n\n"
        f"ACTION: Propagation-optimized schedules restored.\n"
        f"All partners back to normal multi-band forwarding."
    )
    send_bbs_notification("STORM CLEARED - Schedules Restored", body)


# ============================================================================
# MAIN
# ============================================================================

def main():
    # Load settings.json overrides (if Dashboard Settings UI has been configured)
    apply_settings_json()

    parser = argparse.ArgumentParser(description='BPQ Geomagnetic Storm Monitor')
    parser.add_argument('--status', action='store_true', help='Show current state only')
    parser.add_argument('--restore', action='store_true', help='Force restore optimized schedules')
    args = parser.parse_args()

    state = load_state()

    if args.status:
        print(f"Mode: {state['mode']}")
        print(f"Last Kp: {state.get('last_kp', 'unknown')}")
        print(f"Last check: {state.get('last_check', 'never')}")
        if state['mode'] == 'storm':
            print(f"Storm since: {state.get('storm_activated', 'unknown')}")
            print(f"Calm readings: {state.get('calm_count', 0)}/{CONFIG['consecutive_calm']}")
            suspended = state.get('suspended_partners', [])
            if suspended:
                print(f"Suspended partners ({len(suspended)}): {', '.join(suspended)}")
        print(f"Saved scripts: {len(state.get('saved_scripts', {}))} partners")
        return

    if args.restore:
        log.info("Manual restore requested")
        restore_normal_mode(state, state.get('last_kp', 0))
        return

    # Fetch current Kp
    kp = fetch_kp()
    if kp is None:
        log.warning("Could not fetch Kp — skipping this check")
        return

    state['last_kp'] = kp
    state['last_check'] = datetime.now(timezone.utc).isoformat()

    log.info(f"Kp={kp}, mode={state['mode']}, calm_count={state.get('calm_count', 0)}")

    if state['mode'] == 'normal':
        # Check if storm threshold exceeded
        if kp >= CONFIG['kp_storm_threshold']:
            activate_storm_mode(state, kp)
        else:
            state['calm_count'] = 0
            save_state(state)
            log.info("Conditions normal — no action needed")

    elif state['mode'] == 'storm':
        if kp < CONFIG['kp_restore_threshold']:
            state['calm_count'] = state.get('calm_count', 0) + 1
            log.info(f"Kp below restore threshold — calm reading {state['calm_count']}/{CONFIG['consecutive_calm']}")

            if state['calm_count'] >= CONFIG['consecutive_calm']:
                restore_normal_mode(state, kp)
            else:
                save_state(state)
        else:
            # Still stormy
            state['calm_count'] = 0
            save_state(state)
            log.info(f"Storm continues — Kp={kp}, staying on 80m")


if __name__ == '__main__':
    main()
