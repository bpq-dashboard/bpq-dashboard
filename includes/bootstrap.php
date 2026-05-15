<?php
/**
 * BPQ Dashboard Bootstrap
 * Version: 1.3.2
 * 
 * Loads configuration, applies security settings, provides helper functions.
 * Include this at the top of all PHP API files.
 * 
 * Supports both:
 *   - config.php (v1.3+ format - returns array)
 *   - bbs-config.php (v1.2 format - sets $config variable)
 * 
 * Set $SKIP_BBS_CHECK = true BEFORE requiring this file
 * to skip the BBS password validation (for data-only endpoints).
 */

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === 'bootstrap.php') {
    http_response_code(403);
    die('Direct access not allowed');
}

// =========================================================================
// LOAD CONFIGURATION - Support both old and new formats
// =========================================================================

$configFile = dirname(__DIR__) . '/config.php';
$oldConfigFile = dirname(__DIR__) . '/bbs-config.php';

$CONFIG = null;

// Try new config.php first
if (file_exists($configFile)) {
    $CONFIG = require $configFile;
}
// Fall back to old bbs-config.php format
elseif (file_exists($oldConfigFile)) {
    require_once $oldConfigFile;
    
    // Convert old $config format to new $CONFIG format
    if (isset($config)) {
        $CONFIG = [
            'security_mode' => 'local',
            'station' => [
                'callsign' => $config['callsign'] ?? 'N0CALL',
            ],
            'bbs' => [
                'host'    => $config['bbs_host'] ?? 'localhost',
                'port'    => $config['bbs_port'] ?? 8010,
                'user'    => $config['bbs_user'] ?? 'SYSOP',
                'pass'    => $config['bbs_pass'] ?? '',
                'alias'   => $config['bbs_alias'] ?? 'bbs',
                'timeout' => $config['timeout'] ?? 30,
            ],
            'paths' => [
                'logs' => './logs/',
                'data' => './data/',
            ],
            'logs' => [
                'vara_file' => '',  // Auto-detected
            ],
            'features' => [
                'bbs_read'      => true,
                'bbs_write'     => true,
                'bbs_bulletins' => true,
                'nws_alerts'    => true,
                'nws_post'      => true,
                'test_endpoint' => false,
            ],
            'rate_limit' => [
                'enabled'             => false,
                'requests_per_minute' => 30,
                'burst_limit'         => 10,
            ],
            'cors' => [
                'allow_all'       => true,
                'allowed_origins' => [],
            ],
            'ui' => [
                'default_msg_count' => $config['default_count'] ?? 20,
                'max_msg_count'     => $config['max_count'] ?? 100,
            ],
            'logging' => [
                'enabled'  => true,
                'file'     => $config['log_file'] ?? './logs/dashboard.log',
                'max_size' => 5242880,
                'level'    => 'info',
            ],
        ];
    }
}

// No config found — use safe defaults (data endpoints can still work)
if ($CONFIG === null) {
    if (!isset($SKIP_BBS_CHECK) || !$SKIP_BBS_CHECK) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'Configuration file not found',
            'help' => 'Copy bbs-config.php.example to bbs-config.php and edit it'
        ]));
    }
    // Minimal config for data-only endpoints
    $CONFIG = [
        'security_mode' => 'local',
        'paths' => ['logs' => './logs/'],
        'logs'  => ['vara_file' => ''],
        'logging' => ['enabled' => false],
        'rate_limit' => ['enabled' => false],
        'cors' => ['allow_all' => true],
    ];
}

// =========================================================================
// VALIDATE BBS PASSWORD (skip for data-only endpoints)
// =========================================================================

if (!isset($SKIP_BBS_CHECK) || !$SKIP_BBS_CHECK) {
    if (!isset($CONFIG['bbs']['pass']) || 
        $CONFIG['bbs']['pass'] === 'CHANGEME' || 
        $CONFIG['bbs']['pass'] === 'yourpassword' ||
        $CONFIG['bbs']['pass'] === 'password' ||
        strlen($CONFIG['bbs']['pass'] ?? '') < 4) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'BBS password not configured',
            'help' => 'Edit bbs-config.php and set your BBS password'
        ]));
    }
}

// =========================================================================
// SECURITY MODE
// =========================================================================

$SECURITY_MODE = $CONFIG['security_mode'] ?? 'local';
$IS_PUBLIC_MODE = ($SECURITY_MODE === 'public');

if ($IS_PUBLIC_MODE) {
    $CONFIG['features']['bbs_write'] = false;
    $CONFIG['features']['nws_post'] = false;
    $CONFIG['features']['test_endpoint'] = false;
    $CONFIG['rate_limit']['enabled'] = true;
    $CONFIG['cors']['allow_all'] = false;
}

// =========================================================================
// SECURITY HEADERS
// =========================================================================

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// =========================================================================
// CORS
// =========================================================================

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($CONFIG['cors']['allow_all'] ?? true) {
    header('Access-Control-Allow-Origin: *');
} elseif (!empty($origin) && in_array($origin, $CONFIG['cors']['allowed_origins'] ?? [])) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =========================================================================
// RATE LIMITING
// =========================================================================

if ($CONFIG['rate_limit']['enabled'] ?? false) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    
    $rateWindow = floor(time() / 60);
    $rateKey = 'rate_' . $rateWindow;
    $_SESSION[$rateKey] = ($_SESSION[$rateKey] ?? 0) + 1;
    
    // Clean old keys
    foreach ($_SESSION as $key => $value) {
        if (strpos($key, 'rate_') === 0 && intval(str_replace('rate_', '', $key)) < $rateWindow - 1)
            unset($_SESSION[$key]);
    }
    
    if ($_SESSION[$rateKey] > ($CONFIG['rate_limit']['requests_per_minute'] ?? 30)) {
        http_response_code(429);
        header('Retry-After: 60');
        die(json_encode(['error' => 'Rate limit exceeded', 'retry_after' => 60]));
    }
}

// =========================================================================
// HELPER FUNCTIONS
// =========================================================================

function getConfig($section = null, $key = null, $default = null) {
    global $CONFIG;
    if ($section === null) return $CONFIG;
    if (!isset($CONFIG[$section])) return $default;
    if ($key === null) return $CONFIG[$section];
    return $CONFIG[$section][$key] ?? $default;
}

function isFeatureEnabled($feature) {
    global $CONFIG;
    return $CONFIG['features'][$feature] ?? true;
}

function isPublicMode() {
    global $IS_PUBLIC_MODE;
    return $IS_PUBLIC_MODE;
}

function dashboardLog($level, $message, $context = []) {
    global $CONFIG;
    if (!($CONFIG['logging']['enabled'] ?? false)) return;
    
    $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    $configLevel = $levels[$CONFIG['logging']['level'] ?? 'info'] ?? 1;
    if (($levels[$level] ?? 1) < $configLevel) return;
    
    $logFile = $CONFIG['logging']['file'] ?? './logs/dashboard.log';
    $maxSize = $CONFIG['logging']['max_size'] ?? 5242880;
    if (file_exists($logFile) && filesize($logFile) > $maxSize) @rename($logFile, $logFile . '.old');
    
    $ts = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $ctx = $context ? ' ' . json_encode($context) : '';
    @file_put_contents($logFile, "[$ts] [$level] [$ip] $message$ctx\n", FILE_APPEND | LOCK_EX);
}

function apiError($message, $code = 400) {
    http_response_code($code);
    die(json_encode(['success' => false, 'error' => $message]));
}
