-- ============================================================================
-- rf_connections: which modem a session used (v1.5.46, 2026-11-14)
--
-- modem     'vara' (every row until now, written by parse-vara-log.py) or
--           'mercury' (written by scripts/parse-mercury-log.py from Mercury's
--           log: calls, links and how they ended).
-- link_mode Mercury's mode when the link came up (e.g. DATAC16); NULL for
--           VARA.
-- end_reason Mercury: how the link ended, as Mercury logs it (peer_ack,
--           rx_disconnect, timeout ...); the page words it plainly.
--
-- RF Connections shows Mercury rows in Recent connections, the partner
-- strips, the 7-day history and the activity chart; the headline numbers
-- stay VARA-only unless config.php mercury.in_headlines = true.
--
-- Safe to re-run — every step checks INFORMATION_SCHEMA first (works on
-- MariaDB and MySQL).
-- ============================================================================

SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'modem');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_connections ADD COLUMN modem ENUM(''vara'',''mercury'') NOT NULL DEFAULT ''vara''
        COMMENT ''vara = parse-vara-log.py, mercury = parse-mercury-log.py (v1.5.46)'' AFTER direction',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'link_mode');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_connections ADD COLUMN link_mode VARCHAR(12) NULL
        COMMENT ''Mercury: mode when the link came up, e.g. DATAC16'' AFTER bandwidth',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'end_reason');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_connections ADD COLUMN end_reason VARCHAR(24) NULL
        COMMENT ''Mercury: how the link ended (peer_ack, rx_disconnect, timeout ...)'' AFTER failure_reason',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND INDEX_NAME = 'idx_modem');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_connections ADD KEY idx_modem (modem, session_start)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
