-- ============================================================================
-- rf_connections.link_sec: seconds the link was up (v1.5.64, 2026-11-18)
--
-- duration_sec for Mercury and Data2G runs from LinBPQ's call (or the first
-- CONNECT heard) to the end, so it includes connect tries; VARA's comes from
-- VARA's "Session Time", the time connected. link_sec is the connected time
-- for Mercury (connect to end) and Data2G (its "session N s" line), so the
-- effective throughput (bytes x 8 / link_sec) is worked out the same way for
-- every modem. NULL for VARA (duration_sec is already connected time) and for
-- calls that never connected.
--
-- Safe to re-run: the column is added only while it is missing.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'link_sec');
SET @sql := IF(@c = 0,
    'ALTER TABLE rf_connections ADD COLUMN link_sec INT UNSIGNED NULL
        COMMENT ''Seconds the link was up (Mercury, Data2G; v1.5.64). VARA: duration_sec is connected time''
        AFTER duration_sec',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
