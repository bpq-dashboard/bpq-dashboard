"""
bpq_db_config.py - shared DB-credential loader for the Python parsers.
(2026-09-26)

Replaces the password that used to be hard-coded as a default in each
parser. Resolution order, per setting (first one found wins):

    1. command-line flags (--db-pass etc.; applied by each parser itself)
    2. environment variables  BPQ_DB_HOST / _PORT / _USER / _PASS / _NAME
    3. db-config.php           (local-only, gitignored)
    4. config.php 'db' section (the long-standing documented location)
    5. built-in non-secret defaults (localhost / 3306 / tprfn_app / tprfn)

The PHP files are located via $BPQ_DB_CONFIG (explicit path to a
db-config.php), else next to this script's directory's parent (the usual
web-root layout: <root>/scripts/*.py + <root>/db-config.php), else the
script directory itself.

PHP config files are read by asking the `php` CLI to json_encode() them,
which is exact. If `php` isn't available, a simple text reader handles
the plain `'key' => 'value',` shape used by db-config.php.example.
"""
import json
import os
import re
import shutil
import subprocess
import sys

_HERE = os.path.dirname(os.path.abspath(__file__))

_DEFAULTS = {'host': 'localhost', 'port': 3306, 'user': 'tprfn_app', 'name': 'tprfn', 'pass': ''}
_ENV = {'host': 'BPQ_DB_HOST', 'port': 'BPQ_DB_PORT', 'user': 'BPQ_DB_USER',
        'pass': 'BPQ_DB_PASS', 'name': 'BPQ_DB_NAME'}

_PHP_DUMP = ('$c = require $argv[1];'
             'if ($argv[2] !== "" && is_array($c)) { $c = $c[$argv[2]] ?? null; }'
             'echo json_encode(is_array($c) ? $c : null);')


def _candidate_dirs():
    return [os.path.dirname(_HERE), _HERE]


def _read_php_array(path, key=''):
    """Return the array a PHP config file returns (or its [key] sub-array)."""
    php = shutil.which('php') or ('/usr/bin/php' if os.path.exists('/usr/bin/php') else None)
    if php:
        try:
            out = subprocess.run([php, '-r', _PHP_DUMP, '--', path, key],
                                 capture_output=True, text=True, timeout=10)
            if out.returncode == 0 and out.stdout.strip():
                data = json.loads(out.stdout)
                if isinstance(data, dict):
                    return data
                return None
        except (OSError, ValueError, subprocess.SubprocessError):
            pass
    if key:
        return None   # nested config.php sections need real PHP
    # Fallback: plain `'key' => 'value'` / `'key' => 123` lines only.
    try:
        text = open(path, encoding='utf-8').read()
    except OSError:
        return None
    found = {}
    for m in re.finditer(r"'(\w+)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|(\d+))", text):
        found[m.group(1)] = m.group(2).replace("\\'", "'").replace('\\\\', '\\') \
            if m.group(2) is not None else int(m.group(3))
    return found or None


def _from_files():
    explicit = os.environ.get('BPQ_DB_CONFIG')
    paths = [explicit] if explicit else [os.path.join(d, 'db-config.php') for d in _candidate_dirs()]
    for p in paths:
        if p and os.path.isfile(p):
            data = _read_php_array(p)
            if data:
                return data, p
    for d in _candidate_dirs():
        p = os.path.join(d, 'config.php')
        if os.path.isfile(p):
            data = _read_php_array(p, 'db')
            if data:
                return data, p + " ['db']"
    return {}, None


def load_db_config(extra=None):
    """Return a pymysql.connect() kwargs dict (host, port, user, password, database).
    `extra` (e.g. charset/cursorclass) is merged in unchanged."""
    file_vals, source = _from_files()
    vals = {}
    for k, default in _DEFAULTS.items():
        env = os.environ.get(_ENV[k])
        vals[k] = env if env not in (None, '') else file_vals.get(k, default)
    cfg = {
        'host': vals['host'],
        'port': int(vals['port']),
        'user': vals['user'],
        'password': vals['pass'],
        'database': vals['name'],
    }
    if extra:
        cfg.update(extra)
    cfg_source[0] = source
    return cfg


cfg_source = [None]   # where the file-based settings came from (for messages)


def load_config_section(section):
    """Return a non-secret section of config.php ('station', 'paths', 'logs')
    as a dict, or {} if config.php / the section isn't there. (2026-09-26)"""
    for d in _candidate_dirs():
        p = os.path.join(d, 'config.php')
        if os.path.isfile(p):
            data = _read_php_array(p, section)
            return data if isinstance(data, dict) else {}
    return {}


def default_logs_dir():
    """config.php paths.logs, else <web root>/logs next to scripts/."""
    p = load_config_section('paths').get('logs')
    return p.rstrip('/') if p else os.path.join(os.path.dirname(_HERE), 'logs')


def default_linbpq_path(filename):
    """<config.php paths.linbpq>/<filename>, else ./<filename> (cron jobs
    usually `cd` into the LinBPQ directory first)."""
    d = load_config_section('paths').get('linbpq')
    return os.path.join(d, filename) if d else os.path.join(os.getcwd(), filename)


def warn_if_no_password(cfg):
    """Call just before connecting: explains an empty password clearly
    instead of leaving only MariaDB's 'Access denied' to go on."""
    if not cfg.get('password'):
        print('WARNING: no database password configured. Create db-config.php '
              '(copy db-config.php.example next to tprfn-db.php), add a db section '
              'to config.php, or set BPQ_DB_PASS / --db-pass.', file=sys.stderr)
