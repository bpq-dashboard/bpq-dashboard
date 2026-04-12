<?php
/**
 * BPQ Dashboard Health Check
 * 
 * Open this in your browser to diagnose installation issues:
 *   http://localhost/bpq/health-check.php
 * 
 * Checks: PHP version, extensions, file permissions, directories,
 *         config file, API endpoints, log files, BBS connectivity.
 * 
 * Safe to leave in place — does not expose passwords or sensitive data.
 */

// Suppress errors in output — we'll catch them ourselves
error_reporting(E_ALL);
ini_set('display_errors', 0);

$checks = [];
$warnings = [];
$baseDir = __DIR__;

// ========================================================================
// Helper functions
// ========================================================================

function check($name, $pass, $detail = '', $fix = '') {
    global $checks;
    $checks[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail, 'fix' => $fix];
}

function warn($name, $detail, $fix = '') {
    global $warnings;
    $warnings[] = ['name' => $name, 'detail' => $detail, 'fix' => $fix];
}

function testUrl($path) {
    // Build URL from current request
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = dirname($_SERVER['SCRIPT_NAME']);
    $url = "$scheme://$host$dir/$path";
    
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $m);
        $status = intval($m[0] ?? 0);
    }
    return ['status' => $status, 'body' => $body ?: ''];
}

// ========================================================================
// 1. PHP Environment
// ========================================================================

// PHP version
$phpVer = phpversion();
$phpOk = version_compare($phpVer, '7.4.0', '>=');
check('PHP Version', $phpOk, $phpVer, 'PHP 7.4+ required. Update PHP or install a newer version.');

// Required extensions
$requiredExts = ['sockets', 'json', 'date'];
foreach ($requiredExts as $ext) {
    $loaded = extension_loaded($ext);
    check("PHP Extension: $ext", $loaded, $loaded ? 'loaded' : 'missing',
        "Install: sudo apt install php-$ext (Linux) or enable in php.ini (Windows)");
}

// Optional but recommended
$optionalExts = ['curl'];
foreach ($optionalExts as $ext) {
    if (!extension_loaded($ext)) {
        warn("PHP Extension: $ext (optional)", 'Not loaded — some features may be limited',
            "Install: sudo apt install php-$ext (Linux) or enable in php.ini (Windows)");
    }
}

// Memory limit
$memLimit = ini_get('memory_limit');
$memBytes = (function($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $num = intval($val);
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
})($memLimit);
$memOk = $memBytes >= 128 * 1024 * 1024; // 128M minimum
check('PHP Memory Limit', $memOk, $memLimit,
    'Increase memory_limit to at least 128M in php.ini. The RF Power Monitor API sets its own limit to 512M.');

// ========================================================================
// 2. Directory Structure
// ========================================================================

$requiredDirs = [
    'logs'               => 'Log files (BBS, VARA, DataLog)',
    'cache'              => 'API cache (auto-populated)',
    'data'               => 'Application data',
    'data/messages'      => 'Server-side BBS message storage',
    'api'                => 'API endpoint scripts',
    'includes'           => 'PHP includes',
    'shared'             => 'Shared JavaScript config',
];

foreach ($requiredDirs as $dir => $desc) {
    $path = $baseDir . '/' . $dir;
    $exists = is_dir($path);
    check("Directory: $dir/", $exists, $exists ? $desc : "MISSING — $desc",
        "Create it: mkdir " . ($dir === 'data/messages' ? '-p ' : '') . "\"$dir\"");
}

// Check cache is writable by web server
$cachePath = $baseDir . '/cache';
if (is_dir($cachePath)) {
    $writable = is_writable($cachePath);
    check('Cache directory writable', $writable, $writable ? 'Web server can write cache files' : 'NOT writable',
        PHP_OS_FAMILY === 'Windows'
            ? 'Right-click cache folder → Properties → Security → ensure web server user has write access'
            : 'Run: sudo chown www-data:www-data cache && sudo chmod 755 cache');
}

// Check data/messages is writable
$msgPath = $baseDir . '/data/messages';
if (is_dir($msgPath)) {
    $writable = is_writable($msgPath);
    check('Messages directory writable', $writable, $writable ? 'Server-side message storage ready' : 'NOT writable',
        PHP_OS_FAMILY === 'Windows'
            ? 'Right-click data/messages folder → Properties → Security → ensure web server user has write access'
            : 'Run: sudo chown -R www-data:www-data data/ && sudo chmod -R 755 data/');
}

// ========================================================================
// 3. Configuration
// ========================================================================

$configExists = file_exists($baseDir . '/config.php');
check('config.php exists', $configExists,
    $configExists ? 'Configuration file found' : 'MISSING — dashboard cannot start',
    'Copy config.php.example to config.php and edit your settings');

$configExampleExists = file_exists($baseDir . '/config.php.example');
check('config.php.example exists', $configExampleExists,
    $configExampleExists ? 'Template available' : 'Missing — may indicate incomplete installation',
    'Re-extract from the BPQ-Dashboard zip file');

if ($configExists) {
    $config = @include($baseDir . '/config.php');
    $configValid = is_array($config);
    check('config.php valid syntax', $configValid,
        $configValid ? 'Parses correctly' : 'PHP syntax error in config.php',
        'Open config.php in a text editor and check for missing commas, quotes, or brackets');
    
    if ($configValid) {
        // Check critical settings
        $callsign = $config['station']['callsign'] ?? 'N0CALL';
        $callOk = $callsign !== 'N0CALL' && $callsign !== 'YOURCALL' && strlen($callsign) >= 3;
        check('Callsign configured', $callOk, "callsign = '$callsign'",
            'Edit config.php and set your callsign in the station section');
        
        $password = $config['bbs']['pass'] ?? 'CHANGEME';
        $passOk = $password !== 'CHANGEME' && strlen($password) > 0;
        check('BBS password configured', $passOk,
            $passOk ? 'Password is set (not shown)' : 'Still set to CHANGEME',
            'Edit config.php and set your actual BBS password');
        
        $lat = $config['station']['latitude'] ?? 0.0;
        $lon = $config['station']['longitude'] ?? 0.0;
        $coordsOk = ($lat != 0.0 || $lon != 0.0);
        check('Station coordinates', $coordsOk,
            $coordsOk ? "lat=$lat, lon=$lon" : 'Both set to 0.0',
            'Edit config.php and set your latitude and longitude');
    }
}

// ========================================================================
// 4. Key Files
// ========================================================================

$keyFiles = [
    'api/config.php'        => 'Configuration API endpoint',
    'api/data.php'          => 'DataLog + connections API (RF Power Monitor)',
    'bbs-messages.php'      => 'BBS messages backend',
    'bbs-messages.html'     => 'BBS messages dashboard',
    'bpq-rf-connections.html' => 'RF connections dashboard',
    'bpq-system-logs.html'  => 'System logs dashboard',
    'rf-power-monitor.html' => 'RF Power Monitor dashboard',
    'includes/bootstrap.php'=> 'Security and config loader',
    'shared/config.js'      => 'Client-side configuration',
    'message-storage.php'   => 'Server-side message storage',
];

foreach ($keyFiles as $file => $desc) {
    $path = $baseDir . '/' . $file;
    $exists = file_exists($path);
    check("File: $file", $exists, $exists ? $desc : "MISSING — $desc",
        'Re-extract from the BPQ-Dashboard zip file');
}

// ========================================================================
// 5. Log Files
// ========================================================================

$logsDir = $baseDir . '/logs';
if (is_dir($logsDir)) {
    $logFiles = scandir($logsDir);
    $logFiles = array_filter($logFiles, function($f) { return $f !== '.' && $f !== '..'; });
    
    $bbsLogs = preg_grep('/^[Ll]og_\d{6}_BBS\.txt$/i', $logFiles);
    $varaLogs = preg_grep('/\.vara$/i', $logFiles);
    $dataLogs = preg_grep('/^DataLog/i', $logFiles);
    $tcpLogs = preg_grep('/^[Ll]og_\d{6}_TCP\.txt$/i', $logFiles);
    
    check('BBS log files in logs/', count($bbsLogs) > 0,
        count($bbsLogs) . ' file(s) found' . (count($bbsLogs) > 0 ? ' — e.g. ' . reset($bbsLogs) : ''),
        'Run the log sync script to copy BPQ log files into the logs/ directory');
    
    $varaCount = count($varaLogs);
    if ($varaCount > 0) {
        check('VARA log file in logs/', true, $varaCount . ' file(s) — e.g. ' . reset($varaLogs), '');
    } else {
        warn('VARA log file', 'No .vara file found in logs/ — RF Connections frequency correlation unavailable',
            'Copy your callsign.vara file to logs/ or set up VARA log fetching');
    }
    
    $dataLogCount = count($dataLogs);
    if ($dataLogCount > 0) {
        check('DataLog files in logs/', true, "$dataLogCount file(s) — e.g. " . reset($dataLogs), '');
    } else {
        warn('DataLog files', 'No DataLog files found — RF Power Monitor will have no data',
            'Only needed if you have a WaveNode RF power meter. Copy DataLog CSV files to logs/');
    }
    
    // Check for datalog-list.php
    $dlListExists = file_exists($logsDir . '/datalog-list.php');
    if ($dataLogCount > 0) {
        check('datalog-list.php in logs/', $dlListExists,
            $dlListExists ? 'Helper script present' : 'MISSING — API cannot find DataLog files',
            'Copy datalog-list.php from the dashboard root into the logs/ directory');
    }
} else {
    check('Log files', false, 'logs/ directory does not exist', 'Create the logs/ directory');
}

// ========================================================================
// 6. API Endpoints
// ========================================================================

// Test config API
$configApi = testUrl('api/config.php');
$configApiOk = $configApi['status'] === 200;
$configJson = @json_decode($configApi['body'], true);
$configApiSuccess = $configApiOk && isset($configJson['success']) && $configJson['success'];
check('API: config.php', $configApiSuccess,
    $configApiOk ? ('HTTP 200 — ' . ($configJson['success'] ? 'success' : 'returned error: ' . ($configJson['error'] ?? 'unknown'))) : "HTTP {$configApi['status']}",
    'Check that config.php exists and has valid settings');

// Test datalog API
$dataApi = testUrl('api/data.php?source=datalog&days=1&debug=1');
$dataApiOk = $dataApi['status'] === 200;
$dataJson = @json_decode($dataApi['body'], true);
if ($dataApiOk && $dataJson) {
    $samples = $dataJson['totalSamples'] ?? $dataJson['data_count'] ?? count($dataJson['data'] ?? []);
    $hasDebug = isset($dataJson['debug']);
    check('API: data.php (datalog)', true,
        "HTTP 200 — $samples samples" . ($hasDebug ? ', debug info present' : ''),
        '');
    if ($samples === 0) {
        warn('DataLog API returned 0 samples', 'No DataLog data found for the last day',
            'Only an issue if you have a WaveNode meter. Check that DataLog files are in logs/');
    }
} else {
    $errMsg = $dataApiOk ? 'Invalid JSON response' : "HTTP {$dataApi['status']}";
    if ($dataApi['body'] && !$dataJson) {
        $errMsg .= ' — ' . substr(strip_tags($dataApi['body']), 0, 100);
    }
    check('API: data.php (datalog)', false, $errMsg,
        'Check PHP error log. Common causes: missing cache/ directory, PHP syntax error');
}

// Test connections API
$connApi = testUrl('api/data.php?source=connections&days=7&debug=1');
$connApiOk = $connApi['status'] === 200;
$connJson = @json_decode($connApi['body'], true);
if ($connApiOk && $connJson) {
    $connCount = count($connJson['connections'] ?? []);
    $failedCount = count(array_filter($connJson['connections'] ?? [], function($c) { return !empty($c['failed']); }));
    $withFreq = count(array_filter($connJson['connections'] ?? [], function($c) { return !empty($c['freq']); }));
    check('API: data.php (connections)', true,
        "HTTP 200 — $connCount connections ($failedCount failed, $withFreq with frequency)", '');
} else {
    check('API: data.php (connections)', false,
        $connApiOk ? 'Invalid JSON' : "HTTP {$connApi['status']}",
        'Check that a .vara log file exists in logs/');
}

// Test BBS messages endpoint
$bbsApi = testUrl('bbs-messages.php?action=test');
$bbsApiOk = $bbsApi['status'] === 200;
$bbsJson = @json_decode($bbsApi['body'], true);
if ($bbsApiOk && $bbsJson) {
    $bbsSuccess = !empty($bbsJson['success']);
    check('API: bbs-messages.php', $bbsSuccess,
        $bbsSuccess ? 'BBS connection successful' : ('BBS error: ' . ($bbsJson['error'] ?? 'unknown')),
        'Check config.php BBS settings: host, port, callsign, password. Make sure BPQ32/LinBPQ is running.');
} else {
    check('API: bbs-messages.php', false,
        $bbsApiOk ? 'Invalid response' : "HTTP {$bbsApi['status']}",
        'Check that bbs-messages.php exists and PHP is working');
}

// Test message storage
$storageApi = testUrl('message-storage.php?action=stats');
$storageOk = $storageApi['status'] === 200;
$storageJson = @json_decode($storageApi['body'], true);
if ($storageOk && $storageJson) {
    check('API: message-storage.php', !empty($storageJson['success']),
        'Server-side message storage operational', '');
} else {
    warn('Server-side message storage', 'message-storage.php not responding',
        'Check that data/messages/ directory exists and is writable');
}

// ========================================================================
// 7. Server Environment
// ========================================================================

check('Server timezone', true, date_default_timezone_get() . ' (UTC offset: ' . date('P') . ')', '');
check('Server time', true, gmdate('Y-m-d H:i:s') . ' UTC', '');
check('PHP SAPI', true, php_sapi_name(), '');
check('Operating system', true, PHP_OS_FAMILY . ' (' . php_uname('s') . ' ' . php_uname('r') . ')', '');

// ========================================================================
// OUTPUT
// ========================================================================

$passCount = count(array_filter($checks, function($c) { return $c['pass']; }));
$failCount = count(array_filter($checks, function($c) { return !$c['pass']; }));
$warnCount = count($warnings);
$totalChecks = count($checks);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BPQ Dashboard Health Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f4f8; color: #333; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin-bottom: 6px; color: #1a365d; }
        .subtitle { color: #718096; font-size: 0.85rem; margin-bottom: 20px; }
        .summary { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
        .summary-card { padding: 12px 20px; border-radius: 8px; font-weight: 600; font-size: 1.1rem; min-width: 140px; text-align: center; }
        .summary-pass { background: #c6f6d5; color: #22543d; }
        .summary-fail { background: #fed7d7; color: #742a2a; }
        .summary-warn { background: #fefcbf; color: #744210; }
        .section { margin-bottom: 20px; }
        .section h2 { font-size: 1rem; color: #4a5568; margin-bottom: 8px; padding-bottom: 4px; border-bottom: 2px solid #e2e8f0; }
        .check { padding: 8px 12px; border-radius: 6px; margin-bottom: 4px; display: flex; align-items: flex-start; gap: 10px; font-size: 0.85rem; }
        .check-pass { background: #f0fff4; border-left: 3px solid #48bb78; }
        .check-fail { background: #fff5f5; border-left: 3px solid #f56565; }
        .check-warn { background: #fffff0; border-left: 3px solid #ecc94b; }
        .check-icon { font-size: 1rem; flex-shrink: 0; width: 20px; text-align: center; }
        .check-body { flex: 1; }
        .check-name { font-weight: 600; }
        .check-detail { color: #718096; font-size: 0.8rem; }
        .check-fix { color: #e53e3e; font-size: 0.8rem; margin-top: 2px; }
        .check-fix code { background: #fff5f5; padding: 1px 4px; border-radius: 3px; font-size: 0.78rem; }
        .timestamp { text-align: center; color: #a0aec0; font-size: 0.75rem; margin-top: 20px; }
        .all-good { text-align: center; padding: 30px; background: #f0fff4; border-radius: 12px; margin-bottom: 20px; }
        .all-good .icon { font-size: 3rem; }
        .all-good .text { font-size: 1.1rem; color: #22543d; font-weight: 600; margin-top: 8px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📡 BPQ Dashboard — Health Check</h1>
    <div class="subtitle">Diagnostic report for your BPQ Dashboard installation</div>
    
    <div class="summary">
        <div class="summary-card summary-pass">✅ <?= $passCount ?> passed</div>
        <?php if ($failCount > 0): ?><div class="summary-card summary-fail">❌ <?= $failCount ?> failed</div><?php endif; ?>
        <?php if ($warnCount > 0): ?><div class="summary-card summary-warn">⚠️ <?= $warnCount ?> warning<?= $warnCount > 1 ? 's' : '' ?></div><?php endif; ?>
    </div>

    <?php if ($failCount === 0 && $warnCount === 0): ?>
    <div class="all-good">
        <div class="icon">🎉</div>
        <div class="text">Everything looks great! All <?= $totalChecks ?> checks passed.</div>
    </div>
    <?php endif; ?>

    <?php if ($failCount > 0): ?>
    <div class="section">
        <h2>❌ Issues to Fix</h2>
        <?php foreach ($checks as $c): if ($c['pass']) continue; ?>
        <div class="check check-fail">
            <div class="check-icon">❌</div>
            <div class="check-body">
                <div class="check-name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="check-detail"><?= htmlspecialchars($c['detail']) ?></div>
                <?php if ($c['fix']): ?><div class="check-fix">Fix: <?= $c['fix'] ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($warnCount > 0): ?>
    <div class="section">
        <h2>⚠️ Warnings</h2>
        <?php foreach ($warnings as $w): ?>
        <div class="check check-warn">
            <div class="check-icon">⚠️</div>
            <div class="check-body">
                <div class="check-name"><?= htmlspecialchars($w['name']) ?></div>
                <div class="check-detail"><?= htmlspecialchars($w['detail']) ?></div>
                <?php if ($w['fix']): ?><div class="check-fix"><?= $w['fix'] ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>✅ All Checks (<?= $totalChecks ?>)</h2>
        <?php foreach ($checks as $c): ?>
        <div class="check <?= $c['pass'] ? 'check-pass' : 'check-fail' ?>">
            <div class="check-icon"><?= $c['pass'] ? '✅' : '❌' ?></div>
            <div class="check-body">
                <div class="check-name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="check-detail"><?= htmlspecialchars($c['detail']) ?></div>
                <?php if (!$c['pass'] && $c['fix']): ?><div class="check-fix">Fix: <?= $c['fix'] ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="timestamp">
        Generated <?= gmdate('Y-m-d H:i:s') ?> UTC | PHP <?= $phpVer ?> | <?= PHP_OS_FAMILY ?>
        | BPQ Dashboard v1.5.6
    </div>
</div>
</body>
</html>
