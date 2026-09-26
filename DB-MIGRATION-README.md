# BBS Messages → Database Migration

Moves `bbs-messages.html` / `message-storage.php` off JSON-file storage
(`data/messages/*.json`) and onto real tables in your existing
`tprfn`/`tprfn` MariaDB database. Also adds full auto-archiving of
every Personal/NTS/Bulletin message the dashboard ever fetches from BPQ,
so history survives even after BPQ itself expires/kills a message.

## What's new / changed

| File                        | Status                                                                 |
|------------------------------|------------------------------------------------------------------------|
| `sql/2026-07-messages-schema.sql` | **NEW** — run once against your DB                                |
| `message-db.php`             | **NEW** — DB data-access layer (folders/messages/addresses/settings)  |
| `message-storage.php`        | **REPLACED** — same action API, now DB-backed instead of JSON files   |
| `migrate-json-to-db.php`     | **NEW** — one-time importer for your existing JSON data               |
| `bbs-messages.html`          | **UPDATED** — auto-archives every fetched message after `getMail()`   |

The action-based API contract (`?action=folders`, `action=saveMessage`,
`action=markRead`, etc.) is **unchanged**, so nothing else in
`bbs-messages.html` had to change except the one new archive call — your
existing Personal / NTS / Bulletins display logic, rules engine, and
read-state tracking all keep working exactly as before, just backed by
the database now instead of JSON files.

Two new capabilities were added on top:

- **`action=archiveMessages`** (POST) — called automatically by
  `getMail()` after every fetch. Archives every message it just pulled
  from BPQ (Personal/NTS/Bulletin) into `bpq_messages`, permanently,
  whether or not the user ever saves/files it. Safe to call repeatedly —
  it's `INSERT IGNORE` keyed on (bbs_number, msg_type, from_call), so it
  never duplicates or clobbers a message you've already filed/edited.
- **`action=archive`** (GET, optional params `type=P|T|B`, `q=search`,
  `limit`, `offset`) — browse the *entire* archived history, not just
  what's currently filed into a folder. Nothing in the UI calls this yet;
  it's there if you want to add a "search all mail ever received" view
  later — happy to wire that into the UI if you want it.

## Deploy steps

1. **Run the schema** against your existing database (adjust the DB name
   if `tprfn-db.php` on the live server defines something other than
   `tprfn` — check `TPRFN_DB_NAME`/equivalent constant there):

   ```bash
   sudo mysql tprfn < sql/2026-07-messages-schema.sql
   ```

   Idempotent — safe to re-run.

2. **Copy the new/changed files** to the dashboard directory:

   ```bash
   sudo cp message-db.php message-storage.php migrate-json-to-db.php bbs-messages.html \
       /var/www/tprfn/
   sudo chown www-data:www-data /var/www/tprfn/message-db.php \
       /var/www/tprfn/message-storage.php /var/www/tprfn/bbs-messages.html
   ```

   (`migrate-json-to-db.php` is a one-shot CLI script — ownership doesn't
   matter much, but make sure it's readable by whatever user runs it.)

3. **DB helper**: `message-db.php` requires `/var/www/tprfn/tprfn-db.php`
   directly (confirmed present on this server, DB name `tprfn`, functions
   `tprfn_db()` / `tprfn_query()` / `tprfn_query_one()` / `tprfn_execute()`
   — same helper `network-api.php` already uses for `sessions`/`stations`).
   No extra config needed here.

4. **Migrate your existing JSON data** (folders, saved messages, address
   book, rules, unread state) into the new tables:

   ```bash
   cd /var/www/tprfn
   php migrate-json-to-db.php
   ```

   This reads `data/messages/*.json` and writes into the DB. It does
   **not** delete the JSON files — leave them as a backup until you've
   confirmed everything looks right in the UI, then remove
   `data/messages/*.json` yourself whenever you're comfortable.

5. **Verify**: open `bbs-messages.html`, hit "Get Mail", confirm Personal
   / NTS / Bulletins still populate as before, and confirm your existing
   saved folders/messages/address book show up unchanged. Check
   `SELECT COUNT(*) FROM bpq_messages;` grows each time you fetch new
   mail (archiving working) and that filed/saved messages show
   `folder_id IS NOT NULL`.

## Rollback

If something's wrong, the old `message-storage.php` (JSON-backed) and
your `data/messages/*.json` files are untouched on disk until you
manually delete them — just restore the old `message-storage.php` file
from backup/git and everything reverts to JSON storage immediately. No
data is lost either way since the migration only *reads* the JSON files.

## Notes / things I couldn't verify from here

- I don't have access to your live server or its real `tprfn-db.php`
  credentials, so I couldn't run this against production myself — please
  test on a staging copy or during a low-traffic window first.
- The schema assumes BPQ message numbers are unique per (number, type,
  sender) — matches the old JSON behavior. If you ever see two genuinely
  different messages silently merge (rare edge case with reused/blank
  BBS numbers on some bulletins), that's the dedupe key to revisit in
  `sql/2026-07-messages-schema.sql` (`uq_bbs_msg`).
- The rules engine and unread-map are stored as JSON blobs in a small
  `bpq_msg_settings` key/value table rather than being fully normalized
  into columns — this was deliberate, since their JSON shape lives in
  `bbs-messages.html`'s JS and can evolve without needing a schema change
  each time.
