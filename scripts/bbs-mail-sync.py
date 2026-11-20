#!/usr/bin/env python3
"""
bbs-mail-sync.py — copy BBS mail into the IMAP mail store (BPQ Dashboard v1.5.67; read marks v1.5.68; Trash kills v1.5.70)

OPTIONAL. Set up with:  sudo bash <web root>/tools/bbs-mail-setup.sh

The dashboard already keeps a copy of every BBS message it lists, with its
text, in the bpq_messages table (scripts/fetch-message-bodies.php, every 5
minutes). This script copies those messages into a Dovecot mail store, so
any mail app (Thunderbird, a phone) and later the dashboard's Email Client
can read them with folders, read flags and search:

    INBOX              personal and NTS mail to the station (station.callsign,
                       or SYSOP)
    Sent               personal and NTS mail from the station
    Bulletins/<GROUP>  bulletins, one folder per group (WX, SITREP, NEWS ...)

Each message is delivered once (table bpq_mail_sync), through Dovecot's own
delivery program (dovecot-lda), which creates the folders as needed. A
message whose text the dashboard hasn't saved yet waits up to
`wait_for_text_hours` (default 48), then is delivered with a note.
Bulletins older than `bulletin_days` (default 90) are not copied, and once
a day are removed from the mail store; the BBS and the dashboard keep their
own copies. Personal mail is never removed by this script.

The BBS stays the master copy: nothing here changes the BBS.

Settings (config.php, all optional):
    'bbs_mail' => [
        'enabled'             => true,   // set by the setup script
        'user'                => 'k1ajd',// the mail login (default: station callsign, lower case)
        'bulletin_days'       => 90,     // keep bulletins this many days in the mail store
        'wait_for_text_hours' => 48,
        'bulletins'           => true,   // false = personal mail only
    ],

Run as root (the systemd timer bbs-mail-sync.timer does, every 2 minutes):
    bbs-mail-sync.py              normal run
    bbs-mail-sync.py --dry-run    what would be copied, copies nothing
    bbs-mail-sync.py --status     counts per folder, last copy, what's waiting
    bbs-mail-sync.py --limit N    at most N messages this run
    bbs-mail-sync.py --expire     remove bulletins older than bulletin_days now
    bbs-mail-sync.py --no-reads   copy only, leave read marks alone this run

Read marks (v1.5.68): a message read in a mail app is marked read in the
Email Client (bpq_messages.is_read) and the other way round, each run.

Filters (v1.5.73): BBS Mail's Message Filters (bpq_mail_filters) decide the
folder for new mail (and can mark it read or star it) as it is copied in.

Trash (v1.5.70): a message that leaves the mail store's Trash (emptied in a
mail app, or by the daily 30-day clean-up) is queued in bpq_bbs_kills and
killed on the BBS by fetch-message-bodies.php's next session. Emptying Trash
in the Email Client queues them straight away.
"""
import argparse
import datetime as dt
import email.policy
import email.utils
import fcntl
import json
import os
import re
import shutil
import subprocess
import sys
from email.message import EmailMessage

try:
    import pymysql
except ImportError:
    pymysql = None

_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _HERE)
import bpq_db_config  # noqa: E402

CFG = bpq_db_config.load_config_section('bbs_mail') or {}
STATION = (bpq_db_config.load_config_section('station') or {}).get('callsign', '') or ''
STATION = str(STATION).strip().upper()
MAIL_USER = str(CFG.get('user') or STATION.lower() or 'bbs').strip()
BULLETIN_DAYS = int(CFG.get('bulletin_days', 90) or 90)
WAIT_HOURS = int(CFG.get('wait_for_text_hours', 48) or 48)
WANT_BULLETINS = CFG.get('bulletins', True) not in (False, 0, '0', 'false', 'no')
DOMAIN = 'bbs.invalid'          # never routable: these addresses can't leak to the internet
LDA_PATHS = ['/usr/lib/dovecot/dovecot-lda', '/usr/libexec/dovecot/dovecot-lda']
STATE_DIR = '/var/lib/bpq-dashboard'
LOCK = os.path.join(STATE_DIR, 'bbs-mail-sync.lock')
STATE = os.path.join(STATE_DIR, 'bbs-mail-sync.json')

HDR_KEYS = ('from', 'to', 'type/status', 'date/time', 'bid', 'title')


LOG_TO_FILE = [False]


def log(msg):
    line = dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%d %H:%M:%SZ') + ' ' + msg
    print(line, flush=True)
    if LOG_TO_FILE[0]:                 # Log Viewer -> BBS mail store copy
        try:
            with open(bpq_db_config.app_log_path('bbs-mail-sync.log'), 'a') as f:
                f.write(line + '\n')
        except OSError:
            pass


def lda_path():
    for p in LDA_PATHS:
        if os.path.exists(p):
            return p
    return shutil.which('dovecot-lda')


ACCOUNTS = {}   # v1.5.77: extra BBS users whose mail is copied too: CALL -> folder


def load_accounts(conn):
    """Extra accounts (BBS Mail → Settings → Accounts, bpq_mail_prefs 'accounts';
    or config.php 'bbs_mail' => ['accounts' => ['TPRFN', ...]]): mail to them
    goes into their own folder (INBOX/<CALL> unless another is chosen)."""
    acc = {}
    for c in (CFG.get('accounts') or []):
        c = re.sub(r'[^A-Z0-9/-]', '', str(c).upper())
        if c:
            acc[c] = 'INBOX/' + c
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_prefs'")
            if cur.fetchone()['n']:
                cur.execute("SELECT value FROM bpq_mail_prefs WHERE name = 'accounts'")
                r = cur.fetchone()
                for a in ((json.loads(r['value']) or {}).get('accounts') or []) if r else []:
                    c = re.sub(r'[^A-Z0-9/-]', '', str(a.get('call', '')).upper())
                    f = str(a.get('folder') or '').strip()
                    if c and c not in (STATION, 'SYSOP'):
                        acc[c] = f if f and not re.search(r'[\x00-\x1f*%]', f) else 'INBOX/' + c
    except Exception as e:          # accounts are optional: never stop the copier
        log(f'accounts not read: {e}')
    ACCOUNTS.clear()
    ACCOUNTS.update(acc)


def mailbox_for(row):
    """INBOX / Sent / Bulletins/<GROUP> / an extra account's folder, or None to skip."""
    t = (row['msg_type'] or 'P').upper()
    to_ = (row['to_call'] or '').upper().split('@')[0]
    frm = (row['from_call'] or '').upper()
    base = lambda c: re.sub(r'-\d+$', '', c)
    if t == 'B':
        if not WANT_BULLETINS:
            return None
        grp = re.sub(r'[^A-Z0-9_-]', '', to_)[:30] or 'OTHER'
        return 'Bulletins/' + grp
    mine = {STATION, 'SYSOP'} if STATION else {'SYSOP'}
    if base(to_) in mine:
        return 'INBOX'
    if base(to_) in ACCOUNTS:
        return ACCOUNTS[base(to_)]
    if (STATION and base(frm) == STATION) or base(frm) in ACCOUNTS:
        return 'Sent'
    return None            # traffic passing through for other stations


def split_bbs_text(text):
    """BPQ's R <n> reply starts with From:/To:/Type/Status:/Date/Time:/Bid:/Title:
    lines. Return (header dict, message text without them and without the
    closing '[End of Message #n from X]' line)."""
    lines = (text or '').replace('\r\n', '\n').replace('\r', '\n').split('\n')
    hdr, last = {}, -1
    for n, line in enumerate(lines[:25]):
        m = re.match(r'^[\s>|]*([A-Za-z/]+):\s*(.*)$', line)
        if m and m.group(1).lower() in HDR_KEYS:
            hdr.setdefault(m.group(1).lower(), m.group(2).strip())
            last = n
        elif hdr and line.strip():
            break                      # first text line after the header block
    i = last + 1
    if i < len(lines) and hdr and not lines[i].strip():
        i += 1                         # the blank line after the header
    body = lines[i:]
    while body and not body[0].strip():
        body.pop(0)
    while body and (not body[-1].strip() or re.match(r'^\s*\[End of Message #\d+', body[-1])):
        body.pop()
    return hdr, '\n'.join(body)


def bbs_date(hdr, row):
    """Date/Time: 18-Nov 12:37Z -> aware datetime (year from first_seen_at)."""
    seen = (row['first_seen_at'] or dt.datetime.now()).astimezone(dt.timezone.utc)   # local -> UTC
    m = re.match(r'(\d{1,2})-([A-Za-z]{3})\s+(\d{1,2}):(\d{2})', hdr.get('date/time', '') or '')
    if m:
        try:
            mon = dt.datetime.strptime(m.group(2).title(), '%b').month
            d = dt.datetime(seen.year, mon, int(m.group(1)), int(m.group(3)), int(m.group(4)), tzinfo=dt.timezone.utc)
            if d - dt.timedelta(days=200) > seen:   # December message seen in January
                d = d.replace(year=seen.year - 1)
            return d
        except ValueError:
            pass
    return seen


# v1.5.77: Winlink and internet addresses. BPQ writes the sender of mail
# collected from Winlink as RMS:user@domain (internet mail through Winlink)
# and keeps SMTP:user@domain for its own internet gateway; a Winlink user is
# CALL@winlink.org. These keep their real address in the name shown, and get
# an unroutable address of their own.
RE_NET = re.compile(r'^(RMS|SMTP):\s*([^\s@]+@[^\s@]+)$', re.I)


def is_winlink(a):
    a = (a or '').strip()
    return bool(RE_NET.match(a)) or a.lower().endswith('@winlink.org')


def addr(call, label=None):
    raw = (call or '').strip()
    m = RE_NET.match(raw)
    if m:
        who = m.group(2)
        local = re.sub(r'[^A-Za-z0-9._-]', '.', who.replace('@', '.'))[:60].strip('.') or 'unknown'
        how = 'Winlink' if m.group(1).upper() == 'RMS' else 'internet'
        return email.utils.formataddr((label or f'{who} ({how})', f'{m.group(1).lower()}.{local}@{DOMAIN}'))
    if raw.lower().endswith('@winlink.org'):
        c = re.sub(r'[^A-Za-z0-9._-]', '', raw.split('@')[0]) or 'UNKNOWN'
        return email.utils.formataddr((label or f'{c.upper()}@winlink.org', f'{c.upper()}@{DOMAIN}'))
    call = re.sub(r'[^A-Za-z0-9._-]', '', (raw or 'UNKNOWN').split('@')[0]) or 'UNKNOWN'
    return email.utils.formataddr((label or call.upper(), f'{call.upper()}@{DOMAIN}'))


def message_id(row, hdr=None):
    """The copy's Message-ID: <BID@bbs.invalid>, or <number.type.from@bbs.invalid>
    when the text (with its Bid: line) wasn't there."""
    if hdr is None:
        hdr = split_bbs_text(row['body'] or '')[0]
    t = (row['msg_type'] or 'P').upper()
    bid = re.sub(r'[^A-Za-z0-9_.-]', '', hdr.get('bid', '') or '')
    ident = bid or f'{row["bbs_number"]}.{t}.{re.sub(r"[^A-Za-z0-9]", "", row["from_call"] or "")}'
    return f'<{ident}@{DOMAIN}>'


def build(row, has_text):
    hdr, text = split_bbs_text(row['body'] or '')
    t = (row['msg_type'] or 'P').upper()
    msg = EmailMessage(policy=email.policy.SMTP)
    frm = hdr.get('from') or row['from_call'] or 'UNKNOWN'
    to_ = hdr.get('to') or row['to_call'] or ''
    msg['From'] = addr(frm)
    if t == 'B':
        msg['To'] = addr(to_.split('@')[0], f'{to_} (bulletin)')
    else:
        msg['To'] = addr(to_)
    msg['Subject'] = (hdr.get('title') or row['subject'] or 'No Subject')[:300]
    msg['Date'] = email.utils.format_datetime(bbs_date(hdr, row))
    bid = re.sub(r'[^A-Za-z0-9_.-]', '', hdr.get('bid', '') or '')
    msg['Message-ID'] = message_id(row, hdr)
    msg['X-BBS-Number'] = str(row['bbs_number'] or '')
    msg['X-BBS-Type'] = {'P': 'Personal', 'T': 'NTS traffic', 'B': 'Bulletin'}.get(t, t)
    if bid:
        msg['X-BBS-BID'] = bid
    if hdr.get('type/status'):
        msg['X-BBS-Status'] = hdr['type/status']
    # v1.5.77: the BBS's own sender and recipient (BBS Mail replies to these),
    # and a Winlink mark
    msg['X-BBS-From'] = re.sub(r'[\r\n]', '', frm)[:120]
    msg['X-BBS-To'] = re.sub(r'[\r\n]', '', to_)[:120]
    if t != 'B' and (is_winlink(frm) or is_winlink(to_)):
        msg['X-BBS-Via'] = 'Winlink'
    if not has_text:
        text = ('(The dashboard has not saved the text of this message. '
                'Open it on the BBS: R ' + str(row['bbs_number'] or '') + ')')
    msg.set_content(text + '\n', charset='utf-8')
    return msg


def connect():
    if pymysql is None:
        sys.exit('ERROR: python3-pymysql is not installed (apt install python3-pymysql)')
    cfg = bpq_db_config.load_db_config({'charset': 'utf8mb4', 'cursorclass': pymysql.cursors.DictCursor,
                                         'autocommit': True})
    return pymysql.connect(**cfg)


def table_ready(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() "
                    "AND TABLE_NAME = 'bpq_mail_sync'")
        return cur.fetchone()['n'] > 0


def candidates(conn, limit):
    """Messages not yet in the mail store: all personal/NTS mail, bulletins
    first seen within bulletin_days."""
    sql = ("SELECT m.id, m.bbs_number, m.msg_type, m.from_call, m.to_call, m.subject, m.body, "
           "m.first_seen_at, (m.body IS NOT NULL AND m.body <> '') AS has_text "
           "FROM bpq_messages m LEFT JOIN bpq_mail_sync s ON s.message_id = m.id "
           "WHERE s.message_id IS NULL AND (m.msg_type <> 'B' OR m.first_seen_at >= NOW() - INTERVAL %s DAY) "
           "ORDER BY m.id LIMIT %s")
    with conn.cursor() as cur:
        cur.execute(sql, (BULLETIN_DAYS, limit))
        return cur.fetchall()


def deliver(lda, mailbox, msg):
    p = subprocess.run([lda, '-d', MAIL_USER, '-m', mailbox,
                        '-o', 'lda_mailbox_autocreate=yes', '-o', 'lda_mailbox_autosubscribe=yes'],
                       input=msg.as_bytes(), capture_output=True, timeout=60)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout or b'').decode('utf-8', 'replace').strip()[:300]
                           or f'dovecot-lda exit {p.returncode}')


def load_state():
    try:
        with open(STATE) as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def save_state(st):
    try:
        os.makedirs(STATE_DIR, exist_ok=True)
        tmp = STATE + '.tmp'
        with open(tmp, 'w') as f:
            json.dump(st, f, indent=1)
        os.replace(tmp, STATE)
    except OSError:
        pass


def load_retention(conn):
    """v1.5.74: BBS Mail → Settings / Folder Properties → Retention (bpq_mail_prefs).
    {default_days, keep_starred, folders}; config.php's bulletin_days is the default."""
    pol = {'default_days': BULLETIN_DAYS, 'keep_starred': True, 'folders': {}}
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() "
                        "AND TABLE_NAME = 'bpq_mail_prefs'")
            if not cur.fetchone()['n']:
                return pol
            cur.execute("SELECT value FROM bpq_mail_prefs WHERE name = 'retention'")
            r = cur.fetchone()
        j = json.loads(r['value']) if r and r['value'] else None
    except Exception:
        return pol
    if isinstance(j, dict):
        pol['default_days'] = max(0, min(3650, int(j.get('default_days', BULLETIN_DAYS) or 0)))
        pol['keep_starred'] = bool(j.get('keep_starred', True))
        pol['folders'] = {str(k): max(0, min(3650, int(v or 0))) for k, v in (j.get('folders') or {}).items()}
    return pol


def retention_days(pol, folder):
    if folder in pol['folders']:
        return pol['folders'][folder] or None
    if folder.startswith('Bulletins/'):
        return pol['default_days'] or None
    return None


def expire(dry, pol):
    """Once a day: delete messages past their folder's retention (by the
    message's own date; starred kept unless told otherwise), and Trash after
    30 days. Mail-store copies only — nothing on the BBS."""
    doveadm = shutil.which('doveadm')
    if not doveadm:
        return
    p = subprocess.run([doveadm, 'mailbox', 'list', '-u', MAIL_USER], capture_output=True, text=True, timeout=120)
    total = 0
    for box in (p.stdout or '').splitlines():
        box = box.strip()
        days = retention_days(pol, box) if box and box != 'Trash' else None
        if not days:
            continue
        # by calendar date, the same as BBS Mail's "Run now" (IMAP SENTBEFORE)
        cutoff = (dt.date.today() - dt.timedelta(days=days)).isoformat()
        query = ['mailbox', box, 'sentbefore', cutoff] + (['NOT', 'FLAGGED'] if pol['keep_starred'] else [])
        if dry:
            log('would run: doveadm expunge -u ' + MAIL_USER + ' ' + ' '.join(query))
            continue
        c = subprocess.run([doveadm, '-f', 'tab', 'search', '-u', MAIL_USER] + query, capture_output=True, text=True, timeout=300)
        n = max(0, len([l for l in c.stdout.splitlines() if l.strip()]) - 1) if c.returncode == 0 else 0
        if not n:
            continue
        r = subprocess.run([doveadm, 'expunge', '-u', MAIL_USER] + query, capture_output=True, text=True, timeout=600)
        if r.returncode == 0:
            total += n
        else:
            log(f'retention {box}: {r.stderr.strip()[:200]}')
    if not dry:
        log(f'retention: removed {total} message(s) past their folder\'s retention'
            f' (bulletins {pol["default_days"] or "never"} days by default)')
        # v1.5.68: the mail store's Trash after 30 days
        subprocess.run([doveadm, 'expunge', '-u', MAIL_USER, 'mailbox', 'Trash', 'savedbefore', '30d'],
                       capture_output=True, text=True, timeout=600)


# ── message filters (v1.5.73): BBS Mail → Message Filters, bpq_mail_filters ──
def load_filters(conn):
    """Enabled filters that run on new mail, in order. [] when the table is missing."""
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() "
                    "AND TABLE_NAME = 'bpq_mail_filters'")
        if not cur.fetchone()['n']:
            return []
        cur.execute("SELECT * FROM bpq_mail_filters WHERE enabled = 1 AND on_new = 1 ORDER BY position, id")
        out = []
        for r in cur.fetchall():
            try:
                conds = json.loads(r['conditions'] or '[]')
                acts = json.loads(r['actions'] or '{}')
            except ValueError:
                continue
            if conds:
                out.append({'id': r['id'], 'name': r['name'], 'match_all': bool(r['match_all']), 'conditions': conds,
                            'actions': {'move': (acts.get('move') or '').strip(), 'read': bool(acts.get('read')),
                                        'star': bool(acts.get('star')), 'stop': bool(acts.get('stop'))}})
        return out


def filter_matches(f, m):
    """Same rules as bpq_mf_matches() in includes/mail-filters-actions.php."""
    results = []
    for c in f['conditions']:
        field, op, needle = c.get('field'), c.get('op'), str(c.get('value') or '')
        hay = str(m.get(field) or '')
        if field in ('from', 'to', 'group'):
            hay, needle = hay.upper(), needle.upper()
        else:
            hay, needle = hay.lower(), needle.lower()
        base = re.sub(r'[-@].*$', '', hay)
        if op == 'contains':
            ok = needle != '' and needle in hay
        elif op == 'not_contains':
            ok = needle not in hay
        elif op == 'is':
            ok = hay == needle or (field in ('from', 'to') and base == needle)
        elif op == 'is_not':
            ok = not (hay == needle or (field in ('from', 'to') and base == needle))
        elif op == 'begins':
            ok = needle != '' and hay.startswith(needle)
        elif op == 'ends':
            ok = needle != '' and hay.endswith(needle)
        else:
            ok = False
        results.append(ok)
        if f['match_all'] and not ok:
            return False
        if not f['match_all'] and ok:
            return True
    return bool(results) if f['match_all'] else False


def apply_filters(filters, row, box):
    """-> (mailbox, read, star, [filter ids that matched]) for a new message."""
    if not filters or box == 'Sent':
        return box, False, False, []
    hdr, text = split_bbs_text(row['body'] or '')
    t = (row['msg_type'] or 'P').upper()
    to_full = hdr.get('to') or row['to_call'] or ''
    m = {'from': hdr.get('from') or row['from_call'] or '', 'to': to_full,
         'subject': hdr.get('title') or row['subject'] or '', 'body': text,
         'type': {'B': 'Bulletin', 'T': 'NTS'}.get(t, 'Personal'),
         'group': to_full.split('@')[0] if t == 'B' else ''}
    read = star = False
    hit = []
    for f in filters:
        if not filter_matches(f, m):
            continue
        hit.append(f['id'])
        a = f['actions']
        read = read or a['read']
        star = star or a['star']
        if a['move']:
            return a['move'][:200], read, star, hit
        if a['stop']:
            break
    return box, read, star, hit


def flag_new(mailbox, msgid, read, star, dry):
    if dry or not (read or star):
        return
    doveadm = shutil.which('doveadm')
    for on, flag in ((read, '\\Seen'), (star, '\\Flagged')):
        if on:
            subprocess.run([doveadm, 'flags', 'add', '-u', MAIL_USER, flag, 'mailbox', mailbox, 'header', 'Message-ID', msgid],
                           capture_output=True, text=True, timeout=60)


READ_COLS = [False]     # bpq_mail_sync has msgid / seen_synced / read_changed_at (v1.5.68 update)
KILL_TABLE = [False]    # bpq_bbs_kills exists (v1.5.70 update)


def kill_table_ready(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() "
                    "AND TABLE_NAME = 'bpq_bbs_kills'")
        return cur.fetchone()['n'] > 0


def read_cols_ready(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
                    "AND TABLE_NAME = 'bpq_mail_sync' AND COLUMN_NAME IN ('msgid', 'seen_synced', 'read_changed_at')")
        return cur.fetchone()['n'] == 3


def backfill_msgids(conn, dry, limit=5000):
    """Copies made before v1.5.68 have no msgid noted: work it out the same
    way it was made (number-based when the text wasn't there yet)."""
    with conn.cursor() as cur:
        cur.execute("SELECT s.message_id, s.had_text, m.bbs_number, m.msg_type, m.from_call, m.body "
                    "FROM bpq_mail_sync s JOIN bpq_messages m ON m.id = s.message_id "
                    "WHERE s.msgid IS NULL AND s.mailbox <> '-' LIMIT %s", (limit,))
        rows = cur.fetchall()
        for r in rows:
            mid = message_id(r, {} if not r['had_text'] else None)
            if not dry:
                cur.execute("UPDATE bpq_mail_sync SET msgid = %s WHERE message_id = %s", (mid, r['message_id']))
    return len(rows)


def store_flags():
    """msgid -> (mailbox, uid, seen) for every message in the mail store."""
    doveadm = shutil.which('doveadm')
    if not doveadm:
        raise RuntimeError('doveadm not found')
    p = subprocess.run([doveadm, '-f', 'tab', 'fetch', '-u', MAIL_USER, 'mailbox uid flags hdr.message-id', 'all'],
                       capture_output=True, text=True, timeout=600)
    if p.returncode != 0:
        raise RuntimeError('doveadm fetch: ' + (p.stderr.strip()[:200] or f'exit {p.returncode}'))
    out = {}
    for line in p.stdout.splitlines()[1:]:
        parts = line.split('\t')
        if len(parts) < 4:
            continue
        box, uid, flags, mid = parts[0], parts[1], parts[2], parts[3].strip()
        if not mid or not uid.isdigit():
            continue
        seen = '\\Seen' in flags.split()
        prev = out.get(mid)
        # the same message in two folders (a copy made in the mail app): read if either is
        if prev is None or (seen and not prev[2]):
            out[mid] = (box, int(uid), seen)
    return out


def set_flags(seen, items, dry):
    """items: [(mailbox, uid)] -> add or remove \\Seen, per folder, in chunks."""
    doveadm = shutil.which('doveadm')
    per_box = {}
    for box, uid in items:
        per_box.setdefault(box, []).append(uid)
    for box, uids in per_box.items():
        for i in range(0, len(uids), 400):
            chunk = ','.join(str(u) for u in uids[i:i + 400])
            cmd = [doveadm, 'flags', 'add' if seen else 'remove', '-u', MAIL_USER, '\\Seen', 'mailbox', box, 'uid', chunk]
            if dry:
                continue
            p = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
            if p.returncode != 0:
                raise RuntimeError(f'doveadm flags {box}: ' + (p.stderr.strip()[:200] or f'exit {p.returncode}'))


def sync_reads(conn, dry):
    """Keep read marks in step: bpq_messages.is_read (the Email Client) <->
    \\Seen in the mail store (mail apps). seen_synced is what both sides last
    agreed on; whichever side changed since wins (read wins if both did)."""
    flags = store_flags()
    with conn.cursor() as cur:
        cur.execute("SELECT s.message_id, s.msgid, s.mailbox, s.seen_synced, m.is_read, "
                    "m.bbs_number, m.msg_type, m.from_call, m.subject "
                    "FROM bpq_mail_sync s JOIN bpq_messages m ON m.id = s.message_id "
                    "WHERE s.msgid IS NOT NULL AND s.mailbox NOT IN ('-', '(killed)', '(gone)')")
        rows = cur.fetchall()
    if rows and not flags:
        # an empty answer from a mail store that should hold mail: never take
        # that as "everything was deleted" (it would kill Trash on the BBS)
        raise RuntimeError('the mail store listed no messages at all; read marks and Trash left alone this run')
    to_seen, to_unseen, db_read, db_unread, agreed, moved, kills, gone = [], [], [], [], {}, [], [], []
    for r in rows:
        f = flags.get(r['msgid'])
        if f is None:
            # v1.5.70: gone from Trash (emptied in a mail app, or the 30-day
            # clean-up) = kill it on the BBS; gone from anywhere else
            # (bulletin expiry, deleted outright in a mail app) = just gone.
            if r['mailbox'] == 'Trash' and r['bbs_number']:
                kills.append(r)
            else:
                gone.append(r['message_id'])
            continue
        box, uid, seen = f
        dbr = bool(r['is_read'])
        last = r['seen_synced']
        if box != r['mailbox']:
            moved.append((box, r['message_id']))
        if last is None:                   # first time: read on either side wins
            want = dbr or seen
        else:
            last = bool(last)
            if seen != last and dbr == last:
                want = seen                # changed in the mail app
            elif dbr != last and seen == last:
                want = dbr                 # changed in the dashboard
            else:
                want = dbr or seen
        if seen != want:
            (to_seen if want else to_unseen).append((box, uid))
        if dbr != want:
            (db_read if want else db_unread).append(r['message_id'])
        if last is None or bool(last) != want:
            agreed[r['message_id']] = int(want)
    set_flags(True, to_seen, dry)
    set_flags(False, to_unseen, dry)
    if not dry:
        with conn.cursor() as cur:
            for ids, val in ((db_read, 1), (db_unread, 0)):
                for i in range(0, len(ids), 500):
                    chunk = ids[i:i + 500]
                    ph = ','.join(['%s'] * len(chunk))
                    cur.execute(f"UPDATE bpq_messages SET is_read = {val} WHERE id IN ({ph})", chunk)
                    cur.execute(f"UPDATE bpq_mail_sync SET read_changed_at = NOW() WHERE message_id IN ({ph})", chunk)
            for val in (0, 1):
                ids = [k for k, v in agreed.items() if v == val]
                for i in range(0, len(ids), 500):
                    chunk = ids[i:i + 500]
                    ph = ','.join(['%s'] * len(chunk))
                    cur.execute(f"UPDATE bpq_mail_sync SET seen_synced = {val} WHERE message_id IN ({ph})", chunk)
            for box, mid in moved:
                cur.execute("UPDATE bpq_mail_sync SET mailbox = %s WHERE message_id = %s", (box[:120], mid))
            if kills and KILL_TABLE[0]:
                for r in kills:
                    cur.execute("INSERT IGNORE INTO bpq_bbs_kills (message_id, bbs_number, msg_type, from_call, subject, source) "
                                "VALUES (%s, %s, %s, %s, %s, 'mail-app')",
                                (r['message_id'], r['bbs_number'], (r['msg_type'] or 'P')[:1], (r['from_call'] or '')[:64],
                                 (r['subject'] or '')[:500]))
                    cur.execute("UPDATE bpq_mail_sync SET mailbox = '(killed)' WHERE message_id = %s", (r['message_id'],))
                log(f"{len(kills)} message(s) left the mail store's Trash: queued to be killed on the BBS")
            for i in range(0, len(gone), 500):
                chunk = gone[i:i + 500]
                ph = ','.join(['%s'] * len(chunk))
                cur.execute(f"UPDATE bpq_mail_sync SET mailbox = '(gone)' WHERE message_id IN ({ph})", chunk)
    return len(to_seen), len(to_unseen), len(db_read), len(db_unread)


def status(conn):
    with conn.cursor() as cur:
        cur.execute("SELECT mailbox, COUNT(*) AS n, MAX(delivered_at) AS last FROM bpq_mail_sync GROUP BY mailbox "
                    "ORDER BY (mailbox = 'INBOX') DESC, (mailbox = 'Sent') DESC, n DESC")
        rows = cur.fetchall()
    other = sum(r['n'] for r in rows if r['mailbox'] == '-')
    killed = sum(r['n'] for r in rows if r['mailbox'] == '(killed)')
    gone = sum(r['n'] for r in rows if r['mailbox'] == '(gone)')
    rows = [r for r in rows if r['mailbox'] not in ('-', '(killed)', '(gone)')]
    total = sum(r['n'] for r in rows)
    print(f'Mail store user: {MAIL_USER}   station: {STATION or "?"}   bulletins kept {BULLETIN_DAYS} days')
    print(f'Copied so far: {total} message(s) in {len(rows)} folder(s)'
          + (f'; {other} passing through for other stations not copied' if other else ''))
    if killed or gone:
        print(f'Removed since: {killed} from Trash (killed on the BBS too), {gone} expired or deleted in a mail app')
    if KILL_TABLE[0]:
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS n FROM bpq_bbs_kills WHERE done_at IS NULL")
            n = cur.fetchone()['n']
        if n:
            print(f'Waiting to be killed on the BBS: {n} (done on the next message-text run, every 5 minutes)')
    for r in rows[:40]:
        print(f'  {r["mailbox"]:<28} {r["n"]:>6}   last {r["last"]}')
    if len(rows) > 40:
        print(f'  … and {len(rows) - 40} more folders')
    load_accounts(conn)
    if ACCOUNTS:
        print('Extra accounts: ' + ', '.join(f'{c} → {f}' for c, f in sorted(ACCOUNTS.items())))
    waiting = [r for r in candidates(conn, 5000) if mailbox_for(r)]
    nt = sum(1 for r in waiting if not r['has_text'])
    print(f'Waiting: {len(waiting)} message(s), {nt} of them for their text')
    st = load_state()
    if st:
        print(f'Last run: {st.get("last_run", "?")}   last error: {st.get("last_error") or "none"}')


def main():
    ap = argparse.ArgumentParser(description='Copy BBS mail into the IMAP mail store')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--status', action='store_true')
    ap.add_argument('--limit', type=int, default=2000)
    ap.add_argument('--expire', action='store_true', help='remove old bulletins from the mail store now')
    ap.add_argument('--no-reads', action='store_true', help="don't sync read marks this run")
    args = ap.parse_args()

    if CFG.get('enabled') in (False, 0, '0', 'false', 'no') and not (args.status or args.dry_run):
        log("bbs_mail.enabled is false in config.php: nothing to do")
        return
    if not STATION:
        sys.exit('ERROR: station.callsign is not set in config.php')

    conn = connect()
    if not table_ready(conn):
        sys.exit('ERROR: the bpq_mail_sync table is missing: run update.sh (or sql/2026-11-18-bbs-mail-sync.sql)')
    READ_COLS[0] = read_cols_ready(conn)
    KILL_TABLE[0] = kill_table_ready(conn)
    if args.status:
        status(conn)
        return

    lda = lda_path()
    if not lda and not args.dry_run:
        sys.exit('ERROR: dovecot-lda not found: run tools/bbs-mail-setup.sh')

    os.makedirs(STATE_DIR, exist_ok=True)
    lockf = open(LOCK, 'w')
    try:
        fcntl.flock(lockf, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        log('another run is in progress')
        return

    LOG_TO_FILE[0] = True
    st = load_state()
    now = dt.datetime.now()          # first_seen_at is the database's local time
    done = skipped = waiting = filtered = 0
    hits = {}
    err = None
    filters = load_filters(conn)
    load_accounts(conn)
    with conn.cursor() as cur:
        for row in candidates(conn, max(1, args.limit)):
            box = mailbox_for(row)
            if box is None:
                skipped += 1
                if not args.dry_run:
                    cur.execute("INSERT IGNORE INTO bpq_mail_sync (message_id, mailbox, had_text) VALUES (%s, '-', %s)",
                                (row['id'], int(bool(row['has_text']))))
                continue
            has_text = bool(row['has_text'])
            if not has_text and row['first_seen_at'] and (now - row['first_seen_at']).total_seconds() < WAIT_HOURS * 3600:
                waiting += 1
                continue
            box, f_read, f_star, f_hit = apply_filters(filters, row, box)
            if f_hit:
                filtered += 1
            if args.dry_run:
                log(f'would copy #{row["bbs_number"]} {row["msg_type"]} {row["from_call"]}->{row["to_call"]} '
                    f'into {box}{"" if has_text else " (no text)"}: {(row["subject"] or "")[:50]}'
                    + (f' [filter {",".join(str(i) for i in f_hit)}{" read" if f_read else ""}{" star" if f_star else ""}]' if f_hit else ''))
                done += 1
                continue
            try:
                msg = build(row, has_text)
                deliver(lda, box, msg)
                if f_hit:
                    flag_new(box, str(msg['Message-ID']), f_read, f_star, False)
                    for fid in f_hit:
                        hits[fid] = hits.get(fid, 0) + 1
            except Exception as e:          # keep going; report the first error
                err = err or f'#{row["bbs_number"]} into {box}: {e}'
                continue
            if READ_COLS[0]:
                cur.execute("INSERT IGNORE INTO bpq_mail_sync (message_id, mailbox, msgid, had_text) VALUES (%s, %s, %s, %s)",
                            (row['id'], box, str(msg['Message-ID']), int(has_text)))
            else:
                cur.execute("INSERT IGNORE INTO bpq_mail_sync (message_id, mailbox, had_text) VALUES (%s, %s, %s)",
                            (row['id'], box, int(has_text)))
            done += 1

    if done or err or args.dry_run:
        log(f'{"would copy" if args.dry_run else "copied"} {done}, skipped {skipped} (not to or from {STATION}{" or " + ", ".join(sorted(ACCOUNTS)) if ACCOUNTS else ""}), '
            f'{waiting} waiting for their text' + (f', {filtered} sorted by filters' if filtered else '')
            + (f'; ERROR {err}' if err else ''))
    if hits and not args.dry_run:
        with conn.cursor() as cur:
            for fid, n in hits.items():
                cur.execute("UPDATE bpq_mail_filters SET hits = hits + %s, last_hit_at = NOW() WHERE id = %s", (n, fid))

    # v1.5.68: read marks both ways (needs the update's new columns)
    if READ_COLS[0] and not args.no_reads:
        try:
            nb = backfill_msgids(conn, args.dry_run)
            a, b, c, d = sync_reads(conn, args.dry_run)
            if a or b or c or d or nb or args.dry_run:
                log(f'read marks{" (dry run)" if args.dry_run else ""}: mail store {a} read, {b} unread; '
                    f'Email Client {c} read, {d} unread' + (f'; {nb} older copies matched' if nb else ''))
        except Exception as e:
            err = err or f'read marks: {e}'
            log(f'ERROR read marks: {e}')

    day = now.strftime('%Y-%m-%d')
    if args.expire or (not args.dry_run and st.get('expired_day') != day and now.hour >= 4):
        expire(args.dry_run, load_retention(conn))
        st['expired_day'] = day
    if not args.dry_run:
        st.update({'last_run': now.strftime('%Y-%m-%d %H:%M:%S'), 'last_run_ts': int(now.timestamp()), 'last_copied': done,
                   'last_error': err, 'user': MAIL_USER})
        if done:
            st['last_copy'] = st['last_run']
        save_state(st)
    if err:
        sys.exit(1)


if __name__ == '__main__':
    main()
