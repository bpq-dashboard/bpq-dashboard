<?php
/**
 * NWS Alert BBS Poster
 * Version: 1.3.0
 * 
 * This script receives NWS alert data via POST and sends it to the BPQ BBS
 * via telnet connection.
 */

// Load configuration and security
require_once __DIR__ . '/includes/bootstrap.php';

// Check if NWS posting is enabled
if (!isFeatureEnabled('nws_post')) {
    apiError('Weather posting is disabled', 403);
}

// Get BBS config from main config
$config = [
    'bbs_host'  => getConfig('bbs', 'host', 'localhost'),
    'bbs_port'  => getConfig('bbs', 'port', 8010),
    'bbs_user'  => getConfig('bbs', 'user', 'SYSOP'),
    'bbs_pass'  => getConfig('bbs', 'pass'),
    'from_call' => getConfig('station', 'callsign', 'N0CALL'),
    'to_addr'   => getConfig('nws', 'post_destination', 'WX@ALLUS'),
    'timeout'   => getConfig('bbs', 'timeout', 30),
    'enabled'   => isFeatureEnabled('nws_post'),
    'log_file'  => getConfig('logging', 'file', './logs/dashboard.log'),
];

// ================================
// HELPER FUNCTIONS
// ================================

function logMessage($msg) {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [NWS] $msg\n";
    @file_put_contents($config['log_file'], $logLine, FILE_APPEND);
}

function sendResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('c')
    ]);
    exit;
}

function postToBBS($subject, $message) {
    global $config;
    
    if (!$config['enabled']) {
        return ['success' => false, 'message' => 'BBS posting is disabled in configuration'];
    }
    
    $socket = @fsockopen($config['bbs_host'], $config['bbs_port'], $errno, $errstr, $config['timeout']);
    
    if (!$socket) {
        logMessage("ERROR: Cannot connect - $errstr ($errno)");
        return ['success' => false, 'message' => "Cannot connect to BBS: $errstr"];
    }
    
    stream_set_timeout($socket, $config['timeout']);
    
    try {
        // Step 1: Wait for user: prompt
        $response = readUntilPrompt($socket, 5);
        logMessage("Initial: " . cleanLog($response));
        
        // Step 2: Send username
        fwrite($socket, $config['bbs_user'] . "\r\n");
        logMessage("Sent username: " . $config['bbs_user']);
        
        // Step 3: Wait for password: prompt
        $response = readUntilPrompt($socket, 5);
        logMessage("After user: " . cleanLog($response));
        
        // Step 4: Send password
        fwrite($socket, $config['bbs_pass'] . "\r\n");
        logMessage("Sent password");
        
        // Step 5: Wait for node prompt (shows node list, ends with "}")
        $response = readUntilPrompt($socket, 5);
        logMessage("Node response: " . cleanLog($response));
        
        // Step 6: Send BBS command
        $bbsAlias = isset($config['bbs_alias']) ? $config['bbs_alias'] : 'bbs';
        fwrite($socket, $bbsAlias . "\r\n");
        logMessage("Sent: $bbsAlias");
        
        // Step 7: Wait for BBS prompt (ends with ">")
        $response = readUntilPrompt($socket, 5);
        logMessage("BBS connect: " . cleanLog($response));
        
        // Check for connection success
        if (stripos($response, 'Connected to BBS') === false && 
            stripos($response, 'de ') === false &&
            stripos($response, '>') === false) {
            throw new Exception("Failed to connect to BBS: " . cleanLog($response));
        }
        
        // Step 8: Send SP command
        fwrite($socket, "SP " . $config['to_addr'] . "\r\n");
        logMessage("Sent: SP " . $config['to_addr']);
        
        // Step 9: Wait for "Enter Title" prompt
        $response = readUntilPrompt($socket, 5);
        logMessage("After SP: " . cleanLog($response));
        
        if (stripos($response, 'Title') === false && 
            stripos($response, 'ubject') === false) {
            if (stripos($response, 'Invalid') !== false) {
                throw new Exception("Invalid address: " . cleanLog($response));
            }
        }
        
        // Step 10: Send subject/title
        fwrite($socket, $subject . "\r\n");
        logMessage("Sent title: $subject");
        
        // Step 11: Wait for "Enter Message Text" prompt
        $response = readUntilPrompt($socket, 5);
        logMessage("After title: " . cleanLog($response));
        
        // Step 12: Send message body line by line
        $lines = explode("\n", $message);
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            // Don't send /ex in the message body
            if (preg_match('/^\/ex$/i', trim($line))) {
                $line = ' ' . $line;
            }
            fwrite($socket, $line . "\r\n");
            usleep(30000); // 30ms delay
        }
        
        // Step 13: Send /ex to end message
        fwrite($socket, "/ex\r\n");
        logMessage("Sent /ex");
        
        // Step 14: Wait for confirmation (Message: #### Bid: ...)
        $response = readUntilPrompt($socket, 5);
        logMessage("After /ex: " . cleanLog($response));
        
        // Step 15: Send bye to disconnect cleanly
        fwrite($socket, "bye\r\n");
        logMessage("Sent bye");
        
        sleep(1);
        fclose($socket);
        
        // Check for success - look for "Message:" in response
        if (stripos($response, 'Message:') !== false || 
            stripos($response, 'Bid:') !== false ||
            stripos($response, 'Size:') !== false) {
            // Extract message number if possible
            if (preg_match('/Message:\s*(\d+)/i', $response, $matches)) {
                logMessage("SUCCESS: Posted as Message #" . $matches[1]);
                return ['success' => true, 'message' => 'Posted as Message #' . $matches[1]];
            }
            logMessage("SUCCESS: Message posted");
            return ['success' => true, 'message' => 'Message posted to BBS'];
        } else {
            logMessage("Sent but unconfirmed: " . cleanLog($response));
            return ['success' => true, 'message' => 'Message sent (unconfirmed)'];
        }
        
    } catch (Exception $e) {
        if (is_resource($socket)) {
            fwrite($socket, "bye\r\n");
            fclose($socket);
        }
        logMessage("ERROR: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Read until we see a prompt character or timeout
function readUntilPrompt($socket, $timeout = 5) {
    $buffer = '';
    $start = time();
    
    stream_set_blocking($socket, false);
    
    while ((time() - $start) < $timeout) {
        $data = @fread($socket, 4096);
        if ($data !== false && $data !== '') {
            $buffer .= $data;
            // Check for common prompts: "user:" "password:" "}" ">" ":"
            if (preg_match('/(>|:|\})\s*$/', trim($buffer))) {
                usleep(200000); // 200ms extra to catch any trailing data
                $extra = @fread($socket, 4096);
                if ($extra) $buffer .= $extra;
                break;
            }
        }
        usleep(100000); // 100ms
    }
    
    stream_set_blocking($socket, true);
    return $buffer;
}

// Clean log output - remove control chars and truncate
function cleanLog($str) {
    $clean = preg_replace('/[\x00-\x1F\x7F]/', ' ', $str);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return substr(trim($clean), 0, 300);
}

// ================================
// MAIN REQUEST HANDLER
// ================================

// CORS preflight is handled by bootstrap.php

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Only POST method allowed', 405);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    apiError('Invalid JSON input');
}

// Check required fields
if (empty($input['subject']) || empty($input['message'])) {
    apiError('Missing required fields: subject, message');
}

// Check if this is just a status check
if (isset($input['action']) && $input['action'] === 'status') {
    sendResponse(true, 'BBS Poster Status', [
        'enabled' => $config['enabled'],
        'mode' => isPublicMode() ? 'public' : 'local',
        'to_addr' => $config['to_addr']
    ]);
}

// Post the message
$result = postToBBS($input['subject'], $input['message']);

sendResponse($result['success'], $result['message']);
