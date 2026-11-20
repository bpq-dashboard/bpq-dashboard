-- ============================================================================
-- bpq_mail_sync: which BBS messages are already in the IMAP mail store
-- (BBS mail in mail apps, v1.5.68, 2026-11-18)
--
-- scripts/bbs-mail-sync.py copies each message in bpq_messages (personal mail
-- to and from the station, and bulletins) into the Dovecot mail store once,
-- and records it here so it is never copied twice. Only used when the
-- optional mail store is set up (tools/bbs-mail-setup.sh); harmless otherwise.
--
-- Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS bpq_mail_sync (
    message_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,   -- bpq_messages.id
    mailbox       VARCHAR(120)    NOT NULL,               -- INBOX, Sent, Bulletins/WX ...
    had_text      TINYINT(1)      NOT NULL DEFAULT 1,     -- 0 = copied before its text was saved
    delivered_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_delivered (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
