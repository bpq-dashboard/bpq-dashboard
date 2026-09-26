# Email Client: 📌 keep-after-kill folders + message bodies saved to the database

**Status: deployed and verified live on 2026-09-26.**

2026-09-26. Two related changes to `bbs-messages.html` (the Email Client)
so messages can be kept in the database for later retrieval.

## What it does

**1. Message bodies are now saved in the database.** Get Mail's listing
only returns header lines, so every message was archived to `bpq_messages`
with an empty body. The body arrived later, when you clicked a message or
eager prefetch ran, and was only ever saved in that one browser's
`localStorage`. Once BPQ aged a message off, the database had the subject
line but no text. Now every fetched body is also written to the database.
Bodies already cached in your browser are pushed up once, automatically,
the first time the new page loads.

**2. 📌 "Keep after kill", per folder.** Every folder row in the sidebar
(bulletin groups under **Bulletins** and **My Folders**, except Trash) has
a 📌 icon: faint when off, bright when on. When it's on, killing a message
filed in that folder removes it from the BBS but **keeps the database copy
in the folder**. It shows as "no longer on BBS" and stays readable. Folders
without the pin behave exactly as before: Kill removes the message
everywhere. So you can pin `TODAY` and leave `WX` alone.

Before a message in a pinned folder is killed, the page fetches its body
from BPQ first if it doesn't have it yet, so the kept copy is never
headers-only.

## Behaviour details

- **Pinning is not retroactive.** Messages killed before a folder was
  pinned are not brought back.
- **Only filed messages are protected.** A message is kept only if it is
  in a pinned folder at the moment it is killed. Bulletins are auto-filed
  into their `Bulletins - GROUP` folder by rules, so pinning a group covers
  all its bulletins.
- **Kill on a kept message is refused.** It is already gone from BPQ, and
  BPQ reuses message numbers, so a second BBS kill could delete an
  unrelated newer message. To delete a kept copy, **move it to Trash and
  empty Trash**. Emptying Trash deletes kept copies from the database
  without contacting the BBS.
- **Pinned groups are exempt from bulletin retention.** Since the
  later 2026-09-26 change (`RETENTION-14-DAY-DEPLOY.md`), unpinned groups
  are purged after 14 days; pinned ones never are.
- **Renaming a pinned folder keeps the pin.** Deleting a pinned folder
  returns its messages to their inboxes, unfiled; the pin goes with the
  folder.
- **Muted bulletin groups** don't appear in the sidebar, so their 📌 isn't
  reachable. Unmute the group (Settings → Muted bulletin groups), pin it,
  and re-mute it if you like; the pin stays.

## Files

| File | Change |
|---|---|
| `sql/2026-09-26-message-keep-and-bodies.sql` | **New.** Adds `bpq_msg_folders.keep_on_kill` and `bpq_messages.killed_on_bbs_at`. Idempotent (`ADD COLUMN IF NOT EXISTS`); modifies no data. |
| `sql/2026-07-messages-schema.sql` | Same two columns added to the `CREATE TABLE`s, for fresh installs. |
| `message-db.php` | New `msgdb_keep_schema_ready()`, `msgdb_get_keep_folders()`, `msgdb_set_folder_keep()`, `msgdb_save_bodies()`. `msgdb_kill_message()` keeps rows in pinned folders and returns `kept`. `msgdb_save_message()` no longer blanks a stored body when re-filing without one. `msgdb_row_to_message()` returns `_goneFromBbs`. |
| `message-storage.php` | New POST actions `saveBodies` and `setFolderKeep`; GET `action=folders` also returns `keepFolders`. |
| `bbs-messages.html` | 📌 toggle on folder rows; bodies saved on preview, prefetch and before a pinned kill; one-time body backfill; kill paths keep pinned messages; Kill refused on kept copies; Trash handles kept copies database-only; Properties shows a "Kept" row. |
| `USER-GUIDE.md`, `CHANGELOG.md`, `docs/SESSION-JOURNAL.md` | Documentation. |

## Deploy

The PHP is written to degrade safely if the SQL hasn't been applied yet:
nothing is kept, kills behave as before, and the 📌 toggle shows an error
toast. Running the SQL first is still the right order.

```bash
cd /path/to/unzipped/BPQ-Dashboard-ARSSYSTEM-v1.5.13

# 0. Back up the live copies (project convention). -n = never overwrite
#    an existing backup, so running this twice can't clobber it.
for f in message-db.php message-storage.php bbs-messages.html; do
  sudo cp -n -p /var/www/tprfn/$f /var/www/tprfn/$f.bak-2026-09-26
done
# CHECK before going further: the .bak sizes must be the OLD sizes
# (267603 / 26107 / 7029), NOT the new ones (278401 / 32695 / 8038).
ls -l /var/www/tprfn/*.bak-2026-09-26

# 1. Diff against live first. Make sure nothing site-specific lives only
#    in the live copies (see the journal's template-clobbering lessons).
for f in message-db.php message-storage.php bbs-messages.html; do
  echo "=== $f"; diff -q /var/www/tprfn/$f $f && echo same
done
# For any file reported different, eyeball it before copying:
#   diff /var/www/tprfn/bbs-messages.html bbs-messages.html | less

# 2. Schema migration (socket auth, no -p)
sudo mysql tprfn < sql/2026-09-26-message-keep-and-bodies.sql

# 3. Deploy the files
sudo cp message-db.php message-storage.php bbs-messages.html /var/www/tprfn/
sudo chown www-data:www-data /var/www/tprfn/message-db.php \
    /var/www/tprfn/message-storage.php /var/www/tprfn/bbs-messages.html
```

The step 1 diff should only show this change's edits. The live copies were
last synced into the package on 2026-09-20. If a diff shows anything else,
stop and compare before copying.

## Verify

1. **Schema:**
   ```bash
   sudo mysql tprfn -e "SHOW COLUMNS FROM bpq_msg_folders LIKE 'keep_on_kill';
                        SHOW COLUMNS FROM bpq_messages LIKE 'killed_on_bbs_at';"
   ```
   Both should return one row.
2. **Page loads:** hard-refresh `https://your-dashboard.example.com/bbs-messages.html`
   (Ctrl+Shift+R). Every folder row except Trash shows a faint 📌.
3. **Body backfill ran:** open the browser console and look for
   `[bodyBackfill] sent N cached bodies, M filled in the database`. Then:
   ```bash
   sudo mysql tprfn -e "SELECT msg_type, COUNT(*) total,
       SUM(body IS NOT NULL AND body <> '') with_body
       FROM bpq_messages WHERE is_archived = 1 GROUP BY msg_type;"
   ```
   `with_body` should be well above zero, and it grows as you read mail.
4. **Pin works:** click 📌 on `TODAY` (under Bulletins). It turns bright and
   a toast confirms. Check it on the server:
   ```bash
   sudo mysql tprfn -e "SELECT name FROM bpq_msg_folders WHERE keep_on_kill = 1;"
   ```
5. **Keep on kill:** in the pinned folder, kill one forwarded (`BF`)
   bulletin. The toast should read *Killed on BBS — kept in "Bulletins -
   TODAY"*, and the message stays in the folder, readable. Reload the page:
   it's still there. Then:
   ```bash
   sudo mysql tprfn -e "SELECT bbs_number, is_archived, killed_on_bbs_at,
       LENGTH(body) blen FROM bpq_messages
       WHERE killed_on_bbs_at IS NOT NULL ORDER BY killed_on_bbs_at DESC LIMIT 5;"
   ```
   Expect `is_archived = 1`, a timestamp, and `blen > 0`.
6. **Unpinned is unchanged:** kill a bulletin in an unpinned group such as
   `WX`. It disappears, as before.

## Roll back

```bash
for f in message-db.php message-storage.php bbs-messages.html; do
  sudo cp /var/www/tprfn/$f.bak-2026-09-26 /var/www/tprfn/$f
done
```

The two new columns can stay; the old code ignores them. Kept rows remain
`is_archived = 1`, so after a rollback they show as ordinary archived
messages rather than disappearing.
