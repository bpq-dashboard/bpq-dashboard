<?php
/**
 * message-db.php — DB-backed storage layer for BBS messages, folders,
 * address book, and rules/unread settings.
 * Version: 2.0.0
 *
 * Replaces the old JSON-file storage in message-storage.php (v1.3.0) with
 * real tables in the existing `tprfn` MariaDB database. Schema:
 * see sql/2026-07-messages-schema.sql.
 *
 * Reuses the shared PDO connection + helpers from tprfn-db.php
 * (tprfn_db(), tprfn_query(), tprfn_query_one(), tprfn_execute()) —
 * the same DB helper network-api.php already uses for the
 * sessions/stations tables. This file does NOT open its own connection.
 */

require_once __DIR__ . '/tprfn-db.php';

// ===========================================================================
// Small shared helpers (kept local so this file has no other dependency
// beyond the DB helper above and PHP core).
// ===========================================================================

function msgdb_sanitize_folder_name($name) {
    $name = preg_replace('/[^a-zA-Z0-9 _-]/', '', (string)$name);
    return substr(trim($name), 0, 50);
}

function msgdb_sanitize($value) {
    if (!is_string($value)) return '';
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return substr(trim($value), 0, 500);
}

function msgdb_validate_address($addr) {
    $addr = trim((string)$addr);
    // BBS-style address: WORD@WORD (callsign@bbs, or a bulletin distro
    // like WX@ALLUS). Keep this permissive — BPQ's own address rules vary
    // by network — just guard against empty/garbage input.
    return (bool)preg_match('/^[A-Z0-9][A-Z0-9\-\.]{0,29}@[A-Z0-9][A-Z0-9\-\.]{0,29}$/i', $addr);
}

function msgdb_gen_client_id() {
    return uniqid('msg_', true);
}

/** Normalize any parseable date string (ISO-8601 with 'T'/'Z'/offset,
 *  or anything strtotime() understands) into MySQL's DATETIME format
 *  'Y-m-d H:i:s'. MySQL rejects strings like '2026-01-19T10:56:12+00:00'
 *  or '2026-05-15T18:13:42.672Z' outright — this is what
 *  msgdb_save_message()/msgdb_save_messages() run every savedAt value
 *  through before it ever reaches a query. Falls back to "now" if the
 *  input can't be parsed at all, rather than failing the whole insert. */
function msgdb_to_mysql_datetime($value) {
    if (empty($value)) return date('Y-m-d H:i:s');
    $ts = strtotime($value);
    if ($ts === false) return date('Y-m-d H:i:s');
    return date('Y-m-d H:i:s', $ts);
}

/** Build a stable dedupe key when bbs_number is missing/non-unique (e.g.
 *  some bulletins reuse "0" or blank numbers across many messages). */
function msgdb_fallback_number($m) {
    return 'h_' . substr(sha1(($m['from'] ?? '') . '|' . ($m['to'] ?? '') . '|' .
        ($m['subject'] ?? '') . '|' . ($m['date'] ?? '') . '|' .
        substr((string)($m['body'] ?? ''), 0, 200)), 0, 16);
}

// ===========================================================================
// Folders
// ===========================================================================

function msgdb_get_folders() {
    $pdo = tprfn_db();
    $rows = tprfn_query($pdo, 'SELECT name FROM bpq_msg_folders ORDER BY is_system DESC, name ASC');
    return array_map(fn($r) => $r['name'], $rows);
}

function msgdb_create_folder($name) {
    $name = msgdb_sanitize_folder_name($name);
    if ($name === '') apiError('Invalid folder name');

    $pdo = tprfn_db();
    $existing = tprfn_query_one($pdo, 'SELECT id FROM bpq_msg_folders WHERE name = ?', [$name]);
    if ($existing) apiError('Folder already exists');

    $count = tprfn_query_one($pdo, 'SELECT COUNT(*) AS c FROM bpq_msg_folders');
    if (($count['c'] ?? 0) >= 200) apiError('Maximum folder limit reached (200)');

    tprfn_execute($pdo, 'INSERT INTO bpq_msg_folders (name, is_system) VALUES (?, 0)', [$name]);
    dashboardLog('info', 'Folder created', ['name' => $name]);

    return ['success' => true, 'message' => 'Folder created', 'folders' => msgdb_get_folders()];
}

function msgdb_delete_folder($name) {
    $name = msgdb_sanitize_folder_name($name);
    $protected = ['Inbox', 'Saved', 'Bulletins'];
    if (in_array($name, $protected)) apiError('Cannot delete default folders');

    $pdo = tprfn_db();
    $row = tprfn_query_one($pdo, 'SELECT id FROM bpq_msg_folders WHERE name = ? AND is_system = 0', [$name]);
    if (!$row) apiError('Folder not found');

    // Messages in this folder fall back to archive-only (folder_id NULL)
    // via ON DELETE SET NULL rather than being destroyed — nothing the
    // user has saved is silently deleted just because a folder went away.
    tprfn_execute($pdo, 'DELETE FROM bpq_msg_folders WHERE id = ?', [$row['id']]);
    dashboardLog('info', 'Folder deleted', ['name' => $name]);

    return ['success' => true, 'message' => 'Folder deleted', 'folders' => msgdb_get_folders()];
}

function msgdb_folder_id($name, $createIfMissing = true) {
    $pdo = tprfn_db();
    $row = tprfn_query_one($pdo, 'SELECT id FROM bpq_msg_folders WHERE name = ?', [$name]);
    if ($row) return (int)$row['id'];
    if (!$createIfMissing) return null;
    tprfn_execute($pdo, 'INSERT IGNORE INTO bpq_msg_folders (name, is_system) VALUES (?, 0)', [$name]);
    $row = tprfn_query_one($pdo, 'SELECT id FROM bpq_msg_folders WHERE name = ?', [$name]);
    return $row ? (int)$row['id'] : null;
}

// ─── "Keep after BBS kill" folders (2026-09-26) ─────────────────
//
// Per-folder opt-in: a folder with keep_on_kill = 1 keeps its database
// copy of a message when that message is killed on the live BBS,
// instead of the usual is_archived = 0 soft-delete. Lets the operator
// keep e.g. every "Bulletins - TODAY" bulletin while "Bulletins - WX"
// still disappears on Kill as before.
//
// Needs sql/2026-09-26-message-keep-and-bodies.sql. Until that's applied,
// everything below degrades to the old behaviour (no folder is ever
// "keep", kills soft-delete as before) rather than throwing — so
// deploying the PHP/HTML before the SQL can't break the Messages page.
function msgdb_keep_schema_ready() {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo = tprfn_db();
        $a = tprfn_query($pdo, "SHOW COLUMNS FROM bpq_msg_folders LIKE 'keep_on_kill'");
        $b = tprfn_query($pdo, "SHOW COLUMNS FROM bpq_messages LIKE 'killed_on_bbs_at'");
        $ready = (count($a) > 0 && count($b) > 0);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function msgdb_get_keep_folders() {
    if (!msgdb_keep_schema_ready()) return [];
    $pdo = tprfn_db();
    $rows = tprfn_query($pdo, "SELECT name FROM bpq_msg_folders
                                WHERE keep_on_kill = 1 AND LOWER(name) <> 'trash'
                                ORDER BY name ASC");
    return array_map(fn($r) => $r['name'], $rows);
}

function msgdb_set_folder_keep($name, $keep) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    if (!msgdb_keep_schema_ready()) {
        apiError('Database not migrated yet — run sql/2026-09-26-message-keep-and-bodies.sql', 409);
    }
    $name = msgdb_sanitize_folder_name($name);
    if ($name === '') apiError('Invalid folder name');
    // Trash means "delete this" — pinning it as "keep" would be a
    // contradiction, and msgdb_purge_expired_bulletins() sweeps it anyway.
    if (strtolower($name) === 'trash') apiError('Trash cannot be set to keep messages');

    $folderId = msgdb_folder_id($name, true);
    if (!$folderId) apiError('Folder not found');
    $pdo = tprfn_db();
    tprfn_execute($pdo, 'UPDATE bpq_msg_folders SET keep_on_kill = ? WHERE id = ?', [$keep ? 1 : 0, $folderId]);
    dashboardLog('info', 'Folder keep-on-kill changed', ['name' => $name, 'keep' => (bool)$keep]);
    return ['success' => true, 'name' => $name, 'keep' => (bool)$keep, 'keepFolders' => msgdb_get_keep_folders()];
}

// ===========================================================================
// Messages
// ===========================================================================

function msgdb_row_to_message($r) {
    return [
        'id'           => $r['client_id'] ?: ('db_' . $r['id']),
        'dbId'         => (int)$r['id'],
        'number'       => $r['bbs_number'],
        'date'         => $r['msg_date'],
        'type'         => $r['msg_type'],
        'size'         => (int)$r['size_bytes'],
        'from'         => $r['from_call'],
        'to'           => $r['to_call'],
        'subject'      => $r['subject'],
        'body'         => $r['body'],
        'folder'       => $r['folder_name'] ?? null,
        'savedAt'      => $r['saved_at'],
        '_read'        => (bool)$r['is_read'],
        '_ruleMatched' => $r['rule_matched'],
        '_archivedAt'  => $r['first_seen_at'],
        // Killed on the BBS but kept because its folder is keep_on_kill.
        // Absent column (pre-migration) reads as false.
        '_goneFromBbs' => !empty($r['killed_on_bbs_at'] ?? null),
    ];
}

/**
 * Get messages, optionally filtered to one folder. Mirrors old getMessages()
 * contract — only returns FILED messages (folder_id NOT NULL) by default,
 * same as the old "Saved" messages.json did. Use msgdb_get_archive() for the
 * full auto-archived history regardless of filing status.
 */
function msgdb_get_messages($folder = null) {
    $pdo = tprfn_db();
    $sql = "SELECT m.*, f.name AS folder_name FROM bpq_messages m
            JOIN bpq_msg_folders f ON f.id = m.folder_id
            WHERE m.is_archived = 1";
    $params = [];
    if ($folder !== null) {
        $sql .= ' AND f.name = ?';
        $params[] = msgdb_sanitize_folder_name($folder);
    }
    $sql .= ' ORDER BY m.saved_at DESC, m.id DESC';
    $rows = tprfn_query($pdo, $sql, $params);
    $messages = array_map('msgdb_row_to_message', $rows);

    return ['success' => true, 'messages' => $messages, 'count' => count($messages)];
}

/**
 * Save/file a message into a folder (user action: "Save to folder").
 * Upserts on (bbs_number, msg_type, from_call) so re-saving an
 * already-archived message just files it, rather than duplicating it.
 */
function msgdb_save_message($message, $folder = 'Saved') {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);

    $folder = msgdb_sanitize_folder_name($folder) ?: 'Saved';
    if (!is_array($message) || empty($message)) apiError('Invalid message data');

    $folderId = msgdb_folder_id($folder, true);

    $bbsNumber = $message['number'] ?? null;
    $type      = substr($message['type'] ?? 'P', 0, 1);
    $from      = msgdb_sanitize($message['from'] ?? 'Unknown') ?: 'Unknown';
    $dedupeKey = $bbsNumber ?: msgdb_fallback_number($message);

    $pdo = tprfn_db();
    $existing = tprfn_query_one($pdo,
        'SELECT id, client_id FROM bpq_messages WHERE bbs_number = ? AND msg_type = ? AND from_call = ?',
        [$dedupeKey, $type, $from]
    );

    $clientId = $message['id'] ?? ($existing['client_id'] ?? msgdb_gen_client_id());
    $ruleMatched = $message['_ruleMatched'] ?? null;
    $isRead = !empty($message['_read']) ? 1 : 0;
    $savedAt = msgdb_to_mysql_datetime($message['savedAt'] ?? null);

    if ($existing) {
        // body: only overwrite when the caller actually has one. Filing a
        // message whose body isn't loaded in that browser (e.g. rule-based
        // auto-filing, which runs before bodies are fetched) used to blank
        // a body already saved by msgdb_save_bodies(). (2026-09-26)
        tprfn_execute($pdo, "UPDATE bpq_messages SET
                client_id = ?, msg_date = ?, size_bytes = ?, to_call = ?,
                subject = ?, body = COALESCE(NULLIF(?, ''), body), folder_id = ?, rule_matched = ?,
                is_read = ?, saved_at = ?
            WHERE id = ?", [
            $clientId,
            $message['date'] ?? date('d-M'),
            (int)($message['size'] ?? 0),
            msgdb_sanitize($message['to'] ?? ''),
            msgdb_sanitize($message['subject'] ?? 'No Subject'),
            $message['body'] ?? '',
            $folderId,
            $ruleMatched,
            $isRead,
            $savedAt,
            $existing['id'],
        ]);
        return ['success' => true, 'message' => 'Message updated', 'id' => $clientId];
    }

    tprfn_execute($pdo, "INSERT INTO bpq_messages
            (client_id, bbs_number, msg_type, msg_date, size_bytes, from_call,
             to_call, subject, body, folder_id, rule_matched, is_read, saved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        $clientId,
        $dedupeKey,
        $type,
        $message['date'] ?? date('d-M'),
        (int)($message['size'] ?? 0),
        $from,
        msgdb_sanitize($message['to'] ?? ''),
        msgdb_sanitize($message['subject'] ?? 'No Subject'),
        $message['body'] ?? '',
        $folderId,
        $ruleMatched,
        $isRead,
        $savedAt,
    ]);

    dashboardLog('info', 'Message saved', ['id' => $clientId, 'folder' => $folder]);
    return ['success' => true, 'message' => 'Message saved', 'id' => $clientId];
}

function msgdb_save_messages($messages, $folder = 'Saved') {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    if (!is_array($messages)) apiError('Invalid messages data');

    $saved = 0;
    $errors = [];
    foreach ($messages as $msg) {
        try {
            msgdb_save_message($msg, $folder);
            $saved++;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    return ['success' => true, 'saved' => $saved, 'errors' => $errors];
}

/**
 * Auto-archive raw messages fetched straight from BPQ (Personal/NTS/
 * Bulletin), independent of user filing. This is what makes "all messages"
 * durable — every message getMail() ever pulls down gets INSERT-IGNORE'd
 * here immediately, so the archive outlives BPQ's own mailbox retention.
 * Never overwrites a message the user has already filed/edited.
 */
function msgdb_archive_messages($messages) {
    if (!is_array($messages) || empty($messages)) {
        return ['success' => true, 'archived' => 0];
    }
    $pdo = tprfn_db();
    $archived = 0;

    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $type      = substr($m['type'] ?? 'P', 0, 1);
        $from      = msgdb_sanitize($m['from'] ?? 'Unknown') ?: 'Unknown';
        $dedupeKey = $m['number'] ?? msgdb_fallback_number($m);

        // INSERT IGNORE: if this (bbs_number, msg_type, from_call) already
        // exists — whether archive-only or already filed by the user —
        // leave it completely alone. Archiving must never clobber a saved
        // message's folder/read-state/edits.
        $stmt = $pdo->prepare("INSERT IGNORE INTO bpq_messages
                (client_id, bbs_number, msg_type, msg_date, size_bytes,
                 from_call, to_call, subject, body, folder_id, is_read)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)");
        $ok = $stmt->execute([
            $m['id'] ?? msgdb_gen_client_id(),
            $dedupeKey,
            $type,
            $m['date'] ?? date('d-M'),
            (int)($m['size'] ?? 0),
            $from,
            msgdb_sanitize($m['to'] ?? ''),
            msgdb_sanitize($m['subject'] ?? 'No Subject'),
            $m['body'] ?? '',
        ]);
        if ($ok && $stmt->rowCount() > 0) $archived++;
    }

    return ['success' => true, 'archived' => $archived, 'received' => count($messages)];
}

/**
 * Persist message BODIES into bpq_messages (2026-09-26).
 *
 * Get Mail's listing only returns header lines, so archiveMessages
 * creates every row with an empty body; the body arrives later (preview
 * click or eager prefetch) and until now only ever went to that one
 * browser's localStorage. This fills it in server-side.
 *
 * Rows are matched on the same dedup key msgdb_archive_messages() used
 * to create them — (number-or-fallback, type, from) — and a body is only
 * written where the stored one is empty, so this is idempotent and can
 * never clobber anything.
 *
 * $insertMissing = true (live body fetches): if the row doesn't exist yet
 * (e.g. the archiveMessages POST failed), create it, body included.
 * $insertMissing = false (one-time localStorage backfill): update only —
 * never resurrect a row the bulletin retention purge already removed.
 */
function msgdb_save_bodies($messages, $insertMissing = true) {
    if (!is_array($messages) || empty($messages)) {
        return ['success' => true, 'saved' => 0, 'received' => 0];
    }
    $messages = array_slice($messages, 0, 200);   // hard cap per request
    $pdo = tprfn_db();
    $saved = 0;

    $upd = $pdo->prepare("UPDATE bpq_messages SET body = ?
        WHERE bbs_number = ? AND msg_type = ? AND from_call = ?
          AND (body IS NULL OR body = '')");

    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $body = (string)($m['body'] ?? '');
        if (trim($body) === '') continue;
        if (strlen($body) > 1048576) $body = substr($body, 0, 1048576);   // 1 MB sanity cap
        $m['body'] = $body;

        if ($insertMissing) {
            // INSERT IGNORE carries the body in, if the row is new.
            $r = msgdb_archive_messages([$m]);
            if (($r['archived'] ?? 0) > 0) { $saved++; continue; }
        }
        $type      = substr($m['type'] ?? 'P', 0, 1);
        $from      = msgdb_sanitize($m['from'] ?? 'Unknown') ?: 'Unknown';
        $dedupeKey = $m['number'] ?? msgdb_fallback_number($m);
        $upd->execute([$body, $dedupeKey, $type, $from]);
        if ($upd->rowCount() > 0) $saved++;
    }
    return ['success' => true, 'saved' => $saved, 'received' => count($messages)];
}

/**
 * Browse the full archive (filed or not), newest first, optionally
 * filtered by type (P/T/B), a search term, and paginated. This is the
 * new capability that lets Personal/NTS/Bulletins show history beyond
 * whatever BPQ's own mailbox currently still holds.
 */
function msgdb_get_archive($type = null, $q = null, $limit = 200, $offset = 0) {
    $pdo = tprfn_db();
    $sql = "SELECT m.*, f.name AS folder_name FROM bpq_messages m
            LEFT JOIN bpq_msg_folders f ON f.id = m.folder_id
            WHERE m.is_archived = 1";
    $params = [];

    if ($type !== null && $type !== '') {
        $sql .= ' AND m.msg_type = ?';
        $params[] = substr($type, 0, 1);
    }
    if ($q !== null && $q !== '') {
        $sql .= ' AND (m.subject LIKE ? OR m.from_call LIKE ? OR m.to_call LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    $limit  = max(1, min(1000, (int)$limit));
    $offset = max(0, (int)$offset);
    $sql .= " ORDER BY m.first_seen_at DESC, m.id DESC LIMIT $limit OFFSET $offset";

    $rows = tprfn_query($pdo, $sql, $params);
    return ['success' => true, 'messages' => array_map('msgdb_row_to_message', $rows), 'count' => count($rows)];
}

function msgdb_delete_message($id) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    $pdo = tprfn_db();
    $affected = tprfn_execute($pdo, 'DELETE FROM bpq_messages WHERE client_id = ?', [$id]);
    if ($affected === 0) apiError('Message not found');
    dashboardLog('info', 'Saved message deleted', ['id' => $id]);
    return ['success' => true, 'message' => 'Message deleted'];
}

// Soft-deletes a message from the archive by clearing is_archived (a
// column that's existed in the schema since day one for exactly this,
// but was never wired up — always 1 until now). Used when the user
// Kills a message on the live BBS: without this, the row stays fully
// "archived" in the database with nothing recording that it was
// killed, so a page reload (loadInboxesFromDatabase()) or another
// browser would pull it right back in from the archive. Matched by
// bbs_number (+ type) rather than client_id, since a killed message
// may never have been loaded into this browser's session at all —
// e.g. killed via the live BBS listing before ever being archived
// under a client_id this browser assigned.
function msgdb_kill_message($number, $type = null) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    if ($number === null || $number === '') apiError('Message number required');
    $pdo = tprfn_db();

    $typeSql = '';
    $params  = [$number];
    if ($type !== null && $type !== '') {
        $typeSql  = ' AND m.msg_type = ?';
        $params[] = substr($type, 0, 1);
    }

    // Keep-on-kill folders (2026-09-26): rows filed in a folder the user
    // pinned 📌 stay archived — just stamped killed_on_bbs_at — instead
    // of being soft-deleted. Everything else is soft-deleted as before.
    $kept = 0;
    if (msgdb_keep_schema_ready()) {
        $keepWhere = "m.bbs_number = ?$typeSql AND m.is_archived = 1
                      AND f.keep_on_kill = 1 AND LOWER(f.name) <> 'trash'";
        $row = tprfn_query_one($pdo,
            "SELECT COUNT(*) AS c FROM bpq_messages m
             JOIN bpq_msg_folders f ON f.id = m.folder_id
             WHERE $keepWhere", $params);
        $kept = (int)($row['c'] ?? 0);
        if ($kept > 0) {
            tprfn_execute($pdo,
                "UPDATE bpq_messages m JOIN bpq_msg_folders f ON f.id = m.folder_id
                 SET m.killed_on_bbs_at = COALESCE(m.killed_on_bbs_at, NOW())
                 WHERE $keepWhere", $params);
        }
        $affected = tprfn_execute($pdo,
            "UPDATE bpq_messages m LEFT JOIN bpq_msg_folders f ON f.id = m.folder_id
             SET m.is_archived = 0
             WHERE m.bbs_number = ?$typeSql
               AND (f.id IS NULL OR f.keep_on_kill = 0 OR LOWER(f.name) = 'trash')",
            $params);
    } else {
        $affected = tprfn_execute($pdo,
            "UPDATE bpq_messages m SET m.is_archived = 0 WHERE m.bbs_number = ?$typeSql",
            $params);
    }
    // Not finding a row isn't an error here — the message may have been
    // killed before it was ever archived (e.g. archiving failed or
    // hadn't run yet). Nothing to soft-delete in that case, and that's fine.
    return ['success' => true, 'updated' => $affected, 'kept' => $kept];
}

// ─── Retention policy ──────────────────────────────────────────
//
// (Revised 2026-09-26 — was: "unfiled bulletins older than 30 days".)
//
// Personal mail and NTS traffic are kept indefinitely — the msg_type
// filter is hardcoded to 'B', not configurable per-call, specifically
// so a bug elsewhere can't widen it to Personal/NTS.
//
// Why it changed: every bulletin is auto-filed into its
// "Bulletins - GROUP" folder by the page's rules sweep, and the old
// policy treated ANY filing as "keep forever" — so in practice nothing
// but Trash was ever purged, and every group folder grew without limit.
// The 📌 keep flag (bpq_msg_folders.keep_on_kill) is now the explicit
// "keep this group" signal instead.
//
// A bulletin is permanently DELETED when:
//   - it's in Trash (any age — Trash is a delete signal), OR
//   - it's older than $days (first_seen_at) AND it is either
//       * unfiled, or
//       * in a "Bulletins - GROUP" auto-folder that is NOT pinned 📌.
// KEPT indefinitely: bulletins in a pinned 📌 folder, and bulletins in
// any folder you made yourself ("My Folders" — no "Bulletins - " prefix),
// since putting one there is a deliberate choice.
//
// Before sql/2026-09-26-message-keep-and-bodies.sql is applied there's
// no way to know which folders are pinned, so it falls back to the old,
// conservative rule (unfiled only) rather than risk deleting a group
// the user meant to keep.
//
// Real DELETE, not the is_archived soft-delete used by Kill — the point
// is reclaiming space. Shared with scripts/purge-old-bulletins.php (the
// daily systemd timer) via msgdb_bulletin_purge_where(), so the button
// in Settings and the timer can never drift apart.
const MSGDB_BULLETIN_RETENTION_DAYS = 14;

// Pure SQL builder — no auth/logging calls, so the CLI purge script can
// use it without the web bootstrap. Expects aliases m (bpq_messages)
// and f (LEFT JOINed bpq_msg_folders).
function msgdb_bulletin_purge_where($days) {
    $days = max(1, min(3650, (int)$days));
    $expired = "m.first_seen_at < DATE_SUB(NOW(), INTERVAL $days DAY)";
    if (msgdb_keep_schema_ready()) {
        $scope = "(m.folder_id IS NULL
                   OR (f.name LIKE 'Bulletins - %' AND f.keep_on_kill = 0))";
    } else {
        $scope = "m.folder_id IS NULL";
    }
    return "m.msg_type = 'B'
            AND (LOWER(f.name) = 'trash' OR ($expired AND $scope))";
}

function msgdb_purge_expired_bulletins($days = MSGDB_BULLETIN_RETENTION_DAYS) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    $pdo = tprfn_db();
    $days = max(1, min(3650, (int)$days));
    $affected = tprfn_execute($pdo,
        "DELETE m FROM bpq_messages m
         LEFT JOIN bpq_msg_folders f ON f.id = m.folder_id
         WHERE " . msgdb_bulletin_purge_where($days));
    dashboardLog('info', 'Bulletin retention purge ran', ['deleted' => $affected, 'days' => $days]);
    return ['success' => true, 'deleted' => $affected, 'days' => $days];
}

// Clears a message's folder_id (returns it to "archived, not filed"
// status) WITHOUT deleting the underlying archived row — unlike
// msgdb_delete_message() above, which is a real, permanent delete.
// Used anywhere a message needs to come OUT of a folder but stay
// recoverable in Personal/NTS/Bulletins (e.g. emptying a folder).
function msgdb_unfile_message($id) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    $pdo = tprfn_db();
    $affected = tprfn_execute($pdo, 'UPDATE bpq_messages SET folder_id = NULL WHERE client_id = ?', [$id]);
    if ($affected === 0) apiError('Message not found');
    return ['success' => true, 'message' => 'Message unfiled'];
}

function msgdb_move_message($id, $newFolder) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    $newFolder = msgdb_sanitize_folder_name($newFolder);
    if ($newFolder === '') apiError('Invalid folder name');

    $folderId = msgdb_folder_id($newFolder, true);
    $pdo = tprfn_db();
    $affected = tprfn_execute($pdo, 'UPDATE bpq_messages SET folder_id = ? WHERE client_id = ?', [$folderId, $id]);
    if ($affected === 0) apiError('Message not found');
    return ['success' => true, 'message' => 'Message moved'];
}

function msgdb_mark_read($id, $number, $folder) {
    $pdo = tprfn_db();
    if ($id) {
        $affected = tprfn_execute($pdo,
            'UPDATE bpq_messages SET is_read = 1, rule_matched = NULL WHERE client_id = ?', [$id]);
        if ($affected > 0) return ['success' => true];
    }
    if ($number) {
        $folder = msgdb_sanitize_folder_name($folder ?: 'Saved');
        $affected = tprfn_execute($pdo, "UPDATE bpq_messages m
                JOIN bpq_msg_folders f ON f.id = m.folder_id
                SET m.is_read = 1, m.rule_matched = NULL
                WHERE m.bbs_number = ? AND f.name = ?", [$number, $folder]);
        if ($affected > 0) return ['success' => true];
        // Also try archive-only rows (folder_id NULL) matched by number alone
        $affected = tprfn_execute($pdo,
            'UPDATE bpq_messages SET is_read = 1, rule_matched = NULL WHERE bbs_number = ?', [$number]);
        if ($affected > 0) return ['success' => true];
    }
    return ['success' => false, 'error' => 'Message not found'];
}

function msgdb_mark_read_bulk($reads) {
    if (!is_array($reads) || empty($reads)) return ['success' => true, 'updated' => 0];
    $updated = 0;
    foreach ($reads as $r) {
        $res = msgdb_mark_read($r['id'] ?? null, $r['number'] ?? null, $r['folder'] ?? null);
        if (!empty($res['success'])) $updated++;
    }
    return ['success' => true, 'updated' => $updated];
}

// ===========================================================================
// Address book
// ===========================================================================

function msgdb_get_addresses() {
    $pdo = tprfn_db();
    $rows = tprfn_query($pdo, 'SELECT address FROM bpq_addresses ORDER BY address ASC');
    return ['success' => true, 'addresses' => array_map(fn($r) => $r['address'], $rows)];
}

function msgdb_save_address($address) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    if (!msgdb_validate_address($address)) apiError('Invalid address format');

    $address = strtoupper(trim($address));
    $pdo = tprfn_db();

    $count = tprfn_query_one($pdo, 'SELECT COUNT(*) AS c FROM bpq_addresses');
    $existing = tprfn_query_one($pdo, 'SELECT id FROM bpq_addresses WHERE address = ?', [$address]);
    if ($existing) {
        return ['success' => true, 'message' => 'Address already saved', 'addresses' => msgdb_get_addresses()['addresses']];
    }
    if (($count['c'] ?? 0) >= 50) apiError('Maximum address limit reached (50)');

    tprfn_execute($pdo, 'INSERT IGNORE INTO bpq_addresses (address) VALUES (?)', [$address]);
    return ['success' => true, 'message' => 'Address saved', 'addresses' => msgdb_get_addresses()['addresses']];
}

function msgdb_delete_address($address) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    $address = strtoupper(trim($address));
    $pdo = tprfn_db();
    tprfn_execute($pdo, 'DELETE FROM bpq_addresses WHERE address = ?', [$address]);
    return ['success' => true, 'message' => 'Address deleted', 'addresses' => msgdb_get_addresses()['addresses']];
}

// ===========================================================================
// Settings blob store (rules engine config, unread map)
// ===========================================================================

function msgdb_get_setting($key, $default) {
    $pdo = tprfn_db();
    $row = tprfn_query_one($pdo, 'SELECT setting_value FROM bpq_msg_settings WHERE setting_key = ?', [$key]);
    if (!$row) return $default;
    $decoded = json_decode($row['setting_value'], true);
    return $decoded === null && $row['setting_value'] !== 'null' ? $default : $decoded;
}

function msgdb_set_setting($key, $value) {
    $pdo = tprfn_db();
    $json = json_encode($value);
    tprfn_execute($pdo, "INSERT INTO bpq_msg_settings (setting_key, setting_value)
            VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$key, $json]);
    return true;
}

// ===========================================================================
// Stats / export / import
// ===========================================================================

function msgdb_get_stats() {
    $pdo = tprfn_db();
    $msgCount = tprfn_query_one($pdo, 'SELECT COUNT(*) AS c FROM bpq_messages WHERE folder_id IS NOT NULL');
    $archCount = tprfn_query_one($pdo, 'SELECT COUNT(*) AS c FROM bpq_messages');
    $folderCount = tprfn_query_one($pdo, 'SELECT COUNT(*) AS c FROM bpq_msg_folders');
    $addrCount = tprfn_query_one($pdo, 'SELECT COUNT(*) AS c FROM bpq_addresses');

    return ['success' => true, 'stats' => [
        'messages'        => (int)($msgCount['c'] ?? 0),   // filed/saved messages, matches old semantics
        'archivedTotal'    => (int)($archCount['c'] ?? 0),  // NEW: everything ever archived
        'folders'         => (int)($folderCount['c'] ?? 0),
        'addresses'       => (int)($addrCount['c'] ?? 0),
        'storage'         => 'database',
    ]];
}

function msgdb_export_data() {
    return [
        'success' => true,
        'export' => [
            'version'    => '2.0.0',
            'exportedAt' => date('c'),
            'folders'    => msgdb_get_folders(),
            'messages'   => msgdb_get_messages()['messages'],
            'addresses'  => msgdb_get_addresses()['addresses'],
        ],
    ];
}

function msgdb_import_data($data) {
    if (!isFeatureEnabled('bbs_write')) apiError('Write operations disabled', 403);
    if (!is_array($data) || !isset($data['export'])) apiError('Invalid import data format');

    $export = $data['export'];
    $imported = ['folders' => 0, 'messages' => 0, 'addresses' => 0];

    if (!empty($export['folders']) && is_array($export['folders'])) {
        foreach ($export['folders'] as $f) {
            msgdb_folder_id(msgdb_sanitize_folder_name($f), true);
            $imported['folders']++;
        }
    }
    if (!empty($export['messages']) && is_array($export['messages'])) {
        foreach ($export['messages'] as $m) {
            msgdb_save_message($m, $m['folder'] ?? 'Saved');
            $imported['messages']++;
        }
    }
    if (!empty($export['addresses']) && is_array($export['addresses'])) {
        foreach ($export['addresses'] as $a) {
            try { msgdb_save_address($a); $imported['addresses']++; } catch (Throwable $e) {}
        }
    }

    dashboardLog('info', 'Data imported', $imported);
    return ['success' => true, 'message' => 'Data imported', 'imported' => $imported];
}
