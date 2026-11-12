<?php
/**
 * scripts/poll-mheard.php — LinBPQ's live MHeard lists (v1.5.25)
 * BPQ Dashboard v1.5.42
 *
 * LinBPQ writes MHSave.txt only when it stops, so System Logs' MHeard card
 * showed the list as of the last restart. This logs in to the node (same
 * sequence as bpq-nodes-api.php: bbs.user / bbs.pass from config.php, NODE
 * if the login lands in the BBS), runs `MHU <port>` for every PORT in
 * bpq32.cfg and prints JSON:
 *
 *   {"ok":true,"polled_at":"2026-11-08 12:40:00",
 *    "ports":{"4":[{"call":"KP2L-10","heard_at":"2026-11-08 11:07:33"}]}}
 *
 * Times are UTC (MHU). scripts/parse-system-logs.py runs it every 5 minutes
 * and merges the result with MHSave.txt into net_mheard. Command line only.
 *
 * Usage: php poll-mheard.php [--verbose]
 * Off switch: Maintenance → System Logs (v1.5.26), or config.php
 *   'system_logs' => ['live_mheard' => false]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once dirname(__DIR__) . '/includes/station-config.php';

$VERBOSE = in_array('--verbose', $argv, true);
function out(array $j): void { echo json_encode($j), "\n"; exit(empty($j['ok']) ? 1 : 0); }
function vlog(string $m): void { global $VERBOSE; if ($VERBOSE) fwrite(STDERR, $m . "\n"); }

if (!bpq_live_mheard_on()) out(['ok' => false, 'error' => 'switched off (Maintenance → System Logs, or config.php system_logs.live_mheard)']);

$bbs  = bpq_config_section('bbs');
$host = (string)($bbs['host'] ?? '127.0.0.1');
if ($host === '' || $host === 'localhost') $host = '127.0.0.1';
$port = (int)($bbs['port'] ?? 8010);
$user = (string)($bbs['user'] ?? '');
$pass = (string)($bbs['pass'] ?? '');
if ($user === '' || $pass === '' || in_array($user, ['SYSOPUSER', 'YOURCALL'], true) || $pass === 'CHANGE_THIS_PASSWORD') {
    out(['ok' => false, 'error' => 'set bbs.user / bbs.pass in config.php']);
}
$ports = array_keys(bpq_linbpq_ports());
if (!$ports) out(['ok' => false, 'error' => 'no ports: bpq32.cfg not readable at paths.linbpq']);

// ── telnet helpers (as bpq-nodes-api.php) ─────────────────────────────────
function read_until($s, string $needle, float $t = 5.0): string {
    $buf = ''; $end = microtime(true) + $t;
    while (microtime(true) < $end) {
        $r = [$s]; $w = $e = [];
        if (@stream_select($r, $w, $e, 0, 100000)) {
            $c = @fread($s, 4096);
            if ($c === false || $c === '') break;
            $buf .= $c;
            if (stripos(clean_buf($buf), $needle) !== false) return $buf;
        }
    }
    return $buf;
}
function read_quiet($s, float $max = 8.0, float $idle = 1.2): string {
    $buf = ''; $end = microtime(true) + $max; $last = microtime(true);
    while (microtime(true) < $end) {
        $r = [$s]; $w = $e = [];
        if (@stream_select($r, $w, $e, 0, 100000)) {
            $c = @fread($s, 4096);
            if ($c === false || $c === '') break;
            $buf .= $c; $last = microtime(true);
        } elseif ($buf !== '' && microtime(true) - $last >= $idle) {
            break;
        }
    }
    return $buf;
}
function clean_buf(string $b): string {
    $b = preg_replace('/\xff[\xfb\xfc\xfd\xfe]./s', '', $b);
    $b = preg_replace('/\xff\xff/s', "\xff", $b);
    return preg_replace('/\x1b\[[0-9;]*[A-Za-z]/s', '', $b);
}
function respond_iac($s, string $b): void {
    $resp = '';
    for ($i = 0; $i < strlen($b); $i++) {
        if (ord($b[$i]) === 0xff && isset($b[$i + 1], $b[$i + 2])) {
            $cmd = ord($b[$i + 1]); $opt = $b[$i + 2];
            if ($cmd === 0xfb) { $resp .= "\xff\xfd" . $opt; $i += 2; }
            elseif ($cmd === 0xfd) { $resp .= "\xff\xfb" . $opt; $i += 2; }
        }
    }
    if ($resp !== '') @fwrite($s, $resp);
}

/** "KP2L-10    Nov 08 11:07:33" lines of an MHU reply -> [[call, 'Y-m-d H:i:s'], ...] */
function parse_mhu(string $raw, int $now): array {
    static $mon = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
    $rows = []; $seen = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) {
        if (!preg_match('/^\s*([A-Z0-9]{1,6}(?:-\d{1,2})?)\*?\s+([A-Za-z]{3})\s+(\d{1,2})\s+(\d{1,2}):(\d{2}):(\d{2})\b/', $l, $m)) continue;
        $mo = $mon[strtoupper($m[2])] ?? 0;
        if (!$mo) continue;
        $y = (int)gmdate('Y', $now);
        $ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], $mo, (int)$m[3], $y);
        if ($ts > $now + 86400) $ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], $mo, (int)$m[3], $y - 1);   // December list read in January
        $call = strtoupper($m[1]);
        if (isset($seen[$call])) continue;
        $seen[$call] = true;
        $rows[] = ['call' => $call, 'heard_at' => gmdate('Y-m-d H:i:s', $ts)];
    }
    return $rows;
}

// ── log in ────────────────────────────────────────────────────────────────
$s = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 10);
if (!$s) out(['ok' => false, 'error' => "cannot connect to BPQ telnet $host:$port: $errstr"]);
stream_set_timeout($s, 10);

$buf = read_until($s, 'user:', 6.0);
if (stripos(clean_buf($buf), 'user:') === false) { fclose($s); out(['ok' => false, 'error' => 'no user: prompt']); }
respond_iac($s, $buf);
fwrite($s, $user . "\r\n");
$buf = read_until($s, 'password:', 6.0);
fwrite($s, $pass . "\r\n");

// BBS prompt ("de <user>>", or any '>' line after the SID / "Connected to BBS"),
// or straight to the node (prompt ends in '}')
$buf = ''; $end = microtime(true) + 10.0;
while (microtime(true) < $end) {
    $chunk = read_quiet($s, 1.5, 0.8);
    $buf .= $chunk;
    $c = rtrim(clean_buf($buf));
    if (preg_match('/[>}]$/', $c)) break;
    if ($chunk === '' && $buf !== '') break;
}
$clean = clean_buf($buf);
if (preg_match('/sorry|invalid|incorrect|password:|user:/i', $clean)) {
    fclose($s); out(['ok' => false, 'error' => 'login refused: check bbs.user / bbs.pass']);
}
$in_bbs = !preg_match('/\}\s*$/', $clean) && (preg_match('/>\s*$/', $clean)
          || stripos($clean, 'Connected to BBS') !== false || preg_match('/\[[A-Za-z0-9.\-]+\$\]/', $clean));
vlog($in_bbs ? 'login landed in the BBS' : 'login landed at the node');
if ($in_bbs) {
    fwrite($s, "NODE\r\n");
    $ret = clean_buf(read_until($s, '}', 6.0));
    if (strpos($ret, '}') === false) { fclose($s); out(['ok' => false, 'error' => 'NODE did not return to the node prompt']); }
    read_quiet($s, 0.6, 0.3);
}
fwrite($s, "PAGE 0\r\n");
read_quiet($s, 3.0, 0.8);

// ── MHU per port ──────────────────────────────────────────────────────────
$now = time();
$res = [];
foreach ($ports as $p) {
    fwrite($s, "MHU $p\r\n");
    $raw = clean_buf(read_quiet($s, 8.0, 1.0));
    $rows = parse_mhu($raw, $now);
    vlog("port $p: " . count($rows) . " station(s)");
    $res[(string)$p] = $rows;
}
fwrite($s, "BYE\r\n");
fclose($s);

out(['ok' => true, 'polled_at' => gmdate('Y-m-d H:i:s', $now), 'ports' => (object)$res]);
