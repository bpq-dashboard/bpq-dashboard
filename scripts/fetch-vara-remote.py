#!/usr/bin/env python3
"""
fetch-vara-remote.py — download this station's VARA HF log from the TPRFN
server (v1.5.19, 2026-11-02).

For TPRFN stations whose VARA HF sends its syslog to the TPRFN server rather
than to this machine. The server keeps one file per station (e.g.
https://<TPRFN server>/<call>.vara); RF Connections needs a local copy.
Run by vara-remote.timer every 5 minutes as the web user.

config.php:
    'vara_remote' => ['enabled' => true, 'url' => 'https://.../<call>.vara'],
    'logs'        => ['vara_file' => '/var/log/bpq-dashboard/<call>.vara'],
    (a relative vara_file is taken inside paths.logs, as for vara-logger)

How it downloads:
- Only what is new: an HTTP Range request from the size of the local copy,
  appended when the server answers 206. A full download (200) replaces the
  file; 416 = nothing new. If the server's file got shorter (rebuilt after a
  syslog rotation) the whole file is fetched again; the RF Connections parser
  starts over on a shorter file by itself.
- Always to a temporary file first, then renamed into place, so a failed or
  cut-off download never leaves a broken log; the last good copy is kept.
- Sends a 'BPQ-Dashboard/<version> (<CALL>)' user agent: the TPRFN server's
  bot filter refuses curl/python defaults.

Status for Install Check / System Audit: cache/vara-remote-status.json
(last attempt, last success, bytes, error).

Usage:  fetch-vara-remote.py [--verbose] [--full]
"""
import argparse
import json
import os
import sys
import tempfile
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bpq_db_config  # noqa: E402

VERSION = '1.5.33'
WEB_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATUS_FILE = os.path.join(WEB_ROOT, 'cache', 'vara-remote-status.json')
MAX_BYTES = 200 * 1024 * 1024      # refuse anything absurd
TIMEOUT = 60


def log(msg, verbose=True):
    if verbose:
        print(time.strftime('%Y-%m-%d %H:%M:%S ') + msg, flush=True)


def write_status(**kw):
    st = {}
    try:
        with open(STATUS_FILE) as f:
            st = json.load(f)
    except (OSError, ValueError):
        pass
    st.update(kw)
    st['last_attempt'] = int(time.time())
    try:
        os.makedirs(os.path.dirname(STATUS_FILE), exist_ok=True)
        tmp = STATUS_FILE + '.tmp'
        with open(tmp, 'w') as f:
            json.dump(st, f)
        os.replace(tmp, STATUS_FILE)
    except OSError:
        pass


def target_path():
    vf = (bpq_db_config.load_config_section('logs').get('vara_file') or '').strip()
    if not vf:
        call = (bpq_db_config.load_config_section('station').get('callsign') or 'yourcall').lower()
        vf = call + '.vara'
    return vf if os.path.isabs(vf) else os.path.join(bpq_db_config.default_logs_dir(), vf)


def request(url, call, headers=None, method='GET'):
    h = {'User-Agent': f'BPQ-Dashboard/{VERSION} ({call})', 'Accept': 'text/plain, */*'}
    h.update(headers or {})
    return urllib.request.urlopen(urllib.request.Request(url, headers=h, method=method), timeout=TIMEOUT)


def copy_body(resp, out, limit):
    n = 0
    while True:
        chunk = resp.read(65536)
        if not chunk:
            return n
        n += len(chunk)
        if n > limit:
            raise ValueError(f'more than {limit // (1024 * 1024)} MB; refused')
        out.write(chunk)


def main():
    ap = argparse.ArgumentParser(description='Download the VARA HF log from the TPRFN server.')
    ap.add_argument('--verbose', '-v', action='store_true')
    ap.add_argument('--full', action='store_true', help='download the whole file again')
    a = ap.parse_args()

    rc = bpq_db_config.load_config_section('vara_remote')
    if not rc.get('enabled'):
        log("vara_remote is not enabled in config.php; nothing to do", a.verbose)
        return 0
    url = (rc.get('url') or '').strip()
    if not url.lower().startswith(('https://', 'http://')):
        log("ERROR: config.php vara_remote.url is not a web address")
        write_status(ok=False, error='vara_remote.url missing or not a web address')
        return 2
    call = (bpq_db_config.load_config_section('station').get('callsign') or 'N0CALL').upper()
    dest = target_path()
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    have = 0 if a.full or not os.path.exists(dest) else os.path.getsize(dest)

    try:
        # Did the server's file shrink (rebuilt)? Then fetch it all again.
        if have:
            try:
                with request(url, call, method='HEAD') as r:
                    size = int(r.headers.get('Content-Length') or -1)
                if 0 <= size < have:
                    log(f"Server file is shorter ({size} < {have} bytes): downloading it again", a.verbose)
                    have = 0
            except (urllib.error.URLError, ValueError):
                pass   # HEAD refused: carry on with the range request

        hdrs = {'Range': f'bytes={have}-'} if have else {}
        try:
            resp = request(url, call, hdrs)
        except urllib.error.HTTPError as e:
            if e.code == 416:      # nothing past what we have
                log(f"Up to date ({have} bytes)", a.verbose)
                write_status(ok=True, last_success=int(time.time()), bytes=have, added=0, error=None, url=url, file=dest)
                return 0
            raise
        with resp:
            code = resp.status
            fd, tmp = tempfile.mkstemp(prefix='.vara-remote-', dir=os.path.dirname(dest))
            try:
                with os.fdopen(fd, 'wb') as out:
                    if code == 206 and have:
                        with open(dest, 'rb') as old:           # existing part, then the new bytes
                            while True:
                                b = old.read(1 << 20)
                                if not b:
                                    break
                                out.write(b)
                        added = copy_body(resp, out, MAX_BYTES)
                    else:                                        # 200: the whole file
                        added = copy_body(resp, out, MAX_BYTES)
                os.chmod(tmp, 0o644)
                os.replace(tmp, dest)
            except BaseException:
                try:
                    os.unlink(tmp)
                except OSError:
                    pass
                raise
        total = os.path.getsize(dest)
        log(f"{'Added' if code == 206 else 'Downloaded'} {added} bytes; {dest} is {total} bytes", a.verbose)
        write_status(ok=True, last_success=int(time.time()), bytes=total, added=added, error=None, url=url, file=dest)
        return 0
    except urllib.error.HTTPError as e:
        msg = f"HTTP {e.code} from {url}" + (' (the server refused this user agent or the file is private)' if e.code == 403 else '') \
              + (' (no such file on the server: check the name with the TPRFN admin)' if e.code == 404 else '')
    except (urllib.error.URLError, OSError, ValueError) as e:
        msg = f"{url}: {getattr(e, 'reason', e)}"
    log("ERROR: " + msg + " — kept the last good copy")
    write_status(ok=False, error=msg, url=url, file=dest)
    return 1


if __name__ == '__main__':
    sys.exit(main())
