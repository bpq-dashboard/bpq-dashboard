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
$SYSOP_EMAIL = 'sysop@example.com'  // set to your email;
$FLRIG_HOST  = '127.0.0.1'  // flrig host;
$FLRIG_PORT  = 12345;
$DAEMON_FILE  = __DIR__ . '/cache/vara-sessions/vara-daemon.json';
$BPQ32_CFG    = '/home/linbpq/bpq32.cfg'  // path to your bpq32.cfg;   // path to bpq32.cfg
$BPQ32_BACKUP = __DIR__ . '/cache/vara-sessions/bpq32-radio-backup.txt';
$RIGRECONFIG_USER = 'YOURCALL';
$RIGRECONFIG_PASS = 'YOURPASSWORD'; // BPQ sysop password (same as bbs.pass in config.php)

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
    // FTdx3000 returns plain <value>7103200</value> with no type wrapper
    if (preg_match('/<value>(\d+)<\/value>/', $resp, $m)) return (float)$m[1];
    return false;
}

function flrigSetFreq(float $hz): bool {
    // flrig uses Hz as integer — correct method is rig.set_frequency
    $resp = flrigCall('rig.set_frequency', [(string)(int)$hz]);
    return $resp !== false;
}

function flrigSetMode(string $mode): bool {
    $resp = flrigCall('rig.set_mode', [$mode]);
    return $resp !== false;
}

/**
 * Send FREQUENCY command directly to VARA HF command port.
 * VARA accepts: FREQUENCY <hz_integer>
 * This bypasses flrig and BPQ's radio scanner entirely.
 */
function varaSetFreq(string $vara_host, int $vara_port, int $hz): bool {
    $sock = @fsockopen($vara_host, $vara_port, $errno, $errstr, 3);
    if (!$sock) return false;
    stream_set_timeout($sock, 3);
    $cmd = "FREQUENCY " . $hz . "
";
    $ok = @fwrite($sock, $cmd);
    // Read any response (VARA may echo back OK or nothing)
    @fgets($sock, 128);
    fclose($sock);
    return $ok !== false;
}

// ── bpq32.cfg RADIO 2 rewrite ────────────────────────────────────
/**
 * Rewrite the RADIO 2 frequency scan list in bpq32.cfg to a single
 * frequency, then send RIGRECONFIG to BPQ so the scanner picks it up
 * without restarting LinBPQ.
 *
 * Original scan block is backed up to cache/vara-sessions/bpq32-radio-backup.txt
 * Call restoreRadio2() on session disconnect to restore it.
 */
function rewriteRadio2(float $hz, string $cfgPath, string $backupPath): bool {
    if (!file_exists($cfgPath)) return false;
    $cfg = file_get_contents($cfgPath);

    // Match RADIO 2 block from "RADIO 2" line to "*****" terminator (inclusive)
    // Uses [\s\S] to match across multiple lines including time sections
    if (!preg_match('/(^RADIO 2 ?
[\s\S]*?\*{5})/m', $cfg, $m)) {
        return false;
    }
    $originalBlock = $m[1];

    // Back up original block if not already backed up
    if (!file_exists($backupPath)) {
        file_put_contents($backupPath, $originalBlock);
    }

    // Build new block with single frequency
    $mhz = number_format($hz / 1000000, 6, '.', '');
    $newBlock = "RADIO 2
"
              . " FLRIG {$FLRIG_HOST}:{$FLRIG_PORT} HAMLIB={$FLRIG_HOST}:4532
"
              . " 00:00
"
              . " 7,{$mhz},PKT-U
"
              . "*****";

    $cfg = str_replace($originalBlock, $newBlock, $cfg);
    file_put_contents($cfgPath, $cfg);
    return true;
}

function restoreRadio2(string $cfgPath, string $backupPath): bool {
    if (!file_exists($backupPath) || !file_exists($cfgPath)) return false;
    $original = file_get_contents($backupPath);
    $cfg      = file_get_contents($cfgPath);

    // Find the current (modified) RADIO 2 block and replace with original
    if (!preg_match('/(RADIO 2\s*
(?:.*
)*?\*{3,})/m', $cfg, $m)) {
        return false;
    }
    $cfg = str_replace($m[1], $original, $cfg);
    file_put_contents($cfgPath, $cfg);
    unlink($backupPath);
    return true;
}

/**
 * Send RIGRECONFIG to BPQ via HTTP management interface (port 8008).
 *
 * Sequence confirmed working:
 *   1. GET /Node/Terminal.html to get session token
 *   2. POST PASSWORD to /TermInput?TOKEN — grants SYSOP status (Ok)
 *   3. POST RIGRECONFIG immediately — responds "Rigcontrol Reconfig requested"
 */
function sendRigReconfig(string $user, string $pass, string $host = '127.0.0.1', int $port = 8008): bool {
    $base = "http://{$host}:{$port}";
    $auth = base64_encode("{$user}:{$pass}");
    $hdr  = "Authorization: Basic {$auth}\r\nConnection: close\r\n";

    // Step 1 — open terminal session, get token
    $ctx = stream_context_create(['http' => ['method'=>'GET','header'=>$hdr,'timeout'=>5]]);
    $html = @file_get_contents("{$base}/Node/Terminal.html", false, $ctx);
    if (!$html) return false;
    if (!preg_match('/TermClose\?(T[0-9A-Fa-f]+)/i', $html, $m)) return false;
    $token = $m[1];

    // Step 2 — PASSWORD grants SYSOP status
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => $hdr . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => 'input=PASSWORD',
        'timeout' => 5,
    ]]);
    @file_get_contents("{$base}/TermInput?{$token}", false, $ctx);
    usleep(300000); // 300ms — BPQ processes PASSWORD

    // Step 3 — RIGRECONFIG while SYSOP session is still active
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => $hdr . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => 'input=RIGRECONFIG',
        'timeout' => 5,
    ]]);
    @file_get_contents("{$base}/TermInput?{$token}", false, $ctx);
    usleep(600000); // 600ms — BPQ processes RIGRECONFIG

    // Step 4 — verify by reading output screen
    $ctx = stream_context_create(['http' => ['method'=>'GET','header'=>$hdr,'timeout'=>5]]);
    $out = @file_get_contents("{$base}/Node/OutputScreen.html?{$token}", false, $ctx);
    return $out && str_contains($out, 'Rigcontrol');
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
                [$cs, $name, $note, $CONFIG['node']['callsign'] ?? 'YOURCALL']
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
            "From: dashboard@bpq.k1ajd.net\r\nReply-To: {$cs}@invalid");
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
        // No auth required — page login overlay protects access
        $mhz = (float)($input['mhz'] ?? 0);
        if ($mhz <= 0) fail('Frequency (MHz) required');
        $bandInfo = validateAmateurFreq($mhz);
        if (!$bandInfo) fail("$mhz MHz is not in an ITU Region 2 HF data-authorized band");
        $hz = (int)round($mhz * 1000000);
        // Rewrite RADIO 2 scan list in bpq32.cfg to single selected frequency
        // then send RIGRECONFIG so BPQ scanner moves radio without restart
        $cfgOk  = rewriteRadio2((float)$hz, $BPQ32_CFG, $BPQ32_BACKUP);
        $rigOk  = false;
        if ($cfgOk) {
            $rigOk = sendRigReconfig($RIGRECONFIG_USER, $RIGRECONFIG_PASS);
        }


        ok(['mhz' => $mhz, 'hz' => $hz, 'band' => $bandInfo,
            'cfg_rewritten' => $cfgOk, 'rigreconfig_sent' => $rigOk,
            'method' => 'rigreconfig']);

    // ── FREQUENCY — get current ───────────────────────────────────
    case 'get_freq':
        // No auth required — read only
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

    // ── RIG INFO — full rig status for rig-status.html ──────────────
    case 'get_rig_info':
        // Only use confirmed-working flrig methods
        $hz   = flrigGetFreq();
        if ($hz === false) fail("Cannot reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}");
        $modeRaw = flrigCall('rig.get_mode', []);
        // Extract mode string from XML response
        $mode = 'PKT-U';
        if ($modeRaw && preg_match('/<string>([^<]+)<\/string>/', $modeRaw, $mm)) {
            $mode = trim($mm[1]);
        } elseif ($modeRaw && preg_match('/<value>([^<]+)<\/value>/', $modeRaw, $mm)) {
            $mode = trim($mm[1]);
        }
        // S-meter (0-100 scale from flrig, represents signal strength)
        $smeterRaw = flrigCall('rig.get_smeter', []);
        $smeter = null;
        if ($smeterRaw && preg_match('/<value>(\d+)<\/value>/', $smeterRaw, $mm)) {
            $smeter = (int)$mm[1];
        }
        // Power meter (0 on RX, 0-100 on TX)
        $pwrRaw = flrigCall('rig.get_pwrmeter', []);
        $pwrmeter = null;
        if ($pwrRaw && preg_match('/<value>(\d+)<\/value>/', $pwrRaw, $mm)) {
            $pwrmeter = (int)$mm[1];
        }
        // SWR meter (0 on RX, value on TX)
        $swrRaw = flrigCall('rig.get_swrmeter', []);
        $swrmeter = null;
        if ($swrRaw && preg_match('/<value>(\d+)<\/value>/', $swrRaw, $mm)) {
            $swrmeter = (int)$mm[1];
        }
        $isTx = ($pwrmeter !== null && $pwrmeter > 0);
        ok([
            'hz'       => (int)$hz,
            'mhz'      => round($hz / 1000000, 6),
            'mode'     => $mode,
            'smeter'   => $smeter,
            'pwrmeter' => $pwrmeter,
            'swrmeter' => $swrmeter,
            'tx'       => $isTx,
        ]);

    // ── FREQUENCY RESTORE — called on session disconnect ─────────────
    case 'restore_freq':
        // Restore original RADIO 2 scan list and send RIGRECONFIG
        $restored = restoreRadio2($BPQ32_CFG, $BPQ32_BACKUP);
        $rigOk    = false;
        if ($restored) {
            $rigOk = sendRigReconfig($RIGRECONFIG_USER, $RIGRECONFIG_PASS);
        }
        ok(['restored' => $restored, 'rigreconfig_sent' => $rigOk]);

    default:
        fail('Unknown action', 404);
}
