#!/usr/bin/env php
<?php
/**
 * fetch-message-bodies.php — save message text to the database, server-side
 * (2026-10-12)
 *
 * The Email Client used to be the only thing that read message text from
 * BPQ: only while a browser tab was open, only when "Body fetching" was
 * Eager or a message was opened, and only if its saves got past nginx's
 * rate limit. On K1AJD about 1% of bulletins ever had their text saved,
 * and BPQ removed them within days, so the text was lost. This script runs
 * from a systemd timer (message-bodies.timer, every 5 minutes) and:
 *
 *   1. logs in to the BBS once and lists the newest Personal (LP), NTS (LT)
 *      and Bulletins (LB), up to 200 each;
 *   2. archives those listings into bpq_messages (the same INSERT IGNORE +
 *      bulletin-group filing the page's Get Mail uses);
 *   3. reads (R <n>) every listed message whose saved copy has no text yet
 *      and stores the text — only when BPQ's From:/Title: header matches
 *      the saved row, because BPQ reuses message numbers. "Message N not
 *      found" replies are never stored;
 *   4. clears old "Message N not found" replies that earlier versions of
 *      the page saved as if they were message text.
 *
 * Bulletins: config.php 'messages' => ['bulletin_text' => 'all'|'pinned'|'none']
 * (default 'all'; 'pinned' = only groups pinned 📌 in the Email Client).
 * Personal mail and NTS traffic are always fetched.
 *
 * Every BBS session takes the same lock as the Email Client
 * (bpqTelnetLock() in bbs-messages.php), sessions are kept short (at most
 * 20 reads / 25 s) so the page never waits long. A message BPQ answers
 * "not found" for, or whose number now holds another message, is not asked
 * for again; one that failed with an error gets 3 tries
 * (cache/message-bodies-state.json, entries kept 14 days; --retry-failed
 * ignores it).
 *
 * RUN MANUALLY (as www-data):
 *   php fetch-message-bodies.php                 # normal run (what the timer does)
 *   php fetch-message-bodies.php --dry-run       # list + report, read/write nothing
 *   php fetch-message-bodies.php --backfill      # also try EVERY saved message without
 *                                                #   text, newest first (one-off catch-up)
 *   php fetch-message-bodies.php --backfill --days=30 --limit=500
 *   php fetch-message-bodies.php --verbose       # one line per message
 *
 * Log: logs/message-bodies.log (login dialogue + per-message results; the
 * web server refuses /logs/*.log). Summary line on stdout → journalctl.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is for command-line/systemd use only.');
}

// ─── Args ────────────────────────────────────────────────────────────
$opt = ['dry' => false, 'backfill' => false, 'verbose' => false, 'days' => 0,
        'limit' => null, 'bulletins' => null, 'retry' => false];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run')       $opt['dry'] = true;
    elseif ($a === '--backfill')  $opt['backfill'] = true;
    elseif ($a === '--verbose' || $a === '-v') $opt['verbose'] = true;
    elseif ($a === '--retry-failed') $opt['retry'] = true;
    elseif (preg_match('/^--days=(\d+)$/', $a, $m))  $opt['days'] = (int)$m[1];
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m)) $opt['limit'] = max(1, (int)$m[1]);
    elseif (preg_match('/^--bulletins=(all|pinned|none)$/', $a, $m)) $opt['bulletins'] = $m[1];
    elseif ($a === '--help' || $a === '-h') {
        echo "Usage: php fetch-message-bodies.php [--dry-run] [--backfill [--days=N]] [--limit=N]\n" .
             "       [--bulletins=all|pinned|none] [--retry-failed] [--verbose]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: $a (try --help)\n");
        exit(2);
    }
}

// ─── Load the dashboard's BBS helpers + DB layer ────────────────────
$webRoot = dirname(__DIR__);
chdir($webRoot);
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';   // bootstrap.php reads these
$_SERVER['PHP_SELF']       = $_SERVER['PHP_SELF'] ?? '/scripts/fetch-message-bodies.php';
define('BPQ_MESSAGES_LIB', true);
require $webRoot . '/bbs-messages.php';     // $config, sendCmd, waitFor, parseMessageList, bpqTelnetLock …
require_once $webRoot . '/message-db.php';  // tprfn_db(), msgdb_archive_messages(), msgdb_get_keep_folders()

$config['log_file'] = $webRoot . '/logs/message-bodies.log';
$bulletinPolicy = $opt['bulletins'] ?? getConfig('messages', 'bulletin_text', 'all');
if (!in_array($bulletinPolicy, ['all', 'pinned', 'none'], true)) $bulletinPolicy = 'all';
$listCount = 200;
$limit     = $opt['limit'] ?? ($opt['backfill'] ? 5000 : 400);
$stateFile = $webRoot . '/cache/message-bodies-state.json';

const SESSION_MAX_READS   = 20;
const SESSION_MAX_SECONDS = 25;
const MAX_FAILURES        = 3;
const LOCK_WAIT_SECONDS   = 150;

$stats = ['listed' => ['P' => 0, 'T' => 0, 'B' => 0], 'archived' => 0, 'candidates' => 0,
          'saved' => 0, 'not_found' => 0, 'other_msg' => 0, 'skipped_failed' => 0,
          'errors' => 0, 'junk_cleared' => 0];

function out($line) {
    echo $line, "\n";
    logMessage($line);
}
function vlog($line) {
    global $opt;
    logMessage($line);
    if ($opt['verbose']) echo $line, "\n";
}

// ─── Telnet session helpers ─────────────────────────────────────────

/** Log in to the BBS (same dialogue as fetchMessageBody()). Returns socket. */
function bbsOpen() {
    global $config;
    $sock = @stream_socket_client("tcp://{$config['bbs_host']}:{$config['bbs_port']}",
                                  $errno, $errstr, (int)$config['timeout']);
    if (!$sock) throw new Exception("Cannot connect to BBS: $errstr");
    stream_set_timeout($sock, (int)$config['timeout']);
    $r = waitFor($sock, 'user:', 10);
    if (strpos($r, 'user:') === false) { @fclose($sock); throw new Exception('No user prompt'); }
    sendCmd($sock, $config['bbs_user']);
    $r = waitFor($sock, 'password:', 10);
    if (strpos($r, 'password:') === false) { @fclose($sock); throw new Exception('No password prompt'); }
    sendCmd($sock, $config['bbs_pass']);
    $r = waitFor($sock, '}', 20);
    if (strpos($r, '}') === false) { @fclose($sock); throw new Exception('Login failed'); }
    sendCmd($sock, $config['bbs_alias']);
    $r = bbsReadUntilPrompt($sock, 15);
    if (!$r['prompt']) { @fclose($sock); throw new Exception('No BBS prompt'); }
    return $sock;
}

function bbsClose($sock) {
    if (!is_resource($sock)) return;
    @fwrite($sock, "B\r\n");
    usleep(500000);
    @fclose($sock);
}

/**
 * Read until the BBS prompt ("de CALL>") ends the output and nothing more
 * arrives for 0.4 s. waitFor('>') stops at the first '>' anywhere, which
 * can be inside a message body. Answers BPQ's page prompt
 * ("<A>bort, <R Msg(s)>, <CR> = Continue..>") with CR.
 */
function bbsReadUntilPrompt($sock, $timeout = 30) {
    $buf = '';
    $start = microtime(true);
    $lastData = $start;
    $seenPromptAt = null;
    stream_set_blocking($sock, false);
    while (microtime(true) - $start < $timeout) {
        $chunk = @fread($sock, 8192);
        if ($chunk !== false && $chunk !== '') {
            $buf .= $chunk;
            $lastData = microtime(true);
            $seenPromptAt = null;
            $tail = substr($buf, -200);
            if (preg_match('/<CR>[^\n]*>\s*$/i', $tail) || preg_match('/continue[^\n]*>\s*$/i', $tail)) {
                fwrite($sock, "\r\n");
                continue;
            }
            if (preg_match('/\bde [A-Z0-9\/-]+>\s*$/i', $tail)) $seenPromptAt = microtime(true);
        } else {
            if ($seenPromptAt !== null && microtime(true) - $seenPromptAt >= 0.4) {
                stream_set_blocking($sock, true);
                return ['text' => $buf, 'prompt' => true];
            }
            usleep(50000);
        }
    }
    stream_set_blocking($sock, true);
    return ['text' => $buf, 'prompt' => $seenPromptAt !== null];
}

function bbsCommand($sock, $cmd, $timeout = 30) {
    sendCmd($sock, $cmd);
    return bbsReadUntilPrompt($sock, $timeout);
}

/** Acquire the shared lock or throw. */
function lockOrThrow() {
    $h = bpqTelnetLock(LOCK_WAIT_SECONDS);
    if ($h === false) throw new Exception('BBS lock busy for ' . LOCK_WAIT_SECONDS . ' s');
    return $h;
}

// ─── Matching ───────────────────────────────────────────────────────

function normSubject($s) { return strtoupper(preg_replace('/\s+/', ' ', trim((string)$s))); }
function baseCall($s)    { return strtoupper(explode('@', trim((string)$s))[0]); }

function subjectsMatch($a, $b) {
    $a = normSubject($a); $b = normSubject($b);
    if ($a === '' || $b === '') return true;
    return strpos($a, $b) === 0 || strpos($b, $a) === 0;
}

/** From:/Title: from the header BPQ puts on an R <n> reply, or null. */
function parseReadHeader($text) {
    $from = null; $title = null;
    foreach (array_slice(preg_split('/\r?\n|\r/', (string)$text), 0, 25) as $line) {
        if ($from === null && preg_match('/^[\s>|]*from:\s*(\S+)/i', $line, $m)) $from = $m[1];
        if ($title === null && preg_match('/^[\s>|]*title:\s*(.*)$/i', $line, $m)) $title = trim($m[1]);
    }
    return ($from === null && $title === null) ? null : ['from' => $from, 'title' => $title];
}

/** 'ok' | 'not_found' | 'other' for a read reply against the saved row. */
function judgeReply($text, $row) {
    if (preg_match('/^\s*Message\s+\d+\s+not found/i', (string)$text)) return 'not_found';
    $h = parseReadHeader($text);
    if ($h === null) return 'not_found';
    if ($h['from'] !== null && $row['from_call'] !== '' && baseCall($h['from']) !== baseCall($row['from_call'])) return 'other';
    if ($h['title'] !== null && !subjectsMatch($h['title'], $row['subject'])) return 'other';
    return 'ok';
}

/**
 * The body column is utf8mb4, but BBS text is often not UTF-8: 7plus-encoded
 * files use CP437 bytes (░▒▓█ = B0 B1 B2 DB, live 2026-10-12), older
 * bulletins Latin-1/Windows-1252. MariaDB refused those ("Incorrect string
 * value"). Valid UTF-8 is kept as is; 7plus text is converted from CP437
 * (byte-for-byte reversible); anything else from Windows-1252.
 */
function toUtf8($t) {
    $t = (string)$t;
    if (preg_match('//u', $t)) return $t;
    $from = preg_match('/go_7\+\./i', $t) ? 'CP437' : 'CP1252';
    if (function_exists('iconv')) {
        $r = @iconv($from, 'UTF-8//IGNORE', $t);
        if ($r !== false && preg_match('//u', $r)) return $r;
    }
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
    return preg_replace('/[\x80-\xFF]/', '?', $t);
}

// ─── Failure memory ─────────────────────────────────────────────────

function loadState($file) {
    $s = @json_decode((string)@file_get_contents($file), true);
    if (!is_array($s)) $s = [];
    $cut = time() - 14 * 86400;
    return array_filter($s, fn($e) => is_array($e) && ($e['t'] ?? 0) >= $cut);
}
function saveState($file, $s) {
    @file_put_contents($file, json_encode($s), LOCK_EX);
}
function rowKey($row) {
    return $row['bbs_number'] . '|' . $row['msg_type'] . '|' . strtoupper($row['from_call']) . '|' . normSubject($row['subject']);
}

// ═══════════════════════════════════════════════════════════════════
$t0 = microtime(true);
logMessage('=== fetch-message-bodies start' . ($opt['backfill'] ? ' (backfill)' : '') . ($opt['dry'] ? ' (dry run)' : '') .
           " bulletins=$bulletinPolicy ===");
try {
    $pdo = tprfn_db();
} catch (Throwable $e) {
    out('ERROR: database: ' . $e->getMessage());
    exit(1);
}

// 0. Clear "Message N not found" replies saved as text by older page versions.
if (!$opt['dry']) {
    try {
        $stats['junk_cleared'] = $pdo->exec("UPDATE bpq_messages SET body = ''
            WHERE body REGEXP '^[[:space:]]*Message [0-9]+ not found' AND LENGTH(body) < 200");
    } catch (Throwable $e) {
        out('WARNING: junk clean-up failed: ' . $e->getMessage());
    }
}

// 1. Listing (one session).
$listed = [];   // "number|type|FROM" => listing row
try {
    $lock = lockOrThrow();
    try {
        $sock = bbsOpen();
        try {
            foreach (['P' => 'LP', 'T' => 'LT', 'B' => 'LB'] as $kind => $cmd) {
                $r = bbsCommand($sock, $cmd, $kind === 'B' ? 60 : 30);
                if (!$r['prompt']) out("WARNING: $cmd listing did not finish (timeout); using what arrived");
                $msgs = array_slice(parseMessageList($r['text']), 0, $listCount);
                foreach ($msgs as $m) {
                    $type = strtoupper(substr((string)$m['status'], 0, 1));
                    if (!in_array($type, ['P', 'T', 'B'], true)) $type = $kind;
                    $m['type'] = $type;
                    $listed[$m['number'] . '|' . $type . '|' . strtoupper($m['from'])] = $m;
                }
                $stats['listed'][$kind] = count($msgs);
            }
        } finally {
            bbsClose($sock);
        }
    } finally {
        bpqTelnetUnlock($lock);
    }
} catch (Throwable $e) {
    out('ERROR: listing: ' . $e->getMessage());
    if (!$opt['backfill']) exit(1);
}

// 2. Archive the listings (INSERT IGNORE; files bulletins into existing group folders).
if (!$opt['dry'] && $listed) {
    $rows = [];
    foreach ($listed as $m) {
        $rows[] = ['number' => $m['number'], 'date' => $m['date'], 'type' => $m['type'],
                   'size' => $m['size'], 'from' => $m['from'], 'to' => $m['to'],
                   'subject' => $m['subject'], 'body' => ''];
    }
    try {
        foreach (array_chunk($rows, 200) as $chunk) {
            $r = msgdb_archive_messages($chunk);
            $stats['archived'] += (int)($r['archived'] ?? 0);
        }
    } catch (Throwable $e) {
        out('WARNING: archiving listings failed: ' . $e->getMessage());
    }
}

// 3. Which saved rows need text.
$keep = [];
try { $keep = array_flip(msgdb_get_keep_folders()); } catch (Throwable $e) { /* pre-migration: none pinned */ }

$wantType = function ($row) use ($bulletinPolicy, $keep) {
    if ($row['msg_type'] !== 'B') return true;
    if ($bulletinPolicy === 'all')  return true;
    if ($bulletinPolicy === 'none') return false;
    $folder = $row['folder_name'] ?: ('Bulletins - ' . strtoupper(trim((string)$row['to_call'])));
    return isset($keep[$folder]);
};

$sqlBase = "SELECT m.id, m.bbs_number, m.msg_type, m.from_call, m.to_call, m.subject, f.name AS folder_name
            FROM bpq_messages m LEFT JOIN bpq_msg_folders f ON f.id = m.folder_id
            WHERE m.is_archived = 1 AND (m.body IS NULL OR m.body = '')";
$candidates = [];
try {
    if ($opt['backfill']) {
        $sql = $sqlBase . " AND m.bbs_number REGEXP '^[0-9]+$'";
        if ($opt['days'] > 0) $sql .= ' AND m.first_seen_at >= (NOW() - INTERVAL ' . (int)$opt['days'] . ' DAY)';
        $sql .= ' ORDER BY m.first_seen_at DESC, m.id DESC';
        foreach (tprfn_query($pdo, $sql) as $row) {
            if ($wantType($row)) $candidates[] = $row;
        }
    }
    if ($listed) {
        // Listed messages first: their numbers are certainly current.
        $nums = array_values(array_unique(array_map(fn($m) => (string)$m['number'], $listed)));
        $first = [];
        foreach (array_chunk($nums, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (tprfn_query($pdo, $sqlBase . " AND m.bbs_number IN ($in)", $chunk) as $row) {
                $key = $row['bbs_number'] . '|' . $row['msg_type'] . '|' . strtoupper($row['from_call']);
                if (!isset($listed[$key])) continue;
                // Same number+type+sender but a different subject = an old row whose
                // number BPQ has reused; leave it alone.
                if (!subjectsMatch($listed[$key]['subject'], $row['subject'])) continue;
                if ($wantType($row)) $first[$row['id']] = $row;
            }
        }
        $rest = array_filter($candidates, fn($r) => !isset($first[$r['id']]));
        $candidates = array_merge(array_values($first), array_values($rest));
    }
} catch (Throwable $e) {
    out('ERROR: database query: ' . $e->getMessage());
    exit(1);
}

$state = loadState($stateFile);
if (!$opt['retry']) {
    $before = count($candidates);
    $candidates = array_values(array_filter($candidates,
        fn($r) => ($state[rowKey($r)]['n'] ?? 0) < MAX_FAILURES));
    $stats['skipped_failed'] = $before - count($candidates);
}
$candidates = array_slice($candidates, 0, $limit);
$stats['candidates'] = count($candidates);

// 4. Read text, in short sessions.
if (!$opt['dry'] && $candidates) {
    $upd = $pdo->prepare("UPDATE bpq_messages SET body = ? WHERE id = ? AND (body IS NULL OR body = '')");
    $queue = $candidates;
    $consecutiveErrors = 0;
    while ($queue && $consecutiveErrors < 3) {
        try {
            $lock = lockOrThrow();
            try {
                $sock = bbsOpen();
                $sessStart = microtime(true);
                $n = 0;
                try {
                    while ($queue && $n < SESSION_MAX_READS && microtime(true) - $sessStart < SESSION_MAX_SECONDS) {
                        $row = array_shift($queue);
                        $n++;
                        $r = bbsCommand($sock, 'R ' . (int)$row['bbs_number'], 30);
                        $text = $r['text'];
                        $verdict = $r['prompt'] ? judgeReply($text, $row) : 'error';
                        $label = "#{$row['bbs_number']} {$row['msg_type']} {$row['from_call']} \"" . substr($row['subject'], 0, 40) . '"';
                        if ($verdict === 'ok') {
                            // Drop BPQ's page prompts (answered in bbsReadUntilPrompt()).
                            $text = preg_replace('/<A>bort,[^\r\n]*?Continue\.*>/i', '', $text);
                            if (strlen($text) > 1048576) $text = substr($text, 0, 1048576);
                            $text = toUtf8($text);
                            try {
                                $upd->execute([$text, $row['id']]);
                                $stats['saved']++;
                                unset($state[rowKey($row)]);
                                vlog("  saved $label (" . strlen($text) . ' chars)');
                            } catch (Throwable $e) {
                                // One message the database refuses must not end the
                                // session for all the others.
                                $k = rowKey($row);
                                $state[$k] = ['n' => ($state[$k]['n'] ?? 0) + 1, 't' => time(), 'why' => 'db'];
                                $stats['errors']++;
                                out("WARNING: could not save $label: " . $e->getMessage());
                            }
                        } else {
                            $k = rowKey($row);
                            // Gone from BPQ / number now another message: final, don't ask
                            // again. Errors (timeouts etc.) get MAX_FAILURES tries.
                            $n2 = in_array($verdict, ['not_found', 'other'], true)
                                ? MAX_FAILURES : ($state[$k]['n'] ?? 0) + 1;
                            $state[$k] = ['n' => $n2, 't' => time(), 'why' => $verdict];
                            if ($verdict === 'not_found') $stats['not_found']++;
                            elseif ($verdict === 'other') $stats['other_msg']++;
                            else $stats['errors']++;
                            vlog("  $verdict $label");
                        }
                    }
                } finally {
                    bbsClose($sock);
                }
            } finally {
                bpqTelnetUnlock($lock);
            }
            $consecutiveErrors = 0;
        } catch (Throwable $e) {
            $consecutiveErrors++;
            $stats['errors']++;
            out('WARNING: read session: ' . $e->getMessage());
            sleep(3);
        }
        usleep(300000);   // let a waiting page request take the lock
    }
    saveState($stateFile, $state);
}

$s = $stats;
out(sprintf('Done in %ds: listed P:%d T:%d B:%d, %d new archived, %d to read, text saved %d, ' .
            'not on BBS %d, number reused %d, skipped (gone or failed before) %d, errors %d, "not found" junk cleared %d%s',
    (int)round(microtime(true) - $t0), $s['listed']['P'], $s['listed']['T'], $s['listed']['B'], $s['archived'],
    $s['candidates'], $s['saved'], $s['not_found'], $s['other_msg'], $s['skipped_failed'],
    $s['errors'], $s['junk_cleared'], $opt['dry'] ? ' (DRY RUN: nothing read or written)' : ''));
exit($s['errors'] > 0 && $s['saved'] === 0 && $s['candidates'] > 0 ? 1 : 0);
