-- ============================================================
--  BPQDash prop_decisions table
--  Tracks every prop-scheduler.py decision run with full context
--  Run: mysql -u root bpqdash < prop-decisions-schema.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS prop_decisions (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,

    -- Run metadata
    run_at          DATETIME            NOT NULL COMMENT 'UTC time this scheduler run started',
    run_mode        ENUM('dry','apply') NOT NULL DEFAULT 'dry' COMMENT 'dry=no changes written, apply=changes applied',
    season          VARCHAR(16)         NOT NULL DEFAULT '' COMMENT 'summer/winter/spring/fall',
    sfi             SMALLINT UNSIGNED   NULL     COMMENT 'Solar Flux Index at run time',
    kp              DECIMAL(3,1)        NULL     COMMENT 'Planetary K-index at run time',
    solar_source    VARCHAR(64)         NULL     COMMENT 'Source of solar data (hamqsl/noaa/fallback)',

    -- Partner
    partner         VARCHAR(12)         NOT NULL COMMENT 'Partner callsign e.g. PARTNER1',
    location        VARCHAR(64)         NULL     COMMENT 'Partner location description',
    distance_mi     SMALLINT UNSIGNED   NULL     COMMENT 'Distance in miles',

    -- Decision
    changed         TINYINT(1)          NOT NULL DEFAULT 0 COMMENT '1=schedule was changed, 0=no change needed',
    old_script      TEXT                NULL     COMMENT 'ConnectScript before change',
    new_script      TEXT                NULL     COMMENT 'ConnectScript after change',
    historical_summary VARCHAR(255)     NULL     COMMENT 'Band stats summary used for decision',

    -- Time blocks chosen (JSON array of {start,end,band,fallback})
    blocks_json     JSON                NULL     COMMENT 'Full time block assignments as JSON',

    -- Timestamps
    created_at      DATETIME            NOT NULL DEFAULT current_timestamp(),

    PRIMARY KEY (id),
    INDEX idx_run_at    (run_at),
    INDEX idx_partner   (partner, run_at),
    INDEX idx_changed   (changed, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Propagation scheduler decision history — one row per partner per run';

-- Verify
SELECT 'prop_decisions table created OK' AS status;
SELECT COUNT(*) AS columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'bpqdash'
  AND TABLE_NAME   = 'prop_decisions';
