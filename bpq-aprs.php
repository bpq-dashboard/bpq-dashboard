<?php
/**
 * bpq-aprs.php — APRS dashboard API — reads from daemon cache
 * Daemon: /var/www/bpq-dashboard/scripts/bpq-aprs-daemon.py
 *
 * sendmsg (2026-10-04): POST only; logs in to APRS-IS with config.php
 * 'aprs' => callsign / passcode (/ optional server), the same values the
 * daemon uses. Nothing station-specific is hard-coded here any more.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

require_once __DIR__ . '/includes/station-config.php';

/**
 * APRS-IS passcode for a callsign (public algorithm; the SSID is ignored).
 */
function aprsPasscode(string $call): int {
    $base = strtoupper(explode('-', $call)[0]);
    $h = 0x73e2;
    for ($i = 0, $n = strlen($base); $i < $n; $i += 2) {
        $h ^= ord($base[$i]) << 8;
        if ($i + 1 < $n) $h ^= ord($base[$i + 1]);
    }
    return $h & 0x7fff;
}

/**
 * The station's APRS login from config.php 'aprs'.
 * Returns [callsign, passcode, host, port, error-or-null].
 */
function aprsLogin(): array {
    $a    = bpq_config_section('aprs');
    $call = strtoupper(trim((string)($a['callsign'] ?? '')));
    $pass = trim((string)($a['passcode'] ?? ''));
    $srv  = trim((string)($a['server'] ?? '')) ?: 'rotate.aprs2.net:14580';
    $host = $srv; $port = 14580;
    if (preg_match('/^(.+):(\d{1,5})$/', $srv, $m)) { $host = $m[1]; $port = (int)$m[2]; }
    $err = null;
    if ($call === '' || strpos($call, 'YOURCALL') === 0 || $call === 'N0CALL'
        || !preg_match('/^[A-Z0-9]{3,6}(-[A-Z0-9]{1,2})?$/', $call)) {
        $err = "APRS sending isn't set up: put your callsign-SSID in config.php 'aprs' => 'callsign'.";
    } elseif ($pass === '' || $pass === '-1' || !preg_match('/^\d{1,5}$/', $pass)) {
        $err = "APRS sending isn't set up: config.php 'aprs' => 'passcode' is missing or -1 (receive-only).";
    } elseif ((int)$pass !== aprsPasscode($call)) {
        $err = "config.php 'aprs' => 'passcode' doesn't match the callsign " . explode('-', $call)[0] . '.';
    }
    return [$call, $pass, $host, $port, $err];
}

$CACHE_DIR    = __DIR__ . '/cache/aprs';
$STATIONS_FILE= $CACHE_DIR . '/stations.json';
$HISTORY_FILE = $CACHE_DIR . '/history.json';
$MESSAGES_FILE= $CACHE_DIR . '/messages.json';
$DAEMON_FILE  = $CACHE_DIR . '/aprs-daemon.json';

function readJson(string $path): array {
    if (!file_exists($path)) return [];
    $d = @json_decode(file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function daemonRunning(): bool {
    global $DAEMON_FILE;
    if (!file_exists($DAEMON_FILE)) return false;
    $d = @json_decode(file_get_contents($DAEMON_FILE), true);
    if (!is_array($d)) return false;
    // Consider daemon alive if heartbeat within 60s
    return ($d['ts'] ?? 0) > time() - 60;
}

$raw    = file_get_contents('php://input');
$input  = json_decode($raw, true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? 'stations');

if ($action === 'stations') {
    $stations = array_values(readJson($STATIONS_FILE));
    usort($stations, fn($a,$b) => ($b['ts']??0) - ($a['ts']??0));
    echo json_encode([
        'success'  => true,
        'stations' => $stations,
        'count'    => count($stations),
        'updated'  => file_exists($STATIONS_FILE) ? filemtime($STATIONS_FILE) : time(),
        'daemon'   => daemonRunning(),
        'aprs_call'=> aprsLogin()[0] ?: null,
        'can_send' => aprsLogin()[4] === null,
    ]);
    exit;
}

if ($action === 'history') {
    // Return track history for all stations or specific callsign
    $call    = strtoupper($input['call'] ?? '');
    $history = readJson($HISTORY_FILE);
    if ($call && isset($history[$call])) {
        echo json_encode(['success'=>true,'history'=>[$call=>$history[$call]]]);
    } else {
        echo json_encode(['success'=>true,'history'=>$history]);
    }
    exit;
}

if ($action === 'messages') {
    $messages = readJson($MESSAGES_FILE);
    echo json_encode(['success'=>true,'messages'=>$messages]);
    exit;
}

if ($action === 'status') {
    $daemon = readJson($DAEMON_FILE);
    echo json_encode(['success'=>true,'daemon'=>$daemon,'running'=>daemonRunning()]);
    exit;
}

if ($action === 'sendmsg') {
    // Writes go out on the air as the station: POST only, so the web
    // server's "writes need the login" rule (limit_except GET) always
    // applies. A GET carrying a JSON body used to reach this branch.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success'=>false,'error'=>'sendmsg must be a POST']);
        exit;
    }

    require_once __DIR__ . '/includes/web-login.php';
    bpq_require_web_login('Sending APRS messages', true);

    [$myCall, $myPass, $host, $port, $cfgErr] = aprsLogin();
    if ($cfgErr !== null) {
        echo json_encode(['success'=>false,'error'=>$cfgErr]);
        exit;
    }

    $to   = strtoupper(trim((string)($input['to']  ?? '')));
    $text = trim((string)($input['text'] ?? ''));
    $seq  = intval($input['seq'] ?? 1);

    if (!$to || $text === '') {
        echo json_encode(['success'=>false,'error'=>'Missing to or text']);
        exit;
    }
    // Addressee: callsign-SSID or a group/bulletin name, max 9 characters.
    if (!preg_match('/^[A-Z0-9]{1,6}(-[A-Z0-9]{1,2})?$/', $to)) {
        echo json_encode(['success'=>false,'error'=>'Invalid recipient callsign']);
        exit;
    }
    if (strlen($text) > 67) {
        echo json_encode(['success'=>false,'error'=>'Message too long']);
        exit;
    }
    // No control characters (a newline would start a second packet sent as
    // the station) and none of | ~ { which the APRS spec reserves.
    if (preg_match('/[\x00-\x1F\x7F|~{]/', $text)) {
        echo json_encode(['success'=>false,'error'=>'Message contains characters APRS does not allow (| ~ { or control characters)']);
        exit;
    }
    if ($seq < 1 || $seq > 99999) $seq = 1;

    // Build APRS message packet
    // Format: SRCCALL>APRS,TCPIP*::DEST     :message text{seq}
    $dest    = str_pad($to, 9);
    $seqStr  = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    $packet  = "{$myCall}>APRS,TCPIP*::{$dest}:{$text}{{$seqStr}\r\n";

    // Send via APRS-IS
    $sock = @stream_socket_client("tcp://{$host}:{$port}", $e, $s, 10);
    if (!$sock) {
        echo json_encode(['success'=>false,'error'=>"Cannot connect: $s"]);
        exit;
    }
    stream_set_timeout($sock, 10);
    fgets($sock, 512); // banner
    fwrite($sock, "user {$myCall} pass {$myPass} vers BPQ-Dashboard 1.5\r\n");
    $resp = (string)fgets($sock, 512); // "# logresp CALL verified, server ..."
    if (!preg_match('/^#\s*logresp\s+\S+\s+verified\b/i', $resp)) {
        fclose($sock);
        echo json_encode(['success'=>false,'error'=>'APRS-IS did not accept the login'
            . ($resp !== '' ? ': ' . trim($resp) : ' (no reply)')]);
        exit;
    }
    fwrite($sock, $packet);
    usleep(500000);
    fclose($sock);

    echo json_encode(['success'=>true,'packet'=>trim($packet)]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action: '.$action]);
