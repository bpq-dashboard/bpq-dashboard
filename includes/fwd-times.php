<?php
/**
 * includes/fwd-times.php — forwarding schedule gaps (v1.5.82)
 *
 * A partner's ConnectScript in LinBPQ's linmail.cfg can be split into time
 * bands, each starting with "TIMES hhmm-hhmm" (UTC):
 *     ConnectScript = "TIMES 0000-1059|ATTACH 6|RADIO 3.596 PKT-U|PAUSE|C N3MEL|TIMES 1300-2359|...";
 * Outside every band the script has nothing to do: LinBPQ "connects", finds
 * no band for the time and hangs up, so mail for that partner waits (or
 * leaves another way). A script with no TIMES line runs at any hour.
 * These functions list each partner's bands and the hours none covers.
 */

require_once __DIR__ . '/station-config.php';

/** linmail.cfg's path (config.php paths.linbpq), or '' when not set. */
function bpq_linmail_path(): string
{
    $dir = (string)(bpq_config_section('paths')['linbpq'] ?? '');
    return $dir === '' ? '' : rtrim($dir, '/') . '/linmail.cfg';
}

/** "hhmm" → minutes after midnight, or null. */
function bpq_fwd_minute(string $hhmm): ?int
{
    if (!preg_match('/^(\d{1,2}):?(\d{2})$/', $hhmm, $m)) return null;
    $h = (int)$m[1]; $mi = (int)$m[2];
    return ($h > 23 || $mi > 59) ? null : $h * 60 + $mi;
}

/**
 * The partners in linmail.cfg's BBSForwarding section:
 * [name => ['enabled' => bool|null, 'script' => string, 'bands' => [[from, to], ...] (minutes, inclusive)]]
 */
function bpq_fwd_partners(string $cfg): array
{
    $m = [];
    $region = preg_match('/BBSForwarding\s*:\s*\{/', $cfg, $m, PREG_OFFSET_CAPTURE) ? substr($cfg, $m[0][1] + strlen($m[0][0])) : $cfg;
    // v1.5.83: only up to the section's own closing brace: linmail.cfg's
    // later sections (Housekeeping, UIPORT1…, BBSUsers) aren't partners
    $depth = 0; $inStr = false;
    for ($i = 0, $n = strlen($region); $i < $n; $i++) {
        $ch = $region[$i];
        if ($ch === '"' && ($i === 0 || $region[$i - 1] !== '\\')) $inStr = !$inStr;
        if ($inStr) continue;
        if ($ch === '{') $depth++;
        elseif ($ch === '}') { if ($depth === 0) { $region = substr($region, 0, $i); break; } $depth--; }
    }
    $out = [];
    if (!preg_match_all('/(?m)^\s*([A-Za-z0-9\-]{1,12})\s*:\s*\{([^{}]*)\}/', $region, $bb, PREG_SET_ORDER)) return $out;
    foreach ($bb as $b) {
        $body = $b[2];
        $en = preg_match('/\bEnabled\s*=\s*(\d+)/', $body, $e) ? $e[1] === '1' : null;
        $script = preg_match('/\bConnectScript\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/s', $body, $s) ? stripcslashes($s[1]) : '';
        $bands = [];
        foreach (preg_split('/\||\r?\n/', $script) as $step) {
            if (preg_match('/^\s*TIMES\s+(\d{1,2}:?\d{2})\s*-\s*(\d{1,2}:?\d{2})\s*$/i', $step, $t)) {
                $a = bpq_fwd_minute($t[1]); $z = bpq_fwd_minute($t[2]);
                if ($a !== null && $z !== null) $bands[] = [$a, $z];
            }
        }
        $out[strtoupper($b[1])] = ['enabled' => $en, 'script' => $script, 'bands' => $bands];
    }
    return $out;
}

/**
 * The parts of the day no band covers, as [[from, to], ...] (minutes,
 * inclusive). [] when there are no TIMES bands (runs any time) or they
 * cover the whole day. A band like 2200-0159 runs over midnight.
 */
function bpq_fwd_gaps(array $bands): array
{
    if (!$bands) return [];
    $on = array_fill(0, 1440, false);
    foreach ($bands as [$a, $z]) {
        if ($a <= $z) { for ($i = $a; $i <= $z; $i++) $on[$i] = true; }
        else { for ($i = $a; $i < 1440; $i++) $on[$i] = true; for ($i = 0; $i <= $z; $i++) $on[$i] = true; }
    }
    $gaps = []; $start = null;
    for ($i = 0; $i < 1440; $i++) {
        if (!$on[$i] && $start === null) $start = $i;
        if ($on[$i] && $start !== null) { $gaps[] = [$start, $i - 1]; $start = null; }
    }
    if ($start !== null) $gaps[] = [$start, 1439];
    // a gap at the very end and one at the very start are the same gap over midnight
    if (count($gaps) > 1 && $gaps[0][0] === 0 && end($gaps)[1] === 1439) {
        $last = array_pop($gaps); $gaps[0] = [$last[0], $gaps[0][1]];
    }
    return $gaps;
}

/** [from, to] minutes → "1100-1259Z". */
function bpq_fwd_span(array $g): string
{
    return sprintf('%02d%02d-%02d%02dZ', intdiv($g[0], 60), $g[0] % 60, intdiv($g[1], 60), $g[1] % 60);
}

/** Minutes in a gap (over midnight too). */
function bpq_fwd_len(array $g): int
{
    return $g[0] <= $g[1] ? $g[1] - $g[0] + 1 : 1440 - $g[0] + $g[1] + 1;
}

/**
 * Everything System Audit and Maintenance need:
 * ['readable' => bool, 'path' => string, 'partners' => [name => [... + 'gaps' => [...]]]]
 */
function bpq_fwd_times_report(): array
{
    $path = bpq_linmail_path();
    if ($path === '' || !@is_readable($path)) return ['readable' => false, 'path' => $path, 'partners' => []];
    $ps = bpq_fwd_partners((string)@file_get_contents($path));
    foreach ($ps as &$p) $p['gaps'] = bpq_fwd_gaps($p['bands']);
    unset($p);
    return ['readable' => true, 'path' => $path, 'partners' => $ps];
}
