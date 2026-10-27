<?php
/**
 * bpq-nodes-api.php - Live BPQ NetROM nodes and routes via telnet scrape
 * BPQ Dashboard v1.5.14
 *
 * BPQ login sequence (confirmed from nc trace on the server):
 *   IAC negotiation -> user: -> <bbs.user> -> password: -> <bbs.pass>
 *   -> "de <bbs.user>>" (BBS prompt)  — or straight to the node prompt for
 *      a user whose default application is the node
 *   -> NODE -> "Returned to Node ALIAS:<station.node>" -> "ALIAS:<station.node>}"
 *   -> NODES / ROUTES, each ending at the node prompt "...:<station.node>}"
 *   -> BYE
 *
 * 2026-10-25: login, port and node call come from config.php ('bbs' and
 * 'station.node') — they were placeholders here, so the Telnet page's
 * NetROM list was empty on every install. The prompt is matched by the node
 * call instead of one station's node alias.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/includes/station-config.php';
$_bbs = bpq_config_section('bbs');
$_stn = bpq_station();

$BPQ_HOST  = (string)($_bbs['host'] ?? '127.0.0.1');
if ($BPQ_HOST === '' || $BPQ_HOST === 'localhost') $BPQ_HOST = '127.0.0.1';
$BPQ_PORT  = (int)($_bbs['port'] ?? 8010);
$BPQ_USER  = (string)($_bbs['user'] ?? '');
$BPQ_PASS  = (string)($_bbs['pass'] ?? '');
$NODE_CALL = strtoupper(trim((string)($_stn['node'] ?? '')));   // excluded from the node list
$TIMEOUT   = 12;

if ($BPQ_USER === '' || $BPQ_PASS === '' || $NODE_CALL === ''
    || in_array($BPQ_USER, ['SYSOPUSER', 'YOURCALL'], true)
    || $BPQ_PASS === 'CHANGE_THIS_PASSWORD' || str_starts_with($NODE_CALL, 'YOURCALL')) {
    http_response_code(503);
    echo json_encode(['error' => "Set bbs.user, bbs.pass and station.node in config.php"]);
    exit;
}


function read_until($sock, string $needle, float $timeout = 5.0): string {
    $buf      = '';
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $r = [$sock]; $w = []; $e = [];
        if (@stream_select($r, $w, $e, 0, 100000)) {
            $chunk = @fread($sock, 4096);
            if ($chunk === false || $chunk === '') break;
            $buf .= $chunk;
            // Check raw buffer first (needle may be literal \r\n sequence)
            if (strpos($buf, $needle) !== false) return $buf;
            // Also check IAC-stripped version
            $clean = preg_replace('/\xff[\xfb\xfc\xfd\xfe]./s', '', $buf);
            if (strpos($clean, $needle) !== false) return $buf;
        }
    }
    return $buf;
}

// Read until the output goes quiet ($idle s with no data after some arrived),
// or $max s in all — the node's replies don't end with a fixed marker.
function read_quiet($sock, float $max = 8.0, float $idle = 1.2): string {
    $buf = ''; $deadline = microtime(true) + $max; $last = microtime(true);
    while (microtime(true) < $deadline) {
        $r = [$sock]; $w = []; $e = [];
        if (@stream_select($r, $w, $e, 0, 100000)) {
            $chunk = @fread($sock, 4096);
            if ($chunk === false || $chunk === '') break;
            $buf .= $chunk; $last = microtime(true);
        } elseif ($buf !== '' && microtime(true) - $last >= $idle) {
            break;
        }
    }
    return $buf;
}

function clean_buf(string $buf): string {
    $buf = preg_replace('/\xff[\xfb\xfc\xfd\xfe]./s', '', $buf);
    $buf = preg_replace('/\xff\xff/s', "\xff", $buf);
    $buf = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/s', '', $buf);
    return $buf;
}

function respond_iac($sock, string $buf): void {
    $resp = '';
    for ($i = 0; $i < strlen($buf); $i++) {
        if (ord($buf[$i]) === 0xff && isset($buf[$i+1], $buf[$i+2])) {
            $cmd = ord($buf[$i+1]);
            $opt = $buf[$i+2];
            if ($cmd === 0xfb) { $resp .= "\xff\xfd" . $opt; $i += 2; }
            elseif ($cmd === 0xfd) { $resp .= "\xff\xfb" . $opt; $i += 2; }
        }
    }
    if ($resp !== '') @fwrite($sock, $resp);
}

function parse_nodes(string $raw, string $own_call): array {
    $nodes = [];
    $seen  = [];
    preg_match_all('/([A-Z0-9]{1,9}):([A-Z0-9]{3,8}(?:-\d{1,2})?)/', $raw, $m, PREG_SET_ORDER);
    foreach ($m as $match) {
        $alias = strtoupper(trim($match[1]));
        $call  = strtoupper(trim($match[2]));
        if ($call === $own_call) continue;
        if (isset($seen[$call])) continue;
        $seen[$call] = true;
        $nodes[] = ['alias' => $alias, 'call' => $call];
    }
    return $nodes;
}

function parse_routes(string $raw): array {
    $routes = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!str_starts_with($line, '>')) continue;
        $parts = preg_split('/\s+/', $line);
        if (count($parts) < 4) continue;
        $call  = strtoupper($parts[2]);
        $qual  = (int)($parts[3] ?? 0);
        $nodes = (int)($parts[4] ?? 0);
        if (!preg_match('/^[A-Z0-9]{3,8}(-\d{1,2})?$/', $call)) continue;
        $routes[] = ['call' => $call, 'qual' => $qual, 'node_count' => $nodes];
    }
    return $routes;
}

// ── Connect ───────────────────────────────────────────────────────
$sock = @stream_socket_client("tcp://{$BPQ_HOST}:{$BPQ_PORT}", $errno, $errstr, $TIMEOUT);
if (!$sock) {
    http_response_code(503);
    echo json_encode(['error' => "Cannot connect to BPQ: {$errstr}"]);
    exit;
}
stream_set_timeout($sock, $TIMEOUT);

// Step 1 - IAC + username
$buf = read_until($sock, 'user:', 5.0);
respond_iac($sock, $buf);
$drain = microtime(true) + 0.4;
while (microtime(true) < $drain) {
    $r = [$sock]; $w = []; $e = [];
    if (@stream_select($r, $w, $e, 0, 100000)) {
        $x = @fread($sock, 256);
        if ($x) respond_iac($sock, $x);
    }
}
fwrite($sock, $BPQ_USER . "\r\n");

// Step 2 - password
$buf = read_until($sock, 'password:', 5.0);
fwrite($sock, $BPQ_PASS . "\r\n");

// Step 3 - BBS prompt "de <user>>", or (default application NODE) the node
$bbsPrompt = 'de ' . $BPQ_USER . '>';
$buf = '';
$deadline = microtime(true) + 8.0;
while (microtime(true) < $deadline) {
    $chunk = read_quiet($sock, 1.5, 0.8);
    $buf  .= $chunk;
    if (stripos(clean_buf($buf), $bbsPrompt) !== false) break;
    if ($chunk === '' && $buf !== '') break;   // greeting finished, no BBS prompt: at the node
}
$clean  = clean_buf($buf);
// 2026-10-27: BPQ Mail's prompt is configurable (not always "de <user>>"),
// and its greeting can follow "Connected to BBS" + the SID after a pause.
$in_bbs = stripos($clean, $bbsPrompt) !== false
       || stripos($clean, 'Connected to BBS') !== false
       || preg_match('/\[[A-Za-z0-9.\-]+\$\]/', $clean)
       || preg_match('/\bde [A-Za-z0-9\/\-]+>\s*$/', $clean);
if ($in_bbs && !preg_match('/>\s*$/', $clean)) {      // wait for the BBS prompt
    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline && !preg_match('/>\s*$/', $clean)) {
        $buf  .= read_quiet($sock, 1.5, 0.8);
        $clean = clean_buf($buf);
    }
}
if (!$in_bbs && ($clean === '' || preg_match('/sorry|invalid|password:|user:/i', $clean))) {
    fclose($sock);
    http_response_code(503);
    echo json_encode(['error' => 'Login failed (check bbs.user / bbs.pass in config.php). Got: '
                     . substr(trim(preg_replace('/[^\x20-\x7e]+/', ' ', $clean)), -80)]);
    exit;
}

// Step 4 - NODE: from the BBS back to the node prompt
if ($in_bbs) {
    fwrite($sock, "NODE\r\n");
    $ret = clean_buf(read_until($sock, 'Returned to Node', 6.0));
    if (strpos($ret, 'Returned to Node') === false) {
        fclose($sock);
        http_response_code(503);
        echo json_encode(['error' => 'NODE failed. Got: ' . substr($ret, -120)]);
        exit;
    }
    // Drain the rest of the node prompt line
    read_quiet($sock, 0.6, 0.3);
}

// Disable paging so BPQ sends full output without waiting for keypress
fwrite($sock, "PAGE 0\r\n");
read_quiet($sock, 3.0, 0.8);

// Step 5 - NODES (PAGE 0: the whole list, no keypress)
fwrite($sock, "NODES\r\n");
$nodes_raw = clean_buf(read_quiet($sock, 8.0, 1.2));

// Step 6 - ROUTES
fwrite($sock, "ROUTES\r\n");
$routes_raw = clean_buf(read_quiet($sock, 8.0, 1.2));

// Step 7 - disconnect
fwrite($sock, "BYE\r\n");
fclose($sock);

// ── Parse + annotate ──────────────────────────────────────────────
$nodes  = parse_nodes($nodes_raw, $NODE_CALL);
$routes = parse_routes($routes_raw);

$route_map = [];
foreach ($routes as $r) $route_map[$r['call']] = $r;

foreach ($nodes as &$n) {
    if (isset($route_map[$n['call']])) {
        $n['active']     = true;
        $n['qual']       = $route_map[$n['call']]['qual'];
        $n['node_count'] = $route_map[$n['call']]['node_count'];
    } else {
        $n['active']     = false;
        $n['qual']       = 0;
        $n['node_count'] = 0;
    }
}
unset($n);

echo json_encode(['nodes' => $nodes, 'routes' => $routes, 'ts' => time()], JSON_PRETTY_PRINT);
