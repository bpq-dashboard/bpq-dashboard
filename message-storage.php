<?php
/**
 * BPQ Dashboard Message Storage API
 * Version: 2.0.0
 *
 * Stores folders, the address book, rules/unread settings, and the full
 * message archive in the database (see sql/2026-07-messages-schema.sql
 * and message-db.php) instead of JSON files on disk.
 *
 * The action-based request/response contract is UNCHANGED from v1.3.0 so
 * bbs-messages.html does not need to change how it talks to this endpoint
 * for existing features. New: action=archiveMessages (POST) and
 * action=archive (GET) — see message-db.php for what they do.
 * 2026-09-26: action=saveBodies, action=setFolderKeep (POST); action=folders
 * now also returns keepFolders.
 */

// Set required server vars if missing (CLI/direct access)
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['PHP_SELF'])) $_SERVER['PHP_SELF'] = '/message-storage.php';

// Load bootstrap if available — skip BBS password check
if (file_exists(__DIR__ . '/includes/bootstrap.php')) {
    $SKIP_BBS_CHECK = true;
    require_once __DIR__ . '/includes/bootstrap.php';
} else {
    // Minimal fallback if bootstrap not present
    function apiError($msg, $code = 400) {
        http_response_code($code);
        die(json_encode(['success' => false, 'error' => $msg]));
    }
    function isFeatureEnabled($f) { return true; }
    function dashboardLog($level, $msg, $ctx = []) {}
}

require_once __DIR__ . '/message-db.php';

header('Content-Type: application/json');

// ===========================================================================
// REQUEST HANDLER
// ===========================================================================

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        switch ($action) {
            case 'rules':
                echo json_encode(['success' => true, 'rules' => msgdb_get_setting('rules', [])]);
                break;

            case 'unread':
                echo json_encode(['success' => true, 'unread' => msgdb_get_setting('unread', (object)[])]);
                break;

            case 'folders':
                // keepFolders (2026-09-26): names pinned "keep after BBS kill".
                // Separate key so `folders` stays a plain name list for
                // anything already parsing it.
                echo json_encode([
                    'success'     => true,
                    'folders'     => msgdb_get_folders(),
                    'keepFolders' => msgdb_get_keep_folders(),
                ]);
                break;

            case 'messages':
                echo json_encode(msgdb_get_messages($_GET['folder'] ?? null));
                break;

            case 'addresses':
                echo json_encode(msgdb_get_addresses());
                break;

            case 'archive':
                // Browse the full auto-archived history (filed or not).
                // ?type=P|T|B  ?q=search  ?limit=200  ?offset=0
                echo json_encode(msgdb_get_archive(
                    $_GET['type'] ?? null,
                    $_GET['q'] ?? null,
                    $_GET['limit'] ?? 200,
                    $_GET['offset'] ?? 0
                ));
                break;

            case 'stats':
                echo json_encode(msgdb_get_stats());
                break;

            case 'export':
                echo json_encode(msgdb_export_data());
                break;

            default:
                echo json_encode([
                    'success'   => true,
                    'folders'   => msgdb_get_folders(),
                    'messages'  => msgdb_get_messages()['messages'],
                    'addresses' => msgdb_get_addresses()['addresses'],
                ]);
        }
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['action'])) apiError('Invalid request');

        $action = $input['action'];

        switch ($action) {
            case 'createFolder':
                echo json_encode(msgdb_create_folder($input['name'] ?? ''));
                break;

            case 'deleteFolder':
                echo json_encode(msgdb_delete_folder($input['name'] ?? ''));
                break;

            case 'saveMessage':
                echo json_encode(msgdb_save_message($input['message'] ?? [], $input['folder'] ?? 'Saved'));
                break;

            case 'saveMessages':
                echo json_encode(msgdb_save_messages($input['messages'] ?? [], $input['folder'] ?? 'Saved'));
                break;

            case 'archiveMessages':
                // NEW: fire-and-forget archival of every message the client
                // just fetched from BPQ, regardless of folder. Called by
                // bbs-messages.html right after a successful getMail().
                echo json_encode(msgdb_archive_messages($input['messages'] ?? []));
                break;

            case 'deleteMessage':
                echo json_encode(msgdb_delete_message($input['id'] ?? ''));
                break;

            case 'unfileMessage':
                echo json_encode(msgdb_unfile_message($input['id'] ?? ''));
                break;

            case 'saveBodies':
                // Fill in message bodies server-side once fetched (see
                // msgdb_save_bodies). insertMissing=false for backfill.
                echo json_encode(msgdb_save_bodies(
                    $input['messages'] ?? [],
                    !isset($input['insertMissing']) || !empty($input['insertMissing'])
                ));
                break;

            case 'setFolderKeep':
                echo json_encode(msgdb_set_folder_keep($input['name'] ?? '', !empty($input['keep'])));
                break;

            case 'killMessage':
                echo json_encode(msgdb_kill_message($input['number'] ?? null, $input['type'] ?? null));
                break;

            case 'purgeExpiredBulletins':
                echo json_encode(msgdb_purge_expired_bulletins($input['days'] ?? 30));
                break;

            case 'moveMessage':
                echo json_encode(msgdb_move_message($input['id'] ?? '', $input['folder'] ?? ''));
                break;

            case 'saveAddress':
                echo json_encode(msgdb_save_address($input['address'] ?? ''));
                break;

            case 'deleteAddress':
                echo json_encode(msgdb_delete_address($input['address'] ?? ''));
                break;

            case 'import':
                echo json_encode(msgdb_import_data($input));
                break;

            case 'markRead':
                echo json_encode(msgdb_mark_read(
                    $input['id'] ?? null,
                    $input['number'] ?? null,
                    $input['folder'] ?? 'Saved'
                ));
                break;

            case 'markReadBulk':
                echo json_encode(msgdb_mark_read_bulk($input['reads'] ?? []));
                break;

            case 'saveRules':
                $rulesData = $input['rules'] ?? [];
                if (!is_array($rulesData)) apiError('Invalid rules data');
                msgdb_set_setting('rules', $rulesData);
                echo json_encode(['success' => true, 'count' => count($rulesData)]);
                break;

            case 'saveUnread':
                msgdb_set_setting('unread', $input['unread'] ?? []);
                echo json_encode(['success' => true]);
                break;

            default:
                apiError('Unknown action');
        }
        exit;
    }

    apiError('Method not allowed', 405);

} catch (PDOException $e) {
    error_log('message-storage.php DB error: ' . $e->getMessage());
    apiError('Database error — see server logs', 500);
} catch (Throwable $e) {
    error_log('message-storage.php error: ' . $e->getMessage());
    apiError('Server error: ' . $e->getMessage(), 500);
}
