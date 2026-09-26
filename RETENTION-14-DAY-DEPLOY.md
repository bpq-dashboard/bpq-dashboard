# Bulletin retention: 14 days, and the 📌 pin decides what's kept

2026-09-26. Follows `MESSAGE-KEEP-MIGRATION.md`, which is already deployed.
No database change is needed; this uses the `keep_on_kill` column that
migration added.

## What changes

**Before:** "unfiled bulletins older than 30 days" were purged. Every
bulletin is auto-filed into its `Bulletins - GROUP` folder, so in
practice nothing but Trash was ever purged, and every group folder grew
forever.

**Now:**

| Bulletin is in… | Kept? |
|---|---|
| a 📌 **pinned** group (e.g. TODAY) | **Forever** |
| one of **your own folders** (My Folders) | **Forever** |
| an **unpinned** group (e.g. WX), or unfiled | Deleted once **older than 14 days** |
| Trash | Deleted at the next run |
| Personal mail / NTS traffic | **Never touched** |

Deletion is permanent. It runs daily at 03:30 via the existing
`bulletin-retention.timer`, or on demand from Settings → **Run retention
cleanup now**. The page also drops its own cached copies on the same
rule, so the folder lists actually shrink.

Also changed: **emptying Trash** no longer sends a BBS kill for anything
marked "⚠ killed on BBS". That label only means "not in the last Get
Mail listing" (the newest ~50), and BPQ reuses message numbers, so a kill
by number could hit an unrelated newer message. Those are now removed
from the database only.

## Files

| File | Live path | Change |
|---|---|---|
| `message-db.php` | `/var/www/tprfn/` | New shared policy `msgdb_bulletin_purge_where()`; default 14 days |
| `scripts/purge-old-bulletins.php` | `/var/www/tprfn/scripts/` | Uses the shared policy; per-folder breakdown |
| `bbs-messages.html` | `/var/www/tprfn/` | Local 14-day prune; Settings text and button; Trash DB-only for stale messages |
| `admin/bpq-maintenance.html` | `/var/www/tprfn/admin/` | Help text only (14 days) |

## Deploy

Run each step on its own and check its output before moving on. Don't
paste several steps at once.

### Step 0: pin what you want to keep, FIRST

Pinning already works, because the earlier change is live. In
`bbs-messages.html`, click 📌 on every group you want kept in full
(COOK, SITREP, …). **After this deploy, the next 03:30 run permanently
deletes unpinned bulletins older than 14 days.** Confirm the pins:

```bash
mysql tprfn -e "SELECT name FROM bpq_msg_folders WHERE keep_on_kill = 1 ORDER BY name;"
```

### Step 1: go to the staging folder

```bash
cd ~/Downloads/retention-2026-09-26
ls -R
```

Expect `message-db.php`, `bbs-messages.html`, `scripts/purge-old-bulletins.php`,
`admin/bpq-maintenance.html` and the docs.

### Step 2: pre-flight check (is live what these edits started from?)

```bash
md5sum /var/www/tprfn/message-db.php /var/www/tprfn/bbs-messages.html \
       /var/www/tprfn/scripts/purge-old-bulletins.php /var/www/tprfn/admin/bpq-maintenance.html
```

Expected:

```
27d82220725a50f43fe22162d0acb06a  message-db.php
cd368d720a137ad17488fbfdf3f877fd  bbs-messages.html
8da53298ef5e9280ac8a55ce5bc98fdd  purge-old-bulletins.php
a41c97be92873841910a9e27ddc790da  bpq-maintenance.html
```

For any that don't match, run the diff below (swap in the file) and send
the output before continuing:

```bash
diff /var/www/tprfn/scripts/purge-old-bulletins.php scripts/purge-old-bulletins.php | grep '^<'
```

For reference, this change removes 37 lines from `message-db.php`, 7
from `bbs-messages.html`, 32 from `purge-old-bulletins.php` and 2 from
`bpq-maintenance.html`.

### Step 3: back up (on its own)

`-n` never overwrites an existing backup, so running this twice is safe.

```bash
cp -n -p /var/www/tprfn/message-db.php                 /var/www/tprfn/message-db.php.bak-retention
cp -n -p /var/www/tprfn/bbs-messages.html              /var/www/tprfn/bbs-messages.html.bak-retention
cp -n -p /var/www/tprfn/scripts/purge-old-bulletins.php /var/www/tprfn/scripts/purge-old-bulletins.php.bak-retention
cp -n -p /var/www/tprfn/admin/bpq-maintenance.html     /var/www/tprfn/admin/bpq-maintenance.html.bak-retention
ls -l /var/www/tprfn/*.bak-retention /var/www/tprfn/scripts/*.bak-retention /var/www/tprfn/admin/*.bak-retention
```

**Check the sizes before going on.** They must be the OLD sizes:

| Backup | Must be (old) | Must NOT be (new) |
|---|---|---|
| `message-db.php.bak-retention` | 32695 | 33232 |
| `bbs-messages.html.bak-retention` | 278401 | 280567 |
| `purge-old-bulletins.php.bak-retention` | 3879 | 4051 |
| `bpq-maintenance.html.bak-retention` | 107620 | 107798 |

(If step 2 showed a mismatch for a file, its old size may differ too;
it just must not equal the new size.)

### Step 4: deploy

```bash
cp message-db.php bbs-messages.html /var/www/tprfn/
cp scripts/purge-old-bulletins.php /var/www/tprfn/scripts/
cp admin/bpq-maintenance.html /var/www/tprfn/admin/
chown www-data:www-data /var/www/tprfn/message-db.php /var/www/tprfn/bbs-messages.html \
      /var/www/tprfn/scripts/purge-old-bulletins.php /var/www/tprfn/admin/bpq-maintenance.html
ls -l /var/www/tprfn/message-db.php /var/www/tprfn/bbs-messages.html \
      /var/www/tprfn/scripts/purge-old-bulletins.php /var/www/tprfn/admin/bpq-maintenance.html
```

The sizes should now be the new ones (33232 / 280567 / 4051 / 107798).

### Step 5: dry run (deletes nothing)

```bash
sudo -u www-data php /var/www/tprfn/scripts/purge-old-bulletins.php --dry-run
```

This shows a per-folder list of what the next run would delete. **If a
group you want to keep appears, pin it now** (step 0) and dry-run again.
None of your pinned groups should appear.

### Step 6: confirm the daily timer is running

```bash
systemctl list-timers bulletin-retention.timer
```

It should show a NEXT time around 03:30. If it prints "0 timers listed",
the timer was never installed, and the purge only happens when you click
the Settings button. Tell me, and I'll give you the install steps.

### Step 7 (optional): run it now

Either click Settings → **Run retention cleanup now** in the page, or:

```bash
sudo -u www-data php /var/www/tprfn/scripts/purge-old-bulletins.php
```

### Step 8: check the page

Hard-refresh `https://your-dashboard.example.com/bbs-messages.html` (Ctrl+Shift+R).
In the F12 console, look for `[retention] dropped N local bulletin
copies older than 14 days`. Settings → Bulletin retention should now
describe the 14-day rule.

## Roll back

```bash
cp /var/www/tprfn/message-db.php.bak-retention                 /var/www/tprfn/message-db.php
cp /var/www/tprfn/bbs-messages.html.bak-retention              /var/www/tprfn/bbs-messages.html
cp /var/www/tprfn/scripts/purge-old-bulletins.php.bak-retention /var/www/tprfn/scripts/purge-old-bulletins.php
cp /var/www/tprfn/admin/bpq-maintenance.html.bak-retention     /var/www/tprfn/admin/bpq-maintenance.html
chown www-data:www-data /var/www/tprfn/message-db.php /var/www/tprfn/bbs-messages.html \
      /var/www/tprfn/scripts/purge-old-bulletins.php /var/www/tprfn/admin/bpq-maintenance.html
```

A rollback restores the old 30-day unfiled-only rule. It can't bring back
bulletins that were already purged.
