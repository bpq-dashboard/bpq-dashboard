<?php
/**
 * BPQ Dashboard Station Storage API
 * Version: 1.5.5
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
$MAX_STORAGE_SIZE = 2 * 1024 * 1024; // 2MB limit (locations are small)
$MAX_LOCATIONS = 500;  // Sanity limit on station count

// Default forwarding partners (used if no server file exists)
$DEFAULT_PARTNERS = ['WP4OH-11', 'PARTNER1-2', 'NP4JN-11', 'PARTNER5', 'PARTNER2-1', 'PARTNER6-1', 'PARTNER7-1'];

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
            
        default:
            apiError('Unknown action: ' . $action);
    }
    exit;
}

apiError('Method not allowed', 405);
