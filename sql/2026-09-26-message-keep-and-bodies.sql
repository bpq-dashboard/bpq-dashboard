-- ============================================================================
-- BPQ Dashboard — "Keep after BBS kill" folders + message-body persistence
-- 2026-09-26
--
-- Run against the EXISTING `tprfn` database (same DB as bpq_messages):
--   sudo mysql tprfn < 2026-09-26-message-keep-and-bodies.sql
--
-- Safe to re-run: ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+).
--
-- What it adds:
--   bpq_msg_folders.keep_on_kill
--       Per-folder flag. When 1, killing a message on the BBS leaves the
--       database copy in the folder (is_archived stays 1) instead of the
--       usual soft-delete. Set/cleared from the 📌 icon on a folder row in
--       bbs-messages.html (action=setFolderKeep). Ignored for "Trash".
--
--   bpq_messages.killed_on_bbs_at
--       Stamped when a kept message is killed on the BBS, so the page can
--       show it as "no longer on BBS" and never tries to re-read its body
--       from BPQ.
--
-- No data is modified. Every existing folder starts with keep_on_kill = 0,
-- i.e. behaviour is unchanged until a folder is explicitly pinned.
-- ============================================================================

ALTER TABLE bpq_msg_folders
    ADD COLUMN IF NOT EXISTS keep_on_kill TINYINT(1) NOT NULL DEFAULT 0 AFTER is_system;

ALTER TABLE bpq_messages
    ADD COLUMN IF NOT EXISTS killed_on_bbs_at DATETIME NULL AFTER saved_at;
