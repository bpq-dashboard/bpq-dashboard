#!/usr/bin/env python3
"""
parse-vhf-connections.py — VHF packet connections into rf_connections (v1.5.51)

Writes rows with modem = 'vhf' so RF Connections' Recent connections, the
partner strips and the 7-day history show VHF packet calls next to VARA
and Mercury. Not counted in the headline numbers.

VHF ports are the LinBPQ ports whose driver is a packet TNC (KISS / ASYNC,
UZ7HO, AGW, SCS: sound-card modems such as QtSoundModem, Direwolf, UZ7HO
SoundModem, or a hardware TNC), read from bpq32.cfg, or config.php
'vhf' => ['ports' => [4]]. AXIP / Telnet / VARA / Mercury / Data2G ports are not VHF.

Where the calls come from (all UTC as written, like the other parsers):
  1. The BBS log (log_YYMMDD_BBS.txt), our outgoing connects:
       >RMS       C 4 KP2L-10                (or ATTACH 4 then C KP2L-10)
       <RMS       AGNODE:N0CALL-4} Connected to KP2L-10     -> connected
       <...       Failure with / Busy from                 -> failed
     The session ends at the stream's last line before a "Disconnected"
     line, its next command, or 10 minutes of silence.
  2. The BBS log, calls into the BBS: "Incoming Connect from X on Port 4".
  3. LinBPQ's ConnectLog_YYMMDD.log (LogAllConnects=1): "Call from X to
     N0CALL-4 Mode AX.25". It doesn't say which port, and AXIP (internet)
     links are "AX.25" too, so a call only counts as VHF when the station
     was heard on a VHF port within a few minutes of it (net_heard_history,
     filled by the 5-minute MHeard poll).
  4. The scheduled test connects (link_probes, scripts/link-probe.py),
     marked "test connect".

v1.5.92: frequency and Winlink. Each row gets the port's frequency: config.php
'vhf' => ['freq_mhz' => 145.010], else the frequency in the port's WL2KREPORT
line in bpq32.cfg (one per port; with one VHF port, calls whose port isn't
known get it too). An incoming call that LinBPQ's gateway log
(CMSAccess_YYYYMMDD.log) shows connecting to CMS within 2 minutes is a
Winlink session: band 'WL2K' instead of 'VHF'.
Runs from parse-system-logs.py every 5 minutes (no cron line of its own),
re-reading the last --days (default 2) days; rows are keyed on start time
and callsign, so a re-read updates rather than duplicates.

Usage:
  parse-vhf-connections.py [--days N] [--dry-run] [-v]
  parse-vhf-connections.py --reset      # delete the VHF rows and read --days again
Exit: 0 ok, 2 database, 3 database not upgraded (run update.sh).
"""
import argparse
import datetime as dt
import glob
import importlib.util
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402
import winlink_match as wm  # noqa: E402

try:
    import pymysql
except ImportError:
    pymysql = None

HERE = os.path.dirname(os.path.abspath(__file__))
GAP = dt.timedelta(minutes=10)          # silence that ends an outgoing session
HEARD_BEFORE = dt.timedelta(minutes=3)  # ConnectLog call counts as VHF when heard
HEARD_AFTER = dt.timedelta(minutes=8)   # on a VHF port this close to it

RE_BBS = re.compile(r'^(\d{6}) (\d\d:\d\d:\d\d) ([<>|?])(\S+)\s+(.*)$')
RE_C_PORT = re.compile(r'^C\s+(\d+)\s+([A-Z0-9]{1,6}(?:-\d{1,2})?)\b', re.I)
RE_C = re.compile(r'^C\s+([A-Z0-9]{1,6}(?:-\d{1,2})?)\b', re.I)
RE_ATTACH = re.compile(r'^ATTACH\s+(\d+)\b', re.I)
RE_CONNECTED = re.compile(r'Connected to\s+([A-Z0-9]{1,6}(?:-\d{1,2})?)', re.I)
RE_FAILED = re.compile(r'(Failure with|Busy from)\s+([A-Z0-9]{1,6}(?:-\d{1,2})?)', re.I)
RE_DISC = re.compile(r'Disconnected|\*\*\* DISCONNECTED', re.I)
RE_INCOMING = re.compile(r'Incoming Connect from\s+([A-Z0-9]{1,6}(?:-\d{1,2})?)\s+on Port\s+(\d+)', re.I)
RE_CONNLOG = re.compile(r'^(\d\d:\d\d:\d\d)\s+Call from\s+([A-Z0-9]{1,6}(?:-\d{1,2})?)\b.*?\bto\s+(\S+)\s+Mode\s+AX\.25', re.I)


def base_call(c):
    return (c or '').upper().split('-')[0][:10]


# ── VHF ports ───────────────────────────────────────────────────────────────
def vhf_ports():
    """{port: name} of packet-TNC ports. config.php vhf.ports wins."""
    cfg = bpq_db_config.load_config_section('vhf') or {}
    if isinstance(cfg.get('ports'), (list, tuple)) and cfg['ports']:
        return {int(p): f'Port {int(p)}' for p in cfg['ports'] if str(p).isdigit()}
    spec = importlib.util.spec_from_file_location('pvl', os.path.join(HERE, 'parse-vara-log.py'))
    pvl = importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(pvl)
        pm = pvl.parse_bpq_port_map(bpq_db_config.default_linbpq_path('bpq32.cfg'))
    except Exception:  # noqa: BLE001
        return {}
    return {n: m for n, m in pm.items() if m == 'AX.25'}


# ── sources ─────────────────────────────────────────────────────────────────
def day_files(logs_dir, days, pattern):
    today = dt.datetime.now(dt.timezone.utc).replace(tzinfo=None).date()
    out = []
    for i in range(days - 1, -1, -1):
        d = today - dt.timedelta(days=i)
        p = os.path.join(logs_dir, pattern.format(d.strftime('%y%m%d')))
        if os.path.isfile(p):
            out.append((d, p))
    return out


def from_bbs(logs_dir, days, ports):
    rows = []
    streams = {}          # stream -> open attempt
    attached = {}         # stream -> port from ATTACH

    def close(st, why_end=None):
        a = streams.pop(st, None)
        if not a:
            return
        if a['status'] is None:
            a['status'], a['failure_reason'] = 'failed', 'no_answer'
        a['end'] = why_end or a['last']
        rows.append(a)

    for _, path in day_files(logs_dir, days, 'log_{}_BBS.txt'):
        with open(path, encoding='utf-8', errors='replace') as fh:
            for line in fh:
                m = RE_BBS.match(line.rstrip('\r\n'))
                if not m:
                    continue
                try:
                    t = dt.datetime.strptime(m.group(1) + m.group(2), '%y%m%d%H:%M:%S')
                except ValueError:
                    continue
                d, st, text = m.group(3), m.group(4), m.group(5).strip()
                # close streams that went quiet
                for s in [s for s, a in streams.items() if t - a['last'] > GAP]:
                    close(s)
                mi = RE_INCOMING.search(text)
                if mi and int(mi.group(2)) in ports:
                    rows.append({'callsign': mi.group(1).upper(), 'direction': 'incoming', 'start': t,
                                 'end': None, 'status': 'success', 'failure_reason': None,
                                 'port': int(mi.group(2)), 'link_mode': 'BBS', 'last': t})
                    continue
                a = streams.get(st)
                if d == '>':
                    ma = RE_ATTACH.match(text)
                    if ma:
                        attached[st] = int(ma.group(1))
                        continue
                    mp = RE_C_PORT.match(text)
                    port = call = None
                    if mp:
                        port, call = int(mp.group(1)), mp.group(2)
                    else:
                        mc = RE_C.match(text)
                        if mc and st in attached:
                            port, call = attached[st], mc.group(1)
                    if call:
                        close(st, a['last'] if a else None)
                        if port in ports:
                            streams[st] = {'callsign': call.upper(), 'direction': 'outgoing', 'start': t,
                                           'end': None, 'status': None, 'failure_reason': None,
                                           'port': port, 'link_mode': 'BBS', 'last': t}
                        continue
                if not a:
                    continue
                a['last'] = t
                if a['status'] is None:
                    mc = RE_CONNECTED.search(text)
                    if mc and base_call(mc.group(1)) == base_call(a['callsign']):
                        a['status'] = 'success'
                        continue
                    mf = RE_FAILED.search(text)
                    if mf:
                        a['status'] = 'failed'
                        a['failure_reason'] = 'busy' if mf.group(1).lower().startswith('busy') else 'no_answer'
                        close(st, t)
                        continue
                if a['status'] == 'success' and RE_DISC.search(text) and d in ('<', '|'):
                    close(st, t)
    for s in list(streams):
        a = streams[s]
        if dt.datetime.now(dt.timezone.utc).replace(tzinfo=None) - a['last'] > GAP:      # still open: leave it for the next run
            close(s)
    return rows


def from_connectlog(logs_dir, days, ports, heard):
    """heard: {base call: sorted [datetime]} on VHF ports."""
    rows = []
    for d, path in day_files(logs_dir, days, 'ConnectLog_{}.log'):
        with open(path, encoding='utf-8', errors='replace') as fh:
            for line in fh:
                m = RE_CONNLOG.match(line.strip())
                if not m:
                    continue
                t = dt.datetime.combine(d, dt.datetime.strptime(m.group(1), '%H:%M:%S').time())
                call = m.group(2).upper()
                times = heard.get(base_call(call), ())
                if not any(t - HEARD_BEFORE <= h <= t + HEARD_AFTER for h in times):
                    continue                     # not heard on VHF then: AXIP / internet
                rows.append({'callsign': call, 'direction': 'incoming', 'start': t, 'end': None,
                             'status': 'success', 'failure_reason': None, 'port': None,
                             'link_mode': 'node', 'last': t})
    return rows


def from_probes(conn, days, ports):
    rows = []
    with conn.cursor() as cur:
        try:
            cur.execute("SELECT probed_at, callsign, port, result, connect_secs FROM link_probes "
                        "WHERE probed_at >= UTC_TIMESTAMP() - INTERVAL %s DAY", (days,))
        except pymysql.MySQLError:
            return rows                         # table not there yet
        for at, call, port, result, secs in cur.fetchall():
            if result == 'error':
                continue                        # never went on the air
            ok = result == 'connected'
            end = at + dt.timedelta(seconds=float(secs or 0) + (5 if ok else 0))
            rows.append({'callsign': call, 'direction': 'outgoing', 'start': at, 'end': end,
                         'status': 'success' if ok else 'failed',
                         'failure_reason': None if ok else ('busy' if result == 'busy' else 'no_answer'),
                         'port': port, 'link_mode': 'test', 'last': end})
    return rows


def heard_on(conn, days, ports):
    out = {}
    if not ports:
        return out
    with conn.cursor() as cur:
        try:
            cur.execute("SELECT callsign, heard_at FROM net_heard_history WHERE port IN ("
                        + ','.join(['%s'] * len(ports)) + ") AND heard_at >= UTC_TIMESTAMP() - INTERVAL %s DAY",
                        list(ports) + [days + 1])
        except pymysql.MySQLError:
            return out
        for call, at in cur.fetchall():
            out.setdefault(base_call(call), []).append(at)
    return out


def dedupe(rows):
    """One row per call within a minute: a BBS-log incoming connect and the
    ConnectLog line for the same call; prefer the BBS log's."""
    rows.sort(key=lambda r: (r['start'], 0 if r['link_mode'] == 'BBS' else 1))
    out, seen = [], []
    for r in rows:
        key = (base_call(r['callsign']), r['direction'])
        if any(k == key and abs((r['start'] - s).total_seconds()) <= 60 for k, s in seen):
            continue
        seen.append((key, r['start']))
        out.append(r)
    return out


# ── database ────────────────────────────────────────────────────────────────
def db_connect():
    if pymysql is None:
        sys.stderr.write("ERROR: python3-pymysql is not installed\n")
        sys.exit(2)
    try:
        return pymysql.connect(**bpq_db_config.load_db_config({'connect_timeout': 10, 'charset': 'utf8mb4',
                                                               'autocommit': False}))
    except pymysql.MySQLError as e:
        sys.stderr.write(f"ERROR: Database connection failed: {e}\n")
        sys.exit(2)


def has_vhf(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
                    "AND TABLE_NAME = 'rf_connections' AND COLUMN_NAME = 'modem'")
        r = cur.fetchone()
    return bool(r and "'vhf'" in r[0])


def port_freqs(ports):
    """{port: kHz} — config.php vhf.freq_mhz for every VHF port, else each port's single WL2KREPORT frequency."""
    cfg = bpq_db_config.load_config_section('vhf') or {}
    try:
        f = float(cfg.get('freq_mhz') or 0)
    except (TypeError, ValueError):
        f = 0
    if f > 0:
        return {p: round(f * 1000, 2) for p in ports}
    rep = wm.port_report_freqs(bpq_db_config.default_linbpq_path('bpq32.cfg'))
    return {p: rep[p][0] for p in ports if len(set(rep.get(p) or [])) == 1}


def enrich(rows, ports, logs_dir, days):
    """v1.5.92: frequency of the port; WL2K when the gateway log confirms the caller."""
    freqs = port_freqs(ports)
    only = next(iter(freqs.values())) if len(ports) == 1 and freqs else None
    if not rows:
        return rows
    cms = wm.Cms(logs_dir, wm.dates_between(min(r['start'] for r in rows), max(r['start'] for r in rows)))
    for r in rows:
        r['freq_khz'] = freqs.get(r.get('port')) if r.get('port') is not None else only
        r['band'] = 'VHF'
        if r['direction'] == 'incoming' and r['status'] == 'success' and cms.match(r['callsign'], r['start']):
            r['band'] = 'WL2K'
    return rows


def write(conn, rows):
    sql = ("INSERT INTO rf_connections (callsign, base_call, direction, modem, session_start, session_end, "
           "duration_sec, freq_khz, band, status, failure_reason, link_mode) "
           "VALUES (%s,%s,%s,'vhf',%s,%s,%s,%s,%s,%s,%s,%s) "
           "ON DUPLICATE KEY UPDATE "
           "session_end = IF(modem = 'vhf', VALUES(session_end), session_end), "
           "duration_sec = IF(modem = 'vhf', VALUES(duration_sec), duration_sec), "
           "freq_khz = IF(modem = 'vhf', VALUES(freq_khz), freq_khz), "
           "band = IF(modem = 'vhf', VALUES(band), band), "
           "status = IF(modem = 'vhf', VALUES(status), status), "
           "failure_reason = IF(modem = 'vhf', VALUES(failure_reason), failure_reason)")
    n = 0
    with conn.cursor() as cur:
        for r in rows:
            dur = int((r['end'] - r['start']).total_seconds()) if r.get('end') else None
            n += cur.execute(sql, (r['callsign'][:16], base_call(r['callsign']), r['direction'], r['start'],
                                   r.get('end'), dur, r.get('freq_khz'), r.get('band') or 'VHF',
                                   r['status'], r['failure_reason'], r['link_mode']))
    return n


def main():
    ap = argparse.ArgumentParser(description='VHF packet connections into rf_connections',
                                 formatter_class=argparse.RawDescriptionHelpFormatter, epilog=__doc__)
    ap.add_argument('--days', type=int, default=2, help='days to (re)read, today included (default 2)')
    ap.add_argument('--logs-dir', default=bpq_db_config.default_logs_dir())
    ap.add_argument('--reset', action='store_true', help='delete the VHF rows first')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--verbose', '-v', action='store_true')
    ap.add_argument('--quiet', '-q', action='store_true')
    a = ap.parse_args()
    days = max(1, min(a.days, 400))

    ports = vhf_ports()
    if not ports:
        if not a.quiet:
            print('VHF: no packet (KISS/AGW/UZ7HO) port found in bpq32.cfg; nothing to do '
                  "(or set config.php 'vhf' => ['ports' => [N]])")
        return 0
    conn = db_connect()
    try:
        if not has_vhf(conn):
            sys.stderr.write("ERROR: rf_connections can't hold VHF rows yet — run update.sh "
                             "(sql/2026-11-15-rf-connections-vhf.sql)\n")
            return 3
        if a.reset and not a.dry_run:
            with conn.cursor() as cur:
                cur.execute("DELETE FROM rf_connections WHERE modem = 'vhf'")
        rows = from_bbs(a.logs_dir, days, ports)
        rows += from_connectlog(a.logs_dir, days, ports, heard_on(conn, days, ports))
        rows += from_probes(conn, days, ports)
        rows = enrich(dedupe(rows), ports, a.logs_dir, days)
        if a.verbose or a.dry_run:
            for r in rows:
                dur = int((r['end'] - r['start']).total_seconds()) if r.get('end') else None
                print(f"  {r['start']:%Y-%m-%d %H:%M:%S} {r['direction']:<8} {r['callsign']:<10} {r['status']:<7} "
                      f"{r['failure_reason'] or '':<9} {r['link_mode']:<5} {r.get('band'):<4} {r.get('freq_khz') or '-':<9} "
                      f"{'' if dur is None else str(dur) + ' s'}")
        n = 0
        if not a.dry_run:
            n = write(conn, rows)
            conn.commit()
        if rows or not a.quiet:
            print(f"VHF: {len(rows)} connection(s) in the last {days} day(s) on port(s) "
                  f"{', '.join(map(str, sorted(ports)))}; {n} row(s) written or updated"
                  + ('  [DRY RUN]' if a.dry_run else ''))
        return 0
    except pymysql.MySQLError as e:
        conn.rollback()
        sys.stderr.write(f"ERROR: database: {e}\n")
        return 2
    finally:
        conn.close()


if __name__ == '__main__':
    sys.exit(main())
