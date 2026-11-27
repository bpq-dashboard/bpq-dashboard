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
 * 2026-10-04: action=fileMessages (POST), bulk filing in one request.
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

require_once __DIR__ . '/includes/web-login.php';
// Your stored mail, folders and address book: admin login only (2026-10-04)
bpq_require_web_login('The Email Client');

require_once __DIR__ . '/message-db.php';

header('Content-Type: application/json');

// ===========================================================================
// REQUEST HANDLER
// ===========================================================================

$method = $_SERVER['REQUEST_METHOD'];

// v1.5.68: the "Mail store" view (Dovecot over IMAP on 127.0.0.1)
require_once __DIR__ . '/includes/mail-store-actions.php';
$msAction = $method === 'GET' ? (string)($_GET['action'] ?? '') : '';
$msInput = null;
if ($method === 'POST') {
    $msRaw = file_get_contents('php://input');
    $msInput = json_decode($msRaw, true);
    $msAction = is_array($msInput) ? (string)($msInput['action'] ?? '') : '';
}
if (bpq_mail_store_action($method, $msAction, $msInput)) exit;
// v1.5.72: the BBS Mail address book
require_once __DIR__ . '/includes/contacts-actions.php';
if (bpq_contacts_action($method, $msAction, $msInput)) exit;
// v1.5.73: BBS Mail message filters
require_once __DIR__ . '/includes/mail-filters-actions.php';
if (bpq_mail_filters_action($method, $msAction, $msInput)) exit;

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        switch ($action) {
            case 'rules':
                echo json_encode(['success' => true, 'rules' => msgdb_get_setting('rules', [])]);
                break;

            // v1.5.78: BBS Mail → Tools (from the database, so every browser agrees)
            case 'bbsSeen': {
                // BBSes in the senders' full addresses (the "From: CALL@BBS.#AREA..."
                // line BPQ puts at the top of a message), last 180 days
                $pdo = tprfn_db();
                $rows = $pdo->query("SELECT msg_type, from_call, first_seen_at, LEFT(body, 600) AS head
                    FROM bpq_messages WHERE body IS NOT NULL AND first_seen_at >= NOW() - INTERVAL 180 DAY
                    ORDER BY id DESC LIMIT 20000")->fetchAll(PDO::FETCH_ASSOC);
                $seen = [];
                foreach ($rows as $r) {
                    if (!preg_match('/^\s*From:\s*([A-Z0-9\/-]+)@([A-Z0-9#.-]+)/mi', (string)$r['head'], $m)) continue;
                    $route = strtoupper($m[2]);
                    $key = explode('.', $route)[0];
                    if (!preg_match('/^[A-Z0-9-]{3,12}$/', $key)) continue;
                    $e = &$seen[$key];
                    if (!$e) $e = ['bbs' => $key, 'route' => $route, 'count' => 0, 'personal' => 0, 'bulletins' => 0,
                                   'first' => $r['first_seen_at'], 'last' => $r['first_seen_at'], 'senders' => []];
                    $e['count']++;
                    if ($r['msg_type'] === 'B') $e['bulletins']++; else $e['personal']++;
                    if (strlen($route) > strlen($e['route'])) $e['route'] = $route;
                    if ($r['first_seen_at'] < $e['first']) $e['first'] = $r['first_seen_at'];
                    if ($r['first_seen_at'] > $e['last']) $e['last'] = $r['first_seen_at'];
                    $who = strtoupper($m[1]);
                    $e['senders'][$who] = ($e['senders'][$who] ?? 0) + 1;
                    unset($e);
                }
                foreach ($seen as &$e) { arsort($e['senders']); $e['senders'] = array_slice(array_keys($e['senders']), 0, 5); }
                unset($e);
                usort($seen, fn($a, $b) => strcmp($b['last'], $a['last']));
                echo json_encode(['success' => true, 'bbses' => array_values($seen), 'scanned' => count($rows)]);
                break;
            }

            case 'mailStats': {
                $pdo = tprfn_db();
                $by = $pdo->query("SELECT msg_type, COUNT(*) AS n, SUM(first_seen_at >= NOW() - INTERVAL 1 DAY) AS d1,
                        SUM(first_seen_at >= NOW() - INTERVAL 7 DAY) AS d7, SUM(first_seen_at >= NOW() - INTERVAL 30 DAY) AS d30,
                        SUM(body IS NOT NULL AND body <> '') AS texts, MIN(first_seen_at) AS since
                    FROM bpq_messages GROUP BY msg_type")->fetchAll(PDO::FETCH_ASSOC);
                $senders = $pdo->query("SELECT CASE WHEN from_call LIKE 'RMS:%' OR from_call LIKE 'SMTP:%' THEN LOWER(SUBSTRING(from_call, LOCATE(':', from_call) + 1))
                        ELSE REGEXP_REPLACE(UPPER(SUBSTRING_INDEX(from_call, '@', 1)), '-[0-9]+$', '') END AS who, COUNT(*) AS n
                    FROM bpq_messages WHERE first_seen_at >= NOW() - INTERVAL 30 DAY GROUP BY who ORDER BY n DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                $groups = $pdo->query("SELECT UPPER(SUBSTRING_INDEX(to_call, '@', 1)) AS grp, COUNT(*) AS n
                    FROM bpq_messages WHERE msg_type = 'B' AND first_seen_at >= NOW() - INTERVAL 30 DAY GROUP BY grp ORDER BY n DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
                $kills = null;
                try {
                    $kills = $pdo->query("SELECT COUNT(*) AS n, SUM(done_at IS NULL) AS waiting, SUM(result = 'killed') AS killed FROM bpq_bbs_kills")->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) { /* table not there yet */ }
                echo json_encode(['success' => true, 'byType' => $by, 'senders' => $senders, 'groups' => $groups, 'kills' => $kills]);
                break;
            }

            case 'killList': {
                $pdo = tprfn_db();
                $rows = [];
                try {
                    $rows = $pdo->query("SELECT bbs_number, msg_type, from_call, LEFT(subject, 120) AS subject, source, queued_at, tries, done_at, result
                        FROM bpq_bbs_kills ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) { /* table not there yet: run update.sh */ }
                $other = $pdo->query("SELECT bbs_number, msg_type, from_call, LEFT(subject, 120) AS subject, killed_on_bbs_at
                    FROM bpq_messages WHERE killed_on_bbs_at IS NOT NULL ORDER BY killed_on_bbs_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'queue' => $rows, 'kept' => $other]);
                break;
            }

            case 'winlinkStatus':   // v1.5.77: Winlink mail the BBS collected or sent (Maintenance)
                $pdo = tprfn_db();
                $where = "msg_type <> 'B' AND (from_call LIKE 'RMS:%' OR from_call LIKE 'SMTP:%' OR to_call LIKE 'RMS:%' OR to_call LIKE 'SMTP:%' OR LOWER(to_call) LIKE '%@winlink.org' OR LOWER(from_call) LIKE '%@winlink.org')";
                $in = $pdo->query("SELECT REGEXP_REPLACE(UPPER(SUBSTRING_INDEX(to_call, '@', 1)), '-[0-9]+$', '') AS account,
                        COUNT(*) AS n, SUM(first_seen_at >= NOW() - INTERVAL 7 DAY) AS week, MAX(first_seen_at) AS last
                    FROM bpq_messages WHERE $where AND (from_call LIKE 'RMS:%' OR from_call LIKE 'SMTP:%' OR LOWER(from_call) LIKE '%@winlink.org')
                    GROUP BY account ORDER BY last DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
                $out = $pdo->query("SELECT COUNT(*) AS n, SUM(first_seen_at >= NOW() - INTERVAL 7 DAY) AS week, MAX(first_seen_at) AS last
                    FROM bpq_messages WHERE $where AND NOT (from_call LIKE 'RMS:%' OR from_call LIKE 'SMTP:%' OR LOWER(from_call) LIKE '%@winlink.org')")->fetch(PDO::FETCH_ASSOC);
                $acc = [];
                try {
                    $v = tprfn_query_one($pdo, "SELECT value FROM bpq_mail_prefs WHERE name = 'accounts'");
                    foreach ((array)(json_decode((string)($v['value'] ?? ''), true)['accounts'] ?? []) as $x) $acc[] = $x['call'] ?? '';
                } catch (Throwable $e) { /* table not there yet */ }
                echo json_encode(['success' => true, 'received' => $in, 'sent' => $out, 'accounts' => array_values(array_filter($acc)),
                    'now' => date('Y-m-d H:i:s')]);
                break;

            case 'readChanges':   // v1.5.68: read marks made in a mail app (bbs-mail-sync.py)
                echo json_encode(msgdb_read_changes((string)($_GET['since'] ?? '')));
                break;

            case 'unread':
                echo json_encode(['success' => true, 'unread' => msgdb_get_setting('unread', (object)[])]);
                break;

            case 'wp':   // v1.5.96: White Pages suggestions for BBS Mail's To field
                require_once __DIR__ . '/includes/wp-lookup.php';
                echo json_encode(wpLookup((string)($_GET['q'] ?? '')), JSON_INVALID_UTF8_SUBSTITUTE);
                break;

            case 'drafts':   // v1.5.96: unsent messages saved from Compose
                $d = msgdb_get_setting('drafts', []);
                $d = is_array($d) ? array_values($d) : [];
                usort($d, fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));
                echo json_encode(['success' => true, 'drafts' => $d]);
                break;

            case 'signature':   // v1.5.19 (N3MEL): signature line for new mail
                echo json_encode(['success' => true, 'signature' => msgdb_get_setting('signature',
                    ['enabled' => false, 'text' => '', 'replies' => true, 'separator' => true])]);
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

            case 'fileMessages':
                // 2026-10-04: bulk filing, [{message, folder}, ...] in one
                // request (replaces one saveMessage POST per message).
                echo json_encode(msgdb_file_messages($input['items'] ?? []));
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

            case 'markUnread':    // v1.5.68: so the mail store (Thunderbird) shows it unread too
                echo json_encode(msgdb_mark_unread($input['id'] ?? null, $input['number'] ?? null));
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

            case 'saveSignature':   // v1.5.19: plain text, at most 10 lines / 1000 characters
                $sg = $input['signature'] ?? [];
                if (!is_array($sg)) apiError('Invalid signature');
                $txt = str_replace(["\r\n", "\r"], "\n", (string)($sg['text'] ?? ''));
                $txt = preg_replace('/[^\P{C}\n\t]/u', '', $txt) ?? '';   // no control characters
                $lines = array_slice(explode("\n", rtrim($txt)), 0, 10);
                $txt = mb_substr(implode("\n", $lines), 0, 1000);
                $sig = ['enabled' => !empty($sg['enabled']) && trim($txt) !== '', 'text' => $txt,
                        'replies' => !empty($sg['replies']), 'separator' => !empty($sg['separator'])];
                msgdb_set_setting('signature', $sig);
                echo json_encode(['success' => true, 'signature' => $sig]);
                break;

            case 'saveDraft':   // v1.5.96: one draft, created or updated (at most 50, 200 000 characters each)
                $dr = $input['draft'] ?? null;
                if (!is_array($dr)) apiError('Invalid draft');
                $all = msgdb_get_setting('drafts', []);
                if (!is_array($all)) $all = [];
                $id = (string)($dr['id'] ?? '');
                if (!preg_match('/^[a-z0-9]{8,32}$/', $id)) $id = bin2hex(random_bytes(8));
                if (!isset($all[$id]) && count($all) >= 50) apiError('50 drafts saved already: delete some under Drafts');
                $clean = fn($v, $n) => mb_substr(preg_replace('/[^\P{C}\n\t]/u', '', str_replace(["\r\n", "\r"], "\n", (string)$v)) ?? '', 0, $n);
                $type = strtoupper((string)($dr['type'] ?? 'P'));
                $all[$id] = ['id' => $id, 'to' => $clean($dr['to'] ?? '', 200), 'type' => in_array($type, ['P', 'B', 'T'], true) ? $type : 'P',
                    'subject' => $clean($dr['subject'] ?? '', 200), 'body' => $clean($dr['body'] ?? '', 200000),
                    'smtp' => !empty($dr['smtp']), 'title' => $clean($dr['title'] ?? '', 80), 'updated' => time()];
                msgdb_set_setting('drafts', $all);
                echo json_encode(['success' => true, 'id' => $id, 'updated' => $all[$id]['updated'], 'count' => count($all)]);
                break;

            case 'deleteDraft':   // v1.5.96
                $all = msgdb_get_setting('drafts', []);
                if (!is_array($all)) $all = [];
                unset($all[(string)($input['id'] ?? '')]);
                msgdb_set_setting('drafts', $all ?: (object)[]);
                echo json_encode(['success' => true, 'count' => count($all)]);
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
