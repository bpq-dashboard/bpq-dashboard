#!/usr/bin/env python3
"""
winlink_match.py — shared by the VARA, Data2G and VHF parsers (v1.5.92)

Two facts LinBPQ's BBS log doesn't give for a Winlink user who calls the
RMS gateway call (the call goes to the gateway, not the BBS, so there is no
"Incoming Connect ... Freq" line):

  * that it was a Winlink session: LinBPQ's gateway log,
    CMSAccess_YYYYMMDD.log in the logs folder, has
        "03:03:19 10 KQ4WUH Connected to CMS"
    for it. A call counts as Winlink only when that line is there within
    CMS_WINDOW_S of the call.
  * the frequency: rig-history.jsonl (config.php paths.datalog, written
    by rig-history-poller every 3 s while the radio transmits, from flrig)
    has the dial frequency the radio was on. The most common transmit
    frequency during the call is used; nothing is guessed from other calls.

And for VHF ports, the frequency the port reports to Winlink (its
WL2KREPORT line in bpq32.cfg), or config.php vhf.freq_mhz.

Times are naive UTC, as in the parsers and rf_connections.
"""
import bisect
import collections
import datetime as dt
import json
import os
import re

import bpq_db_config

_HERE = os.path.dirname(os.path.abspath(__file__))
CMS_WINDOW_S = 120
RE_CMS = re.compile(r'^(\d\d):(\d\d):(\d\d)\s+\d+\s+([A-Z0-9]+(?:-\d+)?)\s+Connected to CMS')


def base_call(c):
    return re.sub(r'-\d+$', '', (c or '').upper())


def dates_between(a, b):
    """Every date from a to b (datetimes or dates), inclusive."""
    a = a.date() if isinstance(a, dt.datetime) else a
    b = b.date() if isinstance(b, dt.datetime) else b
    out, d = [], a
    while d <= b:
        out.append(d)
        d += dt.timedelta(days=1)
    return out


# ---------------------------------------------------------------- gateway log
class Cms:
    """"Connected to CMS" events, and which days have a gateway log at all."""

    def __init__(self, log_dir, dates, skip_call=None):
        self.events = []                       # (time, base call), sorted
        self.days = set()                      # dates whose CMSAccess file exists
        skip = base_call(skip_call) if skip_call else None
        for day in sorted(set(dates)):
            p = os.path.join(log_dir or '', f'CMSAccess_{day:%Y%m%d}.log')
            try:
                fh = open(p, 'r', encoding='utf-8', errors='replace')
            except OSError:
                continue
            self.days.add(day)
            with fh:
                for line in fh:
                    m = RE_CMS.match(line)
                    if not m:
                        continue
                    b = base_call(m.group(4))
                    if skip and b == skip:          # our own internet link to CMS
                        continue
                    t = dt.datetime.combine(day, dt.time(int(m.group(1)), int(m.group(2)), int(m.group(3))))
                    self.events.append((t, b))
        self.events.sort()
        self._keys = [e[0] for e in self.events]

    def covers(self, t):
        """True when the gateway log for t's day exists, so a missing line means 'not Winlink'."""
        return t.date() in self.days

    def match(self, call, t, window=CMS_WINDOW_S):
        b = base_call(call)
        i = bisect.bisect_left(self._keys, t - dt.timedelta(seconds=window))
        while i < len(self.events) and self.events[i][0] <= t + dt.timedelta(seconds=window):
            if self.events[i][1] == b:
                return True
            i += 1
        return False


# ---------------------------------------------------------------- rig history
def rig_history_file():
    paths = bpq_db_config.load_config_section('paths') or {}
    d = (paths.get('datalog') or os.path.join(os.path.dirname(_HERE), 'logs')).rstrip('/')
    return os.path.join(d, 'rig-history.jsonl')


class Rig:
    """Transmit samples (time, kHz) from rig-history.jsonl for the given dates."""

    def __init__(self, path, dates):
        self.tx = []
        self.days = set()                      # dates with any sample (radio was being read)
        want = {f'"ts":"{d:%Y-%m-%d}T': d for d in set(dates)}
        if path and os.path.isfile(path) and want:
            try:
                with open(path, 'r', encoding='utf-8', errors='replace') as fh:
                    for line in fh:
                        p = line.find('"ts":"')
                        d = want.get(line[p:p + 17]) if p >= 0 else None
                        if d is None:
                            continue
                        self.days.add(d)
                        if '"tx":true' not in line:
                            continue
                        try:
                            e = json.loads(line)
                            t = dt.datetime.strptime(e['ts'][:19], '%Y-%m-%dT%H:%M:%S')
                            mhz = float(e.get('mhz') or 0)
                        except (ValueError, KeyError, TypeError):
                            continue
                        if mhz > 0:
                            self.tx.append((t, round(mhz * 1000, 2)))
            except OSError:
                pass
        self.tx.sort()
        self._keys = [e[0] for e in self.tx]

    def freq(self, start, end, pad=5):
        """Most common transmit frequency (kHz) between start and end, or None."""
        if not self.tx or not start:
            return None
        a = start - dt.timedelta(seconds=pad)
        b = (end or start) + dt.timedelta(seconds=pad)
        i = bisect.bisect_left(self._keys, a)
        seen = collections.Counter()
        while i < len(self.tx) and self.tx[i][0] <= b:
            seen[self.tx[i][1]] += 1
            i += 1
        return seen.most_common(1)[0][0] if seen else None


def rig_enabled(section):
    """config.php <section>.rig_history (default true): false when that modem's radio isn't the one flrig reads."""
    return bool((bpq_db_config.load_config_section(section) or {}).get('rig_history', True))


# ---------------------------------------------------------------- bpq32.cfg
def port_report_freqs(cfg_path):
    """{port number: [kHz, ...]} from the WL2KREPORT lines inside each PORT block."""
    out = {}
    try:
        with open(cfg_path, 'r', encoding='utf-8', errors='replace') as fh:
            lines = fh.readlines()
    except (OSError, TypeError):
        return out
    num, freqs, in_port = None, [], False
    for raw in lines:
        line = raw.split(';', 1)[0].strip()
        up = line.upper()
        if up == 'PORT':
            in_port, num, freqs = True, None, []
        elif up == 'ENDPORT':
            if in_port and num is not None and freqs:
                out[num] = freqs
            in_port = False
        elif in_port and up.startswith('PORTNUM'):
            m = re.search(r'(\d+)', up)
            num = int(m.group(1)) if m else None
        elif in_port and up.startswith('WL2KREPORT'):
            f = [x.strip() for x in line[10:].split(',')]
            if len(f) >= 7 and f[6].isdigit():
                freqs.append(round(int(f[6]) / 1000.0, 2))
    return out
