<?php
/**
 * includes/mail-store-actions.php — the Email Client's "Mail store" view
 * (BPQ Dashboard v1.5.68).
 *
 * Handled inside message-storage.php (same admin login, same web-server
 * rule, so nothing changes in the nginx/Apache site file). Reads the BBS
 * mail store (Dovecot, tools/bbs-mail-setup.sh) live over IMAP on
 * 127.0.0.1 with the dashboard's own login:
 *
 *   GET  ?action=msStatus                       set up? reachable?
 *   GET  ?action=msFolders                      folders, message and unread counts
 *   GET  ?action=msList&folder=F&offset=0&limit=50[&q=text][&fields=from,to,subject,body]
 *        [&unseen=1][&flagged=1][&sort=date|arrival|from|to|subject|size][&dir=asc|desc]
 *   GET  ?action=msSearchAll&q=text[&fields=..]      every folder but Trash (v1.5.71)
 *   POST {action: msFlag, folder, uids, flagged} / {action: msCopy, folder, uids, to}
 *   POST {action: msMarkFolderRead, folder[, tree: true]}  (v1.5.71; tree = with its subfolders, v1.5.73)
 *   GET  ?action=msMessage&folder=F&uid=N[&peek=1]   whole text; marks it read unless peek
 *   POST {action: msSeen,  folder, uids: [..], seen: true|false}
 *   POST {action: msTrash, folder, uids: [..]}       moves to Trash
 *   POST {action: msDelete, folder, uids: [..]}      deletes for good (Trash only, v1.5.69)
 *   POST {action: msEmptyTrash}                      empties Trash (v1.5.70)
 *        Both queue the messages to be KILLED ON THE BBS (bpq_bbs_kills, v1.5.70)
 *   GET  ?action=msCheckInGet / POST {action: msCheckInSave, profile}   Quick Check-In details (v1.5.75)
 *   GET  ?action=msAccountsGet / POST {action: msAccountsSave, accounts: ['TPRFN']}   extra accounts (v1.5.77)
 *   POST {action: msDraftSave, draft: {to, type, subject, body}, uid?} / {action: msDraftDelete, uid}   Drafts (v1.5.78)
 *   POST {action: msMove, folder, uids, to}          moves to another folder (v1.5.70)
 *   POST {action: msCreateFolder, parent: 'INBOX'|'', name}   (v1.5.70)
 *   POST {action: msRenameFolder, folder, name} / {action: msDeleteFolder, folder}
 *        your own folders only; deleting one moves its messages to Trash
 *
 * Read marks set here are what Thunderbird shows, and bbs-mail-sync.py
 * carries them to the Email Client's own lists within 2 minutes.
 */

require_once __DIR__ . '/imap-client.php';

function bpq_ms_folder(string $f): string
{
    $f = trim($f);
    if ($f === '' || strlen($f) > 200 || preg_match('/[\x00-\x1f*%]/', $f)) throw new BpqImapError('Bad folder name');
    return $f;
}

/** List / search options from the query string (v1.5.71). */
function bpq_ms_query_args(string $q): array
{
    $fields = array_values(array_intersect(['from', 'to', 'subject', 'body'], explode(',', (string)($_GET['fields'] ?? ''))));
    $sort = in_array($_GET['sort'] ?? '', ['date', 'arrival', 'from', 'to', 'subject', 'size'], true) ? $_GET['sort'] : 'date';
    return ['text' => $q, 'fields' => $fields, 'unseen' => !empty($_GET['unseen']), 'flagged' => !empty($_GET['flagged']),
            'sort' => $sort, 'desc' => ($_GET['dir'] ?? 'desc') !== 'asc'];
}

function bpq_ms_trash_name(BpqImap $im): string
{
    foreach ($im->folders() as $f) if ($f['special'] === 'trash') return $f['name'];
    return 'Trash';
}

/** A folder name typed by the user: one level, no separators or wildcards. */
function bpq_ms_leaf(string $n): string
{
    $n = trim(preg_replace('/\s+/u', ' ', $n));
    if ($n === '' || mb_strlen($n) > 40) throw new BpqImapError('A folder name needs 1 to 40 characters');
    if (preg_match('/[\/\\\\*%"\x00-\x1f]/u', $n) || $n[0] === '.') throw new BpqImapError('A folder name can\'t contain / \\ * % " or start with a dot');
    if (in_array(strtoupper($n), ['INBOX', 'TRASH', 'SENT', 'BULLETINS', 'DRAFTS', 'JUNK'], true)) throw new BpqImapError($n . ' is a name the mail store uses itself');
    return $n;
}

/** Folders the user made (in the Email Client or a mail app): not INBOX, Sent, Trash or Bulletins. */
function bpq_ms_is_user_folder(string $name, array $byName): bool
{
    if (!isset($byName[$name]) || $name === 'INBOX') return false;
    if ($name === 'Bulletins' || strpos($name, 'Bulletins/') === 0) return false;
    return ($byName[$name]['special'] ?? '') === '';
}

/**
 * v1.5.70: messages leaving the mail store's Trash are killed on the BBS.
 * Queues them in bpq_bbs_kills (scripts/fetch-message-bodies.php kills them
 * on its next BBS session, after checking the number still holds the
 * same message). Returns ['queued' => n, 'not_bbs' => n, 'off' => bool].
 */
function bpq_ms_queue_kills(BpqImap $im, array $uids, string $source): array
{
    if (function_exists('isFeatureEnabled') && !isFeatureEnabled('bbs_write')) return ['queued' => 0, 'not_bbs' => count($uids), 'off' => true];
    $pdo = tprfn_db();
    $has = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_bbs_kills'")->fetchColumn();
    if (!$has) throw new BpqImapError('The database is missing bpq_bbs_kills: run update.sh, then try again (nothing was deleted)');
    $ids = [];
    foreach (array_chunk($uids, 200) as $chunk) {
        foreach ($im->summaries($chunk) as $r) if ($r['messageId'] !== '') $ids[] = $r['messageId'];
    }
    $queued = 0;
    if ($ids) {
        $ins = $pdo->prepare("INSERT IGNORE INTO bpq_bbs_kills (message_id, bbs_number, msg_type, from_call, subject, source)
                              SELECT m.id, m.bbs_number, m.msg_type, m.from_call, LEFT(m.subject, 500), ?
                                FROM bpq_mail_sync s JOIN bpq_messages m ON m.id = s.message_id
                               WHERE s.msgid = ? AND m.bbs_number IS NOT NULL AND m.bbs_number <> ''");
        $mark = $pdo->prepare("UPDATE bpq_mail_sync SET mailbox = '(killed)' WHERE msgid = ?");
        foreach (array_unique($ids) as $mid) {
            $ins->execute([$source, $mid]);
            $queued += $ins->rowCount();
            $mark->execute([$mid]);
        }
    }
    return ['queued' => $queued, 'not_bbs' => count($uids) - $queued, 'off' => false];
}

// ── v1.5.74: retention policy (like Thunderbird's), bpq_mail_prefs 'retention' ──
function bpq_ms_retention_default_days(): int
{
    $c = function_exists('getConfig') ? getConfig('bbs_mail', 'bulletin_days', 90) : 90;
    return max(0, min(3650, (int)($c ?: 90)));
}

function bpq_ms_retention_get(): array
{
    $pol = ['default_days' => bpq_ms_retention_default_days(), 'keep_starred' => true, 'folders' => [], 'saved' => false];
    try {
        $pdo = tprfn_db();
        $has = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_prefs'")->fetchColumn();
        if (!$has) return $pol + ['ready' => false];
        $v = tprfn_query_one($pdo, "SELECT value FROM bpq_mail_prefs WHERE name = 'retention'");
        $j = $v ? json_decode((string)$v['value'], true) : null;
        if (is_array($j)) {
            $pol['default_days'] = max(0, min(3650, (int)($j['default_days'] ?? $pol['default_days'])));
            $pol['keep_starred'] = !empty($j['keep_starred']);
            foreach ((array)($j['folders'] ?? []) as $k => $d) $pol['folders'][(string)$k] = max(0, min(3650, (int)$d));
            $pol['saved'] = true;
        }
        return $pol + ['ready' => true];
    } catch (Throwable $e) {
        return $pol + ['ready' => false];
    }
}

/** The Drafts folder (special-use \Drafts, else "Drafts"), made when missing unless $make is false. */
function bpq_ms_drafts_box(BpqImap $im, bool $make = true): ?string
{
    $all = $im->folders();
    foreach ($all as $f) if (($f['special'] ?? '') === 'drafts') return $f['name'];
    foreach ($all as $f) if (strcasecmp($f['name'], 'Drafts') === 0) return $f['name'];
    if (!$make) return null;
    $im->createFolder('Drafts');
    return 'Drafts';
}

/** Extra accounts (v1.5.77): [['call' => 'TPRFN', 'folder' => 'INBOX/TPRFN'], ...]. */
function bpq_ms_accounts_get(): array
{
    try {
        if (!bpq_ms_retention_get()['ready']) return [];
        $v = tprfn_query_one(tprfn_db(), "SELECT value FROM bpq_mail_prefs WHERE name = 'accounts'");
        $j = $v ? json_decode((string)$v['value'], true) : null;
        return array_values((array)($j['accounts'] ?? []));
    } catch (Throwable $e) {
        return [];
    }
}

/** Quick Check-In profile (v1.5.75): ['profile' => [...], 'saved' => bool]. */
function bpq_ms_checkin_get(): array
{
    try {
        if (!bpq_ms_retention_get()['ready']) return ['profile' => new stdClass(), 'saved' => false];
        $v = tprfn_query_one(tprfn_db(), "SELECT value FROM bpq_mail_prefs WHERE name = 'checkin'");
        $j = $v ? json_decode((string)$v['value'], true) : null;
        return is_array($j) ? ['profile' => $j, 'saved' => true] : ['profile' => new stdClass(), 'saved' => false];
    } catch (Throwable $e) {
        return ['profile' => new stdClass(), 'saved' => false];
    }
}

/** Days for a folder: its own number, else default for Bulletins/*, else null (never). */
function bpq_ms_retention_days(array $pol, string $folder): ?int
{
    if (array_key_exists($folder, $pol['folders'])) return $pol['folders'][$folder] ?: null;
    if (strpos($folder, 'Bulletins/') === 0) return $pol['default_days'] ?: null;
    return null;
}

/** Returns true when it handled the request. */
function bpq_mail_store_action(string $method, string $action, ?array $input): bool
{
    if (strpos($action, 'ms') !== 0) return false;
    header('Content-Type: application/json');
    try {
        if ($action === 'msStatus') {
            $c = BpqImap::settings();
            if (!$c) {
                echo json_encode(['success' => true, 'enabled' => false,
                    'reason' => @file_exists(BPQ_MAILSTORE_SECRET) ? 'The web server can\'t read ' . BPQ_MAILSTORE_SECRET
                                                                   : 'Not set up (tools/bbs-mail-setup.sh)']);
                return true;
            }
            $im = BpqImap::open();
            echo json_encode(['success' => true, 'enabled' => true, 'user' => $c['user']]);
            return true;
        }
        if ($action === 'msFolders') {
            $im = BpqImap::open();
            $f = $im->folders();
            // INBOX, Sent, then the rest by name (Bulletins/... together), Trash last
            $rank = fn($x) => ['inbox' => 0, 'sent' => 1, 'drafts' => 2, 'junk' => 8, 'trash' => 9][$x['special']] ?? 5;
            usort($f, fn($a, $b) => [$rank($a), strtolower($a['name'])] <=> [$rank($b), strtolower($b['name'])]);
            echo json_encode(['success' => true, 'folders' => $f]);
            return true;
        }
        if ($action === 'msList') {
            $folder = bpq_ms_folder((string)($_GET['folder'] ?? 'INBOX'));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
            $q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
            $im = BpqImap::open();
            $im->examine($folder);
            $uids = $im->query(bpq_ms_query_args($q));
            $page = array_slice($uids, $offset, $limit);
            $unseen = (int)(array_values(array_filter($im->folders(), fn($f) => $f['name'] === $folder))[0]['unseen'] ?? 0);
            echo json_encode(['success' => true, 'folder' => $folder, 'total' => count($uids), 'unseen' => $unseen,
                              'offset' => $offset, 'messages' => $im->summaries($page)]);
            return true;
        }
        if ($action === 'msSearchAll') {   // v1.5.71: every folder but Trash
            $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
            if ($q === '' && empty($_GET['unseen']) && empty($_GET['flagged'])) throw new BpqImapError('Nothing to search for');
            $im = BpqImap::open();
            $hits = []; $total = 0;
            foreach ($im->folders() as $f) {
                if (!$f['selectable'] || $f['special'] === 'trash' || !$f['messages']) continue;
                $im->examine($f['name']);
                $uids = $im->query(bpq_ms_query_args($q));
                $total += count($uids);
                foreach ($im->summaries(array_slice($uids, 0, 100)) as $r) { $r['folder'] = $f['name']; $hits[] = $r; }
            }
            usort($hits, fn($a, $b) => strcmp($b['date'], $a['date']));
            echo json_encode(['success' => true, 'total' => $total, 'messages' => array_slice($hits, 0, 300)]);
            return true;
        }
        if ($action === 'msMessage') {
            $folder = bpq_ms_folder((string)($_GET['folder'] ?? 'INBOX'));
            $uid = (int)($_GET['uid'] ?? 0);
            if ($uid <= 0) throw new BpqImapError('No message');
            $im = BpqImap::open();
            $peek = !empty($_GET['peek']);
            $im->examine($folder, !$peek);
            $m = $im->message($uid);
            if (!$m) { echo json_encode(['success' => false, 'error' => 'That message is no longer in ' . $folder]); return true; }
            if (!$peek && !$m['seen']) { $im->setSeen([$uid], true); $m['seen'] = true; }
            echo json_encode(['success' => true, 'folder' => $folder, 'message' => $m]);
            return true;
        }
        if ($action === 'msRetentionGet') {
            echo json_encode(['success' => true, 'policy' => bpq_ms_retention_get()]);
            return true;
        }
        // v1.5.78: drafts. Write saves itself into the mail store's Drafts
        // folder (the same Drafts Thunderbird shows) every so often; each
        // save replaces the last one. Opening a draft reopens Write.
        if ($method === 'POST' && $action === 'msDraftSave') {
            $d = (array)($input['draft'] ?? []);
            $clean = fn($v, $n) => mb_substr(str_replace("\0", '', (string)$v), 0, $n);
            $to = trim(preg_replace('/[\r\n]+/', ' ', $clean($d['to'] ?? '', 500)));
            $type = in_array(($d['type'] ?? 'P'), ['P', 'B', 'T'], true) ? $d['type'] : 'P';
            $subject = trim(preg_replace('/[\r\n]+/', ' ', $clean($d['subject'] ?? '', 200)));
            $body = $clean($d['body'] ?? '', 200000);
            $old = (int)($input['uid'] ?? 0);
            $im = BpqImap::open();
            $box = bpq_ms_drafts_box($im);
            $c = BpqImap::settings();
            $me = strtoupper((string)($c['user'] ?? 'SYSOP'));
            $enc = fn($t) => preg_match('/[^\x20-\x7e]/', $t) ? mb_encode_mimeheader($t, 'UTF-8', 'B', "\r\n") : $t;
            $raw = "From: $me <$me@bbs.invalid>\r\n"
                 . 'To: ' . ($to !== '' ? $enc($to) : 'undisclosed-recipients:;') . "\r\n"
                 . 'Subject: ' . $enc($subject !== '' ? $subject : '(no subject)') . "\r\n"
                 . 'Date: ' . date('r') . "\r\n"
                 . 'Message-ID: <draft.' . bin2hex(random_bytes(8)) . "@bbs.invalid>\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n"
                 . 'X-BBS-Draft-To: ' . $enc($to) . "\r\n"
                 . "X-BBS-Draft-Type: $type\r\n"
                 . "\r\n" . $body . "\r\n";
            $uid = $im->append($box, $raw, ['\\Draft', '\\Seen']);
            if ($old > 0 && $old !== $uid) {
                try { $im->examine($box, true); $im->expunge([$old]); } catch (Throwable $e) { /* already gone */ }
            }
            echo json_encode(['success' => true, 'uid' => $uid, 'folder' => $box, 'saved' => date('H:i')]);
            return true;
        }
        if ($method === 'POST' && $action === 'msDraftDelete') {
            $uid = (int)($input['uid'] ?? 0);
            if ($uid > 0) {
                $im = BpqImap::open();
                $box = bpq_ms_drafts_box($im, false);
                if ($box !== null) { $im->examine($box, true); $im->expunge([$uid]); }
            }
            echo json_encode(['success' => true]);
            return true;
        }

        // v1.5.77: extra accounts: other BBS users (e.g. a club or tactical
        // call that the BBS also collects Winlink mail for) whose mail
        // bbs-mail-sync.py copies into its own folder, INBOX/<CALL>
        if ($action === 'msAccountsGet') {
            echo json_encode(['success' => true, 'accounts' => bpq_ms_accounts_get(), 'station' => strtoupper((string)(BpqImap::settings()['user'] ?? ''))]);
            return true;
        }
        if ($method === 'POST' && $action === 'msAccountsSave') {
            if (!bpq_ms_retention_get()['ready']) throw new BpqImapError('The settings table is missing: run update.sh');
            $calls = [];
            foreach ((array)($input['accounts'] ?? []) as $c) {
                $c = strtoupper(trim((string)(is_array($c) ? ($c['call'] ?? '') : $c)));
                $c = preg_replace('/-\d+$/', '', $c);
                if ($c === '') continue;
                if (!preg_match('/^[A-Z0-9\/]{2,12}$/', $c)) throw new BpqImapError("Not a BBS user name: $c");
                if ($c === 'SYSOP' || $c === strtoupper((string)(BpqImap::settings()['user'] ?? ''))) continue;   // already the Inbox
                $calls[$c] = ['call' => $c, 'folder' => 'INBOX/' . $c];
            }
            $calls = array_slice(array_values($calls), 0, 10);
            $pdo = tprfn_db();
            tprfn_execute($pdo, "INSERT INTO bpq_mail_prefs (name, value) VALUES ('accounts', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [json_encode(['accounts' => $calls])]);
            // messages already looked at and skipped (not to or from the station)
            // are looked at again on the next copier run
            $requeued = 0;
            if ($calls) {
                $in = implode(',', array_fill(0, count($calls), '?'));
                $names = array_column($calls, 'call');
                $st = $pdo->prepare("DELETE s FROM bpq_mail_sync s JOIN bpq_messages m ON m.id = s.message_id
                    WHERE s.mailbox = '-' AND m.msg_type <> 'B' AND (
                      REGEXP_REPLACE(UPPER(SUBSTRING_INDEX(m.to_call, '@', 1)), '-[0-9]+$', '') IN ($in)
                      OR REGEXP_REPLACE(UPPER(m.from_call), '-[0-9]+$', '') IN ($in))");
                $st->execute(array_merge($names, $names));
                $requeued = $st->rowCount();
                // make the folders now, so they show before the first message
                try {
                    $im = BpqImap::open();
                    $have = array_column($im->folders(), 'name');
                    foreach ($calls as $a) if (!in_array($a['folder'], $have, true)) $im->createFolder($a['folder']);
                } catch (Throwable $e) { /* the copier creates them anyway */ }
            }
            echo json_encode(['success' => true, 'accounts' => bpq_ms_accounts_get(), 'requeued' => $requeued]);
            return true;
        }
        // v1.5.75: Quick Check-In's saved station details, kept on the
        // station so every browser and PC fills in the same ones
        if ($action === 'msCheckInGet') {
            echo json_encode(['success' => true] + bpq_ms_checkin_get());
            return true;
        }
        if ($method === 'POST' && $action === 'msCheckInSave') {
            $in = (array)($input['profile'] ?? []);
            $clean = fn($k, $n) => trim(mb_substr(preg_replace('/[\x00-\x1f]/u', ' ', (string)($in[$k] ?? '')), 0, $n));
            $p = [
                'name' => $clean('name', 40), 'callsign' => strtoupper($clean('callsign', 12)),
                'town' => $clean('town', 40), 'state' => $clean('state', 20), 'grid' => strtoupper($clean('grid', 8)),
                'netAddress' => strtoupper($clean('netAddress', 80)), 'msgType' => ($in['msgType'] ?? 'B') === 'P' ? 'P' : 'B',
                'type' => $clean('type', 20), 'band' => $clean('band', 10), 'session' => $clean('session', 20), 'station' => $clean('station', 60),
            ];
            if (!bpq_ms_retention_get()['ready']) throw new BpqImapError('The settings table is missing: run update.sh');
            tprfn_execute(tprfn_db(), "INSERT INTO bpq_mail_prefs (name, value) VALUES ('checkin', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [json_encode($p)]);
            echo json_encode(['success' => true, 'profile' => $p, 'saved' => true]);
            return true;
        }
        if ($method === 'POST' && $action === 'msRetentionSave') {
            $in = (array)($input['policy'] ?? []);
            $pol = ['default_days' => max(0, min(3650, (int)($in['default_days'] ?? 90))), 'keep_starred' => !empty($in['keep_starred']), 'folders' => []];
            foreach ((array)($in['folders'] ?? []) as $k => $d) {
                $k = (string)$k;
                if ($k === '' || strlen($k) > 200 || preg_match('/[\x00-\x1f*%]/', $k) || $d === null || $d === '') continue;
                $pol['folders'][$k] = max(0, min(3650, (int)$d));
            }
            $pdo = tprfn_db();
            if (!bpq_ms_retention_get()['ready']) throw new BpqImapError('The settings table is missing: run update.sh');
            tprfn_execute($pdo, "INSERT INTO bpq_mail_prefs (name, value) VALUES ('retention', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [json_encode($pol)]);
            echo json_encode(['success' => true, 'policy' => bpq_ms_retention_get()]);
            return true;
        }
        if ($method === 'POST' && $action === 'msRetentionRun') {
            // the same clean-up bbs-mail-sync.py does once a day, now
            $pol = bpq_ms_retention_get();
            $im = BpqImap::open();
            $done = []; $total = 0;
            foreach ($im->folders() as $f) {
                if (!$f['selectable'] || !$f['messages'] || $f['special'] === 'trash') continue;
                $days = bpq_ms_retention_days($pol, $f['name']);
                if (!$days) continue;
                $im->examine($f['name'], true);
                $crit = 'SENTBEFORE ' . date('j-M-Y', time() - $days * 86400) . ($pol['keep_starred'] ? ' UNFLAGGED' : '');
                $uids = [];
                foreach ($im->cmd("UID SEARCH $crit") as $r) {
                    if (preg_match('/^\* SEARCH\s*(.*)$/i', trim($r), $m)) foreach (preg_split('/\s+/', trim($m[1])) as $u) if (ctype_digit($u)) $uids[] = (int)$u;
                }
                if (!$uids) continue;
                foreach (array_chunk($uids, 500) as $ch) $im->expunge($ch);
                $done[$f['name']] = count($uids); $total += count($uids);
            }
            echo json_encode(['success' => true, 'deleted' => $total, 'folders' => $done]);
            return true;
        }
        if ($method === 'POST' && $action === 'msMarkFolderRead') {   // v1.5.71
            $folder = bpq_ms_folder((string)($input['folder'] ?? ''));
            $im = BpqImap::open();
            // v1.5.73: tree = the folder and every folder under it (e.g. all of Bulletins)
            $list = [];
            foreach ($im->folders() as $f) {
                if (!$f['selectable'] || $f['unseen'] < 1) continue;
                if ($f['name'] === $folder || (!empty($input['tree']) && strpos($f['name'], $folder . '/') === 0)) $list[] = $f;
            }
            $n = 0;
            foreach ($list as $f) { $im->examine($f['name'], true); $im->setSeenAll(true); $n += $f['unseen']; }
            echo json_encode(['success' => true, 'folders' => count($list), 'count' => $n]);
            return true;
        }
        if ($method === 'POST' && in_array($action, ['msSeen', 'msTrash', 'msDelete', 'msMove', 'msFlag', 'msCopy'], true)) {
            $folder = bpq_ms_folder((string)($input['folder'] ?? ''));
            $uids = array_values(array_filter(array_map('intval', (array)($input['uids'] ?? [])), fn($u) => $u > 0));
            if (!$uids || count($uids) > 500) throw new BpqImapError('No messages chosen');
            $im = BpqImap::open();
            $trash = bpq_ms_trash_name($im);
            $im->examine($folder, true);
            $extra = [];
            if ($action === 'msSeen') {
                $im->setSeen($uids, !empty($input['seen']));
            } elseif ($action === 'msFlag') {
                $im->setFlagged($uids, !empty($input['flagged']));
            } elseif ($action === 'msCopy') {
                $to = bpq_ms_folder((string)($input['to'] ?? ''));
                $names = array_column(array_filter($im->folders(), fn($f) => $f['selectable']), 'name');
                if (!in_array($to, $names, true) || $to === $folder) throw new BpqImapError('No folder ' . $to);
                $im->copy($uids, $to);
            } elseif ($action === 'msDelete') {
                // v1.5.70: leaving Trash = killed on the BBS too
                if ($folder !== $trash) throw new BpqImapError('Messages are deleted for good from Trash only');
                $extra['kills'] = bpq_ms_queue_kills($im, $uids, 'email-client');
                $im->expunge($uids);
            } elseif ($action === 'msMove') {
                $to = bpq_ms_folder((string)($input['to'] ?? ''));
                if ($to === $folder) throw new BpqImapError('Already in ' . $to);
                $names = array_column(array_filter($im->folders(), fn($f) => $f['selectable']), 'name');
                if (!in_array($to, $names, true)) throw new BpqImapError('No folder ' . $to);
                $im->move($uids, $to);
            } else {
                if ($folder === $trash) throw new BpqImapError('Already in ' . $trash);
                $im->move($uids, $trash);
            }
            echo json_encode(['success' => true, 'count' => count($uids)] + $extra);
            return true;
        }
        if ($method === 'POST' && $action === 'msEmptyTrash') {
            $im = BpqImap::open();
            $trash = bpq_ms_trash_name($im);
            $im->examine($trash, true);
            $uids = $im->sortedUids();
            $kills = $uids ? bpq_ms_queue_kills($im, $uids, 'email-client') : ['queued' => 0, 'not_bbs' => 0, 'off' => false];
            if ($uids) $im->expunge($uids);
            echo json_encode(['success' => true, 'count' => count($uids), 'kills' => $kills]);
            return true;
        }
        if ($method === 'POST' && in_array($action, ['msCreateFolder', 'msRenameFolder', 'msDeleteFolder'], true)) {
            $im = BpqImap::open();
            $folders = $im->folders();
            $byName = [];
            foreach ($folders as $f) $byName[$f['name']] = $f;
            if ($action === 'msCreateFolder') {
                $parent = (string)($input['parent'] ?? 'INBOX');
                if ($parent !== '' && $parent !== 'INBOX' && !bpq_ms_is_user_folder($parent, $byName)) throw new BpqImapError('Folders can be made in Inbox or in your own folders');
                $name = bpq_ms_leaf((string)($input['name'] ?? ''));
                $full = $parent === '' ? $name : $parent . '/' . $name;
                if (isset($byName[$full])) throw new BpqImapError('There is already a folder ' . $full);
                $im->createFolder($full);
                echo json_encode(['success' => true, 'folder' => $full]);
                return true;
            }
            $from = bpq_ms_folder((string)($input['folder'] ?? ''));
            if (!bpq_ms_is_user_folder($from, $byName)) throw new BpqImapError('Only folders you made can be renamed or deleted');
            foreach ($folders as $f) {
                if (strpos($f['name'], $from . '/') === 0) throw new BpqImapError('Move or delete the folders inside ' . $from . ' first');
            }
            if ($action === 'msRenameFolder') {
                $leaf = bpq_ms_leaf((string)($input['name'] ?? ''));
                $p = strrpos($from, '/');
                $to = ($p === false ? '' : substr($from, 0, $p + 1)) . $leaf;
                if ($to === $from) { echo json_encode(['success' => true, 'folder' => $to]); return true; }
                if (isset($byName[$to])) throw new BpqImapError('There is already a folder ' . $to);
                $im->renameFolder($from, $to);
                echo json_encode(['success' => true, 'folder' => $to]);
                return true;
            }
            // delete: its messages go to Trash first (nothing is lost until Trash is emptied)
            $moved = 0;
            if (($byName[$from]['messages'] ?? 0) > 0) {
                $im->examine($from, true);
                $uids = $im->sortedUids();
                if ($uids) { $im->move($uids, bpq_ms_trash_name($im)); $moved = count($uids); }
                $im->unselect();
            }
            $im->deleteFolder($from);
            echo json_encode(['success' => true, 'movedToTrash' => $moved]);
            return true;
        }
        echo json_encode(['success' => false, 'error' => 'Unknown mail store action']);
    } catch (BpqImapError $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Mail store error']);
    }
    return true;
}
