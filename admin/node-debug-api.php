<?php
/**
 * Admin > Node Debug (v1.5.95) — LinBPQ's node debug log, read and explained
 *
 * GET node-debug-api.php?action=summary&days=1|3|7
 *     starts, neighbour links, internet / Winlink CMS, Winlink channel reports,
 *     modem events and known problems, from NodeDebuglog_YYMMDD.log
 * GET node-debug-api.php?action=tail&offset=N
 *     today's log from byte N (first call: the last ~600 lines), each line
 *     classed for the live viewer; returns the new offset to poll with.
 *
 * The logs are in the LinBPQ log folder (config.php paths.logs, else
 * <web root>/logs). Times in them are UTC (hh:mm:ss, the date from the
 * file name). Lines LinBPQ writes while it shuts down (links dropped,
 * "called without semaphore") are told apart from real problems: anything
 * in the 30 seconds before a start counts as part of that restart.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/web-login.php';
bpq_require_web_login('Node Debug');

$cfg = function_exists('bpq_config_all') ? (array)bpq_config_all() : [];
$LOG_DIR = rtrim((string)($cfg['paths']['logs'] ?? ''), '/');
if ($LOG_DIR === '' || !is_dir($LOG_DIR)) $LOG_DIR = dirname(__DIR__) . '/logs';
if (getenv('NODE_DEBUG_LOG_DIR') && in_array(PHP_SAPI, ['cli', 'cli-server'], true)) $LOG_DIR = (string)getenv('NODE_DEBUG_LOG_DIR');   // tests
$OWN_CALL = strtoupper(preg_replace('/-\d+$/', '', (string)($cfg['station']['callsign'] ?? $cfg['callsign'] ?? '')));
if (getenv('NODE_DEBUG_CALL') && in_array(PHP_SAPI, ['cli', 'cli-server'], true)) $OWN_CALL = (string)getenv('NODE_DEBUG_CALL');
$TODAY = getenv('NODE_DEBUG_TODAY') && in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? (string)getenv('NODE_DEBUG_TODAY') : gmdate('ymd');

function out($d) { echo json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function file_for(string $dir, string $ymd): string { return "$dir/NodeDebuglog_$ymd.log"; }
function mask(string $l): string {
    return preg_replace('/((?:pass(?:word|wd)?)\s*[=:]?\s*)\S+/i', '$1********', $l);
}

// ── Line classes (live viewer and summary) ──────────────────────────────────
const RX_CHATTER = '/^VARA Processing buffer - (?:(?:OK|BUSY O(?:N|FF)|IAMALIVE|PTT O(?:N|FF)|PENDING|CANCELPENDING|BUFFER \d+)\s*)+$|^BPQ32 Heartbeat Buffers/';
const RX_CRIT    = '/MISSING SOUNDCARD|Check CMS Failed|Resolve CMS Failed|Resolve root nameserver failed/i';
const RX_SHUT    = '/called without semaphore|Link to Neighbour .* lost|Delete Node for .* Called with active/';
const RX_START   = '/CTEXT Read for ports|Processing RIG line|PORTS 0x|Monitor Thread ID|Maint Clock/';
const RX_OK      = '/Database update ok|\bCONNECTED\b|KISS Session Stats|Calling Application|REGISTERED|Saving WP Database/';
const RX_ERR     = '/\berror\b|fail|\bcant\b|can\'t|refused|timed? ?out|dropped/i';

function classify(string $text, bool $shutdown): string {
    if (preg_match(RX_CRIT, $text))    return 'crit';
    if ($shutdown && preg_match(RX_SHUT, $text)) return 'stop';
    if (preg_match('/called without semaphore/', $text)) return 'stop';
    if (preg_match(RX_START, $text))   return 'start';
    if (preg_match(RX_ERR, $text))     return 'err';
    if (preg_match('/Link to Neighbour .* lost|Retrying L\d|Out Of Seq|Discarding repeated|deferred/', $text)) return 'warn';
    if (preg_match(RX_OK, $text))      return 'ok';
    if (preg_match(RX_CHATTER, $text)) return 'chatter';
    return '';
}

// ── Known problems: what they mean and what to do ──────────────────────────
const KNOWN = [
    'soundcard' => ['/MISSING SOUNDCARD/', 'crit', 'A modem lost its sound card',
        "A VARA-type modem (VARA, Mercury or Data2G) told LinBPQ its sound card is gone, usually because the radio's USB sound card dropped off and came back. The modem stays deaf until it is restarted.",
        'Restart the modem that uses that sound card (sudo systemctl restart data2g / mercury, or close and reopen VARA). System Audit shows which modems started before the USB came back.'],
    'cms' => ['/Check CMS Failed|Resolve CMS Failed/', 'crit', "Couldn't reach Winlink's servers (CMS)",
        "LinBPQ's regular check of the Winlink CMS servers failed: Winlink users calling your gateway can't get through while this lasts.",
        "Usually the internet connection or DNS. Check the router and that the machine can resolve names (ping api.winlink.org). When it happened is listed below and in the hourly grid."],
    'dns' => ['/Resolve root nameserver failed|Resolve Failed for/', 'warn', 'Name lookups failing (DNS)',
        "LinBPQ couldn't look up names on the internet (Winlink, node maps, updates).",
        'Short bursts are an internet hiccup. If it keeps happening, check the DNS servers the machine uses (resolvectl status).'],
    'chatlink' => ['/Chat link (\S+) Link Setup Failed/', 'warn', 'Chat link keeps failing',
        "LinBPQ's chat server can't set up its link to another chat node and keeps retrying.",
        'Ask that node whether its chat server still runs, or remove the link from chatconfig.cfg if it has gone.'],
    'axip' => ['/AXIP Packet from (\S+) dropped - can\'t reply/', 'info', 'AXIP packets from unknown addresses',
        "A packet arrived over AXIP from a station LinBPQ has no MAP entry (address) for yet, so it can't answer. Once per start is normal while links come up.",
        'Only worth a look if it keeps happening between restarts: check the MAP line for that station in bpq32.cfg.'],
    'forms' => ['/cant open forms dir/', 'info', "BPQ web forms folder missing",
        "BPQ's built-in web mail forms folder doesn't exist. Harmless unless you use BPQ's own web forms.",
        'To silence it, create that folder exactly as the line names it: everything between "dir " and the two numbers at the end (those are an error code, not part of the name), e.g. mkdir -p /path/to/linbpq/Standard_Templates, then chown it to the LinBPQ user. Copy the name from the line rather than typing it (it has an underscore, not a space); put it in quotes if it does contain a space. LinBPQ notices at its next start.'],
    'rtsptt' => ['/RigControl Opening\s+for RTS PTT/', 'info', 'Rig control start-up note (no serial PTT port)',
        "Logged once each time LinBPQ starts, right after it reads a RADIO block that talks to flrig or hamlib over the network: there is no serial port to open for RTS keying, so the name is blank. Keying goes through flrig / hamlib (PTT CAT) and works.",
        'Nothing to fix: it is not an error. (Removing RTS / DTR from the modem ports\' ADDR lines does not stop it; it comes from the RADIO block.)'],
    'tcpsend' => ['/TCP Send Failed/', 'info', 'Internet link send failed and was retried',
        'A send on a telnet / AXIP / internet link failed and LinBPQ queued the data again.', 'Occasional ones are normal.'],
    'deferred' => ['/Message \d+ deferred/', 'info', 'Forwarding offers deferred',
        'A partner answered a forwarding offer with "defer" (it is receiving that message from someone else); it is offered again later.', 'Normal.'],
    'retry' => ['/Retrying L\d Connect Request/', 'info', 'Link connect retried',
        'LinBPQ is retrying a link connect that got no answer.', 'Normal now and then; many in a row point at that neighbour or the path.'],
];

// ── Summary ─────────────────────────────────────────────────────────────────
function summary(string $dir, string $today, int $days): array {
    $t0 = strtotime('20' . substr($today, 0, 2) . '-' . substr($today, 2, 2) . '-' . substr($today, 4, 2) . ' 00:00:00 UTC');
    $files = []; $all = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ymd = gmdate('ymd', $t0 - $i * 86400);
        $f = file_for($dir, $ymd);
        if (!is_readable($f)) { $files[] = ['date' => $ymd, 'present' => false]; continue; }
        $base = $t0 - $i * 86400; $n = 0;
        $fh = fopen($f, 'rb');
        while (($l = fgets($fh)) !== false) {
            if (!preg_match('/^(\d\d):(\d\d):(\d\d) (.*)$/', rtrim($l, "\r\n"), $m)) continue;
            $all[] = [$base + $m[1] * 3600 + $m[2] * 60 + $m[3], $m[4]]; $n++;
        }
        fclose($fh);
        $files[] = ['date' => $ymd, 'present' => true, 'size' => filesize($f), 'lines' => $n, 'mtime' => filemtime($f)];
    }
    $starts = []; $startInfo = [];
    foreach ($all as $i => [$ts, $tx]) {
        if (strpos($tx, 'CTEXT Read for ports') === false) continue;
        $starts[] = $ts;
        // what the log shows of the way down: LinBPQ's shutdown lines (a stop it
        // was told to do) or none (it ended abruptly), and the last lines written
        $clean = false; $before = []; $prev = null;
        for ($j = $i - 1; $j >= 0 && $ts - $all[$j][0] <= 900; $j--) {
            [$t2, $x2] = $all[$j];
            if (strpos($x2, 'CTEXT Read for ports') !== false) break;
            if ($prev === null && ($ts - $t2 <= 3 || preg_match(RX_START, $x2) || stripos($x2, 'Resolving') === 0)) continue;   // its own start-up lines
            if ($prev === null) $prev = $t2;
            if ($ts - $t2 <= 30 && preg_match(RX_SHUT, $x2)) $clean = true;
            if (count($before) < 14 && !preg_match(RX_CHATTER, $x2)) array_unshift($before, gmdate('H:i:s', $t2) . ' ' . mask($x2));
        }
        $startInfo[] = ['at' => $ts, 'clean' => $clean, 'stopped_at' => $prev, 'down_s' => $prev !== null ? $ts - $prev : null,
                        'slot' => ((int)gmdate('i', $ts) % 5 === 0 && (int)gmdate('s', $ts) < 40), 'before' => $before];
    }
    $shut = function (int $ts) use ($starts): bool { foreach ($starts as $s) if ($s - $ts >= 0 && $s - $ts <= 30) return true; return false; };

    $dayIdx = fn(int $ts) => (int)floor(($ts - ($t0 - ($days - 1) * 86400)) / 86400);
    $per = array_fill(0, $days, ['starts' => 0, 'lost' => 0, 'cms_fail' => 0, 'dns_fail' => 0, 'soundcard' => 0, 'wl2k_ok' => 0, 'connects' => 0]);
    $nb = []; $wl = []; $pendingReport = null; $cmsFail = []; $dnsFail = []; $soundcard = []; $connects = [];
    $prob = []; $lastLine = null; $shutLines = 0; $chatter = 0;
    $hours = array_fill(0, $days * 24, ['cms_fail' => 0, 'dns_fail' => 0, 'lost' => 0]);
    $hourIdx = fn(int $ts) => (int)floor(($ts - ($t0 - ($days - 1) * 86400)) / 3600);
    foreach ($all as [$ts, $tx]) {
        $lastLine = $ts; $d = $dayIdx($ts); $h = $hourIdx($ts);
        if ($d < 0 || $d >= $days) continue;
        if (preg_match(RX_CHATTER, $tx)) { $chatter++; continue; }
        if (strpos($tx, 'CTEXT Read for ports') !== false) $per[$d]['starts']++;
        if (preg_match('/Link to Neighbour (\S+)\s+lost/', $tx, $m)) {
            $c = $m[1]; $nb[$c] = $nb[$c] ?? ['call' => $c, 'lost' => 0, 'lost_restart' => 0, 'sessions' => 0, 'sent' => 0, 'recv' => 0, 'secs' => 0, 'retries' => 0, 'last_lost' => null, 'last_session' => null, 'days' => array_fill(0, $days, 0)];
            if ($shut($ts)) { $nb[$c]['lost_restart']++; $shutLines++; }
            else { $nb[$c]['lost']++; $nb[$c]['last_lost'] = $ts; $nb[$c]['days'][$d]++; $per[$d]['lost']++; if ($h >= 0 && $h < count($hours)) $hours[$h]['lost']++; }
            continue;
        }
        if (preg_match('/called without semaphore/', $tx)) { $shutLines++; continue; }
        if (preg_match('/KISS Session Stats Port \d+ (\S+) (\S+) (\d+) secs Bytes Sent (\d+)\s+BPM [\d.]+ Bytes Received (\d+)/', $tx, $m)) {
            // one side is this node: the neighbour is the call that isn't ours
            $own = $GLOBALS['OWN_CALL'] ?? '';
            [$c, $other] = (strtoupper(preg_replace('/-\d+$/', '', $m[2])) === $own) ? [$m[1], $m[2]] : [$m[2], $m[1]];
            $nb[$c] = $nb[$c] ?? ['call' => $c, 'lost' => 0, 'lost_restart' => 0, 'sessions' => 0, 'sent' => 0, 'recv' => 0, 'secs' => 0, 'retries' => 0, 'last_lost' => null, 'last_session' => null, 'days' => array_fill(0, $days, 0)];
            $nb[$c]['sessions']++; $nb[$c]['secs'] += (int)$m[3]; $nb[$c]['sent'] += (int)$m[4]; $nb[$c]['recv'] += (int)$m[5]; $nb[$c]['last_session'] = $ts;
            $nb[$c]['_pair'] = $other;
            continue;
        }
        if (preg_match('/"Frequency":(\d+).*"Mode":(\d+).*"ServiceCode":"(\w+)"/', $tx, $m)) {
            $k = $m[3] . ' ' . $m[1];
            $wl[$k] = $wl[$k] ?? ['service' => $m[3], 'freq' => (int)$m[1], 'sent' => 0, 'ok' => 0, 'last_ok' => null];
            $wl[$k]['sent']++; $pendingReport = [$k, $ts];
            continue;
        }
        if (strpos($tx, 'WL2K Database update ok') !== false) {
            if ($pendingReport && $ts - $pendingReport[1] <= 60) { $wl[$pendingReport[0]]['ok']++; $wl[$pendingReport[0]]['last_ok'] = $ts; }
            $pendingReport = null; $per[$d]['wl2k_ok']++;
            continue;
        }
        if (preg_match('/Check CMS Failed|Resolve CMS Failed/', $tx)) { $cmsFail[] = $ts; $per[$d]['cms_fail']++; if ($h >= 0 && $h < count($hours)) $hours[$h]['cms_fail']++; }
        if (preg_match('/Resolve root nameserver failed|Resolve Failed for/', $tx)) { $dnsFail[] = $ts; $per[$d]['dns_fail']++; if ($h >= 0 && $h < count($hours)) $hours[$h]['dns_fail']++; }
        if (strpos($tx, 'MISSING SOUNDCARD') !== false) { $soundcard[] = $ts; $per[$d]['soundcard']++; }
        if (preg_match('/\bCONNECTED (\S+) (\S+)/', $tx, $m) && strpos($tx, 'DISCONNECTED') === false) {
            $last = end($connects);
            if (!$last || $last['at'] !== $ts || $last['from'] !== $m[1]) { $connects[] = ['at' => $ts, 'from' => $m[1], 'to' => $m[2]]; $per[$d]['connects']++; }   // VARA sometimes repeats the line
        }
        if (preg_match('/Retrying L\d Connect Request/', $tx)) { /* counted under problems */ }
        foreach (KNOWN as $key => [$rx, $lvl, $title, $what, $fix]) {
            if (preg_match($rx, $tx, $m)) {
                $p = &$prob[$key];
                if (!$p) $p = ['key' => $key, 'level' => $lvl, 'title' => $title, 'what' => $what, 'fix' => $fix, 'count' => 0, 'first' => $ts, 'last' => $ts, 'sample' => mask($tx), 'who' => []];
                $p['count']++; $p['last'] = $ts;
                if (isset($m[1])) $p['who'][$m[1]] = ($p['who'][$m[1]] ?? 0) + 1;
                unset($p);
                continue 2;
            }
        }
        if (preg_match(RX_ERR, $tx) && !preg_match('/Check CMS|Resolve/', $tx)) {
            $key = 'other:' . substr(preg_replace('/\d+/', 'N', $tx), 0, 70);
            $p = &$prob[$key];
            if (!$p) $p = ['key' => $key, 'level' => 'warn', 'title' => 'Other: ' . substr(mask($tx), 0, 80), 'what' => '', 'fix' => '', 'count' => 0, 'first' => $ts, 'last' => $ts, 'sample' => mask($tx), 'who' => []];
            $p['count']++; $p['last'] = $ts; unset($p);
        }
    }
    // outages: failures within 10 minutes of each other form one stretch
    $stretches = function (array $ts) {
        sort($ts); $out = [];
        foreach ($ts as $t) {
            $n = count($out);
            if ($n && $t - $out[$n - 1]['to'] <= 600) { $out[$n - 1]['to'] = $t; $out[$n - 1]['count']++; }
            else $out[] = ['from' => $t, 'to' => $t, 'count' => 1];
        }
        return array_values(array_filter($out, fn($s) => $s['count'] >= 3 || $s['to'] - $s['from'] >= 300));
    };
    foreach ($nb as &$n) { unset($n['_pair']); } unset($n);
    usort($nb, fn($a, $b) => ($b['lost'] <=> $a['lost']) ?: ($b['sessions'] <=> $a['sessions']));
    $wl = array_values($wl); usort($wl, fn($a, $b) => [$a['service'], $a['freq']] <=> [$b['service'], $b['freq']]);
    $rank = ['crit' => 0, 'err' => 1, 'warn' => 2, 'info' => 3];
    $prob = array_values($prob); usort($prob, fn($a, $b) => ($rank[$a['level']] <=> $rank[$b['level']]) ?: ($b['count'] <=> $a['count']));
    foreach ($prob as &$p) { arsort($p['who']); $p['who'] = (object)array_slice($p['who'], 0, 8, true); } unset($p);
    return [
        'success' => true, 'days' => $days, 'today' => $today, 'from' => $t0 - ($days - 1) * 86400, 'files' => $files,
        'last_line' => $lastLine, 'lines' => count($all), 'chatter' => $chatter, 'restart_lines' => $shutLines,
        'starts' => $starts, 'start_info' => $startInfo, 'per_day' => $per, 'hours' => $hours,
        'neighbours' => array_slice($nb, 0, 60), 'winlink' => $wl,
        'internet' => ['cms_fail' => count($cmsFail), 'dns_fail' => count($dnsFail), 'outages' => $stretches(array_merge($cmsFail, $dnsFail))],
        'soundcard' => ['count' => count($soundcard), 'stretches' => $stretches($soundcard) ?: ($soundcard ? [['from' => min($soundcard), 'to' => max($soundcard), 'count' => count($soundcard)]] : [])],
        'connects' => array_slice(array_reverse($connects), 0, 200),
        'problems' => $prob,
    ];
}

// ── Live tail ───────────────────────────────────────────────────────────────
function tail(string $dir, string $today, int $offset): array {
    $f = file_for($dir, $today);
    if (!is_readable($f)) return ['success' => true, 'present' => false, 'file' => basename($f), 'offset' => 0, 'lines' => []];
    clearstatcache(true, $f);
    $size = filesize($f);
    if ($offset <= 0 || $offset > $size) $offset = max(0, $size - 400000);     // first call (or a new day): the last ~600 lines' worth
    $fh = fopen($f, 'rb'); fseek($fh, $offset);
    $buf = stream_get_contents($fh, min(2000000, $size - $offset)); fclose($fh);
    $cut = strrpos($buf, "\n");
    if ($cut === false) return ['success' => true, 'present' => true, 'file' => basename($f), 'offset' => $offset, 'size' => $size, 'lines' => []];
    $buf = substr($buf, 0, $cut + 1);
    $newOffset = $offset + strlen($buf);
    $rows = preg_split('/\r?\n/', rtrim($buf, "\r\n"));
    if ($offset > 0 && $offset === max(0, $size - 400000)) array_shift($rows);      // first line may be cut
    // shutdown context: a start ("CTEXT Read") within the chunk marks the 30 s before it
    $startSecs = [];
    foreach ($rows as $r) if (strpos($r, 'CTEXT Read for ports') !== false && preg_match('/^(\d\d):(\d\d):(\d\d)/', $r, $m)) $startSecs[] = $m[1] * 3600 + $m[2] * 60 + $m[3];
    $out = [];
    foreach ($rows as $r) {
        $r = mask($r);
        $sec = preg_match('/^(\d\d):(\d\d):(\d\d)/', $r, $m) ? $m[1] * 3600 + $m[2] * 60 + $m[3] : null;
        $shut = false;
        if ($sec !== null) foreach ($startSecs as $s) if ($s - $sec >= 0 && $s - $sec <= 30) $shut = true;
        $text = $sec !== null ? substr($r, 9) : $r;
        $out[] = ['t' => $r, 'k' => $sec === null ? 'cont' : classify($text, $shut)];
    }
    return ['success' => true, 'present' => true, 'file' => basename($f), 'offset' => $newOffset, 'size' => $size, 'mtime' => filemtime($f), 'lines' => array_slice($out, -6000)];
}

// ── Why LinBPQ restarted (v1.5.95) ──────────────────────────────────────────
// Sources, best first: the system records the LinBPQ Status helper reads as
// root (systemd's record of the service, kernel crash / out-of-memory lines,
// sudo / su / cron / reboot lines; needs its sudo rule), and the Connect
// Watchdog's own log. Without them, what the node debug log itself shows.
const HELPER = '/usr/local/sbin/bpq-dashboard-linbpq-status';

function sys_tz(): DateTimeZone {
    $n = (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && getenv('NODE_DEBUG_TZ')) ? (string)getenv('NODE_DEBUG_TZ') : '';
    if ($n === '') $n = trim((string)@file_get_contents('/etc/timezone'));
    if ($n === '' && ($l = @readlink('/etc/localtime')) && preg_match('#zoneinfo/(.+)$#', $l, $m)) $n = $m[1];
    try { return new DateTimeZone($n !== '' ? $n : date_default_timezone_get()); } catch (Exception $e) { return new DateTimeZone('UTC'); }
}

function helper_data(): array {
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && getenv('NODE_DEBUG_HELPER_JSON') !== false) {             // tests
        $f = (string)getenv('NODE_DEBUG_HELPER_JSON');
        return $f === '' ? ['_state' => 'missing'] : ((array)json_decode((string)@file_get_contents($f), true) + ['_state' => 'ok']);
    }
    if (!is_file(HELPER)) return ['_state' => 'missing'];
    $cache = sys_get_temp_dir() . '/bpq-node-debug-helper.json';      // the page reloads its summary every 5 min
    if (is_file($cache) && time() - filemtime($cache) < 120 && ($c = json_decode((string)@file_get_contents($cache), true))) return $c;
    $raw = (string)shell_exec('sudo -n ' . HELPER . ' 2>&1');
    $d = json_decode($raw, true);
    if (!is_array($d)) return ['_state' => preg_match('/password is required|not allowed|may not run sudo|^sudo:/m', $raw) ? 'refused' : 'failed'];
    $d['_state'] = 'ok';
    $old = umask(077); @file_put_contents($cache, json_encode($d)); umask($old);
    return $d;
}

function watchdog_events(DateTimeZone $tz): array {
    $f = (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && getenv('NODE_DEBUG_WATCHDOG_LOG') !== false) ? (string)getenv('NODE_DEBUG_WATCHDOG_LOG') : '';
    if ($f === '') {
        $ip = dirname(__DIR__) . '/includes/station-config.php';
        if (!function_exists('bpq_app_log') && is_file($ip)) @include_once $ip;
        $f = function_exists('bpq_app_log') ? bpq_app_log('connect-watchdog.log') : '/var/log/bpq-dashboard/connect-watchdog.log';
    }
    $lines = []; $readable = false;
    foreach ([$f . '.1', $f] as $p) {
        if (!is_readable($p)) continue;
        $readable = true;
        foreach ((array)@file($p, FILE_IGNORE_NEW_LINES) as $l) $lines[] = $l;
    }
    if (is_readable($f . '.2.gz') && function_exists('gzfile')) $lines = array_merge((array)@gzfile($f . '.2.gz'), $lines);
    $ev = [];
    foreach ($lines as $l) {
        if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) \[\w+\] (Stopping LinBPQ to set (.*)|Could not (?:stop|start) LinBPQ.*)$/', rtrim((string)$l), $m)) continue;
        try { $t = (new DateTime($m[1], $tz))->getTimestamp(); } catch (Exception $e) { continue; }
        $what = [];
        foreach (explode(',', $m[3] ?? '') as $c) if (preg_match('/(\S+) Enabled=([01])/', trim($c), $x)) $what[] = ($x[2] === '1' ? 'resumed ' : 'paused ') . $x[1];
        $ev[] = ['t' => $t, 'what' => $what ? implode(', ', $what) : trim($m[2])];
    }
    return ['readable' => $readable, 'file' => $f, 'events' => $ev];
}

function system_events(array $d): array {
    $E = [];
    $ts = function (string $l): ?int {
        if (preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:[+-]\d\d:?\d\d|Z)?)/', $l, $m)) return strtotime($m[1]) ?: null;
        if (preg_match('/^\[(\w{3} \w{3}\s+\d+ \d\d:\d\d:\d\d \d{4})\]/', $l, $m)) return strtotime($m[1]) ?: null;
        return null;
    };
    foreach ((array)($d['unit_events'] ?? []) as $l) {
        $l = (string)$l; if (($t = $ts($l)) === null) continue;
        if (preg_match('/Main process exited, code=(\w+), status=(\d+)(?:\/(\w+))?/', $l, $m)) {
            $sig = $m[3] ?? '';
            if ($m[1] === 'exited') $E[] = ['t' => $t, 'k' => $m[2] === '0' ? 'exit0' : 'exitN', 'x' => $m[2], 'line' => $l];
            elseif (in_array($sig, ['TERM', 'INT', 'HUP'], true)) $E[] = ['t' => $t, 'k' => 'term', 'x' => $sig, 'line' => $l];
            else $E[] = ['t' => $t, 'k' => 'crash', 'x' => $sig ?: $m[1], 'line' => $l];
        } elseif (strpos($l, 'Scheduled restart job') !== false) $E[] = ['t' => $t, 'k' => 'autorestart', 'line' => $l];
        elseif (preg_match('/\bStopping\b/', $l)) $E[] = ['t' => $t, 'k' => 'stopping', 'line' => $l];
    }
    foreach ((array)($d['kernel_crashes'] ?? []) as $l) {
        $l = (string)$l; if (($t = $ts($l)) === null) continue;
        $E[] = ['t' => $t, 'k' => preg_match('/Out of memory|oom-kill/i', $l) ? 'oom' : 'crash', 'x' => preg_match('/segfault/i', $l) ? 'SEGV' : 'kernel', 'line' => $l];
    }
    foreach ((array)($d['coredumps'] ?? []) as $l) {
        if (preg_match('/(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \S+)\s+\d+\s+\d+\s+\d+\s+(SIG\w+|\d+)/', (string)$l, $m) && ($t = strtotime($m[1])))
            $E[] = ['t' => $t, 'k' => 'crash', 'x' => preg_replace('/^SIG/', '', $m[2]), 'line' => (string)$l];
    }
    foreach ((array)($d['admin_events'] ?? []) as $l) {
        $l = (string)$l; if (($t = $ts($l)) === null) continue;
        if (preg_match('/sudo\[\d+\]:\s+(\S+) : .*COMMAND=(.*)$/', $l, $m)) {
            $cmd = trim($m[2]);
            $k = preg_match('#/(reboot|shutdown|poweroff|halt)\b#', $cmd) ? 'reboot_cmd' : (preg_match('#/(bash|sh|zsh|su)( -l| -i| -)?$#', $cmd) ? 'rootshell' : 'sudo');
            $E[] = ['t' => $t, 'k' => $k, 'user' => $m[1], 'x' => preg_replace('#^\S*/#', '', $cmd), 'line' => $l];
        } elseif (preg_match('/session opened for user root.* by (\w[\w.-]*)/', $l, $m)) {
            $E[] = ['t' => $t, 'k' => 'rootshell', 'user' => $m[1], 'x' => 'su', 'line' => $l];
        } elseif (preg_match('/CRON\[\d+\]: \((\S+)\) CMD \((.*)\)/', $l, $m)) {
            $E[] = ['t' => $t, 'k' => 'cron', 'user' => $m[1], 'x' => $m[2], 'line' => $l];
        } elseif (preg_match('/systemd-logind\[\d+\]:\s*(.*)$/', $l, $m)) {
            $E[] = ['t' => $t, 'k' => 'shutdown', 'x' => $m[1], 'line' => $l];
        }
    }
    foreach ((array)($d['boots'] ?? []) as $l) {
        if (preg_match_all('/(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?: [A-Z]{2,5}|[+-]\d\d:?\d\d)?)/', (string)$l, $m) && count($m[1]) >= 1) {
            if ($t = strtotime($m[1][0])) $E[] = ['t' => $t, 'k' => 'boot', 'line' => trim((string)$l)];
            if (isset($m[1][1]) && ($t2 = strtotime($m[1][1]))) $E[] = ['t' => $t2, 'k' => 'bootend', 'line' => trim((string)$l)];
        }
    }
    if (!empty($d['boot']) && ($t = strtotime((string)$d['boot']))) $E[] = ['t' => $t, 'k' => 'boot', 'line' => 'current boot'];
    foreach ((array)($d['root_logins'] ?? []) as $l) {             // v1.5.96: root sessions (last -F --time-format iso)
        if (!preg_match('/^root\s+(\S+)\s+(\S+)?\s*(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:?\d\d)\s+(?:-\s+(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:?\d\d)|still logged in|-\s+\w+)/', (string)$l, $m)) continue;
        $t0 = strtotime($m[3]); if (!$t0) continue;
        $t1 = !empty($m[4]) ? strtotime($m[4]) : (stripos((string)$l, 'still logged in') !== false ? time() + 60 : $t0 + 1);
        $from = preg_match('/^\d{1,3}(\.\d{1,3}){3}$|:/', (string)($m[2] ?? '')) ? 'SSH from ' . $m[2] : (preg_match('/^tty/', $m[1]) ? 'the console (' . $m[1] . ')' : $m[1] . (($m[2] ?? '') !== '' ? ' from ' . $m[2] : ''));
        $E[] = ['t' => $t0, 'k' => 'rootlogin', 'end' => $t1, 'x' => $from, 'line' => trim((string)$l)];
    }
    foreach ((array)($d['jobs_at_stops'] ?? []) as $j) {           // v1.5.96: a job still running when LinBPQ was stopped
        if (!is_array($j) || !($ts = strtotime((string)($j['stop'] ?? ''))) || !($t0 = strtotime((string)($j['started'] ?? '')))) continue;
        $E[] = ['t' => $ts, 'k' => 'job', 'started' => $t0, 'unit' => preg_replace('/\.service$/', '', (string)($j['unit'] ?? '')), 'x' => (string)($j['desc'] ?? ''),
                'line' => gmdate('Y-m-d H:i:s', $t0) . 'Z started ' . ($j['unit'] ?? '') . (($j['desc'] ?? '') !== '' ? ' (' . $j['desc'] . ')' : '') . ', still running when LinBPQ was stopped'];
    }
    usort($E, fn($a, $b) => $a['t'] <=> $b['t']);
    return $E;
}

function explain_starts(array &$S): void {
    $tz = sys_tz();
    $h = helper_data(); $wd = watchdog_events($tz);
    $E = ($h['_state'] ?? '') === 'ok' ? system_events($h) : [];
    $unit = ($h['how']['unit'] ?? '') ?: 'bpq';
    $SIG = ['SEGV' => 'a segfault (memory error)', 'ABRT' => 'an abort', 'KILL' => 'kill -9', 'BUS' => 'a bus error', 'ILL' => 'an illegal instruction', 'FPE' => 'an arithmetic error', 'kernel' => 'a crash in the kernel log', 'dumped' => 'a crash (core dumped)', 'killed' => 'being killed'];
    $in = fn(int $a, int $b, array $kinds) => array_values(array_filter($E, fn($e) => $e['t'] >= $a && $e['t'] <= $b && in_array($e['k'], $kinds, true)));
    $fmt = fn(int $t) => gmdate('H:i:s', $t) . 'Z';
    $counts = [];
    foreach ($S['start_info'] as &$s) {
        $at = $s['at']; $down = $s['stopped_at'] ?? $at;
        $why = null; $ev = [];
        // 1. a crash or the out-of-memory killer, not followed by a stop request
        $cr = $in($at - 900, $at + 5, ['crash', 'oom', 'exitN']);
        if ($cr) {
            $c = end($cr);
            $after = $in($c['t'] + 1, $at, ['stopping', 'sudo']);
            if (!$after) {
                $auto = $in($c['t'], $at + 5, ['autorestart']);
                $why = $c['k'] === 'oom' ? ['crash', 'Killed: out of memory', 'The computer ran out of memory and the kernel ended LinBPQ.']
                     : ($c['k'] === 'exitN' ? ['crash', 'LinBPQ quit with an error', "LinBPQ ended with error code {$c['x']}."]
                     : ['crash', 'Crashed', 'LinBPQ crashed: ' . ($SIG[$c['x']] ?? $c['x']) . '.']);
                $why[2] .= $auto ? ' systemd started it again automatically.' : '';
                foreach (array_merge($cr, $auto) as $e) $ev[] = $e['line'];
            }
        }
        // 2. the computer started (and how it went down)
        if (!$why && ($b = $in($at - 900, $at, ['boot']))) {
            $b = end($b);
            $prev = $in($b['t'] - 86400 * 30, $b['t'] - 1, ['bootend']); $pe = $prev ? end($prev) : null;
            $ord = $pe ? $in($pe['t'] - 900, $pe['t'] + 5, ['reboot_cmd', 'shutdown']) : [];
            $oc = array_values(array_filter($ord, fn($e) => $e['k'] === 'reboot_cmd'));
            $o = $oc ? end($oc) : ($ord ? end($ord) : null);
            $why = ['reboot', 'Computer restarted', $o
                ? ('The computer was restarted' . (isset($o['user']) ? " by {$o['user']} ({$o['x']})" : " ({$o['x']})") . '; LinBPQ started with it.')
                : ($pe ? 'The computer started again with no shutdown on record (last entry before it ' . $fmt($pe['t']) . '): power loss, a hang or a reset button. LinBPQ started with it.'
                       : 'The computer started; LinBPQ started with it.')];
            $ev[] = $b['line']; if ($o) $ev[] = $o['line'];
        }
        // 3. the Connect Watchdog
        if (!$why) foreach ($wd['events'] as $w) if ($w['t'] >= $at - 180 && $w['t'] <= $at + 5) {
            $why = ['watchdog', 'Connect Watchdog', 'The Connect Watchdog restarted LinBPQ to change forwarding: ' . $w['what'] . '.'];
            $ev[] = 'connect-watchdog.log: ' . $fmt($w['t']) . ' Stopping LinBPQ (' . $w['what'] . ')';
        }
        // 3b. a scheduled job (a backup, typically) still running when LinBPQ was stopped (v1.5.96)
        if (!$why && ($x = $in($at - 300, $at + 5, ['job']))) {
            $x = end($x);
            $bk = preg_match('/backup|restic|borg|rsnapshot|snapshot|rsync|duplicity|timeshift/i', $x['unit'] . ' ' . $x['x']);
            $why = ['job', ($bk ? 'Backup: ' : 'Job: ') . $x['unit'],
                ($x['x'] !== '' ? $x['x'] . ' (' . $x['unit'] . ')' : $x['unit']) . ' started at ' . $fmt($x['started']) . ' and was still running when LinBPQ was stopped at ' . $fmt($x['t']) . '.'
                . ($bk ? ' Backups stop LinBPQ for a few seconds so its BBS files are copied while nothing is writing to them; this is expected.' : ' That job most likely stopped it.')];
            $ev[] = $x['line'];
        }
        // 4. someone with sudo, 5. a cron job
        if (!$why && ($x = $in($at - 300, $at + 5, ['sudo']))) {
            $x = end($x);
            $by = $x['user'] === 'www-data' ? 'from a dashboard page' : 'by ' . $x['user'];
            $why = ['manual', ($x['user'] === 'www-data' ? 'Dashboard' : 'By ' . $x['user']), "Restarted $by: sudo {$x['x']} at " . $fmt($x['t']) . '.'];
            $ev[] = $x['line'];
        }
        if (!$why && ($x = $in($at - 180, $at + 5, ['cron']))) {
            $x = end($x);
            $why = ['cron', 'Cron job', "A scheduled job of {$x['user']}'s restarted it: {$x['x']}"];
            $ev[] = $x['line'];
        }
        // 6. systemd was asked to stop it, by root with no sudo record
        if (!$why && ($x = $in($at - 300, $at + 5, ['stopping', 'term']))) {
            $x = end($x);
            // v1.5.96: a root login open at that moment (a restart typed in a root shell leaves no sudo record)
            $rl = array_values(array_filter($E, fn($e) => $e['k'] === 'rootlogin' && $e['t'] <= $x['t'] && $e['end'] >= $x['t'] - 2));
            if ($rl) {
                $r = end($rl);
                $why = ['manual', 'Root login', 'Restarted from a root session: systemd was told to stop LinBPQ at ' . $fmt($x['t']) . ' while root was logged in (' . $r['x'] . ', since ' . $fmt($r['t'])
                    . ($x['t'] - $r['t'] < 120 ? ', ' . ($x['t'] - $r['t']) . ' s earlier' : '') . '). Commands typed as root need no sudo, so nothing else records them.'];
                $ev[] = $x['line']; $ev[] = 'last: ' . $r['line'];
            }
        }
        if (!$why && ($x = $in($at - 300, $at + 5, ['stopping', 'term']))) {
            $x = end($x);
            $rs = $in($at - 43200, $at, ['rootshell']); $r = $rs ? end($rs) : null;
            $why = ['root', 'Root / script', 'systemd was told to stop LinBPQ at ' . $fmt($x['t']) . ' by root, with no sudo record: a root shell, a script, or another program.'
                . ($r ? " {$r['user']} had opened a root shell at " . $fmt($r['t']) . ' (' . $r['x'] . ').' : '')];
            $ev[] = $x['line']; if ($r) $ev[] = $r['line'];
        }
        if (!$why && ($x = $in($at - 900, $at, ['exit0']))) {
            $x = end($x);
            $why = ['self', 'LinBPQ closed itself', 'LinBPQ ended by itself without an error (exit 0) at ' . $fmt($x['t']) . ': a sysop command, or a setting it could not use.'];
            $ev[] = $x['line'];
        }
        // nothing on record: the log's own clues
        if (!$why && !$wd['readable'] && $s['slot'] && $s['clean']) {
            $why = ['watchdog?', 'Probably Watchdog', "On the Connect Watchdog's 5-minute schedule, with a normal shutdown (its log can't be read to make sure)."];
        }
        if (!$why) {
            $why = ['unknown', 'Unknown', $s['clean']
                ? "LinBPQ was told to stop (its normal shutdown lines are there), but nothing recorded who or what asked."
                : 'LinBPQ stopped without its shutdown lines: it ended abruptly (a crash with no record, kill -9, out of memory or a power cut), or nothing was running on its links to log as it went.'];
        }
        [$s['why'], $s['label'], $s['detail']] = $why;
        $s['evidence'] = array_values(array_unique($ev));
        $counts[$s['why']] = ($counts[$s['why']] ?? 0) + 1;
        if ($s['why'] === 'unknown') {
            $a = gmdate('Y-m-d H:i:s', $down - 300); $b2 = gmdate('Y-m-d H:i:s', $at + 60);
            $checked = [];
            $checked[] = $wd['readable'] ? "Connect Watchdog: no restart in its log then." : "Connect Watchdog: its log (" . $wd['file'] . ") couldn't be read.";
            if (($h['_state'] ?? '') === 'ok') {
                $checked[] = 'System records: no crash, out-of-memory, sudo, cron, reboot, scheduled job or systemd stop for LinBPQ then.';
                if (empty($h['journal_persistent'])) $checked[] = 'The system journal isn\'t kept across reboots here, so older records may be gone.';
                if (($h['how']['kind'] ?? '') !== 'systemd') $checked[] = 'LinBPQ isn\'t run by systemd here (' . ($h['how']['kind'] ?? 'unknown') . '), so there is no service record of stops and starts.';
            } else {
                $checked[] = 'System records: not available (' . (($h['_state'] ?? '') === 'missing' ? 'the LinBPQ Status helper isn\'t installed' : 'the web server may not run the LinBPQ Status helper') . '). Run the update and answer yes to the admin pages\' sudo rules for automatic answers.';
            }
            $s['troubleshoot'] = [
                'checked' => $checked,
                'ideas' => $s['clean']
                    ? ['Someone in a root shell (sudo -i, sudo bash, su -) running systemctl restart ' . $unit . ': sudo only records the shell being opened.',
                       'Another program or script that restarts LinBPQ (a backup or update script, a second watchdog, Pat or a modem helper).',
                       'A LinBPQ sysop command, or the BPQ web page\'s restart.']
                    : ['A crash that left no record: look for a core dump (coredumpctl list linbpq).',
                       'kill -9 / pkill from a root shell, or the out-of-memory killer (dmesg).',
                       'A power cut or the computer hanging: check whether the computer restarted (last -x).',
                       'Several minutes with nothing in the log before it: the computer itself may have been off or frozen.'],
                'commands' => [
                    ['Everything the system logged then', "sudo journalctl --utc --since \"$a\" --until \"$b2\" -o short-iso"],
                    ['LinBPQ\'s service then', "sudo journalctl --utc -u $unit --since \"$a\" --until \"$b2\" -o short-iso"],
                    ['Logins, reboots and shutdowns', 'last -x -F | head -25'],
                    ['Root\'s typed commands (no times, newest last)', 'sudo grep -nE "systemctl.*bpq|linbpq|kill" /root/.bash_history | tail -8'],
                    ['Other jobs that could restart LinBPQ', 'sudo grep -rnE "bpq|linbpq" /etc/crontab /etc/cron.d /var/spool/cron/crontabs 2>/dev/null'],
                    ['Crash dumps and memory kills', 'coredumpctl list --no-pager 2>/dev/null | grep -i bpq; sudo dmesg -T | grep -iE "oom|killed process|segfault"'],
                ],
            ];
        }
    }
    unset($s);
    $S['restart_reasons'] = $counts;
    $S['restart_sources'] = ['system' => $h['_state'] ?? 'missing', 'watchdog' => $wd['readable'], 'watchdog_file' => $wd['file'], 'unit' => $unit];
    // to Needs attention
    $crash = array_values(array_filter($S['start_info'], fn($s) => $s['why'] === 'crash'));
    $unk = array_values(array_filter($S['start_info'], fn($s) => $s['why'] === 'unknown'));
    $add = [];
    if ($crash) $add[] = ['key' => 'restart-crash', 'level' => 'crit', 'title' => 'LinBPQ crashed', 'what' => 'LinBPQ ended by crashing or being killed, not by a normal stop. Each one is on the Starts tab.',
        'fix' => 'Admin → LinBPQ Status has the crash records. If it keeps happening, note the time and what LinBPQ was doing (the lines before it on the Starts tab) for the LinBPQ users group.',
        'count' => count($crash), 'first' => $crash[0]['at'], 'last' => end($crash)['at'], 'sample' => $crash[0]['detail'], 'who' => (object)[], 'tab' => 'starts'];
    if ($unk) $add[] = ['key' => 'restart-unknown', 'level' => 'warn', 'title' => 'LinBPQ restarted for no recorded reason', 'what' => 'Nothing the dashboard can read recorded why LinBPQ stopped at these times.',
        'fix' => 'Open each one on the Starts tab: it lists what was checked, what could cause it, and commands to look at the system logs for that minute.',
        'count' => count($unk), 'first' => $unk[0]['at'], 'last' => end($unk)['at'], 'sample' => $unk[0]['detail'], 'who' => (object)[], 'tab' => 'starts'];
    if ($add) $S['problems'] = array_merge($add, $S['problems']);
}

// ── Search the period / lines around a moment (v1.5.95) ─────────────────────
// The live view holds only the end of today's file; "Find" buttons search
// every file in the chosen range instead.
function find_lines(string $dir, string $today, int $days, string $q, int $at = 0, string $rx = ''): array {
    $t0 = strtotime('20' . substr($today, 0, 2) . '-' . substr($today, 2, 2) . '-' . substr($today, 4, 2) . ' 00:00:00 UTC');
    $out = []; $total = 0; $ql = mb_strtolower($q);
    $DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    if ($at > 0) { $dayList = [gmdate('ymd', $at)]; $from = $at - 300; $to = $at + 90; }
    else { $dayList = []; for ($i = $days - 1; $i >= 0; $i--) $dayList[] = gmdate('ymd', $t0 - $i * 86400); }
    foreach ($dayList as $ymd) {
        $f = file_for($dir, $ymd);
        if (!is_readable($f)) continue;
        $base = strtotime('20' . substr($ymd, 0, 2) . '-' . substr($ymd, 2, 2) . '-' . substr($ymd, 4, 2) . ' 00:00:00 UTC');
        $lbl = $DOW[(int)gmdate('w', $base)] . ' ' . (int)gmdate('j', $base) . ' ';
        $fh = fopen($f, 'rb'); $sec = 0;
        while (($l = fgets($fh)) !== false) {
            $l = rtrim($l, "\r\n");
            $has = preg_match('/^(\d\d):(\d\d):(\d\d) /', $l, $m);
            if ($has) $sec = $m[1] * 3600 + $m[2] * 60 + $m[3];
            if ($at > 0) {
                $ts = $base + $sec;
                if ($ts < $from) continue;
                if ($ts > $to) break;
                $text = $has ? substr($l, 9) : $l;
                $out[] = ['t' => ($has ? $lbl : '') . mask($l), 'k' => !$has ? 'cont' : (strpos($text, 'CTEXT Read for ports') !== false && abs($ts - $at) <= 1 ? 'start mark' : classify($text, $ts < $at && $at - $ts <= 30)), 'at' => $ts];
                $total++;
                continue;
            }
            if ($rx !== '' ? !($has && preg_match($rx, substr($l, 9))) : mb_stripos($l, $q) === false) continue;
            $total++;
            $text = $has ? substr($l, 9) : $l;
            $out[] = ['t' => ($has ? $lbl : '') . mask($l), 'k' => $has ? classify($text, false) : 'cont'];
            if (count($out) > 3000) array_splice($out, 0, 1000);      // keep the newest
        }
        fclose($fh);
    }
    return ['success' => true, 'q' => $q, 'at' => $at ?: null, 'total' => $total, 'shown' => min(count($out), 2000), 'lines' => array_slice($out, -2000)];
}

$action = (string)($_GET['action'] ?? 'summary');
if ($action === 'find') {
    $q = trim((string)($_GET['q'] ?? '')); $days = (int)($_GET['days'] ?? 3); if (!in_array($days, [1, 3, 7], true)) $days = 3;
    $key = (string)($_GET['key'] ?? '');
    if (isset(KNOWN[$key])) { $r = find_lines($LOG_DIR, $TODAY, $days, KNOWN[$key][2], 0, KNOWN[$key][0]); $r['by_key'] = true; out($r); }   // a known problem: the same pattern that counted it
    if (mb_strlen($q) < 2 || mb_strlen($q) > 100) out(['success' => false, 'error' => 'Type at least 2 characters to search for.']);
    out(find_lines($LOG_DIR, $TODAY, $days, $q));
}
if ($action === 'around') {
    $at = (int)($_GET['at'] ?? 0);
    if ($at < 1600000000 || $at > time() + 86400) out(['success' => false, 'error' => 'bad time']);
    out(find_lines($LOG_DIR, $TODAY, 1, '', $at));
}
if ($action === 'tail') out(tail($LOG_DIR, $TODAY, (int)($_GET['offset'] ?? 0)));
if ($action === 'summary') {
    $days = (int)($_GET['days'] ?? 3); if (!in_array($days, [1, 3, 7], true)) $days = 3;
    $S = summary($LOG_DIR, $TODAY, $days);
    explain_starts($S);
    out($S);
}
http_response_code(400);
out(['success' => false, 'error' => 'unknown action']);
