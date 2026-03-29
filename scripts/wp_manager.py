#!/usr/bin/env python3
"""
WP Manager — Winlink White Pages Interactive Scanner/Cleaner
============================================================
Safely scans WP.cfg for bogus entries, lets you flag which ones
to remove, and surgically removes only those — preserving all
original R numbers so Winlink/BPQ32 internal references stay intact.

WORKFLOW (manual):
  Step 1 — Scan:    python3 wp_manager.py --scan
  Step 2 — Review:  edit wp_review.txt, change ? to REMOVE or KEEP
  Step 3 — Apply:   python3 wp_manager.py --apply
  Step 4 — Watch:   python3 wp_manager.py --diff  (after next WP sync)

WORKFLOW (automated — for cron):
  python3 wp_manager.py --auto-clean
    Removes INVALID FORMAT / BBS names / empty callsigns automatically.
    STALE and REVIEW entries are never removed without manual approval.
    Use --dry-run first to preview changes before scheduling.

CRON EXAMPLE (run daily at 03:00 UTC):
  0 3 * * * cd /home/tony/linbpq && python3 /var/www/tprfn/scripts/wp_manager.py --auto-clean >> /var/log/wp-auto-clean.log 2>&1

FILES CREATED:
  wp_review.txt     — editable review list (you mark KEEP/REMOVE)
  wp_whitelist.txt  — callsigns never to flag again
  wp_blacklist.txt  — callsigns always flagged even if CMS repopulates
  wp_baseline.txt   — snapshot of last clean state for --diff comparison
  wp_manager.log    — audit log of all actions taken

Author: K1AJD
"""

import re
import sys
import os
import shutil
import argparse
import json
from datetime import datetime, timezone
from collections import defaultdict

# ─────────────────────────────────────────────────────────────
# CONFIG — edit these for your system
# ─────────────────────────────────────────────────────────────

DEFAULT_WP_PATH      = "/home/tony/linbpq/WP.cfg"
REVIEW_FILE          = os.path.join(os.path.dirname(os.path.abspath(__file__)), "wp_review.txt")
WHITELIST_FILE       = os.path.join(os.path.dirname(os.path.abspath(__file__)), "wp_whitelist.txt")
BLACKLIST_FILE       = os.path.join(os.path.dirname(os.path.abspath(__file__)), "wp_blacklist.txt")
BASELINE_FILE        = os.path.join(os.path.dirname(os.path.abspath(__file__)), "wp_baseline.json")
LOG_FILE             = os.path.join(os.path.dirname(os.path.abspath(__file__)), "wp_manager.log")
STALE_DAYS_DEFAULT   = 730

# ─────────────────────────────────────────────────────────────
# CALLSIGN VALIDATION
# ─────────────────────────────────────────────────────────────

VALID_CALLSIGN_RE = re.compile(
    r'^('
    r'[A-Z]{1,2}\d[A-Z]{1,4}'          # Standard 2-prefix
    r'|[AKNW][A-Z]\d[A-Z]{1,3}'        # US 3-prefix (AA-AL, KA-KZ, NA-NZ, WA-WZ)
    r'|[A-Z]\d[A-Z]{1,4}'              # Single letter prefix
    r'|[2-9][A-Z]{1,2}\d[A-Z]{1,4}'   # Digit-prefix ITU (2E0, 4G1, 5B4 etc)
    r')'
    r'(-\d{1,2})?$'
)

DIGIT_SUFFIX_RE = re.compile(r'\d(-\d+)?$')

BBS_RE = re.compile(
    r'BBS$|^Sally$|^HAMGAT|^WIRES|^ALLSTAR|^SVXLINK|^APRS',
    re.IGNORECASE
)

BAD_HIER_RE = re.compile(r'[@]')   # @ in hierarchy = malformed

def validate_callsign(call):
    """Returns list of fault strings. Empty = valid."""
    if not call:
        return ["EMPTY_CALLSIGN"]
    if BBS_RE.search(call):
        return ["BBS_SYSTEM_NAME"]
    if call != call.upper():
        return ["NOT_UPPERCASE"]
    base = call.split('-')[0]
    if not VALID_CALLSIGN_RE.match(call):
        return ["INVALID_FORMAT"]
    if DIGIT_SUFFIX_RE.search(base):
        return ["DIGIT_IN_SUFFIX"]
    return []


def check_record(rec, now_ts, stale_days, whitelist, blacklist):
    """
    Returns (category, [reasons])
    category: 'BLACKLIST' | 'AUTO' | 'STALE' | 'REVIEW' | 'OK'
    """
    call = rec.get('c', '')
    reasons = []

    # Whitelist overrides everything
    if call and call.upper() in whitelist:
        return 'OK', []

    # Blacklist — always flag
    if call and call.upper() in blacklist:
        reasons.append("IN_BLACKLIST")
        return 'BLACKLIST', reasons

    # Callsign format
    call_faults = validate_callsign(call)
    reasons.extend(call_faults)

    # Placeholder
    if rec.get('T', 0) == 0 and not call:
        reasons.append("PLACEHOLDER_RECORD")

    # Future timestamp
    m_ts = rec.get('m', 0)
    if m_ts > now_ts + 86400 * 30:
        future_days = int((m_ts - now_ts) / 86400)
        reasons.append(f"FUTURE_TIMESTAMP_{future_days}d")

    # Stale
    ls_ts = rec.get('ls', 0)
    age_days = int((now_ts - ls_ts) / 86400) if ls_ts > 0 else 0
    if ls_ts > 0 and age_days > stale_days:
        reasons.append(f"STALE_{age_days}d")

    # Hierarchy anomaly
    h = rec.get('h', '')
    if h and BAD_HIER_RE.search(h):
        reasons.append(f"HIERARCHY_ANOMALY")

    # Ghost record
    s  = rec.get('s', 0)
    n  = rec.get('n', '')
    q  = rec.get('q', '')
    z  = rec.get('z', '')
    if s == 0 and not n and not q and not z and not call_faults:
        reasons.append("GHOST_NO_INFO")

    # Determine category
    AUTO_TRIGGERS = {'EMPTY_CALLSIGN','PLACEHOLDER_RECORD','BBS_SYSTEM_NAME',
                     'NOT_UPPERCASE','INVALID_FORMAT','DIGIT_IN_SUFFIX'}

    if any(r in AUTO_TRIGGERS for r in reasons):
        return 'AUTO', reasons
    elif any(r.startswith('STALE') for r in reasons):
        return 'STALE', reasons
    elif reasons:
        return 'REVIEW', reasons
    return 'OK', []


# ─────────────────────────────────────────────────────────────
# PARSER
# ─────────────────────────────────────────────────────────────

def parse_wp(path):
    """Parse WP.cfg — returns list of record dicts with _id and _raw preserved."""
    with open(path, 'r', errors='replace') as f:
        content = f.read()

    blocks = re.findall(r'(R(\d+)\s*:\s*\{[^}]+\})', content, re.DOTALL)
    records = []
    for raw, num in blocks:
        rec = {'_id': int(num), '_raw': raw}
        for m in re.finditer(r'(\w+)\s*=\s*"([^"]*)"', raw):
            rec[m.group(1)] = m.group(2)
        for m in re.finditer(r'(\w+)\s*=\s*(\d+)L?;', raw):
            if m.group(1) not in rec:
                rec[m.group(1)] = int(m.group(2))
        records.append(rec)
    return records


# ─────────────────────────────────────────────────────────────
# WHITELIST / BLACKLIST
# ─────────────────────────────────────────────────────────────

def load_set(path):
    if not os.path.exists(path):
        return set()
    with open(path, 'r') as f:
        return {line.strip().upper() for line in f
                if line.strip() and not line.startswith('#')}


def save_set(path, items, header):
    with open(path, 'w') as f:
        f.write(header + '\n')
        for item in sorted(items):
            f.write(item + '\n')


# ─────────────────────────────────────────────────────────────
# BASELINE
# ─────────────────────────────────────────────────────────────

def save_baseline(records, path):
    data = {str(r['_id']): r.get('c','') for r in records}
    with open(path, 'w') as f:
        json.dump({'ts': datetime.now(timezone.utc).isoformat(),
                   'records': data}, f, indent=2)


def load_baseline(path):
    if not os.path.exists(path):
        return None
    with open(path, 'r') as f:
        return json.load(f)


# ─────────────────────────────────────────────────────────────
# LOGGING
# ─────────────────────────────────────────────────────────────

def log(msg, path=LOG_FILE):
    ts = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')
    line = f"[{ts}] {msg}"
    print(line)
    with open(path, 'a') as f:
        f.write(line + '\n')


# ─────────────────────────────────────────────────────────────
# STEP 1 — SCAN
# ─────────────────────────────────────────────────────────────

def cmd_scan(wp_path, stale_days, force):
    whitelist = load_set(WHITELIST_FILE)
    blacklist = load_set(BLACKLIST_FILE)
    now_ts    = datetime.now(timezone.utc).timestamp()

    records = parse_wp(wp_path)
    log(f"SCAN  {wp_path}  total={len(records)}")

    # Load existing review decisions so we don't re-flag already-decided entries
    existing_decisions = {}
    if os.path.exists(REVIEW_FILE) and not force:
        with open(REVIEW_FILE, 'r') as f:
            for line in f:
                m = re.match(r'R(\d+)\s+\S+\s+(KEEP|REMOVE)', line)
                if m:
                    existing_decisions[int(m.group(1))] = m.group(2)

    categories = defaultdict(list)
    for rec in records:
        cat, reasons = check_record(rec, now_ts, stale_days, whitelist, blacklist)
        categories[cat].append((rec, reasons))

    ok     = len(categories['OK'])
    auto   = len(categories['AUTO'])
    stale  = len(categories['STALE'])
    review = len(categories['REVIEW'])
    bl     = len(categories['BLACKLIST'])

    print(f"\n{'═'*65}")
    print(f"  WP Manager — Scan Results")
    print(f"  File    : {wp_path}")
    print(f"  Scanned : {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}")
    print(f"  Stale   : >{stale_days} days")
    print(f"{'═'*65}")
    print(f"  Total records    : {len(records):5d}")
    print(f"  ✓ Clean          : {ok:5d}")
    print(f"  ✗ Auto-flag      : {auto:5d}  invalid format / BBS names / empty")
    print(f"  ★ Blacklisted    : {bl:5d}  your permanent remove list")
    print(f"  ⚠ Stale          : {stale:5d}  not seen in >{stale_days} days")
    print(f"  ? Review         : {review:5d}  anomalies / ghost records")
    print(f"{'─'*65}")

    # Write review file
    with open(REVIEW_FILE, 'w') as f:
        f.write("# WP Manager — Review File\n")
        f.write(f"# Generated: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}\n")
        f.write(f"# File: {wp_path}\n")
        f.write("#\n")
        f.write("# Edit the ACTION column for each entry:\n")
        f.write("#   REMOVE  — delete this record from WP.cfg\n")
        f.write("#   KEEP    — leave it in, whitelist it so it's never flagged again\n")
        f.write("#   ?       — undecided (will be flagged again next scan)\n")
        f.write("#\n")
        f.write(f"# {'ID':<7} {'CALLSIGN':<12} {'ACTION':<8} {'REASON':<30} {'NAME':<15} {'QTH'}\n")
        f.write(f"# {'─'*7} {'─'*12} {'─'*8} {'─'*30} {'─'*15} {'─'*20}\n")

        for cat_label, cat_key in [
            ('── AUTO-FLAG (strongly recommended REMOVE) ──', 'AUTO'),
            ('── BLACKLISTED (your permanent list) ──',       'BLACKLIST'),
            ('── STALE (not seen in >730 days) ──',           'STALE'),
            ('── REVIEW (anomalies / ghost records) ──',      'REVIEW'),
        ]:
            entries = categories[cat_key]
            if not entries:
                continue
            f.write(f"\n# {cat_label}\n")
            for rec, reasons in sorted(entries, key=lambda x: x[0]['_id']):
                rid  = rec['_id']
                call = rec.get('c', '(empty)')
                name = rec.get('n', '')[:14]
                qth  = rec.get('q', '')[:25]
                rsn  = ', '.join(reasons)[:30]
                ls   = rec.get('ls', 0)
                age  = int((now_ts - ls) / 86400) if ls else 0

                # Use existing decision if already reviewed
                if rid in existing_decisions:
                    action = existing_decisions[rid]
                elif cat_key == 'AUTO':
                    action = 'REMOVE'    # pre-fill obvious ones
                elif cat_key == 'BLACKLIST':
                    action = 'REMOVE'
                else:
                    action = '?'

                f.write(f"R{rid:<6d} {call:<12s} {action:<8s} {rsn:<30s} {name:<15s} {qth}\n")

    print(f"\n  Review file written: {REVIEW_FILE}")
    print(f"  Edit ACTION column (REMOVE/KEEP/?), then run:")
    print(f"  python3 wp_manager.py --apply --wp {wp_path}\n")

    # Save baseline
    save_baseline(records, BASELINE_FILE)


# ─────────────────────────────────────────────────────────────
# STEP 2 — APPLY decisions from review file
# ─────────────────────────────────────────────────────────────

def cmd_apply(wp_path):
    if not os.path.exists(REVIEW_FILE):
        print(f"ERROR: {REVIEW_FILE} not found. Run --scan first.")
        sys.exit(1)

    # Parse review file decisions
    to_remove  = set()   # R IDs to remove
    to_whitelist = set() # callsigns to add to whitelist

    with open(REVIEW_FILE, 'r') as f:
        for line in f:
            if line.startswith('#') or not line.strip():
                continue
            m = re.match(r'R(\d+)\s+(\S+)\s+(REMOVE|KEEP)', line)
            if not m:
                continue
            rid    = int(m.group(1))
            call   = m.group(2)
            action = m.group(3)
            if action == 'REMOVE':
                to_remove.add(rid)
            elif action == 'KEEP':
                if call and call != '(empty)':
                    to_whitelist.add(call.upper())

    if not to_remove and not to_whitelist:
        print("No REMOVE or KEEP decisions found in review file.")
        print("Edit wp_review.txt and set actions, then run --apply again.")
        return

    # Update whitelist
    if to_whitelist:
        whitelist = load_set(WHITELIST_FILE)
        whitelist |= to_whitelist
        save_set(WHITELIST_FILE, whitelist,
                 "# WP Manager Whitelist — callsigns to never flag\n"
                 "# Add one callsign per line")
        log(f"WHITELIST  added {len(to_whitelist)} entries: {', '.join(sorted(to_whitelist))}")

    if not to_remove:
        print(f"Whitelist updated ({len(to_whitelist)} entries). Nothing to remove.")
        return

    # Read raw WP.cfg content
    with open(wp_path, 'r', errors='replace') as f:
        content = f.read()

    # Backup original
    backup_path = wp_path + f".bak_{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')}"
    shutil.copy2(wp_path, backup_path)
    log(f"BACKUP  {backup_path}")

    # Surgically remove only the flagged R blocks
    # Each block: R{id} : { ... };   — we remove the whole block
    removed_calls = []
    for rid in sorted(to_remove, reverse=True):
        # Match this specific record block
        pattern = re.compile(
            rf'R{rid}\s*:\s*\{{[^}}]+\}}\s*;?\s*', re.DOTALL
        )
        m = pattern.search(content)
        if m:
            # Capture callsign for logging before removal
            call_m = re.search(r'c\s*=\s*"([^"]*)"', m.group(0))
            call   = call_m.group(1) if call_m else f'R{rid}'
            content = pattern.sub('', content, count=1)
            removed_calls.append(f"R{rid}:{call}")
        else:
            log(f"WARN  R{rid} not found in {wp_path} — may have been removed already")

    # Write modified file (R numbers unchanged — no renumbering)
    with open(wp_path, 'w') as f:
        f.write(content)

    log(f"APPLY  removed {len(removed_calls)} records from {wp_path}: {', '.join(removed_calls)}")

    print(f"\n{'═'*65}")
    print(f"  WP Manager — Apply Complete")
    print(f"{'═'*65}")
    print(f"  Removed  : {len(removed_calls)} records")
    for r in removed_calls:
        print(f"    {r}")
    if to_whitelist:
        print(f"  Whitelisted: {len(to_whitelist)} callsigns")
        for c in sorted(to_whitelist):
            print(f"    {c}")
    print(f"  Backup   : {backup_path}")
    print(f"  Log      : {LOG_FILE}")
    print(f"\n  NOTE: Winlink CMS may repopulate removed entries on next sync.")
    print(f"  Run --scan after your next WP update to check for re-injection.")
    print(f"  BPQ restarted automatically — changes are now live.")

    # Restart BPQ so changes take effect
    print(f"  Restarting BPQ service...")
    import subprocess
    result = subprocess.run(
        ["systemctl", "restart", "bpq"],
        capture_output=True, text=True
    )
    if result.returncode == 0:
        log("BPQ_RESTART  systemctl restart bpq — OK")
        print(f"  ✓ BPQ restarted successfully")
    else:
        log(f"BPQ_RESTART  FAILED: {result.stderr.strip()}")
        print(f"  ✗ BPQ restart failed: {result.stderr.strip()}")
        print(f"    Run manually: sudo systemctl restart bpq")

    # Save new baseline
    records = parse_wp(wp_path)
    save_baseline(records, BASELINE_FILE)



# ─────────────────────────────────────────────────────────────
# AUTO-CLEAN — scan + apply in one step, AUTO/BLACKLIST only
# ─────────────────────────────────────────────────────────────

def cmd_auto_clean(wp_path, stale_days, dry_run=False):
    """
    Automated mode: scans WP.cfg and immediately removes entries
    flagged as AUTO (invalid callsign format, BBS names, empty) or
    BLACKLISTED. STALE and REVIEW entries are left untouched —
    those still require human review.

    Safe to run from cron. Always creates a timestamped backup first.
    Logs all actions to wp_manager.log.
    """
    whitelist = load_set(WHITELIST_FILE)
    blacklist = load_set(BLACKLIST_FILE)
    now_ts    = datetime.now(timezone.utc).timestamp()

    records   = parse_wp(wp_path)
    log(f"AUTO-CLEAN  {'DRY-RUN  ' if dry_run else ''}scan {wp_path}  total={len(records)}")

    to_remove = []
    for rec in records:
        cat, reasons = check_record(rec, now_ts, stale_days, whitelist, blacklist)
        if cat in ('AUTO', 'BLACKLIST'):
            to_remove.append((rec, cat, reasons))

    if not to_remove:
        log(f"AUTO-CLEAN  nothing to remove — WP.cfg is clean")
        print("AUTO-CLEAN: nothing to remove.")
        return

    print(f"\n{'═'*65}")
    print(f"  WP Manager — Auto-Clean {'(DRY RUN) ' if dry_run else ''}")
    print(f"  File    : {wp_path}")
    print(f"  Mode    : AUTO + BLACKLIST only (STALE/REVIEW left untouched)")
    print(f"{'═'*65}")
    print(f"  Entries to remove: {len(to_remove)}")
    for rec, cat, reasons in to_remove:
        call = rec.get('c', '(empty)')
        print(f"    R{rec['_id']:<6d} {call:<12s} [{cat}] {', '.join(reasons)}")

    if dry_run:
        print(f"\n  DRY RUN — no changes made. Remove --dry-run to apply.")
        log(f"AUTO-CLEAN  DRY-RUN  would remove {len(to_remove)} records")
        return

    # Read WP.cfg
    with open(wp_path, 'r', errors='replace') as f:
        content = f.read()

    # Backup
    backup_path = wp_path + f".bak_{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')}"
    import shutil as _shutil
    _shutil.copy2(wp_path, backup_path)
    log(f"AUTO-CLEAN  BACKUP  {backup_path}")

    # Surgically remove flagged records
    removed = []
    for rec, cat, reasons in to_remove:
        rid     = rec['_id']
        pattern = re.compile(rf'R{rid}\s*:\s*\{{[^}}]+\}}\s*;?\s*', re.DOTALL)
        m       = pattern.search(content)
        if m:
            call    = rec.get('c', f'R{rid}')
            content = pattern.sub('', content, count=1)
            removed.append(f"R{rid}:{call}[{cat}]")
            log(f"AUTO-CLEAN  REMOVE  R{rid} {call} [{cat}] {', '.join(reasons)}")
        else:
            log(f"AUTO-CLEAN  WARN  R{rid} not found — may already be removed")

    # Write updated WP.cfg
    with open(wp_path, 'w') as f:
        f.write(content)

    log(f"AUTO-CLEAN  DONE  removed {len(removed)} records: {', '.join(removed)}")

    print(f"\n  Removed  : {len(removed)} records")
    print(f"  Backup   : {backup_path}")

    # Update review file so --apply knows what happened
    with open(REVIEW_FILE, 'a') as f:
        f.write(f"\n# AUTO-CLEAN run {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}\n")
        for r in removed:
            f.write(f"# REMOVED {r}\n")

    # Restart BPQ
    import subprocess
    print(f"\n  Restarting BPQ...")
    result = subprocess.run(["systemctl", "restart", "bpq"],
                            capture_output=True, text=True)
    if result.returncode == 0:
        log("AUTO-CLEAN  BPQ_RESTART  OK")
        print(f"  ✓ BPQ restarted")
    else:
        log(f"AUTO-CLEAN  BPQ_RESTART  FAILED: {result.stderr.strip()}")
        print(f"  ✗ BPQ restart failed: {result.stderr.strip()}")
        print(f"    Run manually: sudo systemctl restart bpq")

    # Save new baseline
    records = parse_wp(wp_path)
    save_baseline(records, BASELINE_FILE)
    print(f"  Baseline updated.")
    print()

# ─────────────────────────────────────────────────────────────
# STEP 3 — DIFF against baseline (run after next WP sync)
# ─────────────────────────────────────────────────────────────

def cmd_diff(wp_path):
    baseline = load_baseline(BASELINE_FILE)
    if not baseline:
        print(f"No baseline found. Run --scan first to establish one.")
        return

    bl_ts      = baseline['ts']
    bl_records = baseline['records']  # {str(id): callsign}

    records    = parse_wp(wp_path)
    current    = {str(r['_id']): r.get('c','') for r in records}

    whitelist  = load_set(WHITELIST_FILE)
    blacklist  = load_set(BLACKLIST_FILE)
    now_ts     = datetime.now(timezone.utc).timestamp()

    new_ids      = set(current.keys()) - set(bl_records.keys())
    removed_ids  = set(bl_records.keys()) - set(current.keys())
    repopulated  = []  # removed entries that came back

    # Check if any records we previously removed got repopulated (new R ID, same call)
    bl_calls = set(bl_records.values())
    cur_calls = set(current.values())
    # Calls that appeared in current but weren't in baseline by callsign
    brand_new_calls = cur_calls - bl_calls - {''}

    print(f"\n{'═'*65}")
    print(f"  WP Manager — Diff vs Baseline")
    print(f"  Baseline : {bl_ts}")
    print(f"  Current  : {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}")
    print(f"{'═'*65}")
    print(f"  Baseline records : {len(bl_records)}")
    print(f"  Current records  : {len(current)}")
    print(f"  New entries      : {len(new_ids)}")
    print(f"  Removed entries  : {len(removed_ids)}")
    print(f"{'─'*65}")

    if new_ids:
        print(f"\n  NEW entries since baseline ({len(new_ids)}):")
        flagged_new = []
        clean_new   = []
        for rid in sorted(new_ids, key=int):
            call = current.get(rid, '')
            rec  = next((r for r in records if str(r['_id']) == rid), {})
            cat, reasons = check_record(rec, now_ts, STALE_DAYS_DEFAULT,
                                         whitelist, blacklist)
            if cat != 'OK':
                flagged_new.append((rid, call, cat, reasons))
            else:
                clean_new.append((rid, call))

        if flagged_new:
            print(f"  ⚠ Flagged new entries ({len(flagged_new)}) — run --scan to review:")
            for rid, call, cat, reasons in flagged_new:
                print(f"    R{rid:<6s} {call:<12s} [{cat}] {', '.join(reasons)}")
        if clean_new:
            print(f"  ✓ Clean new entries ({len(clean_new)}):")
            for rid, call in clean_new[:10]:
                print(f"    R{rid:<6s} {call}")
            if len(clean_new) > 10:
                print(f"    ... and {len(clean_new)-10} more")

    if removed_ids:
        print(f"\n  REMOVED since baseline ({len(removed_ids)}):")
        for rid in sorted(removed_ids, key=int):
            print(f"    R{rid:<6s} {bl_records.get(rid,'?')}")

    if not new_ids and not removed_ids:
        print("  No changes since baseline.")

    print()


# ─────────────────────────────────────────────────────────────
# BLACKLIST management
# ─────────────────────────────────────────────────────────────

def cmd_blacklist_add(calls):
    blacklist = load_set(BLACKLIST_FILE)
    added = []
    for call in calls:
        c = call.upper().strip()
        if c:
            blacklist.add(c)
            added.append(c)
    save_set(BLACKLIST_FILE, blacklist,
             "# WP Manager Blacklist — always flag these callsigns for removal\n"
             "# Add one callsign per line. Use for persistent bogus entries.\n"
             "# These will be flagged REMOVE automatically on every scan.")
    log(f"BLACKLIST  added: {', '.join(added)}")
    print(f"Added to blacklist: {', '.join(added)}")


# ─────────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description='WP Manager — Winlink White Pages interactive cleaner',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 wp_manager.py --scan
  python3 wp_manager.py --scan --wp C:\\RMS\\WP.cfg --stale-days 365
  python3 wp_manager.py --apply
  python3 wp_manager.py --diff
  python3 wp_manager.py --blacklist-add NJVBBS ELMBBS Sally HAMGAT
        """
    )

    parser.add_argument('--wp', default=DEFAULT_WP_PATH,
                        help=f'Path to WP.cfg (default: {DEFAULT_WP_PATH})')
    parser.add_argument('--scan', action='store_true',
                        help='Scan WP.cfg and write wp_review.txt')
    parser.add_argument('--apply', action='store_true',
                        help='Apply decisions from wp_review.txt to WP.cfg')
    parser.add_argument('--diff', action='store_true',
                        help='Show new/changed entries since last baseline')
    parser.add_argument('--blacklist-add', nargs='+', metavar='CALL',
                        help='Add callsign(s) to permanent blacklist')
    parser.add_argument('--stale-days', type=int, default=STALE_DAYS_DEFAULT,
                        help=f'Days without activity = stale (default: {STALE_DAYS_DEFAULT})')
    parser.add_argument('--force', action='store_true',
                        help='Re-scan all entries, ignoring previous decisions')
    parser.add_argument('--auto-clean', action='store_true',
                        help='Automated mode: scan and immediately remove AUTO/BLACKLIST entries. '
                             'Safe for cron. STALE/REVIEW entries are never touched automatically.')
    parser.add_argument('--dry-run', action='store_true',
                        help='With --auto-clean: show what would be removed without making changes')

    args = parser.parse_args()

    if not any([args.scan, args.apply, args.diff, args.blacklist_add, args.auto_clean]):
        parser.print_help()
        return

    if args.blacklist_add:
        cmd_blacklist_add(args.blacklist_add)

    if args.auto_clean:
        if not os.path.exists(args.wp):
            print(f"ERROR: {args.wp} not found")
            sys.exit(1)
        cmd_auto_clean(args.wp, args.stale_days, dry_run=args.dry_run)
        return  # don't run scan/apply separately

    if args.scan:
        if not os.path.exists(args.wp):
            print(f"ERROR: {args.wp} not found")
            sys.exit(1)
        cmd_scan(args.wp, args.stale_days, args.force)

    if args.apply:
        if not os.path.exists(args.wp):
            print(f"ERROR: {args.wp} not found")
            sys.exit(1)
        cmd_apply(args.wp)

    if args.diff:
        if not os.path.exists(args.wp):
            print(f"ERROR: {args.wp} not found")
            sys.exit(1)
        cmd_diff(args.wp)


if __name__ == '__main__':
    main()
