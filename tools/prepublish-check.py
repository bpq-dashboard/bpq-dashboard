#!/usr/bin/env python3
"""
prepublish-check.py — scan what would be published before any `git push`.

    python3 tools/prepublish-check.py                        # scan this repo
    python3 tools/prepublish-check.py --secrets-from /var/www/bpq-dashboard
    python3 tools/prepublish-check.py --list                 # just list files scanned

Exit code 0 = clean (warnings allowed), 1 = something must be fixed first.

WHAT IS SCANNED
  Exactly the files Git would publish: `git ls-files --cached --others
  --exclude-standard` inside a Git work tree; otherwise every file not
  excluded by the top-level .gitignore (simple patterns). Binary files are
  skipped.

WHAT BLOCKS (exit 1)
  - private keys (-----BEGIN ... PRIVATE KEY-----), common API tokens
  - password-like assignments with a real-looking value
    ('pass' => '...', password = "...", IDENTIFIED BY '...') unless the
    value is an obvious placeholder (CHANGE_THIS..., YOUR..., example...)
  - any term listed with a leading "!" in the local terms file
  - with --secrets-from DIR: the actual secret values found in
    DIR/config.php and DIR/db-config.php (keys named pass/password/secret/
    token/key), matched literally — never printed

WHAT WARNS (exit 0)
  - public IPv4 addresses, email addresses outside example/placeholder domains
  - terms listed without "!" in the local terms file (e.g. your callsign,
    which may appear on purpose in examples/credits)

LOCAL TERMS FILE (never published — keep it in a gitignored place)
  private/prepublish-terms.txt  (override with --terms FILE)
      # comment
      !mydomain.example      <- blocks
      MYCALL                 <- warns
  Matching is case-insensitive, plain substring.

SUPPRESSING A LINE
  Put `prepublish-ok` anywhere on a line to accept it deliberately.
"""
import argparse
import fnmatch
import ipaddress
import json
import os
import re
import shutil
import subprocess
import sys

PLACEHOLDER = re.compile(
    r'^(|x+|\*+|change[_-]?this.*|changeme|your.*|my.*password|password|pass|secret|'
    r'example.*|placeholder|redacted.*|\[redacted.*|<.*>|\$.*|\{.*\}|%s|none|null|'
    r'bpqcheck|admin|-1|test|dummy|sample|xxx.*)$', re.I)

PASS_ASSIGN = [
    re.compile(r"""(?<![\w-])['"]?(?:pass|passwd|password|passcode|db_pass|bbs_pass|pwd|secret|token|api_key)['"]?\s*(?:=>|=|:)\s*['"]([^'"\n]{3,})['"]""", re.I),
    re.compile(r"""\b[A-Z0-9_]*(?:PASS|PASSWD|PASSWORD|PASSCODE|SECRET|TOKEN|API_KEY)\b\s*=\s*['"]([^'"\n]{3,})['"]"""),
    re.compile(r"""IDENTIFIED\s+BY\s+['"]([^'"\n]+)['"]""", re.I),
    re.compile(r"""define\(\s*['"][A-Z0-9_]*PASS[A-Z0-9_]*['"]\s*,\s*['"]([^'"\n]+)['"]""", re.I),
]
TOKENS = [
    ('private key', re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY')),
    ('AWS key', re.compile(r'\bAKIA[0-9A-Z]{16}\b')),
    ('GitHub token', re.compile(r'\bgh[pousr]_[A-Za-z0-9]{30,}\b')),
    ('Slack token', re.compile(r'\bxox[abpr]-[A-Za-z0-9-]{10,}\b')),
]
IPV4 = re.compile(r'\b(?:\d{1,3}\.){3}\d{1,3}\b')
EMAIL = re.compile(r'\b[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b')
OK_EMAIL_DOMAINS = re.compile(r'(^|\.)(example\.(com|org|net)|example|localhost|local|domain\.com|'
                              r'domain\.tld|yourdomain\.\w+|test|routing|winlink\.org|'
                              # packet-radio BBS hierarchical addresses (CALL@CALL.GA.USA.NOAM)
                              r'NOAM|EURO|ASIA|AUST|SOAM|AFRI|MDEA|WW)$', re.I)
SUPPRESS = 'prepublish-ok'


def git_files(root):
    if not shutil.which('git'):
        return None
    r = subprocess.run(['git', '-C', root, 'rev-parse', '--is-inside-work-tree'],
                       capture_output=True, text=True)
    if r.returncode != 0 or r.stdout.strip() != 'true':
        return None
    r = subprocess.run(['git', '-C', root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
                       capture_output=True, text=True)
    return [f for f in r.stdout.split('\0') if f and os.path.isfile(os.path.join(root, f))]


def gitignore_files(root):
    """Fallback when not a Git repo: approximate the top-level .gitignore."""
    rules = []
    gi = os.path.join(root, '.gitignore')
    if os.path.isfile(gi):
        for line in open(gi, encoding='utf-8'):
            line = line.strip()
            if line and not line.startswith('#'):
                rules.append(line)

    def ignored(rel, is_dir):
        verdict = False
        for rule in rules:
            neg = rule.startswith('!')
            pat = rule[1:] if neg else rule
            dir_only = pat.endswith('/')
            pat = pat.rstrip('/')
            anchored = pat.startswith('/')
            pat = pat.lstrip('/')
            if dir_only and not is_dir:
                # a file is ignored if any parent dir matches
                parts = rel.split('/')
                hit = any(fnmatch.fnmatch('/'.join(parts[:i]), pat) if anchored or '/' in pat
                          else fnmatch.fnmatch(parts[i - 1], pat) for i in range(1, len(parts)))
            elif anchored or '/' in pat:
                hit = fnmatch.fnmatch(rel, pat)
            else:
                hit = fnmatch.fnmatch(os.path.basename(rel), pat)
            if hit:
                verdict = not neg
        return verdict

    out = []
    for dirpath, dirnames, filenames in os.walk(root):
        reldir = os.path.relpath(dirpath, root).replace(os.sep, '/')
        reldir = '' if reldir == '.' else reldir
        dirnames[:] = [d for d in dirnames if d != '.git'
                       and not ignored((reldir + '/' + d).lstrip('/'), True)]
        for f in filenames:
            rel = (reldir + '/' + f).lstrip('/')
            if not ignored(rel, False):
                out.append(rel)
    return sorted(out)


BINARY_EXT = ('.pdf', '.png', '.jpg', '.jpeg', '.gif', '.ico', '.webp', '.zip', '.gz', '.tgz',
              '.tar', '.woff', '.woff2', '.ttf', '.eot', '.mp3', '.wav', '.db', '.sqlite')


def is_binary(path):
    if path.lower().endswith(BINARY_EXT):
        return True
    with open(path, 'rb') as f:
        return b'\0' in f.read(8192)


def load_terms(path):
    block, warn = [], []
    if path and os.path.isfile(path):
        for line in open(path, encoding='utf-8'):
            line = line.rstrip('\n').strip()
            if not line or line.startswith('#'):
                continue
            (block if line.startswith('!') else warn).append(line.lstrip('!').strip().lower())
    return [t for t in block if t], [t for t in warn if t]


def load_secrets(src):
    """Secret values from src/config.php + src/db-config.php via the php CLI."""
    vals = set()
    php = shutil.which('php')
    if not php:
        sys.exit('--secrets-from needs the php command-line binary.')
    for name in ('config.php', 'db-config.php'):
        p = os.path.join(src, name)
        if not os.path.isfile(p):
            continue
        r = subprocess.run([php, '-r', 'echo json_encode(require $argv[1]);', '--', p],
                           capture_output=True, text=True)
        try:
            data = json.loads(r.stdout)
        except ValueError:
            print(f'  note: could not read {p}', file=sys.stderr)
            continue

        def walk(o, key=''):
            if isinstance(o, dict):
                for k, v in o.items():
                    walk(v, str(k))
            elif isinstance(o, list):
                for v in o:
                    walk(v, key)
            elif isinstance(o, str) and re.search(r'pass|pwd|secret|token|key', key, re.I):
                if len(o) >= 4 and not PLACEHOLDER.match(o):
                    vals.add(o)
        walk(data)
    return vals


def mask(s):
    return s[:1] + '*' * max(3, len(s) - 2) + s[-1:] if len(s) > 2 else '***'


def main():
    ap = argparse.ArgumentParser(description='Scan what would be published for secrets / private terms.')
    ap.add_argument('--root', default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                    help='repository root (default: parent of tools/)')
    ap.add_argument('--terms', help='terms file (default: <root>/private/prepublish-terms.txt)')
    ap.add_argument('--secrets-from', metavar='DIR', help='web root holding the LIVE config.php / db-config.php')
    ap.add_argument('--list', action='store_true', help='only list the files that would be scanned')
    args = ap.parse_args()

    root = os.path.abspath(args.root)
    files = git_files(root)
    mode = 'git'
    if files is None:
        files, mode = gitignore_files(root), '.gitignore'
    if args.list:
        print('\n'.join(files))
        print(f'\n{len(files)} files (selected via {mode})')
        return 0

    terms_path = args.terms or os.path.join(root, 'private', 'prepublish-terms.txt')
    block_terms, warn_terms = load_terms(terms_path)
    secrets = load_secrets(args.secrets_from) if args.secrets_from else set()
    here = os.path.abspath(__file__)

    blocks, warns = [], []
    for rel in files:
        path = os.path.join(root, rel)
        if os.path.abspath(path) == here or is_binary(path):
            continue
        try:
            lines = open(path, encoding='utf-8', errors='replace').read().splitlines()
        except OSError:
            continue
        low_rel = rel.lower()
        for t in block_terms:
            if t in low_rel:
                blocks.append((rel, 0, f'term in file name: "{t}"'))
        for n, line in enumerate(lines, 1):
            if SUPPRESS in line:
                continue
            low = line.lower()
            for name, rx in TOKENS:
                if rx.search(line):
                    blocks.append((rel, n, name))
            for rx in PASS_ASSIGN:
                for m in rx.finditer(line):
                    v = m.group(1).strip()
                    if not PLACEHOLDER.match(v) and not re.fullmatch(r'[A-Z_]+|\$\w+.*|.*\(.*|[/~.].*', v):
                        blocks.append((rel, n, f'password-like value {mask(v)}'))
            for s in secrets:
                if s in line:
                    blocks.append((rel, n, f'LIVE SECRET value {mask(s)}'))
            for t in block_terms:
                if t in low:
                    blocks.append((rel, n, f'blocked term "{t}"'))
            for t in warn_terms:
                if t in low:
                    warns.append((rel, n, f'term "{t}"'))
            # long lines (inline SVG paths, minified code) and .svg files are
            # full of dotted numbers that aren't addresses
            ip_candidates = [] if (len(line) > 400 or rel.lower().endswith('.svg')) else IPV4.findall(line)
            for ip in ip_candidates:
                try:
                    a = ipaddress.ip_address(ip)
                except ValueError:
                    continue
                if a.is_global:
                    warns.append((rel, n, f'public IP {ip}'))
            for m in EMAIL.finditer(line):
                if not OK_EMAIL_DOMAINS.search(m.group(1)):
                    warns.append((rel, n, f'email {m.group(0)}'))

    def show(title, items, limit=400):
        if not items:
            return
        print(f'\n{title} ({len(items)})')
        for rel, n, why in items[:limit]:
            print(f'  {rel}:{n}: {why}' if n else f'  {rel}: {why}')
        if len(items) > limit:
            print(f'  ... and {len(items) - limit} more')

    print(f'Scanned {len(files)} files (selected via {mode}); '
          f'terms: {len(block_terms)} block / {len(warn_terms)} warn'
          f'{" (" + terms_path + ")" if block_terms or warn_terms else " (no terms file)"}; '
          f'live secrets checked: {len(secrets)}')
    show('BLOCK — fix before publishing', blocks)
    show('WARN — review', warns)
    if blocks:
        print('\nRESULT: BLOCKED — do not push.')
        return 1
    print('\nRESULT: OK to publish' + (' (review the warnings above)' if warns else '') + '.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
