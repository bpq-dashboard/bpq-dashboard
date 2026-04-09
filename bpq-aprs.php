<?php
/**
 * bpq-aprs.php — APRS dashboard API — reads from daemon cache
 * Daemon: /var/www/bpqdash/scripts/bpq-aprs-daemon.py
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

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
    $to   = strtoupper(trim($input['to']  ?? ''));
    $text = trim($input['text'] ?? '');
    $seq  = intval($input['seq'] ?? 1);

    if (!$to || !$text) {
        echo json_encode(['success'=>false,'error'=>'Missing to or text']);
        exit;
    }
    if (strlen($text) > 67) {
        echo json_encode(['success'=>false,'error'=>'Message too long']);
        exit;
    }

    // Build APRS message packet
    // Format: SRCCALL>APRS,TCPIP*::DEST     :message text{seq}
    $dest    = str_pad($to, 9);
    $seqStr  = str_pad($seq, 3, '0', STR_PAD_LEFT);
    $packet  = "YOURCALL-1>APRS,TCPIP*::{$dest}:{$text}{{$seqStr}
";

    // Send via APRS-IS
    $sock = @stream_socket_client('tcp://rotate.aprs2.net:14580', $e, $s, 10);
    if (!$sock) {
        echo json_encode(['success'=>false,'error'=>"Cannot connect: $s"]);
        exit;
    }
    stream_set_timeout($sock, 10);
    fgets($sock, 512); // banner
    fwrite($sock, "user YOURCALL-1 pass 15769 vers BPQ-Dashboard 1.0
");
    fgets($sock, 512); // login resp
    fwrite($sock, $packet);
    usleep(500000);
    fclose($sock);

    echo json_encode(['success'=>true,'packet'=>trim($packet)]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action: '.$action]);
