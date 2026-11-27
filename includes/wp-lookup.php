<?php
// v1.5.96: To-field suggestions from LinBPQ's White Pages, shared by the Email
// Client (bbs-messages.php?action=wp) and BBS Mail (message-storage.php?action=wp).
require_once __DIR__ . '/station-config.php';

/**
 * v1.5.96: LinBPQ's White Pages (WP.cfg in the LinBPQ folder): every station
 * the BBS has heard of, with its home BBS. Records look like
 *   R123 : { c = "K1AJD"; h = "K1AJD.#NNJ.NJ.USA.NOAM"; n = "Tony"; q = "..."; ls = 1795400000L; ... };
 * Parsed once per change of the file (cached in the temp folder).
 */
if (!function_exists('wpLookup')) {
function wpLookup(string $q): array {
    $q = strtoupper(trim($q));
    if (!preg_match('/^[A-Z0-9\/]{2,10}$/', $q)) return ['success' => true, 'entries' => [], 'note' => 'Type 2 or more letters of a callsign'];
    $dir = rtrim((string)(bpq_config_section('paths')['linbpq'] ?? ''), '/');
    $f = $dir !== '' ? "$dir/WP.cfg" : '';
    if ($f === '' || !is_readable($f)) return ['success' => true, 'entries' => [], 'note' => 'White Pages not readable' . ($f !== '' ? " ($f)" : ' (paths.linbpq not set)')];
    $cache = sys_get_temp_dir() . '/bpq-wp-' . md5($f) . '.json';
    $rows = null;
    if (is_file($cache) && filemtime($cache) >= filemtime($f)) $rows = json_decode((string)@file_get_contents($cache), true);
    if (!is_array($rows)) {
        $rows = [];
        $txt = (string)@file_get_contents($f);
        if (preg_match_all('/R\d+\s*:\s*\{([^}]*)\}/', $txt, $mm)) {
            foreach ($mm[1] as $blk) {
                $r = [];
                if (preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $blk, $kv, PREG_SET_ORDER)) foreach ($kv as $x) $r[$x[1]] = $x[2];
                if (preg_match('/\bls\s*=\s*(\d+)/', $blk, $x)) $r['ls'] = (int)$x[1];
                $c = strtoupper(trim($r['c'] ?? ''));
                $h = strtoupper(trim($r['h'] ?? ''));
                if (!preg_match('/^[A-Z0-9]{1,3}[0-9][A-Z0-9]{0,4}$/', $c) || $h === '' || $h === $c) continue;   // callsigns with a home BBS only
                $rows[] = [$c, $h, (string)($r['n'] ?? ''), (string)($r['q'] ?? ''), (int)($r['ls'] ?? 0)];
            }
        }
        $old = umask(077); @file_put_contents($cache, json_encode($rows)); umask($old);
    }
    $hits = array_values(array_filter($rows, fn($r) => strpos($r[0], $q) === 0));
    usort($hits, fn($a, $b) => strcmp($a[0], $b[0]) ?: $b[4] <=> $a[4]);
    $out = [];
    foreach (array_slice($hits, 0, 10) as [$c, $h, $n, $qth, $ls]) {
        // h is the home BBS's hierarchical address; the message goes to CALL@<home BBS>
        $out[] = ['callsign' => $c, 'fullAddress' => $c . '@' . $h, 'displayName' => trim($n . ($qth !== '' ? ', ' . $qth : '')), 'lastSeen' => $ls ? $ls * 1000 : 0];
    }
    return ['success' => true, 'entries' => $out, 'total' => count($hits)];
}
}
