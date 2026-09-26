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
