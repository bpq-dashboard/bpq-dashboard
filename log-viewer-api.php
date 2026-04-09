<?php
/**
 * log-viewer-api.php — Log file reader API for BPQSERVER
 * Version: 1.0.0
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Auth
$configFile = __DIR__ . '/bpqdash-config.php';
if (file_exists($configFile)) {
    $cfg = include $configFile;
    $bbsPassword = $cfg['bbs_password'] ?? $cfg['password'] ?? null;
} else {
    $bbsPassword = null;
}
$providedPassword = $_GET['password'] ?? $_SERVER['HTTP_X_BBS_PASSWORD'] ?? null;
if ($bbsPassword && $providedPassword !== $bbsPassword) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Log catalogue
$LOGS = [
    'prop-scheduler'   => ['path' => '/var/www/bpqdash/logs/prop-scheduler.log',   'label' => 'Prop Scheduler',    'group' => 'BPQ Dashboard', 'color' => 'blue',   'desc' => 'Propagation-based forwarding schedule updates'],
    'connect-watchdog' => ['path' => '/var/www/bpqdash/logs/connect-watchdog.log', 'label' => 'Connect Watchdog',  'group' => 'BPQ Dashboard', 'color' => 'amber',  'desc' => 'Failed connect detection and pause/restore'],
    'wp-auto-clean'    => ['path' => '/var/log/wp-auto-clean.log',               'label' => 'WP Auto Clean',     'group' => 'BPQ Dashboard', 'color' => 'green',  'desc' => 'Winlink White Pages automatic cleanup'],
    'watchdog-state'   => ['path' => '/var/www/bpqdash/cache/watchdog-state.json', 'label' => 'Watchdog State',    'group' => 'BPQ Dashboard', 'color' => 'amber',  'desc' => 'Current connect-watchdog pause state (JSON)'],
    'vara-validator'   => ['path' => '/var/log/vara-validator.log',              'label' => 'VARA Validator',    'group' => 'VARA',          'color' => 'purple', 'desc' => 'VARA callsign validator proxy log'],
    'vara-sessions'    => ['path' => '/var/www/bpqdash/logs/yourcall.vara',          'label' => 'VARA Sessions',     'group' => 'VARA',          'color' => 'purple', 'desc' => 'Raw VARA HF session data'],
    'bbs-today'        => ['path' => null, 'dir' => '/var/www/bpqdash/logs',       'label' => 'BBS Log (Today)',   'group' => 'BPQ Node',      'color' => 'cyan',   'desc' => "Today's BPQ BBS activity log", 'dynamic' => 'today'],
    'bbs-archive'      => ['path' => null, 'dir' => '/var/www/bpqdash/logs',       'label' => 'BBS Log (Archive)', 'group' => 'BPQ Node',      'color' => 'cyan',   'desc' => 'Historical BPQ BBS logs — select a date', 'dynamic' => 'archive'],
    'nws-monitor'      => ['path' => '/var/log/nws-monitor.log',                 'label' => 'NWS Monitor',       'group' => 'System',        'color' => 'red',    'desc' => 'NWS weather alert monitor'],
    'nginx-error'      => ['path' => '/var/log/nginx/error.log',                 'label' => 'NGINX Errors',      'group' => 'System',        'color' => 'red',    'desc' => 'nginx web server error log'],
    'auth'             => ['path' => '/var/log/auth.log',                        'label' => 'Auth Log',          'group' => 'System',        'color' => 'amber',  'desc' => 'SSH and system authentication events'],
];

function get_today_bbs_path(): string {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    return sprintf('/var/www/bpqdash/logs/log_%s_BBS.txt', $now->format('ymd'));
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
    if (($meta['dynamic'] ?? '') === 'today')   return get_today_bbs_path();
    if (($meta['dynamic'] ?? '') === 'archive') return $date ? "/var/www/bpqdash/logs/log_{$date}_BBS.txt" : null;
    return $meta['path'] ?? null;
}

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $out = [];
    foreach ($LOGS as $key => $meta) {
        $path = resolve_path($meta);
        $e = ['key'=>$key,'label'=>$meta['label'],'group'=>$meta['group'],'color'=>$meta['color'],'desc'=>$meta['desc']];
        if (($meta['dynamic'] ?? '') === 'archive') {
            $e['exists'] = true; $e['dynamic'] = 'archive';
            $e['dates']  = list_bbs_dates($meta['dir']);
        } elseif ($path && file_exists($path)) {
            $e['exists'] = true; $e['size'] = filesize($path);
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
    echo json_encode(['dates' => list_bbs_dates('/var/www/bpqdash/logs')], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
echo json_encode(['error' => "Unknown action: $action"]);
