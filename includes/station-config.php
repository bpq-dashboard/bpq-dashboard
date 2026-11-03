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
