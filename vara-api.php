<?php
/**
 * vara-api.php — VARA HF Terminal API
 * BPQ Dashboard v1.5.6
 *
 * Endpoints:
 *   POST action=auth          — verify sysop password, set session
 *   POST action=check_auth    — check if session is authenticated
 *   GET  action=list          — list allowed stations (auth required)
 *   POST action=add           — add callsign to allowlist (auth required)
 *   POST action=toggle        — enable/disable callsign (auth required)
 *   POST action=delete        — remove callsign (auth required)
 *   POST action=request       — email sysop for access (no auth)
 *   POST action=set_freq      — QSY flrig to frequency (auth required)
 *   GET  action=get_freq      — get current flrig frequency (auth required)
 *   GET  action=status        — daemon heartbeat status (auth required)
 */

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$SKIP_BBS_CHECK = true;
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/tprfn-db.php';

// ── Config ────────────────────────────────────────────────────────
$SYSOP_PASS  = 'YOURPASSWORD';        // overridden from config.php bbs.pass below
$SYSOP_EMAIL = 'sysop@example.com';  // set to your email address
$FLRIG_HOST  = '127.0.0.1';          // flrig host — localhost or remote IP
$FLRIG_PORT  = 12345;
$DAEMON_FILE = __DIR__ . '/cache/vara-sessions/vara-daemon.json';

// Load actual pass from config if available
if (!empty($CONFIG['bbs']['pass'])) $SYSOP_PASS = $CONFIG['bbs']['pass'];

// ── DB setup ──────────────────────────────────────────────────────
// Table vara_allowed_stations created manually via:
//   sudo mysql tprfn -e "CREATE TABLE IF NOT EXISTS vara_allowed_stations (...)"
// tprfn_app has SELECT/INSERT/UPDATE/DELETE but not CREATE privilege.

// ── Auth helpers ──────────────────────────────────────────────────
function isAuthed(): bool {
    return !empty($_SESSION['vara_authed']) && $_SESSION['vara_authed'] === true
        && !empty($_SESSION['vara_auth_time'])
        && (time() - $_SESSION['vara_auth_time']) < 3600; // 1hr session
}

function requireAuth(): void {
    if (!isAuthed()) {
        http_response_code(401);
        die(json_encode(['error' => 'Authentication required']));
    }
}

function ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── Callsign validation ───────────────────────────────────────────
function validCallsign(string $cs): bool {
    return (bool)preg_match('/^[A-Z0-9]{3,7}(-\d{1,2})?$/', strtoupper(trim($cs)));
}

// ── ITU Region 2 HF data-authorized frequency bands ──────────────
// Based on FCC Part 97.305(c) — data/RTTY authorized segments
// Returns ['band'=>'40m','low'=>7.025,'high'=>7.125,'mode'=>'USB'] or false
function validateAmateurFreq(float $mhz): array|false {
    $bands = [
        // Band     Low MHz   High MHz   Notes
        ['160m',  1.800,    2.000,    'Data authorized throughout'],
        ['80m',   3.525,    3.600,    'CW/RTTY/Data — Extra/Advanced'],
        ['80m',   3.600,    4.000,    'General+ data segment'],
        ['60m',   5.3305,   5.3305,   'Channel only — 5330.5 kHz'],
        ['60m',   5.3465,   5.3465,   'Channel only — 5346.5 kHz'],
        ['60m',   5.3570,   5.3570,   'Channel only — 5357.0 kHz'],
        ['60m',   5.3715,   5.3715,   'Channel only — 5371.5 kHz'],
        ['60m',   5.4035,   5.4035,   'Channel only — 5403.5 kHz'],
        ['40m',   7.025,    7.125,    'CW/RTTY/Data'],
        ['40m',   7.125,    7.300,    'General+ (data permitted)'],
        ['30m',   10.100,   10.150,   'CW/RTTY/Data only — 200W max'],
        ['20m',   14.025,   14.150,   'CW/RTTY/Data'],
        ['20m',   14.150,   14.350,   'General+ (data permitted)'],
        ['17m',   18.068,   18.110,   'CW/RTTY/Data'],
        ['17m',   18.110,   18.168,   'General+ (data permitted)'],
        ['15m',   21.025,   21.200,   'CW/RTTY/Data'],
        ['15m',   21.200,   21.450,   'General+ (data permitted)'],
        ['12m',   24.890,   24.930,   'CW/RTTY/Data'],
        ['12m',   24.930,   24.990,   'General+ (data permitted)'],
        ['10m',   28.000,   28.300,   'CW/RTTY/Data — 200W max'],
        ['10m',   28.300,   29.700,   'General+ (data permitted)'],
    ];
    foreach ($bands as [$band, $low, $high, $note]) {
        if ($mhz >= $low - 0.0001 && $mhz <= $high + 0.0001) {
            return ['band' => $band, 'low' => $low, 'high' => $high, 'note' => $note];
        }
    }
    return false;
}

// ── Flrig XML-RPC helpers ─────────────────────────────────────────
function flrigCall(string $method, array $params = []): string|false {
    global $FLRIG_HOST, $FLRIG_PORT;
    $paramXml = '';
    foreach ($params as $p) {
        if (is_float($p) || is_int($p)) {
            $paramXml .= "<param><value><double>{$p}</double></value></param>";
        } else {
            $paramXml .= "<param><value><string>{$p}</string></value></param>";
        }
    }
    $xml = "<?xml version='1.0'?><methodCall><methodName>{$method}</methodName>"
         . "<params>{$paramXml}</params></methodCall>";
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: text/xml\r\nContent-Length: " . strlen($xml),
        'content' => $xml,
        'timeout' => 3,
    ]]);
    $resp = @file_get_contents("http://{$FLRIG_HOST}:{$FLRIG_PORT}/RPC2", false, $ctx);
    return $resp ?: false;
}

function flrigGetFreq(): float|false {
    $resp = flrigCall('rig.get_vfo');
    if (!$resp) return false;
    if (preg_match('/<double>([\d.]+)<\/double>/', $resp, $m)) return (float)$m[1];
    if (preg_match('/<string>([\d.]+)<\/string>/', $resp, $m)) return (float)$m[1];
    return false;
}

function flrigSetFreq(float $hz): bool {
    // flrig uses Hz as integer
    $resp = flrigCall('rig.set_vfo', [(string)(int)$hz]);
    return $resp !== false;
}

function flrigSetMode(string $mode): bool {
    $resp = flrigCall('rig.set_mode', [$mode]);
    return $resp !== false;
}

// ── Request dispatch ──────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $action;


switch ($action) {

    // ── AUTH ──────────────────────────────────────────────────────
    case 'auth':
        $pass = $input['password'] ?? $_POST['password'] ?? '';
        if (empty($pass)) fail('Password required', 401);
        if ($pass !== $SYSOP_PASS) {
            sleep(1); // slow brute force
            fail('Invalid password', 401);
        }
        $_SESSION['vara_authed']    = true;
        $_SESSION['vara_auth_time'] = time();
        ok(['authenticated' => true]);

    case 'check_auth':
        ok(['authenticated' => isAuthed()]);

    case 'logout':
        $_SESSION['vara_authed'] = false;
        unset($_SESSION['vara_auth_time']);
        ok();

    // ── ALLOWLIST — read ──────────────────────────────────────────
    case 'list':
        requireAuth();
        $pdo  = tprfn_db();
        $rows = tprfn_query($pdo, "SELECT * FROM vara_allowed_stations ORDER BY callsign ASC");
        ok(['stations' => $rows]);

    // ── ALLOWLIST — add ───────────────────────────────────────────
    case 'add':
        requireAuth();
        $cs   = strtoupper(trim($input['callsign'] ?? ''));
        $name = trim($input['name'] ?? '');
        $note = trim($input['notes'] ?? '');
        if (!validCallsign($cs)) fail('Invalid callsign format');
        $pdo = tprfn_db();
        try {
            tprfn_execute($pdo,
                "INSERT INTO vara_allowed_stations (callsign, name, notes, added_by) VALUES (?,?,?,?)",
                [$cs, $name, $note, 'YOURCALL']
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) fail("$cs is already in the allowlist");
            fail('Database error: ' . $e->getMessage());
        }
        ok(['callsign' => $cs]);

    // ── ALLOWLIST — toggle ────────────────────────────────────────
    case 'toggle':
        requireAuth();
        $id = (int)($input['id'] ?? 0);
        if (!$id) fail('ID required');
        $pdo = tprfn_db();
        tprfn_execute($pdo,
            "UPDATE vara_allowed_stations SET active = 1 - active WHERE id = ?", [$id]);
        $row = tprfn_query_one($pdo, "SELECT * FROM vara_allowed_stations WHERE id = ?", [$id]);
        ok(['station' => $row]);

    // ── ALLOWLIST — delete ────────────────────────────────────────
    case 'delete':
        requireAuth();
        $id = (int)($input['id'] ?? 0);
        if (!$id) fail('ID required');
        $pdo = tprfn_db();
        tprfn_execute($pdo, "DELETE FROM vara_allowed_stations WHERE id = ?", [$id]);
        ok();

    // ── ACCESS REQUEST — email sysop ──────────────────────────────
    case 'request':
        $cs   = strtoupper(trim($input['callsign'] ?? ''));
        $name = trim($input['name'] ?? '');
        $msg  = trim($input['message'] ?? '');
        if (!validCallsign($cs)) fail('Invalid callsign format');
        $subject = "VARA HF Access Request from $cs";
        $body    = "Callsign: $cs\nName: $name\nMessage: $msg\n\n"
                 . "To approve, add $cs to the allowlist in bpq-vara.html\n"
                 . "Sent from BPQ Dashboard VARA terminal on " . date('Y-m-d H:i:s') . " UTC";
        $sent = mail($SYSOP_EMAIL, $subject, $body,
            "From: dashboard@" . $_SERVER['HTTP_HOST'] . "\r\nReply-To: {$cs}@invalid");
        ok(['sent' => $sent, 'to' => $SYSOP_EMAIL]);

    // ── FREQUENCY — validate ──────────────────────────────────────
    case 'validate_freq':
        $mhz = (float)($input['mhz'] ?? 0);
        if ($mhz <= 0) fail('Frequency required');
        $result = validateAmateurFreq($mhz);
        if (!$result) fail("$mhz MHz is not in an ITU Region 2 HF data-authorized band");
        ok(['mhz' => $mhz, 'band' => $result]);

    // ── FREQUENCY — set via flrig ─────────────────────────────────
    case 'set_freq':
        requireAuth();
        $mhz = (float)($input['mhz'] ?? 0);
        if ($mhz <= 0) fail('Frequency (MHz) required');
        // Validate against Region 2 data allocations
        $bandInfo = validateAmateurFreq($mhz);
        if (!$bandInfo) fail("$mhz MHz is not in an ITU Region 2 HF data-authorized band");
        $hz = $mhz * 1000000;
        // QSY flrig
        $freqOk = flrigSetFreq($hz);
        // Set USB mode (VARA HF uses USB dial)
        $modeOk = flrigSetMode('USB');
        if (!$freqOk) fail("Could not reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}");
        ok(['mhz' => $mhz, 'hz' => $hz, 'band' => $bandInfo, 'mode_set' => $modeOk]);

    // ── FREQUENCY — get current ───────────────────────────────────
    case 'get_freq':
        requireAuth();
        $hz = flrigGetFreq();
        if ($hz === false) fail("Could not reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}");
        $mhz = round($hz / 1000000, 6);
        $bandInfo = validateAmateurFreq($mhz);
        ok(['hz' => $hz, 'mhz' => $mhz, 'band' => $bandInfo ?: null]);

    // ── DAEMON STATUS ─────────────────────────────────────────────
    case 'status':
        requireAuth();
        if (!file_exists($DAEMON_FILE)) {
            ok(['running' => false, 'message' => 'Daemon not started']);
        }
        $data = json_decode(file_get_contents($DAEMON_FILE), true);
        $age  = time() - ($data['updated_ts'] ?? 0);
        $data['stale'] = $age > 60;
        ok($data);

    default:
        fail('Unknown action', 404);
}
