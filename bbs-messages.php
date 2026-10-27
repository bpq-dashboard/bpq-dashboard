<?php
/**
 * BPQ BBS Message API
 * Version: 1.3.1
 * 
 * Connects to BPQ BBS via telnet and provides message management
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load configuration and security via bootstrap
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/web-login.php';
// Reads your mail and acts on the BBS as the sysop: admin login only (2026-10-04)
// (CLI is exempt inside bpq_require_web_login(); scripts/fetch-message-bodies.php
// includes this file with BPQ_MESSAGES_LIB defined to reuse the telnet helpers.)
bpq_require_web_login('The Email Client');

// Get BBS config from loaded $CONFIG (bootstrap handles both config formats)
$config = [
    'bbs_host'      => getConfig('bbs', 'host', 'localhost'),
    'bbs_port'      => getConfig('bbs', 'port', 8010),
    'bbs_user'      => getConfig('bbs', 'user', 'SYSOP'),
    'bbs_pass'      => getConfig('bbs', 'pass'),
    'bbs_alias'     => getConfig('bbs', 'alias', 'bbs'),
    'timeout'       => getConfig('bbs', 'timeout', 30),
    'default_count' => getConfig('ui', 'default_msg_count', 20),
    // Was 100 — silently clamped the UI's "200 (slowest)" Settings option
    // back down to 100 before requests ever reached fetchAllLists()/
    // fetchMessageListByType() (both of which already cap at 200
    // internally), unless the server's config.php separately overrode
    // ui.max_msg_count. Bumped to match so 200 actually means 200.
    'max_count'     => getConfig('ui', 'max_msg_count', 200),
    'log_file'      => getConfig('logging', 'file', './logs/bbs-messages.log'),
];

// ===========================
// PASSWORD AUTHENTICATION
// ===========================

define('AUTH_FILE', __DIR__ . '/data/.bbs_auth');
define('AUTH_SALT', 'bpq_bbs_server_salt_2025_yourcall');

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
if (!defined('BPQ_MESSAGES_LIB') && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
            echo json_encode(['success' => true], JSON_INVALID_UTF8_SUBSTITUTE);
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

// ===========================
// ONE BBS SESSION AT A TIME (2026-10-12)
// ===========================
// BPQ's telnet port copes badly with two logins at once or back to back
// ("No password prompt", seen 10-06 and 10-12). The page's requests and
// scripts/fetch-message-bodies.php (systemd timer) take this lock around
// every BBS session, and a new session waits until 2 s after the last one
// ended. The lock file lives in cache/ (writable by www-data, also under
// php-fpm's PrivateTmp, which /tmp would not be). Returns the handle,
// null when no lock file can be opened (then we just go ahead, as before),
// or false when another session held it for longer than $waitSeconds.
define('BPQ_TELNET_LOCK_FILE', __DIR__ . '/cache/bpq-telnet.lock');
define('BPQ_TELNET_GAP_SECONDS', 2.0);

function bpqTelnetLock($waitSeconds = 40) {
    $h = @fopen(BPQ_TELNET_LOCK_FILE, 'c+');
    if (!$h) return null;
    $start = microtime(true);
    while (!flock($h, LOCK_EX | LOCK_NB)) {
        if (microtime(true) - $start > $waitSeconds) { fclose($h); return false; }
        usleep(250000);
    }
    rewind($h);
    $last = (float)trim((string)stream_get_contents($h));
    $since = microtime(true) - $last;
    if ($last > 0 && $since >= 0 && $since < BPQ_TELNET_GAP_SECONDS) {
        usleep((int)((BPQ_TELNET_GAP_SECONDS - $since) * 1e6));
    }
    return $h;
}

function bpqTelnetUnlock($h) {
    if (!$h) return;
    // Record when this session ended, for the next one's gap.
    @ftruncate($h, 0); @rewind($h);
    @fwrite($h, sprintf('%.3f', microtime(true)));
    @fflush($h);
    @flock($h, LOCK_UN);
    @fclose($h);
}

function validateMsgNum($num) {
    return is_numeric($num) && intval($num) > 0;
}

function validateAddress($addr) {
    // ISP Gateway (BPQ's direct SMTP relay, distinct from Winlink/RMS)
    // addressing: smtp:user@domain.tld — validate the part after the
    // prefix as a real email address rather than a callsign.
    if (stripos($addr, 'smtp:') === 0) {
        $email = substr($addr, 5);
        return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email);
    }
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

// waitFor() returns whatever it managed to read even if it timed out
// before ever seeing $expect — there's no exception, no flag, nothing
// to distinguish "the BBS sent a short list and we saw the prompt" from
// "the BBS was still mid-list when we gave up waiting". For login-stage
// prompts that's caught immediately by the callers' own strpos() checks
// (which throw). List responses (LP/LT/LB) were never checked the same
// way, so a slow/large LB listing that blew through the timeout would
// silently return a partial buffer — and since BPQ lists oldest-first,
// a cut-off buffer is missing exactly the newest bulletins at the end,
// with no error surfaced anywhere. This just checks whether the
// terminating BBS prompt ('>') actually showed up in the buffer.
function responseLooksTruncated($response) {
    return strpos($response, '>') === false;
}

function sendCmd($socket, $cmd) {
    global $config;
    fwrite($socket, $cmd . "\r\n");
    fflush($socket);
    // 2026-10-03: never write the BBS password to the log. Every login
    // used to log ">> <password>" in plain text, and logs/ is web-served.
    $isPass = isset($config['bbs_pass']) && $config['bbs_pass'] !== ''
        && $cmd === $config['bbs_pass'];
    logMessage($isPass ? "  >> (password)" : "  >> $cmd");
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
        
        // Read response - LB is typically the largest listing (bulletin
        // volume tends to dwarf personal/traffic), so it gets the longest
        // timeout of the three list commands.
        $response = waitFor($socket, '>', 45);
        logMessage("Response length: " . strlen($response));
        logMessage("Response: " . cleanLog($response));

        // If we never saw the closing prompt, BPQ was still mid-listing
        // when the timeout hit — the buffer we have is a PREFIX of the
        // full list. CORRECTED 2026-08-03: this used to say "oldest-
        // first... missing the newest bulletins at the tail end," which
        // is backwards — verified (see fetchAllLists()'s matching fix)
        // that BPQ's raw LB output is actually newest-first, so a prefix
        // from a truncated response is missing the OLDEST bulletins, not
        // the newest (which already arrived at the very start).
        $truncated = responseLooksTruncated($response);
        if ($truncated) {
            logMessage("WARNING: LB response truncated (no closing prompt seen within timeout) — result is missing the oldest bulletins");
        }

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
            // Slice from the FRONT, not the end — CORRECTED 2026-08-03:
            // BPQ's LB output is verified newest-first by descending
            // message number (not oldest-first as previously assumed
            // here), so a front slice is what actually keeps the newest
            // $count; an end slice kept stale old bulletins and dropped
            // the newest ones once the list exceeded $count.
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
        
        return ['success' => true, 'messages' => $bulletins, 'total' => count($allBulletins), 'truncated' => $truncated];
        
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
        
        // Check for success/failure. "Not found" is split out from the
        // other error cases deliberately: if BPQ doesn't have the
        // message anymore (already aged off its own mailbox — common
        // for old bulletins), there's nothing left to kill, and that's
        // functionally the same outcome we wanted. Treating it as a
        // hard failure left stale bulletins permanently stuck — every
        // Kill/Empty-Trash attempt would fail forever, the local DB
        // row would never get soft-deleted, and it would keep coming
        // back on every page load. "not yours" and "Invalid" are real
        // rejections (wrong permissions / bad command) and still fail.
        $success = (strpos($response, 'Killed') !== false || 
                   strpos($response, 'deleted') !== false ||
                   strpos($response, 'Message') !== false);
        $notFound = (strpos($response, 'Not found') !== false);
        $rejected = (strpos($response, 'not yours') !== false ||
                    strpos($response, 'Invalid') !== false);
        
        // Disconnect
        sendCmd($socket, 'B');
        usleep(500000);
        fclose($socket);
        logMessage("Disconnected");
        
        if ($rejected) {
            return ['success' => false, 'error' => 'Could not delete message - not authorized'];
        }

        if ($notFound) {
            logMessage("Message #$msgNum already gone from BBS — treating as successfully removed");
            return ['success' => true, 'alreadyGone' => true, 'message' => 'Message was already gone from the BBS'];
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
// BPQ MAIL V2 — TYPED LISTINGS AND ACTIONS
// ===========================
//
// Used by the new email-client UI (bbs-messages.html v2). Provides:
//   - fetchMessageListByType()  — LP / LT / LB selective listing
//   - forwardMessage()          — F <n> <to>
//   (markMessageStatus() removed 2026-10-04: BPQ has no set-read-flag command)
//
// All these helpers reuse the existing connection pattern (open socket,
// login, send cmd, parse, close). For efficiency, a future optimization
// would batch multiple commands into a single connection, but each here
// is one-shot for safety and simplicity.

/**
 * Fetch a typed message list from BPQ Mail.
 *
 * @param string $type   'personal' | 'traffic' | 'bulletins'
 * @param int    $count  max messages to return (server caps at 200)
 * @return array         { success, count, messages, type, timestamp }
 */
function fetchMessageListByType($type, $count) {
    global $config;
    $count = min(intval($count), 200);

    // Map type -> BPQ command
    $cmdMap = [
        'personal'   => 'LP',
        'traffic'    => 'LT',
        'bulletins'  => 'LB',
    ];
    if (!isset($cmdMap[$type])) {
        return ['success' => false, 'error' => "Invalid list type: $type"];
    }
    $cmd = $cmdMap[$type];

    logMessage("=== Fetch $type list (cmd=$cmd, count=$count) ===");

    $socket = @stream_socket_client(
        "tcp://{$config['bbs_host']}:{$config['bbs_port']}",
        $errno, $errstr, $config['timeout']
    );
    if (!$socket) {
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    stream_set_timeout($socket, $config['timeout']);

    try {
        // Login (same pattern as fetchMessages)
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

        // Send the typed list command. LB (bulletins) gets a longer
        // timeout than LP/LT — it's typically the largest listing, and a
        // response that gets cut off mid-list is missing exactly the
        // newest entries (BPQ lists oldest-first).
        sendCmd($socket, $cmd);
        $listTimeout = ($type === 'bulletins') ? 45 : 25;
        $response = waitFor($socket, '>', $listTimeout);
        $truncated = responseLooksTruncated($response);
        if ($truncated) {
            logMessage("WARNING: $type list response truncated (no closing prompt within {$listTimeout}s) — missing the newest entries");
        }

        // Disconnect (500ms sleep matches the proven pattern elsewhere
        // and prevents fclose() from blocking on orderly TCP shutdown)
        sendCmd($socket, 'B');
        usleep(500000);
        @fclose($socket);

        // Parse — same parser handles all three (the line format is the same)
        $messages = parseMessageList($response);

        // Tag each message with its source type for client-side categorization
        foreach ($messages as &$m) {
            $m['_listType'] = $type;
        }

        // Honor caller's count limit. CORRECTED 2026-08-03: earlier
        // comment here claimed BPQ's L* commands list oldest-first
        // ascending, and sliced from the END to compensate — but that
        // was verified wrong against this station's actual raw LB
        // output (logs/bbs-messages.log, 22:52:41 fetch): the response
        // starts with today's highest numbers (6029, 6028, 6027...) and
        // ends, later in the same response, at 22-Jul entries. That's
        // NEWEST-first, not oldest-first. Slicing from the end (as the
        // old code did) therefore kept the OLDEST $count and silently
        // discarded every bulletin newer than that window — the actual
        // cause of bulletins going missing for days at a time, upstream
        // of anything the archive/DB/frontend layers could fix. Slice
        // from the FRONT now to keep the newest $count instead.
        if (count($messages) > $count) {
            $messages = array_slice($messages, 0, $count);
        }

        logMessage("Parsed " . count($messages) . " $type messages");

        return [
            'success'   => true,
            'count'     => count($messages),
            'messages'  => $messages,
            'type'      => $type,
            'truncated' => $truncated,
            'timestamp' => date('c'),
        ];

    } catch (Exception $e) {
        logMessage("ERROR fetchMessageListByType($type): " . $e->getMessage());
        if (is_resource($socket)) { @fwrite($socket, "B\r\n"); @fclose($socket); }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Fetch all three message lists (personal / traffic / bulletins) in a
 * SINGLE telnet session. One login, three LIST commands, one logout.
 *
 * This is the preferred fetch path for the v2 email-client UI because:
 *   1. It's faster — 1 login round-trip instead of 3
 *   2. It's reliable — BPQ32 sometimes refuses to send the password prompt
 *      to a new session that opens immediately after a prior one closed
 *      (BPQ is still cleaning up). Sequential per-type calls hit this
 *      race; a single session avoids it entirely.
 *
 * @param int $count  Max messages per type (server caps at 200)
 * @return array      {
 *   success: bool,
 *   personal:  [...],
 *   traffic:   [...],
 *   bulletins: [...],
 *   timestamp: ISO 8601,
 *   error:    string (only on connection-level failures)
 * }
 *
 * If individual list commands fail (e.g. a single LIST returns empty),
 * that list will just be an empty array and we continue — the call as
 * a whole still returns success=true.
 */
function fetchAllLists($count) {
    global $config;
    $count = min(intval($count), 200);

    logMessage("=== Fetch ALL lists in one session (count=$count per type) ===");

    $socket = @stream_socket_client(
        "tcp://{$config['bbs_host']}:{$config['bbs_port']}",
        $errno, $errstr, $config['timeout']
    );
    if (!$socket) {
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    stream_set_timeout($socket, $config['timeout']);

    try {
        // Login
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

        $results = [];
        $truncated = [];
        // Iterate LP -> LT -> LB inside the same session
        $sequence = [
            'personal'  => 'LP',
            'traffic'   => 'LT',
            'bulletins' => 'LB',
        ];

        foreach ($sequence as $type => $cmd) {
            logMessage("  Sending $cmd ($type)");
            sendCmd($socket, $cmd);

            // Wait for the next BBS prompt — gives BPQ time to flush
            // the listing. LB gets the longest timeout since bulletin
            // volume is typically far larger than personal/traffic.
            $listTimeout = ($type === 'bulletins') ? 45 : 30;
            $resp = waitFor($socket, '>', $listTimeout);

            // If the closing prompt never showed up, this response was
            // cut off mid-listing. CORRECTED 2026-08-03: this used to say
            // "since BPQ lists oldest-first, the newest entries never
            // arrived" — backwards, per the verified newest-first order
            // (see the count-slice comment below). A truncated response
            // actually means the OLDEST entries for this type got cut off
            // (they arrive last), not the newest — the newest already
            // arrived at the very start of the response before the
            // timeout hit. Left as a truncation warning either way since
            // losing any part of the listing is still worth flagging.
            $truncated[$type] = responseLooksTruncated($resp);
            if ($truncated[$type]) {
                logMessage("  WARNING: $type response truncated (no closing prompt within {$listTimeout}s) — missing the oldest entries from this fetch");
            }

            $messages = parseMessageList($resp);
            // Tag each with its source type for client-side classification
            foreach ($messages as &$m) {
                $m['_listType'] = $type;
            }
            unset($m);

            // Honor caller's per-type count limit. CORRECTED 2026-08-03 —
            // see fetchMessageListByType()'s matching comment for the
            // full explanation: BPQ's raw output is verified NEWEST-first
            // (from logs/bbs-messages.log), not oldest-first as previously
            // assumed here, so slicing from the end kept the OLDEST
            // $count and discarded every bulletin newer than that window.
            // This is the path "Get Mail" actually uses
            // (action=list_all), so this was the real cause of bulletins
            // from later days never showing up once the Bulletins area on
            // the BBS grew past $count (default 50) messages. Slice from
            // the FRONT to keep the newest $count instead.
            if (count($messages) > $count) {
                $messages = array_slice($messages, 0, $count);
            }

            logMessage("  $type: " . count($messages) . " messages");
            $results[$type] = $messages;
        }

        // Logout. The 500ms sleep before fclose mirrors the pattern in
        // fetchMessages() and is necessary: it gives BPQ enough time to
        // send its TCP FIN. Without it, fclose() can block for a very
        // long time (we saw 130+ seconds via curl) on an orderly-shutdown
        // wait, since the kernel needs the peer's close before fully
        // freeing the socket.
        sendCmd($socket, 'B');
        usleep(500000);
        @fclose($socket);
        logMessage("Disconnected");

        return [
            'success'   => true,
            'personal'  => $results['personal'],
            'traffic'   => $results['traffic'],
            'bulletins' => $results['bulletins'],
            'counts' => [
                'personal'  => count($results['personal']),
                'traffic'   => count($results['traffic']),
                'bulletins' => count($results['bulletins']),
            ],
            // Per-type truncation, plus a convenience OR of all three so
            // the client can show one warning without inspecting each key.
            'truncated' => $truncated,
            'anyTruncated' => in_array(true, $truncated, true),
            'timestamp' => date('c'),
        ];

    } catch (Exception $e) {
        logMessage("ERROR fetchAllLists: " . $e->getMessage());
        if (is_resource($socket)) { @fwrite($socket, "B\r\n"); @fclose($socket); }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Forward a message to another address.
 *
 * @param int    $msgNum
 * @param string $toAddr  e.g. "N4VAD@N4VAD.GA.USA.NOAM"
 * @return array          { success, message }
 */
function forwardMessage($msgNum, $toAddr) {
    global $config;

    if (!validateAddress($toAddr)) {
        return ['success' => false, 'error' => 'Invalid forwarding address'];
    }

    logMessage("=== Forward msg #$msgNum to $toAddr ===");

    $socket = @stream_socket_client(
        "tcp://{$config['bbs_host']}:{$config['bbs_port']}",
        $errno, $errstr, $config['timeout']
    );
    if (!$socket) {
        return ['success' => false, 'error' => "Cannot connect: $errstr"];
    }
    stream_set_timeout($socket, $config['timeout']);

    try {
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

        // BPQ Mail's user-callable forward is "SC" (Send Copy):
        //   SC <msg#> <callsign>          — within local BBS
        //   SC <msg#> <callsign> @ <bbs>  — to another BBS
        // (Reference: BBSUserCommands.html — there's no `F` command for
        // users; that's why the original `F 8601 N4VAD@...` returned
        // "Invalid Command".)
        //
        // The toAddr the client sends usually has the form
        //   N4VAD@N4VAD.GA.USA.NOAM
        // BPQ accepts that form OR  "N4VAD @ N4VAD.GA.USA.NOAM".  We
        // pass it through unchanged; if BPQ complains we can split it.
        $cmd = "SC $msgNum $toAddr";
        logMessage("Sending: $cmd");
        sendCmd($socket, $cmd);

        // SC may produce a prompt like "Subject:" or "Enter message,"
        // depending on the BPQ build. In most modern builds it just
        // copies and returns to the > prompt. We wait briefly for
        // either '>' or 'Subject:' / 'Title:' so we don't hang waiting
        // for a body if one is requested.
        $response = waitFor($socket, '>', 15);
        logMessage("Response: " . cleanLog($response));

        // If BPQ asked for a Subject or message body (older versions
        // do), send a terminator so we exit the entry mode cleanly.
        if (stripos($response, 'subject') !== false ||
            stripos($response, 'title')   !== false ||
            stripos($response, 'enter')   !== false) {
            // Send /EX on its own line to terminate message entry
            sendCmd($socket, '/EX');
            $response = waitFor($socket, '>', 10);
            logMessage("After /EX: " . cleanLog($response));
        }

        sendCmd($socket, 'B');
        usleep(500000);
        @fclose($socket);
        logMessage("Disconnected");

        // Heuristic success/failure detection — BPQ doesn't return
        // structured status codes, so we look at the response text
        $err = (stripos($response, 'not found') !== false ||
                stripos($response, 'not yours') !== false ||
                stripos($response, 'invalid')   !== false ||
                stripos($response, 'no such')   !== false);

        if ($err) {
            return ['success' => false, 'error' => 'Forward rejected by BBS: ' . trim(cleanLog($response))];
        }
        return ['success' => true, 'message' => "Forwarded (SC) msg #$msgNum to $toAddr"];

    } catch (Exception $e) {
        logMessage("ERROR forwardMessage: " . $e->getMessage());
        if (is_resource($socket)) { @fwrite($socket, "B\r\n"); @fclose($socket); }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Change the BPQ status flag on a message (read/unread/forwarded).
 *
 * @param int    $msgNum
 * @param string $flag    Y (read), N (new/unread), F (forwarded)
 * @return array
 */
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
        // Callsign addresses are conventionally uppercased; an smtp:
        // (ISP Gateway) address is a real email address and shouldn't
        // be — preserve it exactly as given.
        $toForCmd = (stripos($to, 'smtp:') === 0) ? $to : strtoupper($to);
        $sendCmd = "S{$type} " . $toForCmd;
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
    $lines = explode("\n", str_replace("\r", '', $raw));

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (strpos($line, 'de ') === 0) continue;
        if (strpos($line, '>') === 0) continue;
        if (strpos($line, 'Connected') !== false) continue;

        // BPQ format: 9095   11-Jan BY     578 SYSOP  @WPNC8Q NC8Q   Subject
        // ROUTE is explicitly marked with a leading "@" when present, and
        // simply absent (not blank-padded) for plain personal mail with
        // no network hop — the optional (@\S+)? group handles both
        // correctly since it's zero-width when there's no "@" token to
        // match, letting FROM land in the right place either way.
        //
        // NOTE: an earlier version of this function used fixed-width
        // column slicing instead, based on a listing sample that turned
        // out to have lost its "@" markers and original column spacing
        // somewhere in copy/paste before it reached us. That produced
        // corrupted `to` values (e.g. "1 ARNR" instead of "ARNR") against
        // this station's real raw output, which does use "@". Verified
        // against a second, directly-pasted raw sample that this
        // token-based approach parses every line correctly — bulletins
        // with a route, personal mail without one, 5-digit sizes,
        // punctuation-heavy subjects (`7+ FOOTBA_1.JPG 9/11`, `[425ENG]
        // 425 DX News 1839 Cal`, etc.) all came back clean.
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
        // Simpler fallback for anything that doesn't match the pattern
        // above (defensive only — kept from before this revert).
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

// Included as a library (scripts/fetch-message-bodies.php): functions and
// $config only, no request handling.
if (defined('BPQ_MESSAGES_LIB')) return;

// Every request below talks to the BBS: take the shared lock first, and
// release it when PHP finishes (all paths end in echo/exit/apiError).
$bpqLock = bpqTelnetLock(40);
if ($bpqLock === false) {
    logMessage("BBS busy: lock not free after 40 s");
    apiError('The BBS is busy (background message download). Try again in a minute.', 503);
}
register_shutdown_function(function () use ($bpqLock) { bpqTelnetUnlock($bpqLock); });

// Handle POST requests (for sending messages)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    logMessage("POST Request: action=$action");

    // Everything that changes the BBS honours features.bbs_write
    // (forced off in public mode by bootstrap.php). 2026-10-20.
    if (in_array($action, ['send', 'delete', 'kill', 'forward'], true) && !isFeatureEnabled('bbs_write')) {
        apiError('Sending, forwarding and killing messages is turned off on this dashboard (features.bbs_write in config.php)', 403);
    }
    
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
        echo json_encode(sendMessage($to, $type, $subject, $body), JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    
    if ($action === 'delete') {
        $msgNum = intval($input['msgNum'] ?? 0);
        
        if (!validateMsgNum($msgNum)) {
            apiError('Invalid message number');
        }
        
        logMessage("Deleting message: $msgNum");
        echo json_encode(deleteMessage($msgNum), JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Kill / Forward (2026-10-20): POST only. They were GETs, which a link,
    // an image tag or a prefetch could trigger for a logged-in sysop.
    if ($action === 'kill') {
        $msgNum = intval($input['msg'] ?? $input['msgNum'] ?? 0);
        if (!validateMsgNum($msgNum)) apiError('Invalid message number');
        logMessage("Killing message: $msgNum");
        echo json_encode(deleteMessage($msgNum), JSON_INVALID_UTF8_SUBSTITUTE);   // K command
        exit;
    }

    if ($action === 'forward') {
        $msgNum = intval($input['msg'] ?? $input['msgNum'] ?? 0);
        if (!validateMsgNum($msgNum)) apiError('Invalid message number');
        $toAddr = trim((string)($input['to'] ?? ''));
        if (!validateAddress($toAddr)) apiError('Invalid forwarding address');
        logMessage("Forwarding message $msgNum to $toAddr");
        echo json_encode(forwardMessage($msgNum, $toAddr), JSON_INVALID_UTF8_SUBSTITUTE);
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
$allowedActions = [
    'list', 'read', 'bulletins', 'test',
    // v2 — typed listings
    'list_personal', 'list_traffic', 'list_bulletins',
    // v2.1 — combined-session listing (preferred for v2 client)
    'list_all',
];

// Kill / Forward moved to POST (2026-10-20); say so instead of "Invalid action".
if ($action === 'kill' || $action === 'forward') {
    header('Allow: POST');
    apiError("'$action' must be sent as a POST request (update bbs-messages.html)", 405);
}

if (!in_array($action, $allowedActions)) {
    apiError('Invalid action');
}

// Ensure every JSON response has the proper Content-Type. Without this,
// nginx defaults to text/html which interferes with content encoding and
// can cause the browser to receive corrupted/empty payloads despite the
// HTTP layer reporting 200 OK. Setting it once here covers all the
// case branches below.
if (!headers_sent()) {
    header('Content-Type: application/json');
}

switch ($action) {
    case 'read':
        if (!validateMsgNum($msgNum)) {
            apiError('Invalid message number');
        }
        echo json_encode(fetchMessageBody($msgNum), JSON_INVALID_UTF8_SUBSTITUTE);
        break;
        
    case 'bulletins':
        $address = $_GET['address'] ?? '';
        $bulletinCount = min(intval($_GET['count'] ?? 10), 100);
        
        if (!validateAddress($address)) {
            apiError('Invalid bulletin address format');
        }
        
        logMessage("Bulletins request: address=$address, count=$bulletinCount");
        echo json_encode(fetchBulletins($address, $bulletinCount), JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    // ── v2 typed listings ────────────────────────────────────────
    case 'list_personal':
        echo json_encode(fetchMessageListByType('personal', $count), JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    case 'list_traffic':
        echo json_encode(fetchMessageListByType('traffic', $count), JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    case 'list_bulletins':
        echo json_encode(fetchMessageListByType('bulletins', $count), JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    // ── v2.1 combined-session listing ────────────────────────────
    case 'list_all':
        echo json_encode(fetchAllLists($count), JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    case 'test':
        echo json_encode([
            'success' => true, 
            'message' => 'API working',
            'version' => '1.4.0',
            'actions'  => $allowedActions,
        ]);
        break;
        
    default:
        echo json_encode(fetchMessages($count), JSON_INVALID_UTF8_SUBSTITUTE);
}
