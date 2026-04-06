<?php
/**
 * BPQ Dashboard Message Storage API
 * Version: 1.3.0
 * 
 * Stores saved messages and folders as JSON files on the server.
 * This replaces browser localStorage for persistent, cross-device storage.
 */

// Set required server vars if missing (CLI/direct access)
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['PHP_SELF'])) $_SERVER['PHP_SELF'] = '/message-storage.php';

// Load bootstrap if available — skip BBS password check
if (file_exists(__DIR__ . '/includes/bootstrap.php')) {
    $SKIP_BBS_CHECK = true;
    require_once __DIR__ . '/includes/bootstrap.php';
} else {
    // Minimal fallback if bootstrap not present
    function apiError($msg, $code = 400) {
        http_response_code($code);
        die(json_encode(['success' => false, 'error' => $msg]));
    }
}

// Always return JSON
header('Content-Type: application/json');

// Storage configuration
$STORAGE_DIR = __DIR__ . '/data/messages/';
$FOLDERS_FILE = $STORAGE_DIR . 'folders.json';
$MESSAGES_FILE = $STORAGE_DIR . 'messages.json';
$ADDRESSES_FILE = $STORAGE_DIR . 'addresses.json';
$MAX_STORAGE_SIZE = 10 * 1024 * 1024; // 10MB limit
$RULES_FILE   = $STORAGE_DIR . 'rules.json';
$UNREAD_FILE  = $STORAGE_DIR . 'unread.json';

// ===========================
// HELPER FUNCTIONS
// ===========================

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
    
    $folders = readJsonFile($FOLDERS_FILE, ['Inbox', 'Saved', 'Bulletins']);
    
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
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
    $name = sanitizeFolderName($name);
    if (empty($name)) {
        apiError('Invalid folder name');
    }
    
    $folders = readJsonFile($FOLDERS_FILE, ['Inbox', 'Saved', 'Bulletins']);
    
    if (in_array($name, $folders)) {
        apiError('Folder already exists');
    }
    
    if (count($folders) >= 50) {
        apiError('Maximum folder limit reached (50)');
    }
    
    $folders[] = $name;
    writeJsonFile($FOLDERS_FILE, $folders);
    
    dashboardLog('info', 'Folder created', ['name' => $name]);
    
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
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
    $name = sanitizeFolderName($name);
    $protected = ['Inbox', 'Saved', 'Bulletins'];
    
    if (in_array($name, $protected)) {
        apiError('Cannot delete default folders');
    }
    
    $folders = readJsonFile($FOLDERS_FILE, $protected);
    $folders = array_values(array_filter($folders, fn($f) => $f !== $name));
    writeJsonFile($FOLDERS_FILE, $folders);
    
    // Also delete messages in this folder
    $messages = readJsonFile($MESSAGES_FILE, []);
    $messages = array_filter($messages, fn($m) => ($m['folder'] ?? 'Inbox') !== $name);
    writeJsonFile($MESSAGES_FILE, array_values($messages));
    
    dashboardLog('info', 'Folder deleted', ['name' => $name]);
    
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
        $messages = array_filter($messages, fn($m) => ($m['folder'] ?? 'Inbox') === $folder);
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
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
    $folder = sanitizeFolderName($folder);
    if (empty($folder)) {
        $folder = 'Saved';
    }
    
    // Validate message structure
    if (!is_array($message) || empty($message)) {
        apiError('Invalid message data');
    }
    
    // Ensure folder exists
    $folders = readJsonFile($FOLDERS_FILE, ['Inbox', 'Saved', 'Bulletins']);
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
    
    // Check for duplicate (same BBS message number)
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
    
    dashboardLog('info', 'Message saved', ['id' => $storedMessage['id'], 'folder' => $folder]);
    
    return [
        'success' => true,
        'message' => 'Message saved',
        'id' => $storedMessage['id']
    ];
}

/**
 * Save multiple messages
 */
function saveMessages($messages, $folder = 'Saved') {
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
    if (!is_array($messages)) {
        apiError('Invalid messages data');
    }
    
    $saved = 0;
    $errors = [];
    
    foreach ($messages as $msg) {
        try {
            saveMessage($msg, $folder);
            $saved++;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
    
    return [
        'success' => true,
        'saved' => $saved,
        'errors' => $errors
    ];
}

/**
 * Delete a saved message
 */
function deleteMessage($id) {
    global $MESSAGES_FILE;
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
    $messages = readJsonFile($MESSAGES_FILE, []);
    $found = false;
    
    $messages = array_filter($messages, function($m) use ($id, &$found) {
        if ($m['id'] === $id) {
            $found = true;
            return false;
        }
        return true;
    });
    
    if (!$found) {
        apiError('Message not found');
    }
    
    writeJsonFile($MESSAGES_FILE, array_values($messages));
    
    dashboardLog('info', 'Saved message deleted', ['id' => $id]);
    
    return [
        'success' => true,
        'message' => 'Message deleted'
    ];
}

/**
 * Move message to different folder
 */
function moveMessage($id, $newFolder) {
    global $MESSAGES_FILE;
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
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
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
    if (!validateAddress($address)) {
        apiError('Invalid address format');
    }
    
    $address = strtoupper(trim($address));
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
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
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
    global $STORAGE_DIR, $MESSAGES_FILE, $FOLDERS_FILE, $ADDRESSES_FILE;
    
    $stats = [
        'messages' => 0,
        'folders' => 0,
        'addresses' => 0,
        'totalSize' => 0,
        'maxSize' => 10 * 1024 * 1024,
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
 * Export all data
 */
function exportData() {
    global $MESSAGES_FILE, $FOLDERS_FILE, $ADDRESSES_FILE;
    
    return [
        'success' => true,
        'export' => [
            'version' => '1.3.0',
            'exportedAt' => date('c'),
            'folders' => readJsonFile($FOLDERS_FILE, []),
            'messages' => readJsonFile($MESSAGES_FILE, []),
            'addresses' => readJsonFile($ADDRESSES_FILE, []),
        ]
    ];
}

/**
 * Import data
 */
function importData($data) {
    global $MESSAGES_FILE, $FOLDERS_FILE, $ADDRESSES_FILE;
    
    if (!isFeatureEnabled('bbs_write')) {
        apiError('Write operations disabled', 403);
    }
    
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
    
    dashboardLog('info', 'Data imported', $imported);
    
    return [
        'success' => true,
        'message' => 'Data imported',
        'imported' => $imported
    ];
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
        case 'rules':
            global $RULES_FILE;
            echo json_encode([
                'success' => true,
                'rules'   => readJsonFile($RULES_FILE, [])
            ]);
            exit;

        case 'unread':
            global $UNREAD_FILE;
            echo json_encode([
                'success' => true,
                'unread'  => readJsonFile($UNREAD_FILE, (object)[])
            ]);
            exit;

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
            
        case 'stats':
            echo json_encode(getStorageStats());
            break;
            
        case 'export':
            echo json_encode(exportData());
            break;
            
        default:
            // Return all data
            echo json_encode([
                'success' => true,
                'folders' => readJsonFile($FOLDERS_FILE, ['Inbox', 'Saved', 'Bulletins']),
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
            
        case 'saveRules':
                global $RULES_FILE;
                ensureStorageDir();
                $rulesData = $input['rules'] ?? [];
                if (!is_array($rulesData)) apiError('Invalid rules data');
                file_put_contents($RULES_FILE, json_encode($rulesData, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'count' => count($rulesData)]);
                exit;

            case 'saveUnread':
                global $UNREAD_FILE;
                ensureStorageDir();
                $unreadData = $input['unread'] ?? [];
                file_put_contents($UNREAD_FILE, json_encode($unreadData, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                exit;

            default:
                apiError('Unknown action');
    }
    exit;
}

apiError('Method not allowed', 405);
