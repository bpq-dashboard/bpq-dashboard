#!/usr/bin/env php
<?php
/**
 * nws-auto-post.php — post new NWS Warnings / Watches to the BBS as bulletins
 * (2026-10-26)
 *
 * Every run (systemd timer, every 5 minutes) it:
 *   1. reads the active NWS alerts for one state from api.weather.gov,
 *      FIRST ISSUES ONLY (message_type=alert: no updates, extensions or
 *      cancellations) and real alerts only (status=actual: no tests);
 *   2. keeps the event types configured (default: names ending in
 *      "Warning" or "Watch"), minus any excluded by name;
 *   3. skips anything already over (ends, or expires when there is no end,
 *      is in the past) and anything it has posted before;
 *   4. combines a watch that several NWS offices each issued (same watch
 *      number, e.g. Tornado Watch 123 from Peachtree City, Tallahassee and
 *      Charleston) into ONE bulletin. It waits `watch_hold_minutes` after
 *      the first piece so the other offices' pieces can arrive. Tropical
 *      (hurricane / tropical storm) watches and warnings are combined the
 *      same way, by storm;
 *   5. posts each one to the BBS with SB (bulletin), through the Email
 *      Client's own send code and the shared BBS lock, so it never logs in
 *      at the same time as the Email Client or fetch-message-bodies.
 *
 * Settings: config.php 'nws' => ['auto_post' => [...]]  (see config.php.example;
 * install.sh asks for the state). Nothing posts unless 'enabled' => true and
 * 'area' is a state code. destination defaults to WX@<area>.
 *
 * State: cache/nws-auto-post-state.json (what was posted; entries dropped
 * 2 days after the alert ends). Log: logs/nws-auto-post.log (alerts seen,
 * decisions, and the BBS dialogue). One summary line on stdout → journalctl.
 *
 * RUN MANUALLY (as www-data):
 *   php nws-auto-post.php              # normal run (what the timer does)
 *   php nws-auto-post.php --dry-run    # show what would post; post/record nothing
 *   php nws-auto-post.php --seed       # mark everything active now as posted,
 *                                      #   without posting (first install, optional)
 *   php nws-auto-post.php --verbose    # print every decision, not just the summary
 *   php nws-auto-post.php --file=alerts.json   # read alerts from a saved
 *                                      #   api.weather.gov reply (testing)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is for command-line/systemd use only.');
}

// ─── Args ────────────────────────────────────────────────────────────
$opt = ['dry' => false, 'seed' => false, 'verbose' => false, 'file' => null, 'now' => null];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run')                    $opt['dry'] = true;
    elseif ($a === '--seed')                   $opt['seed'] = true;
    elseif ($a === '--verbose' || $a === '-v') $opt['verbose'] = true;
    elseif (preg_match('/^--file=(.+)$/', $a, $m)) $opt['file'] = $m[1];
    elseif (preg_match('/^--now=(.+)$/', $a, $m))  $opt['now'] = strtotime($m[1]);   // testing
    elseif ($a === '--help' || $a === '-h') {
        echo "Usage: php nws-auto-post.php [--dry-run] [--seed] [--verbose] [--file=alerts.json]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: $a (try --help)\n");
        exit(2);
    }
}

// ─── Load the dashboard's config + the Email Client's BBS send code ──
$webRoot = dirname(__DIR__);
if ($opt['file'] !== null && $opt['file'][0] !== '/') $opt['file'] = getcwd() . '/' . $opt['file'];   // before chdir
chdir($webRoot);
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';   // bootstrap.php reads these
$_SERVER['PHP_SELF']       = $_SERVER['PHP_SELF'] ?? '/scripts/nws-auto-post.php';
define('BPQ_MESSAGES_LIB', true);
require $webRoot . '/bbs-messages.php';   // $config, sendMessage(), bpqTelnetLock(), logMessage()

$config['log_file'] = $webRoot . '/logs/nws-auto-post.log';

$ap = (array)getConfig('nws', 'auto_post', []);
$S = [
    'enabled'      => (bool)($ap['enabled'] ?? false),
    'area'         => strtoupper(trim((string)($ap['area'] ?? ''))),
    'destination'  => strtoupper(trim((string)($ap['destination'] ?? ''))),
    'events'       => array_values(array_filter((array)($ap['events'] ?? ['Warning', 'Watch']))),
    'exclude'      => array_map('strtoupper', (array)($ap['exclude'] ?? [])),
    'watch_hold'   => max(0, (int)($ap['watch_hold_minutes'] ?? 10)) * 60,
    'max_per_run'  => max(1, (int)($ap['max_per_run'] ?? 10)),
    'timezone'     => (string)($ap['timezone'] ?? 'America/New_York'),
    'from'         => strtoupper((string)getConfig('station', 'callsign', 'N0CALL')),
    'email'        => (string)getConfig('station', 'email', ''),
];
if ($S['destination'] === '' && $S['area'] !== '') $S['destination'] = 'WX@' . $S['area'];   // e.g. WX@GA

$NOW       = $opt['now'] ?: time();
$stateFile = $webRoot . '/cache/nws-auto-post-state.json';
$runLock   = $webRoot . '/cache/nws-auto-post.lock';

const BBS_LOCK_WAIT  = 150;   // seconds to wait for the Email Client / body fetcher
const MAX_ATTEMPTS   = 3;     // per bulletin, so a BBS that answers oddly can't cause repeats
const POST_GAP_US    = 3000000;
const KEEP_AFTER_END = 172800; // keep state 2 days after an alert ends

function out($line)  { echo $line, "\n"; logMessage($line); }
function vlog($line) { global $opt; logMessage($line); if ($opt['verbose'] || $opt['dry']) echo $line, "\n"; }
function fail($msg)  { fwrite(STDERR, "nws-auto-post: $msg\n"); @logMessage("ERROR: $msg"); exit(1); }

if (!$S['enabled'] && !$opt['dry'] && !$opt['seed']) {
    echo "nws-auto-post: disabled (config.php nws.auto_post.enabled is not true); nothing done\n";
    exit(0);
}
if (!preg_match('/^[A-Z]{2}$/', $S['area'])) fail("Set config.php nws.auto_post.area to your 2-letter state code (e.g. 'GA'); now '{$S['area']}'");
if (!preg_match('/^[A-Z0-9][A-Z0-9_.#@-]{0,59}$/', $S['destination'])) fail("Invalid nws.auto_post.destination '{$S['destination']}'");
try { $TZ = new DateTimeZone($S['timezone']); } catch (Exception $e) { $TZ = new DateTimeZone('America/New_York'); }

// One run at a time (cron could overlap a slow run; systemd never does).
$rl = @fopen($runLock, 'c');
if ($rl && !flock($rl, LOCK_EX | LOCK_NB)) { echo "nws-auto-post: previous run still going; skipped\n"; exit(0); }

// ─── State ───────────────────────────────────────────────────────────
$state = ['posted' => [], 'attempts' => []];
if (is_file($stateFile)) {
    $j = json_decode((string)file_get_contents($stateFile), true);
    if (is_array($j)) $state = array_merge($state, $j);
}
function saveState() {
    global $state, $stateFile, $opt;
    if ($opt['dry']) return;
    $tmp = $stateFile . '.tmp';
    if (@file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
        || !@rename($tmp, $stateFile)) {
        fail("Cannot write $stateFile (is cache/ writable by " . get_current_user() . "?)");
    }
}
// Drop entries for alerts that ended more than 2 days ago.
foreach ($state['posted'] as $k => $p) {
    if (($p['ends'] ?? 0) + KEEP_AFTER_END < $NOW) unset($state['posted'][$k]);
}
foreach ($state['attempts'] as $k => $a) {
    if (($a['last'] ?? 0) + KEEP_AFTER_END < $NOW) unset($state['attempts'][$k]);
}

// ─── Fetch ───────────────────────────────────────────────────────────
function fetchAlerts() {
    global $S, $opt;
    if ($opt['file']) {
        $raw = @file_get_contents($opt['file']);
        if ($raw === false) fail("Cannot read {$opt['file']}");
    } else {
        $url = 'https://api.weather.gov/alerts/active?area=' . $S['area'] . '&status=actual&message_type=alert';
        // api.weather.gov asks for a User-Agent that identifies the
        // application and a contact.
        $ua  = "BPQ-Dashboard nws-auto-post ({$S['from']}" . ($S['email'] !== '' ? ", {$S['email']}" : '') . ')';
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: $ua\r\nAccept: application/geo+json\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
        }
        if ($raw === false || $status !== 200) {
            fail("api.weather.gov fetch failed (HTTP $status)" . ($raw ? ': ' . substr(cleanLog($raw), 0, 200) : ''));
        }
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['features']) || !is_array($j['features'])) fail('api.weather.gov reply has no "features" list');
    return $j['features'];
}

// ─── Alert helpers ───────────────────────────────────────────────────
function ts($iso) { $t = $iso ? strtotime($iso) : false; return $t === false ? null : $t; }

/** When the alert is over: its event end, else its message expiry. */
function alertEnd(array $p) { return ts($p['ends'] ?? null) ?? ts($p['expires'] ?? null); }

/** First P-VTEC string's parts, or null. */
function vtec(array $p) {
    foreach ((array)($p['parameters']['VTEC'] ?? []) as $v) {
        if (preg_match('#/[OTEX]\.([A-Z]{3})\.([A-Z]{4})\.([A-Z]{2})\.([A-Z])\.(\d{4})\.#', $v, $m)) {
            return ['action' => $m[1], 'office' => $m[2], 'phen' => $m[3], 'sig' => $m[4], 'etn' => (int)$m[5]];
        }
    }
    return null;
}

/**
 * Key for one bulletin. Watches (sig A) carry a national watch number
 * shared by every office's piece; tropical products use national storm
 * numbers (ETN 1001+). Those are combined across offices. Everything else
 * is one bulletin per NWS alert.
 */
function groupKey(array $p, $id) {
    $v = vtec($p);
    if ($v && ($v['sig'] === 'A' || $v['etn'] >= 1000)) {
        return 'vtec:' . $v['phen'] . '.' . $v['sig'] . '.' . sprintf('%04d', $v['etn']) . ':' . strtoupper((string)($p['event'] ?? ''));
    }
    return 'id:' . $id;
}

function wanted(array $p) {
    global $S;
    $event = trim((string)($p['event'] ?? ''));
    if ($event === '') return 'no event name';
    if (in_array(strtoupper($event), $S['exclude'], true)) return 'excluded by name';
    foreach ($S['events'] as $suffix) {
        if (preg_match('/\b' . preg_quote($suffix, '/') . '$/i', $event)) return true;
    }
    return 'not ' . implode('/', $S['events']);
}

/** "Oct 26, 6:29 PM EDT" in the configured time zone. */
function fmtTime($t) {
    global $TZ;
    if (!$t) return 'Not specified';
    return (new DateTime('@' . $t))->setTimezone($TZ)->format('M j, g:i A T');
}

/** Plain ASCII: BBS clients and RF forwarding don't all cope with UTF-8. */
function ascii($s) {
    $s = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}", "\u{00B0}"],
                     ["'", "'", '"', '"', '-', '-', '...', ' deg'], (string)$s);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = ($t === false) ? $s : $t;
    $s = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s);
    return str_replace("\r", '', $s);
}

/** Areas from areaDesc, kept to the configured state when the list says which state each is in. */
function areaList(array $pieces) {
    global $S;
    $all = [];
    foreach ($pieces as $p) {
        foreach (explode(';', (string)($p['areaDesc'] ?? '')) as $a) {
            $a = trim(preg_replace('/\s+/', ' ', ascii($a)));
            if ($a !== '' && !in_array($a, $all, true)) $all[] = $a;
        }
    }
    $withState = array_filter($all, function ($a) { return preg_match('/, [A-Z]{2}$/', $a); });
    if ($all && count($withState) === count($all)) {
        $mine = array_values(array_filter($all, function ($a) use ($S) { return substr($a, -4) === ', ' . $S['area']; }));
        if ($mine) return $mine;
    }
    return $all;
}

function wrapText($text, $width = 79) {
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line);
        $out[] = strlen($line) > $width ? wordwrap($line, $width, "\n", true) : $line;
    }
    return implode("\n", $out);
}

function buildSubject(array $pieces, array $areas, $vt, $grouped) {
    $p = $pieces[0];
    $subject = 'NWS ' . ascii($p['event'] ?? 'WEATHER ALERT');
    if ($grouped && $vt && $vt['sig'] === 'A' && $vt['etn'] < 1000) $subject .= ' ' . $vt['etn'];
    if ($areas) {
        $subject .= ' - ' . $areas[0];
        if (count($areas) > 1) $subject .= ' +' . (count($areas) - 1);
    }
    $subject = trim(preg_replace('/\s+/', ' ', $subject));
    return strlen($subject) > 60 ? rtrim(substr($subject, 0, 57)) . '...' : $subject;
}

function buildBody(array $pieces, array $areas) {
    global $S;
    $p = $pieces[0];
    $offices = [];
    foreach ($pieces as $q) {
        $n = trim(ascii($q['senderName'] ?? ''));
        if ($n !== '' && !in_array($n, $offices, true)) $offices[] = $n;
    }
    $start = min(array_map(function ($q) { return ts($q['onset'] ?? null) ?? ts($q['effective'] ?? null) ?? ts($q['sent'] ?? null); }, $pieces));
    $end   = max(array_map('alertEnd', $pieces));

    $lines = [
        'NWS ' . strtoupper(ascii($p['event'] ?? 'WEATHER ALERT')),
        'FROM: ' . $S['from'],
        'ISSUED BY: ' . ($offices ? implode('; ', $offices) : 'National Weather Service'),
        'TIME: ' . fmtTime($start),
        'EXPIRES: ' . fmtTime($end),
        '',
    ];
    if (!empty($p['headline'])) { $lines[] = ascii($p['headline']); $lines[] = ''; }
    $lines[] = 'AREAS (' . count($areas) . '): ' . implode('; ', $areas);
    $lines[] = '';
    if (count($pieces) === 1) {
        $desc = trim(ascii($p['description'] ?? ''));
        if ($desc !== '') { $lines[] = $desc; $lines[] = ''; }
    }
    $lines[] = 'INSTRUCTIONS:';
    $lines[] = trim(ascii($p['instruction'] ?? '')) ?: 'Follow local emergency guidance';
    $lines[] = '';
    $lines[] = 'Automatic bulletin from api.weather.gov. Check weather.gov for updates.';
    $lines[] = '73 de ' . $S['from'];

    $body = wrapText(implode("\n", $lines));
    // Never let the body end the message early or carry a Ctrl-Z.
    $body = preg_replace('#^\s*/ex\s*$#mi', ' /EX', $body);
    return str_replace("\x1A", '', $body);
}

// ─── Main ────────────────────────────────────────────────────────────
$features = fetchAlerts();
$stats = ['active' => count($features), 'wanted' => 0, 'over' => 0, 'already' => 0,
          'holding' => 0, 'posted' => 0, 'seeded' => 0, 'failed' => 0, 'given_up' => 0, 'deferred' => 0];

$groups = [];   // key => ['pieces' => [...], 'ids' => [...]]
foreach ($features as $f) {
    $p  = (array)($f['properties'] ?? []);
    $id = (string)($p['id'] ?? $f['id'] ?? '');
    if ($id === '') continue;
    $label = ($p['event'] ?? '?') . ' / ' . substr(ascii($p['areaDesc'] ?? ''), 0, 60) . " [$id]";

    // Belt and braces: the query already asks for these.
    if (($p['messageType'] ?? '') !== 'Alert' || ($p['status'] ?? '') !== 'Actual') { vlog("skip (not a first-issue actual alert): $label"); continue; }
    $w = wanted($p);
    if ($w !== true) { vlog("skip ($w): $label"); continue; }
    $stats['wanted']++;
    $end = alertEnd($p);
    if ($end !== null && $end <= $NOW) { $stats['over']++; vlog('skip (over at ' . fmtTime($end) . "): $label"); continue; }

    $key = groupKey($p, $id);
    $groups[$key]['pieces'][] = $p;
    $groups[$key]['ids'][]    = $id;
}

// Oldest first, so bulletins go out in the order NWS issued them.
uasort($groups, function ($a, $b) {
    return min(array_map(function ($p) { return ts($p['sent'] ?? null) ?? 0; }, $a['pieces']))
       <=> min(array_map(function ($p) { return ts($p['sent'] ?? null) ?? 0; }, $b['pieces']));
});

$postedThisRun = 0;
foreach ($groups as $key => $g) {
    $pieces = $g['pieces'];
    $grouped = strpos($key, 'vtec:') === 0;
    $areas = areaList($pieces);
    $vt = vtec($pieces[0]);
    $subject = buildSubject($pieces, $areas, $vt, $grouped);

    if (isset($state['posted'][$key])) {
        $stats['already']++;
        $new = array_diff($g['ids'], $state['posted'][$key]['ids'] ?? []);
        if ($new) vlog("skip (watch already posted; " . count($new) . " later office piece(s) not reposted): $subject");
        continue;
    }
    if (($state['attempts'][$key]['n'] ?? 0) >= MAX_ATTEMPTS) {
        $stats['given_up']++;
        vlog("skip (gave up after " . MAX_ATTEMPTS . " failed tries; see log above): $subject");
        continue;
    }
    $ends = max(array_map('alertEnd', $pieces)) ?? ($NOW + 86400);

    if ($opt['seed']) {
        if (!$opt['dry']) $state['posted'][$key] = ['ids' => $g['ids'], 'subject' => $subject, 'ends' => $ends, 'at' => $NOW, 'seeded' => true];
        $stats['seeded']++;
        vlog("seeded (marked as posted, not sent): $subject");
        continue;
    }

    // Give the other offices time to issue their piece of a watch.
    if ($grouped && $S['watch_hold'] > 0) {
        $first = min(array_map(function ($p) { return ts($p['sent'] ?? null) ?? 0; }, $pieces));
        if ($NOW - $first < $S['watch_hold']) {
            $stats['holding']++;
            vlog('holding ' . ceil(($S['watch_hold'] - ($NOW - $first)) / 60) . " more min for other offices: $subject");
            continue;
        }
    }

    if ($postedThisRun >= $S['max_per_run']) { $stats['deferred']++; vlog("deferred to next run (max_per_run): $subject"); continue; }

    $body = buildBody($pieces, $areas);
    if ($opt['dry']) {
        echo "\n=== WOULD POST: SB {$S['destination']}\nTitle: $subject\n", $body, "\n/ex\n";
        $stats['posted']++;
        $postedThisRun++;
        continue;
    }

    if ($postedThisRun > 0) usleep(POST_GAP_US);
    $lock = bpqTelnetLock(BBS_LOCK_WAIT);
    if ($lock === false) { out("BBS busy (lock held > " . BBS_LOCK_WAIT . " s); will retry next run"); break; }
    try {
        $r = sendMessage($S['destination'], 'B', $subject, $body);
    } finally {
        bpqTelnetUnlock($lock);
    }

    if (!empty($r['success'])) {
        $state['posted'][$key] = ['ids' => $g['ids'], 'subject' => $subject, 'ends' => $ends, 'at' => $NOW];
        unset($state['attempts'][$key]);
        saveState();
        $stats['posted']++;
        $postedThisRun++;
        out("posted to {$S['destination']}: $subject");
    } else {
        $err = $r['error'] ?? 'unknown';
        $stats['failed']++;
        if (stripos($err, 'Cannot connect') === 0) {
            // Nothing reached the BBS, so it can't be a duplicate: keep
            // retrying every run for as long as the alert is in force.
            out("FAILED (BBS not reachable; will retry next run): $subject: $err");
        } else {
            // The BBS may have taken it: count, so it is retried at most MAX_ATTEMPTS times.
            $n = ($state['attempts'][$key]['n'] ?? 0) + 1;
            $state['attempts'][$key] = ['n' => $n, 'last' => $NOW, 'error' => $err];
            saveState();
            out("FAILED (try $n/" . MAX_ATTEMPTS . "): $subject: $err");
        }
        break;   // BBS likely down; don't hammer it, try again next run
    }
}

saveState();

$summary = sprintf('nws-auto-post%s: %s %d active first-issue alerts, %d warnings/watches, %d over, %d already posted, %d holding, %d %s, %d failed%s%s',
    $opt['dry'] ? ' (dry run)' : '', $S['area'], $stats['active'], $stats['wanted'], $stats['over'], $stats['already'],
    $stats['holding'], $opt['seed'] ? $stats['seeded'] : $stats['posted'], $opt['seed'] ? 'seeded' : ($opt['dry'] ? 'would post' : 'posted'),
    $stats['failed'],
    $stats['deferred'] ? ", {$stats['deferred']} deferred" : '',
    $stats['given_up'] ? ", {$stats['given_up']} given up" : '');
out($summary);
exit($stats['failed'] ? 1 : 0);
