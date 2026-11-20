-- ============================================================================
-- bpq_bbs_kills: BBS messages to kill because they left the mail store's
-- Trash (v1.5.70, 2026-11-18)
--
-- Filled when Trash is emptied or a message there is deleted for good
-- (Email Client -> Mail store, includes/mail-store-actions.php) and when
-- bbs-mail-sync.py sees a message gone from Trash (emptied in a mail app,
-- or Trash's 30-day clean-up). scripts/fetch-message-bodies.php works
-- through it on its next BBS session (every 5 minutes): R <n> to check the
-- number still holds this message (BPQ reuses numbers), then K <n>.
--
-- result: killed | gone (no longer on the BBS) | other (number now holds
-- another message: not killed) | error (tried 3 times) | NULL = waiting
--
-- Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS bpq_bbs_kills (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id  BIGINT UNSIGNED NOT NULL,              -- bpq_messages.id
    bbs_number  VARCHAR(20)     NOT NULL,
    msg_type    CHAR(1)         NOT NULL DEFAULT 'P',
    from_call   VARCHAR(64)     NOT NULL DEFAULT '',
    subject     VARCHAR(500)    NOT NULL DEFAULT '',
    source      VARCHAR(20)     NOT NULL DEFAULT 'trash',   -- email-client | mail-app | expired
    queued_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tries       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    done_at     DATETIME        NULL,
    result      VARCHAR(20)     NULL,
    UNIQUE KEY uq_message (message_id),
    KEY idx_pending (done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
