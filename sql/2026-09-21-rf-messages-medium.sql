-- ============================================================================
-- Adds rf_messages.medium — lets the RF Connections page's Forwarded
-- Messages panel (and TPRFN Hub Station tiles) filter to actual RF (VARA
-- HF) traffic, instead of every FBB forwarding proposal regardless of
-- which port/medium carried it.
--
-- Background: the BBS forwarding log's FA/FC lines (what rf_messages was
-- originally built from) fire identically no matter which port a
-- forwarding session used. `partner` is also stored with its SSID
-- stripped, so a real VARA HF hub partner (e.g. N3MEL-2) and the same
-- base callsign reached over NET/ROM/AX.25 (e.g. N3MEL-7) were
-- indistinguishable in this table. `medium` is populated by correlating
-- each message's timestamp+partner against BPQ's ConnectLog_YYMMDD.log
-- (which does record the medium of every connect) — see
-- build_connect_mode_lookup() / lookup_mode_for_message() in
-- scripts/parse-vara-log.py.
--
-- Run against the EXISTING `tprfn` database (adds a column to the
-- rf_messages table created by data/rf-connections-schema.sql on
-- 2026-09-16).
--
-- Deploy:
--   sudo mysql tprfn < sql/2026-09-21-rf-messages-medium.sql
--
-- Safe to re-run — every statement checks INFORMATION_SCHEMA first, same
-- IF-NOT-EXISTS-by-hand pattern used elsewhere in this codebase since
-- ALTER TABLE has no native "ADD COLUMN IF NOT EXISTS" on older
-- MySQL/MariaDB.
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rf_messages'
      AND COLUMN_NAME = 'medium'
);

SET @sql := IF(@col_exists = 0,
    'ALTER TABLE rf_messages
        ADD COLUMN medium VARCHAR(16) NULL
            COMMENT ''VARA/NETROM/AX.25/TELNET/... from ConnectLog correlation; NULL = unknown, never non-RF''
            AFTER partner',
    'SELECT ''rf_messages.medium already exists — skipping'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rf_messages'
      AND INDEX_NAME = 'idx_medium'
);

SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE rf_messages ADD KEY idx_medium (medium)',
    'SELECT ''idx_medium already exists — skipping'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Note: adding the column does NOT retroactively populate it for rows
-- already in the table (including the two N3MEL/NET-ROM rows that
-- prompted this migration) — that needs the parser to re-scan those
-- messages against ConnectLog: run parse-vara-log.py once with
-- --rescan-messages-days N (USER-MANUAL.md, "Updating to a newer version").
