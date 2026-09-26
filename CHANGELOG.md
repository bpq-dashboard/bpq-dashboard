# Changelog

All notable changes to BPQ Dashboard will be documented in this file.

## [Unreleased]

_Nothing yet._

## [1.5.13] - 2026-09-26

First public release of 1.5.13. It includes the changes first packaged
on 2026-09-16 together with everything since.

### Added

- **`scripts/fetch-forms.sh` — message forms downloaded, not bundled.**
  The Email Client's "PKTNET Forms" button opens `pktnet-forms/index.html`.
  That folder is third-party work (TPRFN HTML Forms by N3MEL) and is no longer part of the repository: `install.sh`
  downloads it from the authors' repository
  (`https://github.com/N3MEL39/TPRFN_HTML_Forms`; override with
  `FORMS_REPO=`) when it's missing, and keeps an existing copy on
  upgrades. `install-check.php` warns if it's missing and shows the fix
  command. `LICENSES/README.md` and the bundled-jQuery notices were
  updated to match.
- **`LICENSE` (GPLv3) and `LICENSES/`** — the README already linked
  both, but neither existed.

- **Installation guide rewritten for newcomers** (`USER-MANUAL.md` §4).
  An 11-step guided-install walk-through: a table of every installer
  question with where to find each answer, download, run, read the
  summary, open, self-test, switch on services, optional settings,
  staying safe. Plus "Updating to a newer version" and a condensed manual
  install mirroring the installer. README quick install, §2 "What's New",
  §3 and §10.8 were updated to match, and the old `/var/www/html/bpq` path
  was replaced by `/var/www/bpq-dashboard` throughout the current docs.
- **`tools/prepublish-check.py` + `docs/PUBLISHING.md`** — scans exactly
  what Git would publish and blocks private keys/tokens, real-looking
  password values, a local `!` term list, and (with `--secrets-from DIR`)
  the live config's actual secrets, which are never printed. Warns on
  public IPs, real emails and non-`!` terms. The terms list lives in the
  gitignored `private/prepublish-terms.txt`.
- **APRS page and daemon made config-driven.** `bpq-aprs.html` now takes
  the home position/callsign from `station-info.php` (grid-square centre;
  was hard-coded exact coordinates and a town name) and uses CARTO
  basemaps without an API key (a hard-coded key was removed).
  `scripts/bpq-aprs-daemon.py` reads its APRS-IS login/passcode/filter
  from a new `config.php` `aprs` section (`passcode` -1 = receive-only;
  empty filter = 80 km around the station), and its cache dir from its own
  location (matching `bpq-aprs.php`). A real APRS-IS passcode was removed
  from the code.
- **Station-specific values moved into `config.php`** (phase 2 part B).
  New public-safe endpoint `station-info.php` (callsign, node, grid and
  the **grid-square centre** as the map position; never the exact
  lat/lon, email or secrets) and helper `includes/station-config.php`.
  RF Connections' home station/map, and the Email Client and Traffic
  page titles, now come from it instead of hard-coded K1AJD values.
  `network-api.php` reads the correlation hub from `station.hub`
  (empty = feature off). Python scripts take defaults from config via
  `bpq_db_config.load_config_section()`: VARA log (`paths.logs` +
  `logs.vara_file`), logs dir, and `paths.linbpq` for `WP.cfg` /
  `linmail.cfg`. `install-check.php` looks in `paths.linbpq` first. The
  template gains `station.hub`, `station.grid` and `paths.linbpq`; the
  systemd unit templates and `fix-permissions.sh` default to
  `/var/www/bpq-dashboard`.
- **nginx templates**: also block backup suffixes (`.bak-*`, `.bak.1`,
  …), `.sql` dumps and `SESSION-JOURNAL*` (the old rule only matched
  names ending in `.bak`).

- **Database credentials moved out of the code** into a local, gitignored
  `db-config.php` (template: `db-config.php.example`), falling back to
  `config.php`'s `db` section. `tprfn-db.php` (`tprfn_db_settings()`),
  `generate-report.php`, and the three parsers (via the new shared
  `scripts/bpq_db_config.py`) all read it. The live password is no longer
  hard-coded anywhere in the package. See `CREDENTIALS-DEPLOY.md`.
- **`.gitignore`** — local config/credential files, `private/`, runtime
  data (logs, cache, data/stations, data/backups, archives), `*.bak*`.
- **`private/`** — station-specific files kept out of Git; `tprfn.conf`
  (live nginx config) moved here. `bpq-dashboard.conf` remains the
  public template.

- **`data/rf-connections-schema.sql` / `scripts/parse-vara-log.py` — new `rf_messages` table, Forwarded Messages moved server-side (Phase 3)** — The "Forwarded Messages" panel on `bpq-rf-connections.html` previously re-parsed the full lazy-loaded BBS log window for FA/FC message-proposal lines client-side, in every visitor's browser, on every page load and every 15-minute auto-refresh. `parse-vara-log.py` gained `build_forwarded_messages()` (regexes ported verbatim from the legacy JS `faMatch`/`fcMatch` patterns) and `upsert_message()`, writing into a new `rf_messages` table deduped on `(bid, direction)`. New `rf-connections-api.php` `?action=messages` endpoint serves it. `bpq-rf-connections.html` fetches from the API first (`fetchMessagesFromApi()`/`apiRowToMessage()`, `USE_MSG_API` flag) and only falls back to client-side BBS-log regex parsing if the API/table is unavailable — same graceful-degradation pattern as the Phase 1 sessions API. When the API succeeds, `parseBBSLog()` skips the FA/FC matching block entirely (the actual CPU saving), and message history is no longer capped by whatever BBS-log window the browser had lazy-loaded — the API returns full history regardless.

- **`scripts/parse-vara-log.py` — Channel Busy failures now detected** — The DB-backed parser previously had no way to classify a "Channel is busy" rejection (`status`/`failure_reason` stayed `no_data` or `superseded`) because it only ever read the VARA session log, never the BBS log, for failure classification. New `RE_BBS_CHANNEL_BUSY` regex + `build_channel_busy_lookup()` correlate the BBS log's `Sorry, Can't Connect - Channel is busy` rejection against failed sessions (±120s tolerance, same approach as the existing frequency correlation), overriding `failure_reason` to `channel_busy` in `upsert_session()`. `bpq-rf-connections.html`'s `apiRowToConnection()` now also maps the API's `failure_reason` field to the page's internal `failureType` at all (`FAILURE_REASON_MAP`) — previously dropped entirely, which meant the Failures card's Timeout/Channel Busy/No Data breakdown always read 0 on the DB-backed path even though the total failure count was correct.

### Removed

- `generate-propagation-report.py` (superseded by the in-page Propagation
  Report on RF Connections), `vara-analysis.sh` (superseded by RF
  Connections; its report file was never read), `bbs-fetch.exp` (BBS
  fetching is done in PHP by `bbs-messages.php`), root-level
  `vara-callsign-validator.py` (older duplicate of
  `scripts/vara-callsign-validator.py`), `visitor-log.php` (orphaned; the
  `index.php` / `rf-log.php` it hooks into don't exist). The
  `USER-MANUAL.md` §10.11 install steps for `vara-analysis.sh` were
  removed to match.

### Changed

- Both PDF manuals regenerated from the current Markdown (they predated
  the Traffic/System Logs DB migrations, muting, 📌 keep, and 14-day
  retention).

- **Bulletin retention: 14 days, pin-aware** (`message-db.php`,
  `scripts/purge-old-bulletins.php`, `bbs-messages.html`,
  `admin/bpq-maintenance.html`) — replaces "unfiled bulletins older than
  30 days". Every bulletin is auto-filed into its group folder, so the
  old rule effectively purged only Trash, and group folders grew forever.
  Now bulletins older than 14 days are deleted unless they're in a 📌
  pinned group or one of the user's own (non-`Bulletins - `) folders.
  Personal and NTS mail are unchanged (kept indefinitely). The policy is
  defined once in `msgdb_bulletin_purge_where()` and shared by the daily
  timer script and the Settings button. The script's `--dry-run` prints a
  per-folder breakdown. Before the keep migration is applied, it falls
  back to the old unfiled-only rule. The page prunes its localStorage
  copies on the same rule. Emptying Trash is now database-only for
  anything not in the last Get Mail listing (no BBS kill by a possibly
  reused message number). See `RETENTION-14-DAY-DEPLOY.md`.
- **Email Client: 📌 "keep after kill" folders** (`bbs-messages.html`,
  `message-db.php`, `message-storage.php`) — per-folder opt-in
  (`bpq_msg_folders.keep_on_kill`, toggled from a 📌 icon on each folder
  row except Trash). Killing a message filed in a pinned folder removes
  it from the BBS but keeps its database copy in the folder, stamped
  `bpq_messages.killed_on_bbs_at` and shown as "no longer on BBS".
  Unpinned folders are unchanged. Before a pinned message is killed, its
  body is fetched from BPQ if needed and saved first. Kill is refused on
  an already-kept copy, because BPQ reuses message numbers; Empty Trash
  removes kept copies database-only. New migration
  `sql/2026-09-26-message-keep-and-bodies.sql`; see
  `MESSAGE-KEEP-MIGRATION.md`.
- **Email Client: message bodies persisted to the database** — new
  `saveBodies` action / `msgdb_save_bodies()`. Bodies fetched on preview
  or eager prefetch are now written to `bpq_messages.body`; previously
  they only went to browser `localStorage`, and the database archive was
  effectively headers-only. Includes a one-time, update-only backfill of
  bodies already cached in the browser.

- **`bpq-system-logs.html` migrated to DB-backed data** — Previously
  fetched and re-parsed up to 365 days / 100,000 lines of
  `log_YYMMDD_BBS.txt` client-side on every page load (`parseLogStats()`,
  ~480 lines of regex classification), plus three more separate
  fetch+parse passes for NodesRTT, RMS/CMSAccess logs, and TCP logs.
  New standalone parser `scripts/parse-system-logs.py` (kept separate
  from `parse-vara-log.py`, matching `parse-traffic-report.py`'s
  precedent) with six sub-parsers — `system`, `rms`, `tcp` (idempotent
  "since window" re-scans) and `nodesrtt`, `mheard`, `rtknown` (live
  snapshots, DELETE + re-INSERT every run since BPQ itself rewrites
  those three files with current state only). Ten new tables
  (`data/system-logs-schema.sql`): `sys_events`, `sys_station_daily`,
  `sys_internet_stations`, `rms_connections`, `rms_login_failures`,
  `tcp_events`, `net_nodes_rtt`, `net_mheard`, `net_rtknown`,
  `sys_logs_parser_state`. New `system-logs-api.php` (11 endpoints).
  **Scope is stats/charts only** — the raw log viewer and its
  search/filter UI keep reading the log files directly, unchanged, by
  design (millions of raw lines vs. thousands of classified events;
  the viewer was never the slow part). One row per classified event,
  not per raw line — same philosophy as `rf_messages` — with
  `sys_events.raw_line` populated only for `event_type='error'` so the
  Recent Errors list and "find in viewer" keep working. RMS sessions
  now get real per-station byte/duration attribution via Connect/
  Disconnect pairing, an improvement over the original (which summed
  bytes globally since the log's Disconnect line carries no callsign).
  See `SYSTEM-LOGS-DB-MIGRATION.md` for deploy steps, the two inherited
  parsing quirks kept for parity, and the verify checklist.

- **`bpq-traffic.html` migrated to DB-backed data** — Previously fetched
  and parsed every `Traffic_YYMMDD.txt` file client-side on every page
  load. New `traffic_stats`/`traffic_parser_state` tables (own schema,
  not an extension of `rf_connections` — BPQ's traffic accounting
  covers every connection type, not just VARA HF), a new
  `scripts/parse-traffic-report.py` parser (5-minute cron, same cadence
  as `parse-vara-log.py`), and a new `traffic-api.php` endpoint. The
  page tries the DB-backed path first and falls back to legacy
  client-side file parsing if the API's unavailable. Deployed and
  verified live 2026-09-20 (see `docs/SESSION-JOURNAL.md`).

- **`bbs-messages.html` — mute individual bulletin distribution groups**
  — Each group under Bulletins in the sidebar (SITREP, WX, PKTNET, etc.)
  now has a 🔔 toggle; muting a group removes its row from the sidebar
  list entirely (not just its messages) and drops it from the umbrella
  Bulletins view and unread badge — aimed at trimming a long group list
  down to what you actually read. A "Muted bulletin groups" chip list
  in Settings is the way back: click a chip's ✕ to unmute (its sidebar
  row returns), or click its label to open that folder directly without
  unmuting. Display-only — BPQ still receives, stores, and forwards
  every muted group exactly as before; this doesn't touch BPQ32's own
  mailbox forwarding/distribution-area configuration, which is what
  actually controls what gets downloaded in the first place. Also worth
  knowing: any bulletin-group folder, muted or not, is exempt from the
  automatic 30-day bulletin purge (same rule as any other filed
  folder), so a muted group will keep accumulating in storage unless
  cleared out manually.

- **`bpq-rf-connections.html` — "TPRFN Hub Station Forwarding Partners" and "Messages To Be Forwarded by RF" merged into one card** — Both cards were keyed off the same partner list; each partner tile now shows last-connect status and its live FWD QUEUE pending-message count together instead of split across two cards.

- **`bpq-rf-connections.html` — Msgs Sent/Recv now reflect real message data, not a byte-count guess** — The top "RF Connections Status" card's `Msgs Sent`/`Msgs Recv` previously came from the MSG_THRESHOLD heuristic (>=175 bytes transferred on a connection = "probably a message"), while the Forwarded Messages panel already showed the real, BID-accurate count from actual message parsing — two different numbers answering the same question. `recalculateStats()` now overwrites the heuristic with real counts (also fixing the Hourly Chart's message bars/summary, which read the same `stats` fields) whenever BBS logs loaded successfully; the heuristic remains only as a fallback if they didn't, flagged with a small "(est.)" badge so it's clear when the number is a guess.

### Fixed

- **`install.sh` security**: it linked the web `logs/` folder to the
  *whole* LinBPQ folder (exposing `bpq32.cfg` and its passwords), and its
  web-server config lacked protection rules. It now links LinBPQ's `logs`
  subfolder and blocks code, data, SQL, config, backups, docs and non-log
  files under `/logs/` (nginx and Apache, both tested live).
- **`install.sh` admin protection**: the password rule matched the admin
  pages' old top-level names, so everything under `/admin/` (pages and
  APIs) was open. The whole `/admin/` folder is now protected, and the old
  URLs redirect.
- **`install.sh` completeness**: loads all 6 base schemas (was 3) plus the
  upgrade migrations; schedules the three log parsers
  (`/etc/cron.d/bpq-dashboard`) and `bulletin-retention.timer`; installs
  `python3-pymysql`, `-reportlab`, `-websockets` and `rsync`; writes
  `station.grid` (computed from lat/lon), `station.hub`, the `aprs`
  section and `paths.linbpq` into `config.php`; creates the cache
  subfolders and `data/partners.json`; downloads the APRS symbol sprites;
  sets PHP `max_execution_time` 90; fixes the vara-logger paths; and
  never copies `private/`, `.git` or the journal into the web root.
- **`install-check.php` false failures**: admin pages/APIs were checked at
  the top level; it checked long-renamed `dashboard-*.php` files and
  tables no schema creates; its daemon-path check compared against a
  literal placeholder; it required `logs/` to be writable; the optional
  watchdog/validator showed as failures; and APRS used old config keys.
  It now also checks the Python modules and the parser schedule.
- `vara-logger.service` / `vara-logger.sh` pointed at `/var/www/html/bpq`.

- `USER-MANUAL.md` §4.3 listed `station.call`; the key is
  `station.callsign`. The example coordinates are now generic (W1AW).

- **`msgdb_save_message()` blanked stored bodies on re-file** — filing a
  message whose body wasn't loaded in that browser (including rule-based
  auto-filing, which runs before bodies are fetched) overwrote the stored
  body with an empty string. Now keeps the existing body unless a new
  one is supplied.

- **`scripts/parse-system-logs.py` — snapshot poller (`net_nodes_rtt`/
  `net_mheard`/`net_rtknown`) failed with `DROP command denied to user
  'tprfn_app'@'localhost'`** — the three live-snapshot tables were
  cleared with `TRUNCATE TABLE` before each re-poll, which is DDL and
  requires the `DROP` privilege; a correctly-scoped app DB user
  (`SELECT`/`INSERT`/`UPDATE`/`DELETE` only) doesn't have that, by
  design. Switched to `DELETE FROM`, which only needs `DELETE` (already
  granted) and, as a side benefit, participates properly in the
  surrounding transaction — a failure partway through repopulating one
  of these tables now rolls back cleanly instead of leaving it empty
  (TRUNCATE's implicit-commit behavior meant a rollback couldn't undo
  it). No schema, API, or HTML changes needed — reproduced against a
  real least-privilege DB user before and after the fix. See
  `SYSTEM-LOGS-DB-MIGRATION.md`.

- **`bpq-rf-connections.html` — Forwarded Messages panel (and TPRFN Hub
  Station tile counts) included messages carried over NET/ROM/AX.25 and
  Telnet, not just RF (VARA HF)** — `rf_messages` was populated purely
  from the BBS forwarding log's FA/FC lines, which record a proposal
  identically no matter which port carried it, and the partner callsign
  was stored with its SSID stripped — so e.g. `N3MEL-2` (a real VARA HF
  hub partner) and `N3MEL-7` (a NET/ROM node) both collapsed to the same
  `N3MEL` partner and were uncountable-apart. `scripts/parse-vara-log.py`
  now correlates every message against BPQ's `ConnectLog_YYMMDD.log`
  (`build_connect_mode_lookup()`/`lookup_mode_for_message()`, indexed by
  callsign with binary search — not a linear scan, which hung in
  practice against a full-history ConnectLog of ~250K events) and tags it
  with the medium that actually carried it (`VARA`/`NETROM`/`AX.25`/
  `TELNET`); `rf_messages` gets a new `medium` column
  (`sql/2026-09-21-rf-messages-medium.sql`); `getFilteredForwardedMessages()`
  filters to `medium === 'VARA'` (treating unknown/uncorrelated as "keep,
  not proven non-RF" so ConnectLog coverage gaps never hide real
  messages). Fixes every consumer of that shared function at once — the
  message table, hub station tiles, summary card, hourly chart, station
  modals, and Propagation Report. See
  `RF-MESSAGES-MEDIUM-MIGRATION.md` for deploy + backfill steps.

- **`bpq-rf-connections.html` — Hourly RF Connections & Messages chart
  overcounted messages from non-RF-forwarding-partner stations** — The
  chart's Msgs Sent/Recv totals were pulled from the full real-message
  set with no partner filter, so they could include traffic with
  stations outside the configured RF forwarding partner list (e.g.
  telnet-forwarded systems). Now filtered to configured RF forwarding
  partners only, matching the convention the per-partner tiles already
  used — chart and tiles now agree.

---

## [1.5.12] - 2026-08-30

### Added

- **bpq-telnet.html — YAPP Browse Files** — New 📂 Browse Files button in the YAPP modal Receive tab queries the BBS for available files and renders them as a clickable list. Click a file → automatically arms the receiver and sends `YAPP <filename>` to initiate the transfer. Works on local BPQ BBS and on remote BBSes you're connected to. Handles pagination (`--More--`), empty file areas, and filenames containing spaces. Sizes shown human-readable (B / KB / MB). Closes the last UX gap in the YAPP feature: users no longer need to know filenames in advance.

- **scripts/bpq-telnet-daemon.py — `yapp_list_files` WS action** — Issues `Files
` to the connected BBS, captures the response (suspending normal text passthrough so the listing doesn't spam the terminal), parses each line as `<filename> <size>`, and returns a structured `[{name, size}, ...]` array to the browser. Auto-sends space for pagination prompts. 10-second hard timeout; 1.5-second stability fallback. The parser tolerates filenames with spaces by taking the last whitespace-separated token as the size.

### Fixed

- **bbs-messages.php — bulletin/message fetch count-limiting sliced the wrong end of the list (BIG)** — All three count-limiting call sites (`fetchMessageListByType()`, `fetchAllLists()`, `fetchBulletins()`) assumed BPQ's L* listing commands return messages oldest-first and sliced from the end of the array to keep the newest `$count`. Verified against raw fetch logs that BPQ's actual output is newest-first — so the slice was keeping the *oldest* `$count` and silently discarding everything newer, every single fetch. This was the real cause of bulletins appearing to stop arriving for days at a time; it happened upstream of the database and browser entirely, so no amount of frontend or archive-layer fixing could have caught it. All four `array_slice(..., -$count)` calls changed to `array_slice(..., 0, $count)`; truncation-warning log text corrected to match (a truncated response is now correctly understood as missing the *oldest* entries, not the newest).

- **bbs-messages.html — bulletin Kill blocked on `_stale` messages, defeating the "already gone" handling meant to unstick them** — `actKill()`/`bulkKill()`'s safety gate (bulletins can only be killed once BPQ shows status `BF`, to avoid pulling one out of the outbound queue before it forwards) applied unconditionally, including to bulletins already confirmed missing from BPQ's own mailbox (`_stale`). That meant a stale bulletin's Kill request never reached the backend's "Not found" → `alreadyGone` handling — it was rejected client-side before the request was ever sent, so it could never actually be removed. Both gates now let a message through when `_stale` is true regardless of status; genuinely live, unforwarded bulletins are still blocked exactly as before.

- **bbs-messages.html — new-mail archiving could lose an entire fetch to a rate-limit collision with its own housekeeping** — `getMail()` fired a burst of concurrent folder-filing/auto-creation requests (`applyRulesToInboxes()`) at `message-storage.php` *before* the one call that actually persists the fetched batch to the database (`archiveFetchedMessages()`). Both share the same endpoint's nginx rate-limit budget; a large enough filing burst (e.g. a big bulletin-group delivery) could exhaust it, leaving the archive call to get rejected with no retry and no user-visible error — that entire batch of new mail then simply never existed in the database. Fixed by reordering (archive now runs first, claiming the budget before filing) and adding a single retry with backoff on a failed/non-OK archive response instead of silently dropping it.

- **bbs-messages.html — message sort assumed `bbs_number` only ever increases** — Every message-list sort (`mergeInbox()`, `mergeArchiveIntoInbox()`, both `currentFolderMessages()` sorts) ordered purely by `bbs_number DESC`, which silently breaks the moment BPQ's own message counter wraps around and starts again from a low number — old, large pre-wrap numbers then permanently outrank genuinely newer, small post-wrap ones. All four sort sites now key on a proper receipt timestamp (`_archivedAt` — sourced from the DB's `first_seen_at` for archived messages, or stamped at receipt time for freshly live-fetched ones) and only fall back to `bbs_number` when neither side has one. Both merge functions preserve a message's original `_archivedAt` across re-fetches rather than re-stamping it, so order stays stable across repeated Get Mail cycles.

### Changed

- **Light-mode CSS now covers all dashboard pages** — Eight admin/utility pages were previously dark-only and ignored the theme toggle. Each now defines a `[data-theme="light"]` block that redefines its CSS variables for light backgrounds with darker accent colors for proper contrast: firewall-status, log-viewer, system-audit, bpq-maintenance, bpq-vara, rig-status, bpq-telnet, rf-power-monitor. Two pages use Tailwind (rf-power-monitor) or other unique structures and got targeted utility-class overrides. Deliberate "stay dark" exceptions: the terminal panes in bpq-telnet and bpq-vara retain their green-on-black look since that's the conventional terminal aesthetic, and the rf-power-monitor's meter card retains its instrument-dark gradient since LED-style readouts work better dark. With this change, every page on the dashboard responds correctly to the day/night toggle.

- **firewall-api.php — column-index off-by-one fixed (BIG)** — The iptables parser was reading source from `$parts[7]` which is the OUT interface field (always `*` for INPUT chain rules). Real source address is at `$parts[8]`. Effect: every port was categorized as "ip-restricted" because the `strpos($source, '10.0.0.')` checks always failed, so Public/LAN-Only/Blocked summary cards all read 0. Fix: shift indices to `$parts[8]` (source) / `$parts[9]` (dest) / `$parts[10]+$parts[11]` (extra). Page now shows correct categorization and real source IPs in the rules table.

- **firewall-api.php / system-audit-api.php — sudoers documentation** — Updated comment blocks to reflect that both `fail2ban-client status` AND `fail2ban-client status *` rules are required. The wildcard `*` in sudoers matches ONE OR MORE args (not zero), so bare `status` (used to list all jails) needs its own rule. Without this, per-jail data was returning empty.

- **system-audit-api.php — SSL cert check now covers all configured domains** — The original check hardcoded a single domain. Operators with multiple Let's Encrypt certs (e.g. one for the public dashboard, one for an admin domain) were getting one cert monitored and the others silently expiring. The check now iterates a `$cfg['ssl_domains']` config array, or falls back to `$_SERVER['HTTP_HOST']` if not set. Each domain gets its own finding row so per-cert expiry is visible at a glance.

- **system-audit-api.php — openssl s_client calls now have 5-second timeouts** — Without an explicit timeout, openssl s_client can hang for 30+ seconds when a domain is unreachable, eventually triggering PHP-FPM's request_terminate_timeout and crashing the worker. Browser then saw "JSON.parse: unexpected character" because nginx received a connection reset with no body. Wrapped in `timeout 5 sh -c '...'` to guarantee the call returns within 5 seconds even if the network is hung. Also added RFC-1035 hostname validation on the domain string before passing to shell.

- **system-audit-api.php — nginx-stale-configs check now compares directories dynamically** — Previously hardcoded `^(default|tprfn\.conf)$` as the "not stale" filter, with remediation hardcoding specific filenames. Replaced with `array_diff(sites-available, sites-enabled, ['default'])` — actually computes which configs are present-but-not-symlinked. Remediation lists the real stale filenames at runtime instead of guessing. Works on any installation.

- **rig-status.html — error messages now config-aware** — Static "flrig offline" error no longer hardcodes `127.0.0.1` (stale after FLRIG migration). Catch block now displays the actual API error message dynamically rather than the placeholder text, so failures show useful info like "Cannot reach flrig at 10.0.0.131:12345".

---

## [1.5.11] - 2026-05-21

### Added

- **bpq-telnet.html — YAPP file transfer** — Binary file transfer via YAPP protocol (Yet Another Packet Protocol) and YAPP-C (FBB extension with checksum). New 📁 YAPP button next to Send opens a floating panel (non-blocking — terminal stays interactive). Receive tab arms an incoming-file watcher; downloaded files land in `/var/www/<dashboard>/yapp-files/` with the real filename from the HD packet (e.g. `20260521-000614-update-bpq` rather than a generic `.bin`). Download link in the panel reflects the proper name. Send tab picks a local file and pushes it via YAPP. Requires FBB-mode connection (port 8011 — transparent TCP, no telnet IAC) — the modal includes an "FBB Mode Required" warning with BPQ credential inputs and a "Switch to FBB Mode" button that reconnects via FBB and auto-logs in. Progress bar with KB/total updates in real time. Abort button cancels mid-transfer. 10 MB file size cap, filename sanitization, 120s no-progress timeout. **Verified end-to-end in production**: local 55-byte test transfer in ~3 seconds, and a real over-the-air 2,139-byte sysop-to-sysop transfer with neighboring BBS — receiving a bash script (`update-bpq`) cleanly through the YAPP protocol over packet radio.

- **scripts/yapp_protocol.py** — NEW transport-agnostic YAPP 1.1 state machine (~660 lines). Implements all packet types per WA7MBL spec and FBB YappC extension. Round-trip tested locally with both classic and YappC variants. Used by the telnet daemon to handle transfer state — sends RR after SI, parses HD for filename/size, negotiates YappC via RT (ACK 02 ACK), receives DT data blocks, ACKs EF with AF, handles ET (with checksum byte in YappC mode), responds with AT. Tolerates BPQ's quirk of sending classic-style EOT (`04 01`) even when YappC was negotiated. On successful receive, the on-disk file is renamed from `<timestamp>-pending.bin` to `<timestamp>-<real-filename-from-HD>` (sanitized: alphanumerics + dot/underscore/dash only, 80-char limit).

- **scripts/bpq-telnet-daemon.py — YAPP integration** — TelnetSession now routes incoming TCP bytes through the YAPP state machine when armed (instead of decoding as text). New WebSocket actions: `yapp_send` (file upload from browser as base64), `yapp_recv` (arm receiver), `yapp_abort`. Daemon emits `yapp_started`, `yapp_progress`, `yapp_complete`, `yapp_error` messages back to the browser. Verbose RX/TX hex byte logging during transfers for diagnostics. 10 MB transfer size cap. Auto-creates `/var/www/<dashboard>/yapp-files/` on startup.

- **scripts/bpq-telnet-daemon.py — FBB mode connection** — TelnetSession constructor accepts `fbb_mode`, `username`, `password` parameters. When `fbb_mode=True`, after TCP connect the daemon waits 300ms then sends `username\rpassword\r` for transparent FBB-mode authentication (required for YAPP — port 8011 on standard BPQ installs). IAC option-negotiation stripping is bypassed in FBB mode since it's a binary-clean TCP path. WebSocket `connect` action accepts `fbb`, `username`, `password` fields; `connected` response includes `fbb` flag. Outbound text commands use `\r` (not `\r\n`) line endings in FBB mode to avoid spurious LF bytes confusing BPQ.

- **bpq-maintenance.html — YAPP File Transfer section** — New ops section with 7 commands: list received files with sizes, filter daemon log for YAPP events, restart bpq-telnet, verify FBBPORT config, check listening ports, 30-day cleanup find command, and run the YAPP protocol self-test.

- **system-audit-api.php — bpq-telnet daemon check** — Auditor now checks bpq-telnet daemon health alongside bpq-vara/chat/aprs. Reports OK if active (no warning on stale heartbeat since telnet daemon only writes during active connections). Critical if not active.

- **system-audit-api.php — YAPP files directory monitor** — Counts files and total size in `/var/www/<dashboard>/yapp-files/`. Warns at 100 MB and 500 MB thresholds with cleanup suggestion using `find -mtime +30 -delete`.

### Changed

- **Version badges synced across all pages** — Bumped from v1.5.10 → v1.5.11 across all 18 dashboard pages. Pages that previously lacked a version badge (bpq-telnet, bpq-vara, firewall-status, log-viewer, rf-power-monitor, rig-status, system-audit, bpq-aprs, bpq-chat) now display one. Consistent purple-to-blue gradient pill styling on all badges.

- **Nav rename: BBS → Email** — The BBS nav link now reads "Email" since the page is a full-featured email client (NTS-aware inbox, custom folders, multi-select, forwarding visibility, address book). Page title also updated to "Email Client" from "BBS Mail". The internal `data-nav-page="bbs"` key is preserved so existing per-page nav highlighting continues to work.

- **Theme toggle now in shared nav** — Day/night theme button (🌙 / ☀️) added to the shared `_nav.html` between the local clock and the right-edge controls. Auto-appears on every page since they all load the shared nav. Uses the same `bpq-dark-mode` localStorage key and `data-theme` attribute pattern that existing per-page theme code uses, so pages with their own theme code continue to work — `bpq-nav-loader.js` only installs the central implementation if `window.toggleDarkMode` isn't already defined. Pages without their own theme code now get the central implementation, including OS-preference fallback via `prefers-color-scheme`.

- **`bbs-messages.html` dark mode** — Was the only one of the daily-driver pages without dark-mode CSS. Now uses a clean CSS-variables-flip override (`[data-theme="dark"]` redefines `--bg-page`, `--bg-panel`, `--text-strong` etc.), so the entire 3-column email client switches between light and dark cleanly. Inputs, panels, sidebar, and message list all adapt automatically.

- **Removed legacy duplicate navs from `bpq-aprs.html` and `bpq-chat.html`** — Both pages had a static `<div id="topbar">` legacy nav with hard-coded links (no Telnet/VARA/Rig/RF Power/Admin, said "BBS" instead of Email) that pre-dated the shared `_nav.html` system. The shared nav was loaded too, so users saw double nav bars. Removed the legacy block; pages now use only the shared nav like the rest. Also made the `applyTheme()` and clock-update functions null-safe for the legacy nav's removed DOM elements (`.theme-btn` and `#utcClock`), preventing the APRS map regression where the JavaScript error blocked `initMap()` from running.

- **bpq-maintenance.html — Health Check expanded to 8 daemons** — Was listing 5 (nginx, php8.3-fpm, bpq, vara-validator, bpq-vara). Added the 3 missing dashboard daemons: bpq-telnet (powers Telnet page + YAPP), bpq-chat (powers Chat page), bpq-aprs (powers APRS map data). Each has restart command, log path, and description.

- **install-check.php — page list complete** — Now verifies all 17 dashboard pages on a fresh install. Previously missing: bpq-telnet, bpq-vara, rig-status, rf-power-monitor. Renamed "BBS Messages" → "Email Client" and "Email Monitor" → "Message Monitor".

- **system-audit-api.php — SSL cert check now covers all configured domains** — The original check hardcoded a single domain. Operators with multiple Let's Encrypt certs (e.g. one for the public dashboard, one for an admin domain) were getting one cert monitored and the others silently expiring. The check now iterates a `$cfg['ssl_domains']` config array, or falls back to `$_SERVER['HTTP_HOST']` if not set. Each domain gets its own finding row so per-cert expiry is visible at a glance.

- **system-audit-api.php — openssl s_client calls now have 5-second timeouts** — Without an explicit timeout, openssl s_client can hang for 30+ seconds when a domain is unreachable, eventually triggering PHP-FPM's request_terminate_timeout and crashing the worker. Browser then saw "JSON.parse: unexpected character" because nginx received a connection reset with no body. Wrapped in `timeout 5 sh -c '...'` to guarantee the call returns within 5 seconds even if the network is hung. Also added RFC-1035 hostname validation on the domain string before passing to shell.

- **system-audit-api.php — nginx-stale-configs check now compares directories dynamically** — Previously hardcoded `^(default|tprfn\.conf)$` as the "not stale" filter, with remediation hardcoding specific filenames. Replaced with `array_diff(sites-available, sites-enabled, ['default'])` — actually computes which configs are present-but-not-symlinked. Remediation lists the real stale filenames at runtime instead of guessing. Works on any installation.

- **rig-status.html — error messages now config-aware** — Static "flrig offline" error no longer hardcodes `127.0.0.1` (stale after FLRIG migration). Generic text references config.php instead. Catch block now displays the actual API error message dynamically rather than the placeholder text, so failures show useful info like "Cannot reach flrig at 10.0.0.131:12345".

- **nws-dashboard.html — Convective panel now nationwide** — Previously scoped to NWS Norman OK only (which excluded all other WFOs and SPC's nationwide watches). The filter now matches purely on convective event type (Tornado / Severe Thunderstorm / Flash Flood Watches and Warnings) across all NWS offices. The Storm Prediction Center's nationwide watches are now included. Panel renamed from "Norman OK Convective" to "Severe Weather"; modal renamed from "Norman OK Convective Alerts" to "Severe Weather — Watches & Warnings". Each row's badge shows the actual issuing office (e.g. "Norman OK", "Mount Holly NJ", "Storm Prediction Center") with full sender name in the tooltip. SPC badges are purple, WFO badges are red. Built-in severity ranking: Warnings before Watches; Tornado before Severe Tstm before Flash Flood; within tier, newest first. Also added "Severe Thunderstorm Watch Statement" and "Tornado Watch Statement" to the event-type allowlist (SPC issues these as ongoing status updates for active watches).

### Removed

- **`hub-ops.html`** — Multi-station hub operations view deleted. The page was a vestigial admin tool no longer used. Cleaned up four references: `_nav.html` Admin dropdown link, `bpq-nav-loader.js` `ADMIN_KEYS` array, `install-check.php` page list, `bpq-maintenance.html` nginx auth path docs, and the redirect/auth-protection blocks from `bpq-dashboard.conf`. Admin dropdown now has 4 items: Maintenance, Firewall, Logs, Audit.

### Deployment notes (operator-only, not code changes)

- **FLRIG host moved** from local Wine instance to LAN host. Fix made in `/var/www/<dashboard>/config.php` `radio.flrig_host` — code in `vara-api.php` correctly reads this from config when present and falls back to `127.0.0.1` otherwise. `bpq32.cfg`'s `FLRIG ...:12345 HAMLIB=...:4532` line in `/path/to/linbpq/bpq32.cfg` may also need updating if VARA HF outbound is in use.

---

## [1.5.10] - 2026-05-20

### Added

- **bbs-messages.html — NTS as first-class inbox** — Traffic (T) messages now live in their own dedicated sidebar inbox alongside Personal (P) and Bulletins (B), rather than being combined with Personal. Auto-split happens at fetch time; any message identified as traffic (typeName === 'traffic' OR status flag contains 'T') routes to NTS automatically. Migration on page load moves any existing traffic out of personal into nts. The "Personal & Traffic" label became just "Personal" since traffic now has its own home.
- **bbs-messages.html — Custom mail folders** — Hybrid local folders for filing messages. Sidebar "My Folders" section with create/rename/delete via folder modal. Per-folder unread badge that matches built-in inbox behavior (hides at zero). Filed messages get a "▸ FolderName" chip after their subject. Deleting a populated folder returns its messages to their origin inbox rather than discarding them.
- **bbs-messages.html — Multi-select with checkboxes** — Checkbox column on every message row plus a master select-all checkbox in the list header (respects search filter, shows indeterminate state). Selection toolbar appears above the list when ≥1 message selected with bulk actions: Move to folder, Mark Read, Archive, Kill (with confirmation), Clear selection. Bulk Kill is serial to avoid BPQ telnet collisions.
- **bbs-messages.html — Preview-pane Move button** — File the currently-open message into a folder without using the multi-select flow. Dropdown shows existing folders, "+ New folder…" (auto-moves on create), and an "Unfile (return to inbox)" option for already-filed messages. Button label reflects current folder ("Move (FolderName) ▾").
- **bbs-messages.html — Body-derived address book** — Address book entries now use the proper reply address from the message body's `From:` line (e.g., `K7EK@K7EK.#NOKY.KY.USA.NOAM`) rather than the bare callsign from the listing row. New "Rescan bodies" button in the Address Book modal manually re-scans all cached message bodies. Retroactive scan also runs silently on every page load.
- **bbs-messages.html — Killed message tracking** — Messages you kill are remembered for 90 days so BPQ's eventually-consistent kill propagation can't redeliver them. New `bbs_v2_killed_set` localStorage key tracks `{ msgNumber: timestamp }`. Get Mail filters fetched results against the kill list. Preferences modal shows the current count with a "Forget killed list" button.
- **bbs-messages.html — Forwarding Activity** — New `↗ Forwarding Activity…` sidebar item between BBSes Seen and Settings. Shows recent outbound forwarding events from this BBS parsed from the daily BBS log: destination BBS, type (FA/FC), timestamp, sender/recipient/subject, BID, size. Time window selectable (1h to 7 days). Sidebar badge auto-refreshes every 5 minutes with the current 24-hour forward count.
- **bbs-messages.html — Per-message forwarding events** — When viewing any message that has a BID, the preview pane shows a "Forwarded by this BBS" panel below the body listing every destination BBS the message was relayed to, with timestamps and protocol (FA vs FC). Useful for NTS traffic handlers who need to demonstrate the message was relayed onward.
- **bbs-forwards.php** — NEW endpoint that parses BPQ's daily BBS log (typically `/var/www/<dashboard>/logs/log_YYMMDD_BBS.txt`) for FA (Forward Available) and FC (Forward Compressed) outbound events. Three actions: `list` (recent events with hours/limit filtering), `for_bid` (events for a specific BID across last 7 days), `stats` (24-hour summary). 30-second result cache; streams log file line-by-line for memory efficiency.

### Fixed

- **bbs-messages.html — Custom folder click handlers** — Three locations were building inline `onclick="moveSelectedToFolder(${JSON.stringify(name)})"` patterns. Because `JSON.stringify` produces double-quoted output and the outer `onclick=""` also uses double quotes, the rendered HTML prematurely closed the attribute and the click handler became a no-op. All three (bulk Move dropdown, sidebar folder nav, preview Move dropdown) now use `data-folder-name` attributes with `addEventListener` wiring.
- **bbs-messages.html — Folder badge counted total, not unread** — Custom folder badges in the sidebar were counting all messages in the folder rather than unread-only. Now matches Personal & Bulletins behavior: shows count of unread messages and hides entirely when all are read.
- **bbs-messages.html — Emoji rendering on Windows** — The 📁 (file folder) and 📩 (envelope with arrow) emoji didn't render in Firefox's font fallback on Windows, causing blank-looking buttons in the bulk-select toolbar and preview-pane toolbar. Replaced with text labels and a small ▸ triangle for folder iconography. Other emoji on the page render fine and are unchanged.
- **bbs-messages.html — Address book scan order** — Address book recording moved from before body prefetch to after, so `recordContact` can scan each message's body `From:` line. Previously contacts were recorded with just the bare callsign because bodies weren't loaded yet.

### Changed

- **bbs-messages.html — Storage keys** — New persistent keys: `bbs_v2_custom_folders`, `bbs_v2_killed_set`. Existing keys unchanged.
- **bbs-messages.html — Personal inbox semantics** — `inboxes.personal` now contains P-only messages (was P+T merged). Existing local state is migrated automatically on first page load after upgrade.

---

## [1.5.9] - 2026-05-20

### Added

- **bbs-messages.html — Email-client redesign (v2)** — Complete rewrite as a 3-column email-client UI replacing the old single-pane interface. Two auto-categorized inboxes: Personal & Traffic (P+T merged), and Bulletins (B). Sidebar with unread badges, archived/sent folders, address book, BBSes-seen, settings, and statistics.
- **bbs-messages.html — Auto-fetch timer** — Configurable interval (off / 1 / 5 / 15 / 30 / 60 min), pauses on backgrounded tabs, visible countdown chip. Defaults to 5 min.
- **bbs-messages.html — Full message actions** — Reply (auto-quotes original), Forward via BPQ's `SC` (Send Copy) command, Kill, Mark Unread, Archive (local), Properties (BPQ status decode).
- **bbs-messages.html — Address Book** — Auto-populated from personal/traffic message senders (bulletins excluded). Tracks first-seen, last-seen, message count, display name, notes. Searchable, sortable by recent/frequent/alphabetical. Click ✉ to compose to a contact.
- **bbs-messages.html — BBSes Seen list** — Separate book tracking BBS callsigns observed in routing fields. Sorted by activity.
- **bbs-messages.html — Compose autocomplete** — Dropdown suggests matching addresses as user types in the To field. Arrow-key navigation, Enter to select. Quick-pick chips above the input show six most recent contacts.
- **bbs-messages.php — Combined-session list_all** — New action that fetches Personal (LP), Traffic (LT), and Bulletins (LB) in a single BPQ telnet session. Replaces three separate sessions, eliminating session-cleanup races that caused intermittent "No password prompt" errors.
- **bbs-messages.php — Action handlers** — `forward` (via BPQ `SC`), `mark` (status flag), `kill` (delete), `list_personal`, `list_traffic`, `list_bulletins`, `list_all`.

### Fixed

- **bbs-messages.php — UTF-8 in json_encode** — Bulletin subjects from non-English BBSes contained bytes that weren't valid UTF-8, causing `json_encode()` to silently return `false` and emit a 0-byte HTTP response for any response containing such messages. All 13 `json_encode()` calls now use the `JSON_INVALID_UTF8_SUBSTITUTE` flag, which replaces bad bytes with the Unicode replacement character instead of failing.
- **bbs-messages.php — Missing Content-Type** — JSON responses were not setting `Content-Type: application/json` (only the password-management endpoint did). nginx defaulted them to `text/html`, which combined with gzip encoding caused some browsers to discard the response. Header is now set unconditionally before the action dispatcher.
- **bbs-messages.php — fclose() hang after Bye** — Removed and then re-added the 500ms `usleep` between sending `B` and `fclose($socket)` in `fetchAllLists()`. Without it, the orderly TCP shutdown blocked PHP-FPM workers waiting for the peer's FIN. The half-second sleep is functional, not cosmetic.
- **bbs-messages.html — Concurrent BPQ session collisions** — `Promise.all` parallel fetches and 5-wide body prefetch caused BPQ telnet to interleave responses or refuse new sessions. All list and body fetches are now serial. Added `safeFetchJson()` helper so empty/malformed responses surface as readable errors instead of bubbling `JSON.parse` exceptions.
- **bbs-messages.html — Forward command syntax** — Original `F <msgnum> <addr>` returned "Invalid Command" because BPQ Mail has no user-callable `F`. Replaced with `SC <msgnum> <addr>` (Send Copy) per BPQ documentation.
- **bbs-messages.html — Send action routing** — Compose POST was putting `action=send` in the URL query string, but the PHP POST handler reads `$action` from the JSON body. Moved to the body. Send/Reply/Forward all work end-to-end.
- **bbs-messages.html — Emoji rendering on Windows** — The 📁 (file folder) and 📩 (envelope with arrow) emoji didn't render in Firefox's font fallback on Windows, causing blank-looking buttons in the bulk-select toolbar and preview-pane toolbar. Replaced with text labels and a small ▸ triangle for folder iconography. Other emoji on the page (🗂 🗑 ✕ ↩ → ℹ etc.) render fine and are unchanged.

### Changed

- **nginx — fastcgi_buffering off for bbs-messages.php** — Added a dedicated location block disabling FastCGI buffering for the BBS Mail endpoint. Not strictly required (the real bug was the UTF-8 issue), but defends against future large-response buffer-truncation bugs and adds 120s read/send timeouts for telnet operations.
- **bbs-messages.html — localStorage keys** — Migrated to `bbs_v2_*` keyspace. Old `bbs_saved_messages` and related keys are untouched but no longer read. Clean-slate migration. New keys added for custom folders (`bbs_v2_custom_folders`) and address/BBS books.

---

## [1.5.5-patch2] - 2026-04-06

### Added

- **bpq-aprs.html — APRS Messaging modal** — Send/inbox two-way messaging. Compose tab with 67-char limit. Inbox for messages to YOURCALL-1. Reply button. Unread badge. Double-click marker or click list pre-fills callsign.
- **bpq-aprs.html — Full WX detail panel** — All APRS WX fields in imperial (°F, mph, inHg, inches). Wind direction as compass bearing. Both popup and detail panel updated.
- **bpq-aprs.html — Station Browser modal** — Categorized by Mobile/WX/Digi/iGate/Fixed/Object. Sorted by distance. Expandable rows with full details. Show on map / Message / aprs.fi links.
- **bpq-aprs.html — Distance from home station** — Haversine distance + compass bearing for all WX stations in popup, detail panel, browser summary and browser detail.
- **bpq-aprs.html — PHG/RNG circles** — ⭕ PHG toggle button. Parses PHGpppp and RNGnnnn from comments. Color-coded by station type. Excludes mobiles.
- **install-check.php — Post-installation troubleshooter** — 16 check categories: PHP, Nginx, HTML files, PHP files, Scripts, Data files, Directories, Config, Service files, Cron jobs, Log files, LinBPQ, Database, Network, Security. Password protected. Daemon heartbeat checks, cron race condition detection, malicious cron detection.

---

## [1.5.5-patch1] - 2026-04-05

### Added

- **bpq-aprs.html — Mobile track trails** — Solid blue polyline with position dots. 2-hour history. Toggle with Tracks button. Loads 12-hour server-side history from daemon on page load.
- **bpq-aprs.html — Digi path lines** — aprs.fi style: hover station to see green/blue/purple dashed lines to digipeaters and iGate. parseDigiPath() extracts used digis from path field.
- **bpq-aprs.html — Time filter** — ⏱ Last heard dropdown (1-12 hours). Filters markers, list, and track length simultaneously.
- **bpq-aprs-daemon.py — APRS-IS daemon** — Persistent Python daemon, stays connected 24/7. Writes stations/history/messages cache every 15s. Auto-reconnect. 12-hour track history server-side.
- **bpq-aprs.service — systemd service** — enabled, runs as www-data, auto-restart.

### Fixed

- **bpq-aprs-daemon — No packets bug** — APRS-IS requires vers NAME SPACE VERSION. "vers test" invalid. Fixed to "vers BPQ-Dashboard 1.0". Switched to rotate.aprs2.net.
- **bpq-aprs.php — Simplified** — Now cache-reader only, no direct APRS-IS connection.

---

## [1.5.5] - 2026-04-04

### Added

- **bbs-messages.html — Folder unread indicators** — Icon changes 📁→📬, folder name turns blue/bold with accent border, red unread count badge. Cleared when folder opened.
- **bbs-messages.html — Sort by column** — All 5 column headers clickable (#, Date, From, To, Subject). Toggle asc/desc with arrow indicators. Default Date ↓.
- **bbs-messages.html — Rule-matched highlighting** — Green border/background/text on rule-matched messages. ⚙ RuleName badge. Highlight cleared when message opened.
- **bbs-messages.html — Search feature** — Live search across Subject/From/To/Body/#/Date. Yellow match highlighting. N found counter. ✕ and Escape to clear.
- **bpq-aprs.html — New APRS dashboard page** — Leaflet map centered on Hephzibah GA with 150km radius. Station list, APRS messages panel, station detail overlay. Auto-refresh every 2 minutes.
- **bpq-aprs.php — APRS-IS backend** — Connects directly to noam.aprs2.net:14580, parses all packet types, caches stations. No BPQ32 APRSDIGI required.
- **APRS symbol sprites** — Two local PNG sprite sheets from hessu/aprs-symbols. Primary and alternate symbol tables. 24x24px symbols.
- **tprfn.conf — Extended timeouts** — bpq-aprs.php (60s) and bpq-chat.php (90s) location blocks added.

---

## [1.5.4-patch6] - 2026-04-04

### Fixed

- **bpq-chat — Who panel 171+ users** — who action was returning entire /U history. Fixed by advancing seq before sending /U so only the fresh response is returned.
- **bpq-chat — Who panel not updating** — startWhoRefresh() missing from guest and page-resume paths. Added to startReadOnlyPoll() and init check.
- **bpq-chat — renderWho() null crash** — whoEmpty div destroyed by innerHTML clear. Fixed to rebuild inline. Added null guard at top of renderWho().
- **bpq-chat — Join/leave regex** — corrected to match actual BPQ format (name after colon for joins).

---

## [1.5.4-patch5] - 2026-04-04

### Added

- **bpq-chat.html — Who Online panel** — Persistent right sidebar showing users grouped by topic. Your callsign highlighted blue, others green. Shows callsign, name, node, idle time. Auto-refreshes every 30 seconds. Manual ⟳ refresh button. Toggleable via Who Panel button. Hidden on mobile.

### Fixed

- **bpq-chat.html — Who panel duplicating users** — whoRefreshPending flag prevents same /U response parsed twice. Tighter detection — only triggers on N Station(s) connected: line. /U output suppressed from server terminal pane.

---

## [1.5.4-patch4] - 2026-04-04

### Fixed

- **bpq-chat.html — Double callsign in messages** — sendRoomMsg() and sendServerCmd() were echoing messages locally AND receiving BPQ's echo via poll causing duplicates (YOURCALLYOURCALL: text). Removed local echo — BPQ's echo is the single display source.
- **bpq-chat.html — /B closing entire chat** — /B is BPQ's full chat exit command. In a room tab /B now sends /T General (return to General topic, stay in chat) and closes the tab. /QUIT triggers full disconnect. /B in server pane retains standard BPQ behavior.

---

## [1.5.4-patch3] - 2026-04-04

### Added

- **bpq-chat.html — Auth system** — Auth modal on page load. Three levels: guest (read-only), user (own BPQ credentials), sysop (config.php). Guest mode shows orange banner, disables all send controls. Guest request modal opens mailto: pre-filled to sysop@example.com with callsign/name/note.
- **bpq-chat.php — Per-user credentials** — Non-sysop users can connect with their own BPQ callsign/password, appearing on network as themselves.

### Fixed

- **bpq-chat.php — config.php not loaded** — $BBS_USER undefined error fixed by loading config.php at top of file.
- **bpq-chat.php — phantom require_once** — Removed leftover bpq-chat-connect.php reference causing fatal error.

---

## [1.5.4-patch2] - 2026-04-04

### Added

- **bpq-chat.html — Split screen layout** — Complete redesign. Top pane shows chat server output (always active). Bottom pane shows room tabs. Draggable divider to resize split, touch-friendly. Each /T RoomName command opens a new tab automatically. Multiple rooms supported simultaneously with unread badges.
- **bpq-chat.html — Message routing** — Incoming messages automatically sorted to correct room tab or server pane based on [RoomName] prefix and topic join/leave patterns.
- **bpq-chat.html — Full BPQ command set** — Quick commands panel updated with all BPQ chat commands: /U /N /Q /T /P /A /E /Keepalive /ShowNames /Time /S /F /B /History. Prompt dialogs for /N, /Q, /History nn, /S CALL msg. Corrected /W to /U for Show Users.

---

## [1.5.4-patch1] - 2026-04-04

### Fixed

- **bpq-chat-daemon.py — persistent connection** — Replaced sock.settimeout with select.select() in reader_thread. Timeout exceptions were breaking the read loop causing disconnects.
- **bpq-chat — multiple daemon instances** — Removed pkill from PHP connect. Added fcntl single-instance lock to daemon. Systemd manages lifecycle.
- **bpq-chat — state file going stale** — Added heartbeat_thread() updating state file every 15s. PHP stale threshold raised to 60s.
- **bpq-chat — messages repeating** — Sequence-number tracking replaces timestamp/cookie approach. Daemon writes incrementing seq on each message. PHP tracks last seen seq per session server-side. Session ID passed in request body.
- **bpq-chat — JSON parse error on send** — PHP was writing to FIFO, daemon reading from JSON queue. Fixed PHP sendCommand() to write to chat-cmd-queue.json.
- **bpq-chat — command echo suppressed** — BPQ echoes sent commands back. Added filter for lines matching /^:\s*\//

---

## [1.5.4] - 2026-04-02

### Added

- **bpq-chat.html — BPQ Chat & Terminal client** — Full GUI chat client. Persistent Python daemon (bpq-chat-daemon.py) maintains telnet connection to BPQ node. PHP broker (bpq-chat.php) reads messages from JSON file and sends commands via FIFO pipe. Dark terminal theme, color-coded output, sound alerts, browser notifications, unread badge, quick commands panel, export log, day/night theme toggle.
- **Chat nav link** — bpq-chat.html added to navigation bar on all dashboard pages.

### Changed

- **Version bump to v1.5.4** — All dashboard pages updated from v1.5.3 to v1.5.4.

---

## [1.5.3-patch16] - 2026-04-02

### Fixed

- **visitor-log.php — tprfn-network-map logging** — Visits to YOUR-TPRFN-HUB were not being recorded. Fixed by adding 'tprfn-index-log.php' and 'tprfn-network-map' to the allowed pages list, and adding page name resolver (tprfn-index-log.php → tprfn-network-map). Root cause was the @ error suppressor hiding the write result during debugging — logging was actually working after the allowed list fix was applied.

---

## [1.5.3-patch15] - 2026-03-31

### Fixed

- **callsign-lookup.php — QRZ.com primary lookup** — Archived version was outdated (callook.info + HamDB). Live version on the live server uses QRZ.com as primary with callook.info fallback. Updated both archives with live 876-line version. Lookup confirmed working — browser cache was causing apparent failures. Hard refresh resolved all callsigns except N4DGE (not in QRZ database).

---

## [1.5.3-patch14] - 2026-03-31

### Fixed

- **bbs-messages.html — iOS Safari portrait blank screen** — Viewport meta tag was missing `maximum-scale=1.0, user-scalable=no`. Without these iOS Safari applied its own scaling, reporting `window.innerWidth` larger than physical screen width, preventing the `@media (max-width:767px)` mobile layout from triggering. Fixed to match all other dashboard pages. Also added full iOS Safari height chain: `100svh`/`100dvh`/`100vh` cascade, `@supports (-webkit-touch-callout:none)` detection, `-webkit-fill-available`, `-webkit-overflow-scrolling:touch` on scroll containers, and `viewport-fit=cover` for notch/safe area support.

---

## [1.5.3-patch13] - 2026-03-30

### Fixed

- **Visitor tracking restored** — nginx dual-domain reconfiguration broke visitor logging on both domains. Created `tprfn-index-log.php` wrapper to log TPRFN Network Map visits (index.php is pure HTML, cannot include PHP directly). Restored nginx rewrite rules on `YOUR-DASHBOARD-DOMAIN` to route through `tprfn-rf-log.php`. Both `YOUR-TPRFN-HUB` and `YOUR-DASHBOARD-DOMAIN` visits now tracked in visitor-log.php.

---

## [1.5.3-patch12] - 2026-03-30

### Fixed

- **bbs-messages.html — Infinite recursion on Get Mail** — Mobile responsive patch used JS wrapper functions (`const _origX = X; function X() { _origX()... }`) causing infinite recursion. Fixed by injecting mobile sync calls directly into existing function bodies.
- **bbs-messages.html — Folders wiped on reload** — `loadSavedFromServer()` was replacing the local folder array with the server's list (`['Saved']`), wiping all user-created folders on every page load when server storage mode was active. Fixed to merge local + server folders using Set (no duplicates).
- **bbs-messages.html — Folder list clipping** — With 18 folders the sidebar and save picker overflowed. Added `overflow-y:auto` scroll to `#folderList` and `max-height:280px` to save picker.
- **bbs-messages.html — Wrong localStorage key in deleteFolder** — `persist('bbs_saved', ...)` corrected to `persist('bbs_saved_messages', ...)` in three places.
- **bbs-messages.html — Save picker not updating on folder create** — `createFolder()` now calls `renderSaveFolderList()` to keep save picker in sync.
- **network-api.php — rename() warnings** — `cache/` directory was missing. Fix: `sudo mkdir -p /var/www/<dashboard>/cache && sudo chown -R www-data:www-data /var/www/<dashboard>/cache`.

### Added

- **bbs-messages.html — Rules Test button** — 🔍 Test button in rules modal tests rule against currently loaded inbox, shows match count and preview.
- **bbs-messages.html — Msg # field in rules** — Added message number as a matchable field in the rules engine.

---

## [1.5.3-patch11] - 2026-03-29

### Added

- **tprfn.conf — Dual domain nginx config** — Complete nginx config serving both YOUR-TPRFN-HUB (TPRFN Network Map) and YOUR-DASHBOARD-DOMAIN (BPQ Dashboard) from /var/www/<dashboard>/. YOUR-DASHBOARD-DOMAIN root serves bpq-rf-connections.html. All BPQ Dashboard URLs on YOUR-TPRFN-HUB redirect 301 to YOUR-DASHBOARD-DOMAIN. All original security rules (bot blocking, rate limiting, headers, directory protection) preserved and applied to both domains. Port 514 syslog unaffected.

---

## [1.5.3-patch10] - 2026-03-29

### Added

- **bpq-rf-connections.html — GitHub download banner** — Deep space themed banner between nav and dashboard with animated stars, feature pill badges (RF Analytics, BBS Client, Storm Monitor, Prop Scheduler), and a "Download Free" button linking to https://github.com/bpq-dashboard/bpq-dashboard. Dismissible with × button that saves preference to localStorage.

---

## [1.5.3-patch9] - 2026-03-29

### Fixed

- **bbs-messages.html — Signature not appending** — `buildBody()` now re-reads localStorage as fallback when `signature.text` is empty in memory. `saveSig()` warns if text is empty while enabled.
- **bbs-messages.html — Full mobile responsive layout** — At <768px switches to single-pane app layout with bottom tab bar (Inbox/Message/Folders), slide transitions, touch-friendly rows, sheet-style modals, and mobile bulk select. Desktop three-pane layout unchanged.
- **visitor-log.php — PHP syntax error breaking bpq-rf-connections.html** — Bcrypt hash was truncated by shell `$` expansion during injection, leaving VIEWER_PASSWORD_HASH define unterminated. Fixed with Python. Also fixed placeholder check that was replaced with real hash causing perpetual "not configured" error.

### Added

- **GitHub repository** — Full project published at https://github.com/bpq-dashboard/bpq-dashboard (83 files).

---

## [1.5.3-patch8] - 2026-03-27

### Added

- **bbs-messages.html — Message Rules Engine** — Full email-client-style rules engine for auto-filing messages. Rules match on From/To/Subject using contains/starts-with/equals operators. First matching rule wins. Messages are fetched, saved to the target folder, and removed from Inbox automatically. Rules run silently after every Get Mail and on demand via Apply Rules Now. Sidebar folder badges show blue unread count when rules file new messages; badge clears when folder is opened. Rules manager (⚙ toolbar button) supports add, enable/disable toggle, priority reorder, delete. Rules persisted in `localStorage` as `bbs_rules`. Unread counts persisted as `bbs_unread`.

---

## [1.5.3-patch7] - 2026-03-27

### Changed

- **bbs-messages.html — Full Thunderbird-style redesign** — Complete rebuild from 2,533 lines (purple gradient, modal-based) to 1,738 lines. Three-pane Thunderbird layout: folder sidebar, message list with draggable resize handle, inline preview pane. IBM Plex Sans/Mono fonts. Dark terminal theme with 🌙/🌑 day/night toggle persisted in localStorage. Auto-login via session + remembered hash. Multi-select mode with bulk save-to-folder and bulk delete. All original functionality preserved: auth, compose, reply, bulletins, address book, folder management, server/browser storage.

### Fixed

- **bbs-messages.html — Auth action names** — New page was sending `action:'setupPassword'`/`action:'verifyPassword'` but PHP expects `authAction:'setup'`/`authAction:'verify'`/`authAction:'checkAuth'` (POST). Fixed to match backend.
- **bbs-messages.html — localStorage key mismatch** — Old page used `bbs_saved_messages`; new page was using `bbs_saved`. Fixed so existing saved messages are immediately visible.
- **bbs-messages.html — Server storage URL and actions** — Corrected to `./message-storage.php` with `?action=messages` and `?action=folders` (separate endpoints matching old page).

---

## [1.5.3-patch6] - 2026-03-26

### Fixed

- **connect-watchdog.py — subprocess NameError crashing script** — `apply_enabled_change()` used `subprocess` and `time` directly but neither was in the top-level imports — only imported locally inside other functions. Every BPQ restart attempt caused `NameError: name 'subprocess' is not defined`, silently crashing the script and halting all log output. Added `import subprocess` and `import time` to top-level imports. BPQ restart on pause and restore now confirmed working end-to-end.

---

## [1.5.3-patch5] - 2026-03-26

### Added

- **connect-watchdog.py — BPQ restart on pause and restore** — `Enabled=0/1` changes to linmail.cfg have no effect until BPQ reloads its config. Added `restart_bpq()` function (stop/wait 3s/start via systemctl) called only when `Enabled` actually changes — not on every failure count update. Added `bpq_stop_cmd` / `bpq_start_cmd` to CONFIG. Full watchdog flow now complete: detect → write Enabled=0 → restart BPQ → notify → restore after 4h → write Enabled=1 → restart BPQ → notify.

---

## [1.5.5-patch2] - 2026-04-06

### Added

- **bpq-aprs.html — APRS Messaging modal** — Send/inbox two-way messaging. Compose tab with 67-char limit. Inbox for messages to YOURCALL-1. Reply button. Unread badge. Double-click marker or click list pre-fills callsign.
- **bpq-aprs.html — Full WX detail panel** — All APRS WX fields in imperial (°F, mph, inHg, inches). Wind direction as compass bearing. Both popup and detail panel updated.
- **bpq-aprs.html — Station Browser modal** — Categorized by Mobile/WX/Digi/iGate/Fixed/Object. Sorted by distance. Expandable rows with full details. Show on map / Message / aprs.fi links.
- **bpq-aprs.html — Distance from home station** — Haversine distance + compass bearing for all WX stations in popup, detail panel, browser summary and browser detail.
- **bpq-aprs.html — PHG/RNG circles** — ⭕ PHG toggle button. Parses PHGpppp and RNGnnnn from comments. Color-coded by station type. Excludes mobiles.
- **install-check.php — Post-installation troubleshooter** — 16 check categories: PHP, Nginx, HTML files, PHP files, Scripts, Data files, Directories, Config, Service files, Cron jobs, Log files, LinBPQ, Database, Network, Security. Password protected. Daemon heartbeat checks, cron race condition detection, malicious cron detection.

---

## [1.5.5-patch1] - 2026-04-05

### Added

- **bpq-aprs.html — Mobile track trails** — Solid blue polyline with position dots. 2-hour history. Toggle with Tracks button. Loads 12-hour server-side history from daemon on page load.
- **bpq-aprs.html — Digi path lines** — aprs.fi style: hover station to see green/blue/purple dashed lines to digipeaters and iGate. parseDigiPath() extracts used digis from path field.
- **bpq-aprs.html — Time filter** — ⏱ Last heard dropdown (1-12 hours). Filters markers, list, and track length simultaneously.
- **bpq-aprs-daemon.py — APRS-IS daemon** — Persistent Python daemon, stays connected 24/7. Writes stations/history/messages cache every 15s. Auto-reconnect. 12-hour track history server-side.
- **bpq-aprs.service — systemd service** — enabled, runs as www-data, auto-restart.

### Fixed

- **bpq-aprs-daemon — No packets bug** — APRS-IS requires vers NAME SPACE VERSION. "vers test" invalid. Fixed to "vers BPQ-Dashboard 1.0". Switched to rotate.aprs2.net.
- **bpq-aprs.php — Simplified** — Now cache-reader only, no direct APRS-IS connection.

---

## [1.5.5] - 2026-04-04

### Added

- **bbs-messages.html — Folder unread indicators** — Icon changes 📁→📬, folder name turns blue/bold with accent border, red unread count badge. Cleared when folder opened.
- **bbs-messages.html — Sort by column** — All 5 column headers clickable (#, Date, From, To, Subject). Toggle asc/desc with arrow indicators. Default Date ↓.
- **bbs-messages.html — Rule-matched highlighting** — Green border/background/text on rule-matched messages. ⚙ RuleName badge. Highlight cleared when message opened.
- **bbs-messages.html — Search feature** — Live search across Subject/From/To/Body/#/Date. Yellow match highlighting. N found counter. ✕ and Escape to clear.
- **bpq-aprs.html — New APRS dashboard page** — Leaflet map centered on Hephzibah GA with 150km radius. Station list, APRS messages panel, station detail overlay. Auto-refresh every 2 minutes.
- **bpq-aprs.php — APRS-IS backend** — Connects directly to noam.aprs2.net:14580, parses all packet types, caches stations. No BPQ32 APRSDIGI required.
- **APRS symbol sprites** — Two local PNG sprite sheets from hessu/aprs-symbols. Primary and alternate symbol tables. 24x24px symbols.
- **tprfn.conf — Extended timeouts** — bpq-aprs.php (60s) and bpq-chat.php (90s) location blocks added.

---

## [1.5.4-patch6] - 2026-04-04

### Fixed

- **bpq-chat — Who panel 171+ users** — who action was returning entire /U history. Fixed by advancing seq before sending /U so only the fresh response is returned.
- **bpq-chat — Who panel not updating** — startWhoRefresh() missing from guest and page-resume paths. Added to startReadOnlyPoll() and init check.
- **bpq-chat — renderWho() null crash** — whoEmpty div destroyed by innerHTML clear. Fixed to rebuild inline. Added null guard at top of renderWho().
- **bpq-chat — Join/leave regex** — corrected to match actual BPQ format (name after colon for joins).

---

## [1.5.4-patch5] - 2026-04-04

### Added

- **bpq-chat.html — Who Online panel** — Persistent right sidebar showing users grouped by topic. Your callsign highlighted blue, others green. Shows callsign, name, node, idle time. Auto-refreshes every 30 seconds. Manual ⟳ refresh button. Toggleable via Who Panel button. Hidden on mobile.

### Fixed

- **bpq-chat.html — Who panel duplicating users** — whoRefreshPending flag prevents same /U response parsed twice. Tighter detection — only triggers on N Station(s) connected: line. /U output suppressed from server terminal pane.

---

## [1.5.4-patch4] - 2026-04-04

### Fixed

- **bpq-chat.html — Double callsign in messages** — sendRoomMsg() and sendServerCmd() were echoing messages locally AND receiving BPQ's echo via poll causing duplicates (YOURCALLYOURCALL: text). Removed local echo — BPQ's echo is the single display source.
- **bpq-chat.html — /B closing entire chat** — /B is BPQ's full chat exit command. In a room tab /B now sends /T General (return to General topic, stay in chat) and closes the tab. /QUIT triggers full disconnect. /B in server pane retains standard BPQ behavior.

---

## [1.5.4-patch3] - 2026-04-04

### Added

- **bpq-chat.html — Auth system** — Auth modal on page load. Three levels: guest (read-only), user (own BPQ credentials), sysop (config.php). Guest mode shows orange banner, disables all send controls. Guest request modal opens mailto: pre-filled to sysop@example.com with callsign/name/note.
- **bpq-chat.php — Per-user credentials** — Non-sysop users can connect with their own BPQ callsign/password, appearing on network as themselves.

### Fixed

- **bpq-chat.php — config.php not loaded** — $BBS_USER undefined error fixed by loading config.php at top of file.
- **bpq-chat.php — phantom require_once** — Removed leftover bpq-chat-connect.php reference causing fatal error.

---

## [1.5.4-patch2] - 2026-04-04

### Added

- **bpq-chat.html — Split screen layout** — Complete redesign. Top pane shows chat server output (always active). Bottom pane shows room tabs. Draggable divider to resize split, touch-friendly. Each /T RoomName command opens a new tab automatically. Multiple rooms supported simultaneously with unread badges.
- **bpq-chat.html — Message routing** — Incoming messages automatically sorted to correct room tab or server pane based on [RoomName] prefix and topic join/leave patterns.
- **bpq-chat.html — Full BPQ command set** — Quick commands panel updated with all BPQ chat commands: /U /N /Q /T /P /A /E /Keepalive /ShowNames /Time /S /F /B /History. Prompt dialogs for /N, /Q, /History nn, /S CALL msg. Corrected /W to /U for Show Users.

---

## [1.5.4-patch1] - 2026-04-04

### Fixed

- **bpq-chat-daemon.py — persistent connection** — Replaced sock.settimeout with select.select() in reader_thread. Timeout exceptions were breaking the read loop causing disconnects.
- **bpq-chat — multiple daemon instances** — Removed pkill from PHP connect. Added fcntl single-instance lock to daemon. Systemd manages lifecycle.
- **bpq-chat — state file going stale** — Added heartbeat_thread() updating state file every 15s. PHP stale threshold raised to 60s.
- **bpq-chat — messages repeating** — Sequence-number tracking replaces timestamp/cookie approach. Daemon writes incrementing seq on each message. PHP tracks last seen seq per session server-side. Session ID passed in request body.
- **bpq-chat — JSON parse error on send** — PHP was writing to FIFO, daemon reading from JSON queue. Fixed PHP sendCommand() to write to chat-cmd-queue.json.
- **bpq-chat — command echo suppressed** — BPQ echoes sent commands back. Added filter for lines matching /^:\s*\//

---

## [1.5.4] - 2026-04-02

### Added

- **bpq-chat.html — BPQ Chat & Terminal client** — Full GUI chat client. Persistent Python daemon (bpq-chat-daemon.py) maintains telnet connection to BPQ node. PHP broker (bpq-chat.php) reads messages from JSON file and sends commands via FIFO pipe. Dark terminal theme, color-coded output, sound alerts, browser notifications, unread badge, quick commands panel, export log, day/night theme toggle.
- **Chat nav link** — bpq-chat.html added to navigation bar on all dashboard pages.

### Changed

- **Version bump to v1.5.4** — All dashboard pages updated from v1.5.3 to v1.5.4.

---

## [1.5.3-patch16] - 2026-04-02

### Fixed

- **visitor-log.php — tprfn-network-map logging** — Visits to YOUR-TPRFN-HUB were not being recorded. Fixed by adding 'tprfn-index-log.php' and 'tprfn-network-map' to the allowed pages list, and adding page name resolver (tprfn-index-log.php → tprfn-network-map). Root cause was the @ error suppressor hiding the write result during debugging — logging was actually working after the allowed list fix was applied.

---

## [1.5.3-patch15] - 2026-03-31

### Fixed

- **callsign-lookup.php — QRZ.com primary lookup** — Archived version was outdated (callook.info + HamDB). Live version on the live server uses QRZ.com as primary with callook.info fallback. Updated both archives with live 876-line version. Lookup confirmed working — browser cache was causing apparent failures. Hard refresh resolved all callsigns except N4DGE (not in QRZ database).

---

## [1.5.3-patch14] - 2026-03-31

### Fixed

- **bbs-messages.html — iOS Safari portrait blank screen** — Viewport meta tag was missing `maximum-scale=1.0, user-scalable=no`. Without these iOS Safari applied its own scaling, reporting `window.innerWidth` larger than physical screen width, preventing the `@media (max-width:767px)` mobile layout from triggering. Fixed to match all other dashboard pages. Also added full iOS Safari height chain: `100svh`/`100dvh`/`100vh` cascade, `@supports (-webkit-touch-callout:none)` detection, `-webkit-fill-available`, `-webkit-overflow-scrolling:touch` on scroll containers, and `viewport-fit=cover` for notch/safe area support.

---

## [1.5.3-patch13] - 2026-03-30

### Fixed

- **Visitor tracking restored** — nginx dual-domain reconfiguration broke visitor logging on both domains. Created `tprfn-index-log.php` wrapper to log TPRFN Network Map visits (index.php is pure HTML, cannot include PHP directly). Restored nginx rewrite rules on `YOUR-DASHBOARD-DOMAIN` to route through `tprfn-rf-log.php`. Both `YOUR-TPRFN-HUB` and `YOUR-DASHBOARD-DOMAIN` visits now tracked in visitor-log.php.

---

## [1.5.3-patch12] - 2026-03-30

### Fixed

- **bbs-messages.html — Infinite recursion on Get Mail** — Mobile responsive patch used JS wrapper functions (`const _origX = X; function X() { _origX()... }`) causing infinite recursion. Fixed by injecting mobile sync calls directly into existing function bodies.
- **bbs-messages.html — Folders wiped on reload** — `loadSavedFromServer()` was replacing the local folder array with the server's list (`['Saved']`), wiping all user-created folders on every page load when server storage mode was active. Fixed to merge local + server folders using Set (no duplicates).
- **bbs-messages.html — Folder list clipping** — With 18 folders the sidebar and save picker overflowed. Added `overflow-y:auto` scroll to `#folderList` and `max-height:280px` to save picker.
- **bbs-messages.html — Wrong localStorage key in deleteFolder** — `persist('bbs_saved', ...)` corrected to `persist('bbs_saved_messages', ...)` in three places.
- **bbs-messages.html — Save picker not updating on folder create** — `createFolder()` now calls `renderSaveFolderList()` to keep save picker in sync.
- **network-api.php — rename() warnings** — `cache/` directory was missing. Fix: `sudo mkdir -p /var/www/<dashboard>/cache && sudo chown -R www-data:www-data /var/www/<dashboard>/cache`.

### Added

- **bbs-messages.html — Rules Test button** — 🔍 Test button in rules modal tests rule against currently loaded inbox, shows match count and preview.
- **bbs-messages.html — Msg # field in rules** — Added message number as a matchable field in the rules engine.

---

## [1.5.3-patch11] - 2026-03-29

### Added

- **tprfn.conf — Dual domain nginx config** — Complete nginx config serving both YOUR-TPRFN-HUB (TPRFN Network Map) and YOUR-DASHBOARD-DOMAIN (BPQ Dashboard) from /var/www/<dashboard>/. YOUR-DASHBOARD-DOMAIN root serves bpq-rf-connections.html. All BPQ Dashboard URLs on YOUR-TPRFN-HUB redirect 301 to YOUR-DASHBOARD-DOMAIN. All original security rules (bot blocking, rate limiting, headers, directory protection) preserved and applied to both domains. Port 514 syslog unaffected.

---

## [1.5.3-patch10] - 2026-03-29

### Added

- **bpq-rf-connections.html — GitHub download banner** — Deep space themed banner between nav and dashboard with animated stars, feature pill badges (RF Analytics, BBS Client, Storm Monitor, Prop Scheduler), and a "Download Free" button linking to https://github.com/bpq-dashboard/bpq-dashboard. Dismissible with × button that saves preference to localStorage.

---

## [1.5.3-patch9] - 2026-03-29

### Fixed

- **bbs-messages.html — Signature not appending** — `buildBody()` now re-reads localStorage as fallback when `signature.text` is empty in memory. `saveSig()` warns if text is empty while enabled.
- **bbs-messages.html — Full mobile responsive layout** — At <768px switches to single-pane app layout with bottom tab bar (Inbox/Message/Folders), slide transitions, touch-friendly rows, sheet-style modals, and mobile bulk select. Desktop three-pane layout unchanged.
- **visitor-log.php — PHP syntax error breaking bpq-rf-connections.html** — Bcrypt hash was truncated by shell `$` expansion during injection, leaving VIEWER_PASSWORD_HASH define unterminated. Fixed with Python. Also fixed placeholder check that was replaced with real hash causing perpetual "not configured" error.

### Added

- **GitHub repository** — Full project published at https://github.com/bpq-dashboard/bpq-dashboard (83 files).

---

## [1.5.3-patch8] - 2026-03-27

### Added

- **bbs-messages.html — Message Rules Engine** — Full email-client-style rules engine for auto-filing messages. Rules match on From/To/Subject using contains/starts-with/equals operators. First matching rule wins. Messages are fetched, saved to the target folder, and removed from Inbox automatically. Rules run silently after every Get Mail and on demand via Apply Rules Now. Sidebar folder badges show blue unread count when rules file new messages; badge clears when folder is opened. Rules manager (⚙ toolbar button) supports add, enable/disable toggle, priority reorder, delete. Rules persisted in `localStorage` as `bbs_rules`. Unread counts persisted as `bbs_unread`.

---

## [1.5.3-patch7] - 2026-03-27

### Changed

- **bbs-messages.html — Full Thunderbird-style redesign** — Complete rebuild from 2,533 lines (purple gradient, modal-based) to 1,738 lines. Three-pane Thunderbird layout: folder sidebar, message list with draggable resize handle, inline preview pane. IBM Plex Sans/Mono fonts. Dark terminal theme with 🌙/🌑 day/night toggle persisted in localStorage. Auto-login via session + remembered hash. Multi-select mode with bulk save-to-folder and bulk delete. All original functionality preserved: auth, compose, reply, bulletins, address book, folder management, server/browser storage.

### Fixed

- **bbs-messages.html — Auth action names** — New page was sending `action:'setupPassword'`/`action:'verifyPassword'` but PHP expects `authAction:'setup'`/`authAction:'verify'`/`authAction:'checkAuth'` (POST). Fixed to match backend.
- **bbs-messages.html — localStorage key mismatch** — Old page used `bbs_saved_messages`; new page was using `bbs_saved`. Fixed so existing saved messages are immediately visible.
- **bbs-messages.html — Server storage URL and actions** — Corrected to `./message-storage.php` with `?action=messages` and `?action=folders` (separate endpoints matching old page).

---

## [1.5.3-patch6] - 2026-03-26

### Fixed

- **connect-watchdog.py — subprocess NameError crashing script** — `apply_enabled_change()` used `subprocess` and `time` directly but neither was in the top-level imports — only imported locally inside other functions. Every BPQ restart attempt caused `NameError: name 'subprocess' is not defined`, silently crashing the script and halting all log output. Added `import subprocess` and `import time` to top-level imports. BPQ restart on pause and restore now confirmed working end-to-end.

---

## [1.5.3-patch5] - 2026-03-26

### Fixed

- **connect-watchdog.py — BPQ restart on pause and restore** — linmail.cfg `Enabled=0/1` changes had no effect until BPQ restarted. Added `restart_bpq()` function (stop/3s gap/start via systemctl) called after every `Enabled` flag change. `bpq_restart_needed` flag ensures restart only fires on actual suspension or restore — not on failure count updates. Added `bpq_stop_cmd` / `bpq_start_cmd` to CONFIG for overridability.

---

## [1.5.3-patch4] - 2026-03-26

### Fixed

- **connect-watchdog.py — set_enabled using connect_call instead of partner_key** — Watchdog correctly detected 3 failures for N4VAD at 23:40 UTC but failed to write `Enabled=0` because `set_enabled()` searched linmail.cfg for `N4VAD-7 :` (the connect_call with SSID) instead of `N4VAD :` (the actual block name). All five `set_enabled()` call sites updated to use partner_key (`partner_key`, `call`, or `pk`) instead of `connect_call`.

---

## [1.5.3-patch3] - 2026-03-25

### Fixed

- **bpq-rf-connections.html — WL2K sessions completely invisible** — WL2K stations (direct Winlink HF users e.g. WB2HJQ, W4BLW) connect to RMS port 10 and are logged only in `CMSAccess_YYYYMMDD.log`. The VARA log never emits a `connected VARA HF` line for them — only `Average S/N` and `Disconnected`. Without a connected line `curr` was never created so sessions were silently dropped.

  Fix: CMSAccess parser now builds a `wl2kSessions{}` dict with callsign, time, and seconds. The `Average S/N` handler checks `wl2kSessions` within ±5 minutes when `curr` is null, creates a synthetic `curr` with `band: 'WL2K'` and inferred frequency from `findNearestFrequency()`. WL2K sessions now appear in the connections table with correct callsign, time, S/N, bytes transferred and inferred frequency (marked `~`). Own outbound CMS connections (YOURCALL-7) correctly excluded.

---

## [1.5.3-patch2] - 2026-03-25

### Fixed

- **bpq-rf-connections.html + hub-ops.html — VARA log dual date format** — yourcall.vara contains two date formats: old LinBPQ `YYMMDD HH:MM:SS` and new `Mon DD HH:MM:SS`. Both parsers were silently skipping half the log. Added dual-format detection to `varaLogs.forEach()` and `bbsLogs.forEach()` in both files. YYMMDD format converted to Mon DD internally for compatibility.

- **bpq-rf-connections.html — WL2K frequency resolution** — `allFreqByDate` was declared with `let` inside `findNearestFrequency`'s scope, shadowing the outer variable populated during BBS log parsing. WL2K sessions now correctly resolve to inferred frequency and band within ±15 minutes of known RF activity.

- **connect-watchdog.py — three bugs causing missed failures:**
  1. **State reset on old lines** — `if ts < cutoff: reset state` destroyed pending state at the lookback boundary. Failures straddling the window edge were missed. Removed state reset from old-line skip — state now preserved across cutoff boundary.
  2. **Lookback too short** — `lookback_mins` raised from 10 to 30 (25-minute overlap per 5-minute cron run).
  3. **Failure window too narrow** — `fail_window_mins` raised from 20 to 180 minutes. Partners failing once every 10 minutes for hours never accumulated 3 failures in the old 20-minute window. New 3-hour window catches persistent slow failures. Also: `fail_window_secs` 30→120, `pause_hours` 2→4.
  - **Bonus:** Midnight log gap fixed — yesterday's BBS log now loaded when lookback crosses midnight.
  - **Bonus:** Duplicate log lines fixed — `propagate=False` + `if not log.handlers` guard.

---

## [1.5.3-patch1] - 2026-03-25

### Fixed

- **bpq-rf-connections.html — WL2K frequency resolution** — Variable scoping bug caused `findNearestFrequency()` to always search an empty object. `allFreqByDate` was declared with `let` inside the function's closure, shadowing the outer-scope variable populated during BBS log parsing. WL2K sessions within ±15 minutes of known RF activity now correctly resolve to the inferred frequency and band.

- **storm-monitor.py — duplicate log lines** — `logging.basicConfig()` set up the root logger with both FileHandler and StreamHandler. Named logger propagated to root AND cron redirected stdout to the same log file, doubling every entry. Fixed with `propagate=False` and `if not log.handlers` guard.

---

## [1.5.3] - 2026-03-22

### Added

- **partners.json** (`data/partners.json`) — Shared editable partner configuration file. Single source of truth for all forwarding partner data including callsign, bands, frequencies, distances, and storm thresholds. Both `prop-scheduler.py` and `storm-monitor.py` read this file at startup. Edit to add/remove/update partners without touching Python code. Set `active: false` to disable a partner temporarily. Priority order: partners.json → settings.json → hardcoded fallback.

- **Partners Editor** (`bpq-maintenance.html`) — Live web editor for `data/partners.json` embedded in the maintenance page. BBS password protected. Features: per-partner cards with all fields editable, active/inactive toggle, band add/remove, storm suspend Kp threshold, fallback script field. Saves with automatic timestamped backup to `data/backups/`. Requires `partners-api.php`.

- **partners-api.php** — PHP backend for Partners Editor. Actions: load (GET), save (POST with validation), validate. Creates timestamped backup on every save. BBS password auth.

- **tprfn-hub-report.php** — Live HTML hub health report with PDF print support. Queries the MariaDB database directly. 10 sections: network overview, hub performance table, today's activity, hubs requiring attention, hub-to-hub link quality, top polling stations, 14-day trend, VARA speed distribution, prop scheduler decision history, schedule change impact (pre/post 7-day correlation). Uses the configured app credentials. Print/Save PDF via browser native print dialog with full `@media print` stylesheet.

- **prop_decisions table** (`data/prop-decisions-schema.sql`) — MariaDB table tracking every prop-scheduler.py decision run. 14 columns including run_at, mode (dry/apply), SFI, Kp, season, partner, changed flag, old/new ConnectScript, historical band stats summary, and time block assignments as JSON. One row per partner per run, both changed and unchanged partners recorded. Enables correlation analysis between scheduling decisions and session success rates.

- **prop-scheduler.py** — Updated to record every decision run to `prop_decisions` table via `save_decisions()` function. Called after both `--apply` and dry runs. Also updated to load partners from `partners.json` via `load_partners_json()` and `build_partners_from_list()`. `active: false` partners silently skipped.

- **storm-monitor.py** — Major update: tiered distance-based partner suspension during geomagnetic storms. Uses `Enabled = 0/1` in linmail.cfg (cleaner than ConnectScript modification — scripts never touched for suspended partners). New functions: `get_enabled()`, `set_enabled()`, `get_partner_action()`. Tiered by Kp severity and distance: G1(Kp≥5)=80m only, G2(Kp≥6)=suspend partners ≥500mi, G3(Kp≥7)=suspend ≥300mi, G4(Kp≥8)=suspend all >200mi. Also updated to load partners from `partners.json`.

- **nginx-maintenance-block.conf** — nginx location block restricting all admin/maintenance pages to LAN only (10.0.0.0/8 + localhost). Prevents Google crawler and external access to bpq-maintenance.html, system-audit.html, firewall-status.html, log-viewer.html, and all API PHP files.

### Fixed

- **bpq-maintenance.html** — Removed `document.execCommand('copy')` which triggered Google Safe Browsing false positive virus warning. Replaced with safer clipboard fallback using `setSelectionRange()`. All admin/maintenance pages should be restricted to LAN only via nginx (see `nginx-maintenance-block.conf`).

- **wp_manager.py blacklist** — Fixed relative path issue causing blacklist file to resolve to different locations depending on run directory. All file paths (`BLACKLIST_FILE`, `WHITELIST_FILE`, `REVIEW_FILE`, `BASELINE_FILE`, `LOG_FILE`) now use `os.path.join(os.path.dirname(os.path.abspath(__file__)), ...)` for consistent resolution regardless of working directory. Merged duplicate `/root/wp_blacklist.txt` into `/var/www/<dashboard>/scripts/wp_blacklist.txt`.

- **storm-monitor.py** — `state['suspended_partners']` list added to storm-state.json so suspended partners are correctly unsuspended on restore (previously only 80m-switched scripts were restored, suspended partners stayed disabled).

### Changed

- **prop-scheduler.py** — `PARTNERS` dict now loaded from `data/partners.json` at startup (Priority 1), then `data/settings.json` (Priority 2), then hardcoded (fallback). New `distance_mi` and `last_resort_else` fields added to partner data model.

- **storm-monitor.py** — `storm_partners` CONFIG now loaded from `data/partners.json` at startup. `suspend_kp` and `distance_mi` per-partner fields replace hardcoded tiering logic.

---

## [1.5.1] - 2026-03-06

### Added

- **Maintenance Reference Card** (`bpq-maintenance.html`) — Standalone HTML reference page covering all common maintenance tasks. Sections: Propagation Scheduler, Storm Monitor, BPQ/linmail.cfg, Cache & Dashboard Data, Nginx & PHP, Logs & Disk, Permissions, Key Paths table. Each command block has a one-click Copy button. Dark theme, no external dependencies, works offline. Drop in web root alongside other dashboard pages.

### Fixed

- **bpq-rf-connections.html template literal injection** — `sed`-based script tag injection during v1.5.0 build landed inside a `printWindow.document.write()` template literal, producing `Uncaught SyntaxError: literal not terminated` and preventing all page JavaScript from running (page stuck on "Loading..."). Removed misplaced tag from inside the template literal; correct tag at real `</body>` retained.

- **bpq-rf-connections.html loadLogs() never firing** — Startup chain `loadLocationsFromServer().then(() => loadLogs())` would hang indefinitely if `station-storage.php` stalled or errored. Added `Promise.race` with 5-second timeout so `loadLogs()` always fires. Added `AbortController` 8-second timeout on the `station-storage.php` fetch itself.

- **bpq-maintenance.html copy button syntax error** — `Uncaught SyntaxError: unterminated regular expression literal` caused by Python string escaping mangling a regex in the injected copy script. Rewrote entire copy script block without regex using plain ES5.

- **bpq-maintenance.html favicon 404** — Added `<link rel="icon" type="image/svg+xml" href="favicon.svg">` so browser uses existing `favicon.svg` instead of requesting missing `favicon.ico`.

## [1.5.0] - 2026-03-06

### Added

- **Dashboard Settings System** — Web-based configuration UI accessible via ⚙ gear icon in the nav bar on every dashboard page. Replaces hardcoded Python/PHP configuration and provides a single place to configure the entire BPQ Dashboard installation.

  - `settings-api.php` — PHP backend: reads/writes `data/settings.json`; BBS password authentication required for writes; supports auto-detect of platform paths and import from linmail.cfg
  - `shared/settings-modal.js` — JavaScript module auto-injected into all 8 dashboard pages; renders full-screen tabbed modal with 5 configuration tabs
  - `data/settings.json` — Persistent configuration store (server-side JSON; auto-created on first save)

  **Settings Tabs:**
  - **Station Info** — Callsign, grid square, lat/lon, notes; BBS host/port/user/pass/alias/timeout
  - **Forwarding Partners** — Add/edit/remove partner rows with per-partner callsign, SSID, name, location, lat/lon, attach port, fixed-schedule toggle, and band/frequency/mode sub-table; Import from linmail.cfg button (parses ConnectScript entries)
  - **Propagation Scheduler** — Enable/disable toggle, run interval, lookback days, min sessions threshold, scoring weight sliders (prop/S-N/success), conservative mode toggle
  - **Storm Monitor** — Enable/disable toggle, Kp storm threshold, Kp restore threshold, consecutive calm hours, G-scale reference
  - **Paths & System** — linmail.cfg path, BPQ stop/start commands, log directory, backup directory; Auto-Detect button fills in platform defaults

  **Security:** Write operations require BBS password verification (same credential as BBS Messages page). Read-only access (e.g. displaying callsign in nav) is unauthenticated.

- **Settings.json integration in `scripts/prop-scheduler.py`** — Added `load_settings_json()` and `apply_settings_json()` functions. At startup, if `data/settings.json` exists, it overrides the hardcoded `CONFIG` dict (BBS credentials, paths, scoring weights, run interval, lookback days) and rebuilds the `PARTNERS` dict from the settings partners list. Fully backward-compatible: if settings.json is absent the script runs exactly as before.

- **Settings.json integration in `scripts/storm-monitor.py`** — Same pattern as prop-scheduler. Overrides CONFIG (BBS credentials, paths, storm thresholds) and rebuilds `storm_partners` from settings partners that have an 80m band entry. Backward-compatible.

## [1.4.2] - 2026-03-01

### Added

- **Dark Mode** (All 7 Dashboard Pages)
  - Automatic OS preference detection via `prefers-color-scheme: dark`
  - Manual 🌙/☀️ toggle button in nav bar next to UTC/Local clocks
  - Preference persisted to localStorage across sessions
  - Chart.js integration: text and grid colors update on toggle, all charts re-render
  - Full coverage: backgrounds, stat cards, gradients, tables, forms, log viewer entries, Leaflet map controls, popups, modals, scrollbars
  - All CSS and JS embedded inline (no external files required)

- **Forwarded Messages Panel** (RF Connections)
  - Collapsible panel below the Forwarding Partners card showing actual FBB protocol message transfers
  - Parses all FBB proposal variants: `FA` (accept), `FB` (basic), `FC` (compressed/LZH), `FF` (forced)
  - FC compressed format uses different field order — parser handles both FA and FC layouts
  - Hierarchical BBS addressing supported in To/Category fields: dotted paths (`KD8NOA.MI.USA.NOAM`), hash separators (`N0CALL#XXX.GA.USA.NOAM`), and @ routing (`KM2E@WA2UET.#ENY.NY.USA.NOAM`)
  - Summary cards: received count, forwarded count, bulletin/personal/traffic breakdown, total transfer size
  - Filterable by forwarding partner and message type (Bulletin, Personal, Traffic)
  - **Time range aware** — respects Today/7 Day/30 Day selection; badge, summary cards, partner dropdown, and table all update when range changes
  - Message table columns: Time, Direction (⬇/⬆), Partner, Type (BUL/PER/TFC), From, To (category@area), BID, Size
  - Deduplicates by BID per direction, displays up to 200 messages sorted newest first

- **VARA Performance in Station Map Popups** (RF Connections)
  - Station marker and connection line popups now include VARA-specific analytics
  - Modulation tier (BFSK/BPSK → 4PSK → 8QAM → 16QAM → 32QAM+) color-coded
  - Average and peak bitrate, S/N range (min to max)
  - Primary frequency (most-used), bands, average session duration
  - Data transferred (TX/RX formatted as KB/MB), message counts (sent/received)

- **Security Audit Document** (`SECURITY-AUDIT.md`)
  - Comprehensive code-level audit: 5 critical, 5 high, 5 medium, 4 low findings
  - Per-file remediation with code examples
  - Hardening checklists for LAN-only and internet-facing deployments
  - Cross-referenced with existing `PUBLIC-DEPLOYMENT.md` and `CODE-REVIEW.md`

- **Nginx Security Hardening** (`nginx-tprfn.conf`)
  - Complete server block replacement for `YOUR-TPRFN-HUB` with access controls
  - Blocks direct access to config files (config.php, bbs-config.php, tprfn-config.php) — previously exposed BBS credentials and API keys to the public
  - Blocks protected directories: `includes/`, `scripts/`, `data/`, `cache/`, `archives/`
  - Blocks documentation files (.md), backup files (.bak, .old, .example), and hidden files (.git, .env)
  - Blocks PHP execution in `logs/` directory — prevents uploaded malicious scripts from running
  - Disables directory listing — browsing to any directory no longer shows contents
  - Security headers on every response: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy
  - Logs directory restricted to .txt, .log, .csv, .vara, and .js file types only
  - PDF and VARA files served with proper MIME types and caching
  - Configured for PHP 8.3 FPM, SSL via Let's Encrypt/Certbot

### Changed

- **Message Counting Algorithm** (RF Connections)
  - Threshold raised from 175 bytes (per-direction) to 446 bytes (total TX+RX), aligned with TPRFN Network Map CMS ground truth
  - Sessions below 446 total bytes are FBB handshakes/negotiations, not actual messages
  - Direction now determined by which is larger: TX > RX = sent, otherwise received
  - Applied consistently across all 8 counting locations: parseConnections (×2), filterAndProcess (×2), propagation report per-station/per-band/per-day/global

- **WL2K Frequency Inference** (RF Connections)
  - `findNearestFrequency()` now searches ±15 minutes in both directions (was backward-only)
  - Cross-date support: checks previous/next day for connections near midnight
  - Pending user verification with next WL2K incoming connection

- **Callsign Lookups Centralized** (RF Connections & Connect Log)
  - RF Connections: replaced direct callook.info API calls with `callsign-lookup.php` (QRZ.com primary, callook.info fallback)
  - Connect Log: removed inline callook.info + HamDB fallback chain; uses `callsign-lookup.php` exclusively with batch processing (groups of 50)
  - International stations (VE/VA, G/M, VK, etc.) now properly located on maps

- **System Logs Auto-Refresh** reduced from 60 seconds to 300 seconds (5 minutes), matching MHeard and RTKnown refresh intervals

- **System Logs Display** — `displayLogs()` rewritten from per-line `createElement`/`appendChild` loop to single-pass `innerHTML` batch rendering with HTML escaping

- **Chart.js Animations Disabled** on 8 charts across 3 pages for instant data updates:
  - `bpq-connect-log.html`: mode doughnut, hourly bar, daily bar (3 charts)
  - `bpq-traffic.html`: connections bar, messages doughnut, bytes bar, traffic type pie (4 charts)
  - `bpq-email-monitor.html`: hourly activity bar (1 chart)

- **Version numbers** updated to v1.4.2 across all pages and documentation

### Fixed

- **RF Power Monitor Data Loading Failure** — multi-line `console.log` spanning 7 lines was partially removed by sed (only first line deleted), leaving orphaned object properties as broken JavaScript that silently prevented data loading. Properly removed entire block.

- **RF Power Monitor API Memory Exhaustion** (`api/data.php`) — `parseDataLogFile()` used `file_get_contents()` + `explode()` which loaded entire 14MB DataLog files into memory, then duplicated content into line arrays. With 30 days of files, this exceeded PHP's 512MB memory limit. Replaced with streaming `fopen()`/`fgets()` line-by-line reading (one line in memory at a time) and automatic downsampling: files >5MB keep every 30th sample, 2-5MB every 10th. Reduces memory usage by ~97% for large files with no visible impact on chart quality.

- **BBS Message Sending Rewrite** (`bbs-messages.php`) — Rewrote `sendMessage()` to match the proven `nws-bbs-post.php` telnet flow. Uses `fsockopen()` with proper prompt-aware `readUntilBBSPrompt()` function. Body prompt wait uses non-blocking 2-second drain instead of pattern matching (BBS prompt ends with `)` which doesn't match standard prompt characters). Added missing `validateMsgNum()` and `validateAddress()` utility functions. Added fatal error handler for better diagnostics.

### Added

- **Best Paths Visualization** (`bpq-rf-connections.html`) — Quality-scored station-to-home links on Station Map, adapted from TPRFN Network Map. Toggle button in map header. Scoring: S/N (40%), VARA throughput (35%), reliability (25%). Color-coded lines (red→green) with thickness proportional to score. Animated particles on top 5 paths. Click paths for popup with score, S/N, sessions, avg/peak speed, primary band. Time-range aware — updates with Today/7 Day/30 Day selection. Dark mode supported.

- **Propagation-Based Forwarding Scheduler** (`scripts/prop-scheduler.py`) — Automatically adjusts HF forwarding ConnectScript schedules in linmail.cfg based on NOAA solar flux, Kp index, seasonal NVIS models, and historical BBS connection data. Combines propagation model (25% weight) with historical per-band success rates (75%). Generates optimized time blocks with fallback bands per partner. Supports `fixed_schedule` flag for stations with published scanning schedules (e.g., K7EK). Uses stop → write → start sequence to avoid BPQ config-on-shutdown overwrite. Sends report as BBS personal message. Runs via cron every 48 hours.

- **Geomagnetic Storm Monitor** (`scripts/storm-monitor.py`) — Lightweight hourly companion to prop-scheduler. Monitors NOAA Kp index and forces all HF forwarding to 80m-only when Kp ≥ 5 (G1+ storm). Saves current schedules before overriding. Auto-restores optimized schedules when Kp drops below 3 for 2 consecutive hours. Sends BBS alerts for both storm activation and recovery. Runs via cron every hour.

### Removed

- **69 console.log statements** removed across 5 files:
  - `bpq-system-logs.html` (23), `bbs-messages.html` (18), `rf-power-monitor.html` (15), `bpq-rf-connections.html` (9), `bpq-traffic.html` (4)

- **Text selection blocking** (`user-select: none`) removed from 5 pages: system logs, RF connections, connect log, traffic, email monitor

- **Right-click and keyboard blocking** (contextmenu preventDefault, F12/Ctrl+U/Ctrl+S interception) removed from 5 pages

- **HamDB.org lookups** removed from Connect Log (replaced by centralized `callsign-lookup.php`)

- **Direct callook.info API calls** removed from RF Connections and Connect Log

### Performance

- Batch DOM rendering: eliminates hundreds of individual DOM operations per log refresh
- 69 fewer console.log calls per page load/refresh cycle

## [1.4.2] - 2026-02-07

### Added

- **System Logs - Callsign Search & PDF Reports**
  - New callsign search box in Live Log Viewer filters logs by station
  - Search results summary shows count and active filters
  - PDF Report generation with jsPDF library:
    - Professional formatted reports with title, metadata, and summary statistics
    - Color-coded log entries (green=connections, blue=disconnects, purple=messages, red=errors)
    - Multi-page support with headers
    - Filename includes callsign and date (e.g., `BPQ-Log-N5MDT-2026-02-07.pdf`)

- **System Logs - Enhanced Error Details Panel**
  - Click "Errors ▼" in Event Distribution to expand detailed breakdown
  - Error categories tracked: RF Failures, Node Disappeared, Protocol Errors, Timeouts, Connect Failures, Other
  - Table view with count, percentage, and visual distribution bars
  - Recent Errors list shows last 10 error messages with color coding
  - Click any error to search for that callsign in logs
  - "View All in Log" link filters log viewer to errors only

- **NWS Dashboard - Complete Visual Redesign**
  - Rebuilt from scratch with BPQ Dashboard light theme styling
  - Light gray background (#f3f4f6) with white glass containers
  - Purple/blue gradient accents matching other dashboard pages
  - Nav bar with UTC/Local clocks consistent across all pages
  - Stats cards with colored left borders (Active Alerts, Warnings, Watches, Posted)
  - Alert items with severity-colored left borders and gradient backgrounds
  - Region buttons with purple gradient when active
  - Alert type toggles with purple highlight styling
  - BBS preview with dark terminal-style background
  - Toast notifications matching theme
  - Mobile responsive: alerts appear first on phones/tablets
  - All functionality preserved: region filtering, alert types, NWS API fetch, BBS posting, export
  - **Configurable auto-refresh interval** with live countdown timer:
    - 30 seconds (active severe weather)
    - 1 minute (default)
    - 5 minutes (quiet monitoring)
    - 15 minutes (background awareness)
    - Off (manual refresh only)
  - Setting saved to localStorage for persistence

### Changed

- Navigation labels updated: "Email" → "Messages" across all pages
- NWS dashboard reduced from 2316 lines to 871 lines (62% smaller)
- Error detection expanded to include Timeout and Failed patterns
- Version numbers updated to v1.4.2 on all pages

### Fixed

- Dynamic year display replaces hardcoded "26" in footer timestamps
- NWS dashboard mobile layout shows alerts before sidebar controls

## [1.4.0] - 2026-02-01

### Added

- **VARA Channel Quality Analysis** (RF Connections)
  - New dedicated panel between Band Summary and Recent Connections
  - Maps observed VARA bitrate to modem modulation tier (BFSK→BPSK→4PSK→8QAM→16QAM→32QAM)
  - Per-band cards show: modulation tier badge, quality distribution bar, avg/median/peak bitrate, avg S/N, propagation availability
  - 7-day trend analysis: compares recent S/N and bitrate against older data with directional indicators (📈/📉/→)
  - QSB/multipath detection: flags when S/N is adequate but VARA can't ramp up speed
  - S/N-to-bitrate mismatch warnings indicating path instability despite good signal strength
  - Color-coded quality distribution stacked bar showing connection counts per modulation tier

- **Propagation-Aware Scoring** (RF Connections - Best Band)
  - Failure classification: timeout/nodata/incoming = RF propagation failures; channel busy/port in session = infrastructure
  - Propagation success rate excludes infrastructure failures from band condition assessments
  - Channel quality scoring from VARA modulation tiers (35% weight) replaces raw bitrate linear scaling
  - S/N trend bonus/penalty (±0.08) when recent 7-day S/N diverges from older average by >3 dB
  - Bitrate trend bonus/penalty (±0.06) when recent throughput ratio exceeds 1.5× or drops below 0.6×
  - Multipath penalty (−0.08) when S/N ≥ 8 dB but avg bitrate < 300 bps
  - Revised weighting: propSuccessRate 40% + channelQuality 35% + S/N 15% + sampleBonus 10%
  - Best Band reason text shows modulation tier label (QAM+/QAM/PSK/FSK) alongside propagation rate
  - Bar colors reflect channel quality tier instead of raw success rate
  - Enriched tooltips: score, avg bps, S/N, recent S/N, recent bps, quality notes, infra exclusions

- **Propagation Report Enhancements** (RF Connections)
  - Propagation Availability metric (purple) separating RF failures from infrastructure failures
  - Failure-by-band table with RF Fail / Infra Fail / Total columns
  - Band Efficiency table with Prop% column (purple, bold, color-coded)
  - Failure insights comparing propagation vs link availability to identify infrastructure bottlenecks
  - Week-over-week propagation availability trend
  - Band summary cards show "X% prop" badge with infrastructure failures listed separately

- **Enhanced Data Collection** (RF Connections)
  - Both VARA and BBS log parsers now track `recentSnrSum/Count` and `recentBitrateSum/Count` per band per time slot
  - Enables recent-vs-older trend analysis for S/N and bitrate independently
  - Dashboard stats card relabels timeout as "Timeout (no prop)" for clarity

- **UTC & Local Clocks** (All Pages)
  - Dual digital clocks in the nav bar on all 7 dashboard pages
  - UTC/Zulu time (purple gradient pill) and local browser time (gray pill)
  - Second-resolution updates with drift-free millisecond synchronization

- **Station Location Persistence** (RF Connections)
  - New `station-storage.php` API for server-side storage of station locations and forwarding partners
  - Server-primary architecture: data stored in `data/stations/` via PHP, with `localStorage` as automatic fallback
  - Locations and forwarding partners persist across browsers, devices, and browser data clears
  - Bidirectional sync: browser-only entries pushed to server, server entries merged to browser
  - Import/export still available for manual backup and migration
  - Validated storage with callsign sanitization, coordinate bounds checking, and 500-station limit

- **Enhanced Best Band Recommendations** (RF Connections)
  - 6×4-hour seasonal time slots (replacing fixed 4×6-hour blocks)
  - Automatic sunrise/sunset calculation from station coordinates (solar geometry)
  - Trend indicators comparing recent 48-hour performance vs full 7-day history
  - Dual band recommendations when two bands score within 15% of each other
  - VARA-optimized bitrate scoring (calibrated to 2000 bps scale)
  - Compact responsive layout with visual score bars and trend arrows

- **30-Day Geomagnetic Tracking** (RF Connections)
  - K-index chart expanded from 24 hours to full 3 days of 3-hour intervals
  - A-index chart expanded from 3 days to 30 days using NOAA DGD (Daily Geomagnetic Data)
  - Official Estimated Planetary Ap values from `daily-geomagnetic-indices.txt`
  - Fixed-width column parsing handles NOAA's concatenated negative-value edge cases
  - Graceful fallback to K-index-derived A values if DGD endpoint unavailable
  - Date labels on midnight bars for multi-day K-index readability

- **Enhanced Propagation Report** (RF Connections)
  - Failure analysis section with breakdown by type (timeout, rejected, zero-byte)
  - Station × Band performance matrix with color-coded cells
  - Week-over-week trend comparison (current 7 days vs prior 7 days)
  - Solar conditions panel showing SFI, K-index, and A-index averages
  - Message delivery metrics (sent/received counts, delivery ratio)
  - Interactive heatmap grid replacing horizontal bar charts for band activity
  - Seasonal time slot labels matching Best Band Recommendations

- **Hourly Charts Redesign** (RF Connections)
  - Vertical stacked bars for hourly connections and messages
  - Interactive heatmap grid for hourly band activity
  - Improved visual density and readability

- **Performance Optimizations** (RF Connections)
  - Frequency lookup indexing: O(n) → O(n/days) via date-partitioned index
  - Callsign cache: eliminated repeated JSON.parse with in-memory cache layer
  - Dead array removal: removed unused `snrValues[]` and `allBitrates[]` allocations
  - Pre-computed `baseCall` on connection objects (eliminates 21 regex calls per cycle)
  - Set-based partner lookup: O(partners.length) → O(1) per connection
  - Non-blocking UI updates via requestAnimationFrame and deferred map rendering
  - Cached loop thresholds: Date.now() computed once outside hot loops

- **Server-Side Data API** (`api/data.php`)
  - New PHP endpoint parses DataLog CSVs and VARA/BBS logs server-side with intelligent caching
  - Dramatically improves RF Power Monitor load times (seconds vs minutes)
  - Automatic fallback to direct file loading if API unavailable
  - API/Direct status badge shows active data loading method

- **RF Power Monitor Enhancements**
  - Frequency correlation: TX events annotated with frequency, band, and callsign from VARA connections
  - Success/Failed connection indicators on TX event rows
  - Session consolidation: multiple TX bursts per connection grouped into single rows
  - UTC display for all timestamps (consistency with log sources)
  - 4-channel RF power monitoring with real-time gauges and history charts
  - Requires WaveNode hardware meter

- **VARA Timeout Disconnect Handling** (`api/data.php`)
  - Two new disconnect regex patterns: `Disconnected (Timeout) TX:` and `Incoming Connection failed`
  - Fixes connection windows staying open 30–100 minutes due to unrecognized disconnect patterns
  - Auto-marks 0-byte transfers as failed connections
  - 51% reduction in bloated connection windows

- **Health Check Diagnostic Tool** (`health-check.php`)
  - Comprehensive installation diagnostic page
  - Tests PHP version, extensions, directory permissions, config validity
  - Checks all API endpoints, log file availability, BBS connectivity
  - Provides specific fix instructions for each issue found

- **UI Reorganization: MHeard & Known Stations**
  - MHeard Stations display moved from RF Connections → System Logs
  - Known Stations Routing Table moved from RF Connections → System Logs
  - Better organization: node infrastructure data alongside system-level monitoring
  - Both features include auto-refresh every 5 minutes

- **Calendar-Based Date Filtering** (Traffic & Email Monitor)
  - Traffic Report: 7D/4W/12W buttons filter by actual calendar days (not file count)
  - Email Monitor: new 7D/4W/30D range selector with instant client-side re-filtering
  - All data loaded once upfront; range switching is instant (no re-fetching)

- **Empty State Warnings** (Traffic & Email Monitor)
  - Detailed warnings when no data files found for selected range
  - Shows required file type, expected location, example filename, and date range searched
  - Email Monitor shows "nearest data available" indicator when data gap exists

- **TCP Log File Validation** (Email Monitor)
  - Rejects HTML files and non-log content that share the same `log_YYMMDD_TCP.txt` filename pattern
  - Validates files contain timestamp patterns (`HH:MM:SS`) before accepting as log data
  - Console logging identifies skipped files for troubleshooting

- **Unified Configuration System**
  - Single `config.php` replaces multiple config files
  - Server-side configuration API (`api/config.php`)
  - Client-side config loader (`shared/config.js`)

- **Security Modes**
  - `local` mode (default): Full features for home network
  - `public` mode: Read-only, rate-limited for internet exposure

- **Security Hardening**
  - Rate limiting (30 requests/minute, burst protection)
  - Input validation for all user inputs
  - CORS control (configurable per security mode)
  - Security headers (X-Content-Type-Options, X-Frame-Options, etc.)
  - Centralized bootstrap security layer

- **Data Archival System**
  - Automated archival scripts for logs and traffic data
  - Weekly and monthly archive creation
  - Windows (`archive-bpq-data.bat`) and Linux (`archive-bpq-data.sh`) scripts

- **MHeard Stations Display** (System Logs)
  - Tabbed interface for 4 ports: Telnet Server, Internet, VARA HF, ARDOP
  - Parses MHSave.txt from BPQ logs directory
  - RF ports show grid square, frequency, band with color-coded badges
  - Time-ago display with status indicators (✅ Connected, ❌ Failed/Heard)
  - Station counts per port in summary bar

- **Known Stations Routing Table** (System Logs)
  - Searchable and filterable routing table display
  - Parses RTKnown data from BPQ
  - Region identification from callsign prefixes

- **Traffic Report Enhancements**
  - Efficiency metrics cards: Overall Efficiency %, Messages/Connection, KB/Message
  - "Active BBS Partners" filter to show only BBS nodes with traffic
  - Per-station efficiency column with color coding

- **New Files**
  - `api/data.php` - Server-side DataLog/connection data API
  - `health-check.php` - Installation diagnostic tool
  - `config.php.example` - Main configuration template
  - `includes/bootstrap.php` - Security and config loader
  - `shared/config.js` - Client-side configuration
  - `api/config.php` - Configuration API endpoint
  - `station-storage.php` - Server-side station location and forwarding partner storage
  - `PUBLIC-DEPLOYMENT.md` - Internet deployment guide
  - `scripts/archive-bpq-data.sh` - Linux archival script
  - `scripts/archive-bpq-data.bat` - Windows archival script

### Changed
- **Archive Scripts** (Linux + Windows)
  - Monthly archives now collect every day of the previous calendar month (was: copy of one weekly snapshot)
  - Shared `collect_logs_for_date()` helper (Linux) / PowerShell day-iteration loop (Windows)
  - Archives now include station location data (`station-locations.json` or `data/stations/`) when present
  - Monthly manifest shows days-in-month, days-with-logs, and month name
  - Monthly retention cleanup added to both scripts (configurable, default 12 months)
  - Version bumped to 1.4.0

- **RF Connections - Geomagnetic Conditions**
  - K-index chart: 24 hours → 3 days of 3-hour interval bars
  - A-index chart: 3 days (derived from Kp) → 30 days (official NOAA Planetary Ap)
  - New data source: NOAA SWPC `daily-geomagnetic-indices.txt` (DGD product)
  - Auto-skip labels on K-index chart for readability; date shown at midnight bars

- **RF Connections - Best Band Recommendations**
  - Seasonal time slots (6×4hr) with sunrise/sunset-aware labels replace fixed 4×6hr blocks
  - Trend indicators (↑↗→↘↓) show whether band conditions are improving or declining
  - Dual recommendations shown when top two bands score within 15%
  - Scoring calibrated to VARA bitrate scale (2000 bps max)

- **RF Connections - Propagation Report**
  - Hourly band activity uses interactive heatmap grid instead of horizontal bars
  - New failure analysis, station×band matrix, week-over-week trends, and solar panels
  - Message delivery metrics added alongside connection statistics

- **RF Connections - Hourly Charts**
  - Vertical stacked bars replace horizontal bars for connections and messages
  - Interactive heatmap grid for band activity visualization

- **RF Connections - Station Map**
  - Station locations and forwarding partners now stored server-side via `station-storage.php`
  - Server-primary with `localStorage` fallback; bidirectional sync at startup
  - Live storage status indicator (🟢 server / 🟠 browser only) in Manage Locations and Manage Partners modals
  - Export/Import buttons retained for manual backup and migration
  - `$SKIP_BBS_CHECK` bypasses BBS password validation for data-only endpoint

- **All Pages** - UTC and Local digital clocks in nav bar (second-resolution)

- **Traffic Report**
  - Range buttons changed from 1W/4W/12W to 7D/4W/12W (calendar-day filtering)
  - Default view: 4 weeks (28 days)
  - Status shows filtered report count and date range
  - "BBS Nodes" renamed to "BBS Partners"

- **Email Monitor**
  - Added 7D/4W/30D range selector (default: 4W)
  - Date range and line count displayed in header
  - Refresh button added to header
  - File validation prevents HTML/non-log files from being processed

- **RF Connections**
  - MHeard Stations and Known Stations Routing Table relocated to System Logs
  - Cleaner focus on VARA connection analysis, band stats, and station mapping

- **System Logs**
  - Now includes MHeard Stations (4-port tabbed display) and Known Stations Routing Table
  - Comprehensive node operations view: live logs, MHeard, routing, and station activity

- **All Pages** - Version badge updated to v1.4.0
- All PHP files now use centralized bootstrap for security
- Configuration migrated from multiple files to single `config.php`

### Performance
- Frequency lookup: O(n) → O(n/days) via date-partitioned index
- Callsign cache: in-memory layer eliminates repeated localStorage JSON.parse
- Partner filtering: Set-based lookup O(1) replaces Array.includes O(n)
- Memory: removed 2 dead arrays (snrValues, allBitrates) from stats
- UI responsiveness: non-blocking update chain via requestAnimationFrame
- Loop overhead: pre-computed baseCall and cached Date.now() thresholds

### Fixed
- **RF Power Monitor** - Timezone mismatch between DataLog CSVs and VARA connection timestamps
- **RF Power Monitor** - Frequency correlation search window narrowed from ±5 min to ±2 min
- **VARA Connection Parsing** - Unhandled "Disconnected (Timeout)" and "Incoming Connection failed" patterns
- **Email Monitor** - HTML files with matching filenames incorrectly processed as TCP logs
- **Traffic Report** - Range filter was counting files instead of filtering by calendar date
- **Station Locations** - Export function was broken (empty loop body, console-only output)

### Removed
- `bbs-config.php` support (migrate to `config.php`)
- `nws-config.php` support (migrate to `config.php`)
- Hardcoded station information from HTML files

### Security
- Default password check prevents deployment with unchanged password
- Write operations blocked in public mode
- Rate limiting prevents abuse
- Input validation prevents injection attacks

## [1.3.0] - 2026-01-19

### Added
- **MHeard Stations Display** (RF Connections Dashboard)
  - New "Latest MHeard Stations" card showing stations heard by BPQ
  - Tabbed interface for 4 ports: Telnet Server, Internet, VARA HF, ARDOP
  - Parses MHSave.txt file from BPQ logs directory
  - RF ports (VARA HF, ARDOP) show grid square, frequency, and band
  - Color-coded band badges matching RF Connections style
  - Time-ago display (e.g., "5m ago", "2h ago", "Jan 15")
  - Status indicators: ✅ Connected, ❌ Failed/Heard
  - Auto-refresh every 5 minutes
  - Station counts per port in summary bar

- **Traffic Report Enhancements** (v1.0.5)
  - Weekly time ranges (1W/4W/12W) instead of daily
  - Efficiency metrics cards: Overall Efficiency %, Messages/Connection, KB/Message
  - "Active BBS Partners" filter option
  - Efficiency column (Eff%) with color coding

- **Time Range Filtering** (System Logs & Traffic)
  - UTC-based time range selection (1D/7D/30D for System Logs)
  - Hourly Activity chart respects selected time range

### Changed
- All PHP files now use centralized bootstrap for security
- Configuration migrated from multiple files to single `config.php`

## [1.2.0] - 2026-01-18

### Added
- **Station Connection Info Modal** (RF Connections)
  - Click any callsign for 7-day history and efficiency analysis
  - Band activity breakdown, best time recommendations, connection timeline

- **Password Protection** for BBS Messages and NWS Weather posting
- **Performance Optimizations** - CDN preconnect, deferred loading, debounced search
- **NWS Region Filtering** - Proper client-side filtering by NWS region

### Changed
- System Logs shows entire day when specific date selected
- UTC time display with "Z" suffix

### Fixed
- Email Monitor orphaned code block
- NWS Region Filter API parameter issue

## [1.1.5] - 2025-01-17

### Added
- **BBS Messages Dashboard** - Full BBS client with read/compose/delete
- **Bulletin Downloads** - Fetch by category (WX, NEWS, TECH, DX, ARRL)
- Folder management, multi-select, bulk operations

## [1.1.0] - 2025-01-15

### Added
- **Propagation-Aware Best Band Recommendations** with SFI and K-index integration
- Visual band performance indicators

### Fixed
- RF Connections syntax errors and orphaned code
- System Logs time range filtering

## [1.0.4] - 2025-01-10

### Added
- TPRFN Hub Stations identification
- Station location caching and manual entry

## [1.0.3] - 2025-01-05

### Added
- Geomagnetic conditions display (K-index, A-index, storm alerts)

## [1.0.2] - 2024-12-28

### Added
- Band-by-band statistics, SNR and bitrate tracking

## [1.0.1] - 2024-12-20

### Added
- Station map with Leaflet.js, callsign lookup via callook.info

## [1.0.0] - 2024-12-15

### Initial Release
- RF Connections, System Logs, Traffic, and Email Monitor dashboards
- Deployment scripts for Linux and Windows
- VARA logger service and analysis tools

## v1.5.2 — 2026-03-11

### Added
- `scripts/wp_manager.py` — Interactive Winlink White Pages scanner/cleaner
  - `--scan` scans WP.cfg and writes wp_review.txt with flagged entries
  - `--apply` surgically removes REMOVE-flagged entries, preserving all R numbers
  - Automatically restarts BPQ via `systemctl restart bpq` after changes
  - `--diff` detects CMS re-injection of previously removed entries
  - `--blacklist-add` for permanently flagging persistent bogus callsigns
  - Whitelist prevents re-flagging of reviewed/approved entries
  - Timestamped backup created before every apply operation
  - Full audit log written to wp_manager.log
  - Default WP path: /path/to/linbpq/WP.cfg
- `scripts/wp_scanner.py` — Standalone batch scanner for audit/reporting
  - Read-only analysis mode, safe to run at any time
  - Produces wp_scan_report.txt with full suspect breakdown
  - Callsign validation covering all ITU prefix allocations
