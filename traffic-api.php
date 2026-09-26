<?php
/**
 * traffic-api.php — JSON API for the Traffic Report page
 *
 * Returns pre-parsed BPQ Traffic_YYMMDD.txt data from the traffic_stats
 * table populated by scripts/parse-traffic-report.py.
 *
 * Endpoints (selected via ?action= parameter):
 *
 *   ?action=stations[&days=N]
 *      Returns per-station aggregated traffic totals over the trailing
 *      N days (default 28, matching bpq-traffic.html's default "4W"
 *      view). days defaults to 28, maximum 365. Full P/B/T (Personal/
 *      Bulletin/Traffic) granularity is preserved on every field so the
 *      page can compute totals/charts exactly as it always has —
 *      this endpoint does not pre-collapse anything.
 *
 *   ?action=parser_status
 *      Returns traffic_parser_state — when did the parser last run, how
 *      many files/rows it's processed. Same "is the data fresh?"
 *      convenience as rf-connections-api.php's own parser_status.
 *
 *   ?action=all[&days=N]
 *      Convenience — stations + parser_status in one round-trip. This is
 *      what the page uses.
 *
 * Why a separate API file rather than adding actions to
 * rf-connections-api.php: that file is explicitly scoped to the RF
 * Connections page and its own tables (rf_connections/rf_messages),
 * which only ever cover VARA HF traffic. traffic_stats is BPQ's full
 * per-station accounting across every connection type (VARA HF, packet,
 * Telnet, etc.) plus rejected-message counts that don't exist anywhere
 * else in this project — a genuinely different table for a genuinely
 * different page, so it gets its own API file to match.
 *
 * Response format on success:
 *   { "ok": true, "data": {...}, "generated_at": "2026-09-20T18:00:00Z" }
 *
 * Response format on error:
 *   HTTP status 4xx/5xx with { "ok": false, "error": "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/tprfn-db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// ─── Helpers ────────────────────────────────────────────────────────────────

function bail(int $http_code, string $message): never {
    http_response_code($http_code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function success(array $data): void {
    echo json_encode([
        'ok' => true,
        'data' => $data,
        'generated_at' => gmdate('c'),
    ]);
}

function intparam(string $name, int $default, int $min, int $max): int {
    $val = (int)($_GET[$name] ?? $default);
    return max($min, min($max, $val));
}

// ─── Endpoint handlers ──────────────────────────────────────────────────────

/**
 * GET ?action=stations — per-station traffic totals, trailing N days
 *
 * One row per callsign, summed across every station_date in the window.
 * is_bbs uses MAX() so a station flagged (B) on any day in the window
 * shows as BBS for the whole window — same "sticky across days" behavior
 * bpq-traffic.html's client-side aggregation always had.
 */
function action_stations(PDO $pdo): array {
    $days = intparam('days', 28, 1, 365);

    $sql = "SELECT
                callsign,
                MAX(is_bbs)               AS is_bbs,
                SUM(conn_in)               AS conn_in,
                SUM(conn_out)              AS conn_out,
                SUM(msg_rx_p)              AS msg_rx_p,
                SUM(msg_rx_b)              AS msg_rx_b,
                SUM(msg_rx_t)              AS msg_rx_t,
                SUM(msg_tx_p)              AS msg_tx_p,
                SUM(msg_tx_b)              AS msg_tx_b,
                SUM(msg_tx_t)              AS msg_tx_t,
                SUM(bytes_rx_p)            AS bytes_rx_p,
                SUM(bytes_rx_b)            AS bytes_rx_b,
                SUM(bytes_rx_t)            AS bytes_rx_t,
                SUM(bytes_tx_p)            AS bytes_tx_p,
                SUM(bytes_tx_b)            AS bytes_tx_b,
                SUM(bytes_tx_t)            AS bytes_tx_t,
                SUM(rej_in_p)              AS rej_in_p,
                SUM(rej_in_b)              AS rej_in_b,
                SUM(rej_in_t)              AS rej_in_t,
                SUM(rej_out_p)             AS rej_out_p,
                SUM(rej_out_b)             AS rej_out_b,
                SUM(rej_out_t)             AS rej_out_t,
                MIN(station_date)         AS first_date,
                MAX(station_date)         AS last_date
            FROM traffic_stats
            WHERE station_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY callsign
            ORDER BY (SUM(conn_in) + SUM(conn_out)) DESC";

    $rows = tprfn_query($pdo, $sql, [$days - 1]);

    // Cast numeric strings (PDO returns them as strings) and add is_bbs
    // as a real bool for the client's convenience.
    foreach ($rows as &$r) {
        $r['is_bbs'] = ((int)$r['is_bbs']) === 1;
        foreach ($r as $k => $v) {
            if ($k !== 'is_bbs' && $k !== 'callsign' && $k !== 'first_date' && $k !== 'last_date') {
                $r[$k] = (int)$v;
            }
        }
    }
    unset($r);

    return ['stations' => $rows, 'count' => count($rows), 'days' => $days];
}

/**
 * GET ?action=parser_status — when did the parser last run
 */
function action_parser_status(PDO $pdo): array {
    $row = tprfn_query_one(
        $pdo,
        "SELECT files_processed,
                files_total,
                rows_total,
                DATE_FORMAT(last_parsed_at, '%Y-%m-%dT%H:%i:%sZ') AS last_parsed_at,
                TIMESTAMPDIFF(SECOND, last_parsed_at, NOW())      AS age_seconds
         FROM traffic_parser_state
         WHERE id = 1"
    );
    return [
        'state' => $row,
        'fresh' => $row !== null && ((int)$row['age_seconds'] < 86400),  // <24h = fresh (daily files)
    ];
}

/**
 * GET ?action=all — combined response for the page
 */
function action_all(PDO $pdo): array {
    return [
        'stations' => action_stations($pdo),
        'parser'   => action_parser_status($pdo),
    ];
}

// ─── Dispatcher ─────────────────────────────────────────────────────────────

$action = (string)($_GET['action'] ?? 'all');
$allowed = ['stations', 'parser_status', 'all'];
if (!in_array($action, $allowed, true)) {
    $action = 'all';
}

try {
    $pdo = tprfn_db();
} catch (PDOException $e) {
    bail(500, 'Database connection failed: ' . $e->getMessage());
}

try {
    $data = match ($action) {
        'stations'      => action_stations($pdo),
        'parser_status' => action_parser_status($pdo),
        'all'           => action_all($pdo),
    };
    success($data);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "Table") &&
        str_contains($e->getMessage(), "doesn't exist")) {
        bail(503,
            'Schema not yet applied. Run: ' .
            'mysql -u root -p tprfn < data/traffic-schema.sql'
        );
    }
    bail(500, 'Query failed: ' . $e->getMessage());
}
