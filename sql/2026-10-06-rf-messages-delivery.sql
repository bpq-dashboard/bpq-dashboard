-- ============================================================================
-- rf_messages: per-partner rows + delivery tracking (2026-10-06)
--
-- 1. One row per (bid, direction, partner) instead of per (bid, direction).
--    A bulletin forwarded to EI2GYB *and* N3HYM is two deliveries; the old
--    key kept only whichever partner was offered it first, so per-partner
--    "forwarded" counts were low for anything sent to several hubs.
--
-- 2. accept_status — the partner's FS reply to each offer:
--      'A' accepted   (FS '+' / 'Y' / '!offset')
--      'R' rejected   (FS '-' / 'N' / 'R' — usually "already have it")
--      'D' deferred   (FS '=' / 'L' / 'H' — offer again later)
--      'E' other/error code
--      NULL = no reply seen (session dropped, or row from before this change)
--
-- 3. delivered_time — when the transfer finished (UTC):
--      incoming: the "|CALL Uncompressing Message ..." line for that message
--      outgoing: the partner's next protocol line after accepting (it only
--                takes its turn once it has received everything)
--    NULL for anything not accepted, or accepted but never confirmed.
--
-- Populated by scripts/parse-vara-log.py (build_forwarded_messages()).
--
-- Deploy:
--   sudo mysql <database> < sql/2026-10-06-rf-messages-delivery.sql
-- then re-scan so existing rows get split per partner and tagged:
--   sudo -u www-data python3 <web-root>/scripts/parse-vara-log.py \
--     --bbs-log-dir <web-root>/logs --my-callsign <YOURCALL> --rescan-messages-days 90
--   (use the same user and options as your parse-vara-log.py cron line)
--
-- Safe to re-run — every step checks INFORMATION_SCHEMA first.
-- ============================================================================

-- accept_status ---------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_messages'
             AND COLUMN_NAME = 'accept_status');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_messages ADD COLUMN accept_status CHAR(1) NULL
        COMMENT ''A=accepted R=rejected D=deferred E=other; NULL=no FS reply seen''
        AFTER medium',
    'SELECT ''accept_status already exists — skipping'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- delivered_time --------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_messages'
             AND COLUMN_NAME = 'delivered_time');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_messages ADD COLUMN delivered_time DATETIME NULL
        COMMENT ''UTC time the transfer finished; NULL if not accepted/unconfirmed''
        AFTER accept_status',
    'SELECT ''delivered_time already exists — skipping'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- New unique key (bid, direction, partner) — added BEFORE dropping the old
-- one; existing rows are already unique on the narrower key, so this can't
-- fail on duplicates.
SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_messages'
             AND INDEX_NAME = 'uniq_message_partner');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_messages ADD UNIQUE KEY uniq_message_partner (bid, direction, partner)',
    'SELECT ''uniq_message_partner already exists — skipping'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop the old (bid, direction) key, which would block a second partner row.
SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_messages'
             AND INDEX_NAME = 'uniq_message');
SET @sql := IF(@x > 0,
    'ALTER TABLE rf_messages DROP INDEX uniq_message',
    'SELECT ''uniq_message already dropped — skipping'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for the page's "delivered rows for the last N days" query.
SET @x := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_messages'
             AND INDEX_NAME = 'idx_status_time');
SET @sql := IF(@x = 0,
    'ALTER TABLE rf_messages ADD KEY idx_status_time (accept_status, msg_time)',
    'SELECT ''idx_status_time already exists — skipping'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'rf_messages delivery migration complete' AS note;
