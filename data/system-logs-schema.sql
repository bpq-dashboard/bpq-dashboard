-- ────────────────────────────────────────────────────────────────────────────
-- BPQ Dashboard — system-logs tables
-- ────────────────────────────────────────────────────────────────────────────
--
-- Purpose: DB-backed replacement for bpq-system-logs.html's client-side
-- parsing of log_YYMMDD_BBS.txt (up to 365 days / 100K lines),
-- CMSAccess_YYYYMMDD.log, log_YYMMDD_TCP.txt, plus the three live-snapshot
-- files (nodesrtt.txt, MHSave.txt, RTKnown.txt). Populated by
-- scripts/parse-system-logs.py.
--
-- Scope: STATS/CHARTS ONLY — the raw log viewer/search on that page keeps
-- reading the log files directly, unchanged. These tables store one row
-- per *classified event* (a connection, a message, an error, ...), not
-- one row per raw log line — the same "extract structured facts, not
-- verbatim text" philosophy as rf_connections/rf_messages. The one
-- exception is `sys_events.raw_line`, populated only for event_type =
-- 'error', so the page's "recent errors" list and "jump to this line in
-- the viewer" feature keep working without needing every line stored.
--
-- Data philosophy: same as rf_connections/rf_messages — log files on disk
-- are the source of truth, these tables are a disposable cached derived
-- view (safe to drop and re-parse). The three net_* tables are the
-- exception: they mirror BPQ's own live-snapshot files (nodesrtt.txt,
-- MHSave.txt, RTKnown.txt), which BPQ itself overwrites in place with
-- current state only — so these tables hold current state only too
-- (truncate + reinsert each poll), not history.
-- ────────────────────────────────────────────────────────────────────────────

-- ── Main system log (log_YYMMDD_BBS.txt) ────────────────────────────────────
--
-- One row per classified event. A single log line can legitimately
-- produce more than one row (e.g. a line can both match the message
-- pattern and separately be counted toward a station's activity) — this
-- mirrors the original client-side parser's independent per-line checks,
-- not mutually exclusive categories. Idempotent re-parsing is by
-- (source_file, log_line_no, event_type), not a single-column key.
CREATE TABLE IF NOT EXISTS sys_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    event_time      DATETIME        NOT NULL,
    event_type      ENUM('connection','message','message_rejected','error','vara_bitrate')
                    NOT NULL,

    direction       ENUM('in','out','unknown') NULL,   -- message events only
    error_category  VARCHAR(32)     NULL,               -- error events only:
                                                          -- rf_failure / node_disappeared /
                                                          -- protocol_error / timeout /
                                                          -- connect_failure / other
    callsign        VARCHAR(16)     NULL,               -- base call, if the line had one
    bitrate_bps     SMALLINT UNSIGNED NULL,              -- vara_bitrate events only

    raw_line        TEXT            NULL,               -- error events only — powers the
                                                          -- Recent Errors list + "find in
                                                          -- viewer" without re-fetching files

    source_file     VARCHAR(64)     NOT NULL,
    log_line_no     INT UNSIGNED    NOT NULL,
    parsed_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_event (source_file, log_line_no, event_type),

    KEY idx_time     (event_time),
    KEY idx_type     (event_type, event_time),
    KEY idx_callsign (callsign, event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Classified events from the main BPQ system log';

-- Per-(day, callsign) activity rollup — powers Top Stations / the
-- per-station breakdown modal. Computed directly during the log scan
-- (same pass that produces sys_events), not derived from sys_events
-- afterward, since "touched this station" is a broader per-line signal
-- than any single sys_events row. Each day's rows are fully replaced on
-- re-parse (same "freshest parse wins" pattern as traffic_stats), so a
-- day is always internally consistent.
CREATE TABLE IF NOT EXISTS sys_station_daily (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    activity_date   DATE            NOT NULL,
    callsign        VARCHAR(16)     NOT NULL,

    events          INT UNSIGNED    NOT NULL DEFAULT 0,
    connections     INT UNSIGNED    NOT NULL DEFAULT 0,
    messages_tx     INT UNSIGNED    NOT NULL DEFAULT 0,
    messages_rx     INT UNSIGNED    NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_station_day (activity_date, callsign),
    KEY idx_date (activity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-day per-station activity rollup';

-- Cumulative (not per-day) — mirrors the original `internetStations`
-- object exactly: last-connect time + running count per base callsign
-- that connected without a Freq tag (i.e. not RF).
CREATE TABLE IF NOT EXISTS sys_internet_stations (
    callsign        VARCHAR(16)     NOT NULL,
    last_connect    DATETIME        NOT NULL,
    connect_count   INT UNSIGNED    NOT NULL DEFAULT 0,

    PRIMARY KEY (callsign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Non-RF (internet-connected) stations, cumulative';

-- ── RMS / CMSAccess log (CMSAccess_YYYYMMDD.log) ────────────────────────────
--
-- Sessions, paired Connect->Disconnect same as rf_connections. Unlike the
-- original JS (which summed bytes/duration globally, not per-station),
-- this ties each Disconnect's byte counts back to the station that
-- connected — a straightforward improvement given the log already pairs
-- them in sequence, and it's the same pairing logic parse-vara-log.py
-- already uses for VARA sessions.
CREATE TABLE IF NOT EXISTS rms_connections (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    callsign        VARCHAR(16)     NOT NULL,           -- base call, SSID stripped
    connect_time    DATETIME        NOT NULL,
    disconnect_time DATETIME        NULL,
    bytes_sent      INT UNSIGNED    NULL,
    bytes_received  INT UNSIGNED    NULL,
    duration_sec    INT UNSIGNED    NULL,

    source_file     VARCHAR(64)     NOT NULL,
    log_line_no     INT UNSIGNED    NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_rms_conn (source_file, log_line_no),
    KEY idx_time (connect_time),
    KEY idx_call (callsign, connect_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='RMS/CMSAccess connection sessions';

-- "Login failed" lines aren't tied to a specific station in the source
-- log (same limitation the original JS had — it only counted these
-- globally), so this is a standalone event log, not part of a session.
CREATE TABLE IF NOT EXISTS rms_login_failures (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_time      DATETIME        NOT NULL,
    raw_line        TEXT            NULL,

    source_file     VARCHAR(64)     NOT NULL,
    log_line_no     INT UNSIGNED    NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_rms_fail (source_file, log_line_no),
    KEY idx_time (event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='RMS/CMSAccess login failures';

-- ── TCP log (log_YYMMDD_TCP.txt) ────────────────────────────────────────────
--
-- One row per matched line — TCP log lines are single-event (no
-- Connect/Disconnect pairing like RMS/VARA), so no session concept here,
-- same as the original JS's flat per-line counting.
CREATE TABLE IF NOT EXISTS tcp_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_time      DATETIME        NOT NULL,
    event_type      ENUM('pop3','smtp','nntp','auth_fail') NOT NULL,

    source_file     VARCHAR(64)     NOT NULL,
    log_line_no     INT UNSIGNED    NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_tcp_event (source_file, log_line_no, event_type),
    KEY idx_time (event_time),
    KEY idx_type (event_type, event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='TCP (POP3/SMTP/NNTP) session events';

-- ── Live-snapshot sources — nodesrtt.txt / MHSave.txt / RTKnown.txt ─────────
--
-- BPQ rewrites these files with current state only, in place — there is
-- no history to backfill, so these tables hold ONE row per current
-- entry, fully replaced on every poll (TRUNCATE + INSERT, see the
-- parser). `polled_at` is when the dashboard last read the file, not
-- when BPQ itself last updated the entry.

CREATE TABLE IF NOT EXISTS net_nodes_rtt (
    callsign        VARCHAR(16)     NOT NULL,           -- base call
    full_call       VARCHAR(20)     NOT NULL,           -- as logged (may include node prefix/SSID)
    rtt_sec         DECIMAL(6,2)    NOT NULL,
    frames          INT UNSIGNED    NOT NULL,
    polled_at       DATETIME        NOT NULL,

    PRIMARY KEY (callsign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Live snapshot of nodesrtt.txt';

CREATE TABLE IF NOT EXISTS net_mheard (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    port            TINYINT UNSIGNED NOT NULL,
    callsign        VARCHAR(16)     NOT NULL,
    heard_at        DATETIME        NOT NULL,
    bytes           INT UNSIGNED    NOT NULL,
    status          ENUM('connected','failed','none') NOT NULL,
    grid            VARCHAR(8)      NULL,
    freq_mhz        DECIMAL(8,4)    NULL,
    polled_at       DATETIME        NOT NULL,

    PRIMARY KEY (id),
    KEY idx_port (port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Live snapshot of MHSave.txt (heard stations by port)';

CREATE TABLE IF NOT EXISTS net_rtknown (
    callsign        VARCHAR(16)     NOT NULL,
    last_heard      DATETIME        NOT NULL,
    polled_at       DATETIME        NOT NULL,

    PRIMARY KEY (callsign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Live snapshot of RTKnown.txt (region derived client-side from callsign)';

-- ── Shared parser checkpoint/state ──────────────────────────────────────────
--
-- One row per sub-parser (`source`), same shape as traffic_parser_state —
-- these are "since window" re-scans (like build_forwarded_messages()),
-- not byte-offset tracked, so this is a running summary, not a resume
-- point.
CREATE TABLE IF NOT EXISTS sys_logs_parser_state (
    source          VARCHAR(16)     NOT NULL,   -- 'system' | 'rms' | 'tcp' | 'nodesrtt' | 'mheard' | 'rtknown'
    files_processed INT UNSIGNED    NOT NULL DEFAULT 0,
    files_total     INT UNSIGNED    NOT NULL DEFAULT 0,
    rows_total      INT UNSIGNED    NOT NULL DEFAULT 0,
    last_parsed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-sub-parser checkpoint/summary for bpq-system-logs.html';
