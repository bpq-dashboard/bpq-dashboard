-- ============================================================================
-- rf_connections: VHF packet rows (v1.5.51, 2026-11-15)
--
-- modem gains 'vhf': AX.25 connects over a sound-card / KISS packet port
-- (QtSoundModem, Direwolf, UZ7HO SoundModem, a hardware TNC), written by
-- scripts/parse-vhf-connections.py from the BBS log, LinBPQ's ConnectLog
-- (cross-checked against what was heard on the VHF port) and the scheduled
-- test connects (link_probes). Not in the headline numbers.
--
-- Safe to re-run: changes the column only while 'vhf' is missing.
-- ============================================================================

SET @t := (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'
             AND COLUMN_NAME = 'modem');
SET @sql := IF(@t IS NOT NULL AND @t NOT LIKE '%''vhf''%',
    'ALTER TABLE rf_connections MODIFY COLUMN modem ENUM(''vara'',''mercury'',''vhf'') NOT NULL DEFAULT ''vara''
        COMMENT ''vara = parse-vara-log.py, mercury = parse-mercury-log.py (v1.5.46), vhf = parse-vhf-connections.py (v1.5.51)''',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
