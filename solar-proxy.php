<?php
/**
 * Solar Data Proxy
 * Version: 1.5.5
 * 
 * Proxies HamQSL solar XML data to avoid CORS issues
 */

// Simple security - check config exists (lightweight, no full bootstrap for proxy)
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Configuration required</error>';
    exit;
}

$config = require $configFile;

// Security headers
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: max-age=3600, public'); // Cache for 1 hour

// CORS
if ($config['cors']['allow_all'] ?? true) {
    header('Access-Control-Allow-Origin: *');
} else {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $config['cors']['allowed_origins'] ?? [])) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
}

$url = 'https://www.hamqsl.com/solarxml.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'BPQ-Dashboard/1.3.0');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response) {
    echo $response;
} else {
    // Return empty but valid XML on error
    echo '<?xml version="1.0" encoding="UTF-8"?><solar><calculatedconditions></calculatedconditions></solar>';
}
?>
