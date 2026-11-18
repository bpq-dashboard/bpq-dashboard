# Mercury HF on the dashboard: what's there, what's planned

BPQ Dashboard v1.5.43, 2026-11-13; step 2 v1.5.55, 2026-11-14. Setting Mercury up: Installation Manual §10.17.

Mercury (Rhizomatica, open source) is an HF modem with a VARA-style TNC that
LinBPQ attaches as a VARA-type port. Mercury accepts **one** client on its
control port, and LinBPQ holds it, so the dashboard can never ask Mercury
anything over the TNC. Everything the dashboard shows comes from outside
Mercury: the process, its sockets, its log and (later) its status feed.

## Step 1: Mercury HF Status card (done, v1.5.43)

- `includes/mercury-status.php`
  - Reads `/proc` to see whether Mercury runs, and `/proc/net/tcp` to see
    whether its TNC port listens and LinBPQ is attached.
  - Reads Mercury's log incrementally. `cache/mercury-state.json` keeps the
    file's inode and offset. On rotation to `<log>.1`, it finishes the old
    file first. The first look ever reads the last 4 MB.
- `rf-connections-api.php?action=mercury` serves the card, which refreshes
  every minute. It needs no database.
- System Audit adds `mercury` (running, attached, audio) and
  `mercury-exposed` (are the TNC ports firewalled?). Maintenance has a
  *Mercury HF modem* section, the Log Viewer an entry, and the Firewall page
  labels the Mercury ports.

Log lines used (Mercury 1.9.24, `log_utc = true` gives a `Z` suffix):

| Line | Used for |
|---|---|
| `[main] Mercury 1.9.24 (git …)` | version |
| `[engine] Mercury engine initialised` / `[engine] Shutting down` | start / stop |
| `[arq] My callsign: K1AJD-4` | callsign (sent by LinBPQ) |
| `[tcp-ctl] Command received: LISTEN ON/OFF` | listening; LinBPQ's scan / INTERLOCK toggles it every few seconds |
| `[tcp-ctl] Command received: CONNECT <me> <peer>` | outgoing call |
| `[arq] Incoming connection from <peer> on <me> (pending)` | incoming call |
| `[arq] Connected to <peer>` | session start (both directions) |
| `[arq] Disconnect finalized (<why>)`, `Disconnected`, `Session ended …`, `The peer's application has hung up …` | session end / failed call |
| `[modem-rx] >>> DECODED FRAME n: b bytes, SNR: x dB` | last decode S/N; per-session S/N range. **Not logged at the default INFO level** (seen 2026-11-14), so normally empty |
| `[TMG] [arq-timing] connect mode=DATAC16` | session mode |
| `[TMG] [arq-timing] disconnect reason=timeout tx_bytes=44 rx_bytes=0 frames_tx=2 frames_rx=2` | how the session ended, bytes each way (just before `Disconnected`) |
| `[WRN] [arq-fsm] carousel: nothing from the peer in 240 s with data in flight — link lost` | why it ended |
| `[arq] Incoming connection cancelled` | a heard call that never became a link: grouped per caller on the card, counted in *Calls (24 h)* |
| `[tcp-ctl] LISTEN OFF 0.0 s into a MFSK500 frame not yet decoded: LISTEN OFF drops it` | a scan hop cut a frame: counted per hour |
| `[tcp-ctl] Control client connected` / `… closed the connection` | LinBPQ attached / detached (each LinBPQ restart) |
| `[arq-fsm] CALL from X again: PENDING again …`, `… on the MFSK floor …` | not used (a repeated CALL during one attempt) |
| `[radio] TX enabled (PTT ON)` | last transmit |
| `[audio-cap] the capture device delivers no audio …` / `capture delivering again …` | audio trouble |
| level `[ERR]` | errors (24 h); `snd_pcm_pause` at stop is ignored (harmless with a shared card) |

## Step 2: Mercury calls in RF Connections (done, v1.5.46)

**What was built.**

- `scripts/parse-mercury-log.py` reads Mercury's log incrementally (byte
  offset in `rf_parser_state` under the log's file name; rotation to `.1`
  is followed; a call still open at the end of the file is read again next
  run). `parse-vara-log.py` starts it after itself every 5 minutes when the
  log exists, so no new cron line.
- Migration `sql/2026-11-14-rf-connections-modem.sql` adds to
  `rf_connections`: `modem ENUM('vara','mercury') DEFAULT 'vara'`,
  `link_mode` (e.g. DATAC16), `end_reason` (Mercury's `reason=`), and
  `KEY idx_modem`. `parse-vara-log.py --reset` now clears VARA rows only.
- One row per call:
  - incoming: *Incoming connection from X (pending)*; *cancelled* before a
    link → failed `incoming_failed` ("call not completed");
  - outgoing: LinBPQ's `CONNECT A B`; *Disconnected* with no link → failed
    `no_answer`;
  - a link: *Connected to X* … TMG `disconnect reason=R tx_bytes rx_bytes`
    … *Disconnected* → success, or failed `no_data` when nothing came in;
  - cut off by a LinBPQ or Mercury restart → aborted `linbpq_restart` /
    `modem_restart`.
- Frequency from the BBS log (*Incoming Connect … Freq F Mode VARA[ 500]*
  within 60 s, or `>CALL RADIO f` up to 3 min before an outgoing call);
  bandwidth from that line or the last `BWnnnn` command.
- The page: Recent connections (Modem column, filter, plain-word results),
  the partners' 7-day blocks, the 7-day history and the hourly chart.

**Decision (Tony, 2026-11-14, "Option 2").** The headline numbers (success
rate, failure chips, average S/N, stations), Best band, channel quality, the
propagation report and the Station report stay **VARA only**: Mercury logs no
per-session S/N or bitrate, and scan hops leave many short failed calls, so
mixing them would change what those numbers mean. `config.php`
`mercury.in_headlines = true` puts Mercury into them.

**Tested against** K1AJD's logs of 2026-11-13/14: 17 incoming calls from
K7EK (scan-cancelled, two cut by LinBPQ restarts, one link with nothing
received), the two 40 m sessions of 2026-11-14 (49 and 1,228 bytes in,
`rx_disconnect` and `peer_ack`), and the unanswered outgoing call at 23:27Z.

**Not done.** S/N and best speed per session: Mercury's INFO log has neither
per session (the S/N of each decoded frame is there; an average could be
added if wanted). Bytes sent come from Mercury's TMG line, so they are there.

## Next: label messages forwarded over Mercury (to do)

LinBPQ drives Mercury through a `DRIVER=VARA` port, so the dashboard's
port-to-medium map (parse-vara-log.py) calls messages forwarded over it
"VARA". Plan: treat a VARA-driver port whose ADDR port is Mercury's TNC
port (config.php `mercury.tnc_port`, default 8400) as **Mercury**, accept
it as RF on the page, and give it its own label in Forwarded messages and
Sent/rcvd. (Noted 2026-11-15.)

## Mercury's own monitor on the station desktop (done, v1.5.49)

Checked in Mercury's source (tag v1.9.24 and main, 2026-11-15):
`ui_enabled = true` starts a small HTTP + WebSocket server on `ui_port`
(`gui_interface/websocket/`), bound to 0.0.0.0 (IPv4), no password. The
pages are compiled into the binary: `waterfall.html` (watch only: sends no
commands; shows SNR, bitrate, direction, sync, user/dest callsign, TCP,
TX/RX, FPS and the waterfall), `mercury.html` (full page: `set_audio_config`,
`set_ptt_config`, `set_tx_gain`), `test.html`. Status messages are listed
in `docs/WEBSOCKET_API.md` §5.1 and `docs/WEBSOCKET_STATUS.md`.

`tools/mercury-monitor.sh` blocks the port from the network, sets
`ui_enabled / ui_port = 8403 / waterfall_enabled` in `[main]`, restarts
Mercury (waiting out a session), checks LinBPQ reattaches and the page
answers on 127.0.0.1, and puts a launcher on the desktop of the user Mercury
runs as. System Audit checks the port is blocked; the Firewall page labels
it.

## Later: a live status feed (possible)

Mercury has a WebSocket status feed for its own screen (`mercury -G`,
`[main] ui_enabled = true`, default port 10000). It carries:

- `audio_ok`;
- the busy-channel state;
- S/N, the transmit and receive state, and the speed rung;
- optionally spectrum and waterfall data.

**How it could work:**

- The feed is the one the desktop monitor uses (above, port 8403, kept
  local by the firewall).
- A small dashboard proxy reads it on 127.0.0.1, either PHP on demand or a
  tiny daemon like bpq-vara. It passes a reduced, read-only status to the
  page, so the browser never reaches Mercury itself.
- The card gains a live S/N meter, BUSY/CLEAR, TX/RX and, later, a small
  waterfall.

**To check first:**

- the message format: done (`docs/WEBSOCKET_API.md` in Mercury's source);
- whether it costs anything while LinBPQ scans: watch with the desktop
  monitor running.
