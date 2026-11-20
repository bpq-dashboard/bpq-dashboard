<?php
/**
 * includes/contacts-actions.php — the BBS Mail address book (v1.5.72)
 *
 * Handled inside message-storage.php (same admin login). Table bpq_contacts
 * (sql/2026-11-18-contacts.sql). Actions:
 *
 *   GET  ?action=abList                     every contact and list
 *   GET  ?action=abSenders                  who has written to the station (bpq_messages), for import
 *   POST {action: abSave, contact: {...}}   add (no id) or change (id)
 *   POST {action: abDelete, ids: [..]}
 *   POST {action: abMove, ids: [..], book}  personal | collected
 *   POST {action: abImport, book, contacts: [{callsign, display_name, address, ...}]}
 *        adds those not already in the book (same callsign); fills empty fields of those that are
 *   POST {action: abUsed, addresses: [..]}  after sending: counts use; unknown ones go to Collected
 */

const BPQ_AB_FIELDS = ['callsign' => 20, 'display_name' => 100, 'first_name' => 60, 'last_name' => 60, 'nickname' => 60,
                       'address' => 120, 'email' => 120, 'phone' => 40, 'grid' => 10, 'location' => 120];

function bpq_ab_ready(PDO $pdo): bool
{
    return (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_contacts'")->fetchColumn() > 0;
}

function bpq_ab_clean(array $c): array
{
    $out = [];
    foreach (BPQ_AB_FIELDS as $k => $len) {
        $v = trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string)($c[$k] ?? '')));
        $out[$k] = mb_substr($v, 0, $len);
    }
    $out['callsign'] = strtoupper(preg_replace('/[^A-Za-z0-9\/-]/', '', $out['callsign']));
    $out['address'] = strtoupper(preg_replace('/\s+/', '', $out['address']));
    $out['grid'] = strtoupper($out['grid']);
    $out['notes'] = mb_substr(str_replace("\r", '', (string)($c['notes'] ?? '')), 0, 4000);
    $out['starred'] = !empty($c['starred']) ? 1 : 0;
    $out['kind'] = ($c['kind'] ?? 'contact') === 'list' ? 'list' : 'contact';
    $out['book'] = ($c['book'] ?? 'personal') === 'collected' ? 'collected' : 'personal';
    $m = array_values(array_unique(array_filter(array_map('intval', (array)($c['members'] ?? [])), fn($x) => $x > 0)));
    $out['members'] = $out['kind'] === 'list' ? json_encode($m) : null;
    if ($out['kind'] === 'contact') {
        if ($out['callsign'] === '' && preg_match('/^([A-Z0-9\/-]+)@/', $out['address'], $mm)) $out['callsign'] = $mm[1];
        if ($out['callsign'] === '') throw new RuntimeException('A contact needs a callsign');
        if (!preg_match('/^[A-Z0-9]{1,3}[0-9][A-Z0-9]{0,4}(-\d{1,2})?$|^[A-Z0-9\/-]{3,12}$/', $out['callsign'])) throw new RuntimeException('That doesn\'t look like a callsign');
        if ($out['address'] === '') $out['address'] = $out['callsign'];
        if (!preg_match('/^[A-Z0-9\/-]+(@[A-Z0-9.#-]+)?$/', $out['address'])) throw new RuntimeException('BBS address: CALL or CALL@BBS.#REGION.ST.USA.NOAM');
        if ($out['email'] !== '' && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That email address isn\'t valid');
    } elseif ($out['display_name'] === '') {
        throw new RuntimeException('A list needs a name');
    }
    return $out;
}

function bpq_ab_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'book' => $r['book'], 'kind' => $r['kind'], 'callsign' => $r['callsign'],
        'display_name' => $r['display_name'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
        'nickname' => $r['nickname'], 'address' => $r['address'], 'email' => $r['email'], 'phone' => $r['phone'],
        'grid' => $r['grid'], 'location' => $r['location'], 'notes' => (string)$r['notes'],
        'members' => $r['kind'] === 'list' ? (json_decode((string)$r['members'], true) ?: []) : [],
        'starred' => (bool)$r['starred'], 'use_count' => (int)$r['use_count'],
        'last_used_at' => $r['last_used_at'], 'created_at' => $r['created_at'],
    ];
}

/** Returns true when it handled the request. */
function bpq_contacts_action(string $method, string $action, ?array $input): bool
{
    if (strpos($action, 'ab') !== 0) return false;
    header('Content-Type: application/json');
    try {
        $pdo = tprfn_db();
        if (!bpq_ab_ready($pdo)) {
            echo json_encode(['success' => false, 'error' => 'The address book table is missing: run update.sh']);
            return true;
        }
        if ($action === 'abList') {
            $rows = $pdo->query('SELECT * FROM bpq_contacts ORDER BY kind, display_name, callsign')->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'contacts' => array_map('bpq_ab_row', $rows)]);
            return true;
        }
        if ($action === 'abSenders') {
            $me = strtoupper(preg_replace('/-\d+$/', '', (string)(function_exists('getConfig') ? getConfig('station', 'callsign', '') : '')));
            $rows = tprfn_query($pdo, "SELECT UPPER(from_call) AS callsign, COUNT(*) AS n, MAX(first_seen_at) AS last
                                         FROM bpq_messages
                                        WHERE msg_type IN ('P', 'T') AND from_call <> '' AND UPPER(from_call) <> ?
                                          AND (UPPER(SUBSTRING_INDEX(to_call, '@', 1)) IN (?, 'SYSOP'))
                                        GROUP BY UPPER(from_call) ORDER BY n DESC LIMIT 500", [$me, $me]);
            echo json_encode(['success' => true, 'senders' => $rows]);
            return true;
        }
        if ($method !== 'POST') { echo json_encode(['success' => false, 'error' => 'POST needed']); return true; }

        if ($action === 'abSave') {
            $c = bpq_ab_clean((array)($input['contact'] ?? []));
            $id = (int)($input['contact']['id'] ?? 0);
            $cols = array_merge(array_keys(BPQ_AB_FIELDS), ['notes', 'starred', 'kind', 'book', 'members']);
            if ($id > 0) {
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", $cols));
                tprfn_execute($pdo, "UPDATE bpq_contacts SET $set WHERE id = ?", array_merge(array_map(fn($k) => $c[$k], $cols), [$id]));
            } else {
                $ph = implode(', ', array_fill(0, count($cols), '?'));
                tprfn_execute($pdo, 'INSERT INTO bpq_contacts (`' . implode('`, `', $cols) . "`) VALUES ($ph)", array_map(fn($k) => $c[$k], $cols));
                $id = (int)$pdo->lastInsertId();
            }
            $r = tprfn_query_one($pdo, 'SELECT * FROM bpq_contacts WHERE id = ?', [$id]);
            echo json_encode(['success' => true, 'contact' => $r ? bpq_ab_row($r) : null]);
            return true;
        }
        if ($action === 'abDelete' || $action === 'abMove') {
            $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($x) => $x > 0));
            if (!$ids) throw new RuntimeException('Nothing chosen');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'abDelete') {
                tprfn_execute($pdo, "DELETE FROM bpq_contacts WHERE id IN ($ph)", $ids);
                // take them out of lists too
                foreach ($pdo->query("SELECT id, members FROM bpq_contacts WHERE kind = 'list'")->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $m = json_decode((string)$l['members'], true) ?: [];
                    $keep = array_values(array_diff($m, $ids));
                    if (count($keep) !== count($m)) tprfn_execute($pdo, 'UPDATE bpq_contacts SET members = ? WHERE id = ?', [json_encode($keep), $l['id']]);
                }
            } else {
                $book = ($input['book'] ?? '') === 'collected' ? 'collected' : 'personal';
                tprfn_execute($pdo, "UPDATE bpq_contacts SET book = ? WHERE id IN ($ph) AND kind = 'contact'", array_merge([$book], $ids));
            }
            echo json_encode(['success' => true, 'count' => count($ids)]);
            return true;
        }
        if ($action === 'abImport') {
            $book = ($input['book'] ?? '') === 'collected' ? 'collected' : 'personal';
            $added = 0; $merged = 0; $skipped = 0;
            $find = $pdo->prepare("SELECT * FROM bpq_contacts WHERE kind = 'contact' AND callsign = ? LIMIT 1");
            foreach (array_slice((array)($input['contacts'] ?? []), 0, 5000) as $in) {
                try { $c = bpq_ab_clean(array_merge((array)$in, ['kind' => 'contact', 'book' => $book])); }
                catch (RuntimeException $e) { $skipped++; continue; }
                $find->execute([$c['callsign']]);
                $old = $find->fetch(PDO::FETCH_ASSOC);
                if ($old) {
                    $fill = [];
                    foreach (array_keys(BPQ_AB_FIELDS) as $k) {
                        if ($c[$k] === '' || $c[$k] === $old[$k]) continue;
                        if ($old[$k] === '' || ($k === 'address' && $old[$k] === $old['callsign'] && strlen($c[$k]) > strlen($old[$k]))) $fill[$k] = $c[$k];
                    }
                    if ($c['notes'] !== '' && trim((string)$old['notes']) === '') $fill['notes'] = $c['notes'];
                    if ($fill) {
                        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fill)));
                        tprfn_execute($pdo, "UPDATE bpq_contacts SET $set WHERE id = ?", array_merge(array_values($fill), [$old['id']]));
                        $merged++;
                    } else $skipped++;
                    continue;
                }
                $cols = array_merge(array_keys(BPQ_AB_FIELDS), ['notes', 'starred', 'kind', 'book']);
                tprfn_execute($pdo, 'INSERT INTO bpq_contacts (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                              array_map(fn($k) => $c[$k], $cols));
                $added++;
            }
            echo json_encode(['success' => true, 'added' => $added, 'merged' => $merged, 'skipped' => $skipped]);
            return true;
        }
        if ($action === 'abUsed') {
            $n = 0;
            $find = $pdo->prepare("SELECT id FROM bpq_contacts WHERE kind = 'contact' AND (address = ? OR callsign = ?) ORDER BY book = 'personal' DESC LIMIT 1");
            foreach (array_slice((array)($input['addresses'] ?? []), 0, 50) as $a) {
                $a = strtoupper(preg_replace('/\s+/', '', (string)$a));
                if (!preg_match('/^([A-Z0-9\/-]+)(@[A-Z0-9.#-]+)?$/', $a, $m)) continue;
                $find->execute([$a, $m[1]]);
                $id = $find->fetchColumn();
                if ($id) {
                    tprfn_execute($pdo, 'UPDATE bpq_contacts SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?', [$id]);
                } elseif (strpos($a, '@') === false || !preg_match('/^(WX|NEWS|ALL|ARRL|DX|TECH|SYSOP)$/', $m[1])) {
                    tprfn_execute($pdo, "INSERT INTO bpq_contacts (book, kind, callsign, address, use_count, last_used_at) VALUES ('collected', 'contact', ?, ?, 1, NOW())", [$m[1], $a]);
                }
                $n++;
            }
            echo json_encode(['success' => true, 'count' => $n]);
            return true;
        }
        echo json_encode(['success' => false, 'error' => 'Unknown address book action']);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Address book error']);
    }
    return true;
}
