<?php
/**
 * Version: 1.5.5
 * partners-api.php — Read / write /var/www/bpqdash/data/partners.json
 * Deploy to: /var/www/bpqdash/partners-api.php
 *
 * Actions:
 *   GET  ?action=load&password=...          → returns partners.json content
 *   POST ?action=save&password=...  body=JSON → validates + writes partners.json
 *   GET  ?action=validate&password=...      → returns schema check on current file
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Auth ───────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/bpqdash-config.php';
$bbsPassword = null;
if (file_exists($configFile)) {
    $cfg = include $configFile;
    $bbsPassword = $cfg['bbs_password'] ?? $cfg['password'] ?? null;
}
$providedPassword = $_GET['password'] ?? $_SERVER['HTTP_X_BBS_PASSWORD'] ?? null;
if ($bbsPassword && $providedPassword !== $bbsPassword) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Partners file path ─────────────────────────────────────────────────────
$PARTNERS_FILE = __DIR__ . '/data/partners.json';
$BACKUP_DIR    = __DIR__ . '/data/backups';

// ── Helpers ────────────────────────────────────────────────────────────────
function ok($data)  { echo json_encode(array_merge(['ok' => true],  $data), JSON_PRETTY_PRINT); exit; }
function err($msg)  { http_response_code(400); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

function validate_partners(array $data): array {
    $errors = [];
    if (!isset($data['partners']) || !is_array($data['partners'])) {
        $errors[] = 'Missing or invalid "partners" array';
        return $errors;
    }
    $seen = [];
    foreach ($data['partners'] as $i => $p) {
        $n = $i + 1;
        $call = strtoupper(trim($p['call'] ?? ''));
        if (!$call)           $errors[] = "Partner #$n: missing call";
        if (isset($seen[$call])) $errors[] = "Duplicate callsign: $call";
        $seen[$call] = true;
        if (!isset($p['lat']) || !is_numeric($p['lat'])) $errors[] = "$call: invalid lat";
        if (!isset($p['lon']) || !is_numeric($p['lon'])) $errors[] = "$call: invalid lon";
        if (!isset($p['bands']) || !is_array($p['bands']))
            $errors[] = "$call: missing bands";
        if (!isset($p['distance_mi']) || !is_numeric($p['distance_mi']))
            $errors[] = "$call: missing distance_mi";
        if (isset($p['storm']) && isset($p['storm']['suspend_kp'])) {
            $kp = $p['storm']['suspend_kp'];
            if ($kp !== null && (!is_numeric($kp) || $kp < 1 || $kp > 9))
                $errors[] = "$call: suspend_kp must be 1-9 or null";
        }
    }
    return $errors;
}

$action = $_GET['action'] ?? 'load';

// ── LOAD ───────────────────────────────────────────────────────────────────
if ($action === 'load') {
    if (!file_exists($PARTNERS_FILE)) {
        err('partners.json not found at ' . $PARTNERS_FILE);
    }
    $raw = file_get_contents($PARTNERS_FILE);
    $data = json_decode($raw, true);
    if ($data === null) err('partners.json is not valid JSON: ' . json_last_error_msg());
    ok([
        'data'     => $data,
        'raw'      => $raw,
        'modified' => date('Y-m-d H:i:s', filemtime($PARTNERS_FILE)) . ' UTC',
        'size'     => filesize($PARTNERS_FILE),
    ]);
}

// ── SAVE ───────────────────────────────────────────────────────────────────
if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST required for save');

    $body = file_get_contents('php://input');
    if (!$body) err('Empty request body');

    $data = json_decode($body, true);
    if ($data === null) err('Invalid JSON: ' . json_last_error_msg());

    // Validate
    $errors = validate_partners($data);
    if ($errors) err('Validation failed: ' . implode('; ', $errors));

    // Backup existing file
    if (file_exists($PARTNERS_FILE)) {
        if (!is_dir($BACKUP_DIR)) mkdir($BACKUP_DIR, 0755, true);
        $backupFile = $BACKUP_DIR . '/partners-' . date('Ymd-His') . '.json';
        copy($PARTNERS_FILE, $backupFile);
    }

    // Write new file with pretty print
    $written = file_put_contents(
        $PARTNERS_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($written === false) err('Failed to write partners.json — check file permissions');

    $active  = count(array_filter($data['partners'], fn($p) => $p['active'] ?? true));
    $total   = count($data['partners']);
    ok([
        'message'  => "Saved $total partners ($active active)",
        'partners' => $total,
        'active'   => $active,
        'backup'   => $backupFile ?? null,
    ]);
}

// ── VALIDATE ───────────────────────────────────────────────────────────────
if ($action === 'validate') {
    if (!file_exists($PARTNERS_FILE)) err('partners.json not found');
    $data = json_decode(file_get_contents($PARTNERS_FILE), true);
    if ($data === null) err('Invalid JSON: ' . json_last_error_msg());
    $errors = validate_partners($data);
    ok([
        'valid'    => empty($errors),
        'errors'   => $errors,
        'partners' => count($data['partners'] ?? []),
        'active'   => count(array_filter($data['partners'] ?? [], fn($p) => $p['active'] ?? true)),
    ]);
}

err('Unknown action: ' . htmlspecialchars($action));
