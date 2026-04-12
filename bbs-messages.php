<?php
/**
 * BPQ BBS Message API
 * Version: 1.5.5
 * 
 * Connects to BPQ BBS via telnet and provides message management
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load configuration and security via bootstrap
require_once __DIR__ . '/includes/bootstrap.php';

// Get BBS config from loaded $CONFIG (bootstrap handles both config formats)
$config = [
    'bbs_host'      => getConfig('bbs', 'host', 'localhost'),
    'bbs_port'      => getConfig('bbs', 'port', 8010),
    'bbs_user'      => getConfig('bbs', 'user', 'SYSOP'),
    'bbs_pass'      => getConfig('bbs', 'pass'),
    'bbs_alias'     => getConfig('bbs', 'alias', 'bbs'),
    'timeout'       => getConfig('bbs', 'timeout', 30),
    'default_count' => getConfig('ui', 'default_msg_count', 20),
    'max_count'     => getConfig('ui', 'max_msg_count', 100),
    'log_file'      => getConfig('logging', 'file', './logs/bbs-messages.log'),
];

// ===========================
// PASSWORD AUTHENTICATION
// ===========================

define('AUTH_FILE', __DIR__ . '/data/.bbs_auth');
define('AUTH_SALT', 'bpq_bbs_server_salt_2025_bpqdash');

function getStoredPasswordHash() {
    if (!file_exists(AUTH_FILE)) {
        return null;
    }
    $hash = @file_get_contents(AUTH_FILE);
    return $hash ? trim($hash) : null;
}

function setPasswordHash($hash) {
    $dir = dirname(AUTH_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return @file_put_contents(AUTH_FILE, $hash) !== false;
}

function hashPasswordServer($password) {
    return hash('sha256', $password . AUTH_SALT);
}

function isPasswordConfigured() {
    return getStoredPasswordHash() !== null;
}

function verifyPasswordHash($clientHash) {
    $storedHash = getStoredPasswordHash();
    if ($storedHash === null) {
        return false;
    }
    return hash_equals($storedHash, $clientHash);
}

// Handle authentication requests (before other actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $authAction = $input['authAction'] ?? null;
    
    if ($authAction === 'checkAuth') {
        // Check if password is configured
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'configured' => isPasswordConfigured()
        ]);
        exit;
    }
    
    if ($authAction === 'setup') {
        // First-time password setup - only allowed if no password exists
        if (isPasswordConfigured()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Password already configured']);
            exit;
        }
        
        $hash = $input['hash'] ?? '';
        if (strlen($hash) !== 64) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid hash']);
            exit;
        }
        
        if (setPasswordHash($hash)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to save password']);
        }
        exit;
    }
    
    if ($authAction === 'verify') {
        // Verify password
        $hash = $input['hash'] ?? '';
        if (verifyPasswordHash($hash)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'verified' => true]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'verified' => false]);
        }
        exit;
    }
}

// Config is already loaded above - add defaults for optional fields
$config['bbs_alias'] = $config['bbs_alias'] ?? 'bbs';
$config['default_count'] = $config['default_count'] ?? 20;
$config['max_count'] = $config['max_count'] ?? 100;
$config['log_file'] = $config['log_file'] ?? __DIR__ . '/logs/bbs-messages.log';

// ===========================
// BBS-SPECIFIC HELPER FUNCTIONS
// ===========================

function validateMsgNum($num) {
    return is_numeric($num) && intval($num) > 0;
}

function validateAddress($addr) {
    // Allow callsigns with optional SSID, @routing, .hierarchical
    return !empty($addr) && preg_match('/^[A-Z0-9][A-Z0-9._@#-]{1,60}$/i', $addr);
}

function logMessage($msg) {
    global $config;
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($config['log_file'], "[$ts] [BBS] $msg\n", FILE_APPEND);
}

function cleanLog($str) {
    return substr(trim(preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x1F\x7F]/', ' ', $str))), 0, 300);
}

function waitFor($socket, $expect, $timeout = 15) {
    $buffer = '';
    $start = time();
    
    stream_set_blocking($socket, false);
    
    while ((time() - $start) < $timeout) {
        $chunk = @fread($socket, 8192);
        
        if ($chunk !== false && strlen($chunk) > 0) {
            $buffer .= $chunk;
            logMessage("  << " . cleanLog($chunk));
            
            if (strpos($buffer, $expect) !== false) {
                usleep(500000);
                $extra = @fread($socket, 8192);
                if ($extra !== false && strlen($extra) > 0) {
                    $buffer .= $extra;
                    logMessage("  << " . cleanLog($extra));
                }
                stream_set_blocking($socket, true);
                return $buffer;
            }
        }
        usleep(100000);
    }
    
    stream_set_blocking($socket, true);
    logMessage("  !! TIMEOUT waiting for '$expect'");
    return $buffer;
}

function sendCmd($socket, $cmd) {
    fwrite($socket, $cmd . "\r\n");
    fflush($socket);
    logMessage("  >> $cmd");
    usleep(100000);
}

// ===========================
// FETCH MESSAGE LIST
// ===========================

function fetchMessages($count) {
    global $config;
    
    logMessage("=== Fetch Message List ===");
    
    $socket = @stream_socket_client(
        "tcp://{$config['bbs_host']}:{$config['bbs_port']}", 
        $errno, $errstr, $config['timeout']
    );
    
    if (!$socket) {
        logMessage("ERROR: Cannot connect - $errstr");
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    
    logMessage("Connected");
    stream_set_timeout($socket, $config['timeout']);
    
    try {
        // Login sequence
        $response = waitFor($socket, 'user:', 10);
        if (strpos($response, 'user:') === false) throw new Exception('No user prompt');
        
        sendCmd($socket, $config['bbs_user']);
        $response = waitFor($socket, 'password:', 10);
        if (strpos($response, 'password:') === false) throw new Exception('No password prompt');
        
        sendCmd($socket, $config['bbs_pass']);
        $response = waitFor($socket, '}', 20);
        if (strpos($response, '}') === false) throw new Exception('Login failed');
        
        sendCmd($socket, $config['bbs_alias']);
        $response = waitFor($socket, '>', 15);
        if (strpos($response, '>') === false) throw new Exception('No BBS prompt');
        
        // Get message list
        sendCmd($socket, 'LM');
        $response = waitFor($socket, '>', 20);
        
        // Disconnect
        sendCmd($socket, 'B');
        usleep(500000);
        fclose($socket);
        logMessage("Disconnected");
        
        // Parse messages
        $messages = parseMessageList($response);
        logMessage("Parsed " . count($messages) . " messages");
        
        return [
            'success' => true,
            'count' => count($messages),
            'messages' => $messages,
            'timestamp' => date('c')
        ];
        
    } catch (Exception $e) {
        logMessage("ERROR: " . $e->getMessage());
        if (is_resource($socket)) {
            @fwrite($socket, "B\r\n");
            @fclose($socket);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===========================
// FETCH SINGLE MESSAGE
// ===========================

function fetchMessageBody($msgNum) {
    global $config;
    
    logMessage("=== Fetch Message #$msgNum ===");
    
    $socket = @stream_socket_client(
        "tcp://{$config['bbs_host']}:{$config['bbs_port']}", 
        $errno, $errstr, $config['timeout']
    );
    
    if (!$socket) {
        logMessage("ERROR: Cannot connect - $errstr");
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    
    logMessage("Connected");
    stream_set_timeout($socket, $config['timeout']);
    
    try {
        // Login sequence
        $response = waitFor($socket, 'user:', 10);
        if (strpos($response, 'user:') === false) throw new Exception('No user prompt');
        
        sendCmd($socket, $config['bbs_user']);
        $response = waitFor($socket, 'password:', 10);
        if (strpos($response, 'password:') === false) throw new Exception('No password prompt');
        
        sendCmd($socket, $config['bbs_pass']);
        $response = waitFor($socket, '}', 20);
        if (strpos($response, '}') === false) throw new Exception('Login failed');
        
        sendCmd($socket, $config['bbs_alias']);
        $response = waitFor($socket, '>', 15);
        if (strpos($response, '>') === false) throw new Exception('No BBS prompt');
        
        // Read the message
        sendCmd($socket, "R $msgNum");
        $response = waitFor($socket, '>', 30);
        
        // Disconnect
        sendCmd($socket, 'B');
        usleep(500000);
        fclose($socket);
        logMessage("Disconnected");
        
        return [
            'success' => true,
            'number' => $msgNum,
            'body' => $response ?: 'No content',
            'timestamp' => date('c')
        ];
        
    } catch (Exception $e) {
        logMessage("ERROR: " . $e->getMessage());
        if (is_resource($socket)) {
            @fwrite($socket, "B\r\n");
            @fclose($socket);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===========================
// FETCH BULLETINS
// ===========================

function fetchBulletins($address, $count = 10) {
    global $config;
    
    logMessage("=== Fetch Bulletins: $address (count: $count) ===");
    
    $socket = @stream_socket_client(
        "tcp://{$config['bbs_host']}:{$config['bbs_port']}", 
        $errno, $errstr, $config['timeout']
    );
    
    if (!$socket) {
        logMessage("ERROR: Cannot connect - $errstr");
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    
    logMessage("Connected");
    stream_set_timeout($socket, $config['timeout']);
    
    try {
        // Login sequence
        $response = waitFor($socket, 'user:', 10);
        if (strpos($response, 'user:') === false) throw new Exception('No user prompt');
        
        sendCmd($socket, $config['bbs_user']);
        $response = waitFor($socket, 'password:', 10);
        if (strpos($response, 'password:') === false) throw new Exception('No password prompt');
        
        sendCmd($socket, $config['bbs_pass']);
        $response = waitFor($socket, '}', 20);
        if (strpos($response, '}') === false) throw new Exception('Login failed');
        
        sendCmd($socket, $config['bbs_alias']);
        $response = waitFor($socket, '>', 15);
        if (strpos($response, '>') === false) throw new Exception('No BBS prompt');
        
        // Use LB command to list all bulletins
        logMessage("Sending: LB");
        sendCmd($socket, "LB");
        
        // Read response - may need longer timeout for large lists
        $response = waitFor($socket, '>', 30);
        logMessage("Response length: " . strlen($response));
        logMessage("Response: " . cleanLog($response));
        
        // Parse all bulletins
        $allBulletins = parseBulletinList($response);
        logMessage("Parsed " . count($allBulletins) . " total bulletins");
        
        // Filter by address if specified
        $address = strtoupper(trim($address));
        logMessage("Filtering for address: $address");
        if (!empty($address)) {
            $filtered = [];
            
            // Parse the search address
            $searchParts = explode('@', $address);
            $searchTo = $searchParts[0];
            $searchArea = isset($searchParts[1]) ? $searchParts[1] : '';
            
            foreach ($allBulletins as $b) {
                $to = strtoupper($b['to']);
                
                // Parse bulletin TO field (already in format TO@AREA)
                $toParts = explode('@', $to);
                $bulletinTo = $toParts[0];
                $bulletinArea = isset($toParts[1]) ? $toParts[1] : '';
                
                // Match logic:
                // - If searchArea is specified, match both TO and AREA
                // - If only searchTo, match any bulletin with that TO
                $toMatch = ($bulletinTo === $searchTo);
                $areaMatch = empty($searchArea) || ($bulletinArea === $searchArea);
                
                if ($toMatch && $areaMatch) {
                    logMessage("  Match: $to");
                    $filtered[] = $b;
                }
            }
            $bulletins = array_slice($filtered, 0, $count);
            logMessage("Filtered to " . count($bulletins) . " bulletins matching '$address'");
        } else {
            $bulletins = array_slice($allBulletins, 0, $count);
        }
        
        // Disconnect
        sendCmd($socket, 'B');
        usleep(500000);
        fclose($socket);
        logMessage("Disconnected");
        
        return ['success' => true, 'messages' => $bulletins, 'total' => count($allBulletins)];
        
    } catch (Exception $e) {
        logMessage("ERROR: " . $e->getMessage());
        if (is_resource($socket)) {
            @fwrite($socket, "B\r\n");
            @fclose($socket);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function parseBulletinList($response) {
    $bulletins = [];
    
    // Normalize line endings and split
    $response = str_replace(["\r\n", "\r"], "\n", $response);
    
    // Also try splitting on message number patterns if lines are merged
    // Pattern: space followed by 4-5 digit number followed by space and date
    $response = preg_replace('/\s+(\d{3,5}\s+\d{1,2}-[A-Za-z]{3})/', "\n$1", $response);
    
    $lines = explode("\n", $response);
    
    logMessage("Parsing " . count($lines) . " lines after normalization");
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Skip prompts and headers
        if (preg_match('/^[A-Z0-9-]+:\S*>/', $line)) continue; // BBS prompt
        if (preg_match('/de [A-Z0-9]+>/', $line)) continue; // BBS prompt like "de YOURCALL>"
        if (preg_match('/^\s*Msg/', $line)) continue; // Header line
        if (strpos($line, '---') !== false) continue;
        if (strpos($line, 'No ') === 0) continue;
        if (strpos($line, 'Listed') !== false) continue;
        if (strpos($line, 'Connected to') !== false) continue;
        if (strpos($line, 'BPQ-') !== false) continue;
        
        // BPQ Format: MsgNo Date Status Size TO @AREA FROM Subject
        // Example:    9216 17-Jan B$ 8571 NEWS @WW LU9DCE STORM PREDICTION CENTER 17-JAN
        // Or:         9009 16-Jan BN 1531 WX @YOURCALL N4SD Special Weather Statement
        // Or:         761 28-Jan BN 1854 WX @YOURCALL N4SD Hazardous Weather Outlook
        
        // Match: number, date (DD-Mon), status (B$, BN, BF, BK, etc), size, to, @area, from, subject
        // Status can be: B followed by optional letter/symbol ($, N, F, K, etc)
        if (preg_match('/^(\d{1,5})\s+(\d{1,2}-[A-Za-z]{3})\s+(B[A-Z$!*\-]{0,3}|P[A-Z$!*\-]{0,3})\s+(\d+)\s+(\S+)\s+@(\S+)\s+(\S+)\s*(.*)$/i', $line, $matches)) {
            $to = strtoupper($matches[5]) . '@' . strtoupper($matches[6]);
            $bulletins[] = [
                'number' => intval($matches[1]),
                'date' => $matches[2],
                'status' => $matches[3],
                'size' => intval($matches[4]),
                'to' => $to,
                'from' => strtoupper($matches[7]),
                'subject' => trim($matches[8])
            ];
        }
    }
    
    logMessage("Parsed " . count($bulletins) . " total bulletins");
    return $bulletins;
}

// ===========================
// DELETE MESSAGE
// ===========================

function deleteMessage($msgNum) {
    global $config;
    
    logMessage("=== Delete Message #$msgNum ===");
    
    $socket = @stream_socket_client(
        "tcp://{$config['bbs_host']}:{$config['bbs_port']}", 
        $errno, $errstr, $config['timeout']
    );
    
    if (!$socket) {
        logMessage("ERROR: Cannot connect - $errstr");
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    
    logMessage("Connected");
    stream_set_timeout($socket, $config['timeout']);
    
    try {
        // Login sequence
        $response = waitFor($socket, 'user:', 10);
        if (strpos($response, 'user:') === false) throw new Exception('No user prompt');
        
        sendCmd($socket, $config['bbs_user']);
        $response = waitFor($socket, 'password:', 10);
        if (strpos($response, 'password:') === false) throw new Exception('No password prompt');
        
        sendCmd($socket, $config['bbs_pass']);
        $response = waitFor($socket, '}', 20);
        if (strpos($response, '}') === false) throw new Exception('Login failed');
        
        sendCmd($socket, $config['bbs_alias']);
        $response = waitFor($socket, '>', 15);
        if (strpos($response, '>') === false) throw new Exception('No BBS prompt');
        
        // Send kill command
        logMessage("Sending: K $msgNum");
        sendCmd($socket, "K $msgNum");
        
        // Wait for response
        $response = waitFor($socket, '>', 10);
        logMessage("Response: " . cleanLog($response));
        
        // Check for success/failure
        $success = (strpos($response, 'Killed') !== false || 
                   strpos($response, 'deleted') !== false ||
                   strpos($response, 'Message') !== false);
        $error = (strpos($response, 'Not found') !== false ||
                 strpos($response, 'not yours') !== false ||
                 strpos($response, 'Invalid') !== false);
        
        // Disconnect
        sendCmd($socket, 'B');
        usleep(500000);
        fclose($socket);
        logMessage("Disconnected");
        
        if ($error) {
            return ['success' => false, 'error' => 'Could not delete message - not found or not authorized'];
        }
        
        return ['success' => true, 'message' => 'Message deleted'];
        
    } catch (Exception $e) {
        logMessage("ERROR: " . $e->getMessage());
        if (is_resource($socket)) {
            @fwrite($socket, "B\r\n");
            @fclose($socket);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ===========================
// SEND MESSAGE
// ===========================

function sendMessage($to, $type, $subject, $body) {
    global $config;
    
    logMessage("=== Send Message to $to ===");
    
    $socket = @fsockopen(
        $config['bbs_host'], $config['bbs_port'], 
        $errno, $errstr, $config['timeout']
    );
    
    if (!$socket) {
        logMessage("ERROR: Cannot connect - $errstr");
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    
    logMessage("Connected");
    stream_set_timeout($socket, $config['timeout']);
    
    try {
        // Step 1: Wait for user prompt
        $response = readUntilBBSPrompt($socket, 10);
        logMessage("Initial: " . cleanLog($response));
        
        // Step 2: Send username
        fwrite($socket, $config['bbs_user'] . "\r\n");
        logMessage("  >> " . $config['bbs_user']);
        
        // Step 3: Wait for password prompt
        $response = readUntilBBSPrompt($socket, 10);
        logMessage("After user: " . cleanLog($response));
        
        // Step 4: Send password
        fwrite($socket, $config['bbs_pass'] . "\r\n");
        logMessage("  >> (password)");
        
        // Step 5: Wait for node prompt
        $response = readUntilBBSPrompt($socket, 20);
        logMessage("Node response: " . cleanLog($response));
        
        // Step 6: Send BBS alias
        fwrite($socket, $config['bbs_alias'] . "\r\n");
        logMessage("  >> " . $config['bbs_alias']);
        
        // Step 7: Wait for BBS prompt (>)
        $response = readUntilBBSPrompt($socket, 15);
        logMessage("BBS connect: " . cleanLog($response));
        
        // Step 8: Send SP/SB command
        $sendCmd = "S{$type} " . strtoupper($to);
        fwrite($socket, $sendCmd . "\r\n");
        logMessage("  >> $sendCmd");
        
        // Step 9: Wait for "Enter Title" prompt
        $response = readUntilBBSPrompt($socket, 10);
        logMessage("After SP: " . cleanLog($response));
        
        // Step 10: Send subject
        fwrite($socket, $subject . "\r\n");
        logMessage("  >> $subject");
        
        // Step 11: Brief pause for "Enter Message Text" prompt
        // Don't use readUntilBBSPrompt here — the prompt ends with ")" 
        // which doesn't match the prompt regex, causing a long timeout.
        // The BBS has a short window for body input; a long wait causes
        // it to cancel the message. Just drain whatever the BBS sends.
        usleep(500000);
        stream_set_blocking($socket, false);
        $bodyPrompt = '';
        $promptStart = time();
        while ((time() - $promptStart) < 2) {
            $chunk = @fread($socket, 4096);
            if ($chunk !== false && $chunk !== '') {
                $bodyPrompt .= $chunk;
            }
            usleep(50000);
        }
        stream_set_blocking($socket, true);
        logMessage("After title: " . cleanLog($bodyPrompt));
        
        // Step 12: Send message body line by line
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            // Escape /ex if it appears in the body
            if (preg_match('/^\/ex$/i', trim($line))) {
                $line = ' ' . $line;
            }
            fwrite($socket, $line . "\r\n");
            logMessage("  >> $line");
            usleep(30000); // 30ms delay between lines (matches working NWS poster)
        }
        
        // Step 13: Send /ex to end message
        fwrite($socket, "/ex\r\n");
        logMessage("  >> /ex");
        
        // Step 14: Wait for confirmation
        $response = readUntilBBSPrompt($socket, 15);
        logMessage("After /ex: " . cleanLog($response));
        
        // Check for success indicators
        $success = (strpos($response, 'Msg') !== false || 
                   strpos($response, 'saved') !== false || 
                   strpos($response, 'stored') !== false ||
                   strpos($response, '>') !== false);
        
        // Step 15: Disconnect
        fwrite($socket, "B\r\n");
        usleep(500000);
        fclose($socket);
        logMessage("Disconnected");
        
        if ($success) {
            return ['success' => true, 'message' => 'Message sent successfully'];
        } else {
            return ['success' => false, 'error' => 'Message may not have been sent - check BBS'];
        }
        
    } catch (Exception $e) {
        logMessage("ERROR: " . $e->getMessage());
        @fwrite($socket, "B\r\n");
        @fclose($socket);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Read from socket until a BBS prompt character is detected
function readUntilBBSPrompt($socket, $timeout = 5) {
    $buffer = '';
    $start = time();
    
    stream_set_blocking($socket, false);
    
    while ((time() - $start) < $timeout) {
        $data = @fread($socket, 4096);
        if ($data !== false && $data !== '') {
            $buffer .= $data;
            // Check for common prompts: "user:" "password:" "}" ">" ":"
            if (preg_match('/(>|:|\\})\\s*$/', trim($buffer))) {
                usleep(200000); // 200ms extra to catch trailing data
                $extra = @fread($socket, 4096);
                if ($extra) $buffer .= $extra;
                break;
            }
        }
        usleep(100000);
    }
    
    stream_set_blocking($socket, true);
    return $buffer;
}

// ===========================
// PARSE MESSAGE LIST
// ===========================

function parseMessageList($raw) {
    $messages = [];
    $lines = explode("\n", $raw);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (strpos($line, 'de ') === 0) continue;
        if (strpos($line, '>') === 0) continue;
        if (strpos($line, 'Connected') !== false) continue;
        
        // BPQ format: 9095   11-Jan BY     578 SYSOP  @WPNC8Q NC8Q   Subject
        if (preg_match('/^(\d+)\s+(\d{1,2}-\w{3})\s+([A-Z$]{1,3})\s+(\d+)\s+(\S+)\s+(@\S+)?\s*(\S+)\s+(.*)$/i', $line, $m)) {
            $messages[] = [
                'number' => intval($m[1]),
                'date' => $m[2],
                'status' => $m[3],
                'size' => intval($m[4]),
                'to' => $m[5],
                'route' => isset($m[6]) ? trim($m[6]) : '',
                'from' => $m[7],
                'subject' => trim($m[8]),
            ];
        }
        // Simpler fallback
        elseif (preg_match('/^(\d+)\s+(\d{1,2}-\w{3})\s+([A-Z$]{1,3})\s+(\d+)\s+(\S+)\s+(\S+)\s+(.*)$/i', $line, $m)) {
            $messages[] = [
                'number' => intval($m[1]),
                'date' => $m[2],
                'status' => $m[3],
                'size' => intval($m[4]),
                'to' => $m[5],
                'from' => $m[6],
                'subject' => trim($m[7]),
            ];
        }
    }
    
    return $messages;
}

// ===========================
// MAIN
// ===========================

// Handle POST requests (for sending messages)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    logMessage("POST Request: action=$action");
    
    if ($action === 'send') {
        $to = trim($input['to'] ?? '');
        $type = $input['type'] ?? 'P';
        $subject = trim($input['subject'] ?? '');
        $body = trim($input['body'] ?? '');
        
        // Validate inputs
        if (empty($to) || empty($subject) || empty($body)) {
            apiError('Missing required fields (to, subject, body)');
        }
        
        if (!validateAddress($to)) {
            apiError('Invalid destination address format');
        }
        
        // Validate type
        $type = strtoupper($type);
        if (!in_array($type, ['P', 'B'])) {
            $type = 'P';
        }
        
        logMessage("Sending message to: $to, type: $type");
        echo json_encode(sendMessage($to, $type, $subject, $body));
        exit;
    }
    
    if ($action === 'delete') {
        $msgNum = intval($input['msgNum'] ?? 0);
        
        if (!validateMsgNum($msgNum)) {
            apiError('Invalid message number');
        }
        
        logMessage("Deleting message: $msgNum");
        echo json_encode(deleteMessage($msgNum));
        exit;
    }
    
    apiError('Unknown action');
}

// Handle GET requests
$action = $_GET['action'] ?? 'list';
$count = min(intval($_GET['count'] ?? $config['default_count']), $config['max_count']);
$msgNum = intval($_GET['msg'] ?? 0);

logMessage("Request: action=$action, count=$count, msg=$msgNum");

// Validate action
$allowedActions = ['list', 'read', 'bulletins', 'test'];

if (!in_array($action, $allowedActions)) {
    apiError('Invalid action');
}

switch ($action) {
    case 'read':
        if (!validateMsgNum($msgNum)) {
            apiError('Invalid message number');
        }
        echo json_encode(fetchMessageBody($msgNum));
        break;
        
    case 'bulletins':
        $address = $_GET['address'] ?? '';
        $bulletinCount = min(intval($_GET['count'] ?? 10), 100);
        
        if (!validateAddress($address)) {
            apiError('Invalid bulletin address format');
        }
        
        logMessage("Bulletins request: address=$address, count=$bulletinCount");
        echo json_encode(fetchBulletins($address, $bulletinCount));
        break;
        
    case 'test':
        echo json_encode([
            'success' => true, 
            'message' => 'API working',
            'version' => '1.3.1'
        ]);
        break;
        
    default:
        echo json_encode(fetchMessages($count));
}
