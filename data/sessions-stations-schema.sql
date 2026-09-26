-- ────────────────────────────────────────────────────────────────────────────
-- BPQ Dashboard — sessions / stations tables
-- ────────────────────────────────────────────────────────────────────────────
--
-- Purpose: the core network-map dataset. Populated by network-api.php's
-- tprfn_insert_session() / tprfn_upsert_station() (in tprfn-db.php) every
-- time the syslog is (re)parsed — see parseSyslogFull() / getParsedSyslog().
--
-- History note: these tables predate this repo's schema tracking and were
-- never captured as a .sql file — they only ever existed live on the
-- production server. This file was written 2026-09-19 by reverse-engineering
-- `SHOW CREATE TABLE sessions/stations` against production, after two
-- untracked live changes:
--   1. A column-mismatch bug fix (network-api.php / tprfn-db.php were
--      writing/reading columns that didn't yet exist on `sessions`).
--   2. Addition of `freq_mhz` / `band` to `sessions`, wired into the BBS-log
--      frequency-correlation feature (populated only where hub = 'K1AJD-7';
--      NULL for all other hubs by design — see network-api.php comments).
--
-- Data philosophy: unlike rf_connections/rf_messages, these are NOT disposable
-- derived views — `sessions` is deduplicated/upserted in place (ON DUPLICATE
-- KEY UPDATE on uq_session) and is the only historical record of hub/station
-- connections; there's no raw log replay that fully reconstructs it.
--
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sessions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Session identity
    hub               VARCHAR(12)     NOT NULL,            -- e.g. 'K1AJD-7'
    station           VARCHAR(12)     NOT NULL,            -- far-end callsign-SSID
    direction         ENUM('incoming','outgoing') NOT NULL,
    is_hub_to_hub     TINYINT(1)      NOT NULL DEFAULT 0,

    -- Timing
    session_date      DATE            NOT NULL,
    connected_at      DATETIME        NULL,
    disconnected_at   DATETIME        NULL,
    duration_secs     SMALLINT UNSIGNED NULL,

    -- Quality / throughput
    avg_snr           DECIMAL(5,1)    NULL,
    bytes_tx          INT UNSIGNED    NOT NULL DEFAULT 0,
    bytes_rx          INT UNSIGNED    NOT NULL DEFAULT 0,
    max_bps           INT UNSIGNED    NOT NULL DEFAULT 0,
    max_bps_tx        INT UNSIGNED    NOT NULL DEFAULT 0,
    max_bps_rx        INT UNSIGNED    NOT NULL DEFAULT 0,
    connect_attempts  SMALLINT UNSIGNED NULL,
    vara_version      VARCHAR(16)     NULL,
    msgs_tx           SMALLINT UNSIGNED NULL,
    msgs_rx           SMALLINT UNSIGNED NULL,

    -- RF metadata (added 2026-09-19 — only populated where hub = 'K1AJD-7';
    -- NULL for every other hub, since BBS-log frequency correlation is only
    -- valid for this station's own log — see network-api.php)
    freq_mhz          DECIMAL(8,4)    NULL,
    band              VARCHAR(8)      NULL,

    -- Bookkeeping
    source            ENUM('syslog','snapshot') NOT NULL DEFAULT 'syslog',
    syslog_year       SMALLINT UNSIGNED NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- A session is uniquely identified by hub+station+direction+timestamps.
    -- Re-parsing the same syslog window must UPDATE in place, not duplicate
    -- (see tprfn_insert_session()'s ON DUPLICATE KEY UPDATE).
    UNIQUE KEY uq_session (hub, station, direction, connected_at, disconnected_at),

    KEY idx_session_date      (session_date),
    KEY idx_hub_date          (hub, session_date),
    KEY idx_station_date      (station, session_date),
    KEY idx_hub_station_date  (hub, station, session_date),
    KEY idx_hub_to_hub        (is_hub_to_hub, session_date),
    KEY idx_direction         (direction, session_date),
    KEY idx_source            (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hub/station connection sessions — network map + history views';

-- ────────────────────────────────────────────────────────────────────────────
-- stations — one row per callsign ever seen, last-known location/version
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS stations (
    callsign           VARCHAR(12)  NOT NULL,
    lat                DECIMAL(9,6) NULL,
    lon                DECIMAL(9,6) NULL,
    grid               VARCHAR(8)   NULL,
    country            VARCHAR(64)  NULL,
    last_vara_version  VARCHAR(16)  NULL,
    total_sessions     INT UNSIGNED NOT NULL DEFAULT 0,
    first_seen         DATE         NULL,
    last_seen          DATE         NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (callsign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per station callsign — last-known location/version, upserted by tprfn_upsert_station()';

-- ────────────────────────────────────────────────────────────────────────────
-- Upgrade path — if `sessions` already exists on your server WITHOUT the
-- freq_mhz/band columns (i.e. you're behind the 2026-09-19 change), run
-- just these two lines. Safe/idempotent — MariaDB skips silently if the
-- column is already present.
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE sessions ADD COLUMN IF NOT EXISTS freq_mhz DECIMAL(8,4) NULL AFTER msgs_rx;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS band     VARCHAR(8)   NULL AFTER freq_mhz;
