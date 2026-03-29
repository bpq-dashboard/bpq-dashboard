<?php
/**
 * BPQ Dashboard Settings API
 * Version: 1.0.0
 *
 * Read/write settings.json with BBS password authentication for writes.
 *
 * Endpoints (all via POST with JSON body):
 *   action=get           — Return full settings.json (auth required)
 *   action=get_callsign  — Return callsign only (no auth — for nav bar display)
 *   action=save          — Write settings.json (auth required)
 *   action=detect        — Auto-detect platform paths (auth required)
 *   action=auth          — Test BBS credentials (returns token for this session)
 *   action=import_linmail — Parse linmail.cfg and return partner data (auth required)
 */

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');

// ============================================================================
// SETTINGS FILE
// ============================================================================

$SETTINGS_FILE = __DIR__ . '/data/settings.json';

// Default settings schema — used when settings.json doesn't exist yet
$DEFAULTS = [
    'station' => [
        'callsign' => 'N0CALL',
        'grid'     => '',
        'lat'      => 0.0,
        'lon'      => 0.0,
        'notes'    => '',
    ],
    'bbs' => [
        'host'    => 'localhost',
        'port'    => 8010,
        'user'    => 'N0CALL',
        'pass'    => '',
        'alias'   => 'bbs',
        'timeout' => 30,
    ],
    'partners' => [],
    'prop_scheduler' => [
        'enabled'          => false,
        'interval_hours'   => 48,
        'prop_weight'      => 0.25,
        'sn_weight'        => 0.40,
        'success_weight'   => 0.35,
        'conserve_mode'    => true,
        'conserve_threshold' => 80,
        'lookback_days'    => 14,
        'min_sessions'     => 3,
    ],
    'storm_monitor' => [
        'enabled'            => false,
        'kp_storm_threshold' => 5,
        'kp_restore_threshold' => 3,
        'consecutive_calm'   => 2,
    ],
    'paths' => [
        'linmail_cfg'  => '',
        'bpq_stop_cmd' => '',
        'bpq_start_cmd' => '',
        'log_dir'      => '',
        'backup_dir'   => '',
    ],
    '_version' => '1.0.0',
    '_updated' => '',
];

// ============================================================================
// REQUEST DISPATCH
// ============================================================================

$input = json_decode(file_get_contents('php://input'), true) ?? [];
global $input;
$action = $input['action'] ?? ($_GET['action'] ?? 'get');

switch ($action) {
    case 'get':
        actionGet();
        break;
    case 'get_callsign':
        actionGetCallsign();
        break;
    case 'auth':
        actionAuth($input);
        break;
    case 'save':
        actionSave($input);
        break;
    case 'detect':
        actionDetect();
        break;
    case 'import_linmail':
        actionImportLinmail($input);
        break;
    default:
        jsonError('Unknown action: ' . htmlspecialchars($action), 400);
}

// ============================================================================
// ACTION: GET — Return full settings (auth required)
// ============================================================================
function actionGet() {
    global $input;
    requireAuth($input);

    $settings = loadSettings();

    // Mask BBS password in response — never send plaintext password to browser
    $safe = $settings;
    $safe['bbs']['pass'] = strlen($settings['bbs']['pass'] ?? '') > 0 ? '••••••••' : '';

    jsonOk($safe);
}

// ============================================================================
// ACTION: GET_CALLSIGN — Return callsign only (public — for nav bar display)
// ============================================================================
function actionGetCallsign() {
    $settings = loadSettings();
    jsonOk(['callsign' => $settings['station']['callsign'] ?? 'N0CALL']);
}

// ============================================================================
// ACTION: AUTH — Verify BBS password
// ============================================================================
function actionAuth($input) {
    $password = $input['password'] ?? '';
    if (empty($password)) {
        jsonError('Password required', 401);
    }

    if (verifyBbsPassword($password)) {
        // Store in session for subsequent save calls
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['settings_authed'] = true;
        $_SESSION['settings_auth_time'] = time();
        jsonOk(['authenticated' => true]);
    } else {
        jsonError('Invalid password', 401);
    }
}

// ============================================================================
// ACTION: SAVE — Write settings.json (auth required)
// ============================================================================
function actionSave($input) {
    global $SETTINGS_FILE, $DEFAULTS;

    requireAuth($input);

    $new = $input['settings'] ?? null;
    if (!is_array($new)) {
        jsonError('Missing settings payload', 400);
    }

    // Deep-merge with defaults to ensure all keys present
    $merged = array_replace_recursive($DEFAULTS, loadSettings(), $new);

    // Sanitize / validate
    $merged['station']['callsign'] = strtoupper(trim($merged['station']['callsign'] ?? 'N0CALL'));
    $merged['station']['lat'] = (float)($merged['station']['lat'] ?? 0);
    $merged['station']['lon'] = (float)($merged['station']['lon'] ?? 0);
    $merged['bbs']['port'] = (int)($merged['bbs']['port'] ?? 8010);
    $merged['bbs']['timeout'] = (int)($merged['bbs']['timeout'] ?? 30);

    // Don't overwrite password if sentinel was submitted
    if (($merged['bbs']['pass'] ?? '') === '••••••••') {
        $existing = loadSettings();
        $merged['bbs']['pass'] = $existing['bbs']['pass'] ?? '';
    }

    // Validate partners array
    if (isset($merged['partners']) && is_array($merged['partners'])) {
        foreach ($merged['partners'] as &$p) {
            $p['call']    = strtoupper(trim($p['call'] ?? ''));
            $p['lat']     = (float)($p['lat'] ?? 0);
            $p['lon']     = (float)($p['lon'] ?? 0);
            $p['attach_port'] = (int)($p['attach_port'] ?? 3);
            $p['fixed_schedule'] = (bool)($p['fixed_schedule'] ?? false);
        }
        unset($p);
    }

    // Clamp prop scheduler weights 0–1
    $ps = &$merged['prop_scheduler'];
    foreach (['prop_weight', 'sn_weight', 'success_weight'] as $k) {
        $ps[$k] = max(0.0, min(1.0, (float)($ps[$k] ?? 0.33)));
    }

    $merged['_version'] = '1.0.0';
    $merged['_updated'] = gmdate('Y-m-d\TH:i:s\Z');

    // Ensure data/ directory exists
    $dataDir = dirname($SETTINGS_FILE);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0750, true);
    }

    $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($SETTINGS_FILE, $json, LOCK_EX) === false) {
        jsonError('Failed to write settings.json — check permissions on data/', 500);
    }

    jsonOk(['saved' => true, 'updated' => $merged['_updated']]);
}

// ============================================================================
// ACTION: DETECT — Auto-detect platform defaults (auth required)
// ============================================================================
function actionDetect() {
    global $input;
    requireAuth($input);

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $webRoot   = dirname(__DIR__);   // parent of dashboard root

    if ($isWindows) {
        $appdata = getenv('APPDATA') ?: 'C:\\Users\\SYSOP\\AppData\\Roaming';
        $paths = [
            'linmail_cfg'   => $appdata . '\\BPQ32\\linmail.cfg',
            'bpq_stop_cmd'  => 'net stop BPQ32',
            'bpq_start_cmd' => 'net start BPQ32',
            'log_dir'       => 'C:\\UniServerZ\\www\\bpq\\logs',
            'backup_dir'    => 'C:\\UniServerZ\\www\\bpq\\scripts\\prop-backups',
        ];
    } else {
        // Try common Linux linmail.cfg locations
        $linmailCandidates = [
            '/home/tony/linbpq/linmail.cfg',
            '/home/' . get_current_user() . '/linbpq/linmail.cfg',
            '/opt/bpq/linmail.cfg',
            '/var/lib/bpq/linmail.cfg',
        ];
        $linmail = '';
        foreach ($linmailCandidates as $c) {
            if (file_exists($c)) { $linmail = $c; break; }
        }

        $paths = [
            'linmail_cfg'   => $linmail ?: '/home/SYSOP/linbpq/linmail.cfg',
            'bpq_stop_cmd'  => 'systemctl stop bpq',
            'bpq_start_cmd' => 'systemctl start bpq',
            'log_dir'       => __DIR__ . '/logs',
            'backup_dir'    => __DIR__ . '/scripts/prop-backups',
        ];
    }

    jsonOk([
        'platform' => $isWindows ? 'windows' : 'linux',
        'paths'    => $paths,
    ]);
}

// ============================================================================
// ACTION: IMPORT_LINMAIL — Parse linmail.cfg → partner list
// ============================================================================
function actionImportLinmail($input) {
    requireAuth($input);

    $settings = loadSettings();
    $cfgPath  = $input['path'] ?? $settings['paths']['linmail_cfg'] ?? '';

    if (empty($cfgPath) || !file_exists($cfgPath)) {
        jsonError('linmail.cfg not found at: ' . htmlspecialchars($cfgPath), 404);
    }

    $content = file_get_contents($cfgPath);
    $partners = parseLinmailPartners($content);

    jsonOk(['partners' => $partners, 'count' => count($partners)]);
}

// ============================================================================
// HELPERS
// ============================================================================

function loadSettings() {
    global $SETTINGS_FILE, $DEFAULTS;
    if (!file_exists($SETTINGS_FILE)) {
        return $DEFAULTS;
    }
    $raw = file_get_contents($SETTINGS_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) return $DEFAULTS;
    return array_replace_recursive($DEFAULTS, $data);
}

function requireAuth($input) {
    // Check session first
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['settings_authed']) && (time() - ($_SESSION['settings_auth_time'] ?? 0)) < 3600) {
        return; // Still valid within 1 hour
    }

    // Accept inline password in request
    $password = $input['password'] ?? '';
    if (!empty($password) && verifyBbsPassword($password)) {
        $_SESSION['settings_authed'] = true;
        $_SESSION['settings_auth_time'] = time();
        return;
    }

    jsonError('Authentication required', 401);
}

function verifyBbsPassword($password) {
    // Re-use existing BBS auth from bootstrap if available
    if (function_exists('verifyBbsAuth')) {
        return verifyBbsAuth($password);
    }

    // Fallback: compare against bbs.pass in settings or config
    $settings = loadSettings();
    $stored   = $settings['bbs']['pass'] ?? '';

    // Also try $CONFIG from bootstrap
    global $CONFIG;
    if (!empty($CONFIG['bbs']['pass'])) {
        $stored = $CONFIG['bbs']['pass'];
    }

    return !empty($stored) && $password === $stored;
}

/**
 * Parse linmail.cfg and extract BBS forwarding partner entries.
 * Returns array of partner objects matching settings.json schema.
 */
function parseLinmailPartners($content) {
    $partners = [];
    $lines    = explode("\n", $content);

    $current = null;
    $inBBS   = false;

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);

        // Detect BBS section start
        if (preg_match('/^\[BBS\s+(\S+)\]/i', $line, $m)) {
            if ($current !== null) {
                $partners[] = $current;
            }
            $callFull = strtoupper($m[1]);
            // Split call and SSID
            [$call, $ssid] = array_pad(explode('-', $callFull, 2), 2, '');
            $current = [
                'call'           => $call,
                'connect_call'   => $callFull,
                'name'           => '',
                'location'       => '',
                'lat'            => 0.0,
                'lon'            => 0.0,
                'attach_port'    => 3,
                'fixed_schedule' => false,
                'bands'          => [],
                '_connect_script' => '',
            ];
            $inBBS = true;
            continue;
        }

        if (!$inBBS || $current === null) continue;

        // End of this section
        if (preg_match('/^\[/', $line) && !preg_match('/^\[BBS\s+/i', $line)) {
            $partners[] = $current;
            $current    = null;
            $inBBS      = false;
            continue;
        }

        // ConnectScript line — parse for bands/frequencies
        if (preg_match('/^ConnectScript\s*=\s*(.+)/i', $line, $m)) {
            $script = trim($m[1]);
            $current['_connect_script'] = $script;

            // Extract RADIO freq entries
            // e.g. RADIO 3.596000 PKT-U  or  RADIO 7.103200
            preg_match_all('/RADIO\s+([\d.]+)(?:\s+(\S+))?/i', $script, $rm, PREG_SET_ORDER);
            foreach ($rm as $r) {
                $freq = (float)$r[1];
                $mode = strtoupper($r[2] ?? '');
                $band = freqToBand($freq);
                if ($band && !isset($current['bands'][$band])) {
                    $current['bands'][$band] = [
                        'freq' => number_format($freq, 6, '.', ''),
                        'mode' => $mode,
                    ];
                }
            }

            // Extract port from ATTACH N
            if (preg_match('/ATTACH\s+(\d+)/i', $script, $am)) {
                $current['attach_port'] = (int)$am[1];
            }
        }

        // Comment/description hints
        if (preg_match('/^;?\s*([A-Z][a-z].*)/i', $line, $m) && empty($current['name'])) {
            $hint = trim($m[1], '; ');
            if (strlen($hint) < 40 && !preg_match('/=/', $hint)) {
                $current['name'] = $hint;
            }
        }
    }

    // Flush last partner
    if ($current !== null) {
        $partners[] = $current;
    }

    return $partners;
}

function freqToBand(float $freq): string {
    if ($freq >= 3.5  && $freq <= 4.0)   return '80m';
    if ($freq >= 7.0  && $freq <= 7.3)   return '40m';
    if ($freq >= 10.1 && $freq <= 10.15) return '30m';
    if ($freq >= 14.0 && $freq <= 14.35) return '20m';
    if ($freq >= 18.0 && $freq <= 18.17) return '17m';
    if ($freq >= 21.0 && $freq <= 21.45) return '15m';
    if ($freq >= 28.0 && $freq <= 29.7)  return '10m';
    if ($freq >= 50.0 && $freq <= 54.0)  return '6m';
    if ($freq >= 144  && $freq <= 148)   return '2m';
    return '';
}

function jsonOk($data) {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
