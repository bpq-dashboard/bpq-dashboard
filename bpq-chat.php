<?php
/**
 * bpq-chat.php — BPQ Dashboard Chat Backend
 * Sequence-based message deduplication, no cookie headers.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store');

require_once __DIR__ . '/includes/web-login.php';
// Talks on BPQ Chat as the station: admin login only (2026-10-04)
bpq_require_web_login('Chat');

// Load config
$configFile = __DIR__ . '/config.php';
$CONFIG     = file_exists($configFile) ? include($configFile) : [];
$BBS_HOST   = $CONFIG['bbs']['host']         ?? 'localhost';
$BBS_PORT   = $CONFIG['bbs']['port']         ?? 8010;
$BBS_USER   = $CONFIG['bbs']['user']         ?? 'SYSOP';
$BBS_PASS   = $CONFIG['bbs']['pass']         ?? '';
$CALLSIGN   = $CONFIG['station']['callsign'] ?? 'N0CALL';

$SESS_DIR   = __DIR__ . '/cache/chat-sessions';
$MSG_FILE   = $SESS_DIR . '/chat-messages.json';
$CMD_QUEUE  = $SESS_DIR . '/chat-cmd-queue.json';
$CMD_LOCK   = $SESS_DIR . '/chat-cmd-queue.lock';
$STATE_FILE = $SESS_DIR . '/chat-daemon.json';

// Session ID passed from browser in request body (no cookies)
$raw    = file_get_contents('php://input');
$input  = json_decode($raw, true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? 'status');
$sessId = $input['sess_id'] ?? ($_GET['sess_id'] ?? '');

// Validate or ignore sessId
if (!$sessId || !preg_match('/^[a-f0-9]{32}$/', $sessId)) {
    $sessId = '';
}
$seqFile = $sessId ? $SESS_DIR . '/seq_' . $sessId . '.json' : '';

// Clean up old seq files (>2 hours)
foreach (glob($SESS_DIR . '/seq_*.json') ?: [] as $f) {
    if (filemtime($f) < time()-7200) @unlink($f);
}

function getDaemonState(): array {
    global $STATE_FILE;
    if (!file_exists($STATE_FILE)) return ['connected'=>false];
    $d = @json_decode(file_get_contents($STATE_FILE), true);
    if (!is_array($d)) return ['connected'=>false];
    if ((time()-($d['updated']??0)) > 60) $d['connected'] = false;
    return $d;
}

function getAllMessages(): array {
    global $MSG_FILE;
    if (!file_exists($MSG_FILE)) return [];
    $msgs = @json_decode(file_get_contents($MSG_FILE), true);
    return is_array($msgs) ? $msgs : [];
}

function getLastSeq(): int {
    global $seqFile;
    if (!$seqFile || !file_exists($seqFile)) return 0;
    $d = @json_decode(file_get_contents($seqFile), true);
    return intval($d['seq'] ?? 0);
}

function saveLastSeq(int $seq): void {
    global $seqFile;
    if (!$seqFile) return;
    file_put_contents($seqFile, json_encode(['seq'=>$seq]), LOCK_EX);
}

function getNewMessages(): array {
    $lastSeq = getLastSeq();
    $all     = getAllMessages();
    if (empty($all)) return [];
    $new = array_values(array_filter($all, fn($m) => intval($m['seq']??0) > $lastSeq));
    if (!empty($new)) {
        $maxSeq = max(array_map(fn($m) => intval($m['seq']??0), $new));
        saveLastSeq($maxSeq);
    }
    return $new;
}

function sendCommand(string $cmd): bool {
    global $CMD_QUEUE, $CMD_LOCK, $SESS_DIR;
    @mkdir($SESS_DIR, 0750, true);
    // Append to JSON queue file with file locking
    $lf = @fopen($CMD_LOCK, 'w');
    if ($lf && flock($lf, LOCK_EX)) {
        $queue = [];
        if (file_exists($CMD_QUEUE)) {
            $q = @json_decode(file_get_contents($CMD_QUEUE), true);
            if (is_array($q)) $queue = $q;
        }
        $queue[] = ['cmd' => $cmd, 'ts' => microtime(true)];
        file_put_contents($CMD_QUEUE, json_encode($queue), LOCK_EX);
        flock($lf, LOCK_UN);
        fclose($lf);
        return true;
    }
    return false;
}

// STATUS
if ($action === 'status') {
    $state = getDaemonState();
    echo json_encode(['success'=>true,'connected'=>$state['connected']??false,
        'mode'=>$state['mode']??null]);
    exit;
}

// CONNECT
if ($action === 'connect') {
    $mode     = $input['mode'] ?? 'chat';
    // 2026-10-05: the old per-user path stored a visitor's BBS password in
    // cache/chat-sessions/ but never used it — every message went through the
    // station's daemon anyway. Removed; any bbs_user/bbs_pass sent is ignored.

    // Station daemon path — just wait for daemon to be connected
    $timeout = time() + 15;
    while (time() < $timeout) {
        $st = getDaemonState();
        if (!empty($st['connected'])) {
            $all    = getAllMessages();
            $maxSeq = empty($all) ? 0 : max(array_map(fn($m)=>intval($m['seq']??0),$all));
            saveLastSeq($maxSeq);
            echo json_encode(['success'=>true,'mode'=>$st['mode']??$mode,
                'output'=>"Connected to BPQ Chat
*** Type /H for help, /U for users online"]);
            exit;
        }
        usleep(500000);
    }
    echo json_encode(['success'=>false,
        'error'=>'Chat daemon not connected. Check: sudo systemctl status bpq-chat']);
    exit;
}

// POLL
if ($action === 'poll') {
    $state = getDaemonState();
    if (empty($state['connected'])) {
        echo json_encode(['success'=>false,'error'=>'Not connected']);
        exit;
    }
    $msgs   = getNewMessages();
    $output = implode("\n", array_column($msgs, 'text'));
    echo json_encode(['success'=>true,'output'=>$output,'messages'=>$msgs,
        'mode'=>$state['mode']??'chat']);
    exit;
}

// SEND
if ($action === 'send') {
    $state = getDaemonState();
    if (empty($state['connected'])) {
        echo json_encode(['success'=>false,'error'=>'Not connected']);
        exit;
    }
    $text = trim($input['text'] ?? '');
    if ($text==='') { echo json_encode(['success'=>true,'output'=>'']); exit; }

    $ok = sendCommand($text);
    if (!$ok) {
        echo json_encode(['success'=>false,'error'=>'Cannot write to command pipe']);
        exit;
    }
    usleep(400000);
    $msgs   = getNewMessages();
    $output = implode("\n", array_column($msgs, 'text'));
    echo json_encode(['success'=>true,'output'=>$output,'messages'=>$msgs,
        'mode'=>$state['mode']??'chat']);
    exit;
}

// WHO — send /U and return ONLY the latest response
if ($action === 'who') {
    $state = getDaemonState();
    if (empty($state['connected'])) {
        echo json_encode(['success'=>false,'error'=>'Not connected']);
        exit;
    }

    // 2026-10-24: this used to move the browser's own poll position (seq
    // file) and then read through it. The page polls every 2 s on the same
    // seq file, so a poll landing during the 1.5 s wait swallowed the /U
    // reply: the poll hid it from the terminal, and this action found
    // nothing — the Online panel stayed empty. Now we note where the buffer
    // ends, send /U, and scan the shared buffer directly without touching
    // the poll position. We also wait for the whole reply instead of a
    // fixed 1.5 s.
    $all      = getAllMessages();
    $startSeq = empty($all) ? 0 : max(array_map(fn($m)=>intval($m['seq']??0),$all));

    if (!sendCommand('/U')) {
        echo json_encode(['success'=>false,'error'=>'Cannot write to command pipe']);
        exit;
    }

    $whoLines = [];
    $expected = -1;
    $other    = [];
    $deadline = microtime(true) + 5.0;
    do {
        usleep(250000);
        $whoLines = []; $expected = -1; $other = [];
        foreach (getAllMessages() as $msg) {
            if (intval($msg['seq'] ?? 0) <= $startSeq) continue;
            // Colour-mode lines start with ESC + one colour byte
            $text = preg_replace('/^\x1b./s', '', (string)($msg['text'] ?? ''));
            if (preg_match('/^(\d+)\s+Station\(s\)\s+connected/i', $text, $mm)) {
                $whoLines = [$text];           // newest block wins
                $expected = intval($mm[1]);
            } elseif ($expected >= 0 && preg_match('/\s+at\s+\S+.*\[.*\]\s+Idle for \d+/i', $text)) {
                $whoLines[] = $text;
            } elseif (count($other) < 5) {
                $other[] = $text;              // kept for troubleshooting
            }
        }
        if ($expected >= 0 && count($whoLines) - 1 >= $expected) break;
    } while (microtime(true) < $deadline);

    if ($expected < 0) {
        // No /U reply at all — usually the daemon is no longer inside the
        // chat server (e.g. BPQ's 2-hour idle disconnect left it at the
        // node prompt). Return what did come back so the page can say so.
        echo json_encode(['success'=>false,'error'=>'No reply to /U from the chat server',
            'reply'=>implode("\n", $other)]);
        exit;
    }

    echo json_encode(['success'=>true,'output'=>implode("\n", $whoLines),
        'expected'=>$expected,'complete'=>(count($whoLines) - 1 >= $expected)]);
    exit;
}

// DISCONNECT
if ($action === 'disconnect') {
    if ($seqFile) @unlink($seqFile);
    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action: '.$action]);
