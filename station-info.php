<?php
/**
 * station-info.php — PUBLIC-SAFE station identity for the web pages (2026-09-26)
 *
 * Returns callsign / node / grid square and a map position that is the
 * CENTRE OF THE GRID SQUARE — never the exact lat/lon from config.php, and
 * never the email or anything secret. Pages use it instead of hard-coding
 * "K1AJD" and coordinates.
 *
 * config.php:  'station' => ['callsign'=>..., 'node'=>..., 'grid'=>'FN31PR', ...]
 * Without a grid square, the exact lat/lon is rounded to 1 decimal (~11 km).
 *
 * Also returns the CARTO map tile key from config.php 'map' => ['carto_key']
 * (2026-10-20). That key travels in every tile URL the browser requests, so
 * it is public by design; CARTO keys are limited by web address, not secret.
 */
require_once __DIR__ . '/includes/station-config.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

$s    = bpq_station();
$call = strtoupper(trim((string)($s['callsign'] ?? '')));
$grid = strtoupper(trim((string)($s['grid'] ?? '')));
$pos  = $grid !== '' ? bpq_grid_center($grid) : null;
$carto = trim((string)(bpq_config_section('map')['carto_key'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $carto)) $carto = '';

if (!$pos && isset($s['lat'], $s['lon']) && ((float)$s['lat'] != 0.0 || (float)$s['lon'] != 0.0)) {
    $pos = [round((float)$s['lat'], 1), round((float)$s['lon'], 1)];
}

echo json_encode([
    'callsign' => ($call !== '' && $call !== 'YOURCALL') ? $call : null,
    'node'     => isset($s['node']) ? strtoupper(trim((string)$s['node'])) : null,
    'grid'     => $grid !== '' ? $grid : null,
    'map_lat'  => $pos[0] ?? null,
    'map_lon'  => $pos[1] ?? null,
    'carto_key' => $carto !== '' ? $carto : null,
]);
