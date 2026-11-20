-- ============================================================================
-- bpq_mail_prefs: BBS Mail settings kept on the station (v1.5.74, 2026-11-18)
--
--   retention  JSON {default_days, keep_starred, folders: {"Bulletins/WX": 30,
--              "INBOX/Club": 0, ...}} — like Thunderbird's Retention Policy.
--              default_days applies to every Bulletins/<group> folder that has
--              no setting of its own; 0 = never delete. Other folders are only
--              cleaned when given a number. scripts/bbs-mail-sync.py applies it
--              once a day; BBS Mail can run it now. Mail-store copies only:
--              never kills anything on the BBS.
--
-- Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS bpq_mail_prefs (
    name        VARCHAR(40)  NOT NULL PRIMARY KEY,
    value       TEXT         NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
