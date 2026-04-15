<?php
/**
 * bpq-nodes-api.php - Live BPQ NetROM nodes and routes via telnet scrape
 * BPQ Dashboard v1.5.6
 *
 * BPQ login sequence (confirmed from nc trace on your server):
 *   IAC negotiation -> user: -> YOURCALL -> password: -> YOURPASSWORD
 *   -> "de YOURCALL>" (BBS prompt)
 *   -> NODE -> "Returned to Node YOURNODE:YOURCALL-4" -> node list -> "YOURNODE:YOURCALL-4}"
 *   -> ROUTES -> route table -> "YOURNODE:YOURCALL-4}"
 *   -> BYE
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$BPQ_HOST  = '127.0.0.1';
$BPQ_PORT  = 8010;
$BPQ_USER  = 'YOURCALL';
$BPQ_PASS  = 'YOURPASSWORD';
$NODE_CALL = 'YOURCALL-4';  // your node callsign — excluded from node list   // own node callsign - excluded from node list
$TIMEOUT   = 12;

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

// Step 3 - wait for BBS prompt "de YOURCALL>"
$buf = read_until($sock, 'de ' . $BPQ_USER . '>', 8.0);
$clean = clean_buf($buf);
if (strpos($clean, 'de ' . $BPQ_USER) === false) {
    fclose($sock);
    http_response_code(503);
    echo json_encode(['error' => 'Login failed. Got: ' . substr($clean, -80)]);
    exit;
}

// Step 4 - NODE: return to node prompt
fwrite($sock, "NODE\r\n");
$ret = clean_buf(read_until($sock, 'Returned to Node', 6.0));
if (strpos($ret, 'Returned to Node') === false) {
    fclose($sock);
    http_response_code(503);
    echo json_encode(['error' => 'NODE failed. Got: ' . substr($ret, -120)]);
    exit;
}
// Drain node prompt line
read_until($sock, '}', 3.0);

// Disable paging so BPQ sends full output without waiting for keypress
fwrite($sock, "PAGE 0\r\n");
read_until($sock, '}', 3.0);

// Step 5 - NODES
// PAGE 0 sends full list. List ends with \r\n then prompt arrives shortly after.
// Read with generous timeout — proceed even if prompt not captured.
fwrite($sock, "NODES\r\n");
$nodes_raw = clean_buf(read_until($sock, "\r\nAGNODE", 8.0));
if (strpos($nodes_raw, "AGNODE") === false) {
    // Full list received but prompt not yet — drain briefly and continue
    $extra = @fread($sock, 512);
    $nodes_raw .= clean_buf($extra ?: '');
}

// Step 6 - ROUTES
fwrite($sock, "ROUTES\r\n");
$routes_raw = clean_buf(read_until($sock, "\r\nAGNODE", 8.0));

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
