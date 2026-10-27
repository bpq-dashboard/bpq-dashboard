<?php
/**
 * partners-api.php — read / write <web root>/data/partners.json
 * Lives in: <web root>/admin/partners-api.php  (behind the /admin/ login)
 *
 * Used by the Forwarding Partners editor on admin/bpq-maintenance.html.
 * partners.json is read by scripts/prop-scheduler.py (and its storm logic).
 *
 * Two locks: the admin login on /admin/ (checked by the web server and by
 * PHP through includes/web-login.php), plus the BBS password from
 * config.php ('bbs' => ['pass' => ...]) sent in the X-BBS-Password header.
 * 5 wrong passwords from one address in 15 minutes → 429 (2026-10-25).
 * The password is never accepted in the URL (URLs end up in access logs).
 *
 * Actions:
 *   GET  ?action=load                 → partners.json content
 *   POST ?action=save   body=JSON     → validate, back up, write partners.json
 *   GET  ?action=validate             → schema check on the current file
 *
 * v1.5.13 (2026-10-03): paths fixed for the /admin/ folder (they pointed at
 * admin/data/ and admin/bpqdash-config.php, which don't exist, so the
 * editor couldn't find partners.json and the password check never ran).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$WEB_ROOT = dirname(__DIR__);

function ok($data)  { echo json_encode(array_merge(['ok' => true],  $data), JSON_PRETTY_PRINT); exit; }
function err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── Auth ───────────────────────────────────────────────────────────────────
// First lock: the admin login, checked by PHP too (2026-10-25: this was the
// only admin API that trusted the web server alone).
require_once $WEB_ROOT . '/includes/web-login.php';
bpq_require_web_login('The Partners editor');

// Second lock: the BBS password from config.php, in the X-BBS-Password header.
$bbsPassword = '';
if (is_readable($WEB_ROOT . '/config.php')) {
    $cfg = include $WEB_ROOT . '/config.php';
    if (is_array($cfg)) $bbsPassword = (string)($cfg['bbs']['pass'] ?? '');
}
// Older installs kept it in bpqdash-config.php
if ($bbsPassword === '' && is_readable($WEB_ROOT . '/bpqdash-config.php')) {
    $old = include $WEB_ROOT . '/bpqdash-config.php';
    if (is_array($old)) $bbsPassword = (string)($old['bbs_password'] ?? $old['password'] ?? '');
}
if ($bbsPassword === '' || $bbsPassword === 'CHANGE_THIS_PASSWORD') {
    err('BBS password is not set in config.php (bbs => pass). The editor stays locked until it is.', 503);
}
// Wrong-password throttle (2026-10-25, same rule as settings-api.php):
// 5 wrong passwords from one address within 15 minutes → 429 until the
// 15 minutes are up.
$failDir  = $WEB_ROOT . '/cache/settings-auth';
if (!is_dir($failDir)) @mkdir($failDir, 0750, true);
$failFile = $failDir . '/partners-' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'cli')) . '.json';
$fail = is_readable($failFile) ? json_decode((string)@file_get_contents($failFile), true) : null;
if (!is_array($fail) || time() - (int)($fail['first'] ?? 0) >= 900) $fail = ['count' => 0, 'first' => time()];
if ($fail['count'] >= 5) {
    $wait = max(1, (int)ceil((900 - (time() - $fail['first'])) / 60));
    header('Retry-After: ' . ($wait * 60));
    err("Too many wrong passwords. Try again in $wait minute" . ($wait === 1 ? '' : 's') . '.', 429);
}
$provided = $_SERVER['HTTP_X_BBS_PASSWORD'] ?? '';
if (!is_string($provided) || $provided === '') {
    err('BBS password required (X-BBS-Password header)', 401);   // not counted: the Maintenance status box probes this
}
if (!hash_equals($bbsPassword, $provided)) {
    $fail['count']++;
    @file_put_contents($failFile, json_encode($fail), LOCK_EX);
    sleep(1);
    err('Wrong BBS password', 401);
}
@unlink($failFile);

// ── Partners file path ─────────────────────────────────────────────────────
$PARTNERS_FILE = $WEB_ROOT . '/data/partners.json';
$BACKUP_DIR    = $WEB_ROOT . '/data/backups';

function validate_partners(array $data): array {
    $errors = [];
    if (!isset($data['partners']) || !is_array($data['partners'])) {
        $errors[] = 'Missing or invalid "partners" array';
        return $errors;
    }
    $seen = [];
    foreach ($data['partners'] as $i => $p) {
        $n = $i + 1;
        if (!is_array($p)) { $errors[] = "Partner #$n: not an object"; continue; }
        $call = strtoupper(trim((string)($p['call'] ?? '')));
        if (!$call)              $errors[] = "Partner #$n: missing call";
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
        err('partners.json not found at ' . $PARTNERS_FILE . ' — create it with: echo \'{"partners":[]}\' | sudo -u www-data tee ' . $PARTNERS_FILE, 404);
    }
    $raw = file_get_contents($PARTNERS_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) err('partners.json is not valid JSON: ' . json_last_error_msg());
    if (!isset($data['partners']) || !is_array($data['partners'])) $data['partners'] = [];
    ok([
        'data'     => $data,
        'modified' => gmdate('Y-m-d H:i:s', filemtime($PARTNERS_FILE)) . ' UTC',
        'size'     => filesize($PARTNERS_FILE),
    ]);
}

// ── SAVE ───────────────────────────────────────────────────────────────────
if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST required for save', 405);

    $body = file_get_contents('php://input');
    if (!$body) err('Empty request body');

    $data = json_decode($body, true);
    if (!is_array($data)) err('Invalid JSON: ' . json_last_error_msg());

    $errors = validate_partners($data);
    if ($errors) err('Validation failed: ' . implode('; ', $errors));

    // Back up the existing file first
    $backupFile = null;
    if (file_exists($PARTNERS_FILE)) {
        if (!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0750, true);
        $backupFile = $BACKUP_DIR . '/partners-' . gmdate('Ymd-His') . '.json';
        if (!@copy($PARTNERS_FILE, $backupFile)) err('Could not write a backup to ' . $BACKUP_DIR . ' — nothing was saved. Check permissions.', 500);
    }

    // Write atomically: temp file (dot-name, so never served) then rename
    // Encode from an object decode so empty {} (e.g. "bands": {}) stays an
    // object; an array decode would write [] and break bands.items() in Python.
    $json = json_encode(json_decode($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp  = dirname($PARTNERS_FILE) . '/.partners.json.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $PARTNERS_FILE)) {
        @unlink($tmp);
        err('Failed to write partners.json — check permissions on ' . dirname($PARTNERS_FILE), 500);
    }

    $active = count(array_filter($data['partners'], fn($p) => $p['active'] ?? true));
    $total  = count($data['partners']);
    ok([
        'message'  => "Saved $total partners ($active active)",
        'partners' => $total,
        'active'   => $active,
        'backup'   => $backupFile,
    ]);
}

// ── VALIDATE ───────────────────────────────────────────────────────────────
if ($action === 'validate') {
    if (!file_exists($PARTNERS_FILE)) err('partners.json not found', 404);
    $data = json_decode(file_get_contents($PARTNERS_FILE), true);
    if (!is_array($data)) err('Invalid JSON: ' . json_last_error_msg());
    $errors = validate_partners($data);
    ok([
        'valid'    => empty($errors),
        'errors'   => $errors,
        'partners' => count($data['partners'] ?? []),
        'active'   => count(array_filter($data['partners'] ?? [], fn($p) => $p['active'] ?? true)),
    ]);
}

err('Unknown action: ' . htmlspecialchars((string)$action));
