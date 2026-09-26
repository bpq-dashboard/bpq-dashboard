<?php
/**
 * bpq-fwd-queue.php — BPQ Dashboard
 *
 * Logs into the BBS (same telnet flow bbs-messages.php already uses —
 * the configured bbs_user is SYSOP by default, which is required for
 * this) and issues the sysop-only "FWD QUEUE" command, which lists
 * every BBS callsign with mail currently queued for outbound forwarding
 * and how many messages are waiting.
 *
 * Output format (BPQ32/LinBPQ), one line per BBS with anything queued:
 *   CALLSIGN   N Msgs
 * A BBS with nothing queued simply doesn't appear in the list at all.
 *
 * This intentionally does NOT filter to RF-only hubs itself — the
 * dashboard already has that list client-side (getForwardingPartners()
 * in bpq-rf-connections.html), so filtering happens there. This script
 * just reports the full queue as BPQ sees it.
 *
 * GET ?action=queue → { success, queue: [{call, msgs}, ...], generated_at }
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// CLI-safety: lets this be run directly on the server via
// `php bpq-fwd-queue.php` for testing, bypassing the web server/network
// entirely — useful when network setup makes browser/curl testing hard.
// Without these, bootstrap.php's REQUEST_METHOD/PHP_SELF checks throw
// undefined-index warnings under CLI (harmless with display_errors off,
// but this avoids them and matches the pattern nts-forwarding-report.php
// already uses).
if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
if (!isset($_SERVER['PHP_SELF'])) $_SERVER['PHP_SELF'] = '/bpq-fwd-queue.php';

$SKIP_BBS_CHECK = true;   // this script validates bbs_pass itself, below
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');

$config = [
    'bbs_host'  => getConfig('bbs', 'host', 'localhost'),
    'bbs_port'  => getConfig('bbs', 'port', 8010),
    'bbs_user'  => getConfig('bbs', 'user', 'SYSOP'),
    'bbs_pass'  => getConfig('bbs', 'pass'),
    'bbs_alias' => getConfig('bbs', 'alias', 'bbs'),
    'timeout'   => getConfig('bbs', 'timeout', 30),
];

// apiError() is already provided by bootstrap.php — do not redeclare it
// here. Redeclaring a function PHP already defined is a fatal error,
// which (with display_errors correctly off) produces a completely
// empty response body — exactly the "unexpected end of data" JSON
// parse failure this would otherwise cause client-side.

function waitFor($socket, string $expect, int $timeout = 15): string {
    $buffer = '';
    $start = time();
    stream_set_blocking($socket, false);
    while ((time() - $start) < $timeout) {
        $chunk = @fread($socket, 8192);
        if ($chunk !== false && strlen($chunk) > 0) {
            $buffer .= $chunk;
            if (strpos($buffer, $expect) !== false) {
                usleep(500000);
                $extra = @fread($socket, 8192);
                if ($extra !== false && strlen($extra) > 0) $buffer .= $extra;
                stream_set_blocking($socket, true);
                return $buffer;
            }
        }
        usleep(100000);
    }
    stream_set_blocking($socket, true);
    return $buffer;
}

function sendCmd($socket, string $cmd): void {
    fwrite($socket, $cmd . "\r\n");
    fflush($socket);
    usleep(100000);
}

// Parses FWD QUEUE output into [{call, msgs}, ...]. Deliberately
// tolerant of BPQ's exact column spacing (same lesson learned the hard
// way with the message listing parser this session — never assume
// fixed columns from a single sample, use whitespace-token matching).
function parseFwdQueue(string $raw): array {
    $out = [];
    foreach (explode("\n", str_replace("\r", '', $raw)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^([A-Z0-9\-]+)\s+(\d+)\s+Msgs?\b/i', $line, $m)) {
            $out[] = ['call' => strtoupper($m[1]), 'msgs' => (int)$m[2]];
        }
    }
    return $out;
}

$action = $_GET['action'] ?? 'queue';
if ($action !== 'queue') apiError('Unknown action');

if (!$config['bbs_pass']) apiError('BBS not configured', 500);

$socket = @stream_socket_client(
    "tcp://{$config['bbs_host']}:{$config['bbs_port']}",
    $errno, $errstr, $config['timeout']
);
if (!$socket) apiError("Cannot connect: $errstr", 502);
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

    // Sysop-only command — requires bbs_user to actually have the SYSOP
    // flag (true by default per config above, but a misconfigured
    // non-sysop bbs_user would just get an empty/denied response here
    // rather than an error, since BPQ doesn't necessarily say "denied"
    // explicitly for every such case).
    sendCmd($socket, 'FWD QUEUE');
    $queueResponse = waitFor($socket, '>', 20);

    sendCmd($socket, 'B');
    usleep(500000);
    @fclose($socket);

    $queue = parseFwdQueue($queueResponse);

    echo json_encode([
        'success'      => true,
        'queue'        => $queue,
        'generated_at' => date('c'),
    ]);

} catch (Throwable $e) {
    @fclose($socket);
    apiError($e->getMessage(), 502);
}
