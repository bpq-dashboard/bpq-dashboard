<?php
/**
 * includes/bpq-radio.php — BPQ Dashboard v1.5.16
 *
 * Reads the scan schedule of the FLRIG-controlled radio out of bpq32.cfg,
 * so the Rig Status page shows the frequencies LinBPQ really scans instead
 * of a hard-coded list (2026-10-05).
 *
 * A LinBPQ rig-control block looks like this:
 *
 *     RADIO 2
 *      FLRIG 127.0.0.1:12345
 *      00:00
 *      7,3.596000,PKT-U
 *      7,7.103200,PKT-U
 *      12:00
 *      7,14.103000,PKT-U
 *     *****
 *
 * "hh:mm" lines start a time band (LinBPQ runs these on UTC); each
 * "dwell,MHz,mode[,...]" line is one scan channel. Anything else in the
 * block (PTT settings, other keywords, comments) is ignored.
 *
 * Only frequencies, modes, dwell times and time bands are returned — never
 * any other line of bpq32.cfg (it holds every USER password).
 *
 * Used by vara-api.php (action=get_scan_freqs) and install-check.php.
 */

/** Every RADIO block in $text: [ ['radio'=>2, 'flrig'=>'127.0.0.1:12345'|null, 'lines'=>[...]], ... ] */
function bpqRadioBlocks(string $text): array {
    $blocks = [];
    $cur = null;
    foreach (preg_split('/\r\n|\r|\n/', $text) as $raw) {
        $line = trim(preg_replace('/;.*$/', '', $raw));   // strip ; comments
        if ($cur === null) {
            if (preg_match('/^RADIO\s+(\d+)\b/i', $line, $m)) {
                $cur = ['radio' => (int)$m[1], 'flrig' => null, 'lines' => []];
            }
            continue;
        }
        if (preg_match('/^\*{3,}/', $line)) {            // block terminator
            $blocks[] = $cur;
            $cur = null;
            continue;
        }
        if ($line === '') continue;
        if ($cur['flrig'] === null && preg_match('/^FLRIG\s+(\S+)/i', $line, $m)) {
            $cur['flrig'] = $m[1];
            continue;
        }
        $cur['lines'][] = $line;
    }
    return $blocks;   // an unterminated block at EOF is ignored on purpose
}

/** Time bands of one block: [ ['start'=>'00:00','channels'=>[['dwell'=>7,'mhz'=>7.1032,'mode'=>'PKT-U'],...]], ... ] */
function bpqScanBands(array $block): array {
    $bands = [];
    $idx = -1;
    foreach ($block['lines'] as $line) {
        // "hh:mm" (optionally followed by channels on the same line)
        if (preg_match('/^(\d{1,2}):(\d{2})\b\s*(.*)$/', $line, $m)) {
            $bands[] = ['start' => sprintf('%02d:%02d', (int)$m[1], (int)$m[2]), 'channels' => []];
            $idx = count($bands) - 1;
            $line = trim($m[3]);
            if ($line === '') continue;
        }
        foreach (preg_split('/\s+/', $line) as $tok) {
            if (!preg_match('/^(\d+),(\d+(?:\.\d+)?),([A-Za-z0-9\-]+)/', $tok, $c)) continue;
            $mhz = (float)$c[2];
            if ($mhz >= 1000) $mhz /= 1000000;           // given in Hz
            if ($mhz <= 0 || $mhz > 1000) continue;
            if ($idx < 0) {                                // channels before any time line
                $bands[] = ['start' => '00:00', 'channels' => []];
                $idx = 0;
            }
            $bands[$idx]['channels'][] = [
                'dwell' => (int)$c[1],
                'mhz'   => round($mhz, 6),
                'mode'  => strtoupper($c[3]),
            ];
        }
    }
    return array_values(array_filter($bands, fn($b) => !empty($b['channels'])));
}

/** Index into $bands of the band in force at $utcMinutes (minutes since 00:00 UTC). */
function bpqActiveBand(array $bands, int $utcMinutes): int {
    if (!$bands) return -1;
    $starts = array_map(function ($b) {
        [$h, $m] = array_map('intval', explode(':', $b['start']));
        return $h * 60 + $m;
    }, $bands);
    $best = -1; $bestStart = -1;
    foreach ($starts as $i => $s) {
        if ($s <= $utcMinutes && $s > $bestStart) { $best = $i; $bestStart = $s; }
    }
    if ($best >= 0) return $best;
    // Before the first start of the day: the latest band from "yesterday" still runs
    return array_keys($starts, max($starts))[0];
}

/**
 * Pick the FLRIG radio the dashboard talks to.
 *   $want     — config radio.bpq_radio (0/null = automatic)
 *   $hostPort — config flrig host:port, matched against the FLRIG line
 * Returns the block, or null when bpq32.cfg has no FLRIG RADIO block.
 */
function bpqPickFlrigBlock(array $blocks, ?int $want, string $hostPort): ?array {
    $flrig = array_values(array_filter($blocks, fn($b) => $b['flrig'] !== null));
    if (!$flrig) return null;
    if ($want) {
        foreach ($flrig as $b) if ($b['radio'] === $want) return $b;
        return null;
    }
    $norm = fn($hp) => strtolower(preg_replace('/^(localhost|127\.0\.0\.1)(?=:|$)/i', 'local', $hp));
    foreach ($flrig as $b) if ($norm($b['flrig']) === $norm($hostPort)) return $b;
    return $flrig[0];
}
