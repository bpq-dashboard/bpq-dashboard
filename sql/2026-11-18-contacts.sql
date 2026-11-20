-- ============================================================================
-- bpq_contacts: the BBS Mail address book (v1.5.72, 2026-11-18)
--
-- Laid out like Thunderbird's: a Personal Address Book and Collected
-- Addresses (whoever you write to from BBS Mail is added there), contacts
-- and mailing lists (members = JSON array of contact ids). address is the
-- BBS address mail is sent to (CALL or CALL@BBS.#REGION.ST.USA.NOAM).
-- The older Email Client keeps its own address book; BBS Mail can import it.
--
-- Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS bpq_contacts (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book          VARCHAR(12)   NOT NULL DEFAULT 'personal',   -- personal | collected
    kind          VARCHAR(8)    NOT NULL DEFAULT 'contact',    -- contact | list
    callsign      VARCHAR(20)   NOT NULL DEFAULT '',
    display_name  VARCHAR(100)  NOT NULL DEFAULT '',
    first_name    VARCHAR(60)   NOT NULL DEFAULT '',
    last_name     VARCHAR(60)   NOT NULL DEFAULT '',
    nickname      VARCHAR(60)   NOT NULL DEFAULT '',
    address       VARCHAR(120)  NOT NULL DEFAULT '',
    email         VARCHAR(120)  NOT NULL DEFAULT '',
    phone         VARCHAR(40)   NOT NULL DEFAULT '',
    grid          VARCHAR(10)   NOT NULL DEFAULT '',
    location      VARCHAR(120)  NOT NULL DEFAULT '',
    notes         TEXT          NULL,
    members       TEXT          NULL,
    starred       TINYINT(1)    NOT NULL DEFAULT 0,
    use_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    last_used_at  DATETIME      NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_call (callsign),
    KEY idx_book (book, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
