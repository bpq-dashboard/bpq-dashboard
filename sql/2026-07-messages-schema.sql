-- ============================================================================
-- BPQ Dashboard — Message/Folder/Address-book DB migration
-- Run against the EXISTING `tprfn` database (same DB that already holds
-- `sessions` / `stations` — see tprfn-db.php, TPRFN_DB_NAME='tprfn').
--
-- Deploy:
--   sudo mysql tprfn < 2026-07-messages-schema.sql
--
-- Safe to re-run: every statement uses IF NOT EXISTS / INSERT IGNORE.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Folders (system defaults + user-created custom folders)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bpq_msg_folders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50)  NOT NULL,
    is_system   TINYINT(1)   NOT NULL DEFAULT 0,   -- Inbox/Saved/Bulletins = 1
    keep_on_kill TINYINT(1)  NOT NULL DEFAULT 0,   -- 1 = keep DB copy when killed on BBS (added 2026-09-26)
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_folder_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO bpq_msg_folders (name, is_system) VALUES
    ('Inbox', 1), ('Saved', 1), ('Bulletins', 1);

-- ---------------------------------------------------------------------------
-- Messages — this is the single source of truth for EVERY message the
-- dashboard has ever seen from the BBS (Personal/NTS/Bulletin), whether or
-- not the user filed/saved it. `folder_id` is NULL for messages that are
-- only archived (never explicitly saved by the user); it's set when the
-- user moves/saves a message into a folder (mirrors old saveMessage()).
--
-- Dedup key: a BBS message number is only unique per-BBS/per-mailbox, not
-- globally, so uq_bbs_msg is (bbs_number, msg_type, from_call) rather than
-- bbs_number alone — this matches the old JSON behavior of one entry per
-- (number, folder) but widened so the SAME archived message is never
-- duplicated across polls regardless of folder.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bpq_messages (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id      VARCHAR(64)   NULL,          -- legacy msg_xxxxx id from JS, kept for FE compat
    bbs_number     VARCHAR(20)   NULL,           -- BPQ's message number, may be null/non-unique for bulletins
    msg_type       CHAR(1)       NOT NULL DEFAULT 'P',  -- P=personal, T=NTS/traffic, B=bulletin
    msg_date       VARCHAR(20)   NULL,           -- BPQ's own date string (d-M or similar), kept verbatim
    size_bytes     INT UNSIGNED  NOT NULL DEFAULT 0,
    from_call      VARCHAR(64)   NOT NULL DEFAULT 'Unknown',
    to_call        VARCHAR(64)   NOT NULL DEFAULT '',
    subject        VARCHAR(500)  NOT NULL DEFAULT 'No Subject',
    body           MEDIUMTEXT    NULL,
    folder_id      INT UNSIGNED  NULL,           -- NULL = archive-only, not filed by user
    rule_matched   VARCHAR(100)  NULL,
    is_read        TINYINT(1)    NOT NULL DEFAULT 0,
    is_archived    TINYINT(1)    NOT NULL DEFAULT 1,  -- always 1; kept for future soft-delete use
    first_seen_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP, -- first time dashboard ever saw it
    saved_at       DATETIME      NULL,           -- set when user explicitly files/saves it
    killed_on_bbs_at DATETIME    NULL,           -- set when a message in a keep_on_kill folder is killed on the BBS (added 2026-09-26)
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bbs_msg (bbs_number, msg_type, from_call),
    KEY idx_folder (folder_id),
    KEY idx_type (msg_type),
    KEY idx_read (is_read),
    KEY idx_from (from_call),
    CONSTRAINT fk_msg_folder FOREIGN KEY (folder_id)
        REFERENCES bpq_msg_folders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Address book (bulletin/personal reply addresses)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bpq_addresses (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    address     VARCHAR(80)  NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_address (address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO bpq_addresses (address) VALUES
    ('WX@ALLUS'), ('NEWS@WW'), ('ARRL@USA'), ('DX@WW'), ('TECH@WW');

-- ---------------------------------------------------------------------------
-- Generic settings blob store — used for the rules engine config and any
-- other client-side JSON blob that doesn't need its own relational shape.
-- Deliberately generic (key/value) rather than reverse-engineering the rules
-- JSON into columns, since the rule structure lives in bbs-messages.html
-- and can evolve without a schema migration this way.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bpq_msg_settings (
    setting_key   VARCHAR(50)  NOT NULL PRIMARY KEY,
    setting_value LONGTEXT     NOT NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
