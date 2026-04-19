<?php
/**
 * vara-api.php — VARA HF Terminal API
 * BPQ Dashboard v1.5.6
 *
 * Radio-agnostic version — supports Yaesu, Icom, Kenwood, Elecraft via flrig
 *
 * Endpoints:
 *   POST action=auth          — verify sysop password, set session
 *   POST action=check_auth    — check if session is authenticated
 *   GET  action=list          — list allowed stations (auth required)
 *   POST action=add           — add callsign to allowlist (auth required)
 *   POST action=toggle        — enable/disable callsign (auth required)
 *   POST action=delete        — remove callsign (auth required)
 *   POST action=request       — email sysop for access (no auth)
 *   POST action=set_freq      — QSY radio to frequency (auth required)
 *   GET  action=get_freq      — get current frequency (auth required)
 *   GET  action=get_rig_info  — full rig status for rig-status.html
 *   GET  action=get_rig_model — detect radio model from flrig
 *   POST action=restore_freq  — restore RADIO 2 scan after VARA session
 *   GET  action=status        — daemon heartbeat status (auth required)
 */

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$SKIP_BBS_CHECK = true;
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/tprfn-db.php';

// ── Config — overridden by config.php below ───────────────────────
$SYSOP_PASS       = 'YOURPASSWORD';
$SYSOP_EMAIL      = 'sysop@example.com';
$FLRIG_HOST       = '127.0.0.1';
$FLRIG_PORT       = 12345;
$DAEMON_FILE      = __DIR__ . '/cache/vara-sessions/vara-daemon.json';
$BPQ32_CFG        = '/home/linbpq/bpq32.cfg';
$BPQ32_BACKUP     = __DIR__ . '/cache/vara-sessions/bpq32-radio-backup.txt';
$RIGRECONFIG_USER = 'YOURCALL';
$RIGRECONFIG_PASS = 'YOURPASSWORD';

// Load from config.php
if (!empty($CONFIG['bbs']['pass']))      $SYSOP_PASS       = $CONFIG['bbs']['pass'];
if (!empty($CONFIG['bbs']['user']))      $RIGRECONFIG_USER = $CONFIG['bbs']['user'];
if (!empty($CONFIG['station']['email'])) $SYSOP_EMAIL      = $CONFIG['station']['email'];
if (!empty($CONFIG['radio']['flrig_host'])) $FLRIG_HOST    = $CONFIG['radio']['flrig_host'];
if (!empty($CONFIG['radio']['flrig_port'])) $FLRIG_PORT    = (int)$CONFIG['radio']['flrig_port'];
if (!empty($CONFIG['paths']['linbpq']))  $BPQ32_CFG        = rtrim($CONFIG['paths']['linbpq'],'/') . '/bpq32.cfg';

// ── Radio profile table ───────────────────────────────────────────
/**
 * Profiles for known radios.
 *
 * Keys (case-insensitive partial match against rig.get_xcvr response):
 *
 * digital_mode:
 *   null   = do NOT switch mode — leave radio in whatever mode it is in
 *            (FTdx3000 PKT-U must never be switched — causes 700Hz VARA offset)
 *   string = mode name to pass to rig.set_mode() before connecting
 *
 * mode_label:
 *   Human-readable label shown in the UI
 *
 * freq_response:
 *   'plain'  = <value>7103200</value>  (FTdx3000, some older Yaesu)
 *   'double' = <double>7103200</double> (most modern radios)
 *   'string' = <string>7103200</string> (some Kenwood)
 *   'auto'   = try all three (safe default)
 *
 * bpq32_mode:
 *   The mode string written into the bpq32.cfg RADIO block when rewriting
 *   the scan frequency for VARA. Must match what LinBPQ/flrig expects.
 *
 * note:
 *   Shown in maintenance page for reference.
 */
$RADIO_PROFILES = [
    // ── Yaesu ─────────────────────────────────────────────────────
    'FTdx3000' => [
        'make'          => 'Yaesu',
        'digital_mode'  => null,       // PKT-U — do NOT switch (700Hz offset if switched to USB)
        'mode_label'    => 'PKT-U',
        'bpq32_mode'    => 'PKT-U',
        'freq_response' => 'plain',    // returns bare <value>Hz</value>
        'note'          => 'Leave in PKT-U — switching to USB causes 700Hz carrier offset with VARA',
    ],
    'FTdx10' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FTdx101' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-991' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'FT-991 and FT-991A — use DATA-U',
    ],
    'FT-710' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-891' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-450' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-897' => [
        'make'          => 'Yaesu',
        'digital_mode'  => null,       // PKT-U already correct on this radio
        'mode_label'    => 'PKT-U',
        'bpq32_mode'    => 'PKT-U',
        'freq_response' => 'auto',
        'note'          => 'FT-897/FT-857 — PKT-U is correct, no mode switch needed',
    ],
    'FT-857' => [
        'make'          => 'Yaesu',
        'digital_mode'  => null,
        'mode_label'    => 'PKT-U',
        'bpq32_mode'    => 'PKT-U',
        'freq_response' => 'auto',
        'note'          => 'FT-857 — PKT-U is correct, no mode switch needed',
    ],

    // ── Icom ──────────────────────────────────────────────────────
    'IC-7300' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB',      // IC-7300 has no separate data mode — data via rear ACC
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'double',
        'note'          => 'IC-7300 uses USB mode — data audio via rear ACC connector. No 700Hz offset issue.',
    ],
    'IC-7610' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D (USB Data) mode for VARA HF',
    ],
    'IC-705' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7100' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7600' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7700' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7800' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-9700' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode — supports HF via transverter',
    ],
    'IC-7200' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB',
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'double',
        'note'          => 'IC-7200 has no separate data mode — data via rear ACC connector',
    ],

    // ── Kenwood ───────────────────────────────────────────────────
    'TS-590' => [
        'make'          => 'Kenwood',
        'digital_mode'  => 'USB',      // data via ACC2 jack in USB mode
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'string',
        'note'          => 'TS-590S/SG — data via ACC2 jack in USB mode',
    ],
    'TS-890' => [
        'make'          => 'Kenwood',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'string',
        'note'          => 'TS-890S — use USB-D (Data) mode',
    ],
    'TS-2000' => [
        'make'          => 'Kenwood',
        'digital_mode'  => 'USB',
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'string',
        'note'          => 'TS-2000 — no separate data mode, use USB',
    ],

    // ── Elecraft ──────────────────────────────────────────────────
    'K3' => [
        'make'          => 'Elecraft',
        'digital_mode'  => 'DATA',
        'mode_label'    => 'DATA',
        'bpq32_mode'    => 'DATA',
        'freq_response' => 'auto',
        'note'          => 'Elecraft K3/K3S — use DATA mode',
    ],
    'KX3' => [
        'make'          => 'Elecraft',
        'digital_mode'  => 'DATA',
        'mode_label'    => 'DATA',
        'bpq32_mode'    => 'DATA',
        'freq_response' => 'auto',
        'note'          => 'Elecraft KX3 — use DATA mode',
    ],
    'K4' => [
        'make'          => 'Elecraft',
        'digital_mode'  => 'DATA-USB',
        'mode_label'    => 'DATA-USB',
        'bpq32_mode'    => 'DATA-USB',
        'freq_response' => 'auto',
        'note'          => 'Elecraft K4 — use DATA-USB mode',
    ],
];

// ── Get radio profile (auto-detect or config override) ────────────
function getRadioProfile(): array {
    global $RADIO_PROFILES, $CONFIG;

    // 1. Config override takes priority — sysop can force a specific profile
    if (!empty($CONFIG['radio']['model'])) {
        $model = $CONFIG['radio']['model'];
        $profile = matchRadioProfile($model);
        if ($profile) return $profile;
    }

    // 2. Auto-detect from flrig
    $xcvrRaw = flrigCall('rig.get_xcvr', []);
    if ($xcvrRaw) {
        $xcvr = '';
        if (preg_match('/<string>([^<]+)<\/string>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
        elseif (preg_match('/<value>([^<]+)<\/value>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
        if ($xcvr) {
            $profile = matchRadioProfile($xcvr);
            if ($profile) return $profile;
            // Radio detected but no profile — return safe default
            return defaultProfile($xcvr);
        }
    }

    // 3. No detection possible — safe default (don't switch mode)
    return defaultProfile('Unknown');
}

function matchRadioProfile(string $model): array|false {
    global $RADIO_PROFILES;
    $modelUpper = strtoupper($model);
    foreach ($RADIO_PROFILES as $key => $profile) {
        if (stripos($modelUpper, strtoupper($key)) !== false ||
            stripos(strtoupper($key), $modelUpper) !== false) {
            return array_merge(['model' => $key], $profile);
        }
    }
    return false;
}

function defaultProfile(string $detected): array {
    return [
        'model'         => $detected,
        'make'          => 'Unknown',
        'digital_mode'  => null,       // safe — don't switch mode on unknown radio
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'auto',
        'note'          => 'Unknown radio — mode switching disabled for safety. Add profile to vara-api.php.',
    ];
}

// ── Auth helpers ──────────────────────────────────────────────────
function isAuthed(): bool {
    return !empty($_SESSION['vara_authed']) && $_SESSION['vara_authed'] === true
        && !empty($_SESSION['vara_auth_time'])
        && (time() - $_SESSION['vara_auth_time']) < 3600;
}
function requireAuth(): void {
    if (!isAuthed()) { http_response_code(401); die(json_encode(['error'=>'Authentication required'])); }
}
function ok(array $data = []): void { echo json_encode(array_merge(['ok'=>true],$data)); exit; }
function fail(string $msg, int $code=400): void {
    http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}
function validCallsign(string $cs): bool {
    return (bool)preg_match('/^[A-Z0-9]{3,7}(-\d{1,2})?$/', strtoupper(trim($cs)));
}

// ── ITU Region 2 HF band validation ──────────────────────────────
function validateAmateurFreq(float $mhz): array|false {
    $bands = [
        ['160m', 1.800, 2.000],   ['80m',  3.525, 4.000],
        ['60m',  5.330, 5.405],   ['40m',  7.025, 7.300],
        ['30m', 10.100,10.150],   ['20m', 14.025,14.350],
        ['17m', 18.068,18.168],   ['15m', 21.025,21.450],
        ['12m', 24.890,24.990],   ['10m', 28.000,29.700],
    ];
    foreach ($bands as [$band,$low,$high]) {
        if ($mhz >= $low-0.0001 && $mhz <= $high+0.0001)
            return ['band'=>$band,'low'=>$low,'high'=>$high];
    }
    return false;
}

// ── flrig XML-RPC helpers ─────────────────────────────────────────
function flrigCall(string $method, array $params=[]): string|false {
    global $FLRIG_HOST, $FLRIG_PORT;
    $paramXml = '';
    foreach ($params as $p) {
        if (is_float($p)||is_int($p))
            $paramXml .= "<param><value><double>{$p}</double></value></param>";
        else
            $paramXml .= "<param><value><string>{$p}</string></value></param>";
    }
    $xml = "<?xml version='1.0'?><methodCall><methodName>{$method}</methodName>"
         . "<params>{$paramXml}</params></methodCall>";
    $ctx = stream_context_create(['http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: text/xml\r\nContent-Length: ".strlen($xml),
        'content'=>$xml, 'timeout'=>3,
    ]]);
    return @file_get_contents("http://{$FLRIG_HOST}:{$FLRIG_PORT}/RPC2", false, $ctx) ?: false;
}

function flrigGetFreq(): float|false {
    global $CONFIG;
    $resp = flrigCall('rig.get_vfo');
    if (!$resp) return false;
    // Try all response formats — different radios use different XML types
    if (preg_match('/<double>([\d.]+)<\/double>/', $resp, $m)) return (float)$m[1];
    if (preg_match('/<string>([\d.]+)<\/string>/', $resp, $m)) return (float)$m[1];
    if (preg_match('/<value>(\d+)<\/value>/',      $resp, $m)) return (float)$m[1];
    return false;
}

function flrigSetFreq(float $hz): bool {
    $resp = flrigCall('rig.set_frequency', [(string)(int)$hz]);
    return $resp !== false;
}

function flrigSetMode(string $mode): bool {
    $resp = flrigCall('rig.set_mode', [$mode]);
    return $resp !== false;
}

// ── bpq32.cfg RADIO 2 rewrite ────────────────────────────────────
function rewriteRadio2(float $hz, string $cfgPath, string $backupPath, string $radioMode): bool {
    if (!file_exists($cfgPath)) return false;
    $cfg = file_get_contents($cfgPath);
    if (!preg_match('/(^RADIO 2 ?\n[\s\S]*?\*{5})/m', $cfg, $m)) return false;
    $originalBlock = $m[1];
    if (!file_exists($backupPath)) file_put_contents($backupPath, $originalBlock);

    // Extract FLRIG line from original block to preserve correct host/port
    $flrigLine = "FLRIG 127.0.0.1:12345";
    if (preg_match('/^\s*(FLRIG\s+\S+)/m', $originalBlock, $fl)) {
        $flrigLine = trim($fl[1]);
    }

    $mhz = number_format($hz / 1000000, 6, '.', '');
    $newBlock = "RADIO 2\n"
              . " {$flrigLine}\n"
              . " 00:00\n"
              . " 7,{$mhz},{$radioMode}\n"
              . "*****";

    $cfg = str_replace($originalBlock, $newBlock, $cfg);
    file_put_contents($cfgPath, $cfg);
    return true;
}

function restoreRadio2(string $cfgPath, string $backupPath): bool {
    if (!file_exists($backupPath)||!file_exists($cfgPath)) return false;
    $original = file_get_contents($backupPath);
    $cfg      = file_get_contents($cfgPath);
    if (!preg_match('/(RADIO 2\s*\n(?:.*\n)*?\*{3,})/m', $cfg, $m)) return false;
    $cfg = str_replace($m[1], $original, $cfg);
    file_put_contents($cfgPath, $cfg);
    unlink($backupPath);
    return true;
}

// ── RIGRECONFIG via BPQ HTTP management ──────────────────────────
function sendRigReconfig(string $user, string $pass, string $host='127.0.0.1', int $port=8008): bool {
    $base = "http://{$host}:{$port}";
    $auth = base64_encode("{$user}:{$pass}");
    $hdr  = "Authorization: Basic {$auth}\r\nConnection: close\r\n";
    $ctx  = stream_context_create(['http'=>['method'=>'GET','header'=>$hdr,'timeout'=>5]]);
    $html = @file_get_contents("{$base}/Node/Terminal.html", false, $ctx);
    if (!$html) return false;
    if (!preg_match('/TermClose\?(T[0-9A-Fa-f]+)/i', $html, $m)) return false;
    $token = $m[1];
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>$hdr."Content-Type: application/x-www-form-urlencoded\r\n",'content'=>'input=PASSWORD','timeout'=>5]]);
    @file_get_contents("{$base}/TermInput?{$token}", false, $ctx);
    usleep(300000);
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>$hdr."Content-Type: application/x-www-form-urlencoded\r\n",'content'=>'input=RIGRECONFIG','timeout'=>5]]);
    @file_get_contents("{$base}/TermInput?{$token}", false, $ctx);
    usleep(600000);
    $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>$hdr,'timeout'=>5]]);
    $out = @file_get_contents("{$base}/Node/OutputScreen.html?{$token}", false, $ctx);
    return $out && str_contains($out, 'Rigcontrol');
}

// ── Request dispatch ──────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $action;

switch ($action) {

    case 'auth':
        $pass = $input['password'] ?? '';
        if (empty($pass)) fail('Password required', 401);
        if ($pass !== $SYSOP_PASS) { sleep(1); fail('Invalid password', 401); }
        $_SESSION['vara_authed']    = true;
        $_SESSION['vara_auth_time'] = time();
        ok(['authenticated'=>true]);

    case 'check_auth':
        ok(['authenticated'=>isAuthed()]);

    case 'logout':
        $_SESSION['vara_authed'] = false;
        unset($_SESSION['vara_auth_time']);
        ok();

    // ── ALLOWLIST ─────────────────────────────────────────────────
    case 'list':
        requireAuth();
        $pdo  = tprfn_db();
        $rows = tprfn_query($pdo, "SELECT * FROM vara_allowed_stations ORDER BY callsign ASC");
        ok(['stations'=>$rows]);

    case 'add':
        requireAuth();
        $cs   = strtoupper(trim($input['callsign'] ?? ''));
        $name = trim($input['name'] ?? '');
        $note = trim($input['notes'] ?? '');
        if (!validCallsign($cs)) fail('Invalid callsign format');
        $pdo = tprfn_db();
        try {
            tprfn_execute($pdo,
                "INSERT INTO vara_allowed_stations (callsign,name,notes,added_by) VALUES (?,?,?,?)",
                [$cs, $name, $note, $CONFIG['station']['callsign'] ?? 'SYSOP']);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(),'Duplicate')) fail("$cs is already in the allowlist");
            fail('Database error: '.$e->getMessage());
        }
        ok(['callsign'=>$cs]);

    case 'toggle':
        requireAuth();
        $id = (int)($input['id'] ?? 0);
        if (!$id) fail('ID required');
        $pdo = tprfn_db();
        tprfn_execute($pdo,"UPDATE vara_allowed_stations SET active=1-active WHERE id=?",[$id]);
        $row = tprfn_query_one($pdo,"SELECT * FROM vara_allowed_stations WHERE id=?",[$id]);
        ok(['station'=>$row]);

    case 'delete':
        requireAuth();
        $id = (int)($input['id'] ?? 0);
        if (!$id) fail('ID required');
        $pdo = tprfn_db();
        tprfn_execute($pdo,"DELETE FROM vara_allowed_stations WHERE id=?",[$id]);
        ok();

    case 'request':
        $cs   = strtoupper(trim($input['callsign'] ?? ''));
        $name = trim($input['name'] ?? '');
        $msg  = trim($input['message'] ?? '');
        if (!validCallsign($cs)) fail('Invalid callsign format');
        $subject = "VARA HF Access Request from $cs";
        $body    = "Callsign: $cs\nName: $name\nMessage: $msg\n\nSent from BPQ Dashboard VARA terminal — ".date('Y-m-d H:i:s')." UTC";
        $sent = mail($SYSOP_EMAIL, $subject, $body, "From: dashboard@bpq.local\r\nReply-To: {$cs}@invalid");
        ok(['sent'=>$sent,'to'=>$SYSOP_EMAIL]);

    // ── FREQUENCY validate ────────────────────────────────────────
    case 'validate_freq':
        $mhz = (float)($input['mhz'] ?? 0);
        if ($mhz <= 0) fail('Frequency required');
        $result = validateAmateurFreq($mhz);
        if (!$result) fail("$mhz MHz is not in an ITU Region 2 HF data-authorized band");
        ok(['mhz'=>$mhz,'band'=>$result]);

    // ── FREQUENCY set ─────────────────────────────────────────────
    case 'set_freq':
        $mhz = (float)($input['mhz'] ?? 0);
        if ($mhz <= 0) fail('Frequency (MHz) required');
        $bandInfo = validateAmateurFreq($mhz);
        if (!$bandInfo) fail("$mhz MHz is not in an ITU Region 2 HF data-authorized band");
        $hz = (int)round($mhz * 1000000);

        // Get radio profile to know correct bpq32 mode string
        $profile = getRadioProfile();
        $bpqMode = $profile['bpq32_mode'];

        $cfgOk = rewriteRadio2((float)$hz, $BPQ32_CFG, $BPQ32_BACKUP, $bpqMode);
        $rigOk = false;
        if ($cfgOk) {
            $rigOk = sendRigReconfig($RIGRECONFIG_USER, $RIGRECONFIG_PASS);
        }
        ok(['mhz'=>$mhz,'hz'=>$hz,'band'=>$bandInfo,
            'cfg_rewritten'=>$cfgOk,'rigreconfig_sent'=>$rigOk,
            'radio_mode'=>$bpqMode,'profile'=>$profile['model']]);

    // ── FREQUENCY get ─────────────────────────────────────────────
    case 'get_freq':
        $hz = flrigGetFreq();
        if ($hz === false) fail("Could not reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}");
        $mhz = round($hz / 1000000, 6);
        ok(['hz'=>$hz,'mhz'=>$mhz,'band'=>validateAmateurFreq($mhz) ?: null]);

    // ── RIG MODEL — detect and return profile ─────────────────────
    case 'get_rig_model':
        // Get raw model from flrig
        $xcvrRaw = flrigCall('rig.get_xcvr', []);
        $xcvr    = '';
        if ($xcvrRaw) {
            if (preg_match('/<string>([^<]+)<\/string>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
            elseif (preg_match('/<value>([^<]+)<\/value>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
        }
        $profile = getRadioProfile();
        ok([
            'detected'   => $xcvr ?: 'unknown',
            'model'      => $profile['model'],
            'make'       => $profile['make'],
            'mode_label' => $profile['mode_label'],
            'note'       => $profile['note'],
            'source'     => !empty($CONFIG['radio']['model']) ? 'config' : ($xcvr ? 'auto-detect' : 'default'),
        ]);

    // ── RIG INFO — full status for rig-status.html ────────────────
    case 'get_rig_info':
        $hz = flrigGetFreq();
        if ($hz === false) fail("Cannot reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}");

        // Get radio profile for model name display
        $profile = getRadioProfile();

        // Mode
        $modeRaw = flrigCall('rig.get_mode', []);
        $mode = $profile['mode_label'];
        if ($modeRaw && preg_match('/<string>([^<]+)<\/string>/', $modeRaw, $mm)) $mode = trim($mm[1]);
        elseif ($modeRaw && preg_match('/<value>([^<]+)<\/value>/', $modeRaw, $mm)) $mode = trim($mm[1]);

        // S-meter
        $smeterRaw = flrigCall('rig.get_smeter', []);
        $smeter = null;
        if ($smeterRaw && preg_match('/<value>(\d+)<\/value>/', $smeterRaw, $mm)) $smeter = (int)$mm[1];

        // Power meter
        $pwrRaw = flrigCall('rig.get_pwrmeter', []);
        $pwrmeter = null;
        if ($pwrRaw && preg_match('/<value>(\d+)<\/value>/', $pwrRaw, $mm)) $pwrmeter = (int)$mm[1];

        // SWR meter
        $swrRaw = flrigCall('rig.get_swrmeter', []);
        $swrmeter = null;
        if ($swrRaw && preg_match('/<value>(\d+)<\/value>/', $swrRaw, $mm)) $swrmeter = (int)$mm[1];

        $isTx = ($pwrmeter !== null && $pwrmeter > 0);
        ok([
            'hz'        => (int)$hz,
            'mhz'       => round($hz / 1000000, 6),
            'mode'      => $mode,
            'smeter'    => $smeter,
            'pwrmeter'  => $pwrmeter,
            'swrmeter'  => $swrmeter,
            'tx'        => $isTx,
            'rig_model' => $profile['model'],
            'rig_make'  => $profile['make'],
        ]);

    // ── RESTORE RADIO 2 scan ──────────────────────────────────────
    case 'restore_freq':
        $restored = restoreRadio2($BPQ32_CFG, $BPQ32_BACKUP);
        $rigOk    = false;
        if ($restored) $rigOk = sendRigReconfig($RIGRECONFIG_USER, $RIGRECONFIG_PASS);
        ok(['restored'=>$restored,'rigreconfig_sent'=>$rigOk]);

    // ── DAEMON STATUS ─────────────────────────────────────────────
    case 'status':
        requireAuth();
        if (!file_exists($DAEMON_FILE)) ok(['running'=>false,'message'=>'Daemon not started']);
        $data = json_decode(file_get_contents($DAEMON_FILE), true);
        $age  = time() - ($data['updated_ts'] ?? 0);
        $data['stale'] = $age > 60;
        ok($data);

    default:
        fail('Unknown action', 404);
}
