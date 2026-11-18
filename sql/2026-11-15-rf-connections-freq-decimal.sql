-- ============================================================================
-- rf_connections.freq_khz keeps tenths of a kHz (v1.5.54, 2026-11-15)
--
-- It was a whole number of kHz, so a dial frequency of 7.101300 MHz was
-- stored as 7101 and shown as 7.1010 MHz, and an incoming "Freq 7102400"
-- (Hz) as 7.1020. DECIMAL(9,2) keeps 7101.30 / 7102.40.
-- Rows written before keep their rounded value until re-read
-- (parse-mercury-log.py --reset; parse-vara-log.py --reset).
--
-- Safe to re-run: changes the column only while it is still an integer.
-- ============================================================================

SET @t := (SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'freq_khz');
SET @sql := IF(@t IN ('int', 'mediumint', 'smallint', 'bigint'),
    'ALTER TABLE rf_connections MODIFY COLUMN freq_khz DECIMAL(9,2) UNSIGNED NULL
        COMMENT ''kHz with decimals, e.g. 7101.30 for 7.101300 MHz (v1.5.54)''',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
