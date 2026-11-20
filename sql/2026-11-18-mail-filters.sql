-- ============================================================================
-- bpq_mail_filters: BBS Mail message filters (v1.5.73, 2026-11-18)
--
-- Like Thunderbird's Message Filters. scripts/bbs-mail-sync.py applies the
-- enabled ones (in position order) as it copies new BBS mail into the mail
-- store, so a message lands straight in the folder you chose; BBS Mail can
-- also run them on a folder ("Run Now").
--   conditions  JSON [{field, op, value}]  field: from | to | subject | body |
--               type (Personal, NTS, Bulletin) | group (bulletin group, e.g. WX)
--               op: contains | not_contains | is | is_not | begins | ends
--   actions     JSON {move: "<folder>", read: bool, star: bool, stop: bool}
--
-- Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS bpq_mail_filters (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100)  NOT NULL DEFAULT '',
    enabled      TINYINT(1)    NOT NULL DEFAULT 1,
    position     INT           NOT NULL DEFAULT 0,
    match_all    TINYINT(1)    NOT NULL DEFAULT 1,
    on_new       TINYINT(1)    NOT NULL DEFAULT 1,
    on_manual    TINYINT(1)    NOT NULL DEFAULT 1,
    conditions   TEXT          NULL,
    actions      TEXT          NULL,
    hits         INT UNSIGNED  NOT NULL DEFAULT 0,
    last_hit_at  DATETIME      NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pos (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
