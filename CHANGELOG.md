# Changelog

All notable changes to BPQ Dashboard will be documented in this file.

## [1.5.5-patch2] - 2026-04-06

### Added

- **bpq-aprs.html — APRS Messaging modal** — Send/inbox two-way messaging. Compose tab with 67-char limit. Inbox for messages to K1AJD-1. Reply button. Unread badge. Double-click marker or click list pre-fills callsign.
- **bpq-aprs.html — Full WX detail panel** — All APRS WX fields in imperial (°F, mph, inHg, inches). Wind direction as compass bearing. Both popup and detail panel updated.
- **bpq-aprs.html — Station Browser modal** — Categorized by Mobile/WX/Digi/iGate/Fixed/Object. Sorted by distance. Expandable rows with full details. Show on map / Message / aprs.fi links.
- **bpq-aprs.html — Distance from K1AJD** — Haversine distance + compass bearing for all WX stations in popup, detail panel, browser summary and browser detail.
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

- **bpq-chat.html — Double callsign in messages** — sendRoomMsg() and sendServerCmd() were echoing messages locally AND receiving BPQ's echo via poll causing duplicates (K1AJDK1AJD: text). Removed local echo — BPQ's echo is the single display source.
- **bpq-chat.html — /B closing entire chat** — /B is BPQ's full chat exit command. In a room tab /B now sends /T General (return to General topic, stay in chat) and closes the tab. /QUIT triggers full disconnect. /B in server pane retains standard BPQ behavior.

---

## [1.5.4-patch3] - 2026-04-04

### Added

- **bpq-chat.html — Auth system** — Auth modal on page load. Three levels: guest (read-only), user (own BPQ credentials), sysop (config.php). Guest mode shows orange banner, disables all send controls. Guest request modal opens mailto: pre-filled to tony@k1ajd.net with callsign/name/note.
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

- **visitor-log.php — tprfn-network-map logging** — Visits to tprfn.k1ajd.net were not being recorded. Fixed by adding 'tprfn-index-log.php' and 'tprfn-network-map' to the allowed pages list, and adding page name resolver (tprfn-index-log.php → tprfn-network-map). Root cause was the @ error suppressor hiding the write result during debugging — logging was actually working after the allowed list fix was applied.

---

## [1.5.3-patch15] - 2026-03-31

### Fixed

- **callsign-lookup.php — QRZ.com primary lookup** — Archived version was outdated (callook.info + HamDB). Live version on ARSSYSTEM uses QRZ.com as primary with callook.info fallback. Updated both archives with live 876-line version. Lookup confirmed working — browser cache was causing apparent failures. Hard refresh resolved all callsigns except N4DGE (not in QRZ database).

---

## [1.5.3-patch14] - 2026-03-31

### Fixed

- **bbs-messages.html — iOS Safari portrait blank screen** — Viewport meta tag was missing `maximum-scale=1.0, user-scalable=no`. Without these iOS Safari applied its own scaling, reporting `window.innerWidth` larger than physical screen width, preventing the `@media (max-width:767px)` mobile layout from triggering. Fixed to match all other dashboard pages. Also added full iOS Safari height chain: `100svh`/`100dvh`/`100vh` cascade, `@supports (-webkit-touch-callout:none)` detection, `-webkit-fill-available`, `-webkit-overflow-scrolling:touch` on scroll containers, and `viewport-fit=cover` for notch/safe area support.

---

## [1.5.3-patch13] - 2026-03-30

### Fixed

- **Visitor tracking restored** — nginx dual-domain reconfiguration broke visitor logging on both domains. Created `tprfn-index-log.php` wrapper to log TPRFN Network Map visits (index.php is pure HTML, cannot include PHP directly). Restored nginx rewrite rules on `bpq.k1ajd.net` to route through `tprfn-rf-log.php`. Both `tprfn.k1ajd.net` and `bpq.k1ajd.net` visits now tracked in visitor-log.php.

---

## [1.5.3-patch12] - 2026-03-30

### Fixed

- **bbs-messages.html — Infinite recursion on Get Mail** — Mobile responsive patch used JS wrapper functions (`const _origX = X; function X() { _origX()... }`) causing infinite recursion. Fixed by injecting mobile sync calls directly into existing function bodies.
- **bbs-messages.html — Folders wiped on reload** — `loadSavedFromServer()` was replacing the local folder array with the server's list (`['Saved']`), wiping all user-created folders on every page load when server storage mode was active. Fixed to merge local + server folders using Set (no duplicates).
- **bbs-messages.html — Folder list clipping** — With 18 folders the sidebar and save picker overflowed. Added `overflow-y:auto` scroll to `#folderList` and `max-height:280px` to save picker.
- **bbs-messages.html — Wrong localStorage key in deleteFolder** — `persist('bbs_saved', ...)` corrected to `persist('bbs_saved_messages', ...)` in three places.
- **bbs-messages.html — Save picker not updating on folder create** — `createFolder()` now calls `renderSaveFolderList()` to keep save picker in sync.
- **network-api.php — rename() warnings** — `cache/` directory was missing. Fix: `sudo mkdir -p /var/www/tprfn/cache && sudo chown -R www-data:www-data /var/www/tprfn/cache`.

### Added

- **bbs-messages.html — Rules Test button** — 🔍 Test button in rules modal tests rule against currently loaded inbox, shows match count and preview.
- **bbs-messages.html — Msg # field in rules** — Added message number as a matchable field in the rules engine.

---

## [1.5.3-patch11] - 2026-03-29

### Added

- **tprfn.conf — Dual domain nginx config** — Complete nginx config serving both tprfn.k1ajd.net (TPRFN Network Map) and bpq.k1ajd.net (BPQ Dashboard) from /var/www/tprfn/. bpq.k1ajd.net root serves bpq-rf-connections.html. All BPQ Dashboard URLs on tprfn.k1ajd.net redirect 301 to bpq.k1ajd.net. All original security rules (bot blocking, rate limiting, headers, directory protection) preserved and applied to both domains. Port 514 syslog unaffected.

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

- **bpq-aprs.html — APRS Messaging modal** — Send/inbox two-way messaging. Compose tab with 67-char limit. Inbox for messages to K1AJD-1. Reply button. Unread badge. Double-click marker or click list pre-fills callsign.
- **bpq-aprs.html — Full WX detail panel** — All APRS WX fields in imperial (°F, mph, inHg, inches). Wind direction as compass bearing. Both popup and detail panel updated.
- **bpq-aprs.html — Station Browser modal** — Categorized by Mobile/WX/Digi/iGate/Fixed/Object. Sorted by distance. Expandable rows with full details. Show on map / Message / aprs.fi links.
- **bpq-aprs.html — Distance from K1AJD** — Haversine distance + compass bearing for all WX stations in popup, detail panel, browser summary and browser detail.
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

- **bpq-chat.html — Double callsign in messages** — sendRoomMsg() and sendServerCmd() were echoing messages locally AND receiving BPQ's echo via poll causing duplicates (K1AJDK1AJD: text). Removed local echo — BPQ's echo is the single display source.
- **bpq-chat.html — /B closing entire chat** — /B is BPQ's full chat exit command. In a room tab /B now sends /T General (return to General topic, stay in chat) and closes the tab. /QUIT triggers full disconnect. /B in server pane retains standard BPQ behavior.

---

## [1.5.4-patch3] - 2026-04-04

### Added

- **bpq-chat.html — Auth system** — Auth modal on page load. Three levels: guest (read-only), user (own BPQ credentials), sysop (config.php). Guest mode shows orange banner, disables all send controls. Guest request modal opens mailto: pre-filled to tony@k1ajd.net with callsign/name/note.
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

- **visitor-log.php — tprfn-network-map logging** — Visits to tprfn.k1ajd.net were not being recorded. Fixed by adding 'tprfn-index-log.php' and 'tprfn-network-map' to the allowed pages list, and adding page name resolver (tprfn-index-log.php → tprfn-network-map). Root cause was the @ error suppressor hiding the write result during debugging — logging was actually working after the allowed list fix was applied.

---

## [1.5.3-patch15] - 2026-03-31

### Fixed

- **callsign-lookup.php — QRZ.com primary lookup** — Archived version was outdated (callook.info + HamDB). Live version on ARSSYSTEM uses QRZ.com as primary with callook.info fallback. Updated both archives with live 876-line version. Lookup confirmed working — browser cache was causing apparent failures. Hard refresh resolved all callsigns except N4DGE (not in QRZ database).

---

## [1.5.3-patch14] - 2026-03-31

### Fixed

- **bbs-messages.html — iOS Safari portrait blank screen** — Viewport meta tag was missing `maximum-scale=1.0, user-scalable=no`. Without these iOS Safari applied its own scaling, reporting `window.innerWidth` larger than physical screen width, preventing the `@media (max-width:767px)` mobile layout from triggering. Fixed to match all other dashboard pages. Also added full iOS Safari height chain: `100svh`/`100dvh`/`100vh` cascade, `@supports (-webkit-touch-callout:none)` detection, `-webkit-fill-available`, `-webkit-overflow-scrolling:touch` on scroll containers, and `viewport-fit=cover` for notch/safe area support.

---

## [1.5.3-patch13] - 2026-03-30

### Fixed

- **Visitor tracking restored** — nginx dual-domain reconfiguration broke visitor logging on both domains. Created `tprfn-index-log.php` wrapper to log TPRFN Network Map visits (index.php is pure HTML, cannot include PHP directly). Restored nginx rewrite rules on `bpq.k1ajd.net` to route through `tprfn-rf-log.php`. Both `tprfn.k1ajd.net` and `bpq.k1ajd.net` visits now tracked in visitor-log.php.

---

## [1.5.3-patch12] - 2026-03-30

### Fixed

- **bbs-messages.html — Infinite recursion on Get Mail** — Mobile responsive patch used JS wrapper functions (`const _origX = X; function X() { _origX()... }`) causing infinite recursion. Fixed by injecting mobile sync calls directly into existing function bodies.
- **bbs-messages.html — Folders wiped on reload** — `loadSavedFromServer()` was replacing the local folder array with the server's list (`['Saved']`), wiping all user-created folders on every page load when server storage mode was active. Fixed to merge local + server folders using Set (no duplicates).
- **bbs-messages.html — Folder list clipping** — With 18 folders the sidebar and save picker overflowed. Added `overflow-y:auto` scroll to `#folderList` and `max-height:280px` to save picker.
- **bbs-messages.html — Wrong localStorage key in deleteFolder** — `persist('bbs_saved', ...)` corrected to `persist('bbs_saved_messages', ...)` in three places.
- **bbs-messages.html — Save picker not updating on folder create** — `createFolder()` now calls `renderSaveFolderList()` to keep save picker in sync.
- **network-api.php — rename() warnings** — `cache/` directory was missing. Fix: `sudo mkdir -p /var/www/tprfn/cache && sudo chown -R www-data:www-data /var/www/tprfn/cache`.

### Added

- **bbs-messages.html — Rules Test button** — 🔍 Test button in rules modal tests rule against currently loaded inbox, shows match count and preview.
- **bbs-messages.html — Msg # field in rules** — Added message number as a matchable field in the rules engine.

---

## [1.5.3-patch11] - 2026-03-29

### Added

- **tprfn.conf — Dual domain nginx config** — Complete nginx config serving both tprfn.k1ajd.net (TPRFN Network Map) and bpq.k1ajd.net (BPQ Dashboard) from /var/www/tprfn/. bpq.k1ajd.net root serves bpq-rf-connections.html. All BPQ Dashboard URLs on tprfn.k1ajd.net redirect 301 to bpq.k1ajd.net. All original security rules (bot blocking, rate limiting, headers, directory protection) preserved and applied to both domains. Port 514 syslog unaffected.

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

  Fix: CMSAccess parser now builds a `wl2kSessions{}` dict with callsign, time, and seconds. The `Average S/N` handler checks `wl2kSessions` within ±5 minutes when `curr` is null, creates a synthetic `curr` with `band: 'WL2K'` and inferred frequency from `findNearestFrequency()`. WL2K sessions now appear in the connections table with correct callsign, time, S/N, bytes transferred and inferred frequency (marked `~`). Own outbound CMS connections (K1AJD-7) correctly excluded.

---

## [1.5.3-patch2] - 2026-03-25

### Fixed

- **bpq-rf-connections.html + hub-ops.html — VARA log dual date format** — k1ajd.vara contains two date formats: old LinBPQ `YYMMDD HH:MM:SS` and new `Mon DD HH:MM:SS`. Both parsers were silently skipping half the log. Added dual-format detection to `varaLogs.forEach()` and `bbsLogs.forEach()` in both files. YYMMDD format converted to Mon DD internally for compatibility.

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

- **tprfn-hub-report.php** — Live HTML hub health report with PDF print support. Queries MariaDB `tprfn` database directly. 10 sections: network overview, hub performance table, today's activity, hubs requiring attention, hub-to-hub link quality, top polling stations, 14-day trend, VARA speed distribution, prop scheduler decision history, schedule change impact (pre/post 7-day correlation). Uses `tprfn_app` credentials. Print/Save PDF via browser native print dialog with full `@media print` stylesheet.

- **prop_decisions table** (`data/prop-decisions-schema.sql`) — MariaDB table tracking every prop-scheduler.py decision run. 14 columns including run_at, mode (dry/apply), SFI, Kp, season, partner, changed flag, old/new ConnectScript, historical band stats summary, and time block assignments as JSON. One row per partner per run, both changed and unchanged partners recorded. Enables correlation analysis between scheduling decisions and session success rates.

- **prop-scheduler.py** — Updated to record every decision run to `prop_decisions` table via `save_decisions()` function. Called after both `--apply` and dry runs. Also updated to load partners from `partners.json` via `load_partners_json()` and `build_partners_from_list()`. `active: false` partners silently skipped.

- **storm-monitor.py** — Major update: tiered distance-based partner suspension during geomagnetic storms. Uses `Enabled = 0/1` in linmail.cfg (cleaner than ConnectScript modification — scripts never touched for suspended partners). New functions: `get_enabled()`, `set_enabled()`, `get_partner_action()`. Tiered by Kp severity and distance: G1(Kp≥5)=80m only, G2(Kp≥6)=suspend partners ≥500mi, G3(Kp≥7)=suspend ≥300mi, G4(Kp≥8)=suspend all >200mi. Also updated to load partners from `partners.json`.

- **nginx-maintenance-block.conf** — nginx location block restricting all admin/maintenance pages to LAN only (10.0.0.0/8 + localhost). Prevents Google crawler and external access to bpq-maintenance.html, system-audit.html, firewall-status.html, log-viewer.html, and all API PHP files.

### Fixed

- **bpq-maintenance.html** — Removed `document.execCommand('copy')` which triggered Google Safe Browsing false positive virus warning. Replaced with safer clipboard fallback using `setSelectionRange()`. All admin/maintenance pages should be restricted to LAN only via nginx (see `nginx-maintenance-block.conf`).

- **wp_manager.py blacklist** — Fixed relative path issue causing blacklist file to resolve to different locations depending on run directory. All file paths (`BLACKLIST_FILE`, `WHITELIST_FILE`, `REVIEW_FILE`, `BASELINE_FILE`, `LOG_FILE`) now use `os.path.join(os.path.dirname(os.path.abspath(__file__)), ...)` for consistent resolution regardless of working directory. Merged duplicate `/root/wp_blacklist.txt` into `/var/www/tprfn/scripts/wp_blacklist.txt`.

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
  - Hierarchical BBS addressing supported in To/Category fields: dotted paths (`KD8NOA.MI.USA.NOAM`), hash separators (`K1AJD#AUG.GA.USA.NOAM`), and @ routing (`KM2E@WA2UET.#ENY.NY.USA.NOAM`)
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
  - Complete server block replacement for `tprfn.k1ajd.net` with access controls
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
  - Default WP path: /home/tony/linbpq/WP.cfg
- `scripts/wp_scanner.py` — Standalone batch scanner for audit/reporting
  - Read-only analysis mode, safe to run at any time
  - Produces wp_scan_report.txt with full suspect breakdown
  - Callsign validation covering all ITU prefix allocations
