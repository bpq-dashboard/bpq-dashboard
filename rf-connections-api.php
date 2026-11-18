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
require_once __DIR__ . '/includes/station-config.php';   // bpq_linbpq_ports() (v1.5.23)
require_once __DIR__ . '/includes/mercury-status.php';   // Mercury HF tile (v1.5.43)
require_once __DIR__ . '/includes/data2g-status.php';    // Data2G HF tile (v1.5.56)

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
 * v1.5.46: rf_connections has a modem column (vara | mercury) once
 * sql/2026-11-14-rf-connections-modem.sql has run. Until then every row is
 * VARA and the column names below are left out.
 */
function rf_cols(PDO $pdo): array {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        try {
            $st = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_connections'");
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $cols[$c] = true;
        } catch (Throwable $e) { /* leave empty */ }
    }
    return $cols;
}
function rf_vara_only(PDO $pdo): string {
    return isset(rf_cols($pdo)['modem']) ? " AND modem = 'vara'" : '';
}
/** The headline set: VARA, plus Mercury when mercury.in_headlines and
 *  Data2G when data2g.in_headlines (v1.5.56); never VHF (v1.5.51). */
function rf_headline_modems(PDO $pdo): string {
    if (!isset(rf_cols($pdo)['modem'])) return '';
    $m = ["'vara'"];
    if (rf_mercury_in_headlines()) $m[] = "'mercury'";
    if (rf_data2g_in_headlines())  $m[] = "'data2g'";
    return count($m) > 1 ? ' AND modem IN (' . implode(',', $m) . ')' : " AND modem = 'vara'";
}
/** config.php data2g.in_headlines (default false), as for Mercury (v1.5.56). */
function rf_data2g_in_headlines(): bool {
    $c = function_exists('bpq_config_section') ? bpq_config_section('data2g') : [];
    return filter_var($c['in_headlines'] ?? false, FILTER_VALIDATE_BOOLEAN);
}
/** config.php mercury.in_headlines (default false): Mercury rows count in
 *  the RF Connections headline numbers too. */
function rf_mercury_in_headlines(): bool {
    $c = function_exists('bpq_config_section') ? bpq_config_section('mercury') : [];
    return filter_var($c['in_headlines'] ?? false, FILTER_VALIDATE_BOOLEAN);
}


/**
 * GET ?action=sessions — recent VARA HF (and Mercury, VHF, v1.5.56 Data2G) sessions
 *
 * Optional filters:
 *   days   how many days back (1-365, default 30)
 *   status all | success | failed (default all)
 *   band   filter to specific band (default no filter)
 *   limit  max rows (1-5000, default 1000)
 *   modem  all | vara | mercury | vhf | data2g (v1.5.46, default all)
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

    // v1.5.46: modem / link_mode / end_reason (Mercury rows), when the
    // migration has run; ?modem=vara|mercury narrows to one.
    $cols = rf_cols($pdo);
    $extra = '';
    foreach (['modem', 'link_mode', 'end_reason', 'link_sec'] as $c) {   // link_sec v1.5.64
        $extra .= isset($cols[$c]) ? ", $c" : ", NULL AS $c";
    }
    if (isset($cols['modem'])) {
        $modem = strparam('modem', 'all', ['all', 'vara', 'mercury', 'vhf', 'data2g']);
        if ($modem !== 'all') { $where[] = 'modem = ?'; $params[] = $modem; }
    }

    $where_sql = implode(' AND ', $where);
    $params[] = $limit;

    $sql = "SELECT
                id, callsign, base_call, direction,
                DATE_FORMAT(session_start, '%Y-%m-%dT%H:%i:%sZ') AS session_start,
                DATE_FORMAT(session_end,   '%Y-%m-%dT%H:%i:%sZ') AS session_end,
                duration_sec, freq_khz, band, bandwidth,
                avg_snr_db, tx_bytes, rx_bytes, tx_max_bps, rx_max_bps,
                status, failure_reason$extra
            FROM rf_connections
            WHERE $where_sql
            ORDER BY session_start DESC
            LIMIT ?";

    $rows = tprfn_query($pdo, $sql, $params);
    if (!isset($cols['modem'])) {
        foreach ($rows as &$r) { $r['modem'] = 'vara'; }
        unset($r);
    }
    return ['sessions' => $rows, 'count' => count($rows),
            'mercury_in_headlines' => rf_mercury_in_headlines(),
            'data2g_in_headlines' => rf_data2g_in_headlines()];
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
    $vo = rf_headline_modems($pdo);   // v1.5.55

    $sql = "SELECT
                COALESCE(band, 'Unknown') AS band,
                HOUR(session_start)       AS hour_utc,
                DATE(session_start)       AS day,
                COUNT(*)                  AS session_count,
                SUM(tx_bytes + rx_bytes)  AS total_bytes,
                AVG(avg_snr_db)           AS avg_snr_db
            FROM rf_connections
            WHERE session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status = 'success'$vo
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
    $vo = rf_headline_modems($pdo);   // v1.5.46: headline numbers are VARA

    // Overall numbers
    $overall_sql = "SELECT
                COUNT(*)                                            AS total_sessions,
                SUM(status = 'success')                             AS successful,
                SUM(status != 'success')                            AS failed,
                SUM(tx_bytes + rx_bytes)                            AS total_bytes,
                AVG(CASE WHEN status = 'success' THEN avg_snr_db END) AS avg_snr_db,
                COUNT(DISTINCT base_call)                           AS unique_callsigns
            FROM rf_connections
            WHERE session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)$vo";
    $overall = tprfn_query_one($pdo, $overall_sql, [$days]) ?: [];

    // Top callsigns by session count
    $top_calls_sql = "SELECT
                base_call,
                COUNT(*)               AS session_count,
                SUM(tx_bytes+rx_bytes) AS total_bytes,
                AVG(avg_snr_db)        AS avg_snr_db
            FROM rf_connections
            WHERE session_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status = 'success'$vo
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
              AND status = 'success'$vo
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
 * table (populated by parse-vara-log.py's build_forwarded_messages()).
 *
 * Optional filters:
 *   days      how many days back (1-365, default 30)
 *   partner   filter to one forwarding partner base call (default no filter)
 *   msg_type  B | P | T (default no filter)
 *   status    all (default) | delivered | rejected          (added 2026-10-06)
 *               delivered = everything except rejected offers (accepted,
 *               deferred, and rows with no recorded reply)
 *   counts    1 = also return `status_counts` — per partner/direction/
 *               type/medium/status totals for the same window, so the page
 *               can show rejected-offer totals without loading those rows
 *   limit     max rows (1-50000, default 1000)
 *
 * Rows are newest-first with ISO timestamps. Each row carries `medium`
 * (2026-09-21) and, once sql/2026-10-06-rf-messages-delivery.sql is
 * applied, `accept_status` (A/R/D/E/null) and `delivered_time`. As of
 * that migration there is one row per (bid, direction, partner).
 * Filtering to RF-only is left to the client.
 */
function rf_messages_has_delivery(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        $row = tprfn_query_one($pdo,
            "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rf_messages'
                AND COLUMN_NAME IN ('accept_status', 'delivered_time')");
        $has = $row !== null && (int)$row['n'] === 2;
    }
    return $has;
}

function action_messages(PDO $pdo): array {
    $days = intparam('days', 30, 1, 365);
    $partner = $_GET['partner'] ?? null;
    $msg_type = strparam('msg_type', '', ['', 'B', 'P', 'T']);
    $status = strparam('status', 'all', ['all', 'delivered', 'rejected']);
    $want_counts = ($_GET['counts'] ?? '') === '1';
    $limit = intparam('limit', 1000, 1, 50000);
    $has_delivery = rf_messages_has_delivery($pdo);

    // msg_time is stored in UTC, so compare against UTC_TIMESTAMP()
    $where = ['msg_time >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)'];
    $params = [$days];

    if (!empty($partner)) {
        $where[] = 'partner = ?';
        $params[] = (string)$partner;
    }
    if ($msg_type !== '') {
        $where[] = 'msg_type = ?';
        $params[] = $msg_type;
    }
    $count_where_sql = implode(' AND ', $where);
    $count_params = $params;

    if ($has_delivery && $status === 'delivered') {
        $where[] = "(accept_status IS NULL OR accept_status <> 'R')";
    } elseif ($has_delivery && $status === 'rejected') {
        $where[] = "accept_status = 'R'";
    }

    // v1.5.46: modem / link_mode / end_reason (Mercury rows), when the
    // migration has run; ?modem=vara|mercury narrows to one.
    $cols = rf_cols($pdo);
    $extra = '';
    foreach (['modem', 'link_mode', 'end_reason'] as $c) {
        $extra .= isset($cols[$c]) ? ", $c" : ", NULL AS $c";
    }
    if (isset($cols['modem'])) {
        $modem = strparam('modem', 'all', ['all', 'vara', 'mercury', 'vhf', 'data2g']);
        if ($modem !== 'all') { $where[] = 'modem = ?'; $params[] = $modem; }
    }

    $where_sql = implode(' AND ', $where);
    $params[] = $limit;

    $delivery_cols = $has_delivery
        ? "accept_status, DATE_FORMAT(delivered_time, '%Y-%m-%dT%H:%i:%sZ') AS delivered_time,"
        : '';

    $sql = "SELECT
                id, bid, direction, partner, medium, $delivery_cols msg_type,
                from_call, to_area, category, size_bytes,
                DATE_FORMAT(msg_time, '%Y-%m-%dT%H:%i:%sZ') AS msg_time
            FROM rf_messages
            WHERE $where_sql
            ORDER BY msg_time DESC
            LIMIT ?";

    $rows = tprfn_query($pdo, $sql, $params);
    $out = [
        'messages' => $rows,
        'count' => count($rows),
        'truncated' => count($rows) >= $limit,
        'has_delivery' => $has_delivery,
    ];

    if ($want_counts && $has_delivery) {
        $out['status_counts'] = tprfn_query($pdo,
            "SELECT partner, direction, msg_type, medium, accept_status,
                    DATE_FORMAT(msg_time, '%y%m%d') AS day, COUNT(*) AS n
               FROM rf_messages
              WHERE $count_where_sql
              GROUP BY partner, direction, msg_type, medium, accept_status, day",
            $count_params);
    }
    return $out;
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

// ─── Sound-modem ports (v1.5.23) ────────────────────────────────────────────
// Status of LinBPQ's KISS-over-TCP ports (QtSoundModem, Direwolf, ...) for the
// badge on RF Connections: is the modem listening on its KISS port (local
// modems only), is LinBPQ connected to it, and the last station heard on that
// BPQ port (net_mheard). Read from /proc/net/tcp(6): no commands, no sudo.
// Never returns addresses or port numbers of the modem, only names and state.

function tcp_sockets(): array {
    $out = [];
    foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) continue;
        array_shift($lines);
        foreach ($lines as $l) {
            $c = preg_split('/\s+/', trim($l));
            if (count($c) < 4) continue;
            $out[] = ['lport' => hexdec(substr($c[1], strrpos($c[1], ':') + 1)),
                      'rport' => hexdec(substr($c[2], strrpos($c[2], ':') + 1)),
                      'state' => $c[3]];          // 0A = LISTEN, 01 = ESTABLISHED
        }
    }
    return $out;
}

function action_soundmodem(): array {
    // v1.5.29: stations heard in the reporting period (UTC days, as the page's
    // Today / 7 / 30 / 90 Days buttons: today 00:00Z back days-1 days, to now).
    $days = max(1, min(90, (int)($_GET['days'] ?? 1)));
    $since = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days', time()));
    $socks = tcp_sockets();
    $haveProc = (bool)$socks;
    $pdo = null;
    try { $pdo = tprfn_db(); } catch (Throwable $e) { /* status still works without the database */ }
    $out = [];
    foreach (bpq_linbpq_ports() as $num => $p) {
        if (empty($p['kiss_port'])) continue;
        $kp = (int)$p['kiss_port'];
        $local = in_array(strtolower($p['kiss_host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true);
        $listening = $local ? (bool)array_filter($socks, fn($s) => $s['state'] === '0A' && $s['lport'] === $kp) : null;
        $linked = (bool)array_filter($socks, fn($s) => $s['state'] === '01' && $s['rport'] === $kp);
        $status = !$haveProc ? 'unknown'
                : ($linked ? 'up' : ($listening === false ? 'down' : 'nolink'));
        $last = null; $heard = [];
        if ($pdo) {
            try {
                $st = $pdo->prepare('SELECT callsign, heard_at FROM net_mheard WHERE port = ? ORDER BY heard_at DESC LIMIT 1');
                $st->execute([$num]);
                $last = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                // BPQ's MHeard keeps each station once, with its LAST time
                // heard: so this is every station last heard in the period.
                $st = $pdo->prepare('SELECT callsign, heard_at, status FROM net_mheard WHERE port = ? AND heard_at >= ? ORDER BY heard_at DESC LIMIT 200');
                $st->execute([$num, $since]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $heard[] = ['call' => $r['callsign'], 'heard_at' => $r['heard_at'], 'connected' => $r['status'] === 'connected'];
                }
            } catch (Throwable $e) { $last = null; }
        }
        $out[] = ['port' => $num, 'name' => $p['name'], 'status' => $status, 'local' => $local,
                  'last_heard' => $last ? $last['heard_at'] : null, 'last_call' => $last ? $last['callsign'] : null,
                  'heard' => $heard];
    }
    // v1.5.25: watched stations (config.php system_logs.watch), shown under
    // the badge of the port they're watched on.
    $watch = bpq_watch_status($pdo);
    foreach ($out as &$m) {
        $m['watch'] = array_values(array_filter($watch, fn($w) => $w['port'] === $m['port']));
    }
    unset($m);
    return ['modems' => $out, 'checked_at' => gmdate('Y-m-d H:i:s'), 'since' => $since, 'days' => $days];
}

/**
 * GET ?action=paths&days=14 — VHF path history (v1.5.50)
 *
 * For each watched station (Maintenance → Watched stations): how often it
 * was heard in each UTC hour (net_heard_history, filled by the 5-minute
 * MHeard poll) and the scheduled test connects (link_probes, written by
 * scripts/link-probe.py). Tables missing (update.sh not run) → empty lists
 * and 'tables' => false.
 */
function action_paths(PDO $pdo): array {
    $days = intparam('days', 14, 1, 90);
    $since = gmdate('Y-m-d', time() - ($days - 1) * 86400) . ' 00:00:00';
    $out = []; $tables = true;
    foreach (bpq_watch_list() as $w) {
        $row = $w + ['last_heard' => null, 'heard' => [], 'probes' => []];
        $pw = $w['port'] ? ' AND port = ?' : '';
        $args = $w['port'] ? [$w['call'], $w['port']] : [$w['call']];
        try {
            $st = $pdo->prepare("SELECT MAX(heard_at) FROM net_mheard WHERE callsign = ?$pw");
            $st->execute($args);
            $row['last_heard'] = $st->fetchColumn() ?: null;
        } catch (Throwable $e) { /* System Logs tables missing */ }
        try {
            $st = $pdo->prepare("SELECT DATE_FORMAT(heard_at, '%Y-%m-%d') d, HOUR(heard_at) h, COUNT(*) n
                                 FROM net_heard_history WHERE callsign = ?$pw AND heard_at >= ?
                                 GROUP BY d, h ORDER BY d, h");
            $st->execute(array_merge($args, [$since]));
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $row['heard'][] = [$r[0], (int)$r[1], (int)$r[2]];
            if ($w['port']) {
                $st = $pdo->prepare('SELECT probed_at, result, connect_secs, detail FROM link_probes
                                     WHERE callsign = ? AND port = ? AND probed_at >= ? ORDER BY probed_at');
                $st->execute([$w['call'], $w['port'], $since]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $r['connect_secs'] = $r['connect_secs'] === null ? null : (float)$r['connect_secs'];
                    $row['probes'][] = $r;
                }
            }
        } catch (Throwable $e) { $tables = false; }
        $out[] = $row;
    }
    return ['stations' => $out, 'days' => $days, 'now' => gmdate('Y-m-d H:i:s'), 'tables' => $tables];
}

// ─── Dispatcher ─────────────────────────────────────────────────────────────

$action = strparam('action', 'all', [
    'sessions', 'heatmap', 'stats', 'parser_status', 'all', 'messages', 'soundmodem', 'watchdog', 'bbslines', 'mercury', 'data2g', 'paths'
]);

if ($action === 'mercury') {         // v1.5.43: Mercury HF status tile (no database needed)
    success(bpq_mercury_status(dirname(__FILE__) . '/cache'));
    exit;
}

if ($action === 'data2g') {          // v1.5.56: Data2G HF status tile (no database needed)
    success(bpq_data2g_status(dirname(__FILE__) . '/cache'));
    exit;
}

if ($action === 'soundmodem') {      // works without the database (v1.5.23)
    success(action_soundmodem());
    exit;
}
if ($action === 'bbslines') {        // v1.5.39: the few BBS-log lines the page reads
    bbs_lines_action();
    exit;
}
if ($action === 'watchdog') {        // v1.5.35: Connect Watchdog pauses, for the partner tiles
    $f = dirname(__FILE__) . '/cache/watchdog-state.json';
    $j = @is_readable($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $out = ['running' => false, 'partners' => (object)[]];
    if (is_array($j) && isset($j['partners']) && is_array($j['partners'])) {
        $upd = strtotime((string)($j['updated_at'] ?? '')) ?: 0;
        $out['running'] = $upd > time() - 1800;     // cron runs every 5 minutes
        $out['updated_at'] = $j['updated_at'] ?? null;
        $out['settings'] = $j['settings'] ?? null;
        $p = [];
        foreach ($j['partners'] as $block => $st) {
            if (!is_array($st) || !preg_match('/^[A-Z0-9\-]{1,12}$/', (string)$block)) continue;
            $p[$block] = [
                'call' => (string)($st['call'] ?? $block), 'status' => (string)($st['status'] ?? ''),
                'paused' => !empty($st['paused']), 'resume_at' => $st['resume_at'] ?? null,
                'paused_at' => $st['paused_at'] ?? null, 'reason' => $st['reason'] ?? null,
                'fails_in_a_row' => (int)($st['fails_in_a_row'] ?? 0), 'last_ok_at' => $st['last_ok_at'] ?? null,
            ];
        }
        $out['partners'] = (object)$p;
    }
    success($out);
    exit;
}

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
        'paths'         => action_paths($pdo),
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

/**
 * v1.5.39 — ?action=bbslines&file=log_YYMMDD_BBS.txt
 *
 * The RF Connections page used to download every day's raw BBS log (on a busy
 * hub 40-50 MB a day, 1.3 GB for 30 days) only to read a handful of line
 * types from it. This returns just those lines, the same text as in the
 * file: RADIO commands, "Incoming Connect from … Freq", channel busy / port in
 * use / session attached / "Failure with", and FA/FB/FF/FC proposals. The
 * result is kept in cache/bbs-lines/ and extended as the log grows, so each
 * day's log is read once.
 */
function bbs_lines_action(): void {
    $f = (string)($_GET['file'] ?? '');
    if (!preg_match('/^log_\d{6}_BBS\.txt$/', $f)) { http_response_code(400); echo 'bad file name'; return; }
    $src = __DIR__ . '/logs/' . $f;
    if (!is_file($src) || !is_readable($src)) { http_response_code(404); return; }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $needles = [' RADIO ', 'Incoming Connect from', 'busy', 'Port in use', 'Session Attached',
                'Failure with', ' FA ', ' FB ', ' FF ', ' FC ', 'connected VARA HF'];
    $keep = static function (string $line) use ($needles): bool {
        foreach ($needles as $n) if (stripos($line, $n) !== false) return true;
        return false;
    };

    $dir = __DIR__ . '/cache/bbs-lines';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $cache = $dir . '/' . $f; $metaF = $cache . '.json';
    $lk = (is_dir($dir) && is_writable($dir)) ? @fopen($cache . '.lock', 'c') : false;
    if (!$lk) {                                   // no cache: filter on the fly
        $in = fopen($src, 'rb');
        while (($l = fgets($in)) !== false) if ($keep($l)) echo $l;
        fclose($in);
        return;
    }
    flock($lk, LOCK_EX);
    clearstatcache(true, $src);
    $size = filesize($src); $ino = fileinode($src);
    $meta = is_file($metaF) ? (json_decode((string)@file_get_contents($metaF), true) ?: []) : [];
    $from = 0; $mode = 'wb';
    if (is_file($cache) && ($meta['ino'] ?? null) === $ino && (int)($meta['size'] ?? -1) <= $size) {
        $from = (int)$meta['size']; $mode = 'ab';
    }
    if ($from < $size || $mode === 'wb') {
        $in = fopen($src, 'rb'); fseek($in, $from);
        $out = fopen($cache, $mode); $pos = $from;
        while (($l = fgets($in)) !== false) {
            if (substr($l, -1) !== "\n") break;      // the line being written: next time
            $pos += strlen($l);
            if ($keep($l)) fwrite($out, $l);
        }
        fclose($in); fclose($out);
        file_put_contents($metaF, json_encode(['size' => $pos, 'ino' => $ino]));
    }
    flock($lk, LOCK_UN); fclose($lk);
    readfile($cache);
}
