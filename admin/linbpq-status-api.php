<?php
/**
 * Admin > LinBPQ Status (v1.5.94, requested by N3MEL)
 *
 * GET linbpq-status-api.php              status, starts/stops, crashes, console
 * GET linbpq-status-api.php?view=console console only (the page polls this)
 *
 * Everything comes from the read-only root helper
 * /usr/local/sbin/bpq-dashboard-linbpq-status (tools/linbpq-status-helper),
 * run with `sudo -n` and either no argument or "console"
 * (www-data-tools.sudoers). The web server can't read the system journal,
 * the kernel log or another user's screen session itself.
 * Nothing here sends anything to LinBPQ.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('LinBPQ Status');

const HELPER = '/usr/local/sbin/bpq-dashboard-linbpq-status';
$view = (($_GET['view'] ?? '') === 'console') ? 'console' : 'all';

// Tests only: from the command line, LINBPQ_STATUS_TEST_JSON stands in for the helper.
$test = (PHP_SAPI === 'cli' && getenv('LINBPQ_STATUS_TEST_JSON')) ? (string)getenv('LINBPQ_STATUS_TEST_JSON') : '';
if ($test !== '') {
    $raw = (string)@file_get_contents($test);
} elseif (!is_file(HELPER)) {
    echo json_encode(['success' => false, 'helper' => 'missing',
        'error' => 'The read-only helper ' . HELPER . " isn't installed. Run the update again "
                 . '(sudo bash update.sh), answering yes to the admin pages\' sudo rules, or see Installation Manual §11.']);
    exit;
} else {
    $raw = (string)shell_exec('sudo -n ' . HELPER . ($view === 'console' ? ' console' : '') . ' 2>&1');
}
$d = json_decode($raw, true);
if (!is_array($d)) {
    $refused = (bool)preg_match('/password is required|not allowed|may not run sudo|^sudo:/m', $raw);
    echo json_encode(['success' => false, 'helper' => $refused ? 'sudo_refused' : 'failed',
        'error' => $refused
            ? "The web server isn't allowed to run the helper: the sudo rule for " . HELPER . ' is missing. Run the update again and answer yes to the admin pages\' sudo rules.'
            : 'The helper gave no answer: ' . mb_substr(trim($raw), 0, 200)]);
    exit;
}

// ── Console: flag the lines worth seeing ────────────────────────────────────
// "VARA Thread Terminated Port N" is LinBPQ closing a modem port as it stops: normal, not an error.
$ERR = '/(?i)\b(error|failed|failure|segfault|core dump|cannot|can\'t|unable|refused|not found|timeout|timed out)\b/';
$STOP = '/(?i)thread terminated port|stopping|stopped/';
$OK  = '/(?i)\b(connected to|started|initialising port|mail started|chat started|config processed)\b/';
$console = [];
foreach ((array)($d['console'] ?? []) as $l) {
    $l = (string)$l;
    $console[] = ['t' => $l, 'k' => preg_match($ERR, $l) ? 'err' : (preg_match($STOP, $l) ? 'stop' : (preg_match($OK, $l) ? 'ok' : ''))];
}
$out = ['success' => true, 'view' => $view, 'checked' => $d['checked'] ?? null, 'process' => $d['process'] ?? null,
        'how' => $d['how'] ?? null, 'console' => $console, 'console_error' => $d['console_error'] ?? null];
if ($view === 'console') { echo json_encode($out); exit; }

// ── History: one list of events, newest first ───────────────────────────────
$events = [];
$ts = function (string $line): ?string {
    if (preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)([+-]\d{4}|Z)?/', $line, $m)) {
        $t = strtotime($m[1] . ($m[2] ?? '') );
        return $t ? gmdate('Y-m-d H:i:s', $t) . 'Z' : null;
    }
    if (preg_match('/^\[(\w{3} \w{3}\s+\d+ \d\d:\d\d:\d\d \d{4})\]/', $line, $m)) {      // dmesg -T
        $t = strtotime($m[1]);
        return $t ? gmdate('Y-m-d H:i:s', $t) . 'Z' : null;
    }
    return null;
};
$SIG = ['SEGV' => 'segfault', 'ABRT' => 'abort', 'KILL' => 'killed', 'BUS' => 'bus error', 'ILL' => 'illegal instruction', 'FPE' => 'arithmetic error', 'TERM' => 'stopped (TERM)'];
foreach ((array)($d['unit_events'] ?? []) as $l) {
    $l = (string)$l; $t = $ts($l);
    $msg = preg_replace('/^.*?systemd\[\d+\]:\s*/', '', $l);
    if (preg_match('/Main process exited, code=(\w+), status=(\d+)(?:\/(\w+))?/', $l, $m)) {
        $sig = $m[3] ?? '';
        if ($m[1] === 'exited') {
            $events[] = ['at' => $t, 'kind' => $m[2] === '0' ? 'stop' : 'exit', 'what' => $m[2] === '0' ? 'LinBPQ exited normally' : "LinBPQ exited with error code {$m[2]}", 'line' => $msg];
        } elseif ($sig === 'TERM' || $sig === 'INT' || $sig === 'HUP') {
            $events[] = ['at' => $t, 'kind' => 'stop', 'what' => "Stopped (signal $sig)", 'line' => $msg];
        } else {
            $events[] = ['at' => $t, 'kind' => 'crash', 'what' => 'Crashed: ' . ($SIG[$sig] ?? ($sig ?: $m[1])) . ($m[1] === 'dumped' ? ' (core dumped)' : ''), 'line' => $msg];
        }
    } elseif (preg_match('/Scheduled restart job, restart counter is at (\d+)/', $l, $m)) {
        $events[] = ['at' => $t, 'kind' => 'restart', 'what' => "Restarted automatically by systemd (#{$m[1]})", 'line' => $msg];
    } elseif (preg_match('/\bStarted\b/', $l)) {
        $events[] = ['at' => $t, 'kind' => 'start', 'what' => 'LinBPQ started', 'line' => $msg];
    } elseif (preg_match('/\bStopping\b/', $l)) {
        $events[] = ['at' => $t, 'kind' => 'stop', 'what' => 'Stop requested', 'line' => $msg];
    } elseif (preg_match('/Failed with result \'([^\']+)\'/', $l, $m)) {
        $events[] = ['at' => $t, 'kind' => 'crash', 'what' => "Service failed ({$m[1]})", 'line' => $msg];
    }
}
$segs = 0;
foreach ((array)($d['kernel_crashes'] ?? []) as $l) {
    $l = (string)$l; $segs++;
    $what = preg_match('/Out of memory|oom-kill/i', $l) ? 'Killed: the computer ran out of memory' : (preg_match('/general protection/i', $l) ? 'Crashed: general protection fault' : (preg_match('/segfault/i', $l) ? 'Crashed: segfault' : 'Crashed: trap'));
    $events[] = ['at' => $ts($l), 'kind' => 'crash', 'what' => $what . ' (kernel log)', 'line' => preg_replace('/^.*?kernel:\s*/', '', $l)];
}
$p = $d['process'] ?? null;
if ($p && !empty($p['started'])) {
    $at = gmdate('Y-m-d H:i:s', strtotime($p['started'])) . 'Z';
    $have = false;
    foreach ($events as $e) if ($e['kind'] === 'start' && $e['at'] && abs(strtotime($e['at']) - strtotime($at)) < 10) $have = true;
    if (!$have) $events[] = ['at' => $at, 'kind' => 'start', 'what' => 'Current LinBPQ started', 'line' => 'pid ' . $p['pid']];
}
foreach ((array)($d['boots'] ?? []) as $l) {
    // " -1 abc123 Sun 2026-11-15 09:40:01 UTC Mon 2026-11-16 08:00:00 UTC"
    if (preg_match('/^\s*(-?\d+)\s+\S+\s+\w{3}\s+(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\s+(\S+)/', (string)$l, $m)) {
        $t = strtotime("$m[2] $m[3]");
        if ($t && $t > time() - 30 * 86400) $events[] = ['at' => gmdate('Y-m-d H:i:s', $t) . 'Z', 'kind' => 'boot', 'what' => 'Computer started', 'line' => ''];
    }
}
usort($events, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
// One crash shows up as several records (systemd's exit line, its "failed"
// line, the kernel's segfault line): merge crash records within 10 s.
$merged = [];
foreach ($events as $e) {
    $last = count($merged) - 1;
    if ($e['kind'] === 'crash' && $last >= 0 && $merged[$last]['kind'] === 'crash' && $e['at'] && $merged[$last]['at']
        && abs(strtotime($merged[$last]['at']) - strtotime($e['at'])) <= 10) {
        if (str_starts_with($merged[$last]['what'], 'Service failed')) $merged[$last]['what'] = $e['what'];
        $merged[$last]['line'] .= "\n" . $e['line'];
        continue;
    }
    $merged[] = $e;
}
$events = $merged;

$cnt = fn($k) => count(array_filter($events, fn($e) => $e['kind'] === $k));
$out['summary'] = ['starts' => $cnt('start'), 'crashes' => $cnt('crash'), 'auto_restarts' => $cnt('restart'),
                   'segfaults_kernel' => $segs, 'coredumps' => count((array)($d['coredumps'] ?? [])),
                   'unit_restarts' => isset($d['unit']['NRestarts']) ? (int)$d['unit']['NRestarts'] : null];
$out['events'] = array_slice($events, 0, 300);
$out['unit'] = $d['unit'] ?? null;
$out['coredumps'] = (array)($d['coredumps'] ?? []);
$notes = [];
$kind = $d['how']['kind'] ?? null;
if (!$p) $notes[] = ['lvl' => 'warn', 'text' => 'LinBPQ is not running.'];
if ($kind === 'screen') $notes[] = ['lvl' => 'info', 'text' => 'LinBPQ runs in screen, so only crashes (kernel log), the current start and the computer\'s starts are known; clean stops and restarts made by hand leave no record. Running LinBPQ as a systemd service would record every start and stop, and restart it automatically after a crash.'];
if ($kind === 'other') $notes[] = ['lvl' => 'info', 'text' => 'LinBPQ isn\'t running under systemd or screen (parent: ' . ($d['how']['parent'] ?? '?') . '), so there is no console to copy and only crashes from the kernel log are shown.'];
if (empty($d['journal_persistent'])) $notes[] = ['lvl' => 'warn', 'text' => 'The system journal is not kept across restarts (no /var/log/journal), so history only goes back to the last boot. To keep it: sudo mkdir -p /var/log/journal && sudo systemctl restart systemd-journald'];
if ($kind === 'systemd' && isset($d['unit']['Restart']) && $d['unit']['Restart'] === 'no') $notes[] = ['lvl' => 'info', 'text' => "systemd won't restart LinBPQ after a crash (Restart=no in {$d['how']['unit']}). Restart=on-failure brings it back by itself."];
$out['notes'] = $notes;
echo json_encode($out);
