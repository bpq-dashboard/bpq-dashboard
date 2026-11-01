<?php
/**
 * includes/bbs-login.php — one BBS login for every page and job (2026-11-01)
 * BPQ Dashboard v1.5.18
 *
 * Logs in on an open telnet socket and leaves the session at the BBS prompt.
 *
 *   user: -> <bbs.user> -> password: -> <bbs.pass> -> greeting
 *     at the NODE (USER line with no application): send <bbs.alias>, wait for '>'
 *     already in the BBS (USER line whose application is BBS): carry on
 *
 * Until v1.5.17 the Email Client, message-bodies and the forward queue waited
 * for a '}' after the password and gave up ("Login failed") when the node's
 * greeting had none — N3MEL's station, whose USER line lands at the node with
 * a greeting that ends without '}'. bpq-nodes-api.php already logged in this
 * way (greeting finished + no failure words = logged in); this file is that
 * logic, shared.
 *
 * /includes/ is 403 on the web; PHP require only.
 */

if (!function_exists('bpq_bbs_login')) {

/** Strip telnet negotiation and ANSI colour codes. */
function bpq_bbs_login_clean(string $buf): string {
    $buf = preg_replace('/\xff[\xfb\xfc\xfd\xfe]./s', '', $buf);
    $buf = preg_replace('/\xff\xff/s', "\xff", $buf);
    $buf = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/s', '', $buf);
    return (string)$buf;
}

/** Last non-empty line, printable characters only, for error messages. */
function bpq_bbs_login_tail(string $buf, int $max = 80): string {
    $lines = preg_split('/\r\n|\r|\n/', bpq_bbs_login_clean($buf));
    $lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
    $last  = $lines ? end($lines) : '';
    $last  = trim(preg_replace('/[^\x20-\x7e]+/', ' ', $last));
    return $last === '' ? '(nothing)' : substr($last, -$max);
}

/** Is the buffer ending at a BBS prompt ("de N0CALL>", or after "Connected to BBS")? */
function bpq_bbs_login_at_bbs(string $clean): bool {
    $tail = rtrim($clean);
    if (preg_match('/\bde [A-Z0-9\/\-]+>$/i', $tail)) return true;
    // BPQ Mail's prompt is configurable; after "Connected to BBS" or the
    // SID ([BPQ-6.0.24.1-B1FWIHJM$]) any line ending in '>' is the prompt.
    if ((stripos($clean, 'Connected to BBS') !== false || preg_match('/\[[A-Za-z0-9.\-]+\$\]/', $clean))
        && preg_match('/>$/', $tail)) return true;
    return false;
}

/**
 * Read from $sock until $stop($buf) returns true, nothing more arrives for
 * $idle seconds after some data ($idle > 0), or $max seconds pass.
 * Returns [buffer, reason] with reason 'stop' | 'idle' | 'timeout' | 'closed'.
 */
function bpq_bbs_login_read($sock, callable $stop, float $max, float $idle, ?callable $log): array {
    $buf = ''; $start = microtime(true); $last = $start;
    stream_set_blocking($sock, false);
    $reason = 'timeout';
    while (microtime(true) - $start < $max) {
        $chunk = @fread($sock, 8192);
        if ($chunk !== false && $chunk !== '') {
            $buf .= $chunk; $last = microtime(true);
            if ($log) $log('  << ' . substr(trim(preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x1F\x7F]/', ' ', $chunk))), 0, 300));
            if ($stop($buf)) {
                // Let the rest of the line/prompt arrive (as waitFor() did).
                usleep(400000);
                $extra = @fread($sock, 8192);
                if ($extra !== false && $extra !== '') $buf .= $extra;
                $reason = 'stop';
                break;
            }
            continue;
        }
        if (feof($sock)) { $reason = 'closed'; break; }
        if ($idle > 0 && $buf !== '' && microtime(true) - $last >= $idle) { $reason = 'idle'; break; }
        usleep(50000);
    }
    stream_set_blocking($sock, true);
    return [$buf, $reason];
}

/**
 * Log in and stop at the BBS prompt.
 *
 * @param resource $sock  connected socket (fsockopen / stream_socket_client)
 * @param array    $cfg   ['user' => ..., 'pass' => ..., 'alias' => 'bbs']
 * @param callable|null $log  optional logger, gets one string per line
 * @return string what the BBS sent up to and including its prompt
 * @throws Exception with a message that says which step failed and the last line received
 */
function bpq_bbs_login($sock, array $cfg, ?callable $log = null): string {
    $user  = (string)($cfg['user']  ?? '');
    $pass  = (string)($cfg['pass']  ?? '');
    $alias = trim((string)($cfg['alias'] ?? 'bbs')) ?: 'bbs';
    $send  = function (string $line, bool $secret = false) use ($sock, $log) {
        fwrite($sock, $line . "\r\n");
        fflush($sock);
        if ($log) $log($secret ? '  >> (password)' : '  >> ' . $line);   // never log the password
        usleep(100000);
    };

    // 1. user:
    [$buf] = bpq_bbs_login_read($sock, fn($b) => stripos($b, 'user:') !== false, 10, 0, $log);
    if (stripos($buf, 'user:') === false) {
        throw new Exception('No user prompt (last: ' . bpq_bbs_login_tail($buf) . ')');
    }
    $send($user);

    // 2. password:
    [$buf] = bpq_bbs_login_read($sock, fn($b) => stripos($b, 'password:') !== false, 10, 0, $log);
    if (stripos($buf, 'password:') === false) {
        throw new Exception('No password prompt (last: ' . bpq_bbs_login_tail($buf) . ')');
    }
    $send($pass, true);

    // 3. Greeting. Stop early on: the node prompt '}', the BBS prompt, or a
    //    refusal. Otherwise the greeting is over once BPQ goes quiet.
    $failRe = '/\b(sorry|invalid|incorrect|bad password)\b|user:|password:/i';
    [$buf, $why] = bpq_bbs_login_read($sock, function ($b) use ($failRe) {
        $c = bpq_bbs_login_clean($b);
        return strpos($c, '}') !== false || bpq_bbs_login_at_bbs($c) || preg_match($failRe, $c);
    }, 20, 1.5, $log);
    $clean = bpq_bbs_login_clean($buf);

    if (preg_match($failRe, $clean)) {
        throw new Exception('Login refused: check bbs.user / bbs.pass in config.php against the USER line in bpq32.cfg (last: ' . bpq_bbs_login_tail($buf) . ')');
    }
    if ($why === 'closed') {
        throw new Exception('BPQ closed the connection after the password (last: ' . bpq_bbs_login_tail($buf) . ')');
    }
    if (trim($clean) === '') {
        throw new Exception('Login failed: nothing came back after the password within 20 s');
    }

    // 4a. Already in the BBS (USER line's application is BBS).
    if (bpq_bbs_login_at_bbs($clean)) {
        if ($log) $log('  (login landed in the BBS)');
        return $buf;
    }

    // 4b. At the node: connect to the BBS application.
    $send($alias);
    [$buf2] = bpq_bbs_login_read($sock, fn($b) => bpq_bbs_login_at_bbs(bpq_bbs_login_clean($b))
                                                  || strpos($b, '>') !== false, 15, 0, $log);
    if (strpos($buf2, '>') === false) {
        throw new Exception("No BBS prompt after '$alias' (last: " . bpq_bbs_login_tail($buf2) . ')');
    }
    return $buf2;
}

}
