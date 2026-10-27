<?php
/**
 * log-viewer-api.php — Log file reader API
 * Version: 1.3.0 (2026-10-25) — catalogue covers the dashboard's own logs, the
 *   parsers and BPQ's dated logs; nws-monitor removed; VARA log from logs.vara_file
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// 2026-10-05: auth and paths fixed.
// - This file lives in admin/, so __DIR__ . '/config.php' never existed:
//   config was always empty, the BBS-password check never ran, and the
//   web root and log folder resolved to admin/. Now read through
//   includes/station-config.php (web root = parent folder).
// - The page used to send the BBS password in the URL (?password=), which
//   ends up in web-server logs. Dropped: /admin/ is behind the admin login,
//   and the PHP checks that login itself (includes/web-login.php).
require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('The Log Viewer');

$cfg        = bpq_config_all();
$WEB_ROOT   = rtrim($cfg['paths']['web_root'] ?? dirname(__DIR__), '/');
$LOG_DIR    = rtrim($cfg['paths']['logs']     ?? $WEB_ROOT . '/logs', '/');
$SCRIPTS    = $cfg['paths']['scripts']   ?? $WEB_ROOT . '/scripts';
$CALL       = strtolower($cfg['station']['callsign'] ?? 'bpq');

// Web server error log: the installer's own file first
$webserverLog = '/var/log/nginx/error.log';
foreach (['/var/log/nginx/bpq-dashboard.error.log', '/var/log/nginx/error.log',
          '/var/log/apache2/bpq-error.log', '/var/log/apache2/error.log'] as $c) {
    if (file_exists($c)) { $webserverLog = $c; break; }
}

// Log parser output: wherever /etc/cron.d/bpq-dashboard sends it (installer:
// /var/log/bpq-dashboard/), older installs /var/log/parse-*.log
$parserLog = function (string $name): string {
    $cron = (string)@file_get_contents('/etc/cron.d/bpq-dashboard');
    if (preg_match('/^[^#\n]*' . preg_quote($name, '/') . '\.py[^\n]*>>\s*(\S+)/m', $cron, $m)) return $m[1];
    foreach (["/var/log/bpq-dashboard/$name.log", "/var/log/$name.log"] as $c) if (file_exists($c)) return $c;
    return "/var/log/bpq-dashboard/$name.log";
};

$VARA_FILE = trim((string)($cfg['logs']['vara_file'] ?? ''));
if ($VARA_FILE === '') $VARA_FILE = $CALL . '.vara';
$VARA_PATH = $VARA_FILE[0] === '/' ? $VARA_FILE : $LOG_DIR . '/' . $VARA_FILE;

// Log catalogue (2026-10-25: every log the dashboard and its scripts write;
// nws-monitor removed — not part of the package). 'opt' = shown as
// "not used here" rather than missing when the file doesn't exist.
$LOGS = [
    // Dashboard services and jobs
    'bpq-telnet'       => ['path' => "$LOG_DIR/bpq-telnet-daemon.log",           'label' => 'bpq-telnet daemon',   'group' => 'Dashboard services', 'color' => 'blue',   'desc' => 'Telnet Client page and YAPP transfers (passwords are redacted)'],
    'bpq-vara'         => ['path' => "$LOG_DIR/bpq-vara-daemon.log",             'label' => 'bpq-vara daemon',     'group' => 'Dashboard services', 'color' => 'blue',   'desc' => 'VARA HF Terminal sessions', 'opt' => 'Only if bpq-vara is installed'],
    'message-bodies'   => ['path' => "$LOG_DIR/message-bodies.log",              'label' => 'Message text job',    'group' => 'Dashboard services', 'color' => 'green',  'desc' => 'message-bodies.timer: BBS login and per-message results', 'opt' => 'Needs the database and message-bodies.timer'],
    'bbs-messages'     => ['path' => "$LOG_DIR/bbs-messages.log",                'label' => 'Email Client (BBS)',  'group' => 'Dashboard services', 'color' => 'green',  'desc' => 'BBS sessions made by the Email Client (bbs-messages.php)', 'opt' => 'Appears after the first Get Mail (or at config.php logging.file if set)'],
    'dashboard'        => ['path' => "$LOG_DIR/dashboard.log",                   'label' => 'Dashboard log',       'group' => 'Dashboard services', 'color' => 'green',  'desc' => 'NWS alerts posted to the BBS (nws-bbs-post.php)', 'opt' => 'Appears after the first NWS Post to BBS (or at config.php logging.file if set)'],
    'nws-auto-post'    => ['path' => "$LOG_DIR/nws-auto-post.log",               'label' => 'NWS auto-post',       'group' => 'Dashboard services', 'color' => 'amber',  'desc' => 'nws-auto-post.timer: Warnings/Watches posted as bulletins, and the BBS dialogue', 'opt' => 'Only if nws-auto-post.timer is enabled'],
    // Log parsers (cron)
    'parse-vara-log'   => ['path' => $parserLog('parse-vara-log'),              'label' => 'RF Connections parser', 'group' => 'Log parsers',     'color' => 'cyan',   'desc' => 'parse-vara-log.py, every 5 minutes', 'opt' => 'Needs the database (install.sh step 14)'],
    'parse-traffic'    => ['path' => $parserLog('parse-traffic-report'),        'label' => 'Traffic parser',      'group' => 'Log parsers',        'color' => 'cyan',   'desc' => 'parse-traffic-report.py, every 5 minutes', 'opt' => 'Needs the database (install.sh step 14)'],
    'parse-system'     => ['path' => $parserLog('parse-system-logs'),           'label' => 'System Logs parser',  'group' => 'Log parsers',        'color' => 'cyan',   'desc' => 'parse-system-logs.py, every 5 minutes', 'opt' => 'Needs the database (install.sh step 14)'],
    // Optional forwarding tools
    'prop-scheduler'   => ['path' => "$LOG_DIR/prop-scheduler.log",              'label' => 'Prop Scheduler',      'group' => 'Forwarding tools',   'color' => 'blue',   'desc' => 'Propagation-based forwarding schedule updates', 'opt' => 'Only if prop-scheduler.py is scheduled'],
    'storm-monitor'    => ['path' => "$LOG_DIR/storm-monitor.log",               'label' => 'Storm Monitor',       'group' => 'Forwarding tools',   'color' => 'amber',  'desc' => 'Kp-based forwarding suspend / restore', 'opt' => 'Only if storm-monitor.py is scheduled'],
    'connect-watchdog' => ['path' => "$LOG_DIR/connect-watchdog.log",            'label' => 'Connect Watchdog',    'group' => 'Forwarding tools',   'color' => 'amber',  'desc' => 'Failed connect detection and pause / restore', 'opt' => 'Only if connect-watchdog.py is scheduled'],
    'watchdog-state'   => ['path' => "$WEB_ROOT/cache/watchdog-state.json",      'label' => 'Watchdog State',      'group' => 'Forwarding tools',   'color' => 'amber',  'desc' => 'Current connect-watchdog pause state (JSON)', 'opt' => 'Only if connect-watchdog.py is scheduled'],
    'wp-auto-clean'    => ['path' => '/var/log/wp-auto-clean.log',               'label' => 'WP Auto Clean',       'group' => 'Forwarding tools',   'color' => 'green',  'desc' => 'White Pages automatic clean-up (wp_manager.py --auto-clean)', 'opt' => 'Only if the wp_manager cron line is set up'],
    // VARA
    'vara-sessions'    => ['path' => $VARA_PATH,                                 'label' => 'VARA Sessions',       'group' => 'VARA',               'color' => 'purple', 'desc' => 'Raw VARA HF session log written by vara-logger (config.php logs.vara_file)', 'opt' => 'Only with VARA HF and vara-logger'],
    'vara-validator'   => ['path' => '/var/log/vara-validator.log',              'label' => 'VARA Validator',      'group' => 'VARA',               'color' => 'purple', 'desc' => 'Callsign validator ACCEPTED / REJECTED lines', 'opt' => 'Only if vara-validator is installed'],
    // LinBPQ's own logs (dated, in the logs folder)
    'bbs-today'        => ['path' => null, 'dir' => $LOG_DIR, 'fmt' => 'log_%y%m%d_BBS.txt',      'label' => 'BBS Log (Today)',     'group' => 'BPQ Node', 'color' => 'cyan', 'desc' => "Today's BPQ BBS log (UTC)", 'dynamic' => 'today'],
    'bbs-archive'      => ['path' => null, 'dir' => $LOG_DIR,                                      'label' => 'BBS Log (Archive)',   'group' => 'BPQ Node', 'color' => 'cyan', 'desc' => 'Earlier BPQ BBS logs — select a date', 'dynamic' => 'archive'],
    'tcp-today'        => ['path' => null, 'dir' => $LOG_DIR, 'fmt' => 'log_%y%m%d_TCP.txt',      'label' => 'TCP Log (Today)',     'group' => 'BPQ Node', 'color' => 'cyan', 'desc' => "Today's telnet / TCP connections (UTC)", 'dynamic' => 'today', 'opt' => 'BPQ writes it when telnet logging is on'],
    'connect-today'    => ['path' => null, 'dir' => $LOG_DIR, 'fmt' => 'ConnectLog_%y%m%d.log',   'label' => 'Connect Log (Today)', 'group' => 'BPQ Node', 'color' => 'cyan', 'desc' => "Today's incoming connects (UTC), as on the Connect Log page", 'dynamic' => 'today', 'opt' => 'BPQ writes it when connect logging is on'],
    'cms-today'        => ['path' => null, 'dir' => $LOG_DIR, 'fmt' => 'CMSAccess_%Y%m%d.log',    'label' => 'CMS Access (Today)',  'group' => 'BPQ Node', 'color' => 'cyan', 'desc' => "Today's Winlink CMS gateway sessions (UTC)", 'dynamic' => 'today', 'opt' => 'Only on a Winlink RMS gateway'],
    // System
    'webserver-error'  => ['path' => $webserverLog,                              'label' => 'Web Server Errors',   'group' => 'System',             'color' => 'red',    'desc' => 'nginx or Apache error log (PHP errors land here too)'],
    'auth'             => ['path' => '/var/log/auth.log',                        'label' => 'Auth Log',            'group' => 'System',             'color' => 'amber',  'desc' => 'SSH and sudo logins. Usually readable by root only: sudo tail /var/log/auth.log (systems without it log to the journal: sudo journalctl -t sshd)'],
];

function get_today_path(array $meta): string {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $name = strtr($meta['fmt'] ?? 'log_%y%m%d_BBS.txt', ['%y' => $now->format('y'), '%m' => $now->format('m'), '%d' => $now->format('d'), '%Y' => $now->format('Y')]);
    return rtrim($meta['dir'], '/') . '/' . $name;
}

function list_bbs_dates(string $dir): array {
    $dates = [];
    $files = glob($dir . '/log_??????_BBS.txt') ?: [];
    rsort($files);
    foreach (array_slice($files, 0, 30) as $f) {
        if (preg_match('/log_(\d{6})_BBS\.txt$/', $f, $m)) {
            $dates[] = [
                'date'  => $m[1],
                'label' => '20' . substr($m[1],0,2) . '-' . substr($m[1],2,2) . '-' . substr($m[1],4,2),
                'size'  => filesize($f),
            ];
        }
    }
    return $dates;
}

function read_tail(string $path, int $n): array {
    if (!file_exists($path))  return ['error' => "File not found: $path"];
    if (!is_readable($path))  return ['error' => "Permission denied: $path"];
    $size = filesize($path);
    if ($size < 524288) {
        $all = array_values(array_filter(explode("\n", file_get_contents($path)), fn($l) => $l !== ''));
        $lines = array_slice($all, -$n);
    } else {
        $fp = fopen($path, 'rb'); $buf = ''; $pos = $size; $cnt = 0;
        while ($pos > 0 && $cnt < $n) {
            $read = min(65536, $pos); $pos -= $read;
            fseek($fp, $pos); $buf = fread($fp, $read) . $buf;
            $cnt = substr_count($buf, "\n");
        }
        fclose($fp);
        $all = array_values(array_filter(explode("\n", $buf), fn($l) => $l !== ''));
        $lines = array_slice($all, -$n);
    }
    return ['lines' => $lines, 'total_shown' => count($lines), 'file_size' => $size, 'path' => $path];
}

function search_log(string $path, string $q): array {
    if (!file_exists($path))  return ['error' => "File not found: $path"];
    if (!is_readable($path))  return ['error' => "Permission denied: $path"];
    if (strlen($q) < 2)       return ['error' => 'Search term too short'];
    $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $results = []; $total = count($all);
    foreach ($all as $i => $line) {
        if (stripos($line, $q) !== false) {
            $start = max(0, $i-2); $end = min($total-1, $i+2);
            $snip = [];
            for ($j = $start; $j <= $end; $j++) {
                $snip[] = ['line_num' => $j+1, 'text' => $all[$j], 'match' => ($j === $i)];
            }
            $results[] = $snip;
            if (count($results) >= 100) break;
        }
    }
    return ['query' => $q, 'matches' => count($results), 'results' => $results, 'path' => $path];
}

function resolve_path(array $meta, string $date = ''): ?string {
    global $LOG_DIR;
    if (($meta['dynamic'] ?? '') === 'today')   return get_today_path($meta);
    if (($meta['dynamic'] ?? '') === 'archive') return $date ? "{$LOG_DIR}/log_{$date}_BBS.txt" : null;
    return $meta['path'] ?? null;
}

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $out = [];
    foreach ($LOGS as $key => $meta) {
        $path = resolve_path($meta);
        $e = ['key'=>$key,'label'=>$meta['label'],'group'=>$meta['group'],'color'=>$meta['color'],'desc'=>$meta['desc'],'opt'=>$meta['opt'] ?? ''];
        if (($meta['dynamic'] ?? '') === 'archive') {
            $e['exists'] = true; $e['dynamic'] = 'archive';
            $e['dates']  = list_bbs_dates($meta['dir']);
        } elseif ($path && file_exists($path)) {
            $e['exists'] = true; $e['readable'] = is_readable($path); $e['size'] = filesize($path);
            $e['modified'] = filemtime($path); $e['path'] = $path;
        } else {
            $e['exists'] = false; $e['path'] = $path ?? '(dynamic)';
        }
        $out[] = $e;
    }
    echo json_encode(['logs' => $out, 'generated_at' => gmdate('Y-m-d H:i:s').' UTC'], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'read') {
    $key   = $_GET['log'] ?? '';
    $lines = min(1000, max(50, (int)($_GET['lines'] ?? 200)));
    $date  = preg_replace('/[^0-9]/', '', $_GET['date'] ?? '');
    if (!isset($LOGS[$key])) { http_response_code(400); echo json_encode(['error'=>"Unknown log: $key"]); exit; }
    $path = resolve_path($LOGS[$key], $date);
    if (!$path) { echo json_encode(['error'=>'Date required for archive log (YYMMDD)']); exit; }
    $result = read_tail($path, $lines);
    $result['log_key'] = $key; $result['log_label'] = $LOGS[$key]['label'];
    $result['lines_requested'] = $lines;
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'search') {
    $key  = $_GET['log'] ?? '';
    $q    = trim($_GET['q'] ?? '');
    $date = preg_replace('/[^0-9]/', '', $_GET['date'] ?? '');
    if (!isset($LOGS[$key])) { http_response_code(400); echo json_encode(['error'=>"Unknown log: $key"]); exit; }
    $path = resolve_path($LOGS[$key], $date);
    if (!$path) { echo json_encode(['error'=>'Date required for archive search']); exit; }
    $result = search_log($path, $q);
    $result['log_key'] = $key; $result['log_label'] = $LOGS[$key]['label'];
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'dates') {
    global $LOG_DIR;
    echo json_encode(['dates' => list_bbs_dates($LOG_DIR)], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
echo json_encode(['error' => "Unknown action: $action"]);
