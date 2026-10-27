<?php
/**
 * BPQ Dashboard Station Storage API
 * Version: 1.4.0
 * 
 * Stores managed station locations and forwarding partners as JSON files
 * on the server. This replaces browser localStorage for persistent,
 * cross-device storage of station map data.
 *
 * Endpoints:
 *   GET  ?action=locations          - Get all saved station locations
 *   GET  ?action=partners           - Get forwarding partner list
 *   GET  ?action=all                - Get both locations and partners
 *   POST {action: "saveLocations", locations: {...}}   - Save all locations
 *   POST {action: "savePartners",  partners: [...]}    - Save partner list
 *   POST {action: "deleteLocation", callsign: "XX0XX"} - Delete one location
 *   POST {action: "clearLocations"}                     - Delete all locations
 *   POST {action: "importLocations", locations: {...}}  - Merge imported locations
 *   GET  ?action=bbsinfo            - BBS station info shown on the APRS map (public)
 *   POST {action: "saveBbsInfo", info: {...}}           - Save BBS station info
 *
 * BBS station info (2026-10-16): what the APRS map's home (BBS) marker shows —
 * name, BBS callsign, location, sysop, ports/frequencies, services, hours,
 * website, notes and an optional marker position. Everything in it is
 * PUBLIC (the APRS map is a public page); the sysop chooses what to put in.
 * Stored in data/stations/bbs-info.json. Saving needs the admin login, like
 * every other POST here.
 */

// Station storage is a data-only endpoint — does not require BBS credentials
$SKIP_BBS_CHECK = true;

require_once __DIR__ . '/includes/bootstrap.php';

// Ensure JSON response
header('Content-Type: application/json');

// Storage configuration
$STORAGE_DIR = __DIR__ . '/data/stations/';
$LOCATIONS_FILE = $STORAGE_DIR . 'locations.json';
$PARTNERS_FILE = $STORAGE_DIR . 'partners.json';
$BBS_INFO_FILE = $STORAGE_DIR . 'bbs-info.json';
$MAX_STORAGE_SIZE = 2 * 1024 * 1024; // 2MB limit (locations are small)
$MAX_LOCATIONS = 500;  // Sanity limit on station count

// Default forwarding partners (used if no server file exists)
$DEFAULT_PARTNERS = [];  // Populate with your forwarding hub stations

// ===========================
// HELPER FUNCTIONS
// ===========================

/**
 * Ensure storage directory exists with protection
 */
function ensureStorageDir() {
    global $STORAGE_DIR;
    
    if (!file_exists($STORAGE_DIR)) {
        if (!mkdir($STORAGE_DIR, 0755, true)) {
            apiError('Cannot create station storage directory', 500);
        }
        
        // Protect directory from direct web access
        $htaccess = $STORAGE_DIR . '.htaccess';
        file_put_contents($htaccess, "Deny from all\n");
    }
    
    if (!is_writable($STORAGE_DIR)) {
        apiError('Station storage directory not writable', 500);
    }
}

/**
 * Read JSON file safely
 */
function readJsonFile($filepath, $default = null) {
    if (!file_exists($filepath)) {
        return $default;
    }
    
    $content = file_get_contents($filepath);
    if ($content === false) {
        return $default;
    }
    
    $data = json_decode($content, true);
    return ($data !== null) ? $data : $default;
}

/**
 * Write JSON file atomically
 */
function writeJsonFile($filepath, $data) {
    global $MAX_STORAGE_SIZE;
    
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (strlen($json) > $MAX_STORAGE_SIZE) {
        apiError('Storage limit exceeded (2MB max)', 400);
    }
    
    // Write atomically via temp file
    $temp = $filepath . '.tmp';
    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        apiError('Failed to write storage file', 500);
    }
    
    if (!rename($temp, $filepath)) {
        @unlink($temp);
        apiError('Failed to save storage file', 500);
    }
    
    return true;
}

// ===========================
// LOCATION FUNCTIONS
// ===========================

/**
 * Get all saved station locations
 */
function getLocations() {
    global $LOCATIONS_FILE;
    return readJsonFile($LOCATIONS_FILE, new stdClass());
}

/**
 * Save all station locations (full replace)
 */
function saveLocations($locations) {
    global $LOCATIONS_FILE, $MAX_LOCATIONS;
    
    if (!is_array($locations) && !is_object($locations)) {
        apiError('Invalid locations data');
    }
    
    // Convert to array for counting
    $locArray = (array)$locations;
    
    if (count($locArray) > $MAX_LOCATIONS) {
        apiError("Maximum $MAX_LOCATIONS station locations allowed", 400);
    }
    
    // Validate each location entry
    $clean = [];
    foreach ($locArray as $callsign => $loc) {
        $call = strtoupper(trim($callsign));
        if (strlen($call) < 3 || strlen($call) > 15) continue;
        
        if (!is_array($loc)) continue;
        if (!isset($loc['lat']) || !isset($loc['lon'])) continue;
        if (!is_numeric($loc['lat']) || !is_numeric($loc['lon'])) continue;
        
        $clean[$call] = [
            'lat'  => (float)$loc['lat'],
            'lon'  => (float)$loc['lon'],
            'grid' => isset($loc['grid']) ? strtoupper(substr(trim($loc['grid']), 0, 8)) : '',
            'name' => isset($loc['name']) ? substr(trim($loc['name']), 0, 50) : $call,
            'city' => isset($loc['city']) ? substr(trim($loc['city']), 0, 100) : ''
        ];
    }
    
    writeJsonFile($LOCATIONS_FILE, (object)$clean);
    
    return ['success' => true, 'count' => count($clean)];
}

/**
 * Delete a single station location
 */
function deleteLocation($callsign) {
    global $LOCATIONS_FILE;
    
    $callsign = strtoupper(trim($callsign));
    if (strlen($callsign) < 3) {
        apiError('Invalid callsign');
    }
    
    $locations = (array)getLocations();
    
    if (!isset($locations[$callsign])) {
        apiError('Station not found: ' . $callsign, 404);
    }
    
    unset($locations[$callsign]);
    writeJsonFile($LOCATIONS_FILE, (object)$locations);
    
    return ['success' => true, 'deleted' => $callsign, 'remaining' => count($locations)];
}

/**
 * Clear all saved locations
 */
function clearLocations() {
    global $LOCATIONS_FILE;
    
    writeJsonFile($LOCATIONS_FILE, new stdClass());
    
    return ['success' => true, 'cleared' => true];
}

/**
 * Import/merge locations (additive — existing locations preserved unless overwritten)
 */
function importLocations($imported) {
    global $LOCATIONS_FILE, $MAX_LOCATIONS;
    
    if (!is_array($imported) && !is_object($imported)) {
        apiError('Invalid import data');
    }
    
    $existing = (array)getLocations();
    $importArray = (array)$imported;
    $added = 0;
    $updated = 0;
    
    foreach ($importArray as $callsign => $loc) {
        $call = strtoupper(trim($callsign));
        if (strlen($call) < 3 || strlen($call) > 15) continue;
        
        if (!is_array($loc)) continue;
        if (!isset($loc['lat']) || !isset($loc['lon'])) continue;
        if (!is_numeric($loc['lat']) || !is_numeric($loc['lon'])) continue;
        
        $isNew = !isset($existing[$call]);
        
        $existing[$call] = [
            'lat'  => (float)$loc['lat'],
            'lon'  => (float)$loc['lon'],
            'grid' => isset($loc['grid']) ? strtoupper(substr(trim($loc['grid']), 0, 8)) : '',
            'name' => isset($loc['name']) ? substr(trim($loc['name']), 0, 50) : $call,
            'city' => isset($loc['city']) ? substr(trim($loc['city']), 0, 100) : ''
        ];
        
        if ($isNew) $added++; else $updated++;
    }
    
    if (count($existing) > $MAX_LOCATIONS) {
        apiError("Import would exceed $MAX_LOCATIONS station limit", 400);
    }
    
    writeJsonFile($LOCATIONS_FILE, (object)$existing);
    
    return ['success' => true, 'added' => $added, 'updated' => $updated, 'total' => count($existing)];
}

// ===========================
// PARTNER FUNCTIONS
// ===========================

/**
 * Get forwarding partners list
 */
function getPartners() {
    global $PARTNERS_FILE, $DEFAULT_PARTNERS;
    $partners = readJsonFile($PARTNERS_FILE, null);
    return ($partners !== null) ? $partners : $DEFAULT_PARTNERS;
}

/**
 * Save forwarding partners list
 */
function savePartners($partners) {
    global $PARTNERS_FILE;
    
    if (!is_array($partners)) {
        apiError('Partners must be an array');
    }
    
    // Validate and clean
    $clean = [];
    foreach ($partners as $p) {
        $call = strtoupper(trim($p));
        if (strlen($call) >= 3 && strlen($call) <= 15) {
            $clean[] = $call;
        }
    }
    
    if (count($clean) > 50) {
        apiError('Maximum 50 forwarding partners allowed', 400);
    }
    
    writeJsonFile($PARTNERS_FILE, $clean);
    
    return ['success' => true, 'count' => count($clean)];
}

// ===========================
// BBS STATION INFO (APRS map home marker) — 2026-10-16
// ===========================

/**
 * One line of plain text: control characters removed, spaces collapsed,
 * cut to $max characters (UTF-8 safe).
 */
function bbsInfoLine($v, $max) {
    if (!is_scalar($v)) return '';
    $s = (string)$v;
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    $s = trim(preg_replace('/\s{2,}/u', ' ', $s));
    return mb_substr($s, 0, $max, 'UTF-8');
}

/**
 * Several lines of plain text: each line cleaned like bbsInfoLine(),
 * empty lines dropped, at most $maxLines lines.
 */
function bbsInfoLines($v, $maxLines, $maxLen) {
    if (!is_scalar($v)) return '';
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$v) as $line) {
        $line = bbsInfoLine($line, $maxLen);
        if ($line !== '') $out[] = $line;
        if (count($out) >= $maxLines) break;
    }
    return implode("\n", $out);
}

/** Stored BBS info (empty object when nothing has been saved). */
function getBbsInfo() {
    global $BBS_INFO_FILE;
    $d = readJsonFile($BBS_INFO_FILE, null);
    return is_array($d) ? $d : new stdClass();
}

/** Validate and save the BBS info (full replace). */
function saveBbsInfo($info) {
    global $BBS_INFO_FILE;

    if (!is_array($info)) {
        apiError('Invalid BBS info');
    }

    $clean = [
        'name'        => bbsInfoLine($info['name']     ?? '', 60),
        'callsign'    => strtoupper(bbsInfoLine($info['callsign'] ?? '', 15)),
        'location'    => bbsInfoLine($info['location'] ?? '', 100),
        'sysop'       => bbsInfoLine($info['sysop']    ?? '', 60),
        'frequencies' => bbsInfoLines($info['frequencies'] ?? '', 12, 100),
        'services'    => bbsInfoLine($info['services'] ?? '', 150),
        'hours'       => bbsInfoLine($info['hours']    ?? '', 60),
        'website'     => bbsInfoLine($info['website']  ?? '', 200),
        'notes'       => bbsInfoLines($info['notes']   ?? '', 15, 300),
    ];

    if ($clean['callsign'] !== '' && !preg_match('/^[A-Z0-9]{1,8}(-[A-Z0-9]{1,2})?$/', $clean['callsign'])) {
        apiError('BBS callsign should look like CALL or CALL-SSID');
    }
    if (mb_strlen($clean['notes'], 'UTF-8') > 1000) {
        apiError('Notes are too long (1000 characters max)');
    }
    if ($clean['website'] !== '') {
        if (!preg_match('~^https?://[^\s"<>]+$~i', $clean['website'])
            || !filter_var($clean['website'], FILTER_VALIDATE_URL)) {
            apiError('Website must be a full http:// or https:// address');
        }
    }

    // Optional marker position. Both empty = use the grid-square centre.
    $lat = $info['lat'] ?? '';
    $lon = $info['lon'] ?? '';
    $hasLat = !($lat === '' || $lat === null);
    $hasLon = !($lon === '' || $lon === null);
    if ($hasLat || $hasLon) {
        if (!$hasLat || !$hasLon || !is_numeric($lat) || !is_numeric($lon)) {
            apiError('Marker position needs both latitude and longitude, as decimal degrees');
        }
        $lat = (float)$lat; $lon = (float)$lon;
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($lat == 0.0 && $lon == 0.0)) {
            apiError('Marker position is out of range (latitude -90..90, longitude -180..180)');
        }
        $clean['lat'] = round($lat, 5);
        $clean['lon'] = round($lon, 5);
    }

    // APRS-IS beacon (2026-10-16): sent by scripts/bpq-aprs-daemon.py as the
    // config.php 'aprs' callsign. Off unless switched on here.
    $clean['beacon_enabled']  = !empty($info['beacon_enabled']) && $info['beacon_enabled'] !== 'false';
    $clean['beacon_type']     = (($info['beacon_type'] ?? '') === 'position') ? 'position' : 'object';
    $clean['beacon_name']     = rtrim(bbsInfoLine($info['beacon_name'] ?? '', 20));
    $clean['beacon_comment']  = bbsInfoLine($info['beacon_comment'] ?? '', 100);
    $iv = (int)($info['beacon_interval'] ?? 30);
    $clean['beacon_interval'] = ($iv >= 10 && $iv <= 120) ? $iv : 30;

    if ($clean['beacon_name'] !== '' && !preg_match('~^[A-Za-z0-9 ./-]{1,9}$~', $clean['beacon_name'])) {
        apiError('Beacon object name: 1–9 characters, letters, digits, space . - / only');
    }
    if (preg_match('/[^\x20-\x7E]|[|~]/', $clean['beacon_comment'])) {
        apiError('Beacon comment: plain ASCII only (no accents or symbols, no | or ~)');
    }
    if (strlen($clean['beacon_comment']) > 43) {
        apiError('Beacon comment is too long (43 characters max for APRS)');
    }
    if ($clean['beacon_enabled']) {
        if (!isset($clean['lat'], $clean['lon'])) {
            apiError('The APRS beacon needs a marker position: enter it or use Pick on map');
        }
        if ($clean['beacon_type'] === 'object' && $clean['beacon_name'] === '') {
            apiError('The APRS beacon needs an object name (up to 9 characters)');
        }
    }

    $clean['updated'] = time();

    writeJsonFile($BBS_INFO_FILE, $clean);

    return ['success' => true, 'info' => $clean];
}

// ===========================
// REQUEST HANDLER
// ===========================

ensureStorageDir();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Handle GET requests
if ($method === 'GET') {
    switch ($action) {
        case 'locations':
            echo json_encode(getLocations());
            break;
            
        case 'partners':
            echo json_encode(getPartners());
            break;
            
        case 'all':
            echo json_encode([
                'success'   => true,
                'locations' => getLocations(),
                'partners'  => getPartners()
            ]);
            break;
            
        case 'bbsinfo':
            header('Cache-Control: no-cache');
            echo json_encode(['success' => true, 'info' => getBbsInfo()]);
            break;

        case 'stats':
            $locs = (array)getLocations();
            $parts = getPartners();
            echo json_encode([
                'success'    => true,
                'locations'  => count($locs),
                'partners'   => count($parts),
                'stats'      => [
                    'locations_count' => count($locs),
                    'partners_count'  => count($parts)
                ]
            ]);
            break;
            
        default:
            echo json_encode([
                'success'   => true,
                'locations' => getLocations(),
                'partners'  => getPartners()
            ]);
    }
    exit;
}

// Handle POST requests
if ($method === 'POST') {
    // Saving locations/partners: admin login (reads above stay public) (2026-10-04)
    require_once __DIR__ . '/includes/web-login.php';
    bpq_require_web_login('Saving station locations, partners or BBS info', true);
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        apiError('Invalid request: missing action');
    }
    
    $action = $input['action'];
    
    switch ($action) {
        case 'saveLocations':
            echo json_encode(saveLocations($input['locations'] ?? []));
            break;
            
        case 'savePartners':
            echo json_encode(savePartners($input['partners'] ?? []));
            break;
            
        case 'deleteLocation':
            echo json_encode(deleteLocation($input['callsign'] ?? ''));
            break;
            
        case 'clearLocations':
            echo json_encode(clearLocations());
            break;
            
        case 'importLocations':
            echo json_encode(importLocations($input['locations'] ?? []));
            break;

        case 'saveBbsInfo':
            echo json_encode(saveBbsInfo($input['info'] ?? null), JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            apiError('Unknown action: ' . $action);
    }
    exit;
}

apiError('Method not allowed', 405);
