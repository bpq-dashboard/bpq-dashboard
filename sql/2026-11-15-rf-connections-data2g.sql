-- ============================================================================
-- rf_connections: Data2G HF rows (v1.5.56, 2026-11-15)
--
-- modem gains 'data2g': calls through the Data2G HF modem (a VARA-style TNC
-- LinBPQ drives as a VARA port), written by scripts/parse-data2g-log.py from
-- Data2G's log. Not in the headline numbers unless config.php
-- data2g.in_headlines = true.
-- link_mode widens to 24 characters: Data2G names its waveforms like
-- w48-256l-r5/8 (13); Mercury's DATAC16 and the rows already there are kept.
--
-- Safe to re-run: each change is made only while it is still missing.
-- ============================================================================

SET @t := (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'modem');
SET @sql := IF(@t IS NOT NULL AND @t NOT LIKE '%''data2g''%',
    'ALTER TABLE rf_connections MODIFY COLUMN modem ENUM(''vara'',''mercury'',''vhf'',''data2g'') NOT NULL DEFAULT ''vara''
        COMMENT ''vara = parse-vara-log.py, mercury = parse-mercury-log.py (v1.5.46), vhf = parse-vhf-connections.py (v1.5.51), data2g = parse-data2g-log.py (v1.5.56)''',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'link_mode');
SET @sql := IF(@n IS NOT NULL AND @n < 24,
    'ALTER TABLE rf_connections MODIFY COLUMN link_mode VARCHAR(24) NULL
        COMMENT ''Mercury: mode when the link came up (DATAC16); Data2G: the data waveform used most (v1.5.56)''',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
