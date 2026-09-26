-- ────────────────────────────────────────────────────────────────────────────
-- BPQ Dashboard — rf_connections table
-- ────────────────────────────────────────────────────────────────────────────
--
-- Purpose: pre-parsed view of every VARA HF session, populated by
-- parse-vara-log.py from /var/www/<dashboard>/logs/<call>.vara file.
--
-- Why: bpq-rf-connections.html previously parsed the entire VARA log on
-- every page load (multi-second JS work). With this table, it just hits an
-- indexed SELECT and renders in <1 sec.
--
-- Data philosophy:
--   - Log file on disk = source of truth, never deleted
--   - This table = cached derived view, can be dropped + re-parsed safely
--   - Backfill is idempotent (UNIQUE constraint on session_start + callsign)
--
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rf_connections (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Session identity
    callsign        VARCHAR(16)     NOT NULL,            -- 'KE0GB' or 'KE0GB-1'
    base_call       VARCHAR(10)     NOT NULL,            -- 'KE0GB' (without SSID)
    direction       ENUM('incoming','outgoing','unknown') NOT NULL DEFAULT 'unknown',

    -- Timing — stored as DATETIME in UTC; the source log format is
    -- "Mon DD HH:MM:SS" or "YYMMDD HH:MM:SS". Parser converts to UTC datetime.
    session_start   DATETIME        NOT NULL,
    session_end     DATETIME        NULL,
    duration_sec    INT UNSIGNED    NULL,                -- NULL if session ongoing

    -- RF metadata
    freq_khz        INT UNSIGNED    NULL,                -- 14105 for 14.105 MHz; NULL if unknown
    band            VARCHAR(8)      NULL,                -- '80m','40m','30m','20m','17m','15m','VARA HF','WL2K','Unknown'
    bandwidth       SMALLINT UNSIGNED NULL,              -- 500 / 2300 / 2750 — extracted from VARA "Bandwidth" line

    -- Quality / throughput
    avg_snr_db      DECIMAL(5,1)    NULL,                -- e.g. 12.5 — from "Average S/N: 12.5 dB" line
    tx_bytes        INT UNSIGNED    NULL,
    rx_bytes        INT UNSIGNED    NULL,
    tx_max_bps      INT UNSIGNED    NULL,
    rx_max_bps      INT UNSIGNED    NULL,

    -- Outcome
    status          ENUM('success','failed','timeout','aborted','port_in_use','channel_busy','unknown')
                    NOT NULL DEFAULT 'unknown',
    failure_reason  VARCHAR(64)     NULL,                -- set when status != success. Values written by
                                                           -- parse-vara-log.py: 'no_data', 'incoming_failed',
                                                           -- 'port_in_use', 'superseded', 'incomplete_no_disconnect',
                                                           -- 'channel_busy' (BBS-log-correlated, added 2026-09-16)

    -- Bookkeeping
    log_line_no     INT UNSIGNED    NULL,                -- Where in the source log this session ends — useful for debug
    parsed_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Composite uniqueness — a session is uniquely identified by its
    -- start time + callsign. Re-running the parser must NOT insert dupes.
    UNIQUE KEY uniq_session (session_start, callsign),

    -- Lookup indexes for common queries
    KEY idx_start    (session_start),
    KEY idx_base     (base_call),
    KEY idx_band     (band, session_start),
    KEY idx_status   (status, session_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Pre-parsed VARA HF sessions for fast page rendering';

-- ────────────────────────────────────────────────────────────────────────────
-- Parser state — track which line of which file we last read so the
-- incremental parser knows where to resume. One row per log file.
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rf_parser_state (
    log_file        VARCHAR(255)    NOT NULL,            -- e.g. 'yourcall.vara'
    last_offset     BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- byte offset reached
    last_line_no    INT UNSIGNED    NOT NULL DEFAULT 0,  -- 1-based line number reached
    last_parsed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sessions_total  INT UNSIGNED    NOT NULL DEFAULT 0,  -- cumulative count parsed (debug/sanity)

    PRIMARY KEY (log_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Incremental parser checkpoint per log file';

-- ────────────────────────────────────────────────────────────────────────────
-- rf_messages — pre-parsed FBB message-forwarding events (added 2026-09-16)
-- ────────────────────────────────────────────────────────────────────────────
--
-- Purpose: pre-parsed view of every FB/FA/FC message-proposal line seen in
-- the BBS logs, populated by parse-vara-log.py from the same
-- log_YYMMDD_BBS.txt files it already reads for frequency/channel-busy
-- correlation.
--
-- Why: bpq-rf-connections.html's "Forwarded Messages" panel previously
-- re-parsed the entire lazy-loaded BBS log window client-side, in every
-- visitor's browser, on every page load and every 15-minute auto-refresh —
-- duplicating work the parser now does once, incrementally, server-side.
-- It was also limited to whatever days of BBS log the browser had lazily
-- fetched; this table holds full history regardless.
--
-- Data philosophy: same as rf_connections — BBS log on disk is the source
-- of truth, this table is a disposable cached derived view (safe to drop
-- and re-parse).
--
CREATE TABLE IF NOT EXISTS rf_messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    bid             VARCHAR(32)     NOT NULL,            -- FBB message ID, e.g. '6938_LU9DCE'
    direction       ENUM('in','out') NOT NULL,           -- 'out' = we forwarded it out, 'in' = we received it
    partner         VARCHAR(16)     NOT NULL,            -- forwarding partner base callsign (SSID stripped)
    msg_type        CHAR(1)         NOT NULL,            -- 'B'ulletin, 'P'ersonal, 'T'raffic
    from_call       VARCHAR(16)     NOT NULL,
    to_area         VARCHAR(32)     NOT NULL,
    category        VARCHAR(16)     NOT NULL,
    size_bytes      INT UNSIGNED    NOT NULL,

    msg_time        DATETIME        NOT NULL,            -- UTC, from the BBS log line timestamp

    -- Medium that carried this message proposal (added 2026-09-21):
    -- 'VARA' (RF/HF), 'NETROM', 'AX.25', 'TELNET', or another value as
    -- logged by BPQ's ConnectLog_YYMMDD.log — see
    -- build_connect_mode_lookup()/lookup_mode_for_message() in
    -- parse-vara-log.py. NULL means "unknown/uncorrelated", e.g. no
    -- ConnectLog coverage for that window — NOT "non-RF". Callers must
    -- only filter out rows with a known non-VARA medium, never NULL
    -- rows, or gaps in ConnectLog history would silently hide real
    -- messages. `partner` alone (SSID stripped) cannot distinguish a
    -- real VARA HF forwarding partner (e.g. N3MEL-2) from the same base
    -- callsign reached over NET/ROM/AX.25 (e.g. N3MEL-7) — this column
    -- is what makes that distinction possible.
    medium          VARCHAR(16)     NULL,

    log_line_no     INT UNSIGNED    NULL,
    parsed_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- A message proposal is uniquely identified by its BID + direction —
    -- the same (bid, direction) combination the legacy in-browser parser
    -- used to dedup (`seenBIDs` Set keyed on `bid + '_' + dir`). Matches
    -- FA/FC lines can otherwise appear more than once in the logs (retries,
    -- log rotation overlap) — this key makes re-parsing idempotent.
    UNIQUE KEY uniq_message (bid, direction),

    KEY idx_time     (msg_time),
    KEY idx_partner  (partner, msg_time),
    KEY idx_medium   (medium)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Pre-parsed FBB message-forwarding events for fast page rendering';
