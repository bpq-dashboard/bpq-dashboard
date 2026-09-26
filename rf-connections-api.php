<?php
/**
 * rf-connections-api.php — JSON API for the RF Connections page
 *
 * Returns pre-parsed VARA HF session data from the rf_connections table
 * populated by scripts/parse-vara-log.py.
 *
 * Endpoints (selected via ?action= parameter):
 *
 *   ?action=sessions[&days=N][&status=success|failed|all][&band=20m]
 *      Returns recent sessions as JSON array. days defaults to 30,
 *      maximum 365. status filter defaults to 'all'.
 *
 *   ?action=heatmap[&days=N]
 *      Returns band-by-hour activity for the band heatmap visualisation.
 *      Aggregated server-side: GROUP BY band, hour-of-day.
 *
 *   ?action=stats[&days=N]
 *      Returns summary stats: total sessions, success rate, byte totals,
 *      avg S/N, top callsigns. Used for the stats cards on the page.
 *
 *   ?action=parser_status
 *      Returns rf_parser_state row — when did the parser last run, how
 *      many sessions total. Useful for "is the data fresh?" indicator.
 *
 *   ?action=all[&days=N]
 *      Convenience — calls sessions + heatmap + stats in one round-trip
 *      and returns combined response. This is what the page uses.
 *
 * Why a single endpoint with action= rather than separate files:
 *   - One include of bpqdash-db.php, one connection pool
 *   - Consistent error handling
 *   - Page does one fetch() call for everything
 *
 * Response format on success:
 *   { "ok": true, "data": {...}, "generated_at": "2026-05-25T11:30:00Z" }
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

function strparam(string $name, string $default, array $allowed = null): string {
    $val = (string)($_GET[$name] ?? $default);
    if ($allowed !== null && !in_array($val, $allowed, true)) {
        return $default;
    }
    return $val;
}

// ─── Endpoint handlers ──────────────────────────────────────────────────────

/**
 * GET ?action=sessions — recent VARA HF sessions
 *
 * Optional filters:
 *   days   how many days back (1-365, default 30)
 *   status all | success | failed (default all)
 *   band   filter to specific band (default no filter)
 *   limit  max rows (1-5000, default 1000)
 *
 * Returns rows sorted newest-first, with timestamps as ISO strings for JS.
 */
function action_sessions(PDO $pdo): array {
    $days = intparam('days', 30, 1, 365);
    $status = strparam('status', 'all', ['all', 'success', 'failed']);
    $band = $_GET['band'] ?? null;
    $limit = intparam('limit', 1000, 1, 5000);

    $where = ['session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
    $params = [$days];

    if ($status === 'success') {
        $where[] = "status = 'success'";
    } elseif ($status === 'failed') {
        $where[] = "status IN ('failed','timeout','aborted','port_in_use','channel_busy')";
    }

    if (!empty($band)) {
        $where[] = 'band = ?';
        $params[] = (string)$band;
    }

    $where_sql = implode(' AND ', $where);
    $params[] = $limit;

    $sql = "SELECT
                id, callsign, base_call, direction,
                DATE_FORMAT(session_start, '%Y-%m-%dT%H:%i:%sZ') AS session_start,
                DATE_FORMAT(session_end,   '%Y-%m-%dT%H:%i:%sZ') AS session_end,
                duration_sec, freq_khz, band, bandwidth,
                avg_snr_db, tx_bytes, rx_bytes, tx_max_bps, rx_max_bps,
                status, failure_reason
            FROM rf_connections
            WHERE $where_sql
            ORDER BY session_start DESC
            LIMIT ?";

    $rows = tprfn_query($pdo, $sql, $params);
    return ['sessions' => $rows, 'count' => count($rows)];
}

/**
 * GET ?action=heatmap — band activity grid
 *
 * Returns rows of (band, hour_utc, day_of_week, session_count, total_bytes)
 * which the front-end uses to render the heatmap. We GROUP BY band, hour
 * of day, and date — letting the page assemble the grid however it wants.
 */
function action_heatmap(PDO $pdo): array {
    $days = intparam('days', 30, 1, 365);

    $sql = "SELECT
                COALESCE(band, 'Unknown') AS band,
                HOUR(session_start)       AS hour_utc,
                DATE(session_start)       AS day,
                COUNT(*)                  AS session_count,
                SUM(tx_bytes + rx_bytes)  AS total_bytes,
                AVG(avg_snr_db)           AS avg_snr_db
            FROM rf_connections
            WHERE session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status = 'success'
            GROUP BY band, HOUR(session_start), DATE(session_start)
            ORDER BY day, hour_utc";

    $rows = tprfn_query($pdo, $sql, [$days]);
    return ['cells' => $rows, 'days' => $days];
}

/**
 * GET ?action=stats — summary cards
 *
 * High-level numbers for the stats cards at the top of the page.
 * Computed entirely server-side so the client just renders.
 */
function action_stats(PDO $pdo): array {
    $days = intparam('days', 30, 1, 365);

    // Overall numbers
    $overall_sql = "SELECT
                COUNT(*)                                            AS total_sessions,
                SUM(status = 'success')                             AS successful,
                SUM(status != 'success')                            AS failed,
                SUM(tx_bytes + rx_bytes)                            AS total_bytes,
                AVG(CASE WHEN status = 'success' THEN avg_snr_db END) AS avg_snr_db,
                COUNT(DISTINCT base_call)                           AS unique_callsigns
            FROM rf_connections
            WHERE session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $overall = tprfn_query_one($pdo, $overall_sql, [$days]) ?: [];

    // Top callsigns by session count
    $top_calls_sql = "SELECT
                base_call,
                COUNT(*)               AS session_count,
                SUM(tx_bytes+rx_bytes) AS total_bytes,
                AVG(avg_snr_db)        AS avg_snr_db
            FROM rf_connections
            WHERE session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status = 'success'
            GROUP BY base_call
            ORDER BY session_count DESC
            LIMIT 10";
    $top_calls = tprfn_query($pdo, $top_calls_sql, [$days]);

    // Sessions per band
    $bands_sql = "SELECT
                COALESCE(band, 'Unknown') AS band,
                COUNT(*)                  AS session_count,
                SUM(tx_bytes+rx_bytes)    AS total_bytes
            FROM rf_connections
            WHERE session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status = 'success'
            GROUP BY band
            ORDER BY session_count DESC";
    $bands = tprfn_query($pdo, $bands_sql, [$days]);

    return [
        'overall'    => $overall,
        'top_calls'  => $top_calls,
        'bands'      => $bands,
        'days'       => $days,
    ];
}

/**
 * GET ?action=parser_status — when did the parser last run
 */
function action_parser_status(PDO $pdo): array {
    $row = tprfn_query_one(
        $pdo,
        "SELECT log_file,
                last_offset,
                last_line_no,
                sessions_total,
                DATE_FORMAT(last_parsed_at, '%Y-%m-%dT%H:%i:%sZ') AS last_parsed_at,
                TIMESTAMPDIFF(SECOND, last_parsed_at, NOW())      AS age_seconds
         FROM rf_parser_state
         LIMIT 1"
    );
    return [
        'state' => $row,
        'fresh' => $row !== null && ((int)$row['age_seconds'] < 600),  // <10min = fresh
    ];
}

/**
 * GET ?action=messages — forwarded FBB message events (added 2026-09-16)
 *
 * Serves the "Forwarded Messages" panel from the DB-backed rf_messages
 * table (populated by parse-vara-log.py's build_forwarded_messages())
 * instead of the page re-parsing the raw BBS logs client-side.
 *
 * Optional filters:
 *   days      how many days back (1-365, default 30)
 *   partner   filter to one forwarding partner base call (default no filter)
 *   msg_type  B | P | T (default no filter)
 *   limit     max rows (1-5000, default 1000)
 *
 * Returns rows sorted newest-first, with timestamps as ISO strings for JS.
 * As of 2026-09-21 each row also carries `medium` (VARA/NETROM/AX.25/
 * TELNET/... or null if uncorrelated — see rf-connections-schema.sql).
 * Filtering to RF-only is left to the client (bpq-rf-connections.html's
 * getFilteredForwardedMessages()), same as its existing days/partner-list
 * filtering, rather than baked in here — other consumers of this
 * endpoint may legitimately want every medium.
 */
function action_messages(PDO $pdo): array {
    $days = intparam('days', 30, 1, 365);
    $partner = $_GET['partner'] ?? null;
    $msg_type = strparam('msg_type', '', ['', 'B', 'P', 'T']);
    $limit = intparam('limit', 1000, 1, 5000);

    $where = ['msg_time >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
    $params = [$days];

    if (!empty($partner)) {
        $where[] = 'partner = ?';
        $params[] = (string)$partner;
    }
    if ($msg_type !== '') {
        $where[] = 'msg_type = ?';
        $params[] = $msg_type;
    }

    $where_sql = implode(' AND ', $where);
    $params[] = $limit;

    $sql = "SELECT
                id, bid, direction, partner, medium, msg_type,
                from_call, to_area, category, size_bytes,
                DATE_FORMAT(msg_time, '%Y-%m-%dT%H:%i:%sZ') AS msg_time
            FROM rf_messages
            WHERE $where_sql
            ORDER BY msg_time DESC
            LIMIT ?";

    $rows = tprfn_query($pdo, $sql, $params);
    return ['messages' => $rows, 'count' => count($rows)];
}

/**
 * GET ?action=all — combined response for the page
 *
 * Returns sessions + heatmap + stats in one shot to minimise round-trips.
 * The RF Connections page calls this on load.
 */
function action_all(PDO $pdo): array {
    return [
        'sessions' => action_sessions($pdo),
        'heatmap'  => action_heatmap($pdo),
        'stats'    => action_stats($pdo),
        'parser'   => action_parser_status($pdo),
        'messages' => action_messages($pdo),
    ];
}

// ─── Dispatcher ─────────────────────────────────────────────────────────────

$action = strparam('action', 'all', [
    'sessions', 'heatmap', 'stats', 'parser_status', 'all', 'messages'
]);

try {
    $pdo = tprfn_db();
} catch (PDOException $e) {
    bail(500, 'Database connection failed: ' . $e->getMessage());
}

try {
    $data = match ($action) {
        'sessions'      => action_sessions($pdo),
        'heatmap'       => action_heatmap($pdo),
        'stats'         => action_stats($pdo),
        'parser_status' => action_parser_status($pdo),
        'all'           => action_all($pdo),
        'messages'      => action_messages($pdo),
    };
    success($data);
} catch (PDOException $e) {
    // Common case: table doesn't exist yet (schema not applied)
    if (str_contains($e->getMessage(), "Table") &&
        str_contains($e->getMessage(), "doesn't exist")) {
        bail(503,
            'Schema not yet applied. Run: ' .
            'mysql -u root -p bpqdash < data/rf-connections-schema.sql'
        );
    }
    bail(500, 'Query failed: ' . $e->getMessage());
}
