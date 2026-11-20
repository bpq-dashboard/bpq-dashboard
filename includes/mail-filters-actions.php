<?php
/**
 * includes/mail-filters-actions.php — BBS Mail message filters (v1.5.73)
 *
 * Handled inside message-storage.php (same admin login). Table
 * bpq_mail_filters (sql/2026-11-18-mail-filters.sql). New mail is filtered
 * by scripts/bbs-mail-sync.py as it is copied into the mail store (the
 * matching there is the same as bpq_mf_matches() here); these actions keep
 * the list and run filters on a folder now:
 *
 *   GET  ?action=mfList
 *   POST {action: mfSave, filter: {...}}       add (no id) or change
 *   POST {action: mfDelete, ids: [..]}
 *   POST {action: mfOrder, ids: [..]}          new order, first = runs first
 *   POST {action: mfRun, folder, ids?: [..]}   run (these or every manual-enabled) filter on a folder
 */

require_once __DIR__ . '/imap-client.php';

const BPQ_MF_FIELDS = ['from', 'to', 'subject', 'body', 'type', 'group'];
const BPQ_MF_OPS = ['contains', 'not_contains', 'is', 'is_not', 'begins', 'ends'];

function bpq_mf_ready(PDO $pdo): bool
{
    return (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bpq_mail_filters'")->fetchColumn() > 0;
}

function bpq_mf_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'name' => $r['name'], 'enabled' => (bool)$r['enabled'], 'position' => (int)$r['position'],
        'match_all' => (bool)$r['match_all'], 'on_new' => (bool)$r['on_new'], 'on_manual' => (bool)$r['on_manual'],
        'conditions' => json_decode((string)$r['conditions'], true) ?: [],
        'actions' => (json_decode((string)$r['actions'], true) ?: []) + ['move' => '', 'read' => false, 'star' => false, 'stop' => false],
        'hits' => (int)$r['hits'], 'last_hit_at' => $r['last_hit_at'],
    ];
}

function bpq_mf_clean(array $f): array
{
    $name = trim(mb_substr(preg_replace('/[\x00-\x1f]/u', ' ', (string)($f['name'] ?? '')), 0, 100));
    if ($name === '') throw new RuntimeException('Give the filter a name');
    $conds = [];
    foreach (array_slice((array)($f['conditions'] ?? []), 0, 20) as $c) {
        $field = (string)($c['field'] ?? ''); $op = (string)($c['op'] ?? '');
        $value = trim(mb_substr((string)($c['value'] ?? ''), 0, 200));
        if (!in_array($field, BPQ_MF_FIELDS, true) || !in_array($op, BPQ_MF_OPS, true)) continue;
        if ($value === '') continue;
        $conds[] = ['field' => $field, 'op' => $op, 'value' => $value];
    }
    if (!$conds) throw new RuntimeException('Add at least one condition with something to match');
    $a = (array)($f['actions'] ?? []);
    $move = trim((string)($a['move'] ?? ''));
    if ($move !== '' && (strlen($move) > 200 || preg_match('/[\x00-\x1f*%]/', $move))) throw new RuntimeException('Bad folder name');
    $actions = ['move' => $move, 'read' => !empty($a['read']), 'star' => !empty($a['star']), 'stop' => !empty($a['stop'])];
    if ($move === '' && !$actions['read'] && !$actions['star']) throw new RuntimeException('Choose what the filter does (move, mark read or star)');
    return [
        'name' => $name, 'enabled' => !empty($f['enabled']) ? 1 : 0, 'match_all' => ($f['match_all'] ?? true) ? 1 : 0,
        'on_new' => !empty($f['on_new']) ? 1 : 0, 'on_manual' => !empty($f['on_manual']) ? 1 : 0,
        'conditions' => json_encode($conds), 'actions' => json_encode($actions),
    ];
}

/**
 * Does message $m match filter $f? $m: from, to, subject, body (null = not
 * fetched), type (Personal | NTS | Bulletin), group. Same rules as
 * bbs-mail-sync.py's filter_matches().
 */
function bpq_mf_matches(array $f, array $m): bool
{
    $results = [];
    foreach ($f['conditions'] as $c) {
        $hay = mb_strtolower((string)($m[$c['field']] ?? ''));
        $needle = mb_strtolower($c['value']);
        if ($c['field'] === 'from' || $c['field'] === 'to' || $c['field'] === 'group') {
            $hay = strtoupper($hay); $needle = strtoupper($needle);
        }
        switch ($c['op']) {
            case 'contains':     $ok = $needle !== '' && strpos($hay, $needle) !== false; break;
            case 'not_contains': $ok = strpos($hay, $needle) === false; break;
            case 'is':           $ok = $hay === $needle || (($c['field'] === 'from' || $c['field'] === 'to') && preg_replace('/[-@].*$/', '', $hay) === $needle); break;
            case 'is_not':       $ok = !($hay === $needle || (($c['field'] === 'from' || $c['field'] === 'to') && preg_replace('/[-@].*$/', '', $hay) === $needle)); break;
            case 'begins':       $ok = $needle !== '' && strpos($hay, $needle) === 0; break;
            case 'ends':         $ok = $needle !== '' && substr($hay, -strlen($needle)) === $needle; break;
            default:             $ok = false;
        }
        $results[] = $ok;
        if ($f['match_all'] && !$ok) return false;
        if (!$f['match_all'] && $ok) return true;
    }
    return $f['match_all'] ? (bool)$results : false;
}

function bpq_mf_needs_body(array $filters): bool
{
    foreach ($filters as $f) foreach ($f['conditions'] as $c) if ($c['field'] === 'body') return true;
    return false;
}

/** Returns true when it handled the request. */
function bpq_mail_filters_action(string $method, string $action, ?array $input): bool
{
    if (strpos($action, 'mf') !== 0) return false;
    header('Content-Type: application/json');
    try {
        $pdo = tprfn_db();
        if (!bpq_mf_ready($pdo)) { echo json_encode(['success' => false, 'error' => 'The filters table is missing: run update.sh']); return true; }
        $all = fn() => array_map('bpq_mf_row', $pdo->query('SELECT * FROM bpq_mail_filters ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC));
        if ($action === 'mfList') { echo json_encode(['success' => true, 'filters' => $all()]); return true; }
        if ($method !== 'POST') { echo json_encode(['success' => false, 'error' => 'POST needed']); return true; }

        if ($action === 'mfSave') {
            $c = bpq_mf_clean((array)($input['filter'] ?? []));
            $id = (int)($input['filter']['id'] ?? 0);
            if ($id > 0) {
                tprfn_execute($pdo, 'UPDATE bpq_mail_filters SET name = ?, enabled = ?, match_all = ?, on_new = ?, on_manual = ?, conditions = ?, actions = ? WHERE id = ?',
                    [$c['name'], $c['enabled'], $c['match_all'], $c['on_new'], $c['on_manual'], $c['conditions'], $c['actions'], $id]);
            } else {
                $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM bpq_mail_filters')->fetchColumn();
                tprfn_execute($pdo, 'INSERT INTO bpq_mail_filters (name, enabled, position, match_all, on_new, on_manual, conditions, actions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$c['name'], $c['enabled'], $pos, $c['match_all'], $c['on_new'], $c['on_manual'], $c['conditions'], $c['actions']]);
                $id = (int)$pdo->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id, 'filters' => $all()]);
            return true;
        }
        if ($action === 'mfDelete') {
            $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? [])), fn($x) => $x > 0));
            if ($ids) tprfn_execute($pdo, 'DELETE FROM bpq_mail_filters WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            echo json_encode(['success' => true, 'filters' => $all()]);
            return true;
        }
        if ($action === 'mfOrder') {
            $st = $pdo->prepare('UPDATE bpq_mail_filters SET position = ? WHERE id = ?');
            foreach (array_values(array_map('intval', (array)($input['ids'] ?? []))) as $i => $id) $st->execute([$i + 1, $id]);
            echo json_encode(['success' => true, 'filters' => $all()]);
            return true;
        }
        if ($action === 'mfRun') {
            $folder = trim((string)($input['folder'] ?? 'INBOX'));
            if ($folder === '' || preg_match('/[\x00-\x1f*%]/', $folder)) throw new RuntimeException('Bad folder name');
            $want = array_map('intval', (array)($input['ids'] ?? []));
            $filters = array_values(array_filter($all(), fn($f) => $want ? in_array($f['id'], $want, true) : ($f['enabled'] && $f['on_manual'])));
            if (!$filters) throw new RuntimeException('No filters to run');
            $im = BpqImap::open();
            $names = array_column(array_filter($im->folders(), fn($f) => $f['selectable']), 'name');
            $im->examine($folder, true);
            $uids = array_slice($im->query([]), 0, 3000);
            $needBody = bpq_mf_needs_body($filters);
            $moves = []; $seen = []; $star = []; $hits = []; $matched = 0;
            foreach (array_chunk($uids, 200) as $chunk) {
                foreach ($im->summaries($chunk) as $r) {
                    $m = ['from' => $r['from'], 'to' => $r['to'], 'subject' => $r['subject'],
                          'type' => $r['bbsType'] === 'NTS traffic' ? 'NTS' : ($r['bbsType'] ?: 'Personal'),
                          'group' => $r['bbsType'] === 'Bulletin' ? preg_replace('/@.*$/', '', $r['to']) : '', 'body' => null];
                    $hit = false;
                    foreach ($filters as $f) {
                        if ($needBody && $m['body'] === null && array_filter($f['conditions'], fn($c) => $c['field'] === 'body')) {
                            $full = $im->message($r['uid']);
                            $m['body'] = $full ? $full['body'] : '';
                        }
                        if (!bpq_mf_matches($f, $m)) continue;
                        $hit = true;
                        $hits[$f['id']] = ($hits[$f['id']] ?? 0) + 1;
                        $a = $f['actions'];
                        if ($a['read'] && !$r['seen']) $seen[] = $r['uid'];
                        if ($a['star'] && !$r['flagged']) $star[] = $r['uid'];
                        if ($a['move'] !== '' && $a['move'] !== $folder) { $moves[$a['move']][] = $r['uid']; break; }
                        if ($a['stop']) break;
                    }
                    if ($hit) $matched++;
                }
            }
            if ($seen) $im->setSeen(array_values(array_unique($seen)), true);
            if ($star) $im->setFlagged(array_values(array_unique($star)), true);
            $moved = 0;
            foreach ($moves as $to => $list) {
                if (!in_array($to, $names, true)) { $im->createFolder($to); $names[] = $to; }
                $im->move($list, $to);
                $moved += count($list);
            }
            $hitSt = $pdo->prepare('UPDATE bpq_mail_filters SET hits = hits + ?, last_hit_at = NOW() WHERE id = ?');
            foreach ($hits as $id => $n) $hitSt->execute([$n, $id]);
            echo json_encode(['success' => true, 'checked' => count($uids), 'matched' => $matched, 'moved' => $moved,
                              'read' => count(array_unique($seen)), 'starred' => count(array_unique($star))]);
            return true;
        }
        echo json_encode(['success' => false, 'error' => 'Unknown filter action']);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Filter error']);
    }
    return true;
}
