-- ────────────────────────────────────────────────────────────────────────────
-- BPQ Dashboard — traffic_stats table
-- ────────────────────────────────────────────────────────────────────────────
--
-- Purpose: DB-backed replacement for bpq-traffic.html's client-side parsing
-- of BPQ32's native Traffic_YYMMDD.txt daily report files, populated by
-- scripts/parse-traffic-report.py.
--
-- Why a NEW table rather than reusing rf_connections/sessions: those are
-- both scoped to VARA HF connections only (network-api.php's connection
-- regex is hard-coded to "VARAHF"). Traffic_YYMMDD.txt is BPQ's own global
-- per-station accounting — it counts every connection type BPQ handles
-- (VARA HF, packet/AX.25, Telnet, whatever ports are configured), plus
-- rejected-message counts that no other part of this project tracks at
-- all. Reusing the VARA-only tables would have silently narrowed what
-- bpq-traffic.html's numbers mean; this table preserves BPQ's full scope.
--
-- One row per (station_date, callsign) — a direct capture of one line from
-- one day's Traffic_YYMMDD.txt file. BPQ rewrites today's file throughout
-- the day as a running cumulative total, so re-parsing today's file is
-- expected and each parse fully REPLACES that day's row for that station
-- (not additive) — see parse-traffic-report.py's upsert_traffic_row().
--
-- Data philosophy: same as rf_connections — the Traffic_*.txt files on
-- disk are the source of truth, this table is a derived, disposable cache
-- that can be dropped and re-parsed from the files at any time (as long
-- as BPQ/logrotate hasn't deleted the older files).
--
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS traffic_stats (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Identity — one row per station per day
    station_date    DATE            NOT NULL,
    callsign        VARCHAR(12)     NOT NULL,
    is_bbs          TINYINT(1)      NOT NULL DEFAULT 0,   -- '(B)' prefix in the source line

    -- Connections — plain counts, no P/B/T breakdown in the source format
    conn_in         INT UNSIGNED    NOT NULL DEFAULT 0,
    conn_out        INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Messages — Personal / Bulletin / Traffic breakdown, as BPQ reports it
    msg_rx_p        INT UNSIGNED    NOT NULL DEFAULT 0,
    msg_rx_b        INT UNSIGNED    NOT NULL DEFAULT 0,
    msg_rx_t        INT UNSIGNED    NOT NULL DEFAULT 0,
    msg_tx_p        INT UNSIGNED    NOT NULL DEFAULT 0,
    msg_tx_b        INT UNSIGNED    NOT NULL DEFAULT 0,
    msg_tx_t        INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Bytes transferred — also P/B/T-broken-down in BPQ's own report
    bytes_rx_p      INT UNSIGNED    NOT NULL DEFAULT 0,
    bytes_rx_b      INT UNSIGNED    NOT NULL DEFAULT 0,
    bytes_rx_t      INT UNSIGNED    NOT NULL DEFAULT 0,
    bytes_tx_p      INT UNSIGNED    NOT NULL DEFAULT 0,
    bytes_tx_b      INT UNSIGNED    NOT NULL DEFAULT 0,
    bytes_tx_t      INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Rejected messages — no equivalent anywhere else in this project;
    -- this table is the only place this data exists at all
    rej_in_p        INT UNSIGNED    NOT NULL DEFAULT 0,
    rej_in_b        INT UNSIGNED    NOT NULL DEFAULT 0,
    rej_in_t        INT UNSIGNED    NOT NULL DEFAULT 0,
    rej_out_p       INT UNSIGNED    NOT NULL DEFAULT 0,
    rej_out_b       INT UNSIGNED    NOT NULL DEFAULT 0,
    rej_out_t       INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Bookkeeping
    source_file     VARCHAR(32)     NOT NULL,             -- e.g. 'Traffic_260920.txt'
    parsed_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- One row per station per day — re-parsing the same file updates in
    -- place rather than accumulating duplicates
    UNIQUE KEY uq_station_date (station_date, callsign),

    KEY idx_date        (station_date),
    KEY idx_callsign     (callsign, station_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-station daily BPQ traffic accounting from Traffic_YYMMDD.txt — all connection types, includes rejections';

-- ────────────────────────────────────────────────────────────────────────────
-- Parser state — same bookkeeping pattern as rf_parser_state, one row
-- total (not per-file, since parse-traffic-report.py processes a rolling
-- window of files each run rather than an append-only single log).
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS traffic_parser_state (
    id                  TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- singleton row
    last_parsed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    files_processed     INT UNSIGNED NOT NULL DEFAULT 0,      -- last run's count
    files_total         INT UNSIGNED NOT NULL DEFAULT 0,      -- cumulative, all-time
    rows_total          INT UNSIGNED NOT NULL DEFAULT 0,      -- cumulative station-days written

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Singleton bookkeeping row for parse-traffic-report.py';
