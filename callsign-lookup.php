<?php
/**
 * Callsign Location Lookup — Optimized
 * 
 * Looks up amateur radio callsign locations using QRZ.com (primary) and callook.info (fallback)
 * Returns latitude, longitude, and location details
 * 
 * Optimizations:
 *   - Batched cache writes (single write after all lookups)
 *   - Parallel HTTP requests via curl_multi for batch lookups
 *   - Negative cache for callsigns not found (avoids repeated failed API calls)
 *   - QRZ.com session key caching to minimize login requests
 * 
 * Usage: callsign-lookup.php?call=K1AJD
 *        callsign-lookup.php?calls=K1AJD,N3MEL,KP3FT  (batch lookup)
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Cache file for storing lookups (reduces API calls)
$CACHE_FILE = __DIR__ . '/callsign-cache.json';
$CACHE_EXPIRY = 86400 * 30; // 30 days
$NEGATIVE_CACHE_EXPIRY = 86400 * 7; // 7 days for "not found" entries

// QRZ.com session key cache
$QRZ_SESSION_FILE = __DIR__ . '/cache/qrz-session.json';

// ========== QRZ.COM CONFIGURATION ==========
// Load QRZ credentials from tprfn-config.php
$config = file_exists(__DIR__ . '/tprfn-config.php') ? include(__DIR__ . '/tprfn-config.php') : [];
$QRZ_USERNAME = $config['qrz_username'] ?? '';
$QRZ_PASSWORD = $config['qrz_password'] ?? '';
$QRZ_API_URL = 'https://xmldata.qrz.com/xml/current/';

// ========== MANUAL LOCATIONS ==========
// Known stations not found in public databases (nodes, repeaters, special calls)
// Add entries here for stations that API lookups can't resolve
$MANUAL_LOCATIONS = [
    'GB7BED' => ['callsign' => 'GB7BED', 'lat' => 52.1356, 'lon' => -0.4685, 'grid' => 'IO92od', 'city' => 'Bedford', 'state' => '', 'country' => 'England', 'source' => 'manual'],
];

// Load user-added manual locations from JSON file (takes priority over hardcoded)
$MANUAL_LOC_FILE = __DIR__ . '/manual-locations.json';
if (file_exists($MANUAL_LOC_FILE)) {
    $userManual = json_decode(file_get_contents($MANUAL_LOC_FILE), true);
    if (is_array($userManual)) {
        $MANUAL_LOCATIONS = array_merge($MANUAL_LOCATIONS, $userManual);
    }
}

// ========== PREFIX → COUNTRY FALLBACK ==========
// Approximate center coordinates for callsign prefixes when no exact location found
// Used as a last resort so stations at least appear on the correct continent
$PREFIX_FALLBACK = [
    'LU' => ['lat' => -34.60, 'lon' => -58.38, 'country' => 'Argentina'],
    'LW' => ['lat' => -34.60, 'lon' => -58.38, 'country' => 'Argentina'],
    'GB' => ['lat' => 52.00, 'lon' => -1.17,  'country' => 'England'],
    'G'  => ['lat' => 52.00, 'lon' => -1.17,  'country' => 'England'],
    'M'  => ['lat' => 52.00, 'lon' => -1.17,  'country' => 'England'],
    '2E' => ['lat' => 52.00, 'lon' => -1.17,  'country' => 'England'],
    'VK' => ['lat' => -33.87, 'lon' => 151.21, 'country' => 'Australia'],
    'DK' => ['lat' => 51.17, 'lon' => 10.45,  'country' => 'Germany'],
    'DL' => ['lat' => 51.17, 'lon' => 10.45,  'country' => 'Germany'],
    'DB' => ['lat' => 51.17, 'lon' => 10.45,  'country' => 'Germany'],
    'EI' => ['lat' => 53.35, 'lon' => -6.26,  'country' => 'Ireland'],
    'F'  => ['lat' => 46.60, 'lon' => 1.89,   'country' => 'France'],
    'VE' => ['lat' => 45.42, 'lon' => -75.69, 'country' => 'Canada'],
    'VA' => ['lat' => 45.42, 'lon' => -75.69, 'country' => 'Canada'],
    'JA' => ['lat' => 35.68, 'lon' => 139.69, 'country' => 'Japan'],
    'JH' => ['lat' => 35.68, 'lon' => 139.69, 'country' => 'Japan'],
    'ZL' => ['lat' => -41.29, 'lon' => 174.78, 'country' => 'New Zealand'],
    'ZS' => ['lat' => -33.93, 'lon' => 18.42,  'country' => 'South Africa'],
    'PY' => ['lat' => -23.55, 'lon' => -46.63, 'country' => 'Brazil'],
    'EA' => ['lat' => 40.42, 'lon' => -3.70,  'country' => 'Spain'],
    'I'  => ['lat' => 41.90, 'lon' => 12.50,  'country' => 'Italy'],
    'ON' => ['lat' => 50.85, 'lon' => 4.35,   'country' => 'Belgium'],
    'PA' => ['lat' => 52.37, 'lon' => 4.90,   'country' => 'Netherlands'],
    'SM' => ['lat' => 59.33, 'lon' => 18.07,  'country' => 'Sweden'],
    'LA' => ['lat' => 59.91, 'lon' => 10.75,  'country' => 'Norway'],
    'OH' => ['lat' => 60.17, 'lon' => 24.94,  'country' => 'Finland'],
    'OZ' => ['lat' => 55.68, 'lon' => 12.57,  'country' => 'Denmark'],
    'HB' => ['lat' => 46.95, 'lon' => 7.45,   'country' => 'Switzerland'],
    'OE' => ['lat' => 48.21, 'lon' => 16.37,  'country' => 'Austria'],
    'SP' => ['lat' => 52.23, 'lon' => 21.01,  'country' => 'Poland'],
    'HA' => ['lat' => 47.50, 'lon' => 19.04,  'country' => 'Hungary'],
    'OK' => ['lat' => 50.08, 'lon' => 14.44,  'country' => 'Czech Republic'],
    'YO' => ['lat' => 44.43, 'lon' => 26.10,  'country' => 'Romania'],
    'UA' => ['lat' => 55.76, 'lon' => 37.62,  'country' => 'Russia'],
    'KP' => ['lat' => 18.22, 'lon' => -66.59, 'country' => 'Puerto Rico'],
    'KH' => ['lat' => 21.31, 'lon' => -157.86,'country' => 'Hawaii'],
    'KL' => ['lat' => 61.22, 'lon' => -149.90,'country' => 'Alaska'],
];

/**
 * Look up a callsign prefix in the fallback table
 */
function prefixFallback($callsign) {
    global $PREFIX_FALLBACK;
    $call = strtoupper($callsign);
    
    // Try longest prefix first (2 chars), then 1 char
    for ($len = min(2, strlen($call)); $len >= 1; $len--) {
        $prefix = substr($call, 0, $len);
        if (isset($PREFIX_FALLBACK[$prefix])) {
            $fb = $PREFIX_FALLBACK[$prefix];
            return [
                'callsign' => $callsign,
                'name' => '',
                'lat' => $fb['lat'],
                'lon' => $fb['lon'],
                'grid' => '',
                'city' => '',
                'state' => '',
                'country' => $fb['country'],
                'source' => 'prefix-fallback'
            ];
        }
    }
    return null;
}

// Load cache
$cache = [];
if (file_exists($CACHE_FILE)) {
    $raw = file_get_contents($CACHE_FILE);
    if ($raw !== false) {
        $cache = json_decode($raw, true) ?: [];
    }
}
$cacheModified = false;

function saveCache() {
    global $cache, $CACHE_FILE, $cacheModified;
    if ($cacheModified) {
        file_put_contents($CACHE_FILE, json_encode($cache, JSON_PRETTY_PRINT), LOCK_EX);
    }
}

// Register shutdown to ensure cache is saved even on early exit
register_shutdown_function('saveCache');

// ========== QRZ.COM SESSION MANAGEMENT ==========

/**
 * Get a valid QRZ.com session key (cached or fresh login)
 */
function getQRZSessionKey() {
    global $QRZ_USERNAME, $QRZ_PASSWORD, $QRZ_API_URL, $QRZ_SESSION_FILE;
    
    if (empty($QRZ_USERNAME) || empty($QRZ_PASSWORD)) {
        return null;
    }
    
    // Check for cached session key
    if (file_exists($QRZ_SESSION_FILE)) {
        $session = json_decode(file_get_contents($QRZ_SESSION_FILE), true);
        if ($session && !empty($session['key']) && (time() - ($session['time'] ?? 0)) < 7200) {
            // Session keys typically last ~24hrs, refresh every 2 hours to be safe
            return $session['key'];
        }
    }
    
    // Login to QRZ.com
    $loginUrl = $QRZ_API_URL . '?username=' . urlencode($QRZ_USERNAME) . '&password=' . urlencode($QRZ_PASSWORD);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'TPRFN-Network-Map/1.0']]);
    $response = @file_get_contents($loginUrl, false, $ctx);
    
    if (!$response) return null;
    
    // Parse XML response for session key
    $key = parseQRZSessionKey($response);
    if ($key) {
        // Cache the session key
        $dir = dirname($QRZ_SESSION_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($QRZ_SESSION_FILE, json_encode(['key' => $key, 'time' => time()]), LOCK_EX);
        return $key;
    }
    
    return null;
}

/**
 * Extract session key from QRZ XML response
 */
function parseQRZSessionKey($xml) {
    // Suppress XML warnings
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();
    
    if (!$doc) return null;
    
    // Handle namespace
    $namespaces = $doc->getNamespaces(true);
    if (!empty($namespaces)) {
        $ns = reset($namespaces);
        $doc->registerXPathNamespace('qrz', $ns);
        $keys = $doc->xpath('//qrz:Session/qrz:Key');
        $errors = $doc->xpath('//qrz:Session/qrz:Error');
    } else {
        $keys = $doc->xpath('//Session/Key');
        $errors = $doc->xpath('//Session/Error');
    }
    
    if (!empty($errors)) {
        error_log('QRZ login error: ' . (string)$errors[0]);
        return null;
    }
    
    if (!empty($keys)) {
        return (string)$keys[0];
    }
    
    return null;
}

/**
 * Invalidate the cached QRZ session (forces re-login on next call)
 */
function invalidateQRZSession() {
    global $QRZ_SESSION_FILE;
    if (file_exists($QRZ_SESSION_FILE)) {
        @unlink($QRZ_SESSION_FILE);
    }
}

// ========== QRZ.COM LOOKUP FUNCTIONS ==========

/**
 * Parse a QRZ.com XML callsign response
 */
function parseQRZResponse($callsign, $xml) {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();
    
    if (!$doc) return null;
    
    // Handle namespace
    $namespaces = $doc->getNamespaces(true);
    if (!empty($namespaces)) {
        $ns = reset($namespaces);
        $doc->registerXPathNamespace('qrz', $ns);
        $callNodes = $doc->xpath('//qrz:Callsign');
        $errors = $doc->xpath('//qrz:Session/qrz:Error');
    } else {
        $callNodes = $doc->xpath('//Callsign');
        $errors = $doc->xpath('//Session/Error');
    }
    
    // Check for errors (session timeout, not found, etc.)
    if (!empty($errors)) {
        $errMsg = (string)$errors[0];
        if (stripos($errMsg, 'Session Timeout') !== false || stripos($errMsg, 'Invalid session') !== false) {
            invalidateQRZSession();
            return ['error' => 'session_expired'];
        }
        return null; // Not found or other error
    }
    
    if (empty($callNodes)) return null;
    
    $record = $callNodes[0];
    
    $lat = floatval((string)($record->lat ?? 0));
    $lon = floatval((string)($record->lon ?? 0));
    if ($lat == 0 && $lon == 0) return null;
    
    $country = (string)($record->country ?? '');
    $state = (string)($record->state ?? '');
    $city = (string)($record->addr2 ?? '');
    $grid = (string)($record->grid ?? '');
    $fname = trim((string)($record->fname ?? ''));
    $lname = trim((string)($record->name ?? ''));
    $name = trim($fname . ' ' . $lname);
    
    return [
        'callsign' => $callsign,
        'name' => $name,
        'lat' => $lat,
        'lon' => $lon,
        'grid' => $grid,
        'city' => $city,
        'state' => $state,
        'country' => $country,
        'source' => 'qrz.com'
    ];
}

/**
 * Single QRZ.com lookup (used by single-callsign endpoint)
 */
function lookupQRZ($callsign) {
    global $QRZ_API_URL;
    
    $sessionKey = getQRZSessionKey();
    if (!$sessionKey) return null;
    
    $url = $QRZ_API_URL . '?s=' . urlencode($sessionKey) . '&callsign=' . urlencode($callsign);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'TPRFN-Network-Map/1.0']]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return null;
    
    $result = parseQRZResponse($callsign, $response);
    
    // If session expired, try once more with a fresh login
    if (is_array($result) && isset($result['error']) && $result['error'] === 'session_expired') {
        invalidateQRZSession();
        $sessionKey = getQRZSessionKey();
        if (!$sessionKey) return null;
        
        $url = $QRZ_API_URL . '?s=' . urlencode($sessionKey) . '&callsign=' . urlencode($callsign);
        $response = @file_get_contents($url, false, $ctx);
        if (!$response) return null;
        
        $result = parseQRZResponse($callsign, $response);
        if (is_array($result) && isset($result['error'])) return null;
    }
    
    return $result;
}

/**
 * Parallel QRZ.com lookups using curl_multi
 * QRZ.com API is sequential per session but we can still batch efficiently
 */
function parallelQRZ($callsigns) {
    global $QRZ_API_URL;
    
    if (empty($callsigns)) return [];
    
    $sessionKey = getQRZSessionKey();
    if (!$sessionKey) return [];
    
    $mh = curl_multi_init();
    $handles = [];
    
    foreach ($callsigns as $call) {
        $url = $QRZ_API_URL . '?s=' . urlencode($sessionKey) . '&callsign=' . urlencode($call);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'TPRFN-Network-Map/1.0',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$call] = $ch;
    }
    
    // Execute all requests in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.5);
        }
    } while ($running > 0);
    
    // Collect results
    $results = [];
    $sessionExpired = false;
    foreach ($handles as $call => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $parsed = parseQRZResponse($call, $response);
            if (is_array($parsed) && isset($parsed['error']) && $parsed['error'] === 'session_expired') {
                $sessionExpired = true;
            } elseif ($parsed) {
                $results[$call] = $parsed;
            }
        }
    }
    
    curl_multi_close($mh);
    
    // If session expired, invalidate and retry all failed callsigns
    if ($sessionExpired) {
        invalidateQRZSession();
        $retryList = array_diff($callsigns, array_keys($results));
        if (!empty($retryList)) {
            $sessionKey = getQRZSessionKey();
            if ($sessionKey) {
                $mh = curl_multi_init();
                $handles = [];
                
                foreach ($retryList as $call) {
                    $url = $QRZ_API_URL . '?s=' . urlencode($sessionKey) . '&callsign=' . urlencode($call);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_USERAGENT => 'TPRFN-Network-Map/1.0',
                        CURLOPT_FOLLOWLOCATION => true,
                    ]);
                    curl_multi_add_handle($mh, $ch);
                    $handles[$call] = $ch;
                }
                
                $running = null;
                do {
                    curl_multi_exec($mh, $running);
                    if ($running > 0) {
                        curl_multi_select($mh, 0.5);
                    }
                } while ($running > 0);
                
                foreach ($handles as $call => $ch) {
                    $response = curl_multi_getcontent($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    
                    if ($httpCode === 200 && $response) {
                        $parsed = parseQRZResponse($call, $response);
                        if ($parsed && !isset($parsed['error'])) {
                            $results[$call] = $parsed;
                        }
                    }
                }
                
                curl_multi_close($mh);
            }
        }
    }
    
    return $results;
}

// ========== CALLOOK.INFO FUNCTIONS (FALLBACK) ==========

/**
 * Parallel callook.info lookups using curl_multi (fallback for QRZ failures)
 */
function parallelCallook($callsigns) {
    if (empty($callsigns)) return [];
    
    $mh = curl_multi_init();
    $handles = [];
    
    foreach ($callsigns as $call) {
        $ch = curl_init("https://callook.info/{$call}/json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'TPRFN-Network-Map/1.0',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$call] = $ch;
    }
    
    // Execute all requests in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.5);
        }
    } while ($running > 0);
    
    // Collect results
    $results = [];
    foreach ($handles as $call => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $parsed = parseCallookResponse($call, $response);
            if ($parsed) $results[$call] = $parsed;
        }
    }
    
    curl_multi_close($mh);
    return $results;
}

function parseCallookResponse($callsign, $response) {
    $data = json_decode($response, true);
    if (!$data || ($data['status'] ?? '') !== 'VALID') return null;
    
    $location = $data['location'] ?? [];
    $address = $data['address'] ?? [];
    
    $city = '';
    $state = '';
    $line2 = trim($address['line2'] ?? '');
    if ($line2) {
        if (preg_match('/^(.+?),\s*([A-Z]{2})(?:\s+\d{5})?/', $line2, $matches)) {
            $city = trim($matches[1]);
            $state = trim($matches[2]);
        } else {
            $city = $line2;
        }
    }
    
    $lat = floatval($location['latitude'] ?? 0);
    $lon = floatval($location['longitude'] ?? 0);
    if ($lat == 0 && $lon == 0) return null;
    
    return [
        'callsign' => $callsign,
        'name' => $data['name'] ?? '',
        'lat' => $lat,
        'lon' => $lon,
        'grid' => $location['gridsquare'] ?? '',
        'city' => $city,
        'state' => $state,
        'country' => 'USA',
        'source' => 'callook.info'
    ];
}

// Legacy single-call functions (used by single-callsign endpoint)
function lookupCallook($callsign) {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'TPRFN-Network-Map/1.0']]);
    $response = @file_get_contents("https://callook.info/{$callsign}/json", false, $ctx);
    if (!$response) return null;
    return parseCallookResponse($callsign, $response);
}

// ========== LOOKUP FUNCTIONS ==========

/**
 * Lookup a single callsign (checks cache first)
 */
function lookupCallsign($callsign) {
    global $cache, $CACHE_EXPIRY, $NEGATIVE_CACHE_EXPIRY, $cacheModified, $MANUAL_LOCATIONS;
    
    $baseCall = preg_replace('/-\d+$/', '', strtoupper($callsign));
    
    // Check manual locations first (always takes priority)
    if (isset($MANUAL_LOCATIONS[$baseCall])) {
        return $MANUAL_LOCATIONS[$baseCall];
    }
    
    // Check cache
    if (isset($cache[$baseCall])) {
        $entry = $cache[$baseCall];
        $age = time() - ($entry['cached_at'] ?? 0);
        
        // Valid positive cache hit
        if (isset($entry['lat']) && isset($entry['lon']) 
            && ($entry['lat'] != 0 || $entry['lon'] != 0) 
            && $age < $CACHE_EXPIRY) {
            return $entry;
        }
        
        // Valid negative cache hit (not found)
        if (!empty($entry['not_found']) && $age < $NEGATIVE_CACHE_EXPIRY) {
            return null;
        }
    }
    
    // Try QRZ.com first (primary — international callsigns)
    $result = lookupQRZ($baseCall);
    
    // Try callook.info if QRZ fails (fallback — US callsigns)
    if (!$result || !isset($result['lat']) || ($result['lat'] == 0 && $result['lon'] == 0)) {
        $callookResult = lookupCallook($baseCall);
        if ($callookResult && isset($callookResult['lat']) && ($callookResult['lat'] != 0 || $callookResult['lon'] != 0)) {
            $result = $callookResult;
        }
    }
    
    // Try prefix-based fallback as last resort
    if (!$result || !isset($result['lat']) || ($result['lat'] == 0 && $result['lon'] == 0)) {
        $prefixResult = prefixFallback($baseCall);
        if ($prefixResult) {
            $result = $prefixResult;
        }
    }
    
    // Cache the result
    if ($result && isset($result['lat']) && ($result['lat'] != 0 || $result['lon'] != 0)) {
        $result['cached_at'] = time();
        $cache[$baseCall] = $result;
        $cacheModified = true;
    } else {
        // Negative cache
        $cache[$baseCall] = ['callsign' => $baseCall, 'not_found' => true, 'cached_at' => time()];
        $cacheModified = true;
    }
    
    return $result;
}

/**
 * Batch lookup with parallel HTTP requests
 */
function batchLookupCallsigns($callsigns) {
    global $cache, $CACHE_EXPIRY, $NEGATIVE_CACHE_EXPIRY, $cacheModified, $MANUAL_LOCATIONS;
    
    $results = [];
    $needLookup = [];
    
    // First pass: check manual locations, then cache
    foreach ($callsigns as $call) {
        $baseCall = preg_replace('/-\d+$/', '', strtoupper($call));
        
        // Manual locations always win
        if (isset($MANUAL_LOCATIONS[$baseCall])) {
            $results[$baseCall] = $MANUAL_LOCATIONS[$baseCall];
            continue;
        }
        
        if (isset($cache[$baseCall])) {
            $entry = $cache[$baseCall];
            $age = time() - ($entry['cached_at'] ?? 0);
            
            if (isset($entry['lat']) && isset($entry['lon']) 
                && ($entry['lat'] != 0 || $entry['lon'] != 0)
                && $age < $CACHE_EXPIRY) {
                $results[$baseCall] = $entry;
                continue;
            }
            
            if (!empty($entry['not_found']) && $age < $NEGATIVE_CACHE_EXPIRY) {
                continue; // Skip — known not-found
            }
        }
        
        $needLookup[$baseCall] = true;
    }
    
    // Second pass: parallel lookups for uncached callsigns
    if (!empty($needLookup)) {
        $callsToLookup = array_keys($needLookup);
        
        // Try QRZ.com first (primary — handles international callsigns)
        $qrzResults = parallelQRZ($callsToLookup);
        
        // Identify which ones still need callook.info fallback
        $needCallook = [];
        foreach ($callsToLookup as $baseCall) {
            if (isset($qrzResults[$baseCall]) && $qrzResults[$baseCall]['lat'] != 0) {
                $qrzResults[$baseCall]['cached_at'] = time();
                $cache[$baseCall] = $qrzResults[$baseCall];
                $results[$baseCall] = $qrzResults[$baseCall];
                $cacheModified = true;
            } else {
                $needCallook[] = $baseCall;
            }
        }
        
        // Try callook.info for remaining (fallback — parallel)
        if (!empty($needCallook)) {
            $callookResults = parallelCallook($needCallook);
            
            $stillMissing = [];
            foreach ($needCallook as $baseCall) {
                if (isset($callookResults[$baseCall]) && $callookResults[$baseCall]['lat'] != 0) {
                    $callookResults[$baseCall]['cached_at'] = time();
                    $cache[$baseCall] = $callookResults[$baseCall];
                    $results[$baseCall] = $callookResults[$baseCall];
                    $cacheModified = true;
                } else {
                    $stillMissing[] = $baseCall;
                }
            }
            
            // Try prefix fallback for anything still missing
            foreach ($stillMissing as $baseCall) {
                $prefixResult = prefixFallback($baseCall);
                if ($prefixResult) {
                    $prefixResult['cached_at'] = time();
                    $cache[$baseCall] = $prefixResult;
                    $results[$baseCall] = $prefixResult;
                    $cacheModified = true;
                } else {
                    // Negative cache
                    $cache[$baseCall] = ['callsign' => $baseCall, 'not_found' => true, 'cached_at' => time()];
                    $cacheModified = true;
                }
            }
        }
    }
    
    return $results;
}

// ========== REQUEST HANDLING ==========

if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
    if (file_exists($CACHE_FILE)) {
        unlink($CACHE_FILE);
        echo json_encode(['success' => true, 'message' => 'Cache cleared']);
    } else {
        echo json_encode(['success' => true, 'message' => 'No cache to clear']);
    }
    exit;
}

// ========== MANUAL LOCATION MANAGEMENT ==========

// Save a manual location (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_location') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['callsign'])) {
        echo json_encode(['success' => false, 'error' => 'Callsign required']);
        exit;
    }
    
    $call = preg_replace('/-\d+$/', '', strtoupper(trim($input['callsign'])));
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $call)) {
        echo json_encode(['success' => false, 'error' => 'Invalid callsign format']);
        exit;
    }
    
    $lat = null;
    $lon = null;
    $grid = trim($input['grid'] ?? '');
    
    // If grid provided, convert to lat/lon
    if (!empty($grid) && preg_match('/^[A-R]{2}\d{2}([a-x]{2}(\d{2})?)?$/i', $grid)) {
        $coords = gridToLatLon($grid);
        if ($coords) {
            $lat = $coords['lat'];
            $lon = $coords['lon'];
        }
    }
    
    // Direct lat/lon overrides grid conversion
    if (isset($input['lat']) && isset($input['lon']) && is_numeric($input['lat']) && is_numeric($input['lon'])) {
        $lat = floatval($input['lat']);
        $lon = floatval($input['lon']);
    }
    
    if ($lat === null || $lon === null) {
        echo json_encode(['success' => false, 'error' => 'Valid grid square or lat/lon required']);
        exit;
    }
    
    // Load existing manual locations
    $manualFile = __DIR__ . '/manual-locations.json';
    $manual = [];
    if (file_exists($manualFile)) {
        $manual = json_decode(file_get_contents($manualFile), true) ?: [];
    }
    
    // Save/update
    $manual[$call] = [
        'callsign' => $call,
        'lat' => round($lat, 6),
        'lon' => round($lon, 6),
        'grid' => strtoupper($grid),
        'city' => trim($input['city'] ?? ''),
        'state' => trim($input['state'] ?? ''),
        'country' => trim($input['country'] ?? ''),
        'source' => 'manual',
        'added_at' => time(),
    ];
    
    file_put_contents($manualFile, json_encode($manual, JSON_PRETTY_PRINT), LOCK_EX);
    
    // Also update the callsign cache so it's immediately available
    if (isset($cache[$call])) {
        $cache[$call] = $manual[$call];
        $cache[$call]['cached_at'] = time();
        $cacheModified = true;
    }
    
    echo json_encode(['success' => true, 'callsign' => $call, 'lat' => $manual[$call]['lat'], 'lon' => $manual[$call]['lon'], 'grid' => $manual[$call]['grid']]);
    exit;
}

// Delete a manual location (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_location') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['callsign'])) {
        echo json_encode(['success' => false, 'error' => 'Callsign required']);
        exit;
    }
    
    $call = preg_replace('/-\d+$/', '', strtoupper(trim($input['callsign'])));
    $manualFile = __DIR__ . '/manual-locations.json';
    $manual = [];
    if (file_exists($manualFile)) {
        $manual = json_decode(file_get_contents($manualFile), true) ?: [];
    }
    
    if (isset($manual[$call])) {
        unset($manual[$call]);
        file_put_contents($manualFile, json_encode($manual, JSON_PRETTY_PRINT), LOCK_EX);
        // Clear from callsign cache too
        if (isset($cache[$call])) {
            unset($cache[$call]);
            $cacheModified = true;
        }
        echo json_encode(['success' => true, 'deleted' => $call]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Not found in manual locations']);
    }
    exit;
}

// List manual locations (GET)
if (isset($_GET['action']) && $_GET['action'] === 'list_manual') {
    header('Content-Type: application/json');
    $manualFile = __DIR__ . '/manual-locations.json';
    $manual = [];
    if (file_exists($manualFile)) {
        $manual = json_decode(file_get_contents($manualFile), true) ?: [];
    }
    echo json_encode(['success' => true, 'locations' => $manual, 'count' => count($manual)]);
    exit;
}

/**
 * Convert Maidenhead grid square to lat/lon (center of grid)
 */
function gridToLatLon($grid) {
    $grid = strtoupper(trim($grid));
    $len = strlen($grid);
    if ($len < 4) return null;
    
    $lon = (ord($grid[0]) - ord('A')) * 20 - 180;
    $lat = (ord($grid[1]) - ord('A')) * 10 - 90;
    $lon += intval($grid[2]) * 2;
    $lat += intval($grid[3]) * 1;
    
    if ($len >= 6) {
        $lon += (ord(strtoupper($grid[4])) - ord('A')) * (2.0 / 24);
        $lat += (ord(strtoupper($grid[5])) - ord('A')) * (1.0 / 24);
        $lon += 1.0 / 24; // Center of sub-square
        $lat += 0.5 / 24;
    } else {
        $lon += 1; // Center of square
        $lat += 0.5;
    }
    
    return ['lat' => round($lat, 6), 'lon' => round($lon, 6)];
}

// ========== STANDARD LOOKUP HANDLERS ==========

if (isset($_GET['call'])) {
    // Single callsign lookup
    $callsign = strtoupper(trim($_GET['call']));
    $result = lookupCallsign($callsign);
    
    if ($result) {
        echo json_encode(['success' => true, 'data' => $result]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Callsign not found', 'callsign' => $callsign]);
    }
    
} elseif (isset($_GET['calls'])) {
    // Batch lookup — uses parallel HTTP
    $callsigns = array_filter(array_map('trim', explode(',', strtoupper($_GET['calls']))));
    $callsigns = array_unique($callsigns);
    
    if (empty($callsigns)) {
        echo json_encode(['success' => true, 'data' => [], 'count' => 0]);
        exit;
    }
    
    $results = batchLookupCallsigns($callsigns);
    echo json_encode(['success' => true, 'data' => $results, 'count' => count($results)]);
    
} else {
    echo json_encode(['error' => 'Missing call or calls parameter']);
}
