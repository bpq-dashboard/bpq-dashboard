#!/usr/bin/env python3
"""
WP.cfg Scanner / Cleaner
========================
Scans Winlink White Pages database (WP.cfg) for bogus or suspect entries
and optionally produces a cleaned output file.

Usage:
    python3 wp_scanner.py [WP.cfg] [options]

Options:
    --clean         Write cleaned WP_clean.cfg (removes AUTO_REMOVE entries)
    --report        Write wp_scan_report.txt
    --stale-days N  Flag entries not seen in N days (default: 730 / 2 years)
    --dry-run       Show what would be removed without writing files

Author: YOURCALL — generated for BPQDash / Winlink WP maintenance
"""

import re
import sys
import os
import argparse
from datetime import datetime, timezone
from collections import defaultdict

# ─────────────────────────────────────────────────
# Callsign Validation
# ─────────────────────────────────────────────────

# ITU prefixes known to start with a digit
# 2x = UK Foundation/Intermediate (2E, 2M, 2I, 2W, 2D, 2G)
# 3x = Various (3A Monaco, 3B Mauritius, 3C Eq Guinea, 3D Swaziland,
#               3V Tunisia, 3W Vietnam, 3X Guinea, 3Z Poland special)
# 4x = Various (4F/4G/4H/4I Philippines, 4J/4K Azerbaijan/Russia,
#               4L Georgia, 4O Montenegro, 4S Sri Lanka, 4U UN,
#               4W Timor-Leste, 4X Israel, 4Z Israel)
# 5x = Many African/others
# 6x through 9x = various ITU allocations

VALID_CALLSIGN_RE = re.compile(
    r'^('
    # Standard: 1-2 letter prefix + digit + 1-4 letter suffix
    r'[A-Z]{1,2}\d[A-Z]{1,4}'
    r'|'
    # 3-letter prefix (AA-AL, KA-KZ, NA-NZ, WA-WZ) + digit + 1-3 suffix
    r'[AKNW][A-Z]\d[A-Z]{1,3}'
    r'|'
    # Single letter prefix (G, F, I, K, N, R, W etc) + digit + suffix
    r'[A-Z]\d[A-Z]{1,4}'
    r'|'
    # Digit-prefix callsigns (ITU): digit + letter(s) + digit + suffix
    r'[2-9][A-Z]{1,2}\d[A-Z]{1,4}'
    r')'
    r'(-\d{1,2})?$'   # optional SSID
)

# Suffix must not end with a digit (catches N9PN0, N2MH3, N3FUD1 etc)
DIGIT_SUFFIX_RE = re.compile(r'\d$')

# Known BBS / system / non-ham names
BBS_RE = re.compile(
    r'BBS$|^Sally$|^HAMGAT|^WIRES|^ALLSTAR|^SVXLINK|^APRS',
    re.IGNORECASE
)

# Hierarchy field anomalies
BAD_HIER_RE = re.compile(r'[@\s]')

def validate_callsign(call):
    """
    Returns list of fault strings, empty list = valid.
    """
    faults = []
    if not call:
        faults.append("EMPTY_CALLSIGN")
        return faults

    # BBS/system name check first
    if BBS_RE.search(call):
        faults.append("BBS_SYSTEM_NAME")
        return faults

    # Must be uppercase
    if call != call.upper():
        faults.append("NOT_UPPERCASE")

    # Strip SSID for structural checks
    base = call.split('-')[0]

    # Structural pattern check
    if not VALID_CALLSIGN_RE.match(call):
        faults.append(f"INVALID_FORMAT")

    # Suffix must not end with digit
    elif DIGIT_SUFFIX_RE.search(base):
        faults.append("DIGIT_IN_SUFFIX")

    return faults


def validate_record(rec, now_ts, stale_days):
    """
    Returns (severity, [fault_strings])
    severity: 'AUTO_REMOVE' | 'REVIEW' | 'OK'
    """
    faults = []
    call = rec.get('c', '')

    # ── Callsign format ──
    call_faults = validate_callsign(call)
    faults.extend(call_faults)

    # ── Empty record (R0 placeholder) ──
    T = rec.get('T', 0)
    if T == 0 and not call:
        faults.append("PLACEHOLDER_RECORD")

    # ── Unknown T value ──
    if T not in (0, 71, 73, 85):
        faults.append(f"UNKNOWN_T_VALUE={T}")

    # ── Future timestamp (bad clock on originating node) ──
    m_ts = rec.get('m', 0)
    if m_ts > now_ts + 86400 * 30:
        future_days = int((m_ts - now_ts) / 86400)
        faults.append(f"FUTURE_TIMESTAMP_{future_days}d")

    # ── Stale — not seen within threshold ──
    ls_ts = rec.get('ls', 0)
    if ls_ts > 0:
        age_days = (now_ts - ls_ts) / 86400
        if age_days > stale_days:
            faults.append(f"STALE_{int(age_days)}d_NOT_SEEN")

    # ── Hierarchy anomalies ──
    h = rec.get('h', '')
    if h and BAD_HIER_RE.search(h):
        faults.append(f"HIERARCHY_ANOMALY:{h[:40]}")

    if h:
        h_root = h.split('.')[0].split('@')[0]
        if h_root and validate_callsign(h_root):
            # Only flag if root is clearly not a callsign AND not empty
            root_faults = validate_callsign(h_root)
            if root_faults and 'BBS_SYSTEM_NAME' not in root_faults:
                # Don't double-penalize — note but don't auto-remove
                faults.append(f"BAD_HIERARCHY_ROOT:{h_root}")

    # ── Zero activity + no info (ghost record) ──
    s = rec.get('s', 0)
    n = rec.get('n', '')
    q = rec.get('q', '')
    z = rec.get('z', '')
    if s == 0 and not n and not q and not z and not call_faults:
        faults.append("GHOST_RECORD_NO_INFO")

    # ── Severity determination ──
    AUTO_REMOVE_TRIGGERS = {
        'EMPTY_CALLSIGN', 'PLACEHOLDER_RECORD', 'BBS_SYSTEM_NAME',
        'NOT_UPPERCASE', 'INVALID_FORMAT', 'DIGIT_IN_SUFFIX'
    }
    REVIEW_TRIGGERS = {
        'FUTURE_TIMESTAMP', 'HIERARCHY_ANOMALY', 'BAD_HIERARCHY_ROOT',
        'GHOST_RECORD_NO_INFO', 'UNKNOWN_T_VALUE'
    }

    if any(f.split('_')[0] in {'EMPTY', 'PLACEHOLDER', 'BBS', 'NOT', 'INVALID', 'DIGIT'} or
           f in AUTO_REMOVE_TRIGGERS
           for f in faults):
        severity = 'AUTO_REMOVE'
    elif any(f.startswith('STALE') for f in faults):
        severity = 'STALE'
    elif faults:
        severity = 'REVIEW'
    else:
        severity = 'OK'

    return severity, faults


# ─────────────────────────────────────────────────
# Parser
# ─────────────────────────────────────────────────

def parse_wp(path):
    with open(path, 'r', errors='replace') as f:
        content = f.read()

    records = re.findall(r'(R\d+\s*:\s*\{[^}]+\})', content, re.DOTALL)
    parsed = []
    for raw in records:
        m = re.match(r'R(\d+)\s*:', raw)
        rec = {'_id': int(m.group(1)), '_raw': raw}
        for field in re.finditer(r'(\w+)\s*=\s*"([^"]*)"', raw):
            rec[field.group(1)] = field.group(2)
        for field in re.finditer(r'(\w+)\s*=\s*(\d+)L?;', raw):
            if field.group(1) not in rec:
                rec[field.group(1)] = int(field.group(2))
        parsed.append(rec)
    return parsed


def write_cleaned(records, keep_ids, out_path):
    """Write a new WP.cfg with only the kept records, renumbered from R0."""
    lines = []
    new_id = 0
    for rec in records:
        if rec['_id'] not in keep_ids:
            continue
        # Rebuild the record block with new ID
        raw = rec['_raw']
        # Replace the record number
        raw = re.sub(r'^R\d+', f'R{new_id}', raw)
        lines.append(raw.strip())
        new_id += 1
    with open(out_path, 'w') as f:
        f.write('\n'.join(lines) + '\n')
    return new_id


# ─────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='WP.cfg Scanner/Cleaner')
    parser.add_argument('wpfile', nargs='?', default='WP.cfg',
                        help='Path to WP.cfg (default: WP.cfg)')
    parser.add_argument('--clean', action='store_true',
                        help='Write cleaned WP_clean.cfg removing AUTO_REMOVE entries')
    parser.add_argument('--report', action='store_true',
                        help='Write wp_scan_report.txt')
    parser.add_argument('--stale-days', type=int, default=730,
                        help='Days without activity to flag as stale (default: 730)')
    parser.add_argument('--remove-stale', action='store_true',
                        help='Also remove stale entries when --clean is used')
    parser.add_argument('--dry-run', action='store_true',
                        help='Show summary without writing any files')
    args = parser.parse_args()

    if not os.path.exists(args.wpfile):
        print(f"ERROR: {args.wpfile} not found")
        sys.exit(1)

    now_ts = datetime.now(timezone.utc).timestamp()
    records = parse_wp(args.wpfile)

    results = defaultdict(list)  # severity -> list of (rec, faults)
    for rec in records:
        severity, faults = validate_record(rec, now_ts, args.stale_days)
        results[severity].append((rec, faults))

    total = len(records)
    ok_count      = len(results['OK'])
    remove_count  = len(results['AUTO_REMOVE'])
    stale_count   = len(results['STALE'])
    review_count  = len(results['REVIEW'])

    # ── Console summary ──
    print(f"\n{'═'*60}")
    print(f"  WP.cfg Scanner — YOURCALL")
    print(f"  File   : {args.wpfile}")
    print(f"  Scanned: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}")
    print(f"{'═'*60}")
    print(f"  Total records : {total:5d}")
    print(f"  ✓ Clean        : {ok_count:5d}")
    print(f"  ✗ Auto-remove  : {remove_count:5d}  (invalid format, BBS names, empty)")
    print(f"  ⚠ Stale        : {stale_count:5d}  (not seen in >{args.stale_days} days)")
    print(f"  ? Review       : {review_count:5d}  (anomalies, ghost records)")
    print(f"{'─'*60}")

    if results['AUTO_REMOVE']:
        print(f"\n  AUTO-REMOVE entries ({remove_count}):")
        for rec, faults in results['AUTO_REMOVE']:
            call = rec.get('c', '(empty)')
            q    = rec.get('q', '')[:25]
            print(f"    R{rec['_id']:<5d} {call:<12s} {q:<25s} {', '.join(faults)}")

    if results['STALE']:
        print(f"\n  STALE entries ({stale_count}) — not seen in >{args.stale_days} days:")
        for rec, faults in results['STALE']:
            call = rec.get('c', '')
            ls   = rec.get('ls', 0)
            age  = int((now_ts - ls) / 86400) if ls else 0
            q    = rec.get('q', '')[:20]
            print(f"    R{rec['_id']:<5d} {call:<12s} {q:<20s} last seen {age}d ago")

    if results['REVIEW']:
        print(f"\n  REVIEW entries ({review_count}):")
        for rec, faults in results['REVIEW']:
            call = rec.get('c', '')
            q    = rec.get('q', '')[:25]
            print(f"    R{rec['_id']:<5d} {call:<12s} {q:<25s} {', '.join(faults)}")

    print(f"\n{'─'*60}")

    # ── Write cleaned file ──
    if args.clean and not args.dry_run:
        remove_severities = {'AUTO_REMOVE'}
        if args.remove_stale:
            remove_severities.add('STALE')

        remove_ids = {rec['_id'] for sev in remove_severities
                      for rec, _ in results[sev]}
        keep_ids   = {rec['_id'] for rec in records if rec['_id'] not in remove_ids}

        out_dir  = os.path.dirname(os.path.abspath(args.wpfile))
        out_path = os.path.join(out_dir, 'WP_clean.cfg')
        written  = write_cleaned(records, keep_ids, out_path)
        print(f"  ✓ Cleaned file written: {out_path}")
        print(f"    Removed: {len(remove_ids)} records")
        print(f"    Kept   : {written} records (renumbered R0–R{written-1})")

    # ── Write report ──
    if args.report and not args.dry_run:
        out_dir     = os.path.dirname(os.path.abspath(args.wpfile))
        report_path = os.path.join(out_dir, 'wp_scan_report.txt')
        with open(report_path, 'w') as f:
            f.write(f"WP.cfg Scan Report\n")
            f.write(f"Generated : {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}\n")
            f.write(f"File      : {args.wpfile}\n")
            f.write(f"Total     : {total}\n")
            f.write(f"Clean     : {ok_count}\n")
            f.write(f"Remove    : {remove_count}\n")
            f.write(f"Stale     : {stale_count} (>{args.stale_days}d)\n")
            f.write(f"Review    : {review_count}\n\n")
            for sev in ('AUTO_REMOVE', 'STALE', 'REVIEW'):
                if results[sev]:
                    f.write(f"\n[{sev}]\n")
                    for rec, faults in results[sev]:
                        ls  = rec.get('ls', 0)
                        age = int((now_ts - ls) / 86400) if ls else 0
                        f.write(f"  R{rec['_id']:<5d} {rec.get('c',''):12s} "
                                f"T={rec.get('T','?'):3} s={rec.get('s',0):4d} "
                                f"age={age:4d}d  q={rec.get('q','')[:30]:30s}  "
                                f"faults={faults}\n")
        print(f"  ✓ Report written: {report_path}")

    if args.dry_run:
        print("  [DRY RUN — no files written]")

    print()


if __name__ == '__main__':
    main()
