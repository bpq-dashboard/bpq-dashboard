# Moving the database password out of the code, into `db-config.php`

2026-09-26. Phase 1 of making this working copy safe to publish on GitHub.

**Status: deployed and verified live on 2026-09-26.** Note: on K1AJD's server the live
`generate-report.php` was built from the live copy (its script-path fallback
differs from the package), rather than using the package file.
Your dashboard behaves exactly as before; only **where the password
lives** changes.

## What changes

**Before:** the live database password was typed directly into four
code files: `tprfn-db.php`, `scripts/parse-vara-log.py`,
`scripts/parse-system-logs.py` and `scripts/parse-traffic-report.py`.

**After:** it lives in **one** local file, `/var/www/tprfn/db-config.php`,
which is excluded from Git. All four files, plus `generate-report.php`,
read it from there. The code files contain no secrets any more.

Where the settings come from, in order:

1. For the Python parsers only: `--db-*` flags, then `BPQ_DB_*`
   environment variables.
2. `db-config.php`.
3. The `db` section of `config.php` (the long-standing documented place).
4. A clear error ("No database settings: create db-config.php…").

| File | Change |
|---|---|
| `tprfn-db.php` | Loads credentials via `tprfn_db_settings()`; no password inside |
| `generate-report.php` | Uses the same source as everything else |
| `scripts/bpq_db_config.py` | **New.** Shared loader for the Python parsers |
| `scripts/parse-vara-log.py`, `parse-system-logs.py`, `parse-traffic-report.py` | Use the loader; hard-coded password removed |
| `db-config.php.example` | **New.** Template for `db-config.php` |

Package-only changes, with nothing to deploy: `.gitignore` (new),
`tprfn.conf` moved to `private/`, five obsolete files removed, and the
PDFs regenerated.

## Deploy

Run each step on its own and check its output before going on.

### Step 1: staging folder and fact-finding

```bash
mkdir -p ~/Downloads/credentials-2026-09-26
mv ~/Downloads/credentials-deploy-2026-09-26.zip ~/Downloads/credentials-2026-09-26/
cd ~/Downloads/credentials-2026-09-26
unzip credentials-deploy-2026-09-26.zip
ls -R
```

Then find out **where the parsers live** and **which user runs them**:

```bash
ls -l /var/www/tprfn/scripts/parse-*.py /var/www/tprfn/parse-*.py 2>/dev/null
crontab -u www-data -l 2>/dev/null | grep -i parse
crontab -l 2>/dev/null | grep -i parse
ls /etc/cron.d/ 2>/dev/null
```

Paste this output before going on. The rest of the steps assume the
parsers are in `/var/www/tprfn/scripts/` and run from www-data's
crontab; if that's not what the output shows, the steps get adjusted.

### Step 2: pre-flight check

```bash
md5sum /var/www/tprfn/tprfn-db.php /var/www/tprfn/generate-report.php \
       /var/www/tprfn/scripts/parse-vara-log.py /var/www/tprfn/scripts/parse-system-logs.py \
       /var/www/tprfn/scripts/parse-traffic-report.py
```

Expected (the files these edits started from):

```
0591c8814a34fc882e148a96f327bcee  tprfn-db.php
00b658718968bfe8aac1d04cb325da1c  generate-report.php
13be618edff430a81368746026dc865a  parse-vara-log.py
b26b09dda3f60d793ae476970bde0136  parse-system-logs.py
d66deb5db4e9afc817dc30ab5b643a57  parse-traffic-report.py
```

For any that don't match, run `diff /var/www/tprfn/<file> <file> | grep '^<'`
and send the output. For reference, the change removes 5 lines from
`tprfn-db.php`, 1 from `generate-report.php`, and 7 from each parser.

### Step 3: create `db-config.php` on the server

This must exist **before** the new code goes in, or database pages
would error.

```bash
cp db-config.php.example /var/www/tprfn/db-config.php
nano /var/www/tprfn/db-config.php
```

In nano, change `CHANGE_THIS_DB_PASSWORD` to your real database password,
the same one currently in `/var/www/tprfn/tprfn-db.php`. Check
`name` (`tprfn`) and `user` (`tprfn_app`) too. Save with Ctrl+O, Enter,
Ctrl+X. **Don't paste the password into chat.**

Lock it down so only www-data (the web server and the parser cron) can
read it:

```bash
chown www-data:www-data /var/www/tprfn/db-config.php
chmod 640 /var/www/tprfn/db-config.php
ls -l /var/www/tprfn/db-config.php
```

Expect `-rw-r----- 1 www-data www-data`.

### Step 4: back up (on its own)

```bash
cp -n -p /var/www/tprfn/tprfn-db.php          /var/www/tprfn/tprfn-db.php.bak-creds
cp -n -p /var/www/tprfn/generate-report.php   /var/www/tprfn/generate-report.php.bak-creds
for f in parse-vara-log.py parse-system-logs.py parse-traffic-report.py; do
  cp -n -p /var/www/tprfn/scripts/$f /var/www/tprfn/scripts/$f.bak-creds
done
ls -l /var/www/tprfn/*.bak-creds /var/www/tprfn/scripts/*.bak-creds
```

**Check the sizes before going on.** They must be the OLD sizes:

| Backup | Must be (old) | Must NOT be (new) |
|---|---|---|
| `tprfn-db.php.bak-creds` | 9326 | 10871 |
| `generate-report.php.bak-creds` | 5202 | 5407 |
| `parse-vara-log.py.bak-creds` | 73939 | 74052 |
| `parse-system-logs.py.bak-creds` | 38068 | 38181 |
| `parse-traffic-report.py.bak-creds` | 13243 | 13356 |

### Step 5: deploy

```bash
cd ~/Downloads/credentials-2026-09-26
cp tprfn-db.php generate-report.php /var/www/tprfn/
cp scripts/bpq_db_config.py scripts/parse-vara-log.py scripts/parse-system-logs.py scripts/parse-traffic-report.py /var/www/tprfn/scripts/
chown www-data:www-data /var/www/tprfn/tprfn-db.php /var/www/tprfn/generate-report.php
ls -l /var/www/tprfn/tprfn-db.php /var/www/tprfn/generate-report.php /var/www/tprfn/scripts/bpq_db_config.py /var/www/tprfn/scripts/parse-*.py
```

Then set the owner of the four `scripts/` files to match what step 1
showed for the existing parsers (for example
`chown www-data:www-data /var/www/tprfn/scripts/bpq_db_config.py …`).

### Step 6: verify

**PHP, as the web server user:**

```bash
sudo -u www-data php -r 'require "/var/www/tprfn/tprfn-db.php"; echo tprfn_db_settings()["source"], " -> ", tprfn_db()->query("SELECT CURRENT_USER()")->fetchColumn(), "\n";'
```

Expect `db-config.php -> tprfn_app@localhost`.

**Python, as the cron user:**

```bash
sudo -u www-data python3 -c 'import sys; sys.path.insert(0,"/var/www/tprfn/scripts"); import bpq_db_config as b, pymysql; c=b.load_db_config(); cur=pymysql.connect(**c).cursor(); cur.execute("SELECT CURRENT_USER()"); print(b.cfg_source[0], "->", cur.fetchone()[0])'
```

Expect `/var/www/tprfn/db-config.php -> tprfn_app@localhost`.

**One real parser run**, which is idempotent and the same thing cron does:

```bash
sudo -u www-data python3 /var/www/tprfn/scripts/parse-traffic-report.py -v
```

It should finish without an "Access denied" or "no database password"
message.

**Pages:** hard-refresh RF Connections, Traffic, System Logs and the
Email Client. Each should load normally; the status lines show `(DB)`
where they did before.

### Step 7: confirm the password is gone from the code on the server

```bash
grep -l "CHANGE_THIS_DB_PASSWORD\|define('TPRFN_DB_PASS', '" /var/www/tprfn/tprfn-db.php
grep -c "os.environ.get('BPQ_DB_PASS', '" /var/www/tprfn/scripts/parse-*.py
```

The first should print nothing, and the second should print `0` for each
parser.

## Roll back

```bash
cp /var/www/tprfn/tprfn-db.php.bak-creds        /var/www/tprfn/tprfn-db.php
cp /var/www/tprfn/generate-report.php.bak-creds /var/www/tprfn/generate-report.php
for f in parse-vara-log.py parse-system-logs.py parse-traffic-report.py; do
  cp /var/www/tprfn/scripts/$f.bak-creds /var/www/tprfn/scripts/$f
done
```

`db-config.php` and `bpq_db_config.py` can stay; the old code ignores
them.

## After this: phase 2 (before any GitHub push)

- **Change the database password.** It has appeared in zips and chat.
  After this deploy that's one MariaDB command plus a one-line edit to
  `db-config.php`; no code changes are needed.
- Move the remaining K1AJD-specific values into config.
- Add a pre-publish check script.
- Start the GitHub repository fresh from the cleaned tree.
