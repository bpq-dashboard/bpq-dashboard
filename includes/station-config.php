<?php
/**
 * includes/station-config.php — station settings from config.php (2026-09-26)
 *
 * Small, dependency-free reader for the non-secret parts of config.php
 * ('station', 'paths', 'logs'), so pages/APIs don't hard-code a callsign,
 * hub, grid square or path. /includes/ is 403 on the web; PHP require only.
 */

function bpq_config_all(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    $file = dirname(__DIR__) . '/config.php';
    if (is_readable($file)) {
        $c = require $file;               // v1.3+ config.php returns an array
        if (is_array($c)) $cfg = $c;
    }
    return $cfg;
}

function bpq_config_section(string $name): array {
    $c = bpq_config_all();
    return (isset($c[$name]) && is_array($c[$name])) ? $c[$name] : [];
}

function bpq_station(): array {
    return bpq_config_section('station');
}

/**
 * Centre of a Maidenhead locator (4 or 6 characters) as [lat, lon], or null.
 * FN31PR -> [41.729167, -72.708333]
 */
function bpq_grid_center(string $grid): ?array {
    $g = strtoupper(trim($grid));
    if (!preg_match('/^[A-R]{2}[0-9]{2}([A-X]{2})?$/', $g)) return null;
    $lon = (ord($g[0]) - 65) * 20 - 180 + ((int)$g[2]) * 2;
    $lat = (ord($g[1]) - 65) * 10 - 90  + ((int)$g[3]);
    if (strlen($g) === 6) {
        $lon += (ord($g[4]) - 65) * (5 / 60)   + (5 / 60) / 2;
        $lat += (ord($g[5]) - 65) * (2.5 / 60) + (2.5 / 60) / 2;
    } else {
        $lon += 1;
        $lat += 0.5;
    }
    return [round($lat, 6), round($lon, 6)];
}

/**
 * Cross-origin (CORS) headers from config.php 'cors' (2026-10-20).
 * The dashboard's own pages are same-origin and need none. Default: no
 * Access-Control-Allow-Origin at all, so other web sites can't use this
 * dashboard's endpoints (QRZ lookups, BBS logins, proxies) from their
 * visitors' browsers. 'allowed_origins' lists sites that may;
 * 'allow_all' => true restores the old "*" behaviour.
 */
function bpq_cors_headers(string $methods = 'GET, POST, OPTIONS'): void {
    $cors   = bpq_config_section('cors');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if (($cors['allow_all'] ?? false) === true) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, (array)($cors['allowed_origins'] ?? []), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    } else {
        return;
    }
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type');
}

/**
 * BPQ's telnet, FBB and web-management ports for this station (2026-11-01).
 * Telnet: config.php bbs.port. FBB / HTTP: config.php bbs.fbb_port /
 * bbs.http_port if set, else FBBPORT= / HTTPPORT= in <paths.linbpq>/bpq32.cfg
 * if readable, else BPQ's defaults 8011 / 8008. Until v1.5.17 the Telnet
 * Client only knew 8010/8011 (N3MEL uses 8015/8017).
 */
function bpq_bbs_ports(): array {
    static $ports = null;
    if ($ports !== null) return $ports;
    $bbs = bpq_config_section('bbs');
    $cfgText = '';
    $dir = (string)(bpq_config_section('paths')['linbpq'] ?? '');
    if ($dir !== '' && is_readable(rtrim($dir, '/') . '/bpq32.cfg')) {
        $cfgText = (string)@file_get_contents(rtrim($dir, '/') . '/bpq32.cfg');
    }
    $fromCfg = function (string $key) use ($cfgText): int {
        return preg_match('/^\s*' . $key . '\s*=\s*(\d+)/mi', $cfgText, $m) ? (int)$m[1] : 0;
    };
    $ports = [
        'telnet' => (int)($bbs['port'] ?? 0) ?: ($fromCfg('TCPPORT') ?: 8010),
        'fbb'    => (int)($bbs['fbb_port'] ?? 0) ?: ($fromCfg('FBBPORT') ?: 8011),
        'http'   => (int)($bbs['http_port'] ?? 0) ?: ($fromCfg('HTTPPORT') ?: 8008),
    ];
    return $ports;
}


/**
 * LinBPQ's ports from <paths.linbpq>/bpq32.cfg (v1.5.22): [num => ['name', 'kind']].
 * v1.5.23: KISS-over-TCP ports (TYPE=ASYNC with IPADDR + TCPPORT, e.g. a
 * sound modem) also carry 'kiss_host' / 'kiss_port' for the server's own
 * status checks; callers that answer the web must not pass those on.
 * A port is a PORT ... ENDPORT block; its number is PORTNUM= if given, else
 * its position (as BPQ does). 'name' is ID= (PORTS command text), 'kind' is
 * 'net' for TELNET / AXIP drivers and 'rf' for everything else. Only names
 * and kinds are meant for the browser: never addresses or passwords. Unreadable
 * file -> []. config.php 'system_logs' => ['port_names' => [4 => 'VHF']]
 * overrides or adds names (stations whose bpq32.cfg the web user can't read).
 */
function bpq_linbpq_ports(): array {
    static $ports = null;
    if ($ports !== null) return $ports;
    $ports = [];
    $dir = (string)(bpq_config_section('paths')['linbpq'] ?? '');
    $file = rtrim($dir, '/') . '/bpq32.cfg';
    if ($dir !== '' && is_readable($file)) {
        $text = (string)@file_get_contents($file);
        $text = preg_replace('#/\*.*?\*/#s', '', $text);                     // /* block comments */
        $text = implode("\n", array_map(fn($l) => explode(';', $l, 2)[0], preg_split('/\r\n|\r|\n/', $text)));
        if (preg_match_all('/^\s*PORT\b(.*?)^\s*ENDPORT\b/smi', $text, $mm)) {
            $pos = 0;
            foreach ($mm[1] as $blk) {
                $pos++;
                $num  = preg_match('/^\s*PORTNUM\s*=\s*(\d+)/mi', $blk, $m) ? (int)$m[1] : $pos;
                $name = preg_match('/^\s*ID\s*=\s*(.+)$/mi', $blk, $m) ? trim($m[1]) : '';
                $drv  = preg_match('/^\s*DRIVER\s*=\s*(\S+)/mi', $blk, $m) ? strtoupper($m[1]) : '';
                $kind = in_array($drv, ['TELNET', 'BPQAXIP', 'AXIP'], true) ? 'net' : 'rf';
                $ports[$num] = ['name' => mb_substr($name !== '' ? $name : "Port $num", 0, 40), 'kind' => $kind];
                if (preg_match('/^\s*IPADDR\s*=\s*(\S+)/mi', $blk, $ip) && preg_match('/^\s*TCPPORT\s*=\s*(\d+)/mi', $blk, $tp)
                    && !in_array($drv, ['TELNET', 'BPQAXIP', 'AXIP'], true)) {
                    $ports[$num]['kiss_host'] = $ip[1];
                    $ports[$num]['kiss_port'] = (int)$tp[1];
                }
            }
        }
    }
    foreach ((array)(bpq_config_section('system_logs')['port_names'] ?? []) as $n => $name) {
        $n = (int)$n;
        if ($n < 1 || !is_string($name) || trim($name) === '') continue;
        $ports[$n] = array_merge($ports[$n] ?? ['kind' => 'rf'], ['name' => mb_substr(trim($name), 0, 40)]);
    }
    ksort($ports);
    return $ports;
}

/**
 * System Logs settings saved from the Maintenance page (v1.5.26):
 * <web root>/data/system-logs.json  {"watch": [...], "live_mheard": true}
 * Written by admin/maintenance-api.php (admin login), never config.php,
 * which holds passwords. null = never saved there.
 */
function bpq_system_logs_file(): string {
    return dirname(__DIR__) . '/data/system-logs.json';
}
function bpq_system_logs_saved(): ?array {
    $f = bpq_system_logs_file();
    if (!is_readable($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}
/** Live MHeard polling on? Off when config.php OR the Maintenance page says false. */
function bpq_live_mheard_on(): bool {
    $c = bpq_config_section('system_logs');
    if (array_key_exists('live_mheard', $c) && !$c['live_mheard']) return false;
    $s = bpq_system_logs_saved();
    return !($s !== null && array_key_exists('live_mheard', $s) && !$s['live_mheard']);
}
/** Where the watch list comes from: 'page' (Maintenance), 'config' or 'none'. */
function bpq_watch_source(): string {
    $s = bpq_system_logs_saved();
    if ($s !== null && array_key_exists('watch', $s)) return 'page';
    return (bpq_config_section('system_logs')['watch'] ?? null) ? 'config' : 'none';
}

/**
 * Watched stations (v1.5.25): Maintenance page (data/system-logs.json, v1.5.26)
 * or, until that is saved once, config.php 'system_logs' => ['watch' => [
 *     ['call' => 'KP2L-10', 'port' => 4, 'hours' => 6], ...]]
 * Listen-only check: when was the station last heard (net_mheard), and has
 * it been quiet longer than 'hours'? 'port' optional (any port).
 * -> [['call', 'port' (int|null), 'hours' (float)], ...]
 */
function bpq_watch_list(?array $from = null): array {
    if ($from === null) {
        $s = bpq_system_logs_saved();
        $from = ($s !== null && array_key_exists('watch', $s))
              ? (array)$s['watch'] : (array)(bpq_config_section('system_logs')['watch'] ?? []);
    }
    $out = []; $seen = [];
    foreach ($from as $w) {
        if (!is_array($w)) continue;
        $call = strtoupper(trim((string)($w['call'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{1,6}(-\d{1,2})?$/', $call)) continue;
        $port = isset($w['port']) && (int)$w['port'] > 0 ? (int)$w['port'] : null;
        $hours = (float)($w['hours'] ?? 6);
        $hours = $hours > 0 ? min($hours, 720.0) : 6.0;
        $key = $call . '/' . ($port ?? '*');
        if (isset($seen[$key]) || count($out) >= 20) continue;
        $seen[$key] = true;
        $out[] = ['call' => $call, 'port' => $port, 'hours' => $hours];
    }
    return $out;
}

/** Watch list with last heard (UTC 'Y-m-d H:i:s') and quiet flag, from net_mheard. */
function bpq_watch_status(?PDO $pdo): array {
    $out = [];
    foreach (bpq_watch_list() as $w) {
        $last = null;
        if ($pdo) {
            try {
                $sql = 'SELECT MAX(heard_at) FROM net_mheard WHERE callsign = ?' . ($w['port'] ? ' AND port = ?' : '');
                $st = $pdo->prepare($sql);
                $st->execute($w['port'] ? [$w['call'], $w['port']] : [$w['call']]);
                $last = $st->fetchColumn() ?: null;
            } catch (Throwable $e) { $last = null; }
        }
        $ageH = $last ? (time() - strtotime($last . ' UTC')) / 3600 : null;
        $out[] = $w + ['last_heard' => $last, 'quiet' => $ageH === null || $ageH > $w['hours']];
    }
    return $out;
}

/**
 * Where the dashboard writes ITS OWN log files (v1.5.19, 2026-11-02).
 * Until v1.5.18 they went into <web root>/logs, which the installer links to
 * LinBPQ's own logs folder; where the web user can only read that folder
 * (home-folder installs) bpq-telnet / bpq-vara crashed at start and the
 * other logs were lost (N3MEL). Now: config.php paths.app_logs if set, else
 * /var/log/bpq-dashboard (the installer creates it, owned by the web user),
 * else the old <paths.logs> folder so nothing stops on an older install.
 */
function bpq_app_log_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $p = bpq_config_section('paths');
    foreach ([(string)($p['app_logs'] ?? ''), '/var/log/bpq-dashboard'] as $d) {
        $d = rtrim($d, '/');
        if ($d !== '' && is_dir($d) && is_writable($d)) return $dir = $d;
    }
    return $dir = rtrim((string)($p['logs'] ?? (dirname(__DIR__) . '/logs')), '/');
}

function bpq_app_log(string $file): string {
    return bpq_app_log_dir() . '/' . basename($file);
}

/**
 * Optional pages (v1.5.19). config.php 'pages' => ['telnet' => true, ...];
 * a page left out of the list counts as ON, so older config.php files keep
 * every page. Rig Status and RF Power Monitor follow 'radio' => ['enabled'].
 * The menu hides pages that are off (bpq-nav-loader.js, via station-info.php);
 * the files stay, so switching a page on later is a config change plus its
 * helper service (Maintenance > Optional pages).
 */
function bpq_pages(): array {
    $p = bpq_config_section('pages');
    $on = function (string $k) use ($p): bool { return !array_key_exists($k, $p) || (bool)$p[$k]; };
    $radio = bpq_config_section('radio');
    $rig = !array_key_exists('enabled', $radio) || (bool)$radio['enabled'];
    return [
        'telnet'         => $on('telnet'),
        'chat'           => $on('chat'),
        'aprs'           => $on('aprs'),
        'vara'           => $on('vara'),
        'vara_validator' => $on('vara_validator'),
        'rig'            => $rig,
    ];
}

/** Menu keys (data-page in _nav.html) of the pages that are switched off. */
function bpq_pages_off(): array {
    $map = ['telnet' => ['telnet'], 'chat' => ['chat'], 'aprs' => ['aprs'],
            'vara' => ['vara'], 'rig' => ['rig', 'rf-power']];
    $off = [];
    foreach (bpq_pages() as $k => $on) {
        if (!$on && isset($map[$k])) $off = array_merge($off, $map[$k]);
    }
    return $off;
}

/**
 * v1.5.34: the station's backup status (Maintenance → Backups, System Audit).
 *
 * The dashboard doesn't make backups itself. Any backup script can report to
 * it by writing a small JSON file (world-readable) when it finishes:
 *   {"result":"ok"|"failed", "finished_at":"2026-11-09T08:21:40Z",
 *    "last_ok_at":"2026-11-09T08:21:40Z", "message":"...", "snapshot":"11d6ec94",
 *    "tool":"restic", "drive":"/mnt/backup", "keep":"30 daily + 12 weekly"}
 * Path: config.php 'backup' => ['status_file' => ...]
 * (default /var/lib/bpq-backup/status.json); 'max_age_hours' (default 36).
 */
function bpq_backup_status(): array {
    $cfg  = bpq_config_section('backup');
    $file = (string)($cfg['status_file'] ?? '/var/lib/bpq-backup/status.json');
    $max  = max(1, (float)($cfg['max_age_hours'] ?? 36));
    $out  = ['file' => $file, 'max_age_hours' => $max, 'present' => false, 'verdict' => 'missing'];
    if (!@is_readable($file)) {
        $out['why'] = @file_exists($file) ? 'not readable by the web server (chmod 644)' : 'no status file';
        return $out;
    }
    $j = json_decode((string)@file_get_contents($file, false, null, 0, 65536), true);
    if (!is_array($j)) { $out['why'] = 'status file is not valid JSON'; return $out; }
    $out['present'] = true;
    $keep = ['result', 'finished_at', 'last_ok_at', 'message', 'snapshot', 'tool', 'drive', 'keep', 'stored', 'size'];
    foreach ($keep as $k) if (isset($j[$k]) && is_scalar($j[$k])) $out[$k] = substr((string)$j[$k], 0, 300);
    $lastOk = isset($out['last_ok_at']) ? strtotime($out['last_ok_at']) : false;
    $out['age_hours'] = $lastOk ? round((time() - $lastOk) / 3600, 1) : null;
    $drive = (string)($out['drive'] ?? '');
    if ($drive !== '' && @is_dir($drive)) {
        $free = @disk_free_space($drive); $tot = @disk_total_space($drive);
        if ($free !== false && $tot) {
            $out['drive_free_gb']  = round($free / 1073741824, 1);
            $out['drive_total_gb'] = round($tot / 1073741824, 1);
            $out['drive_used_pct'] = round(100 - $free * 100 / $tot);
        }
        $out['drive_mounted'] = true;
    } elseif ($drive !== '') {
        $out['drive_mounted'] = false;
    }
    if (($out['result'] ?? '') === 'failed')            $out['verdict'] = 'failed';
    elseif (!$lastOk)                                   $out['verdict'] = 'failed';
    elseif ($out['age_hours'] > $max)                   $out['verdict'] = 'stale';
    elseif (($out['drive_used_pct'] ?? 0) >= 90)        $out['verdict'] = 'full';
    else                                                $out['verdict'] = 'ok';
    return $out;
}
