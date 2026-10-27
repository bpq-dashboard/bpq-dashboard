<?php
/**
 * wavenode-api.php — WaveNode RF Power Monitor API
 * BPQ Dashboard v1.5.15
 *
 * Reads JSON files written by wavenode-reader.py on Windows
 * and synced to the server by wavenode-sync.sh
 *
 * Endpoints:
 *   GET action=live    — current reading from wavenode-live.json
 *   GET action=history — time-range history from wavenode-log.json
 *   GET action=status  — reader status from wavenode-status.json
 *   GET action=summary — peak/avg/min stats for a time range
 */

// Bumped from 128M default. The wavenode-log.json file holds up to 7 days of
// per-second data (~600k entries possible at full saturation); json_decode
// of the full array allocates 5-10x the file size in PHP zvals. This margin
// keeps us safe even on a heavy-traffic day.
ini_set('memory_limit', '512M');

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$SKIP_BBS_CHECK = true;
require_once __DIR__ . '/includes/bootstrap.php';

// Data directory
$DATA_DIR = rtrim($CONFIG['paths']['datalog'] ?? __DIR__ . '/wavenode-logs', '/');

function ok(array $data): void {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}
function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function read_json(string $path): array|null {
    if (!file_exists($path)) return null;
    $age = time() - filemtime($path);
    $data = @json_decode(file_get_contents($path), true);
    return $data ? array_merge($data, ['file_age_sec' => $age]) : null;
}

$action = $_GET['action'] ?? 'live';

switch ($action) {

    // ── Current reading ───────────────────────────────────────────
    case 'live':
        $live = read_json("$DATA_DIR/wavenode-live.json");
        if (!$live) {
            ok([
                'online'      => false,
                'message'     => 'No live data — wavenode-reader.py may not be running',
            ]);
        }
        $age = $live['file_age_sec'] ?? 999;
        $live['online']  = $age < 360;   // stale if >6 minutes old (sync runs every 5 min)
        $live['stale']   = $age >= 360;
        ok($live);

    // ── 7-day history ─────────────────────────────────────────────
    case 'history':
        $hours  = min((int)($_GET['hours'] ?? 24), 168);   // max 7 days
        $path   = "$DATA_DIR/wavenode-log.json";

        if (!file_exists($path)) fail('No log data available');

        $entries = @json_decode(file_get_contents($path), true);
        if (!$entries || !is_array($entries)) fail('Log file unreadable');

        // Filter to requested time range
        $cutoff = (new DateTime("-{$hours} hours", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $filtered = array_values(array_filter($entries, fn($e) => ($e['ts'] ?? '') >= $cutoff));

        // Downsample if too many points — max 2000 for browser performance
        // IMPORTANT: always preserve TX events (fw > 0) — they may be brief
        $count = count($filtered);
        if ($count > 2000) {
            $step    = (int)ceil($count / 2000);
            $sampled = [];
            for ($i = 0; $i < $count; $i += $step) {
                $sampled[] = $filtered[$i];
                // Also add any TX events in this step window that would be skipped
                for ($j = $i + 1; $j < min($i + $step, $count); $j++) {
                    if (($filtered[$j]['fw'] ?? 0) > 0) {
                        $sampled[] = $filtered[$j];
                    }
                }
            }
            // Re-sort after inserting TX events
            usort($sampled, fn($a, $b) => strcmp($a['ts'], $b['ts']));
            $filtered = $sampled;
        }

        ok([
            'hours'   => $hours,
            'count'   => count($filtered),
            'total'   => $count,
            'entries' => $filtered,
        ]);

    // ── Summary stats ─────────────────────────────────────────────
    case 'summary':
        $hours = min((int)($_GET['hours'] ?? 24), 168);
        $path  = "$DATA_DIR/wavenode-log.json";

        if (!file_exists($path)) fail('No log data available');

        $entries = @json_decode(file_get_contents($path), true);
        if (!$entries || !is_array($entries)) fail('Log file unreadable');

        $cutoff   = (new DateTime("-{$hours} hours", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $filtered = array_values(array_filter($entries, fn($e) => ($e['ts'] ?? '') >= $cutoff));

        if (empty($filtered)) ok(['hours' => $hours, 'count' => 0, 'message' => 'No data in range']);

        $fwd_vals  = array_column($filtered, 'fw');
        $ref_vals  = array_column($filtered, 're');
        $pk_vals   = array_column($filtered, 'pk');
        $swr_vals  = array_filter(array_column($filtered, 'swr'), fn($v) => $v >= 1.0);
        $tx_vals   = array_filter($fwd_vals, fn($v) => $v > 0);

        ok([
            'hours'         => $hours,
            'count'         => count($filtered),
            'tx_count'      => count($tx_vals),
            'fwd' => [
                'max'       => $fwd_vals ? max($fwd_vals) : 0,
                'avg'       => $tx_vals  ? round(array_sum($tx_vals) / count($tx_vals), 1) : 0,
                'peak'      => $pk_vals  ? max($pk_vals)  : 0,
            ],
            'ref' => [
                'max'       => $ref_vals ? max($ref_vals) : 0,
                'avg'       => $ref_vals ? round(array_sum(array_filter($ref_vals, fn($v) => $v > 0)) / max(1, count(array_filter($ref_vals, fn($v) => $v > 0))), 1) : 0,
            ],
            'swr' => [
                'max'       => $swr_vals ? round(max($swr_vals), 2) : 1.0,
                'avg'       => $swr_vals ? round(array_sum($swr_vals) / count($swr_vals), 2) : 1.0,
            ],
            'first_ts'      => $filtered[0]['ts']                    ?? null,
            'last_ts'       => $filtered[count($filtered)-1]['ts']   ?? null,
        ]);

    // ── Reader status ─────────────────────────────────────────────
    case 'status':
        $status = read_json("$DATA_DIR/wavenode-status.json");
        if (!$status) ok(['running' => false, 'message' => 'No status file']);
        $age = $status['file_age_sec'] ?? 999;
        $status['online'] = ($status['running'] ?? false) && $age < 600;
        ok($status);

    default:
        fail('Unknown action', 404);
}
