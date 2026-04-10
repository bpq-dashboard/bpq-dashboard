#!/usr/bin/env python3
"""
BPQ BBS Propagation-Based Forwarding Scheduler
===============================================
Automatically adjusts HF forwarding connect scripts in linmail.cfg
based on current propagation conditions, historical connection data,
and time-of-day band models.

Usage:
    python3 prop-scheduler.py                    # Dry run (show changes)
    python3 prop-scheduler.py --apply            # Apply changes + email report
    python3 prop-scheduler.py --apply --force    # Apply even if no changes

Cron (every 48 hours):
    0 6 */2 * * /usr/bin/python3 /var/www/tprfn/scripts/prop-scheduler.py --apply

Author: K1AJD BPQ Dashboard Project
Version: 1.0.1
"""

import json
import os
import sys
import re
import math
import socket
import logging
import argparse
import subprocess
import time
from datetime import datetime, timezone, timedelta
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import URLError

import platform

# ============================================================================
# CONFIGURATION
# ============================================================================

# Auto-detect platform
IS_WINDOWS = platform.system() == 'Windows'

CONFIG = {
    # Paths — auto-detect by platform
    'linmail_cfg': (
        os.path.join(os.environ.get('APPDATA', 'C:\\'), 'BPQ32', 'linmail.cfg')
        if IS_WINDOWS else '/home/tony/linbpq/linmail.cfg'
    ),
    'bbs_log_dir': (
        'C:\\UniServerZ\\www\\bpq\\logs'
        if IS_WINDOWS else '/var/www/tprfn/logs'
    ),
    'vara_log': (
        'C:\\UniServerZ\\www\\bpq\\logs\\k1ajd.vara'
        if IS_WINDOWS else '/var/www/tprfn/logs/k1ajd.vara'
    ),
    'backup_dir': (
        'C:\\UniServerZ\\www\\bpq\\scripts\\prop-backups'
        if IS_WINDOWS else '/var/www/tprfn/scripts/prop-backups'
    ),
    'state_file': (
        'C:\\UniServerZ\\www\\bpq\\cache\\prop-state.json'
        if IS_WINDOWS else '/var/www/tprfn/cache/prop-state.json'
    ),
    'log_file': (
        'C:\\UniServerZ\\www\\bpq\\logs\\prop-scheduler.log'
        if IS_WINDOWS else '/var/www/tprfn/logs/prop-scheduler.log'
    ),

    # Home station
    'home_call': 'K1AJD',
    'home_lat': 33.47,
    'home_lon': -82.01,
    'home_grid': 'EM83al',

    # BBS notification (sends report as personal message to SYSOP)
    'notify_via_bbs': True,
    'bbs_host': 'localhost',
    'bbs_port': 8010,
    'bbs_user': 'K1AJD',
    'bbs_pass': 'dawgs1958',
    'bbs_alias': 'bbs',
    'bbs_notify_to': 'K1AJD',

    # BPQ control — platform-specific
    'bpq_stop_cmd': (
        'net stop BPQ32' if IS_WINDOWS else 'systemctl stop bpq'
    ),
    'bpq_start_cmd': (
        'net start BPQ32' if IS_WINDOWS else 'systemctl start bpq'
    ),
    # e.g., 'sudo systemctl restart bpq32' or 'killall -HUP linbpq'

    # Propagation data URLs
    # Database credentials (matches tprfn-db.php)
    'db_user': 'tprfn_app',
    'db_pass': 'TprfnDb2026!',
    'db_name': 'tprfn',
    'noaa_sfi_url': 'https://services.swpc.noaa.gov/json/solar-cycle/observed-solar-cycle-indices.json',
    'noaa_kp_url': 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json',  # Returns objects: {time_tag, Kp, a_running}
    'noaa_forecast_url': 'https://services.swpc.noaa.gov/text/3-day-forecast.txt',

    # Tuning parameters
    'lookback_days': 14,       # Days of BBS logs to analyze
    'min_sessions': 3,         # Minimum sessions on a band to trust the data
    'sn_weight': 0.4,          # Weight for S/N in band scoring
    'success_weight': 0.35,    # Weight for success rate
    'prop_weight': 0.25,       # Weight for propagation model prediction
    'conserve_mode': True,     # If True, only change if new schedule scores 20%+ better
}

# ============================================================================
# PARTNER DEFINITIONS
# ============================================================================
# Each partner includes: callsign, location, grid, lat/lon, available bands,
# BPQ connect command template, and the SSID/alias for connection.

PARTNERS = {
    'N3MEL': {
        'name': 'Glenn',
        'location': 'Downingtown, PA',
        'lat': 40.01, 'lon': -75.71,
        'connect_call': 'N3MEL-2',
        'attach_port': 3,
        'bands': {
            # Per hub fact sheet: 80m NT (nighttime), 40m DT Scan, 20m DT Scan
            '80m': {'freq': '3.596000',  'mode': 'PKT-U'},   # NT — nighttime primary
            '40m': {'freq': '7.103200',  'mode': 'PKT-U'},   # DT Scan — daytime primary
            '20m': {'freq': '14.106500', 'mode': 'PKT-U'},   # DT Scan — daytime secondary (added 2026-03-19)
        },
        'fallback_script': 'TIMES 0000-1059|ATTACH 3|RADIO 3.596000 PKT-U|PAUSE|C N3MEL-2|ELSE|C 2 MELBBS|TIMES 1100-2059|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C N3MEL-2|ELSE|ATTACH 3|RADIO 14.106500 PKT-U|PAUSE|C N3MEL-2|ELSE|C 2 MELBBS|TIMES 2100-2359|ATTACH 3|RADIO 3.596000 PKT-U|PAUSE|C N3MEL-2|ELSE|C 2 MELBBS',
        # last_resort_else: after all RF attempts fail, try C 2 MELBBS via packet
        'last_resort_else': 'C 2 MELBBS',
    },
    'N4VAD': {
        'name': 'Greg',
        'location': 'Guyton, GA',
        'lat': 32.33, 'lon': -81.39,
        'connect_call': 'N4VAD-7',
        'attach_port': 3,
        'bands': {
            '80m': {'freq': '3.585000', 'mode': ''},
            '40m': {'freq': '7.115000', 'mode': ''},
        },
        'fallback_script': 'TIMES 0000-1059|ATTACH 3|RADIO 3.585000|C N4VAD-7|TIMES 1100-2259|ATTACH 3|RADIO 7.115000|C N4VAD-7|TIMES 2300-2359|ATTACH 3|RADIO 3.585000|C N4VAD-7',
    },
    'KD4WLE': {
        'name': 'Sean',
        'location': 'S. Florida',
        'lat': 26.15, 'lon': -80.15,
        'connect_call': 'KD4WLE-3',
        'attach_port': 3,
        'bands': {
            '80m': {'freq': '3.596000', 'mode': 'PKT-U'},
            '40m': {'freq': '7.103200', 'mode': 'PKT-U'},
            '20m': {'freq': '14.106500', 'mode': 'PKT-U'},
        },
        'fallback_script': 'TIMES 0000-1259|ATTACH 3|RADIO 3.596000 PKT-U|PAUSE|C KD4WLE-3|ELSE|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C KD4WLE-3|TIMES 1300-2359|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C KD4WLE-3|ELSE|ATTACH 3|RADIO 14.106500 PKT-U|PAUSE|C KD4WLE-3',
    },
    'N4SFL': {
        'name': 'Jay',
        'location': 'Delray, FL',
        'lat': 26.46, 'lon': -80.07,
        'connect_call': 'N4SFL-1',
        'attach_port': 3,
        'bands': {
            # Per hub fact sheet: 20m=14.1065, 40m=7.1032, 80m=3.596, 30m=10.147
            '80m': {'freq': '3.596000',  'mode': 'PKT-U'},
            '40m': {'freq': '7.103200',  'mode': 'PKT-U'},
            '30m': {'freq': '10.147000', 'mode': 'PKT-U'},
            '20m': {'freq': '14.106500', 'mode': 'PKT-U'},
        },
        'fallback_script': 'TIMES 0000-1259|ATTACH 3|RADIO 3.596000 PKT-U|PAUSE|C N4SFL-1|ELSE|ATTACH 3|PAUSE|RADIO 7.103200 PKT-U|C N4SFL-1|TIMES 1300-2359|ATTACH 3|RADIO 14.106500 PKT-U|C N4SFL-1|ELSE|ATTACH 3|PAUSE|RADIO 10.147000 PKT-U|C N4SFL-1|ELSE|ATTACH 3|PAUSE|RADIO 14.106500 PKT-U|C N4SFL-1',
    },
    'K7EK': {
        'name': 'Gary',
        'location': 'Radcliff, KY',
        'lat': 37.84, 'lon': -85.95,
        'connect_call': 'K7EK',
        'attach_port': 3,
        'bands': {
            '80m': {'freq': '3.596000', 'mode': ''},       # K7EK preferred (*3.596)
            '40m': {'freq': '7.103200', 'mode': 'PKT-U'},  # K7EK preferred (*7.1032)
            '30m': {'freq': '10.143000', 'mode': 'PKT-U'}, # K7EK preferred (*10.1430)
        },
        # K7EK scans multiple freqs per band on a fixed published schedule:
        # 80m 0000-1259z: 3.585, 3.586, 3.587, *3.596, 3.597 MHz (USB dial)
        # 40m 1300-1859z: 7.1009, 7.1015, 7.1025, *7.1032 MHz (USB dial)
        # 30m 1900-2359z: 10.1400, 10.1415, *10.1430, 10.147 MHz (USB dial)
        # VARA HF: 500, 2300, 2750 Hz BW — PACTOR 1/2/3/4 also supported
        # VARA retries MUST be 15-20 due to scanning dwell time (per Gary K7EK)
        # Mirrors WC9P frequencies. VHF packet 145.01 @ 1200 baud.
        'fixed_schedule': True,  # Don't override K7EK's published time windows
        'fallback_script': 'TIMES 0000-1259|ATTACH 3|RADIO 3.596000|PAUSE|C K7EK|TIMES 1300-1859|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C K7EK|TIMES 1900-2359|ATTACH 3|RADIO 10.143000 PKT-U|PAUSE|C K7EK',
    },
    'N9SEO': {
        'name': 'Kayne',
        'location': 'Mountain Home, AR',
        'lat': 36.34, 'lon': -92.38,
        'connect_call': 'N9SEO-1',
        'attach_port': 3,
        'bands': {
            '80m': {'freq': '3.596000', 'mode': 'PKT-U'},
            '40m': {'freq': '7.103200', 'mode': 'PKT-U'},
            '20m': {'freq': '14.106500', 'mode': 'PKT-U'},
        },
        'fallback_script': 'TIMES 0000-0159|ATTACH 3|RADIO 3.596000 PKT-U|PAUSE|C N9SEO-1|ELSE|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C N9SEO-1|TIMES 1300-2359|ATTACH 3|RADIO 14.106500 PKT-U|PAUSE|C N9SEO-1',
    },
    'KK4DIV': {
        'name': 'Bob',
        'location': 'Lynn Haven, FL',
        'lat': 30.24, 'lon': -85.65,
        'connect_call': 'KK4DIV-1',
        'attach_port': 3,
        'bands': {
            '80m': {'freq': '3.596000', 'mode': 'PKT-U'},
            '40m': {'freq': '7.103200', 'mode': 'PKT-U'},
            '20m': {'freq': '14.106500', 'mode': 'PKT-U'},
        },
        'fallback_script': 'TIMES 0000-0659|ATTACH 3|RADIO 3.596000 PKT-U|PAUSE|C KK4DIV-1|ELSE|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C KK4DIV-1|TIMES 0700-1959|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C KK4DIV-1|ELSE|ATTACH 3|RADIO 14.106500 PKT-U|PAUSE|C KK4DIV-1|TIMES 2000-2359|ATTACH 3|RADIO 3.596000 PKT-U|PAUSE|C KK4DIV-1|ELSE|ATTACH 3|RADIO 7.103200 PKT-U|PAUSE|C KK4DIV-1|ELSE|C 2 LHBBS',
        # last_resort_else: after all RF attempts fail, try C 2 LHBBS via packet
        'last_resort_else': 'C 2 LHBBS',
    },
}

# ============================================================================
# SETTINGS.JSON INTEGRATION
# ============================================================================
# If data/settings.json exists (created by the Dashboard Settings UI), its
# values override the hardcoded CONFIG and PARTNERS above.  The script remains
# fully backward-compatible: if settings.json is absent it runs exactly as
# before.

PARTNERS_FILE = os.path.normpath(os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', 'data', 'partners.json'
))


def load_partners_json():
    """Load partners.json — the primary editable partner configuration file.
    Both prop-scheduler and storm-monitor read this file.
    Returns the parsed JSON dict, or None if not found / invalid.
    """
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


def build_partners_from_list(partners_list):
    """Convert a partners array into the PARTNERS dict used internally."""
    new_partners = {}
    for p in partners_list:
        call = (p.get('call') or '').upper().strip()
        if not call:
            continue
        if not p.get('active', True):
            print(f"[partners] Skipping inactive partner: {call}")
            continue
        bands = {}
        for band, bdata in (p.get('bands') or {}).items():
            bands[band] = {'freq': bdata.get('freq', ''), 'mode': bdata.get('mode', '')}
        new_partners[call] = {
            'name':             p.get('name', ''),
            'location':         p.get('location', ''),
            'lat':              float(p.get('lat', 0)),
            'lon':              float(p.get('lon', 0)),
            'connect_call':     p.get('connect_call', call),
            'attach_port':      int(p.get('attach_port', 3)),
            'bands':            bands,
            'fixed_schedule':   bool(p.get('fixed_schedule', False)),
            'fallback_script':  p.get('fallback_script', ''),
            'last_resort_else': p.get('last_resort_else', ''),
            'distance_mi':      int(p.get('distance_mi', 0)),
        }
    return new_partners


def load_settings_json():
    """
    Load data/settings.json relative to this script's parent directory.
    Returns the parsed dict or None if file doesn't exist.
    """
    settings_path = os.path.join(
        os.path.dirname(os.path.abspath(__file__)),
        '..', 'data', 'settings.json'
    )
    settings_path = os.path.normpath(settings_path)
    if not os.path.exists(settings_path):
        return None
    try:
        with open(settings_path, 'r') as f:
            return json.load(f)
    except Exception as e:
        print(f"[settings] Warning: could not read settings.json: {e}", file=sys.stderr)
        return None


def apply_settings_json():
    """
    Load partners.json (primary), then overlay with settings.json (secondary).
    Called once at startup in main().
    """
    global CONFIG, PARTNERS

    # Priority 1 — partners.json (hand-editable, shared by both scripts)
    pdata = load_partners_json()
    if pdata and pdata.get('partners'):
        new_partners = build_partners_from_list(pdata['partners'])
        if new_partners:
            PARTNERS = new_partners
            print(f"[partners] Active: {', '.join(PARTNERS.keys())}")
        home = pdata.get('home', {})
        if home.get('lat'): CONFIG['home_lat'] = float(home['lat'])
        if home.get('lon'): CONFIG['home_lon'] = float(home['lon'])

    # Priority 2 — settings.json (Dashboard UI, overrides partners.json if present)
    s = load_settings_json()
    if s is None:
        return  # nothing to do

    print("[settings] Loaded settings.json — overriding CONFIG / PARTNERS")

    # --- Station / BBS config ---
    if 'bbs' in s:
        b = s['bbs']
        if b.get('host'):    CONFIG['bbs_host'] = b['host']
        if b.get('port'):    CONFIG['bbs_port'] = int(b['port'])
        if b.get('user'):    CONFIG['bbs_user'] = b['user']
        if b.get('pass'):    CONFIG['bbs_pass'] = b['pass']

    if 'station' in s:
        st = s['station']
        if st.get('callsign'): CONFIG['home_call'] = st['callsign'].upper()
        if st.get('lat'):      CONFIG['home_lat']  = float(st['lat'])
        if st.get('lon'):      CONFIG['home_lon']  = float(st['lon'])
        if st.get('grid'):     CONFIG['home_grid'] = st['grid']

    # --- Paths ---
    if 'paths' in s:
        p = s['paths']
        if p.get('linmail_cfg'):   CONFIG['linmail_cfg']  = p['linmail_cfg']
        if p.get('log_dir'):       CONFIG['bbs_log_dir']  = p['log_dir']
        if p.get('bpq_stop_cmd'):  CONFIG['bpq_stop_cmd'] = p['bpq_stop_cmd']
        if p.get('bpq_start_cmd'): CONFIG['bpq_start_cmd']= p['bpq_start_cmd']
        if p.get('backup_dir'):    CONFIG['backup_dir']   = p['backup_dir']
        if p.get('log_dir'):
            CONFIG['log_file']   = os.path.join(p['log_dir'], 'prop-scheduler.log')
            CONFIG['vara_log']   = os.path.join(
                p['log_dir'],
                (CONFIG.get('home_call', 'k1ajd') + '.vara').lower()
            )

    # --- Prop scheduler weights / options ---
    if 'prop_scheduler' in s:
        ps = s['prop_scheduler']
        if 'interval_hours'  in ps: CONFIG['run_interval_hours']  = int(ps['interval_hours'])
        if 'lookback_days'   in ps: CONFIG['lookback_days']        = int(ps['lookback_days'])
        if 'min_sessions'    in ps: CONFIG['min_sessions']         = int(ps['min_sessions'])
        if 'conserve_mode'   in ps: CONFIG['conserve_mode']        = bool(ps['conserve_mode'])
        if 'conserve_threshold' in ps:
            CONFIG['conserve_threshold'] = float(ps['conserve_threshold'])

    # --- Partners ---
    # settings.json partners override partners.json when present
    if 'partners' in s and isinstance(s['partners'], list) and s['partners']:
        new_partners = build_partners_from_list(s['partners'])
        if new_partners:
            PARTNERS = new_partners
            print(f"[settings] {len(PARTNERS)} partner(s) from settings.json: "
                  + ', '.join(PARTNERS.keys()))


# ============================================================================
# NVIS BAND MODEL
# ============================================================================
# Band availability windows based on NVIS propagation for SE United States.
# These are baseline models adjusted by solar flux and geomagnetic conditions.
#
# foF2 (critical frequency for F2 layer reflection):
#   - Must be > operating frequency for NVIS to work
#   - 80m (3.5 MHz): Works when foF2 > 3.5 (most of the time except deep night)
#   - 40m (7 MHz): Works when foF2 > 7 (daytime, higher solar flux)
#   - 30m (10 MHz): Works when foF2 > 10 (midday, good solar conditions)
#   - 20m (14 MHz): Rarely NVIS, mostly skip — needs foF2 > 14 or skip propagation

# Hours are UTC. Scores 0-100 for each band at each hour.
# These are BASELINE scores adjusted by solar conditions at runtime.

NVIS_BASELINE = {
    # UTC hour: {band: base_score}
    #
    # CORRECTED 2026-03-19 based on D-layer absorption research.
    # Augusta GA (33N) UTC offsets: EDT=UTC-4 (Mar-Nov), EST=UTC-5 (Nov-Mar)
    #
    # KEY FINDING: D-layer absorbs 80m from ~30min after local sunrise
    # until ~45min after local sunset. 80m should NOT be primary during
    # daylight hours. 40m is the daytime workhorse. 20m is viable midday
    # for partners >400mi (skip distance favourable).
    #
    # 20m note: viable for KD4WLE(518mi), N4SFL(498mi), N3MEL(571mi),
    # N9SEO(620mi), KK4DIV(309mi marginal). NOT viable for N4VAD(87mi)
    # — too close, skip zone. The distance/SFI adjustments in
    # calculate_band_scores() further tune these scores per partner.

    # Winter model (Nov-Feb): sunrise ~12:30z, sunset ~22:30z (EST UTC-5)
    # D-layer active: ~13:00z-23:00z  |  80m night window: 23:00z-12:00z
    'winter': {
        0:  {'80m': 90, '40m': 28, '30m': 5,  '20m': 0},
        1:  {'80m': 90, '40m': 28, '30m': 5,  '20m': 0},
        2:  {'80m': 90, '40m': 28, '30m': 5,  '20m': 0},
        3:  {'80m': 90, '40m': 28, '30m': 5,  '20m': 0},
        4:  {'80m': 90, '40m': 28, '30m': 5,  '20m': 0},
        5:  {'80m': 85, '40m': 30, '30m': 5,  '20m': 0},
        6:  {'80m': 85, '40m': 30, '30m': 5,  '20m': 0},
        7:  {'80m': 85, '40m': 30, '30m': 5,  '20m': 0},
        8:  {'80m': 85, '40m': 30, '30m': 5,  '20m': 0},
        9:  {'80m': 85, '40m': 30, '30m': 5,  '20m': 0},
        10: {'80m': 85, '40m': 30, '30m': 5,  '20m': 0},
        11: {'80m': 85, '40m': 30, '30m': 5,  '20m': 0},
        12: {'80m': 70, '40m': 42, '30m': 12, '20m': 3},   # Pre-dawn transition
        13: {'80m': 25, '40m': 65, '30m': 35, '20m': 15},  # Sunrise — 80m collapses
        14: {'80m': 10, '40m': 80, '30m': 52, '20m': 42},  # Midday
        15: {'80m': 10, '40m': 80, '30m': 52, '20m': 42},
        16: {'80m': 10, '40m': 80, '30m': 52, '20m': 42},
        17: {'80m': 12, '40m': 75, '30m': 45, '20m': 32},  # Afternoon
        18: {'80m': 12, '40m': 75, '30m': 45, '20m': 32},
        19: {'80m': 15, '40m': 68, '30m': 35, '20m': 20},
        20: {'80m': 18, '40m': 62, '30m': 28, '20m': 10},  # Late afternoon
        21: {'80m': 35, '40m': 55, '30m': 18, '20m': 5},   # Dusk transition
        22: {'80m': 72, '40m': 40, '30m': 10, '20m': 0},   # Post-sunset 80m returns
        23: {'80m': 85, '40m': 32, '30m': 8,  '20m': 0},
    },
    # Summer model (May-Aug): sunrise ~10:15z, sunset ~00:45z (EDT UTC-4)
    # D-layer active: ~10:00z-01:00z  |  80m night window: 01:00z-09:00z
    'summer': {
        0:  {'80m': 85, '40m': 30, '30m': 8,  '20m': 0},   # Post-midnight, D-layer still active
        1:  {'80m': 85, '40m': 30, '30m': 8,  '20m': 0},   # D-layer collapses ~01z
        2:  {'80m': 90, '40m': 25, '30m': 5,  '20m': 0},
        3:  {'80m': 90, '40m': 25, '30m': 5,  '20m': 0},
        4:  {'80m': 90, '40m': 25, '30m': 5,  '20m': 0},
        5:  {'80m': 90, '40m': 25, '30m': 5,  '20m': 0},
        6:  {'80m': 90, '40m': 25, '30m': 5,  '20m': 0},
        7:  {'80m': 90, '40m': 25, '30m': 5,  '20m': 0},
        8:  {'80m': 90, '40m': 25, '30m': 5,  '20m': 0},
        9:  {'80m': 75, '40m': 38, '30m': 10, '20m': 2},   # Pre-dawn transition
        10: {'80m': 30, '40m': 58, '30m': 22, '20m': 8},   # Sunrise — 80m collapses
        11: {'80m': 10, '40m': 78, '30m': 48, '20m': 38},  # Morning
        12: {'80m': 10, '40m': 78, '30m': 48, '20m': 38},
        13: {'80m': 8,  '40m': 82, '30m': 60, '20m': 58},  # Midday peak
        14: {'80m': 8,  '40m': 82, '30m': 60, '20m': 58},
        15: {'80m': 8,  '40m': 82, '30m': 60, '20m': 58},
        16: {'80m': 8,  '40m': 82, '30m': 60, '20m': 58},
        17: {'80m': 8,  '40m': 82, '30m': 60, '20m': 58},
        18: {'80m': 8,  '40m': 80, '30m': 52, '20m': 48},  # Afternoon
        19: {'80m': 8,  '40m': 80, '30m': 52, '20m': 48},
        20: {'80m': 8,  '40m': 80, '30m': 52, '20m': 48},
        21: {'80m': 10, '40m': 75, '30m': 40, '20m': 30},  # Evening
        22: {'80m': 12, '40m': 68, '30m': 28, '20m': 15},
        23: {'80m': 25, '40m': 55, '30m': 15, '20m': 5},   # Dusk — sun sets late
    },
    # Equinox model (Mar-Apr, Sep-Oct): sunrise ~11:30z, sunset ~23:30z (EDT UTC-4)
    # D-layer active: ~12:00z-00:00z  |  80m night window: 00:00z-11:00z
    'equinox': {
        0:  {'80m': 90, '40m': 30, '30m': 8,  '20m': 0},
        1:  {'80m': 90, '40m': 30, '30m': 8,  '20m': 0},
        2:  {'80m': 90, '40m': 30, '30m': 8,  '20m': 0},
        3:  {'80m': 90, '40m': 30, '30m': 8,  '20m': 0},
        4:  {'80m': 90, '40m': 30, '30m': 8,  '20m': 0},
        5:  {'80m': 88, '40m': 25, '30m': 5,  '20m': 0},
        6:  {'80m': 88, '40m': 25, '30m': 5,  '20m': 0},
        7:  {'80m': 88, '40m': 25, '30m': 5,  '20m': 0},
        8:  {'80m': 80, '40m': 35, '30m': 8,  '20m': 2},   # Approaching dawn
        9:  {'80m': 80, '40m': 35, '30m': 8,  '20m': 2},
        10: {'80m': 80, '40m': 35, '30m': 8,  '20m': 2},
        11: {'80m': 35, '40m': 55, '30m': 20, '20m': 5},   # Dawn transition
        12: {'80m': 10, '40m': 80, '30m': 50, '20m': 35},  # Sunrise — 80m collapses
        13: {'80m': 10, '40m': 80, '30m': 50, '20m': 35},
        14: {'80m': 8,  '40m': 82, '30m': 58, '20m': 55},  # Midday peak
        15: {'80m': 8,  '40m': 82, '30m': 58, '20m': 55},
        16: {'80m': 8,  '40m': 82, '30m': 58, '20m': 55},
        17: {'80m': 8,  '40m': 82, '30m': 58, '20m': 55},
        18: {'80m': 10, '40m': 78, '30m': 48, '20m': 38},  # Afternoon
        19: {'80m': 10, '40m': 78, '30m': 48, '20m': 38},
        20: {'80m': 10, '40m': 78, '30m': 48, '20m': 38},
        21: {'80m': 12, '40m': 72, '30m': 38, '20m': 22},  # Late afternoon
        22: {'80m': 15, '40m': 62, '30m': 25, '20m': 10},
        23: {'80m': 45, '40m': 50, '30m': 15, '20m': 5},   # Dusk transition
    },
}

# ============================================================================
# LOGGING
# ============================================================================

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [PROP] %(levelname)s %(message)s',
    handlers=[
        logging.FileHandler(CONFIG['log_file']),
        logging.StreamHandler(sys.stdout),
    ]
)
log = logging.getLogger('prop-scheduler')

# ============================================================================
# PROPAGATION DATA FETCHERS
# ============================================================================

def fetch_json(url, timeout=15):
    """Fetch JSON data from a URL."""
    try:
        req = Request(url, headers={'User-Agent': 'BPQ-PropScheduler/1.0'})
        with urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode())
    except (URLError, json.JSONDecodeError, Exception) as e:
        log.warning(f"Failed to fetch {url}: {e}")
        return None


def fetch_solar_conditions():
    """Fetch current solar flux index and geomagnetic conditions from NOAA."""
    conditions = {
        'sfi': 150,      # Default: moderate solar flux
        'kp': 2,         # Default: quiet geomagnetic
        'source': 'defaults',
        'timestamp': datetime.now(timezone.utc).isoformat(),
    }

    # Solar Flux Index (most recent)
    sfi_data = fetch_json(CONFIG['noaa_sfi_url'])
    if sfi_data and len(sfi_data) > 0:
        # Data is sorted oldest first; get last entry
        latest = sfi_data[-1]
        try:
            conditions['sfi'] = float(latest.get('f10.7', 150))
            conditions['source'] = 'NOAA SWPC'
            log.info(f"Solar Flux Index: {conditions['sfi']}")
        except (ValueError, TypeError):
            log.warning("Could not parse SFI value")

    # Planetary K-index (most recent)
    # NOAA changed format: old = array-of-arrays with header row
    #   [["time_tag","Kp",...], ["2026-01-01 00:00:00","2.33",...]]
    # New = array-of-objects
    #   [{"time_tag":"2026-04-09T00:00:00","Kp":2.33,"a_running":9}]
    kp_data = fetch_json(CONFIG['noaa_kp_url'])
    if kp_data and len(kp_data) > 0:
        try:
            latest_kp = kp_data[-1]
            # Detect format: new API returns objects, old returned arrays
            if isinstance(latest_kp, dict):
                conditions['kp'] = float(latest_kp.get('Kp', latest_kp.get('kp', 2)))
            elif isinstance(latest_kp, list) and len(latest_kp) > 1:
                conditions['kp'] = float(latest_kp[1])
            log.info(f"Kp index: {conditions['kp']}")
        except (ValueError, TypeError, KeyError, IndexError):
            log.warning("Could not parse Kp value — using default")

    return conditions


def get_season():
    """Determine current season for band model selection."""
    month = datetime.now(timezone.utc).month
    if month in (11, 12, 1, 2):
        return 'winter'
    elif month in (5, 6, 7, 8):
        return 'summer'
    else:
        return 'equinox'


def get_distance(lat1, lon1, lat2, lon2):
    """Haversine distance in miles."""
    R = 3959
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = math.sin(dlat / 2) ** 2 + math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) * math.sin(dlon / 2) ** 2
    return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


# ============================================================================
# BBS LOG ANALYZER
# ============================================================================

def parse_bbs_logs(partner_call, days=14):
    """Query MariaDB sessions table for connection statistics per band.

    Replaces the original BBS log file parser which was written for a
    different log format. ARSSYSTEM BPQ logs do not include RADIO frequency
    commands or byte counts in the Disconnected line — all that data is
    captured in MariaDB via tprfn_insert_session(). This function queries
    the sessions table directly for accurate historical band statistics.

    Returns dict: {band: {sessions, successes, avg_snr, avg_bps, total_bytes,
                          success_rate}}
    """
    import subprocess, json

    # Map known partner frequencies to bands
    band_freq_map = {}
    for pname, pdata in PARTNERS.items():
        if pname.upper() == partner_call.upper() or            pdata.get('connect_call','').upper() == partner_call.upper():
            for band, bdata in pdata.get('bands', {}).items():
                freq = float(bdata.get('freq', 0))
                if freq > 0:
                    band_freq_map[band] = freq

    # Build station pattern — match both bare callsign and SSID variants
    # e.g. N3MEL matches N3MEL-2, N3MEL-7, N3MEL etc.
    base_call = partner_call.split('-')[0].upper()

    cutoff_date = (datetime.now(timezone.utc) - timedelta(days=days)).strftime('%Y-%m-%d')

    # Query sessions table — match on hub=K1AJD-7 and station starts with partner base call
    sql = f"""
SELECT
    station,
    AVG(avg_snr)              AS avg_snr,
    AVG(max_bps)              AS avg_bps,
    SUM(bytes_tx + bytes_rx)  AS total_bytes,
    COUNT(*)                  AS sessions,
    SUM(CASE WHEN (bytes_tx + bytes_rx) > 0 THEN 1 ELSE 0 END) AS successes
FROM sessions
WHERE session_date >= '{cutoff_date}'
  AND (
    station LIKE '{base_call}%'
    OR hub LIKE '{base_call}%'
  )
GROUP BY station;
"""

    try:
        # Use tprfn_app user (read credentials from tprfn-db.php or config)
        db_user = CONFIG.get('db_user', 'tprfn_app')
        db_pass = CONFIG.get('db_pass', 'TprfnDb2026!')
        db_name = CONFIG.get('db_name', 'tprfn')
        cmd = ['mysql', f'-u{db_user}', f'-p{db_pass}', db_name,
               '--batch', '--skip-column-names', '-e', sql]
        result = subprocess.run(
            cmd, capture_output=True, text=True, timeout=10
        )
        if result.returncode != 0:
            log.warning(f"  DB query failed for {partner_call}: {result.stderr.strip()}")
            return {}
    except Exception as e:
        log.warning(f"  DB query exception for {partner_call}: {e}")
        return {}

    # Parse results — aggregate across all station variants
    total_sessions  = 0
    total_successes = 0
    total_bytes     = 0
    snr_values      = []
    bps_values      = []

    for line in result.stdout.strip().split('\n'):
        if not line.strip():
            continue
        parts = line.split('\t')
        if len(parts) < 6:
            continue
        try:
            avg_snr    = float(parts[1]) if parts[1] != 'NULL' else None
            avg_bps    = float(parts[2]) if parts[2] != 'NULL' else None
            tot_bytes  = int(float(parts[3])) if parts[3] != 'NULL' else 0
            sessions   = int(parts[4])
            successes  = int(parts[5])

            total_sessions  += sessions
            total_successes += successes
            total_bytes     += tot_bytes
            if avg_snr is not None:
                snr_values.append(avg_snr)
            if avg_bps is not None:
                bps_values.append(avg_bps)
        except (ValueError, IndexError):
            continue

    if total_sessions == 0:
        log.info(f"  No sessions found in DB for {partner_call} (base: {base_call}) in last {days} days")
        return {}

    # Since BPQ logs don't record per-session frequency, distribute stats
    # across available bands proportionally using the partner bands config.
    # If only one band is configured use it directly.
    # If multiple bands, weight by time-of-day typical usage
    # (simplified: equal weight across configured bands).
    band_stats = {}

    if not band_freq_map:
        # No band config found — return under generic key so scheduler
        # still gets some signal rather than nothing
        band_stats['40m'] = {
            'sessions':     total_sessions,
            'successes':    total_successes,
            'total_bytes':  total_bytes,
            'avg_snr':      sum(snr_values)/len(snr_values) if snr_values else None,
            'avg_bps':      sum(bps_values)/len(bps_values) if bps_values else None,
            'success_rate': total_successes / total_sessions if total_sessions > 0 else 0,
        }
    else:
        # Distribute evenly across configured bands
        n_bands = len(band_freq_map)
        per_band_sessions  = max(1, total_sessions  // n_bands)
        per_band_successes = max(0, total_successes // n_bands)
        per_band_bytes     = total_bytes // n_bands
        avg_snr  = sum(snr_values)/len(snr_values)   if snr_values else None
        avg_bps  = sum(bps_values)/len(bps_values)   if bps_values else None

        for band in band_freq_map:
            band_stats[band] = {
                'sessions':     per_band_sessions,
                'successes':    per_band_successes,
                'total_bytes':  per_band_bytes,
                'avg_snr':      avg_snr,
                'avg_bps':      avg_bps,
                'success_rate': per_band_successes / per_band_sessions if per_band_sessions > 0 else 0,
            }

    log.info(f"  DB historical: {total_sessions} sessions, "
             f"{total_successes} successes, "
             f"avg SNR {sum(snr_values)/len(snr_values):.1f}dB" if snr_values else
             f"  DB historical: {total_sessions} sessions, {total_successes} successes, no SNR data")

    return band_stats


def freq_to_band(freq_mhz):
    """Convert frequency in MHz to band name."""
    if freq_mhz is None:
        return 'Unknown'
    if 3.5 <= freq_mhz <= 4.0:
        return '80m'
    elif 7.0 <= freq_mhz <= 7.3:
        return '40m'
    elif 10.1 <= freq_mhz <= 10.15:
        return '30m'
    elif 14.0 <= freq_mhz <= 14.35:
        return '20m'
    elif 50.0 <= freq_mhz <= 54.0:
        return '6m'
    elif 144.0 <= freq_mhz <= 148.0:
        return '2m'
    return 'Unknown'


# ============================================================================
# SCHEDULE OPTIMIZER
# ============================================================================

def calculate_band_scores(partner, solar, historical_stats):
    """Calculate per-hour band scores combining propagation model + historical data.
    
    Returns: {hour: {band: score}} for hours 0-23 UTC
    """
    season = get_season()
    baseline = NVIS_BASELINE[season]
    distance = get_distance(
        CONFIG['home_lat'], CONFIG['home_lon'],
        partner['lat'], partner['lon']
    )
    available_bands = set(partner['bands'].keys())

    # Solar flux adjustments
    sfi = solar['sfi']
    kp = solar['kp']

    # SFI adjustments: higher SFI benefits higher bands
    sfi_boost = {
        '80m': 0,
        '40m': max(-10, min(10, (sfi - 120) / 10)),       # ±10 based on SFI
        '30m': max(-15, min(15, (sfi - 130) / 8)),         # Needs higher SFI
        '20m': max(-20, min(20, (sfi - 140) / 6)),         # Needs even higher SFI
    }

    # Kp adjustments: high Kp degrades all bands, especially higher ones
    # VHF bands (6m, 2m) are not affected by geomagnetic storms
    kp_penalty = {
        '80m': min(0, -kp * 2),
        '40m': min(0, -kp * 4),
        '30m': min(0, -kp * 6),
        '20m': min(0, -kp * 8),
    }

    # Distance adjustments: longer paths need higher bands during day
    # 6m: sporadic-E works 300-1200mi; useless for short NVIS paths
    # 2m: line-of-sight only (~50mi typical); 309mi is too far without ducting
    if distance > 500:
        dist_adj = {'80m': -15, '40m': 5, '30m': 10, '20m': 15}
    elif distance > 300:
        dist_adj = {'80m': -5, '40m': 5, '30m': 5, '20m': 5}
    elif distance > 100:
        dist_adj = {'80m': 5, '40m': 0, '30m': -5, '20m': -10}
    else:
        dist_adj = {'80m': 5, '40m': 0, '30m': -5, '20m': -10}

    hourly_scores = {}

    for hour in range(24):
        hourly_scores[hour] = {}
        for band in available_bands:
            # Propagation model score
            # 6m: low base score, summer sporadic-E peak 1400-2200 UTC
            # 2m: very low base score (line-of-sight only at most distances)
            prop_score = baseline[hour].get(band, 0)
            prop_score += sfi_boost.get(band, 0)
            prop_score += kp_penalty.get(band, 0)
            prop_score += dist_adj.get(band, 0)
            prop_score = max(0, min(100, prop_score))

            # Historical performance score
            hist_score = 50  # Default neutral
            if band in historical_stats:
                stats = historical_stats[band]
                if stats['sessions'] >= CONFIG['min_sessions']:
                    # Success rate: 0-100
                    sr = stats['success_rate'] * 100
                    # S/N bonus: map -15..+15 to 0..100
                    snr_score = 50
                    if stats['avg_snr'] is not None:
                        snr_score = max(0, min(100, (stats['avg_snr'] + 15) * (100 / 30)))
                    hist_score = sr * CONFIG['success_weight'] / (CONFIG['success_weight'] + CONFIG['sn_weight']) + \
                                snr_score * CONFIG['sn_weight'] / (CONFIG['success_weight'] + CONFIG['sn_weight'])

            # Combined weighted score
            combined = (prop_score * CONFIG['prop_weight'] +
                       hist_score * (1 - CONFIG['prop_weight']))

            hourly_scores[hour][band] = round(combined, 1)

    return hourly_scores


def build_time_blocks(hourly_scores, partner):
    """Convert per-hour band scores into BPQ time blocks.
    
    Groups consecutive hours that use the same best band into time blocks.
    Returns: [(start_hour, end_hour, primary_band, fallback_band), ...]
    """
    available_bands = set(partner['bands'].keys())

    # Find best band per hour
    best_per_hour = {}
    for hour in range(24):
        scores = hourly_scores[hour]
        ranked = sorted(scores.items(), key=lambda x: x[1], reverse=True)
        best_per_hour[hour] = ranked[0][0] if ranked else '40m'

    # Group consecutive hours with same band
    blocks = []
    current_band = best_per_hour[0]
    block_start = 0

    for hour in range(1, 24):
        if best_per_hour[hour] != current_band:
            # Find fallback (second best band for this block)
            mid_hour = (block_start + hour - 1) // 2
            scores = hourly_scores[mid_hour]
            ranked = sorted(scores.items(), key=lambda x: x[1], reverse=True)
            fallback = ranked[1][0] if len(ranked) > 1 else current_band

            blocks.append((block_start, hour - 1, current_band, fallback))
            current_band = best_per_hour[hour]
            block_start = hour

    # Close final block
    mid_hour = (block_start + 23) // 2
    scores = hourly_scores[mid_hour]
    ranked = sorted(scores.items(), key=lambda x: x[1], reverse=True)
    fallback = ranked[1][0] if len(ranked) > 1 else current_band
    blocks.append((block_start, 23, current_band, fallback))

    return blocks


def build_connect_script(blocks, partner):
    """Build a BPQ ConnectScript string from time blocks.

    If partner has 'last_resort_else' defined, it is appended as a final
    ELSE after every time block's RF attempts — so the fallback fires
    regardless of which time window is active when all RF bands fail.
    """
    parts = []
    attach = f"ATTACH {partner['attach_port']}"
    call = partner['connect_call']
    last_resort = partner.get('last_resort_else', '')

    for start, end, primary, fallback in blocks:
        start_str = f"{start:02d}00"
        end_str = f"{end:02d}59"
        time_range = f"TIMES {start_str}-{end_str}"

        pri_info = partner['bands'][primary]
        pri_radio = f"RADIO {pri_info['freq']}"
        if pri_info.get('mode'):
            pri_radio += f" {pri_info['mode']}"

        parts.append(f"{time_range}|{attach}|{pri_radio}|PAUSE|C {call}")

        # Add ELSE fallback band if different band available
        if fallback != primary and fallback in partner['bands']:
            fb_info = partner['bands'][fallback]
            fb_radio = f"RADIO {fb_info['freq']}"
            if fb_info.get('mode'):
                fb_radio += f" {fb_info['mode']}"
            parts.append(f"ELSE|{attach}|{fb_radio}|PAUSE|C {call}")

        # Append last-resort ELSE at end of every time block
        if last_resort:
            parts.append(f"ELSE|{last_resort}")

    return '|'.join(parts)


# ============================================================================
# LINMAIL.CFG PARSER / WRITER
# ============================================================================

def read_linmail_cfg(filepath):
    """Read linmail.cfg and return raw content."""
    with open(filepath, 'r', errors='replace') as f:
        return f.read()


def update_connect_script(cfg_content, partner_call, new_script):
    """Replace ConnectScript for a specific partner in linmail.cfg content.
    
    Returns: (new_content, old_script) or (None, None) if partner not found.
    """
    # Find the partner's section and its ConnectScript line
    pattern = re.compile(
        rf'({re.escape(partner_call)}\s*:\s*\{{[^}}]*?ConnectScript\s*=\s*")([^"]*?)(")',
        re.DOTALL
    )

    match = pattern.search(cfg_content)
    if not match:
        log.warning(f"Partner {partner_call} not found in linmail.cfg")
        return None, None

    old_script = match.group(2)
    new_content = cfg_content[:match.start(2)] + new_script + cfg_content[match.end(2):]

    return new_content, old_script


# ============================================================================
# REPORTING
# ============================================================================

def build_report(changes, solar, season):
    """Build a human-readable report of changes made."""
    lines = [
        "=" * 60,
        "BPQ Propagation-Based Forwarding Schedule Update",
        f"Generated: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}",
        "=" * 60,
        "",
        "SOLAR CONDITIONS:",
        f"  Solar Flux Index (SFI): {solar['sfi']}",
        f"  Planetary K-index:      {solar['kp']}",
        f"  Season model:           {season}",
        f"  Data source:            {solar['source']}",
        "",
    ]

    if not changes:
        lines.append("NO CHANGES — All schedules are optimal for current conditions.")
        lines.append("")
        return '\n'.join(lines)

    lines.append(f"SCHEDULE CHANGES ({len(changes)} partners updated):")
    lines.append("")

    for change in changes:
        lines.append(f"  {change['call']} — {change['name']} ({change['location']})")
        lines.append(f"  Distance: {change['distance']:.0f} mi")
        lines.append(f"  Historical data: {change['historical_summary']}")
        lines.append(f"  OLD: {change['old_script'][:80]}...")
        lines.append(f"  NEW: {change['new_script'][:80]}...")
        lines.append(f"  Time blocks:")
        for start, end, primary, fallback in change['blocks']:
            if isinstance(start, int):
                fb_str = f" (fallback: {fallback})" if fallback != primary else ""
                lines.append(f"    {start:02d}00-{end:02d}59 UTC → {primary}{fb_str}")
            else:
                lines.append(f"    {primary}")
        lines.append("")

    lines.append("=" * 60)
    lines.append("End of report")
    return '\n'.join(lines)


def send_bbs_report(report_text, changes_count):
    """Send the report as a personal message via BBS telnet."""
    if not CONFIG.get('notify_via_bbs'):
        return

    try:
        to_call = CONFIG['bbs_notify_to']
        subject = f"Prop Schedule Update - {changes_count} changes - {datetime.now(timezone.utc).strftime('%Y-%m-%d')}"

        # Truncate report if too long for BBS message (keep under 5KB)
        if len(report_text) > 4500:
            report_text = report_text[:4500] + '\n\n[Report truncated]'

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

        # Login sequence
        read_until('user:', 10)
        send(CONFIG['bbs_user'])
        read_until('password:', 10)
        send(CONFIG['bbs_pass'])
        read_until('}', 20)
        send(CONFIG['bbs_alias'])
        read_until('>', 15)

        # Send personal message
        send(f'SP {to_call}')
        read_until(':', 10)  # "Enter Title (only):"
        send(subject)

        # Wait for body prompt — ends with ")" not ":" so just pause
        time.sleep(1.5)
        try:
            sock.setblocking(False)
            sock.recv(4096)  # Drain body prompt
        except (BlockingIOError, socket.error):
            pass
        sock.setblocking(True)
        sock.settimeout(10)

        # Send report body line by line
        for line in report_text.split('\n'):
            line = line.rstrip()
            # Escape /ex if it appears in the report
            if line.strip().lower() == '/ex':
                line = ' /ex'
            sock.sendall((line + '\r\n').encode())
            time.sleep(0.03)

        # End message
        time.sleep(0.5)
        sock.sendall(b'/ex\r\n')

        # Wait for confirmation
        response = read_until('>', 15)
        log.info(f"BBS response: {response[:100].strip()}")

        # Disconnect
        send('B')
        time.sleep(0.5)
        sock.close()

        log.info(f"BBS notification sent to {to_call}")

    except Exception as e:
        log.error(f"Failed to send BBS notification: {e}")


# ============================================================================
# MAIN
# ============================================================================

def main():
    parser = argparse.ArgumentParser(description='BPQ Propagation-Based Forwarding Scheduler')
    parser.add_argument('--apply', action='store_true', help='Apply changes to linmail.cfg')
    parser.add_argument('--force', action='store_true', help='Apply even if changes are minimal')
    parser.add_argument('--partner', type=str, help='Only process specific partner callsign')
    parser.add_argument('--dry-run', action='store_true', help='Show what would change (default)')
    args = parser.parse_args()

    # Load settings.json overrides (if Dashboard Settings UI has been configured)
    apply_settings_json()

    log.info("=" * 40)
    log.info("Propagation Scheduler starting")
    log.info("=" * 40)

    # Step 1: Fetch solar conditions
    log.info("Fetching solar conditions...")
    solar = fetch_solar_conditions()
    season = get_season()
    log.info(f"Season: {season}, SFI: {solar['sfi']}, Kp: {solar['kp']}")

    # Step 2: Read current config
    cfg_path = CONFIG['linmail_cfg']
    if not os.path.exists(cfg_path):
        log.error(f"linmail.cfg not found at {cfg_path}")
        sys.exit(1)

    cfg_content = read_linmail_cfg(cfg_path)
    original_content = cfg_content

    # Step 3: Process each partner
    changes = []
    partners_to_process = {args.partner.upper(): PARTNERS[args.partner.upper()]} if args.partner else PARTNERS

    for call, partner in partners_to_process.items():
        log.info(f"Processing {call} ({partner['location']})...")

        # Skip partners with fixed published schedules (e.g., scanning stations)
        if partner.get('fixed_schedule'):
            # Still update to preferred frequencies but keep their time windows
            new_script = partner['fallback_script']
            log.info(f"  Fixed schedule station — using published time windows")
            log.info(f"  Script: {new_script[:80]}...")

            cfg_content, old_script = update_connect_script(cfg_content, call, new_script)
            if cfg_content is None:
                log.warning(f"  Skipped — partner not found in config")
                continue
            if old_script == new_script:
                log.info(f"  No change needed")
                continue

            distance = get_distance(
                CONFIG['home_lat'], CONFIG['home_lon'],
                partner['lat'], partner['lon']
            )
            changes.append({
                'call': call,
                'name': partner['name'],
                'location': partner['location'],
                'distance': distance,
                'old_script': old_script,
                'new_script': new_script,
                'blocks': [('Fixed', '', 'Published schedule', '')],
                'historical_summary': 'Fixed schedule — not optimized',
            })
            continue

        # Analyze historical BBS logs
        hist_stats = parse_bbs_logs(call, CONFIG['lookback_days'])
        hist_summary = ', '.join(
            f"{b}: {s['sessions']}sess/{s['success_rate']:.0%}ok"
            for b, s in hist_stats.items()
        ) if hist_stats else 'No historical data'
        log.info(f"  Historical: {hist_summary}")

        # Calculate band scores
        hourly_scores = calculate_band_scores(partner, solar, hist_stats)

        # Build optimized time blocks
        blocks = build_time_blocks(hourly_scores, partner)

        # Build new connect script
        new_script = build_connect_script(blocks, partner)
        log.info(f"  New script: {new_script[:80]}...")

        # Update config content
        cfg_content, old_script = update_connect_script(cfg_content, call, new_script)

        if cfg_content is None:
            log.warning(f"  Skipped — partner not found in config")
            continue

        if old_script == new_script:
            log.info(f"  No change needed")
            continue

        distance = get_distance(
            CONFIG['home_lat'], CONFIG['home_lon'],
            partner['lat'], partner['lon']
        )

        changes.append({
            'call': call,
            'name': partner['name'],
            'location': partner['location'],
            'distance': distance,
            'old_script': old_script,
            'new_script': new_script,
            'blocks': blocks,
            'historical_summary': hist_summary,
        })

    # Step 4: Generate report
    report = build_report(changes, solar, season)
    print(report)

    # Step 5: Apply changes if requested
    if args.apply and (changes or args.force):
        # Stop BPQ first — BPQ saves its in-memory config on shutdown,
        # so we must let it save, THEN overwrite with our changes, THEN start.
        if CONFIG['bpq_stop_cmd']:
            try:
                subprocess.run(CONFIG['bpq_stop_cmd'], shell=True, check=True, timeout=30)
                log.info("BPQ stopped")
                time.sleep(2)  # Give BPQ time to save and exit
            except subprocess.SubprocessError as e:
                log.error(f"Failed to stop BPQ: {e}")

        # Backup the config BPQ just saved (may differ from our earlier read)
        backup_dir = CONFIG['backup_dir']
        os.makedirs(backup_dir, exist_ok=True)
        backup_name = f"linmail_{datetime.now().strftime('%Y%m%d_%H%M%S')}.cfg.bak"
        backup_path = os.path.join(backup_dir, backup_name)

        # Re-read config after BPQ saved it, re-apply our changes
        cfg_content = read_linmail_cfg(cfg_path)
        for change in changes:
            cfg_content, _ = update_connect_script(cfg_content, change['call'], change['new_script'])
            if cfg_content is None:
                log.error(f"Failed to update {change['call']} in re-read config")
                cfg_content = read_linmail_cfg(cfg_path)  # Reset and skip this partner

        with open(backup_path, 'w') as f:
            f.write(original_content)
        log.info(f"Backup saved: {backup_path}")

        # Write updated config
        with open(cfg_path, 'w') as f:
            f.write(cfg_content)
        log.info(f"Updated {cfg_path}")

        # Start BPQ with new config
        if CONFIG['bpq_start_cmd']:
            try:
                subprocess.run(CONFIG['bpq_start_cmd'], shell=True, check=True, timeout=30)
                log.info("BPQ started with new config")
                time.sleep(10)  # Wait for BPQ telnet port to initialize
            except subprocess.SubprocessError as e:
                log.error(f"Failed to start BPQ: {e}")

        # Send report via BBS (after BPQ is back up)
        send_bbs_report(report, len(changes))

        # Save state
        state = {
            'last_run': datetime.now(timezone.utc).isoformat(),
            'solar': solar,
            'season': season,
            'changes': len(changes),
            'partners': list(partners_to_process.keys()),
        }
        os.makedirs(os.path.dirname(CONFIG['state_file']), exist_ok=True)
        with open(CONFIG['state_file'], 'w') as f:
            json.dump(state, f, indent=2)

    elif not changes:
        log.info("No changes needed — all schedules optimal")
    else:
        log.info("Dry run — use --apply to write changes")

    log.info("Done")


if __name__ == '__main__':
    main()
