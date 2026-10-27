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
 *   - 2026-10-06: a failed lookup (timeout/HTTP/QRZ error) never replaces a known
 *     location; only a definite "not found" from every source is cached for 7 days
 *   - 2026-10-10: a rejected QRZ login waits 30 min instead of retrying on every
 *     request; the real QRZ login error is logged; a non-subscriber account is an
 *     error, not "not found"; CLI check: php callsign-lookup.php --qrz-test [CALL]
 *   - 2026-10-19: QRZ login read from config.php ('qrz' => username/password);
 *     older bpqdash-config.php / tprfn-config.php files still work as a fallback
 *
 * Usage: callsign-lookup.php?call=W1AW
 *        callsign-lookup.php?calls=W1AW,K1ABC,VE3XYZ  (batch lookup)
 */

// CORS: config.php 'cors' (2026-10-20: was "*", which let any web site
// spend this station's QRZ lookups from its visitors' browsers)
require_once __DIR__ . '/includes/station-config.php';
bpq_cors_headers('GET');
header('Content-Type: application/json');

// Cache file for storing lookups (reduces API calls)
$CACHE_FILE = __DIR__ . '/callsign-cache.json';
$CACHE_EXPIRY = 86400 * 30; // 30 days
$NEGATIVE_CACHE_EXPIRY = 86400 * 7; // 7 days for "not found" entries
$FAILURE_RETRY = 3600; // 2026-10-06: retry after a failed lookup (timeout/error) in 1 hour, never 7 days
$PREFIX_FALLBACK_EXPIRY = 86400 * 7; // 2026-10-06: re-check country-level prefix guesses weekly
$LOOKUP_HEALTH_FILE = __DIR__ . '/cache/lookup-health.json'; // 2026-10-06: last lookup failures (read by daily check)

// QRZ.com session key cache
$QRZ_SESSION_FILE = __DIR__ . '/cache/qrz-session.json';

// ========== QRZ.COM CONFIGURATION ==========
// QRZ login (2026-10-19): config.php  'qrz' => ['username' => ..., 'password' => ...].
// Older installs kept qrz_username / qrz_password in a separate file; those are
// still read, outside the web root first, when config.php has no QRZ username.
// The first source with a username supplies both values. QRZ is optional:
// without it, US calls are still found through callook.info.
$QRZ_CONFIG_SOURCE = '';
$QRZ_USERNAME = '';
$QRZ_PASSWORD = '';
$QRZ_CONFIG_FILES = [__DIR__ . '/config.php'];
foreach (['bpqdash-config.php', 'tprfn-config.php'] as $legacy) {
    $QRZ_CONFIG_FILES[] = dirname(__DIR__) . '/' . $legacy;
    $QRZ_CONFIG_FILES[] = __DIR__ . '/' . $legacy;
}
unset($legacy);

/** QRZ username/password from one config file's array, or null. */
function qrzCredsFrom($cfg) {
    if (!is_array($cfg)) return null;
    if (isset($cfg['qrz']) && is_array($cfg['qrz']) && trim($cfg['qrz']['username'] ?? '') !== '') {
        return [trim($cfg['qrz']['username']), (string)($cfg['qrz']['password'] ?? '')];
    }
    if (trim($cfg['qrz_username'] ?? '') !== '') {
        return [trim($cfg['qrz_username']), (string)($cfg['qrz_password'] ?? '')];
    }
    return null;
}

foreach ($QRZ_CONFIG_FILES as $cfgFile) {
    if (!is_readable($cfgFile)) continue;
    $creds = qrzCredsFrom(include $cfgFile);
    if ($creds) {
        [$QRZ_USERNAME, $QRZ_PASSWORD] = $creds;   // password not trimmed; --qrz-test reports stray spaces
        $QRZ_CONFIG_SOURCE = $cfgFile;
        break;
    }
}
unset($cfgFile, $creds);
$QRZ_API_URL = 'https://xmldata.qrz.com/xml/current/';
$QRZ_AGENT = 'BPQ-Dashboard-1.5';
$QRZ_LOGIN_BACKOFF = 1800;   // 2026-10-10: after QRZ rejects the login, wait 30 min before trying again
$QRZ_LAST_LOGIN_ERROR = '';

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
    global $QRZ_USERNAME, $QRZ_PASSWORD, $QRZ_API_URL, $QRZ_SESSION_FILE, $QRZ_AGENT,
           $QRZ_LOGIN_BACKOFF, $QRZ_LAST_LOGIN_ERROR;

    $QRZ_LAST_LOGIN_ERROR = '';
    if (empty($QRZ_USERNAME) || empty($QRZ_PASSWORD)) {
        $QRZ_LAST_LOGIN_ERROR = 'no QRZ credentials configured';
        return null;
    }

    // Check for cached session key — or a recent rejected login
    $session = file_exists($QRZ_SESSION_FILE) ? json_decode(file_get_contents($QRZ_SESSION_FILE), true) : null;
    if ($session && !empty($session['key']) && (time() - ($session['time'] ?? 0)) < 7200) {
        // Session keys typically last ~24hrs, refresh every 2 hours to be safe
        return $session['key'];
    }
    // 2026-10-10: before, a rejected login was retried on every page load / batch,
    // which keeps sending a wrong password to QRZ. Now it waits $QRZ_LOGIN_BACKOFF.
    // Deleting cache/qrz-session.json (or fixing the password) clears this.
    if ($session && !empty($session['login_failed_at'])
            && (time() - $session['login_failed_at']) < $QRZ_LOGIN_BACKOFF
            && ($session['user'] ?? '') === $QRZ_USERNAME
            && ($session['pw_hash'] ?? '') === md5($QRZ_PASSWORD)) {
        $QRZ_LAST_LOGIN_ERROR = ($session['login_error'] ?? 'login failed') . ' (waiting before retry)';
        return null;
    }

    // Login to QRZ.com (2026-10-10: curl, like the lookups, so HTTP/network failures are reported)
    $login = qrzLogin();
    $dir = dirname($QRZ_SESSION_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    if ($login['key']) {
        file_put_contents($QRZ_SESSION_FILE, json_encode([
            'key' => $login['key'], 'time' => time(), 'subexp' => $login['subexp'],
        ]), LOCK_EX);
        return $login['key'];
    }

    $QRZ_LAST_LOGIN_ERROR = $login['error'];
    error_log('QRZ login error: ' . $login['error']);
    // Only back off when QRZ itself answered (wrong password, account problem).
    // A network hiccup is retried normally.
    if ($login['qrz_answered']) {
        file_put_contents($QRZ_SESSION_FILE, json_encode([
            'login_failed_at' => time(), 'login_error' => $login['error'],
            'user' => $QRZ_USERNAME, 'pw_hash' => md5($QRZ_PASSWORD),
        ]), LOCK_EX);
    }
    return null;
}

/**
 * One QRZ login request. Returns key (or null), error text, subscription
 * expiry text, and whether QRZ itself answered (vs a network/HTTP failure).
 */
function qrzLogin() {
    global $QRZ_USERNAME, $QRZ_PASSWORD, $QRZ_API_URL, $QRZ_AGENT;

    $loginUrl = $QRZ_API_URL . '?username=' . rawurlencode($QRZ_USERNAME)
              . '&password=' . rawurlencode($QRZ_PASSWORD) . '&agent=' . rawurlencode($QRZ_AGENT);
    $r = curlMultiFetch(['login' => $loginUrl], 10, 5)['login'];

    $out = ['key' => null, 'error' => '', 'subexp' => '', 'qrz_answered' => false, 'raw' => $r['body']];
    if ($r['code'] !== 200 || $r['body'] === null) {
        $out['error'] = trim("HTTP {$r['code']} {$r['err']}");
        return $out;
    }
    $s = parseQRZSession($r['body']);
    if ($s === null) {
        $out['error'] = 'unreadable login response';
        return $out;
    }
    $out['qrz_answered'] = true;
    $out['subexp'] = $s['subexp'];
    if ($s['key'] !== '') {
        $out['key'] = $s['key'];             // a key is usable even if QRZ adds an Error/Message
        if ($s['error'] !== '') error_log('QRZ login note: ' . $s['error']);
        return $out;
    }
    $out['error'] = $s['error'] !== '' ? $s['error'] : 'no session key in login response';
    return $out;
}

/**
 * Read the <Session> block of any QRZ XML response.
 * Returns ['key','error','subexp','message'] (empty strings when absent) or null if not XML.
 */
function parseQRZSession($xml) {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$doc) return null;

    $namespaces = $doc->getNamespaces(true);
    $p = '';
    if (!empty($namespaces)) {
        $doc->registerXPathNamespace('qrz', reset($namespaces));
        $p = 'qrz:';
    }
    $get = function ($field) use ($doc, $p) {
        $n = $doc->xpath("//{$p}Session/{$p}{$field}");
        return !empty($n) ? trim((string)$n[0]) : '';
    };
    return ['key' => $get('Key'), 'error' => $get('Error'), 'subexp' => $get('SubExp'), 'message' => $get('Message')];
}

/**
 * Extract session key from QRZ XML response (kept for compatibility)
 */
function parseQRZSessionKey($xml) {
    $s = parseQRZSession($xml);
    return ($s && $s['key'] !== '') ? $s['key'] : null;
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

// ========== LOOKUP OUTCOMES (2026-10-06) ==========
//
// Every source reports one of these per callsign:
//   ['status' => 'found', 'record' => [...]]  — a real location
//   ['status' => 'not_found']                 — the source answered: no such call, or no location on file
//   ['status' => 'error', 'reason' => '...']  — timeout, HTTP error, QRZ login/rate-limit, bad response
//   ['status' => 'skipped']                   — source not configured (no QRZ credentials)
//
// Before 2026-10-06 an 'error' was treated exactly like 'not_found', so a single
// failed batch (QRZ or network down) replaced expired-but-good locations with
// 7-day "not_found" entries, or with a country-level prefix guess. Now:
//   - a lookup error NEVER replaces a known real location (it is kept, marked
//     stale, and re-tried after $FAILURE_RETRY);
//   - "not found" is only cached for 7 days when every source answered not_found;
//   - a prefix guess never replaces a real location;
//   - failures are written to the PHP error log and cache/lookup-health.json.

/**
 * Fetch several URLs in parallel (at most $maxConcurrent at a time).
 * Returns key => ['code' => int, 'body' => string|null, 'err' => string]
 */
function curlMultiFetch(array $urls, $timeout, $connectTimeout, $maxConcurrent = 8) {
    $out = [];
    foreach (array_chunk($urls, $maxConcurrent, true) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_USERAGENT => 'BPQDASH-Network-Map/1.0',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running > 0);

        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $out[$key] = [
                'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body' => ($body === '' ? null : $body),
                'err'  => curl_error($ch),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

// ========== QRZ.COM LOOKUP FUNCTIONS ==========

/**
 * Parse a QRZ.com XML callsign response into an outcome (see top of section)
 */
function parseQRZResponse($callsign, $xml) {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();

    if (!$doc) return ['status' => 'error', 'reason' => 'qrz: unreadable response'];

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

    if (!empty($errors)) {
        $errMsg = trim((string)$errors[0]);
        if (stripos($errMsg, 'Session Timeout') !== false || stripos($errMsg, 'Invalid session') !== false) {
            invalidateQRZSession();
            return ['status' => 'error', 'reason' => 'session_expired'];
        }
        if (stripos($errMsg, 'not found') !== false) {
            return ['status' => 'not_found'];          // QRZ answered: no such callsign
        }
        return ['status' => 'error', 'reason' => 'qrz: ' . $errMsg];   // rate limit, account problem, etc.
    }

    if (empty($callNodes)) return ['status' => 'error', 'reason' => 'qrz: no callsign record in response'];

    $record = $callNodes[0];

    $lat = floatval((string)($record->lat ?? 0));
    $lon = floatval((string)($record->lon ?? 0));
    if ($lat == 0 && $lon == 0) {
        // 2026-10-10: without an XML Logbook Data subscription QRZ still answers,
        // but leaves out lat/lon for EVERY call. That is an account problem, not
        // "no location on file" — before, it put each call in the 7-day not-found cache.
        $s = parseQRZSession($xml);
        if ($s && stripos($s['subexp'], 'non-subscriber') !== false) {
            return ['status' => 'error', 'reason' => 'qrz: account has no XML data subscription (SubExp: non-subscriber)'];
        }
        return ['status' => 'not_found'];   // QRZ has the call but no location
    }

    $country = (string)($record->country ?? '');
    $state = (string)($record->state ?? '');
    $city = (string)($record->addr2 ?? '');
    $grid = (string)($record->grid ?? '');
    $fname = trim((string)($record->fname ?? ''));
    $lname = trim((string)($record->name ?? ''));
    $name = trim($fname . ' ' . $lname);

    return ['status' => 'found', 'record' => [
        'callsign' => $callsign,
        'name' => $name,
        'lat' => $lat,
        'lon' => $lon,
        'grid' => $grid,
        'city' => $city,
        'state' => $state,
        'country' => $country,
        'source' => 'qrz.com'
    ]];
}

/**
 * QRZ.com lookups for several callsigns. Returns callsign => outcome.
 * Retries once with a fresh login if QRZ reports the session expired.
 */
function parallelQRZ($callsigns) {
    global $QRZ_API_URL, $QRZ_USERNAME, $QRZ_PASSWORD, $QRZ_LAST_LOGIN_ERROR, $QRZ_AGENT;

    $out = [];
    if (empty($callsigns)) return $out;

    if (empty($QRZ_USERNAME) || empty($QRZ_PASSWORD)) {
        foreach ($callsigns as $call) $out[$call] = ['status' => 'skipped'];
        return $out;
    }

    $pending = array_values($callsigns);
    for ($attempt = 1; $attempt <= 2 && !empty($pending); $attempt++) {
        $sessionKey = getQRZSessionKey();
        if (!$sessionKey) {
            $why = $QRZ_LAST_LOGIN_ERROR !== '' ? " ($QRZ_LAST_LOGIN_ERROR)" : '';
            foreach ($pending as $call) $out[$call] = ['status' => 'error', 'reason' => "qrz: login failed$why"];
            return $out;
        }

        $urls = [];
        foreach ($pending as $call) {
            $urls[$call] = $QRZ_API_URL . '?s=' . urlencode($sessionKey) . '&callsign=' . urlencode($call)
                         . '&agent=' . rawurlencode($QRZ_AGENT);
        }
        $responses = curlMultiFetch($urls, 10, 5);

        $retry = [];
        foreach ($pending as $call) {
            $r = $responses[$call];
            if ($r['code'] !== 200 || $r['body'] === null) {
                $out[$call] = ['status' => 'error', 'reason' => trim("qrz: HTTP {$r['code']} {$r['err']}")];
                continue;
            }
            $outcome = parseQRZResponse($call, $r['body']);
            $out[$call] = $outcome;
            if ($outcome['status'] === 'error' && $outcome['reason'] === 'session_expired') {
                $retry[] = $call;
            }
        }

        if (!empty($retry)) invalidateQRZSession();
        $pending = $retry;
    }

    return $out;
}

// ========== CALLOOK.INFO FUNCTIONS (FALLBACK) ==========

/**
 * callook.info lookups (US callsigns). Returns callsign => outcome.
 * callook answers status INVALID for calls it doesn't know (incl. all non-US calls);
 * anything other than VALID/INVALID (e.g. UPDATING) is treated as a temporary error.
 */
function parallelCallook($callsigns) {
    $out = [];
    if (empty($callsigns)) return $out;

    $urls = [];
    foreach ($callsigns as $call) {
        $urls[$call] = 'https://callook.info/' . rawurlencode($call) . '/json';
    }
    $responses = curlMultiFetch($urls, 5, 3);

    foreach ($callsigns as $call) {
        $r = $responses[$call];
        if ($r['code'] !== 200 || $r['body'] === null) {
            $out[$call] = ['status' => 'error', 'reason' => trim("callook: HTTP {$r['code']} {$r['err']}")];
            continue;
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            $out[$call] = ['status' => 'error', 'reason' => 'callook: unreadable response'];
            continue;
        }
        $status = strtoupper($data['status'] ?? '');
        if ($status === 'INVALID') {
            $out[$call] = ['status' => 'not_found'];
            continue;
        }
        if ($status !== 'VALID') {
            $out[$call] = ['status' => 'error', 'reason' => 'callook: status ' . ($status ?: 'missing')];
            continue;
        }
        $record = parseCallookResponse($call, $r['body']);
        $out[$call] = $record ? ['status' => 'found', 'record' => $record] : ['status' => 'not_found'];
    }

    return $out;
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

// ========== CACHE RULES ==========

function entryHasLocation($entry) {
    return is_array($entry) && isset($entry['lat'], $entry['lon'])
        && ($entry['lat'] != 0 || $entry['lon'] != 0);
}

/**
 * Decide what a cache entry means right now.
 * Returns ['hit', entry] (use it), ['skip', null] (known nothing — don't look up yet)
 * or ['miss', null] (look it up).
 */
function cacheState($entry) {
    global $CACHE_EXPIRY, $NEGATIVE_CACHE_EXPIRY, $PREFIX_FALLBACK_EXPIRY;

    if (!is_array($entry)) return ['miss', null];
    $now = time();
    $hasLoc = entryHasLocation($entry);

    // Entries written since 2026-10-06 carry an explicit retry time
    if (!empty($entry['retry_after'])) {
        if ($entry['retry_after'] > $now) return $hasLoc ? ['hit', $entry] : ['skip', null];
        return ['miss', null];
    }

    // Older entries: age-based
    $age = $now - ($entry['cached_at'] ?? 0);
    if ($hasLoc) {
        $ttl = (($entry['source'] ?? '') === 'prefix-fallback') ? $PREFIX_FALLBACK_EXPIRY : $CACHE_EXPIRY;
        return ($age < $ttl) ? ['hit', $entry] : ['miss', null];
    }
    if (!empty($entry['not_found']) && $age < $NEGATIVE_CACHE_EXPIRY) return ['skip', null];
    return ['miss', null];
}

/**
 * Turn the per-source outcomes for one callsign into a cache entry + result.
 */
function resolveLookup($baseCall, array $outcomes) {
    global $cache, $cacheModified, $FAILURE_RETRY, $NEGATIVE_CACHE_EXPIRY;

    $now = time();

    foreach ($outcomes as $outcome) {
        if (($outcome['status'] ?? '') === 'found') {
            $record = $outcome['record'];
            $record['cached_at'] = $now;
            $cache[$baseCall] = $record;
            $cacheModified = true;
            return $record;
        }
    }

    $errors = [];
    foreach ($outcomes as $src => $outcome) {
        if (($outcome['status'] ?? '') === 'error') $errors[] = $outcome['reason'] ?? "$src error";
    }
    $definite = empty($errors);   // every source asked answered "not found"
    $retryAfter = $now + ($definite ? $NEGATIVE_CACHE_EXPIRY : $FAILURE_RETRY);
    if (!$definite) noteLookupFailure($baseCall, $errors);

    // 1. Keep the last real location we had — a failed or empty lookup never removes it
    $prior = $cache[$baseCall] ?? null;
    if (entryHasLocation($prior) && ($prior['source'] ?? '') !== 'prefix-fallback') {
        $prior['stale'] = true;
        $prior['retry_after'] = $retryAfter;
        $prior['last_check'] = $now;
        $prior['last_check_result'] = $definite ? 'not_found' : 'lookup_error';
        $cache[$baseCall] = $prior;
        $cacheModified = true;
        return $prior;
    }

    // 2. Country-level guess from the callsign prefix, if there is one
    $guess = prefixFallback($baseCall);
    if ($guess) {
        $guess['cached_at'] = $now;
        $guess['retry_after'] = $retryAfter;
        $cache[$baseCall] = $guess;
        $cacheModified = true;
        return $guess;
    }

    // 3. Nothing known
    $entry = ['callsign' => $baseCall, 'not_found' => true, 'cached_at' => $now, 'retry_after' => $retryAfter];
    if (!$definite) $entry['lookup_error'] = true;
    $cache[$baseCall] = $entry;
    $cacheModified = true;
    return null;
}

// ========== LOOKUP FAILURE LOG ==========

$lookupFailures = [];

function noteLookupFailure($callsign, array $reasons) {
    global $lookupFailures;
    $lookupFailures[$callsign] = implode('; ', array_unique($reasons));
}

function saveLookupHealth() {
    global $lookupFailures, $LOOKUP_HEALTH_FILE;
    if (empty($lookupFailures)) return;

    $summary = [];
    foreach ($lookupFailures as $call => $why) $summary[] = "$call ($why)";
    error_log('callsign-lookup: lookup failed, last known location kept / retry in 1h: ' . implode(', ', $summary));

    $health = [];
    if (file_exists($LOOKUP_HEALTH_FILE)) {
        $health = json_decode(file_get_contents($LOOKUP_HEALTH_FILE), true) ?: [];
    }
    $health['last_failure_at'] = time();
    $health['last_failure'] = $lookupFailures;
    $health['failure_count'] = ($health['failure_count'] ?? 0) + count($lookupFailures);
    $dir = dirname($LOOKUP_HEALTH_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($LOOKUP_HEALTH_FILE, json_encode($health, JSON_PRETTY_PRINT), LOCK_EX);
}

register_shutdown_function('saveLookupHealth');

// ========== LOOKUP FUNCTIONS ==========

/**
 * Lookup a single callsign (same rules as the batch lookup)
 */
function lookupCallsign($callsign) {
    $baseCall = preg_replace('/-\d+$/', '', strtoupper($callsign));
    $results = batchLookupCallsigns([$baseCall]);
    return $results[$baseCall] ?? null;
}

/**
 * Batch lookup with parallel HTTP requests
 */
function batchLookupCallsigns($callsigns) {
    global $cache, $MANUAL_LOCATIONS;

    $results = [];
    $needLookup = [];

    // First pass: manual locations, then cache
    foreach ($callsigns as $call) {
        $baseCall = preg_replace('/-\d+$/', '', strtoupper($call));

        // Manual locations always win
        if (isset($MANUAL_LOCATIONS[$baseCall])) {
            $results[$baseCall] = $MANUAL_LOCATIONS[$baseCall];
            continue;
        }

        [$state, $entry] = cacheState($cache[$baseCall] ?? null);
        if ($state === 'hit') {
            $results[$baseCall] = $entry;
            continue;
        }
        if ($state === 'skip') continue;

        $needLookup[$baseCall] = true;
    }

    if (empty($needLookup)) return $results;
    $callsToLookup = array_keys($needLookup);

    // QRZ.com first (primary — handles international callsigns)
    $qrz = parallelQRZ($callsToLookup);

    // callook.info for anything QRZ didn't locate (fallback — US callsigns)
    $needCallook = [];
    foreach ($callsToLookup as $baseCall) {
        if (($qrz[$baseCall]['status'] ?? '') !== 'found') $needCallook[] = $baseCall;
    }
    $callook = parallelCallook($needCallook);

    foreach ($callsToLookup as $baseCall) {
        $outcomes = [];
        if (isset($qrz[$baseCall]) && $qrz[$baseCall]['status'] !== 'skipped') $outcomes['qrz'] = $qrz[$baseCall];
        if (isset($callook[$baseCall])) $outcomes['callook'] = $callook[$baseCall];
        $record = resolveLookup($baseCall, $outcomes);
        if ($record) $results[$baseCall] = $record;
    }

    return $results;
}

// ========== QRZ DIAGNOSTIC (CLI only, 2026-10-10) ==========
// Run as the web user so it reads the same files the site does:
//   sudo -u www-data php /var/www/bpq-dashboard/callsign-lookup.php --qrz-test [CALLSIGN]
// Never prints the password; always does a fresh login (ignores the cached key
// and the 30-min wait) and does not change the callsign cache.
if (PHP_SAPI === 'cli' && in_array('--qrz-test', $argv ?? [], true)) {
    $testCall = 'W1AW';
    foreach (array_slice($argv, 1) as $a) if ($a !== '--qrz-test') $testCall = strtoupper($a);

    echo "QRZ diagnostic\n";
    foreach ($QRZ_CONFIG_FILES as $f) {
        $has = '';
        if (is_readable($f)) {
            $has = qrzCredsFrom(include $f) ? 'has a QRZ username' : 'no QRZ username';
        } elseif (file_exists($f)) {
            $has = 'not readable by this user';
        }
        printf("  config  %-40s %s%s\n", $f, file_exists($f) ? 'exists, ' . $has : 'missing',
               $f === $QRZ_CONFIG_SOURCE ? '  <-- used' : '');
    }
    if ($QRZ_USERNAME === '') { echo "  RESULT  no QRZ username configured (config.php 'qrz' => username) — QRZ lookups are skipped\n"; exit(1); }
    echo "  user    $QRZ_USERNAME\n";
    $pw = $QRZ_PASSWORD;
    $notes = [];
    if ($pw !== trim($pw)) $notes[] = 'HAS LEADING/TRAILING WHITESPACE';
    if (preg_match('/[^\x20-\x7E]/', $pw)) $notes[] = 'contains non-ASCII / control characters';
    printf("  password %d characters%s\n", strlen($pw), $notes ? ' — ' . implode(', ', $notes) : '');

    $sess = file_exists($QRZ_SESSION_FILE) ? json_decode(file_get_contents($QRZ_SESSION_FILE), true) : null;
    if (!empty($sess['login_failed_at'])) {
        echo '  cache   last login REJECTED at ' . date('Y-m-d H:i:s T', $sess['login_failed_at']) . ': ' . ($sess['login_error'] ?? '') . "\n";
    } elseif (!empty($sess['key'])) {
        echo '  cache   session key from ' . date('Y-m-d H:i:s T', $sess['time'] ?? 0) . "\n";
    }

    $login = qrzLogin();
    if (!$login['key']) {
        echo "  LOGIN   FAILED: {$login['error']}\n";
        if (stripos($login['error'], 'password') !== false || stripos($login['error'], 'username') !== false) {
            echo "          QRZ rejected the credentials. Check the password still matches qrz.com\n";
            echo "          (it changes if the QRZ account password was changed) and that it is in\n";
            echo "          SINGLE quotes in the config — in double quotes PHP rewrites any \$ in it.\n";
        }
        exit(1);
    }
    echo "  LOGIN   OK, subscription: " . ($login['subexp'] ?: '(not stated)') . "\n";
    if (stripos($login['subexp'], 'non-subscriber') !== false) {
        echo "          No XML Logbook Data subscription — QRZ will not return coordinates.\n";
    }
    $dir = dirname($QRZ_SESSION_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($QRZ_SESSION_FILE, json_encode(['key' => $login['key'], 'time' => time(), 'subexp' => $login['subexp']]), LOCK_EX);

    $res = parallelQRZ([$testCall])[$testCall];
    echo "  LOOKUP  $testCall: {$res['status']}";
    if ($res['status'] === 'found') printf(" — %.4f, %.4f %s %s", $res['record']['lat'], $res['record']['lon'], $res['record']['grid'], $res['record']['country']);
    if (!empty($res['reason'])) echo " — {$res['reason']}";
    echo "\n";
    $cacheModified = false;   // diagnostic never writes the callsign cache
    $lookupFailures = [];
    exit($res['status'] === 'found' ? 0 : 1);
}

// ========== REQUEST HANDLING ==========

// 2026-10-03: POST only, so nginx can require the admin login for it
// (GET lookups stay public for the maps).
if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        die(json_encode(['success' => false, 'error' => 'clear_cache requires POST']));
    }
    require_once __DIR__ . '/includes/web-login.php';   // 2026-10-04
    bpq_require_web_login('Clearing the callsign cache', true);
    if (file_exists($CACHE_FILE)) {
        unlink($CACHE_FILE);
        echo json_encode(['success' => true, 'message' => 'Cache cleared']);
    } else {
        echo json_encode(['success' => true, 'message' => 'No cache to clear']);
    }
    exit;
}

// ========== MANUAL LOCATION MANAGEMENT ==========

/**
 * Manual-location edits need the dashboard admin login (includes/web-login.php).
 * Answers JSON on refusal, so the Add Station dialog can show the reason.
 */
function require_location_admin($what) {
    require_once __DIR__ . '/includes/web-login.php';   // 2026-10-04
    bpq_require_web_login($what, true);
}

// Save a manual location (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_location') {
    header('Content-Type: application/json');
    require_location_admin('Saving manual station locations');
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
    require_location_admin('Deleting manual station locations');
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
