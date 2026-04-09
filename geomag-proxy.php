<?php
/**
 * Version: 1.5.5
 * geomag-proxy.php — NOAA Geomagnetic Data Proxy
 * Part of BPQ Dashboard Suite
 *
 * Proxies NOAA space weather data server-side to avoid CORS issues
 * and browser fetch failures. Called by bpq-rf-connections.html.
 *
 * Usage:
 *   ?data=kindex  — 3-day planetary K-index JSON
 *   ?data=dgd     — 30-day daily geomagnetic indices text
 */

// Cache settings — geomagnetic data changes every 3 hours
define('CACHE_DIR',   __DIR__ . '/cache/geomag/');
define('CACHE_TTL',   900);  // 15 minutes

$data = $_GET['data'] ?? 'kindex';

$sources = [
    'kindex' => [
        'url'   => 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json',
        'type'  => 'application/json',
        'cache' => 'kindex.json',
    ],
    'dgd' => [
        'url'   => 'https://services.swpc.noaa.gov/text/daily-geomagnetic-indices.txt',
        'type'  => 'text/plain',
        'cache' => 'dgd.txt',
    ],
];

if (!isset($sources[$data])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown data type']);
    exit;
}

$src = $sources[$data];
header('Content-Type: ' . $src['type']);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=' . CACHE_TTL . ', public');

// ── Try cache first ────────────────────────────────────────────────
$cacheFile = CACHE_DIR . $src['cache'];
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0775, true);
}

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
    echo file_get_contents($cacheFile);
    exit;
}

// ── Fetch from NOAA ────────────────────────────────────────────────
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $src['url'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'BPQ-Dashboard/1.5.5 (YOURCALL; sysop@example.com)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => ['Accept: */*'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && $response) {
    // Save to cache
    @file_put_contents($cacheFile, $response);
    echo $response;
} elseif (file_exists($cacheFile)) {
    // Serve stale cache on fetch failure
    header('X-Cache: STALE');
    echo file_get_contents($cacheFile);
} else {
    // Nothing available
    http_response_code(503);
    if ($src['type'] === 'application/json') {
        echo json_encode([
            'error'   => 'NOAA data unavailable',
            'detail'  => $curlErr ?: "HTTP $httpCode",
        ]);
    } else {
        echo "# NOAA data unavailable: " . ($curlErr ?: "HTTP $httpCode") . "\n";
    }
}
