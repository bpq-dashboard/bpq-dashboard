<?php
/**
 * vara-api.php — VARA HF Terminal API
 * BPQ Dashboard v1.5.14
 *
 * Radio-agnostic version — supports Yaesu, Icom, Kenwood, Elecraft via flrig
 *
 * Endpoints:
 *   POST action=auth          — verify sysop password, set session
 *   POST action=check_auth    — check if session is authenticated
 *   GET  action=list          — list allowed stations (auth required)
 *   POST action=add           — add callsign to allowlist (auth required)
 *   POST action=toggle        — enable/disable callsign (auth required)
 *   POST action=delete        — remove callsign (auth required)
 *   POST action=request       — email sysop for access (no auth; service=chat for the Chat page)
 *   POST action=set_freq      — QSY radio to frequency (auth required)
 *   GET  action=get_freq      — get current frequency (auth required)
 *   GET  action=get_rig_info  — full rig status for rig-status.html
 *   GET  action=get_rig_history — TX power / SWR history for rig-status.html charts
 *   GET  action=get_rig_model — detect radio model from flrig
 *   GET  action=get_scan_freqs — FLRIG radio's scan schedule from bpq32.cfg (read-only)
 *   GET  action=get_power     — TX power setting / maximum from flrig
 *   POST action=set_power     — set TX power via flrig (auth required)
 *   POST action=restore_freq  — restore RADIO 2 scan after VARA session
 *   GET  action=status        — daemon heartbeat status (auth required)
 */

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$SKIP_BBS_CHECK = true;
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bpq-radio.php';
if (file_exists(__DIR__.'/dashboard-db.php')) require_once __DIR__.'/dashboard-db.php';

// ── Config — overridden by config.php below ───────────────────────
$SYSOP_PASS       = 'YOURPASSWORD';
$SYSOP_EMAIL      = 'sysop@example.com';
$FLRIG_HOST       = '127.0.0.1';
$FLRIG_PORT       = 12345;
$DAEMON_FILE      = __DIR__ . '/cache/vara-sessions/vara-daemon.json';
$BPQ32_CFG        = '/home/linbpq/bpq32.cfg';
$BPQ32_BACKUP     = __DIR__ . '/cache/vara-sessions/bpq32-radio-backup.txt';
$RIGRECONFIG_USER = 'YOURCALL';
$RIGRECONFIG_PASS = 'YOURPASSWORD';

// ── Smartplug config (NP-05B) ────────────────────────────────────
// 2026-10-27: no built-in address any more (it used to try 192.168.1.100 with
// admin/admin on every install). Set config.php 'smartplug' => ['host' => …].
$PLUG_HOST    = '';
$PLUG_USER    = 'admin';
$PLUG_PASS    = '';
$PLUG_OUTLET  = 1;
$PLUG_TIMEOUT = 5;

if (!empty($CONFIG['smartplug']['host']))    $PLUG_HOST    = $CONFIG['smartplug']['host'];
if (!empty($CONFIG['smartplug']['user']))    $PLUG_USER    = $CONFIG['smartplug']['user'];
if (!empty($CONFIG['smartplug']['pass']))    $PLUG_PASS    = $CONFIG['smartplug']['pass'];
if (!empty($CONFIG['smartplug']['outlet']))  $PLUG_OUTLET  = (int)$CONFIG['smartplug']['outlet'];

// Load from config.php
if (!empty($CONFIG['bbs']['pass']))      $SYSOP_PASS       = $CONFIG['bbs']['pass'];
if (!empty($CONFIG['bbs']['user']))      $RIGRECONFIG_USER = $CONFIG['bbs']['user'];
if (!empty($CONFIG['station']['email'])) $SYSOP_EMAIL      = $CONFIG['station']['email'];
if (!empty($CONFIG['radio']['flrig_host'])) $FLRIG_HOST    = $CONFIG['radio']['flrig_host'];
if (!empty($CONFIG['radio']['flrig_port'])) $FLRIG_PORT    = (int)$CONFIG['radio']['flrig_port'];
if (!empty($CONFIG['paths']['linbpq']))  $BPQ32_CFG        = rtrim($CONFIG['paths']['linbpq'],'/') . '/bpq32.cfg';

// 2026-10-05: which bpq32.cfg RADIO block the Rig page lists (0 = the
// FLRIG block whose host:port matches flrig_host/flrig_port, else the
// first FLRIG block), and an optional ceiling for web power changes
// (0 = the radio's own maximum as reported by flrig).
$BPQ_RADIO_NUM   = (int)($CONFIG['radio']['bpq_radio'] ?? 0);
$TX_POWER_CAP    = (int)($CONFIG['radio']['max_power'] ?? 0);
$TX_POWER_MIN    = max(1, (int)($CONFIG['radio']['min_power'] ?? 1));

// ── Rig history log (TX power / SWR) ───────────────────────────────
// Appended to by get_rig_info on every poll (throttled to 1 entry/min),
// read back by get_rig_history for the 24h/7d/30d charts on rig-status.html.
// JSON-Lines format (one compact JSON object per line) so concurrent
// polls can safely append without reading/rewriting the whole file.
$RIG_HISTORY_DIR  = rtrim($CONFIG['paths']['datalog'] ?? __DIR__ . '/logs', '/');
$RIG_HISTORY_FILE = $RIG_HISTORY_DIR . '/rig-history.jsonl';
$RIG_HISTORY_MAX_DAYS   = 30;   // prune anything older than this
$RIG_HISTORY_MIN_GAP_S  = 60;   // minimum seconds between logged samples

// ── Radio profile table ───────────────────────────────────────────
/**
 * Profiles for known radios.
 *
 * Keys (case-insensitive partial match against rig.get_xcvr response):
 *
 * digital_mode:
 *   null   = do NOT switch mode — leave radio in whatever mode it is in
 *            (FTdx3000 PKT-U must never be switched — causes 700Hz VARA offset)
 *   string = mode name to pass to rig.set_mode() before connecting
 *
 * mode_label:
 *   Human-readable label shown in the UI
 *
 * freq_response:
 *   'plain'  = <value>7103200</value>  (FTdx3000, some older Yaesu)
 *   'double' = <double>7103200</double> (most modern radios)
 *   'string' = <string>7103200</string> (some Kenwood)
 *   'auto'   = try all three (safe default)
 *
 * bpq32_mode:
 *   The mode string written into the bpq32.cfg RADIO block when rewriting
 *   the scan frequency for VARA. Must match what LinBPQ/flrig expects.
 *
 * note:
 *   Shown in maintenance page for reference.
 */
$RADIO_PROFILES = [
    // ── Yaesu ─────────────────────────────────────────────────────
    'FTdx3000' => [
        'make'          => 'Yaesu',
        'digital_mode'  => null,       // PKT-U — do NOT switch (700Hz offset if switched to USB)
        'mode_label'    => 'PKT-U',
        'bpq32_mode'    => 'PKT-U',
        'freq_response' => 'plain',    // returns bare <value>Hz</value>
        'note'          => 'Leave in PKT-U — switching to USB causes 700Hz carrier offset with VARA',
    ],
    'FTdx10' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FTdx101' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-991' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'FT-991 and FT-991A — use DATA-U',
    ],
    'FT-710' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-891' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-450' => [
        'make'          => 'Yaesu',
        'digital_mode'  => 'DATA-U',
        'mode_label'    => 'DATA-U',
        'bpq32_mode'    => 'DATA-U',
        'freq_response' => 'auto',
        'note'          => 'Use DATA-U mode for VARA HF',
    ],
    'FT-897' => [
        'make'          => 'Yaesu',
        'digital_mode'  => null,       // PKT-U already correct on this radio
        'mode_label'    => 'PKT-U',
        'bpq32_mode'    => 'PKT-U',
        'freq_response' => 'auto',
        'note'          => 'FT-897/FT-857 — PKT-U is correct, no mode switch needed',
    ],
    'FT-857' => [
        'make'          => 'Yaesu',
        'digital_mode'  => null,
        'mode_label'    => 'PKT-U',
        'bpq32_mode'    => 'PKT-U',
        'freq_response' => 'auto',
        'note'          => 'FT-857 — PKT-U is correct, no mode switch needed',
    ],

    // ── Icom ──────────────────────────────────────────────────────
    'IC-7300' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB',      // IC-7300 has no separate data mode — data via rear ACC
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'double',
        'note'          => 'IC-7300 uses USB mode — data audio via rear ACC connector. No 700Hz offset issue.',
    ],
    'IC-7610' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D (USB Data) mode for VARA HF',
    ],
    'IC-705' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7100' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7600' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7700' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-7800' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode for VARA HF',
    ],
    'IC-9700' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'double',
        'note'          => 'Use USB-D mode — supports HF via transverter',
    ],
    'IC-7200' => [
        'make'          => 'Icom',
        'digital_mode'  => 'USB',
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'double',
        'note'          => 'IC-7200 has no separate data mode — data via rear ACC connector',
    ],

    // ── Kenwood ───────────────────────────────────────────────────
    'TS-590' => [
        'make'          => 'Kenwood',
        'digital_mode'  => 'USB',      // data via ACC2 jack in USB mode
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'string',
        'note'          => 'TS-590S/SG — data via ACC2 jack in USB mode',
    ],
    'TS-890' => [
        'make'          => 'Kenwood',
        'digital_mode'  => 'USB-D',
        'mode_label'    => 'USB-D',
        'bpq32_mode'    => 'USB-D',
        'freq_response' => 'string',
        'note'          => 'TS-890S — use USB-D (Data) mode',
    ],
    'TS-2000' => [
        'make'          => 'Kenwood',
        'digital_mode'  => 'USB',
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'string',
        'note'          => 'TS-2000 — no separate data mode, use USB',
    ],

    // ── Elecraft ──────────────────────────────────────────────────
    'K3' => [
        'make'          => 'Elecraft',
        'digital_mode'  => 'DATA',
        'mode_label'    => 'DATA',
        'bpq32_mode'    => 'DATA',
        'freq_response' => 'auto',
        'note'          => 'Elecraft K3/K3S — use DATA mode',
    ],
    'KX3' => [
        'make'          => 'Elecraft',
        'digital_mode'  => 'DATA',
        'mode_label'    => 'DATA',
        'bpq32_mode'    => 'DATA',
        'freq_response' => 'auto',
        'note'          => 'Elecraft KX3 — use DATA mode',
    ],
    'K4' => [
        'make'          => 'Elecraft',
        'digital_mode'  => 'DATA-USB',
        'mode_label'    => 'DATA-USB',
        'bpq32_mode'    => 'DATA-USB',
        'freq_response' => 'auto',
        'note'          => 'Elecraft K4 — use DATA-USB mode',
    ],
];

// ── Get radio profile (auto-detect or config override) ────────────
function getRadioProfile(): array {
    global $RADIO_PROFILES, $CONFIG;

    // 1. Config override takes priority — sysop can force a specific profile
    if (!empty($CONFIG['radio']['model'])) {
        $model = $CONFIG['radio']['model'];
        $profile = matchRadioProfile($model);
        if ($profile) return $profile;
    }

    // 2. Auto-detect from flrig
    $xcvrRaw = flrigCall('rig.get_xcvr', []);
    if ($xcvrRaw) {
        $xcvr = '';
        if (preg_match('/<string>([^<]+)<\/string>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
        elseif (preg_match('/<value>([^<]+)<\/value>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
        if ($xcvr) {
            $profile = matchRadioProfile($xcvr);
            if ($profile) return $profile;
            // Radio detected but no profile — return safe default
            return defaultProfile($xcvr);
        }
    }

    // 3. No detection possible — safe default (don't switch mode)
    return defaultProfile('Unknown');
}

/**
 * Find the profile for a model name from flrig (rig.get_xcvr) or config.
 *
 * 2026-10-19: matching is now whole-model, longest name first. The old
 * partial match tested the table in order and in both directions, so an
 * FTdx101D matched 'FTdx10' and showed the wrong name. Now:
 *   - case, dashes and spaces are ignored ("FTDX-101D" = "FTdx101D"),
 *   - a profile only matches where the model name starts (so 'K3' can't
 *     match inside "KX3") and only when no digit follows (so 'FTdx10'
 *     can't match "FTdx101D", but 'K3' still matches "K3S" and 'IC-7300'
 *     matches "IC-7300MK2"),
 *   - longer names are tried first, so the most specific profile wins.
 */
function matchRadioProfile(string $model): array|false {
    global $RADIO_PROFILES;
    $model = strtoupper(trim($model));
    if ($model === '') return false;

    $keys = array_keys($RADIO_PROFILES);
    usort($keys, fn($a, $b) => strlen(radioKeyNorm($b)) <=> strlen(radioKeyNorm($a)));

    foreach ($keys as $key) {
        $chars = str_split(radioKeyNorm($key));
        $body  = implode('[-_\s]?', array_map(fn($c) => preg_quote($c, '/'), $chars));
        if (preg_match('/(?<![A-Z0-9])' . $body . '(?![0-9])/', $model)) {
            return array_merge(['model' => $key], $RADIO_PROFILES[$key]);
        }
    }
    return false;
}

/** Profile key with case, dashes and spaces removed: 'IC-7300' -> 'IC7300'. */
function radioKeyNorm(string $key): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($key));
}

function defaultProfile(string $detected): array {
    return [
        'model'         => $detected,
        'make'          => 'Unknown',
        'digital_mode'  => null,       // safe — don't switch mode on unknown radio
        'mode_label'    => 'USB',
        'bpq32_mode'    => 'USB',
        'freq_response' => 'auto',
        'note'          => 'Unknown radio — mode switching disabled for safety. Add profile to vara-api.php.',
    ];
}

// ── Auth helpers ──────────────────────────────────────────────────
function isAuthed(): bool {
    return !empty($_SESSION['vara_authed']) && $_SESSION['vara_authed'] === true
        && !empty($_SESSION['vara_auth_time'])
        && (time() - $_SESSION['vara_auth_time']) < 3600;
}
function requireAuth(): void {
    if (!isAuthed()) { http_response_code(401); die(json_encode(['error'=>'Authentication required'])); }
    // 2026-10-03: sliding session, so a VARA session longer than an hour can
    // still restore the radio when it ends (restore_freq now needs the login).
    $_SESSION['vara_auth_time'] = time();
}
function ok(array $data = []): void { echo json_encode(array_merge(['ok'=>true],$data)); exit; }
function fail(string $msg, int $code=400): void {
    http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit;
}
function validCallsign(string $cs): bool {
    return (bool)preg_match('/^[A-Z0-9]{3,7}(-\d{1,2})?$/', strtoupper(trim($cs)));
}

// ── Rig history (TX power / SWR) ───────────────────────────────────
/**
 * Append one sample to the rig history log, throttled to at most one
 * entry every $RIG_HISTORY_MIN_GAP_S seconds. Called from get_rig_info
 * on every poll — cheap no-op most of the time since it just peeks at
 * the log file's mtime before deciding whether to write.
 *
 * Only logs while transmitting (pwr > 0) OR once per throttle window
 * regardless, so the 30-day view still has a baseline "was online"
 * trace even during long idle/receive periods, not just TX spikes.
 */
function logRigHistory(?int $pwrmeter, ?int $swrmeter, bool $isTx, ?float $mhz = null): void {
    global $RIG_HISTORY_DIR, $RIG_HISTORY_FILE, $RIG_HISTORY_MIN_GAP_S;

    if (!is_dir($RIG_HISTORY_DIR)) @mkdir($RIG_HISTORY_DIR, 0750, true);
    if (!is_dir($RIG_HISTORY_DIR) || !is_writable($RIG_HISTORY_DIR)) return; // fail silently — history is best-effort

    $now = time();

    // Throttle: skip if we logged a non-TX sample too recently. TX samples
    // always get through (at up to 1/sec, the natural poll rate) so brief
    // transmissions and their peak power/SWR are never missed.
    if (!$isTx && file_exists($RIG_HISTORY_FILE)) {
        $age = $now - filemtime($RIG_HISTORY_FILE);
        if ($age < $RIG_HISTORY_MIN_GAP_S) return;
    }

    // mhz/band are OPTIONAL fields (added after this log format's initial
    // release) — older entries simply won't have them; readers must treat
    // their absence as "unknown", not an error. Used by rf-power-monitor.html
    // to tag each historical sample with the band it was taken on, since
    // FLRIG-sourced history has no separate connection-log to correlate
    // against (unlike the old WaveNode-based frequency correlation).
    $band = $mhz !== null ? (validateAmateurFreq($mhz)['band'] ?? null) : null;

    $entry = [
        'ts'   => gmdate('Y-m-d\TH:i:s\Z', $now),
        'pwr'  => $pwrmeter ?? 0,
        'swr'  => $swrmeter,       // null when not transmitting (flrig has no idle SWR reading)
        'tx'   => $isTx,
        'mhz'  => $mhz,
        'band' => $band,
    ];
    @file_put_contents($RIG_HISTORY_FILE, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

    // Occasional lazy prune (~1 in 500 writes, i.e. roughly a few times a
    // day at typical poll rates) instead of checking every single append.
    if (random_int(1, 500) === 1) pruneRigHistory();
}

function pruneRigHistory(): void {
    global $RIG_HISTORY_FILE, $RIG_HISTORY_MAX_DAYS;
    if (!file_exists($RIG_HISTORY_FILE)) return;

    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $RIG_HISTORY_MAX_DAYS * 86400);
    $tmp    = $RIG_HISTORY_FILE . '.tmp';
    $in  = @fopen($RIG_HISTORY_FILE, 'r');
    if (!$in) return;
    $out = @fopen($tmp, 'w');
    if (!$out) { fclose($in); return; }

    while (($line = fgets($in)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (is_array($row) && ($row['ts'] ?? '') >= $cutoff) {
            fwrite($out, $line . "\n");
        }
    }
    fclose($in);
    fclose($out);
    @rename($tmp, $RIG_HISTORY_FILE);
}

/**
 * Read back history entries newer than $days ago, downsampled to keep
 * the response small. Mirrors the pattern used by wavenode-api.php's
 * action=history: preserve TX events even when downsampling everything
 * else, so brief high-SWR spikes during a 30-day view are never
 * silently averaged/skipped away.
 */
function readRigHistory(float $days, int $maxPoints = 1500): array {
    global $RIG_HISTORY_FILE;
    if (!file_exists($RIG_HISTORY_FILE)) return ['entries' => [], 'total' => 0];

    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - (int)round($days * 86400));
    $entries = [];
    $fh = @fopen($RIG_HISTORY_FILE, 'r');
    if (!$fh) return ['entries' => [], 'total' => 0];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (is_array($row) && ($row['ts'] ?? '') >= $cutoff) $entries[] = $row;
    }
    fclose($fh);

    $total = count($entries);
    if ($total > $maxPoints) {
        $step = (int)ceil($total / $maxPoints);
        $sampled = [];
        for ($i = 0; $i < $total; $i += $step) {
            $sampled[] = $entries[$i];
            // Preserve any TX sample within this step window even if it'd
            // otherwise be skipped by the stride.
            for ($j = $i + 1; $j < min($i + $step, $total); $j++) {
                if (!empty($entries[$j]['tx'])) $sampled[] = $entries[$j];
            }
        }
        usort($sampled, fn($a, $b) => strcmp($a['ts'], $b['ts']));
        $entries = $sampled;
    }

    return ['entries' => $entries, 'total' => $total];
}

// ── ITU Region 2 HF band validation ──────────────────────────────
function validateAmateurFreq(float $mhz): array|false {
    $bands = [
        ['160m', 1.800, 2.000],   ['80m',  3.525, 4.000],
        ['60m',  5.330, 5.405],   ['40m',  7.025, 7.300],
        ['30m', 10.100,10.150],   ['20m', 14.025,14.350],
        ['17m', 18.068,18.168],   ['15m', 21.025,21.450],
        ['12m', 24.890,24.990],   ['10m', 28.000,29.700],
    ];
    foreach ($bands as [$band,$low,$high]) {
        if ($mhz >= $low-0.0001 && $mhz <= $high+0.0001)
            return ['band'=>$band,'low'=>$low,'high'=>$high];
    }
    return false;
}

// ── flrig XML-RPC helpers ─────────────────────────────────────────
function flrigCall(string $method, array $params=[]): string|false {
    global $FLRIG_HOST, $FLRIG_PORT;
    $paramXml = '';
    foreach ($params as $p) {
        if (is_int($p))       // 2026-10-05: ints as <i4> (rig.set_power wants an int)
            $paramXml .= "<param><value><i4>{$p}</i4></value></param>";
        elseif (is_float($p))
            $paramXml .= "<param><value><double>{$p}</double></value></param>";
        else
            $paramXml .= "<param><value><string>{$p}</string></value></param>";
    }
    $xml = "<?xml version='1.0'?><methodCall><methodName>{$method}</methodName>"
         . "<params>{$paramXml}</params></methodCall>";
    $ctx = stream_context_create(['http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: text/xml\r\nContent-Length: ".strlen($xml),
        'content'=>$xml, 'timeout'=>3,
    ]]);
    return @file_get_contents("http://{$FLRIG_HOST}:{$FLRIG_PORT}/RPC2", false, $ctx) ?: false;
}

function flrigGetFreq(): float|false {
    global $CONFIG;
    $resp = flrigCall('rig.get_vfo');
    if (!$resp) return false;
    // Different radios/flrig builds reply as <double>, <string>, <i4>, <int>
    // or a bare <value> — flrigNumber() accepts all of them.
    $hz = flrigNumber($resp);
    return ($hz !== null && $hz > 0) ? $hz : false;
}

function flrigSetFreq(float $hz): bool {
    $resp = flrigCall('rig.set_frequency', [(string)(int)$hz]);
    return $resp !== false;
}

function flrigSetMode(string $mode): bool {
    $resp = flrigCall('rig.set_mode', [$mode]);
    return $resp !== false;
}

/** Numeric value of an flrig reply (<i4>/<int>/<double>/<string>/bare), or null. */
function flrigNumber(string|false $resp): ?float {
    if ($resp === false || str_contains($resp, '<fault>')) return null;
    $num = '(-?\d+(?:\.\d*)?(?:[eE][-+]?\d+)?)';
    if (preg_match('/<(?:i4|i8|int|double|string)>\s*' . $num . '\s*<\//', $resp, $m)) return (float)$m[1];
    if (preg_match('/<value>\s*' . $num . '\s*<\/value>/', $resp, $m)) return (float)$m[1];
    return null;
}

/**
 * TX power setting and the ceiling the web page may use.
 * max = the radio's maximum (rig.get_maxpwr), lowered to config
 * radio.max_power when that is set; 100 W when flrig reports nothing.
 */
function flrigPowerInfo(): array {
    global $TX_POWER_CAP, $TX_POWER_MIN;
    $power  = flrigNumber(flrigCall('rig.get_power'));
    $rigMax = flrigNumber(flrigCall('rig.get_maxpwr'));
    $max = ($rigMax !== null && $rigMax > 0) ? (int)round($rigMax) : 100;
    if ($TX_POWER_CAP > 0) $max = min($max, $TX_POWER_CAP);
    return [
        'power'   => $power === null ? null : (int)round($power),
        'max'     => $max,
        'min'     => min($TX_POWER_MIN, $max),
        'rig_max' => $rigMax === null ? null : (int)round($rigMax),
        'capped'  => $TX_POWER_CAP > 0,
    ];
}

function flrigSetPower(int $watts): bool {
    $resp = flrigCall('rig.set_power', [$watts]);
    if ($resp !== false && str_contains($resp, '<fault>')) {
        // Some flrig builds type the parameter as a double — retry that way
        $resp = flrigCall('rig.set_power', [(float)$watts]);
    }
    return $resp !== false && !str_contains($resp, '<fault>');
}

// ── bpq32.cfg RADIO 2 rewrite ────────────────────────────────────
function rewriteRadio2(float $hz, string $cfgPath, string $backupPath, string $radioMode): bool {
    if (!file_exists($cfgPath)) return false;
    $cfg = file_get_contents($cfgPath);
    if (!preg_match('/(^RADIO 2 ?\n[\s\S]*?\*{5})/m', $cfg, $m)) return false;
    $originalBlock = $m[1];
    if (!file_exists($backupPath)) file_put_contents($backupPath, $originalBlock);

    // Extract FLRIG line from original block to preserve correct host/port
    $flrigLine = "FLRIG 127.0.0.1:12345";
    if (preg_match('/^\s*(FLRIG\s+\S+)/m', $originalBlock, $fl)) {
        $flrigLine = trim($fl[1]);
    }

    $mhz = number_format($hz / 1000000, 6, '.', '');
    $newBlock = "RADIO 2\n"
              . " {$flrigLine}\n"
              . " 00:00\n"
              . " 7,{$mhz},{$radioMode}\n"
              . "*****";

    $cfg = str_replace($originalBlock, $newBlock, $cfg);
    file_put_contents($cfgPath, $cfg);
    return true;
}

function restoreRadio2(string $cfgPath, string $backupPath): bool {
    if (!file_exists($backupPath)||!file_exists($cfgPath)) return false;
    $original = file_get_contents($backupPath);
    $cfg      = file_get_contents($cfgPath);
    if (!preg_match('/(RADIO 2\s*\n(?:.*\n)*?\*{3,})/m', $cfg, $m)) return false;
    $cfg = str_replace($m[1], $original, $cfg);
    file_put_contents($cfgPath, $cfg);
    unlink($backupPath);
    return true;
}

// ── RIGRECONFIG via BPQ HTTP management ──────────────────────────
function sendRigReconfig(string $user, string $pass, string $host='127.0.0.1', int $port=8008): bool {
    $base = "http://{$host}:{$port}";
    $auth = base64_encode("{$user}:{$pass}");
    $hdr  = "Authorization: Basic {$auth}\r\nConnection: close\r\n";
    $ctx  = stream_context_create(['http'=>['method'=>'GET','header'=>$hdr,'timeout'=>5]]);
    $html = @file_get_contents("{$base}/Node/Terminal.html", false, $ctx);
    if (!$html) return false;
    if (!preg_match('/TermClose\?(T[0-9A-Fa-f]+)/i', $html, $m)) return false;
    $token = $m[1];
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>$hdr."Content-Type: application/x-www-form-urlencoded\r\n",'content'=>'input=PASSWORD','timeout'=>5]]);
    @file_get_contents("{$base}/TermInput?{$token}", false, $ctx);
    usleep(300000);
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>$hdr."Content-Type: application/x-www-form-urlencoded\r\n",'content'=>'input=RIGRECONFIG','timeout'=>5]]);
    @file_get_contents("{$base}/TermInput?{$token}", false, $ctx);
    usleep(600000);
    $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>$hdr,'timeout'=>5]]);
    $out = @file_get_contents("{$base}/Node/OutputScreen.html?{$token}", false, $ctx);
    return $out && str_contains($out, 'Rigcontrol');
}

// ── Smartplug helpers (NP-05B) ───────────────────────────────────
function plugCmd(string $cmd): string|false {
    global $PLUG_HOST, $PLUG_USER, $PLUG_PASS, $PLUG_TIMEOUT;
    if ($PLUG_HOST === '') return false;          // no power switch configured
    $auth = base64_encode("{$PLUG_USER}:{$PLUG_PASS}");
    $ctx  = stream_context_create(['http' => [
        'timeout' => $PLUG_TIMEOUT,
        'header'  => "Authorization: Basic {$auth}
",
    ]]);
    return @file_get_contents("http://{$PLUG_HOST}/cmd.cgi?{$cmd}", false, $ctx) ?: false;
}

function plugStatus(int $outlet): string {
    global $PLUG_HOST;
    if ($PLUG_HOST === '') return 'none';         // Rig Status shows "Not set up"
    // $A5 returns $A0,XXXXX — rightmost digit = outlet 1
    $resp = plugCmd('%24A5');
    if (!$resp) return 'unknown';
    // Parse: $A0,10001 → outlet states right-to-left
    if (preg_match('/\$A0,(\d+)/', $resp, $m)) {
        $states = str_split(strrev($m[1]));
        $idx    = $outlet - 1;
        return isset($states[$idx]) ? ($states[$idx] === '1' ? 'on' : 'off') : 'unknown';
    }
    return 'unknown';
}

function plugOn(int $outlet): bool {
    plugCmd('%24A3%20' . $outlet . '%201');
    sleep(1);
    return plugStatus($outlet) === 'on';
}

function plugOff(int $outlet): bool {
    plugCmd('%24A3%20' . $outlet . '%200');
    sleep(1);
    return plugStatus($outlet) === 'off';
}

function plugReboot(int $outlet): bool {
    plugCmd('%24A4%20' . $outlet);
    sleep(3);
    return plugStatus($outlet) === 'on';
}

function getPTT(): int {
    $v = flrigNumber(flrigCall('rig.get_ptt', []));
    return $v === null ? 0 : (int)$v;
}

/**
 * A meter reading (rig.get_pwrmeter / get_swrmeter / get_smeter) as an int,
 * or null when flrig didn't answer or the reply couldn't be read.
 * 2026-10-19: was a bare-<value> regex only, which matched the FTdx3000 but
 * returned nothing for radios whose flrig replies use <i4>, <int> or
 * <double> — the RF Power gauge then stayed blank on those radios.
 */
function flrigMeter(string $method): ?int {
    $v = flrigNumber(flrigCall($method, []));
    return $v === null ? null : (int)round($v);
}

/**
 * pollRigStatus() — the actual FLRIG read + history-log work behind
 * action=get_rig_info, pulled out into its own function so it can be
 * called from two places: the HTTP dispatcher below (for rig-status.html
 * / rf-power-monitor.html's live polling) AND rig-history-poller.php,
 * a standalone CLI script that polls independently of any browser tab
 * being open (see that file for why this exists — history used to go
 * silent for hours whenever nobody had the dashboard open).
 *
 * IMPORTANT — false-zero fix: pwrmeter/swrmeter are only ever non-null
 * when FLRIG actually answered and the response matched. A failed/timed-
 * out FLRIG call (flrigCall() returns false) or an unparseable response
 * leaves them null — which must NOT be treated the same as a genuine
 * "0 W" or "0 SWR" reading. This function reports pwr_ok/swr_ok
 * explicitly so callers (both the JSON API response and the history
 * logger) can tell "we don't know" apart from "we know, and it's zero".
 * logRigHistory() is only called when pwr_ok is true, so a transient
 * FLRIG miss no longer writes a false idle/zero sample into the 30-day
 * history — it's simply skipped, and the next successful poll picks
 * back up, rather than permanently recording a fake gap.
 */
function pollRigStatus(): array {
    $hz = flrigGetFreq();
    if ($hz === false) {
        return ['ok' => false, 'error' => 'flrig_unreachable'];
    }

    $profile = getRadioProfile();

    $modeRaw = flrigCall('rig.get_mode', []);
    $mode = $profile['mode_label'];
    if ($modeRaw && preg_match('/<string>([^<]+)<\/string>/', $modeRaw, $mm)) $mode = trim($mm[1]);
    elseif ($modeRaw && preg_match('/<value>([^<]+)<\/value>/', $modeRaw, $mm)) $mode = trim($mm[1]);

    $smeter = flrigMeter('rig.get_smeter');

    $pwrmeter = flrigMeter('rig.get_pwrmeter');
    $pwrOk = ($pwrmeter !== null);
    if (!$pwrOk) {
        error_log('[vara-api] rig.get_pwrmeter failed/unparseable');
    }

    $swrmeter = flrigMeter('rig.get_swrmeter');
    $swrOk = ($swrmeter !== null);
    if (!$swrOk) {
        error_log('[vara-api] rig.get_swrmeter failed/unparseable');
    }

    $ptt      = getPTT();
    $isTx     = ($pwrOk && $pwrmeter > 0);
    $pttState = $ptt === 0 ? 0 : ($isTx ? 1 : 2);

    // Only log to history when we actually have a real power reading this
    // poll. A failed FLRIG call must never masquerade as a verified "0 W,
    // not transmitting" sample.
    if ($pwrOk) {
        logRigHistory($pwrmeter, $swrmeter, $isTx, round($hz / 1000000, 6));
    }

    return [
        'ok'        => true,
        'hz'        => (int)$hz,
        'mhz'       => round($hz / 1000000, 6),
        'mode'      => $mode,
        'smeter'    => $smeter,
        'pwrmeter'  => $pwrmeter,
        'pwr_ok'    => $pwrOk,
        'swrmeter'  => $swrmeter,
        'swr_ok'    => $swrOk,
        'tx'        => $isTx,
        'ptt'       => $ptt,
        'ptt_state' => $pttState,
        'rig_model' => $profile['model'],
        'rig_make'  => $profile['make'],
    ];
}

// ── Request dispatch ──────────────────────────────────────────────
// Guarded so rig-history-poller.php (a CLI script) can safely
// `require_once` this file to reuse pollRigStatus() and friends
// without also triggering the HTTP action dispatcher below.
if (php_sapi_name() === 'cli') {
    return;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $action;

switch ($action) {

    case 'auth':
        $pass = $input['password'] ?? '';
        if (empty($pass)) fail('Password required', 401);
        if ($pass !== $SYSOP_PASS) { sleep(1); fail('Invalid password', 401); }
        $_SESSION['vara_authed']    = true;
        $_SESSION['vara_auth_time'] = time();
        ok(['authenticated'=>true]);

    case 'check_auth':
        ok(['authenticated'=>isAuthed()]);

    case 'logout':
        $_SESSION['vara_authed'] = false;
        unset($_SESSION['vara_auth_time']);
        ok();

    // ── ALLOWLIST ─────────────────────────────────────────────────
    case 'list':
        requireAuth();
        $pdo  = db_get();
        $rows = db_query($pdo, "SELECT * FROM vara_allowed_stations ORDER BY callsign ASC");
        ok(['stations'=>$rows]);

    case 'add':
        requireAuth();
        $cs   = strtoupper(trim($input['callsign'] ?? ''));
        $name = trim($input['name'] ?? '');
        $note = trim($input['notes'] ?? '');
        if (!validCallsign($cs)) fail('Invalid callsign format');
        $pdo = db_get();
        try {
            db_execute($pdo,
                "INSERT INTO vara_allowed_stations (callsign,name,notes,added_by) VALUES (?,?,?,?)",
                [$cs, $name, $note, $CONFIG['station']['callsign'] ?? 'SYSOP']);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(),'Duplicate')) fail("$cs is already in the allowlist");
            fail('Database error: '.$e->getMessage());
        }
        ok(['callsign'=>$cs]);

    case 'toggle':
        requireAuth();
        $id = (int)($input['id'] ?? 0);
        if (!$id) fail('ID required');
        $pdo = db_get();
        db_execute($pdo,"UPDATE vara_allowed_stations SET active=1-active WHERE id=?",[$id]);
        $row = db_query_one($pdo,"SELECT * FROM vara_allowed_stations WHERE id=?",[$id]);
        ok(['station'=>$row]);

    case 'delete':
        requireAuth();
        $id = (int)($input['id'] ?? 0);
        if (!$id) fail('ID required');
        $pdo = db_get();
        db_execute($pdo,"DELETE FROM vara_allowed_stations WHERE id=?",[$id]);
        ok();

    case 'request':
        $cs   = strtoupper(trim($input['callsign'] ?? ''));
        $name = trim($input['name'] ?? '');
        $msg  = trim($input['message'] ?? '');
        if (!validCallsign($cs)) fail('Invalid callsign format');
        // 2026-10-27: also used by the Chat page (service=chat). The sysop's
        // address is no longer returned — this action needs no login.
        $svc  = (($input['service'] ?? '') === 'chat') ? 'BPQ Chat' : 'VARA HF';
        $name = substr(preg_replace('/[\r\n]+/', ' ', $name), 0, 80);
        $msg  = substr(preg_replace('/[\r\n]+/', ' ', $msg), 0, 500);
        if (!filter_var($SYSOP_EMAIL, FILTER_VALIDATE_EMAIL) || preg_match('/@example\.(com|org|net)$/i', $SYSOP_EMAIL)) {
            ok(['sent'=>false, 'error'=>'No sysop e-mail is set on this server (config.php station.email) — contact the sysop directly']);
        }
        // One request per address per 10 minutes (cache/access-requests.json)
        $rl = __DIR__ . '/cache/access-requests.json';
        $seen = is_file($rl) ? (json_decode((string)@file_get_contents($rl), true) ?: []) : [];
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $seen = array_filter($seen, fn($t) => $t > time() - 600);
        if (isset($seen[$ip])) ok(['sent'=>false, 'error'=>'A request was already sent from here in the last 10 minutes']);
        $subject = "$svc Access Request from $cs";
        $body    = "Callsign: $cs\nName: $name\nMessage: $msg\n\nSent from the BPQ Dashboard $svc page — ".gmdate('Y-m-d H:i:s')." UTC";
        $sent = @mail($SYSOP_EMAIL, $subject, $body, "From: dashboard@bpq.local\r\nReply-To: {$cs}@invalid");
        if ($sent) { $seen[$ip] = time(); @file_put_contents($rl, json_encode($seen), LOCK_EX); }
        ok(['sent'=>$sent] + ($sent ? [] : ['error'=>'This server could not send the e-mail — contact the sysop directly']));

    // ── FREQUENCY validate ────────────────────────────────────────
    case 'validate_freq':
        $mhz = (float)($input['mhz'] ?? 0);
        if ($mhz <= 0) fail('Frequency required');
        $result = validateAmateurFreq($mhz);
        if (!$result) fail("$mhz MHz is not in an ITU Region 2 HF data-authorized band");
        ok(['mhz'=>$mhz,'band'=>$result]);

    // ── FREQUENCY set ─────────────────────────────────────────────
    case 'set_freq':
        requireAuth();   // 2026-10-03: rewrites bpq32.cfg RADIO + RIGRECONFIG — sysop only
        $mhz = (float)($input['mhz'] ?? 0);
        if ($mhz <= 0) fail('Frequency (MHz) required');
        $bandInfo = validateAmateurFreq($mhz);
        if (!$bandInfo) fail("$mhz MHz is not in an ITU Region 2 HF data-authorized band");
        $hz = (int)round($mhz * 1000000);

        // Get radio profile to know correct bpq32 mode string
        $profile = getRadioProfile();
        $bpqMode = $profile['bpq32_mode'];

        $cfgOk = rewriteRadio2((float)$hz, $BPQ32_CFG, $BPQ32_BACKUP, $bpqMode);
        $rigOk = false;
        if ($cfgOk) {
            $rigOk = sendRigReconfig($RIGRECONFIG_USER, $RIGRECONFIG_PASS);
        }
        ok(['mhz'=>$mhz,'hz'=>$hz,'band'=>$bandInfo,
            'cfg_rewritten'=>$cfgOk,'rigreconfig_sent'=>$rigOk,
            'radio_mode'=>$bpqMode,'profile'=>$profile['model']]);

    // ── FREQUENCY get ─────────────────────────────────────────────
    case 'get_freq':
        $hz = flrigGetFreq();
        if ($hz === false) fail("Could not reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}");
        $mhz = round($hz / 1000000, 6);
        ok(['hz'=>$hz,'mhz'=>$mhz,'band'=>validateAmateurFreq($mhz) ?: null]);

    // ── RIG MODEL — detect and return profile ─────────────────────
    case 'get_rig_model':
        // Get raw model from flrig
        $xcvrRaw = flrigCall('rig.get_xcvr', []);
        $xcvr    = '';
        if ($xcvrRaw) {
            if (preg_match('/<string>([^<]+)<\/string>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
            elseif (preg_match('/<value>([^<]+)<\/value>/', $xcvrRaw, $m)) $xcvr = trim($m[1]);
        }
        $profile = getRadioProfile();
        ok([
            'detected'   => $xcvr ?: 'unknown',
            'model'      => $profile['model'],
            'make'       => $profile['make'],
            'mode_label' => $profile['mode_label'],
            'note'       => $profile['note'],
            'source'     => !empty($CONFIG['radio']['model']) ? 'config' : ($xcvr ? 'auto-detect' : 'default'),
        ]);

    // ── RIG INFO — full status for rig-status.html ────────────────
    case 'get_rig_info':
        $status = pollRigStatus();
        if (!$status['ok']) fail("Cannot reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}");
        unset($status['ok']);
        ok($status);   // includes pwr_ok/swr_ok — see pollRigStatus() doc comment

    // ── RIG HISTORY — 30-day TX power / SWR for rig-status.html chart ──
    case 'get_rig_history':
        $days = (float)($input['days'] ?? $_GET['days'] ?? 1);
        $days = max(1/24, min($days, 30));   // clamp: 1 hour .. 30 days
        $result = readRigHistory($days);
        ok([
            'days'    => $days,
            'count'   => count($result['entries']),
            'total'   => $result['total'],
            'entries' => $result['entries'],
        ]);

    // ── SCAN FREQUENCIES — FLRIG radio's schedule from bpq32.cfg ──
    // 2026-10-05: replaces the hard-coded list on rig-status.html. Read-only;
    // returns only time bands / frequencies / modes, never other cfg lines.
    case 'get_scan_freqs':
        if (!is_readable($BPQ32_CFG)) {
            fail('Cannot read bpq32.cfg — check config.php paths.linbpq, and that the web server '
               . 'user can read the file', 503);
        }
        $blocks = bpqRadioBlocks((string)file_get_contents($BPQ32_CFG));
        $block  = bpqPickFlrigBlock($blocks, $BPQ_RADIO_NUM ?: null, "{$FLRIG_HOST}:{$FLRIG_PORT}");
        if (!$block) {
            fail($BPQ_RADIO_NUM
                ? "bpq32.cfg has no RADIO {$BPQ_RADIO_NUM} block with an FLRIG line (config.php radio.bpq_radio)"
                : 'bpq32.cfg has no RADIO block with an FLRIG line', 404);
        }
        // A VARA keyboard session pins RADIO 2 to one frequency and keeps the
        // real schedule in the backup file until End Session puts it back.
        $paused = null;
        if ($block['radio'] === 2 && is_readable($BPQ32_BACKUP)) {
            $saved = bpqRadioBlocks((string)file_get_contents($BPQ32_BACKUP));
            if ($saved && ($savedBands = bpqScanBands($saved[0]))) {
                $pinned = bpqScanBands($block);
                $paused = ['mhz' => $pinned[0]['channels'][0]['mhz'] ?? null];
                $block  = $saved[0];
            }
        }
        $bands = bpqScanBands($block);
        foreach ($bands as &$b) {
            foreach ($b['channels'] as &$c) {
                $c['band'] = validateAmateurFreq($c['mhz'])['band'] ?? null;
            }
            unset($c);
        }
        unset($b);
        $nowMin = (int)gmdate('G') * 60 + (int)gmdate('i');
        ok([
            'radio'    => $block['radio'],
            'bands'    => $bands,
            'active'   => bpqActiveBand($bands, $nowMin),
            'paused'   => $paused,
            'utc_now'  => gmdate('H:i'),
            'cfg_time' => gmdate('Y-m-d\TH:i:s\Z', filemtime($BPQ32_CFG)),
        ]);

    // ── TX POWER — read (public) / set (sysop) via flrig ──────────
    case 'get_power':
        $info = flrigPowerInfo();
        if ($info['power'] === null) fail("Cannot read TX power from flrig at {$FLRIG_HOST}:{$FLRIG_PORT}", 502);
        ok($info);

    case 'set_power':
        requireAuth();
        $want = $input['watts'] ?? null;
        if (!is_numeric($want)) fail('watts required');
        $want = (int)round((float)$want);
        $info = flrigPowerInfo();
        if ($info['power'] === null) fail("Cannot reach flrig at {$FLRIG_HOST}:{$FLRIG_PORT}", 502);
        if ($want < $info['min'] || $want > $info['max']) {
            fail("Power must be {$info['min']}–{$info['max']} W" . ($info['capped'] ? ' (config.php radio.max_power)' : ''));
        }
        if (!flrigSetPower($want)) fail('flrig refused the power change', 502);
        usleep(400000);   // let the radio settle before reading back
        $after = flrigNumber(flrigCall('rig.get_power'));
        error_log(sprintf('[vara-api] TX power %s -> %d W requested from %s, rig now %s W',
            $info['power'], $want, $_SERVER['REMOTE_ADDR'] ?? '?', $after === null ? '?' : (int)round($after)));
        ok(array_merge($info, [
            'requested' => $want,
            'power'     => $after === null ? null : (int)round($after),
        ]));

    // ── RESTORE RADIO 2 scan ──────────────────────────────────────
    case 'restore_freq':
        requireAuth();   // 2026-10-03: same as set_freq
        $restored = restoreRadio2($BPQ32_CFG, $BPQ32_BACKUP);
        $rigOk    = false;
        if ($restored) $rigOk = sendRigReconfig($RIGRECONFIG_USER, $RIGRECONFIG_PASS);
        ok(['restored'=>$restored,'rigreconfig_sent'=>$rigOk]);

    // ── DAEMON STATUS ─────────────────────────────────────────────
    case 'status':
        requireAuth();
        if (!file_exists($DAEMON_FILE)) ok(['running'=>false,'message'=>'Daemon not started']);
        $data = json_decode(file_get_contents($DAEMON_FILE), true);
        $age  = time() - ($data['updated_ts'] ?? 0);
        $data['stale'] = $age > 60;
        ok($data);

    // ── SMARTPLUG STATUS ──────────────────────────────────────────────
    case 'plug_status':
        global $PLUG_OUTLET;
        $status = plugStatus($PLUG_OUTLET);
        $ptt    = getPTT();
        $pwr    = flrigMeter('rig.get_pwrmeter') ?? 0;
        $pttState = $ptt === 0 ? 0 : ($pwr > 0 ? 1 : 2);
        ok([
            'outlet'    => $PLUG_OUTLET,
            'power'     => $status,
            'ptt'       => $ptt,
            'ptt_state' => $pttState,
            'pwrmeter'  => $pwr,
        ]);

    // ── SMARTPLUG ON ───────────────────────────────────────────────
    case 'plug_on':
        requireAuth();
        global $PLUG_OUTLET, $PLUG_HOST;
        if ($PLUG_HOST === '') fail("No power switch is set up (config.php 'smartplug')", 409);
        $result = plugOn($PLUG_OUTLET);
        ok(['outlet' => $PLUG_OUTLET, 'power' => plugStatus($PLUG_OUTLET), 'success' => $result]);

    // ── SMARTPLUG OFF ──────────────────────────────────────────────
    case 'plug_off':
        requireAuth();
        global $PLUG_OUTLET, $PLUG_HOST;
        if ($PLUG_HOST === '') fail("No power switch is set up (config.php 'smartplug')", 409);
        // Safety check — block if genuine TX (PTT=1 AND power>0)
        $ptt    = getPTT();
        $pwr    = flrigMeter('rig.get_pwrmeter') ?? 0;
        if ($ptt === 1 && $pwr > 0) {
            fail('Radio is transmitting — cannot power off during TX', 409);
        }
        $result = plugOff($PLUG_OUTLET);
        ok(['outlet' => $PLUG_OUTLET, 'power' => plugStatus($PLUG_OUTLET), 'success' => $result]);

    // ── SMARTPLUG REBOOT ───────────────────────────────────────────
    case 'plug_reboot':
        requireAuth();
        global $PLUG_OUTLET, $PLUG_HOST;
        if ($PLUG_HOST === '') fail("No power switch is set up (config.php 'smartplug')", 409);
        // Block only genuine TX — allow reboot on stuck PTT
        $ptt    = getPTT();
        $pwr    = flrigMeter('rig.get_pwrmeter') ?? 0;
        if ($ptt === 1 && $pwr > 0) {
            fail('Radio is transmitting — cannot reboot during TX', 409);
        }
        $result = plugReboot($PLUG_OUTLET);
        ok(['outlet' => $PLUG_OUTLET, 'power' => plugStatus($PLUG_OUTLET), 'success' => $result]);

    default:
        fail('Unknown action', 404);
}
