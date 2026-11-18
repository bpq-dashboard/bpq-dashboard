<?php
/**
 * rig-control-api.php — FTdx-3000 settings for the Rig page (BPQ Dashboard v1.5.66)
 *
 * Reads, and on request changes, the radio's receive and transmit settings
 * that matter for the data modems, through flrig (which owns the radio's CAT
 * port, so nothing competes with LinBPQ's scanning and keying):
 *   width (SH, the list flrig gives for the mode), narrow (NA), IF shift (IS),
 *   roofing filter (RF), IPO/AMP (PA), attenuator (RA), AGC (GT), noise
 *   blanker (NB), noise reduction (NR, RL), auto notch (BC), manual notch
 *   (BP), RF power (rig.set_power); speech processor (PR) and monitor (ML)
 *   are shown only; live meters ALC / PO / SWR / COMP (RM).
 * Reads go through flrig's rig.cat_string (it waits for the radio's answer);
 * changes through rig.cat_priority, then read back.
 *
 * The radio's DATA menu (EX 067-079) does not answer reads through flrig
 * 2.0.10 on the FTdx-3000 it was tested with (2026-11-17), so the page lists those menu
 * items for checking on the front panel instead.
 *
 * Changes need the Rig page's unlock (the BBS password, as for TX power and
 * the power switch) and are refused while the radio is transmitting, has
 * transmitted in the last 45 s (logs/rig-history.jsonl, written every 3 s
 * by rig-history-poller), or Mercury / Data2G is in a session. Every change
 * is logged (cache/rig-control-log.jsonl and the web server's error log).
 *
 *   GET  ?action=state            settings, the width list, busy state
 *   GET  ?action=meters           ALC, PO, SWR, COMP (0-255) and PTT
 *   GET  ?action=log              the last 25 changes
 *   POST {"action":"set","key":"nb","value":"0"}
 *
 * Only the FTdx-3000 (flrig's rig.get_xcvr "FTDX3000") is supported: these
 * CAT commands differ between Yaesu models. Others get supported:false, and
 * the Rig page then doesn't show the card at all (v1.5.66).
 * config.php 'radio' => ['settings_panel' => false] turns it off entirely.
 */

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$SKIP_BBS_CHECK = true;
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mercury-status.php';
require_once __DIR__ . '/includes/data2g-status.php';

$FLRIG_HOST = (string)($CONFIG['radio']['flrig_host'] ?? '127.0.0.1') ?: '127.0.0.1';
$FLRIG_PORT = (int)($CONFIG['radio']['flrig_port'] ?? 12345) ?: 12345;
$PWR_CAP    = (int)($CONFIG['radio']['max_power'] ?? 0);
$PWR_MIN    = max(5, (int)($CONFIG['radio']['min_power'] ?? 5));
$HIST_FILE  = rtrim((string)($CONFIG['paths']['datalog'] ?? __DIR__ . '/logs'), '/') . '/rig-history.jsonl';
$LOG_FILE   = __DIR__ . '/cache/rig-control-log.jsonl';

function out(array $d): void { echo json_encode(['ok' => true] + $d); exit; }
function bad(string $m, int $code = 400, array $extra = []): void {
    http_response_code($code); echo json_encode(['ok' => false, 'error' => $m] + $extra); exit;
}

// ── flrig ───────────────────────────────────────────────────────────────────
function flrig(string $method, array $params = [], float $timeout = 3.0): string|false {
    global $FLRIG_HOST, $FLRIG_PORT;
    $px = '';
    foreach ($params as $p) {
        $px .= is_int($p) ? "<param><value><i4>$p</i4></value></param>"
                          : '<param><value><string>' . htmlspecialchars((string)$p, ENT_XML1) . '</string></value></param>';
    }
    $xml = "<?xml version='1.0'?><methodCall><methodName>$method</methodName><params>$px</params></methodCall>";
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout,
        'header' => "Content-Type: text/xml\r\nContent-Length: " . strlen($xml), 'content' => $xml]]);
    $r = @file_get_contents("http://$FLRIG_HOST:$FLRIG_PORT/RPC2", false, $ctx);
    if ($r === false || str_contains($r, '<fault>')) return false;
    return $r;
}
function flrig_values(string|false $r): array {            // every scalar in the reply
    if ($r === false) return [];
    preg_match_all('#<value>\s*(?:<(?:string|i4|int|double)>)?([^<]*)(?:</(?:string|i4|int|double)>)?\s*</value>#', $r, $m);
    return array_map(fn($v) => html_entity_decode(trim($v), ENT_XML1), $m[1]);
}
function flrig_one(string $method): ?string { $v = flrig_values(flrig($method)); return $v ? $v[0] : null; }
/** Send a CAT read ("NB0;") and return the radio's answer ("NB02;"), or null. */
function cat_read(string $cmd): ?string {
    $v = flrig_values(flrig('rig.cat_string', [$cmd], 2.5));
    $a = $v ? trim($v[0]) : '';
    $want = substr($cmd, 0, 2);
    return ($a !== '' && str_starts_with($a, $want) && str_ends_with($a, ';')) ? $a : null;
}
/** Send a CAT set ("NB00;"); flrig queues it ahead of its polling. */
function cat_set(string $cmd): bool {
    return flrig('rig.cat_priority', [$cmd]) !== false;
}

// ── What each setting is, and how to read / write it ───────────────────────
// read: CAT read; parse: answer -> value (string); set: value -> CAT set; opts: allowed values => label
$S = [
    'narrow' => ['label' => 'Narrow', 'read' => 'NA0;', 'parse' => fn($a) => substr($a, 3, 1),
                 'opts' => ['0' => 'Off', '1' => 'On'], 'set' => fn($v) => "NA0$v;"],
    'shift'  => ['label' => 'IF shift', 'read' => 'IS0;', 'parse' => fn($a) => (string)(int)substr($a, 3, 5),
                 'range' => [-1000, 1000, 10], 'unit' => 'Hz',
                 'set' => fn($v) => sprintf('IS0%s%04d;', (int)$v < 0 ? '-' : '+', abs((int)$v))],
    'roofing'=> ['label' => 'Roofing filter', 'read' => 'RF0;', 'parse' => fn($a) => substr($a, 3, 1),
                 // the answer says AUTO-xx when AUTO is set; setting takes 0-5
                 'shown' => ['1' => '15 kHz', '2' => '6 kHz', '3' => '3 kHz', '4' => 'AUTO (15 kHz)', '5' => 'AUTO (6 kHz)',
                             '6' => 'AUTO (3 kHz)', '7' => '600 Hz', '8' => '300 Hz', '9' => 'AUTO (600 Hz)', 'A' => 'AUTO (300 Hz)'],
                 'opts' => ['0' => 'AUTO', '1' => '15 kHz', '2' => '6 kHz', '3' => '3 kHz', '4' => '600 Hz', '5' => '300 Hz'],
                 'as_opt' => ['1' => '1', '2' => '2', '3' => '3', '4' => '0', '5' => '0', '6' => '0', '7' => '4', '8' => '5', '9' => '0', 'A' => '0'],
                 'set' => fn($v) => "RF0$v;"],
    'frontend'=>['label' => 'IPO / preamp', 'read' => 'PA0;', 'parse' => fn($a) => substr($a, 3, 1),
                 'opts' => ['0' => 'IPO', '1' => 'AMP 1', '2' => 'AMP 2'], 'set' => fn($v) => "PA0$v;"],
    'att'    => ['label' => 'Attenuator', 'read' => 'RA0;', 'parse' => fn($a) => substr($a, 3, 1),
                 'opts' => ['0' => 'Off', '1' => '6 dB', '2' => '12 dB', '3' => '18 dB'], 'set' => fn($v) => "RA0$v;"],
    'agc'    => ['label' => 'AGC', 'read' => 'GT0;', 'parse' => fn($a) => substr($a, 3, 1),
                 'shown' => ['0' => 'Off', '1' => 'Fast', '2' => 'Mid', '3' => 'Slow', '4' => 'AUTO (fast)', '5' => 'AUTO (mid)', '6' => 'AUTO (slow)'],
                 'opts' => ['0' => 'Off', '1' => 'Fast', '2' => 'Mid', '3' => 'Slow', '4' => 'AUTO'],
                 'as_opt' => ['0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '4', '6' => '4'],
                 'set' => fn($v) => "GT0$v;"],
    'nb'     => ['label' => 'Noise blanker', 'read' => 'NB0;', 'parse' => fn($a) => substr($a, 3, 1),
                 'opts' => ['0' => 'Off', '1' => 'On', '2' => 'Wide'], 'set' => fn($v) => "NB0$v;"],
    'nr'     => ['label' => 'Noise reduction', 'read' => 'NR0;', 'parse' => fn($a) => substr($a, 3, 1),
                 'opts' => ['0' => 'Off', '1' => 'On'], 'set' => fn($v) => "NR0$v;"],
    'nrlevel'=> ['label' => 'NR level', 'read' => 'RL0;', 'parse' => fn($a) => (string)(int)substr($a, 3, 2),
                 'range' => [1, 15, 1], 'set' => fn($v) => sprintf('RL0%02d;', (int)$v)],
    'autonotch'=>['label' => 'Auto notch', 'read' => 'BC0;', 'parse' => fn($a) => substr($a, 3, 1),
                 'opts' => ['0' => 'Off', '1' => 'On'], 'set' => fn($v) => "BC0$v;"],
    'notch'  => ['label' => 'Manual notch', 'read' => 'BP00;', 'parse' => fn($a) => (string)(int)substr($a, 4, 3),
                 'opts' => ['0' => 'Off', '1' => 'On'], 'set' => fn($v) => sprintf('BP00%03d;', (int)$v)],
    'notchfreq'=>['label' => 'Notch frequency', 'read' => 'BP01;', 'parse' => fn($a) => (string)((int)substr($a, 4, 3) * 10),
                 'range' => [10, 4000, 10], 'unit' => 'Hz', 'set' => fn($v) => sprintf('BP01%03d;', intdiv((int)$v, 10))],
    'processor'=>['label' => 'Speech processor', 'read' => 'PR0;', 'parse' => fn($a) => substr($a, 3, 1), 'readonly' => true,
                 'shown' => ['0' => 'Off', '1' => 'Off', '2' => 'On']],
    'monitor'=> ['label' => 'Monitor', 'read' => 'ML0;', 'parse' => fn($a) => (string)(int)substr($a, 3, 3), 'readonly' => true,
                 'shown' => ['0' => 'Off', '1' => 'On']],
];
// What suits data (VARA, Mercury, Data2G): shown as a hint next to the value
$DATA_HINT = [
    'nb' => ['0', 'Off is best for data: the blanker can chop or distort a modem\'s signal next to strong noise or signals'],
    'nr' => ['0', 'Off for data: noise reduction alters the audio the modems decode'],
    'autonotch' => ['0', 'Off for data: it notches out a modem\'s steady tones'],
    'notch' => ['0', 'Off for data unless a carrier sits in the passband'],
    'processor' => ['0', 'Must be off for data'],
    'monitor' => ['0', 'Off, so the data audio isn\'t fed back'],
    'narrow' => ['0', 'Off: narrow limits the width'],
];

// ── busy? ───────────────────────────────────────────────────────────────────
function busy_state(): array {
    global $HIST_FILE;
    $ptt = flrig_one('rig.get_ptt');
    if ($ptt !== null && (int)$ptt !== 0) return ['busy' => true, 'why' => 'the radio is transmitting'];
    // last transmit sample (rig-history-poller writes one every 3 s while transmitting)
    $fh = @fopen($HIST_FILE, 'rb');
    if ($fh) {
        $sz = (int)@filesize($HIST_FILE);
        fseek($fh, max(0, $sz - 20000));
        $tail = (string)fread($fh, 20000);
        fclose($fh);
        foreach (array_reverse(explode("\n", trim($tail))) as $l) {
            $j = json_decode($l, true);
            if (!is_array($j) || empty($j['tx'])) continue;
            $age = time() - (int)strtotime((string)$j['ts']);
            if ($age >= 0 && $age < 45) return ['busy' => true, 'why' => "the radio transmitted {$age} s ago (a modem session may be running)"];
            break;
        }
    }
    $cache = __DIR__ . '/cache';
    $m = bpq_mercury_status($cache);
    if (($m['status'] ?? '') === 'session') return ['busy' => true, 'why' => 'Mercury is in a session' . (!empty($m['session']['peer']) ? ' with ' . $m['session']['peer'] : '')];
    $d = bpq_data2g_status($cache);
    if (($d['status'] ?? '') === 'session') return ['busy' => true, 'why' => 'Data2G is in a session' . (!empty($d['session']['peer']) ? ' with ' . $d['session']['peer'] : '')];
    return ['busy' => false, 'why' => null];
}
function authed(): bool {
    return !empty($_SESSION['vara_authed']) && $_SESSION['vara_authed'] === true
        && !empty($_SESSION['vara_auth_time']) && (time() - $_SESSION['vara_auth_time']) < 3600;
}

function read_all(): array {
    global $S, $DATA_HINT;
    $vals = [];
    foreach ($S as $k => $s) {
        $a = cat_read($s['read']);
        $v = $a === null ? null : ($s['parse'])($a);
        $shown = $v === null ? null : ($s['shown'][$v] ?? $s['opts'][$v] ?? ($v . (isset($s['unit']) ? ' ' . $s['unit'] : '')));
        $vals[$k] = ['value' => $v, 'shown' => $shown, 'raw' => $a,
                     'opt' => $v === null ? null : ($s['as_opt'][$v] ?? $v)];
        if (isset($DATA_HINT[$k]) && $v !== null) {
            $good = $k === 'processor' ? in_array($v, ['0', '1'], true) : $v === $DATA_HINT[$k][0];
            $vals[$k]['hint'] = ['good' => $good, 'text' => $DATA_HINT[$k][1]];
        }
    }
    return $vals;
}
function widths(): array {
    $v = flrig_values(flrig('rig.get_bws'));
    $list = [];
    foreach ($v as $x) if (ctype_digit($x)) $list[] = (int)$x;
    return $list;
}
/** A value as the page shows it ("AUTO (mid)", "-120 Hz"); $isOpt for the set codes. */
function label_for(string $key, $v, bool $isOpt = false): ?string {
    global $S;
    if ($v === null) return null;
    $v = (string)$v;
    if ($key === 'width') return "$v Hz";
    if ($key === 'power') return "$v W";
    $s = $S[$key] ?? [];
    if ($isOpt && isset($s['opts'][$v])) return $s['opts'][$v];
    return $s['shown'][$v] ?? $s['opts'][$v] ?? ($v . (isset($s['unit']) ? ' ' . $s['unit'] : ''));
}
function log_change(string $key, $from, $to, ?string $after): void {
    global $LOG_FILE, $S;
    $e = ['ts' => gmdate('Y-m-d H:i:s'), 'key' => $key,
          'label' => $key === 'width' ? 'Width' : ($key === 'power' ? 'RF power' : ($S[$key]['label'] ?? $key)),
          'from' => label_for($key, $from), 'to' => label_for($key, $to, true), 'now' => label_for($key, $after),
          'ip' => $_SERVER['REMOTE_ADDR'] ?? '?'];
    @file_put_contents($LOG_FILE, json_encode($e) . "\n", FILE_APPEND | LOCK_EX);
    error_log(sprintf('[rig-control] %s: %s -> %s requested from %s, radio now %s', $e['label'], $e['from'] ?? '?', $e['to'], $e['ip'], $e['now'] ?? '?'));
}

// ── actions ─────────────────────────────────────────────────────────────────
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $input['action'] ?? 'state';

// v1.5.66: config.php 'radio' => ['settings_panel' => false] turns the card off
// (default 'auto': shown only when flrig reports an FTdx-3000)
$PANEL = $CONFIG['radio']['settings_panel'] ?? 'auto';
if ($PANEL === false || $PANEL === 0 || (is_string($PANEL) && in_array(strtolower($PANEL), ['false', 'off', 'no', '0'], true))) {
    if ($action === 'set') bad('Radio settings are turned off in config.php (radio.settings_panel)', 403);
    out(['disabled' => true, 'supported' => false]);
}

$model = flrig_one('rig.get_xcvr');
if ($model === null) bad("flrig did not answer at $FLRIG_HOST:$FLRIG_PORT", 502, ['flrig' => false]);
$supported = (bool)preg_match('/FT-?DX\s*3000/i', $model);

if ($action === 'meters') {
    if (!$supported) out(['supported' => false, 'model' => $model]);
    $m = [];
    foreach (['comp' => 3, 'alc' => 4, 'po' => 5, 'swr' => 6] as $k => $n) {
        $a = cat_read("RM$n;");
        $m[$k] = $a === null ? null : (int)substr($a, 3, 3);
    }
    $ptt = flrig_one('rig.get_ptt');
    out(['meters' => $m, 'ptt' => $ptt === null ? null : (int)$ptt !== 0, 'at' => gmdate('H:i:s')]);
}

if ($action === 'log') {
    $lines = @file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $show = authed();                                      // addresses only for the unlocked sysop
    out(['log' => array_reverse(array_values(array_filter(array_map(function ($l) use ($show) {
        $e = json_decode($l, true);
        if (is_array($e) && !$show) unset($e['ip']);
        return $e;
    }, array_slice($lines, -25)))))]);
}

if ($action === 'state') {
    if (!$supported) out(['supported' => false, 'model' => $model]);
    $mode = flrig_one('rig.get_mode');
    $bw = flrig_one('rig.get_bw');
    $pwr = flrig_one('rig.get_power');
    $vals = read_all();
    $vals['width'] = ['value' => $bw, 'shown' => $bw !== null ? $bw . ' Hz' : null,
                      'hint' => $bw !== null ? ['good' => (int)$bw >= 2400, 'text' => 'Data2G and Mercury use up to 2300 Hz; VARA HF 2300 Hz in its wide mode: 2400 Hz keeps all of it'] : null];
    $vals['power'] = ['value' => $pwr, 'shown' => $pwr !== null ? (int)$pwr . ' W' : null];
    out(['supported' => true, 'model' => $model, 'mode' => $mode, 'values' => $vals, 'widths' => widths(),
         'power_range' => [$PWR_MIN, $PWR_CAP > 0 ? min(100, $PWR_CAP) : 100],
         'settings' => array_map(fn($s) => array_intersect_key($s, array_flip(['label', 'opts', 'range', 'unit', 'readonly'])), $S),
         'busy' => busy_state(), 'authed' => authed()]);
}

if ($action === 'set') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST only', 405);
    if (!$supported) bad("Not supported for $model");
    if (!authed()) bad('Locked: unlock the Rig page first (🔒 in Radio Control)', 403);
    $b = busy_state();
    if ($b['busy']) bad('Not now: ' . $b['why'] . '. Try again when the radio is idle.', 409, ['busy' => $b]);
    $key = (string)($input['key'] ?? '');
    $val = (string)($input['value'] ?? '');

    if ($key === 'width') {
        $list = widths();
        $i = array_search((int)$val, $list, true);
        if ($i === false) bad('Width must be one of: ' . implode(', ', $list) . ' Hz');
        $before = flrig_one('rig.get_bw');
        cat_set(sprintf('SH0%02d;', $i + 1));                // flrig's list, 1-based, is the radio's SH index
        usleep(500000);
        $after = flrig_one('rig.get_bw');
        log_change('width', $before, (string)$list[$i], $after);
        if ((int)$after !== $list[$i]) bad("The radio reports $after Hz after asking for {$list[$i]} Hz", 502);
        out(['key' => $key, 'now' => $after]);
    }
    if ($key === 'power') {
        $w = (int)$val;
        [$lo, $hi] = [$PWR_MIN, $PWR_CAP > 0 ? min(100, $PWR_CAP) : 100];
        if ($w < $lo || $w > $hi) bad("Power must be $lo–$hi W" . ($PWR_CAP > 0 ? ' (config.php radio.max_power)' : ''));
        $before = flrig_one('rig.get_power');
        if (flrig('rig.set_power', [$w]) === false) cat_set(sprintf('PC%03d;', $w));
        usleep(400000);
        $after = flrig_one('rig.get_power');
        log_change('power', $before, (string)$w, $after);
        out(['key' => $key, 'now' => $after]);
    }
    $s = $S[$key] ?? null;
    if (!$s || !empty($s['readonly'])) bad('Unknown or read-only setting');
    if (isset($s['opts'])) {
        if (!array_key_exists($val, $s['opts'])) bad('Value must be one of: ' . implode(', ', $s['opts']));
    } elseif (isset($s['range'])) {
        [$lo, $hi, $step] = $s['range'];
        if (!preg_match('/^-?\d+$/', $val) || (int)$val < $lo || (int)$val > $hi || ((int)$val - $lo) % $step) bad("Value must be $lo to $hi in steps of $step");
    }
    $a0 = cat_read($s['read']);
    $before = $a0 === null ? null : ($s['parse'])($a0);
    if ($before !== null && ($s['as_opt'][$before] ?? $before) === $val) out(['key' => $key, 'now' => $before, 'unchanged' => true]);
    cat_set(($s['set'])($val));
    usleep(350000);
    $a1 = cat_read($s['read']);
    $after = $a1 === null ? null : ($s['parse'])($a1);
    $afterOpt = $after === null ? null : ($s['as_opt'][$after] ?? $after);
    log_change($key, $before, $val, $after);
    if ($afterOpt !== $val) bad("The radio reports " . ($after ?? 'nothing') . " after the change", 502, ['now' => $after]);
    out(['key' => $key, 'now' => $after]);
}

bad('Unknown action');
