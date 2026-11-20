-- ============================================================================
-- bpq_mail_sync: read marks kept in step with the mail store (v1.5.68)
--
--   msgid            the copy's Message-ID in the mail store (<BID@bbs.invalid>)
--   seen_synced      the read state both sides last agreed on (NULL = not yet)
--   read_changed_at  when a read mark made in a mail app reached
--                    bpq_messages.is_read (the Email Client picks these up)
--
-- Safe to re-run: each column / key is added only while it is missing.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_sync' AND COLUMN_NAME = 'msgid');
SET @sql := IF(@c = 0, 'ALTER TABLE bpq_mail_sync ADD COLUMN msgid VARCHAR(250) NULL AFTER mailbox', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_sync' AND COLUMN_NAME = 'seen_synced');
SET @sql := IF(@c = 0, 'ALTER TABLE bpq_mail_sync ADD COLUMN seen_synced TINYINT(1) NULL AFTER had_text', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_sync' AND COLUMN_NAME = 'read_changed_at');
SET @sql := IF(@c = 0, 'ALTER TABLE bpq_mail_sync ADD COLUMN read_changed_at DATETIME NULL AFTER seen_synced', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_sync' AND INDEX_NAME = 'idx_msgid');
SET @sql := IF(@c = 0, 'ALTER TABLE bpq_mail_sync ADD KEY idx_msgid (msgid)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_sync' AND INDEX_NAME = 'idx_read_changed');
SET @sql := IF(@c = 0, 'ALTER TABLE bpq_mail_sync ADD KEY idx_read_changed (read_changed_at)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
