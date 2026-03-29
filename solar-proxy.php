<?php
// solar-proxy.php
// Place this file on your web server alongside the dashboard files
// This proxies the HamQSL solar XML data to avoid CORS issues

header('Content-Type: application/xml');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=3600'); // Cache for 1 hour

$url = 'https://www.hamqsl.com/solarxml.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'BPQ32-Dashboard/1.0');

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
