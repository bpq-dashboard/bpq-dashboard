-- ============================================================================
-- bpq_messages.bbs_status: BPQ's own status letters for the message, as last
-- listed by the BBS (e.g. "TF", "PY", "BN"); v1.5.93, 2026-11-23
--
-- The NTS forwarding report matched each message with its offer in the BBS
-- log, so anything forwarded before the oldest log still kept showed "Not Yet
-- Forwarded" (N3MEL: 22 such, back to June, with 9 days of logs). The
-- message-text job now records the status from every listing (LL every few
-- minutes, LP/LT/LB once a day), and the report uses it when the log has no
-- record: F = forwarded, D = delivered, Y = read, H = held, K = killed.
--
-- Safe to re-run: each column is added only while it is missing.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_messages'
             AND COLUMN_NAME = 'bbs_status');
SET @sql := IF(@c = 0,
    'ALTER TABLE bpq_messages ADD COLUMN bbs_status VARCHAR(4) NULL
        COMMENT ''BPQ status letters as last listed (TF, PY, BN...; v1.5.93)'' AFTER msg_type',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_messages'
             AND COLUMN_NAME = 'bbs_status_at');
SET @sql := IF(@c = 0,
    'ALTER TABLE bpq_messages ADD COLUMN bbs_status_at DATETIME NULL
        COMMENT ''When bbs_status was last seen in a BBS listing (v1.5.93)'' AFTER bbs_status',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
