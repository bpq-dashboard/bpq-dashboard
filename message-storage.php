<?php
/**
 * BPQ Dashboard Message Storage API
 * Version: 1.2.1
 * 
 * Stores saved messages and folders as JSON files on the server.
 * This provides persistent storage that works across devices.
 */

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Storage configuration
$STORAGE_DIR = __DIR__ . '/data/messages/';
$FOLDERS_FILE = $STORAGE_DIR . 'folders.json';
$MESSAGES_FILE = $STORAGE_DIR . 'messages.json';
$ADDRESSES_FILE = $STORAGE_DIR . 'addresses.json';
$MAX_STORAGE_SIZE = 10 * 1024 * 1024; // 10MB limit

// ===========================
// HELPER FUNCTIONS
// ===========================

/**
 * Send JSON error response
 */
function apiError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/**
 * Sanitize user input
 */
function sanitize($str) {
    if (!is_string($str)) return '';
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * Ensure storage directory exists
 */
function ensureStorageDir() {
    global $STORAGE_DIR;
    
    if (!file_exists($STORAGE_DIR)) {
        if (!mkdir($STORAGE_DIR, 0755, true)) {
            apiError('Cannot create storage directory', 500);
        }
        
        // Create .htaccess to protect directory
        $htaccess = $STORAGE_DIR . '.htaccess';
        file_put_contents($htaccess, "Deny from all\n");
    }
    
    if (!is_writable($STORAGE_DIR)) {
        apiError('Storage directory not writable', 500);
    }
}

/**
 * Read JSON file safely
 */
function readJsonFile($filepath, $default = []) {
    if (!file_exists($filepath)) {
        return $default;
    }
    
    $content = file_get_contents($filepath);
    if ($content === false) {
        return $default;
    }
    
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

/**
 * Write JSON file safely
 */
function writeJsonFile($filepath, $data) {
    global $MAX_STORAGE_SIZE;
    
    $json = json_encode($data, JSON_PRETTY_PRINT);
    
    // Check size limit
    if (strlen($json) > $MAX_STORAGE_SIZE) {
        apiError('Storage limit exceeded (10MB max)', 400);
    }
    
    // Write atomically
    $temp = $filepath . '.tmp';
    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        apiError('Failed to write storage file', 500);
    }
    
    if (!rename($temp, $filepath)) {
        unlink($temp);
        apiError('Failed to save storage file', 500);
    }
    
    return true;
}

/**
 * Sanitize folder name
 */
function sanitizeFolderName($name) {
    // Allow alphanumeric, spaces, hyphens, underscores
    $name = preg_replace('/[^a-zA-Z0-9 _-]/', '', $name);
    $name = trim($name);
    return substr($name, 0, 50); // Max 50 chars
}

/**
 * Generate unique message ID
 */
function generateMessageId() {
    return uniqid('msg_', true);
}

// ===========================
// API ACTIONS
// ===========================

/**
 * Get all folders
 */
function getFolders() {
    global $FOLDERS_FILE;
    
    $folders = readJsonFile($FOLDERS_FILE, ['Saved']);
    
    return [
        'success' => true,
        'folders' => $folders
    ];
}

/**
 * Create a new folder
 */
function createFolder($name) {
    global $FOLDERS_FILE;
    
    $name = sanitizeFolderName($name);
    if (empty($name)) {
        apiError('Invalid folder name');
    }
    
    $folders = readJsonFile($FOLDERS_FILE, ['Saved']);
    
    if (in_array($name, $folders)) {
        apiError('Folder already exists');
    }
    
    if (count($folders) >= 50) {
        apiError('Maximum folder limit reached (50)');
    }
    
    $folders[] = $name;
    writeJsonFile($FOLDERS_FILE, $folders);
    
    return [
        'success' => true,
        'message' => 'Folder created',
        'folders' => $folders
    ];
}

/**
 * Delete a folder
 */
function deleteFolder($name) {
    global $FOLDERS_FILE, $MESSAGES_FILE;
    
    $name = sanitizeFolderName($name);
    
    if ($name === 'Saved') {
        apiError('Cannot delete default folder');
    }
    
    $folders = readJsonFile($FOLDERS_FILE, ['Saved']);
    $folders = array_values(array_filter($folders, fn($f) => $f !== $name));
    
    // Ensure 'Saved' always exists
    if (!in_array('Saved', $folders)) {
        array_unshift($folders, 'Saved');
    }
    
    writeJsonFile($FOLDERS_FILE, $folders);
    
    // Move messages from deleted folder to 'Saved'
    $messages = readJsonFile($MESSAGES_FILE, []);
    foreach ($messages as &$m) {
        if (($m['folder'] ?? 'Saved') === $name) {
            $m['folder'] = 'Saved';
        }
    }
    writeJsonFile($MESSAGES_FILE, array_values($messages));
    
    return [
        'success' => true,
        'message' => 'Folder deleted',
        'folders' => $folders
    ];
}

/**
 * Get all saved messages
 */
function getMessages($folder = null) {
    global $MESSAGES_FILE;
    
    $messages = readJsonFile($MESSAGES_FILE, []);
    
    if ($folder !== null) {
        $folder = sanitizeFolderName($folder);
        $messages = array_filter($messages, fn($m) => ($m['folder'] ?? 'Saved') === $folder);
        $messages = array_values($messages);
    }
    
    return [
        'success' => true,
        'messages' => $messages,
        'count' => count($messages)
    ];
}

/**
 * Save a message
 */
function saveMessage($message, $folder = 'Saved') {
    global $MESSAGES_FILE, $FOLDERS_FILE;
    
    $folder = sanitizeFolderName($folder);
    if (empty($folder)) {
        $folder = 'Saved';
    }
    
    // Validate message structure
    if (!is_array($message) || empty($message)) {
        apiError('Invalid message data');
    }
    
    // Ensure folder exists
    $folders = readJsonFile($FOLDERS_FILE, ['Saved']);
    if (!in_array($folder, $folders)) {
        $folders[] = $folder;
        writeJsonFile($FOLDERS_FILE, $folders);
    }
    
    // Prepare message for storage
    $storedMessage = [
        'id' => $message['id'] ?? generateMessageId(),
        'number' => $message['number'] ?? null,
        'date' => $message['date'] ?? date('d-M'),
        'type' => $message['type'] ?? 'P',
        'size' => $message['size'] ?? 0,
        'from' => sanitize($message['from'] ?? 'Unknown'),
        'to' => sanitize($message['to'] ?? ''),
        'subject' => sanitize($message['subject'] ?? 'No Subject'),
        'body' => $message['body'] ?? '',
        'folder' => $folder,
        'savedAt' => date('c'),
    ];
    
    $messages = readJsonFile($MESSAGES_FILE, []);
    
    // Check for duplicate (same BBS message number in same folder)
    if ($storedMessage['number']) {
        foreach ($messages as $i => $m) {
            if ($m['number'] === $storedMessage['number'] && $m['folder'] === $folder) {
                // Update existing
                $messages[$i] = $storedMessage;
                writeJsonFile($MESSAGES_FILE, $messages);
                
                return [
                    'success' => true,
                    'message' => 'Message updated',
                    'id' => $storedMessage['id']
                ];
            }
        }
    }
    
    // Add new message
    $messages[] = $storedMessage;
    
    // Limit total messages (keep most recent 1000)
    if (count($messages) > 1000) {
        usort($messages, fn($a, $b) => strtotime($b['savedAt'] ?? 0) - strtotime($a['savedAt'] ?? 0));
        $messages = array_slice($messages, 0, 1000);
    }
    
    writeJsonFile($MESSAGES_FILE, $messages);
    
    return [
        'success' => true,
        'message' => 'Message saved',
        'id' => $storedMessage['id']
    ];
}

/**
 * Save multiple messages
 */
function saveMessages($messageList, $folder = 'Saved') {
    if (!is_array($messageList)) {
        apiError('Invalid messages data');
    }
    
    $saved = 0;
    foreach ($messageList as $msg) {
        $result = saveMessage($msg, $folder);
        if ($result['success']) $saved++;
    }
    
    return [
        'success' => true,
        'message' => "$saved messages saved",
        'count' => $saved
    ];
}

/**
 * Delete a message
 */
function deleteMessage($id) {
    global $MESSAGES_FILE;
    
    if (empty($id)) {
        apiError('Message ID required');
    }
    
    $messages = readJsonFile($MESSAGES_FILE, []);
    $original = count($messages);
    $messages = array_filter($messages, fn($m) => $m['id'] !== $id);
    
    if (count($messages) === $original) {
        apiError('Message not found');
    }
    
    writeJsonFile($MESSAGES_FILE, array_values($messages));
    
    return [
        'success' => true,
        'message' => 'Message deleted'
    ];
}

/**
 * Move a message to another folder
 */
function moveMessage($id, $newFolder) {
    global $MESSAGES_FILE;
    
    if (empty($id)) {
        apiError('Message ID required');
    }
    
    $newFolder = sanitizeFolderName($newFolder);
    if (empty($newFolder)) {
        apiError('Invalid folder name');
    }
    
    $messages = readJsonFile($MESSAGES_FILE, []);
    $found = false;
    
    foreach ($messages as &$m) {
        if ($m['id'] === $id) {
            $m['folder'] = $newFolder;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        apiError('Message not found');
    }
    
    writeJsonFile($MESSAGES_FILE, $messages);
    
    return [
        'success' => true,
        'message' => 'Message moved'
    ];
}

/**
 * Get saved bulletin addresses
 */
function getAddresses() {
    global $ADDRESSES_FILE;
    
    $addresses = readJsonFile($ADDRESSES_FILE, [
        'WX@ALLUS', 'NEWS@WW', 'ARRL@USA', 'DX@WW', 'TECH@WW'
    ]);
    
    return [
        'success' => true,
        'addresses' => $addresses
    ];
}

/**
 * Save a bulletin address
 */
function saveAddress($address) {
    global $ADDRESSES_FILE;
    
    $address = strtoupper(trim($address));
    
    // Basic validation
    if (empty($address) || strlen($address) > 50) {
        apiError('Invalid address');
    }
    
    $addresses = readJsonFile($ADDRESSES_FILE, []);
    
    if (in_array($address, $addresses)) {
        return [
            'success' => true,
            'message' => 'Address already saved',
            'addresses' => $addresses
        ];
    }
    
    if (count($addresses) >= 50) {
        apiError('Maximum address limit reached (50)');
    }
    
    $addresses[] = $address;
    writeJsonFile($ADDRESSES_FILE, $addresses);
    
    return [
        'success' => true,
        'message' => 'Address saved',
        'addresses' => $addresses
    ];
}

/**
 * Delete a bulletin address
 */
function deleteAddress($address) {
    global $ADDRESSES_FILE;
    
    $address = strtoupper(trim($address));
    $addresses = readJsonFile($ADDRESSES_FILE, []);
    $addresses = array_values(array_filter($addresses, fn($a) => $a !== $address));
    writeJsonFile($ADDRESSES_FILE, $addresses);
    
    return [
        'success' => true,
        'message' => 'Address deleted',
        'addresses' => $addresses
    ];
}

/**
 * Get storage statistics
 */
function getStorageStats() {
    global $STORAGE_DIR, $MESSAGES_FILE, $FOLDERS_FILE, $ADDRESSES_FILE, $MAX_STORAGE_SIZE;
    
    $stats = [
        'messages' => 0,
        'folders' => 0,
        'addresses' => 0,
        'totalSize' => 0,
        'maxSize' => $MAX_STORAGE_SIZE,
    ];
    
    if (file_exists($MESSAGES_FILE)) {
        $messages = readJsonFile($MESSAGES_FILE, []);
        $stats['messages'] = count($messages);
        $stats['totalSize'] += filesize($MESSAGES_FILE);
    }
    
    if (file_exists($FOLDERS_FILE)) {
        $folders = readJsonFile($FOLDERS_FILE, []);
        $stats['folders'] = count($folders);
        $stats['totalSize'] += filesize($FOLDERS_FILE);
    }
    
    if (file_exists($ADDRESSES_FILE)) {
        $addresses = readJsonFile($ADDRESSES_FILE, []);
        $stats['addresses'] = count($addresses);
        $stats['totalSize'] += filesize($ADDRESSES_FILE);
    }
    
    $stats['usagePercent'] = round(($stats['totalSize'] / $stats['maxSize']) * 100, 1);
    
    return [
        'success' => true,
        'stats' => $stats
    ];
}

/**
 * Export all data for backup
 */
function exportData() {
    global $MESSAGES_FILE, $FOLDERS_FILE, $ADDRESSES_FILE;
    
    return [
        'success' => true,
        'export' => [
            'version' => '1.2.1',
            'exportedAt' => date('c'),
            'folders' => readJsonFile($FOLDERS_FILE, []),
            'messages' => readJsonFile($MESSAGES_FILE, []),
            'addresses' => readJsonFile($ADDRESSES_FILE, []),
        ]
    ];
}

/**
 * Import data from backup
 */
function importData($data) {
    global $MESSAGES_FILE, $FOLDERS_FILE, $ADDRESSES_FILE;
    
    if (!is_array($data) || !isset($data['export'])) {
        apiError('Invalid import data format');
    }
    
    $export = $data['export'];
    $imported = ['folders' => 0, 'messages' => 0, 'addresses' => 0];
    
    if (isset($export['folders']) && is_array($export['folders'])) {
        writeJsonFile($FOLDERS_FILE, $export['folders']);
        $imported['folders'] = count($export['folders']);
    }
    
    if (isset($export['messages']) && is_array($export['messages'])) {
        writeJsonFile($MESSAGES_FILE, $export['messages']);
        $imported['messages'] = count($export['messages']);
    }
    
    if (isset($export['addresses']) && is_array($export['addresses'])) {
        writeJsonFile($ADDRESSES_FILE, $export['addresses']);
        $imported['addresses'] = count($export['addresses']);
    }
    
    return [
        'success' => true,
        'message' => 'Data imported',
        'imported' => $imported
    ];
}

/**
 * Migrate data from localStorage format
 */
function migrateFromLocalStorage($data) {
    global $MESSAGES_FILE, $FOLDERS_FILE, $ADDRESSES_FILE;
    
    $imported = ['folders' => 0, 'messages' => 0, 'addresses' => 0];
    
    // Import folders
    if (isset($data['folders']) && is_array($data['folders'])) {
        $existingFolders = readJsonFile($FOLDERS_FILE, ['Saved']);
        foreach ($data['folders'] as $folder) {
            $folder = sanitizeFolderName($folder);
            if (!empty($folder) && !in_array($folder, $existingFolders)) {
                $existingFolders[] = $folder;
                $imported['folders']++;
            }
        }
        writeJsonFile($FOLDERS_FILE, $existingFolders);
    }
    
    // Import messages
    if (isset($data['messages']) && is_array($data['messages'])) {
        $existingMessages = readJsonFile($MESSAGES_FILE, []);
        $existingNumbers = array_column($existingMessages, 'number');
        
        foreach ($data['messages'] as $msg) {
            // Skip if we already have this message number
            if ($msg['number'] && in_array($msg['number'], $existingNumbers)) {
                continue;
            }
            
            $storedMessage = [
                'id' => $msg['id'] ?? generateMessageId(),
                'number' => $msg['number'] ?? null,
                'date' => $msg['date'] ?? date('d-M'),
                'type' => $msg['type'] ?? 'P',
                'size' => $msg['size'] ?? 0,
                'from' => sanitize($msg['from'] ?? 'Unknown'),
                'to' => sanitize($msg['to'] ?? ''),
                'subject' => sanitize($msg['subject'] ?? 'No Subject'),
                'body' => $msg['body'] ?? '',
                'folder' => sanitizeFolderName($msg['folder'] ?? 'Saved') ?: 'Saved',
                'savedAt' => $msg['savedAt'] ?? date('c'),
            ];
            
            $existingMessages[] = $storedMessage;
            $imported['messages']++;
        }
        
        writeJsonFile($MESSAGES_FILE, $existingMessages);
    }
    
    // Import addresses
    if (isset($data['addresses']) && is_array($data['addresses'])) {
        $existingAddresses = readJsonFile($ADDRESSES_FILE, []);
        foreach ($data['addresses'] as $addr) {
            $addr = strtoupper(trim($addr));
            if (!empty($addr) && !in_array($addr, $existingAddresses)) {
                $existingAddresses[] = $addr;
                $imported['addresses']++;
            }
        }
        writeJsonFile($ADDRESSES_FILE, $existingAddresses);
    }
    
    return [
        'success' => true,
        'message' => 'Migration complete',
        'imported' => $imported
    ];
}

// ===========================
// REQUEST HANDLER
// ===========================

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// For stats action, check availability without failing
if ($method === 'GET' && $action === 'stats') {
    $available = true;
    $reason = '';
    
    // Check if directory exists or can be created
    if (!file_exists($STORAGE_DIR)) {
        if (!@mkdir($STORAGE_DIR, 0755, true)) {
            $available = false;
            $reason = 'Cannot create storage directory';
        } else {
            // Create .htaccess
            @file_put_contents($STORAGE_DIR . '.htaccess', "Deny from all\n");
        }
    }
    
    // Check if writable
    if ($available && !is_writable($STORAGE_DIR)) {
        $available = false;
        $reason = 'Storage directory not writable';
    }
    
    if ($available) {
        echo json_encode(getStorageStats());
    } else {
        echo json_encode([
            'success' => false,
            'error' => $reason,
            'available' => false
        ]);
    }
    exit;
}

// For all other actions, ensure storage directory exists
ensureStorageDir();

// Handle GET requests
if ($method === 'GET') {
    switch ($action) {
        case 'folders':
            echo json_encode(getFolders());
            break;
            
        case 'messages':
            $folder = $_GET['folder'] ?? null;
            echo json_encode(getMessages($folder));
            break;
            
        case 'addresses':
            echo json_encode(getAddresses());
            break;
            
        case 'export':
            echo json_encode(exportData());
            break;
            
        default:
            // Return all data
            echo json_encode([
                'success' => true,
                'folders' => readJsonFile($FOLDERS_FILE, ['Saved']),
                'messages' => readJsonFile($MESSAGES_FILE, []),
                'addresses' => readJsonFile($ADDRESSES_FILE, []),
            ]);
    }
    exit;
}

// Handle POST requests
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        apiError('Invalid request');
    }
    
    $action = $input['action'];
    
    switch ($action) {
        case 'createFolder':
            echo json_encode(createFolder($input['name'] ?? ''));
            break;
            
        case 'deleteFolder':
            echo json_encode(deleteFolder($input['name'] ?? ''));
            break;
            
        case 'saveMessage':
            echo json_encode(saveMessage($input['message'] ?? [], $input['folder'] ?? 'Saved'));
            break;
            
        case 'saveMessages':
            echo json_encode(saveMessages($input['messages'] ?? [], $input['folder'] ?? 'Saved'));
            break;
            
        case 'deleteMessage':
            echo json_encode(deleteMessage($input['id'] ?? ''));
            break;
            
        case 'moveMessage':
            echo json_encode(moveMessage($input['id'] ?? '', $input['folder'] ?? ''));
            break;
            
        case 'saveAddress':
            echo json_encode(saveAddress($input['address'] ?? ''));
            break;
            
        case 'deleteAddress':
            echo json_encode(deleteAddress($input['address'] ?? ''));
            break;
            
        case 'import':
            echo json_encode(importData($input));
            break;
            
        case 'migrate':
            echo json_encode(migrateFromLocalStorage($input));
            break;
            
        default:
            apiError('Unknown action');
    }
    exit;
}

apiError('Method not allowed', 405);
