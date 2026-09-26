<?php
/**
 * system-logs-api.php — JSON API for bpq-system-logs.html's stats/charts
 *
 * Serves pre-parsed data from the sys_, rms_, tcp_, and net_ prefixed
 * tables populated by scripts/parse-system-logs.py. Scope matches that
 * script: STATS/CHARTS ONLY — the raw log viewer on that page keeps
 * fetching and parsing the log files directly, unchanged, so there is
 * deliberately no "raw log lines" endpoint here.
 *
 * Endpoints (selected via ?action=):
 *
 *   ?action=stats[&days=N]
 *      Overall counts: connections, messages (total/forwarded/received/
 *      rejected), errors (total + by category), avg VARA bitrate.
 *
 *   ?action=hourly[&days=N]
 *      Event counts by hour-of-day and event_type, for the Hourly
 *      Activity / Event Types charts.
 *
 *   ?action=top_stations[&days=N][&limit=N]
 *      Top stations by total events, from sys_station_daily.
 *
 *   ?action=station_detail&callsign=X[&days=N]
 *      One station's rolled-up events/connections/messagesTx/messagesRx.
 *
 *   ?action=errors[&days=N][&category=X][&limit=N]
 *      Error breakdown by category, plus the most recent raw error
 *      lines (powers the Recent Errors list + "find in viewer").
 *
 *   ?action=rms[&days=N]
 *      RMS/CMSAccess stats: connection count, bytes sent/received,
 *      login failures, per-station breakdown.
 *
 *   ?action=tcp[&days=N]
 *      TCP stats: session count, POP3/SMTP/NNTP/auth-failure counts.
 *
 *   ?action=nodesrtt | mheard | rtknown
 *      Current snapshot rows (no days/date filtering — these tables
 *      hold current state only, see data/system-logs-schema.sql).
 *
 *   ?action=internet_stations
 *      Cumulative non-RF-connected stations (sys_internet_stations).
 *
 *   ?action=all[&days=N]
 *      Convenience — stats + hourly + top_stations + errors + rms +
 *      tcp + the three snapshots + internet_stations in one round-trip.
 *      This is what the page calls on load.
 *
 * Response format on success: { "ok": true, "data": {...}, "generated_at": "..." }
 * Response format on error:   HTTP 4xx/5xx, { "ok": false, "error": "..." }
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

function action_stats(PDO $pdo): array {
    $days = intparam('days', 7, 1, 365);

    $overall = tprfn_query_one($pdo,
        "SELECT
            SUM(event_type = 'connection')        AS connections,
            SUM(event_type = 'message')            AS messages,
            SUM(event_type = 'message' AND direction = 'out') AS messages_forwarded,
            SUM(event_type = 'message' AND direction = 'in')  AS messages_received,
            SUM(event_type = 'message_rejected')   AS messages_rejected,
            SUM(event_type = 'error')              AS errors,
            SUM(event_type = 'error' AND error_category = 'rf_failure')      AS rf_failures,
            SUM(event_type = 'error' AND error_category = 'node_disappeared') AS node_disappeared,
            SUM(event_type = 'error' AND error_category = 'protocol_error')  AS protocol_errors,
            SUM(event_type = 'error' AND error_category = 'timeout')         AS timeout_errors,
            SUM(event_type = 'error' AND error_category = 'connect_failure') AS connect_failures,
            SUM(event_type = 'error' AND error_category = 'other')           AS other_errors,
            COUNT(DISTINCT callsign)               AS active_stations
         FROM sys_events
         WHERE event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$days]
    ) ?: [];

    $bitrate = tprfn_query_one($pdo,
        "SELECT AVG(bitrate_bps) AS avg_bitrate, MIN(bitrate_bps) AS min_bitrate,
                MAX(bitrate_bps) AS max_bitrate, COUNT(*) AS sample_count
         FROM sys_events
         WHERE event_type = 'vara_bitrate' AND event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$days]
    ) ?: [];

    $date_range = tprfn_query_one($pdo,
        "SELECT MIN(event_time) AS earliest, MAX(event_time) AS latest
         FROM sys_events WHERE event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$days]
    ) ?: [];

    return ['overall' => $overall, 'vara_bitrate' => $bitrate,
            'date_range' => $date_range, 'days' => $days];
}

function action_hourly(PDO $pdo): array {
    $days = intparam('days', 7, 1, 365);

    $rows = tprfn_query($pdo,
        "SELECT event_type, HOUR(event_time) AS hour_utc, COUNT(*) AS count
         FROM sys_events
         WHERE event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY event_type, HOUR(event_time)
         ORDER BY hour_utc",
        [$days]
    );

    return ['cells' => $rows, 'days' => $days];
}

function action_top_stations(PDO $pdo): array {
    $days = intparam('days', 7, 1, 365);
    // station_limit takes precedence over the generic `limit` — this
    // endpoint is also called as part of action=all, which independently
    // needs `limit` to control action_errors()'s recent-errors cap; a
    // single shared `limit` param would leak between the two (station
    // count and error count have very different natural sizes). Standalone
    // ?action=top_stations&limit=N calls still work via the fallback.
    $limit = isset($_GET['station_limit'])
        ? max(1, min(2000, (int)$_GET['station_limit']))
        : intparam('limit', 10, 1, 2000);

    $rows = tprfn_query($pdo,
        "SELECT callsign,
                SUM(events)      AS events,
                SUM(connections) AS connections,
                SUM(messages_tx) AS messages_tx,
                SUM(messages_rx) AS messages_rx
         FROM sys_station_daily
         WHERE activity_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY callsign
         ORDER BY events DESC
         LIMIT ?",
        [$days, $limit]
    );

    return ['stations' => $rows, 'days' => $days];
}

function action_station_detail(PDO $pdo): array {
    $callsign = strtoupper((string)($_GET['callsign'] ?? ''));
    if ($callsign === '') {
        bail(400, 'callsign parameter is required');
    }
    $days = intparam('days', 30, 1, 365);

    $row = tprfn_query_one($pdo,
        "SELECT callsign,
                SUM(events)      AS events,
                SUM(connections) AS connections,
                SUM(messages_tx) AS messages_tx,
                SUM(messages_rx) AS messages_rx
         FROM sys_station_daily
         WHERE callsign = ? AND activity_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY callsign",
        [$callsign, $days]
    );

    return ['station' => $row, 'days' => $days];
}

function action_errors(PDO $pdo): array {
    $days = intparam('days', 7, 1, 365);
    $category = $_GET['category'] ?? null;
    $limit = intparam('limit', 50, 1, 500);

    $breakdown = tprfn_query($pdo,
        "SELECT error_category, COUNT(*) AS count
         FROM sys_events
         WHERE event_type = 'error' AND event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY error_category
         ORDER BY count DESC",
        [$days]
    );

    $where = ["event_type = 'error'", 'event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
    $params = [$days];
    if (!empty($category)) {
        $where[] = 'error_category = ?';
        $params[] = (string)$category;
    }
    $params[] = $limit;

    $recent = tprfn_query($pdo,
        "SELECT event_time, error_category, callsign, raw_line, source_file, log_line_no
         FROM sys_events
         WHERE " . implode(' AND ', $where) . "
         ORDER BY event_time DESC
         LIMIT ?",
        $params
    );

    return ['breakdown' => $breakdown, 'recent' => $recent, 'days' => $days];
}

function action_rms(PDO $pdo): array {
    $days = intparam('days', 7, 1, 365);

    $overall = tprfn_query_one($pdo,
        "SELECT COUNT(*) AS connections,
                SUM(bytes_sent)     AS bytes_sent,
                SUM(bytes_received) AS bytes_received
         FROM rms_connections
         WHERE connect_time >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$days]
    ) ?: [];

    $login_failed = tprfn_query_one($pdo,
        "SELECT COUNT(*) AS count FROM rms_login_failures
         WHERE event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$days]
    ) ?: [];

    $stations = tprfn_query($pdo,
        "SELECT callsign, COUNT(*) AS connections,
                SUM(bytes_sent) AS bytes_sent, SUM(bytes_received) AS bytes_received
         FROM rms_connections
         WHERE connect_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY callsign
         ORDER BY connections DESC",
        [$days]
    );

    return [
        'connections' => (int)($overall['connections'] ?? 0),
        'bytes_sent' => (int)($overall['bytes_sent'] ?? 0),
        'bytes_received' => (int)($overall['bytes_received'] ?? 0),
        'login_failed' => (int)($login_failed['count'] ?? 0),
        'stations' => $stations,
        'days' => $days,
    ];
}

function action_tcp(PDO $pdo): array {
    $days = intparam('days', 7, 1, 365);

    $row = tprfn_query_one($pdo,
        "SELECT
            SUM(event_type = 'pop3')      AS pop3,
            SUM(event_type = 'smtp')      AS smtp,
            SUM(event_type = 'nntp')      AS nntp,
            SUM(event_type = 'auth_fail') AS auth_fail,
            SUM(event_type IN ('pop3','smtp','nntp')) AS sessions
         FROM tcp_events
         WHERE event_time >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$days]
    ) ?: [];

    return ['stats' => $row, 'days' => $days];
}

function action_nodesrtt(PDO $pdo): array {
    $rows = tprfn_query($pdo,
        "SELECT callsign, full_call, rtt_sec, frames, polled_at
         FROM net_nodes_rtt ORDER BY callsign"
    );
    return ['nodes' => $rows];
}

function action_mheard(PDO $pdo): array {
    $rows = tprfn_query($pdo,
        "SELECT port, callsign, heard_at, bytes, status, grid, freq_mhz, polled_at
         FROM net_mheard ORDER BY port, heard_at DESC"
    );
    return ['heard' => $rows];
}

function action_rtknown(PDO $pdo): array {
    $rows = tprfn_query($pdo,
        "SELECT callsign, last_heard, polled_at FROM net_rtknown ORDER BY last_heard DESC"
    );
    return ['known' => $rows];
}

function action_internet_stations(PDO $pdo): array {
    $rows = tprfn_query($pdo,
        "SELECT callsign, last_connect, connect_count
         FROM sys_internet_stations ORDER BY last_connect DESC"
    );
    return ['stations' => $rows];
}

function action_all(PDO $pdo): array {
    return [
        'stats' => action_stats($pdo),
        'hourly' => action_hourly($pdo),
        'top_stations' => action_top_stations($pdo),
        'errors' => action_errors($pdo),
        'rms' => action_rms($pdo),
        'tcp' => action_tcp($pdo),
        'nodesrtt' => action_nodesrtt($pdo),
        'mheard' => action_mheard($pdo),
        'rtknown' => action_rtknown($pdo),
        'internet_stations' => action_internet_stations($pdo),
    ];
}

// ─── Dispatcher ─────────────────────────────────────────────────────────────

$valid_actions = [
    'stats', 'hourly', 'top_stations', 'station_detail', 'errors', 'rms',
    'tcp', 'nodesrtt', 'mheard', 'rtknown', 'internet_stations', 'all',
];
$action = (string)($_GET['action'] ?? 'all');
if (!in_array($action, $valid_actions, true)) {
    $action = 'all';
}

try {
    $pdo = tprfn_db();
} catch (PDOException $e) {
    bail(500, 'Database connection failed: ' . $e->getMessage());
}

try {
    $data = match ($action) {
        'stats'             => action_stats($pdo),
        'hourly'            => action_hourly($pdo),
        'top_stations'      => action_top_stations($pdo),
        'station_detail'    => action_station_detail($pdo),
        'errors'            => action_errors($pdo),
        'rms'               => action_rms($pdo),
        'tcp'               => action_tcp($pdo),
        'nodesrtt'          => action_nodesrtt($pdo),
        'mheard'            => action_mheard($pdo),
        'rtknown'           => action_rtknown($pdo),
        'internet_stations' => action_internet_stations($pdo),
        'all'               => action_all($pdo),
    };
    success($data);
} catch (PDOException $e) {
    bail(500, 'Query failed: ' . $e->getMessage());
}
