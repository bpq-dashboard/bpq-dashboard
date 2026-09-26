# Forwarded Messages panel: filter to RF (VARA HF) traffic only

Fixes the TPRFN Hub Station Forwarding Partners tiles (and the Forwarded
Messages panel/table itself, and everything else that reads from it —
top summary card, hourly chart, RF Connected Stations modal, Station Map
popups, Propagation Report) showing message counts for a partner that
weren't actually carried over RF.

## Root cause

`rf_messages` is populated from BPQ's BBS forwarding log (`FA`/`FC`
lines), which records a message-forwarding proposal identically
regardless of which port/medium carried the connection — VARA HF, NET/ROM,
AX.25, and Telnet forwarding sessions all produce the same line shape.
On top of that, the parser stripped the SSID off the partner callsign
before storing it, so `N3MEL-2` (a real VARA HF/RF hub partner) and
`N3MEL-7` (a NET/ROM node with the same base callsign) both collapsed to
`partner = 'N3MEL'` — indistinguishable by the time the data reached the
page.

## What changed

| File | What changed |
|---|---|
| `scripts/parse-vara-log.py` | New `build_connect_mode_lookup()` reads BPQ's `ConnectLog_YYMMDD.log` files (a separate log BPQ already writes, recording the medium of every connect — `VARA`/`NETROM`/`AX.25`/`TELNET`) and returns them **indexed by callsign** (`{'exact': {...}, 'base': {...}}`, each a sorted `(timestamps, modes)` pair) rather than a flat list. New `lookup_mode_for_message()` binary-searches (`bisect`) that index for the nearest event to a given message's timestamp — exact callsign+SSID first, falling back to base-callsign — within a 30-minute window. `build_forwarded_messages()` tags every message with the result as `medium`. `upsert_message()` now does `INSERT ... ON DUPLICATE KEY UPDATE medium = COALESCE(...)` instead of `INSERT IGNORE`, so re-running the parser can backfill `medium` on rows inserted before this change. **Note on the indexed approach:** the first version of this did a plain linear scan of every ConnectLog event per message — fine at small scale, but a full-history production ConnectLog can easily hold ~250,000 events, and with enough messages that made a `--reset` backfill run hang indefinitely (O(messages × events)). The indexed/bisected version is O(log n) per lookup regardless of history size — benchmarked at ~0.1s for 20,000 message lookups against a 250,000-event index. |
| `data/rf-connections-schema.sql` | `rf_messages` gets a new `medium VARCHAR(16) NULL` column + `idx_medium` index (for fresh installs). |
| `sql/2026-09-21-rf-messages-medium.sql` | **New** — `ALTER TABLE` migration for existing databases (idempotent, checks `INFORMATION_SCHEMA` first). |
| `rf-connections-api.php` | `action=messages` now returns `medium` on every row. |
| `bpq-rf-connections.html` | New `isRfMedium(m)` helper — `true` when `medium` is `'VARA'` **or** null/undefined (unknown ≠ non-RF, so nothing is hidden just because it hasn't been correlated yet). `getFilteredForwardedMessages()` and `getRealMessageCountsInRange()` now apply it — this is the single shared source behind the Forwarded Messages table, the TPRFN Hub Station tile counts, the top summary card, the hourly chart, the per-station message counts, and the Propagation Report, so all of them pick up the fix together. |
| `USER-GUIDE.md` | Hub Station Forwarding Partners section now notes the per-tile counts are RF-only. |

`medium = NULL` is **never** treated as non-RF — only a positively-identified
non-`VARA` value (`NETROM`, `AX.25`, `TELNET`, ...) gets filtered out. This
matters because ConnectLog retention/coverage may not reach as far back as
`rf_messages` history, and a coverage gap should never silently make real
RF messages disappear.

## Deploy

```bash
# 1. Schema migration
sudo mysql tprfn < sql/2026-09-21-rf-messages-medium.sql

# 2. Deploy the updated files
sudo cp scripts/parse-vara-log.py rf-connections-api.php bpq-rf-connections.html /var/www/tprfn/
sudo chown www-data:www-data /var/www/tprfn/rf-connections-api.php /var/www/tprfn/bpq-rf-connections.html
sudo chown <parser-user>:<parser-group> /var/www/tprfn/scripts/parse-vara-log.py   # match existing ownership

# 3. Backfill medium on already-stored messages (including the two
#    N3MEL/NET-ROM ones that prompted this). Ordinary incremental runs
#    only rescan the last 2 days of BBS logs, so a full re-scan is
#    needed once to correlate historical rows:
python3 scripts/parse-vara-log.py --reset
```

`--reset` re-parses from scratch but is safe here — `rf_connections`
inserts are idempotent on `(session_start, callsign)` and `rf_messages`
upserts are idempotent on `(bid, direction)`; nothing gets duplicated,
existing rows just get their `medium` filled in.

If your `ConnectLog_YYMMDD.log` retention doesn't go back as far as your
BBS log retention, older messages outside that window will simply keep
`medium = NULL` and continue to display (per the "unknown ≠ non-RF" rule
above) rather than disappearing.

**If `--reset` was interrupted (e.g. Ctrl-C) partway through** — safe to
just run it again. It wipes and rebuilds `rf_parser_state`/
`rf_connections` at the start, so an interrupted run leaves those two
tables empty/partial but nothing else broken; a second `--reset` run
starts clean. (This is also the exact scenario that surfaced the
linear-scan performance bug fixed in this version — an older build of
`parse-vara-log.py` with a naive scan instead of the indexed/bisected
lookup will appear to hang indefinitely on the "Scanning N BBS log files
for message proposals..." step against a large ConnectLog history;
confirm you're on the current file before re-running.)

## Verify

1. Confirm the migration applied: `DESCRIBE rf_messages;` should show a
   `medium` column.
2. Run the backfill (`--reset`), then check a known NET/ROM-forwarded
   message directly:
   ```sql
   SELECT bid, direction, partner, medium, msg_time
   FROM rf_messages WHERE partner = 'N3MEL' ORDER BY msg_time DESC LIMIT 10;
   ```
   Rows carried over VARA should show `medium = 'VARA'`; the NET/ROM ones
   should show `'NETROM'` (or whatever BPQ's ConnectLog labels that
   port). Rows with no ConnectLog coverage show `NULL`.
3. Open `bpq-rf-connections.html`, hard-refresh, and check the N3MEL-2
   TPRFN Hub Station tile's message count — it should now exclude the
   NET/ROM messages that were previously counted there.
4. Spot-check the Forwarded Messages table/panel itself, the Propagation
   Report, and the RF Connected Stations modal for the same partner —
   all should now agree and exclude the non-RF rows.

## Rollback

Restore the three code files from backup/git. The `medium` column can be
left in place (harmless if unused — `action_messages()` just won't be
asked to return it, and the old front-end code never reads it); to fully
revert the schema, `ALTER TABLE rf_messages DROP COLUMN medium;`.
