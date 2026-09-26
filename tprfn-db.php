<?php
/**
 * tprfn-db.php — Shared database connection helper
 * Version: 1.0.0
 *
 * Include this file in any TPRFN PHP script that needs database access.
 * Usage:
 *   require_once __DIR__ . '/tprfn-db.php';
 *   $pdo = tprfn_db();
 *   $rows = tprfn_query($pdo, "SELECT * FROM sessions WHERE hub = ?", ['K1AJD-7']);
 *
 * The connection is a singleton — multiple require_once calls share one PDO.
 */

// ── Credentials ───────────────────────────────────────────────────────────────
// (2026-09-26) No longer hard-coded here. Loaded, in order, from:
//   1. db-config.php        (local-only, gitignored — see db-config.php.example)
//   2. config.php's 'db' section  (the long-standing documented location)
// Nothing secret lives in this file any more, so it's safe to publish.

/**
 * Return the DB settings as ['host','port','name','user','pass'] (+ 'source'),
 * or [] if neither source exists. Cached per request.
 */
function tprfn_db_settings(): array {
    static $settings = null;
    if ($settings !== null) return $settings;
    $settings = [];

    $local = __DIR__ . '/db-config.php';
    if (is_readable($local)) {
        $c = require $local;
        if (is_array($c)) { $settings = $c; $settings['source'] = 'db-config.php'; }
    }
    if (!$settings) {
        $cfgFile = __DIR__ . '/config.php';
        if (is_readable($cfgFile)) {
            $cfg = require $cfgFile;          // v1.3+ config.php returns an array
            if (is_array($cfg) && isset($cfg['db']) && is_array($cfg['db'])) {
                $settings = $cfg['db']; $settings['source'] = 'config.php';
            }
        }
    }
    return $settings;
}

$__tprfn_db = tprfn_db_settings();
define('TPRFN_DB_HOST', $__tprfn_db['host'] ?? 'localhost');
define('TPRFN_DB_NAME', $__tprfn_db['name'] ?? 'tprfn');
define('TPRFN_DB_USER', $__tprfn_db['user'] ?? 'tprfn_app');
define('TPRFN_DB_PASS', $__tprfn_db['pass'] ?? '');
define('TPRFN_DB_PORT', (int)($__tprfn_db['port'] ?? 3306));
unset($__tprfn_db);

/**
 * Return the singleton PDO connection.
 * Throws PDOException on failure (caught at call site or bubbles up as 500).
 */
function tprfn_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!tprfn_db_settings()) {
            throw new RuntimeException('No database settings: create db-config.php '
                . '(copy db-config.php.example) or add a db section to config.php');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            TPRFN_DB_HOST, TPRFN_DB_PORT, TPRFN_DB_NAME
        );
        $pdo = new PDO($dsn, TPRFN_DB_USER, TPRFN_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Prepare, execute, and return all rows.
 * Returns [] on empty result. Throws on SQL error.
 */
function tprfn_query(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Prepare, execute, and return one row (or null).
 */
function tprfn_query_one(PDO $pdo, string $sql, array $params = []): ?array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Prepare and execute a write statement (INSERT/UPDATE/DELETE).
 * Returns the number of affected rows.
 */
function tprfn_execute(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Insert a session row — used by network-api.php and the importer.
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE against the uq_session key
 * (hub, station, direction, connected_at, disconnected_at). If the syslog
 * gets re-parsed and the same session is submitted again — with updated
 * byte counts, SNR, etc. now that more of the session has been logged —
 * the existing row is UPDATED in place rather than silently ignored (the
 * old behavior) or inserted as a fresh duplicate row (the bug this fixed:
 * the previous uq_session key included bytes_tx/bytes_rx/max_bps/avg_snr,
 * so any change in those values on re-parse produced a brand new row
 * instead of being recognized as the same session).
 *
 * Returns the session id (existing or new), or 0 on genuine DB error.
 */
function tprfn_insert_session(PDO $pdo, array $s): int {
    $sql = "
        INSERT INTO sessions
            (hub, station, direction, is_hub_to_hub, session_date,
             connected_at, disconnected_at, duration_secs,
             avg_snr, bytes_tx, bytes_rx, max_bps,
             max_bps_tx, max_bps_rx, connect_attempts, vara_version,
             msgs_tx, msgs_rx, freq_mhz, band,
             source, syslog_year)
        VALUES
            (:hub, :station, :direction, :is_hub_to_hub, :session_date,
             :connected_at, :disconnected_at, :duration_secs,
             :avg_snr, :bytes_tx, :bytes_rx, :max_bps,
             :max_bps_tx, :max_bps_rx, :connect_attempts, :vara_version,
             :msgs_tx, :msgs_rx, :freq_mhz, :band,
             :source, :syslog_year)
        ON DUPLICATE KEY UPDATE
            duration_secs     = VALUES(duration_secs),
            avg_snr           = VALUES(avg_snr),
            bytes_tx          = VALUES(bytes_tx),
            bytes_rx          = VALUES(bytes_rx),
            max_bps           = VALUES(max_bps),
            max_bps_tx        = VALUES(max_bps_tx),
            max_bps_rx        = VALUES(max_bps_rx),
            connect_attempts  = VALUES(connect_attempts),
            vara_version      = COALESCE(VALUES(vara_version), vara_version),
            msgs_tx           = COALESCE(VALUES(msgs_tx), msgs_tx),
            msgs_rx           = COALESCE(VALUES(msgs_rx), msgs_rx),
            freq_mhz          = COALESCE(VALUES(freq_mhz), freq_mhz),
            band              = COALESCE(VALUES(band), band),
            id                = LAST_INSERT_ID(id)
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':hub'              => strtoupper($s['hub']),
            ':station'          => strtoupper($s['station']),
            ':direction'        => $s['direction']        ?? 'incoming',
            ':is_hub_to_hub'    => $s['is_hub_to_hub']    ?? 0,
            ':session_date'     => $s['session_date'],
            ':connected_at'     => $s['connected_at']     ?? null,
            ':disconnected_at'  => $s['disconnected_at']  ?? null,
            ':duration_secs'    => $s['duration_secs']    ?? null,
            ':avg_snr'          => $s['avg_snr']          ?? null,
            ':bytes_tx'         => $s['bytes_tx']         ?? 0,
            ':bytes_rx'         => $s['bytes_rx']         ?? 0,
            ':max_bps'          => $s['max_bps']          ?? 0,
            ':max_bps_tx'       => $s['max_bps_tx']       ?? 0,
            ':max_bps_rx'       => $s['max_bps_rx']       ?? 0,
            ':connect_attempts' => $s['connect_attempts'] ?? null,
            ':vara_version'     => $s['vara_version']     ?? null,
            ':msgs_tx'          => $s['msgs_tx']          ?? null,
            ':msgs_rx'          => $s['msgs_rx']          ?? null,
            ':freq_mhz'         => $s['freq_mhz']         ?? null,
            ':band'             => $s['band']             ?? null,
            ':source'           => $s['source']           ?? 'syslog',
            ':syslog_year'      => $s['syslog_year']      ?? (int)date('Y'),
        ]);
        // LAST_INSERT_ID(id) in the UPDATE clause makes lastInsertId() return
        // the existing row's id on an update, not just on a fresh insert.
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('tprfn_insert_session error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Upsert a station record (insert or update last_seen/coordinates).
 */
function tprfn_upsert_station(PDO $pdo, string $callsign, string $date,
                               ?float $lat = null, ?float $lon = null,
                               ?string $grid = null,
                               ?string $country = null,
                               ?string $vara_version = null): void {
    $callsign = strtoupper($callsign);
    $sql = "
        INSERT INTO stations
            (callsign, lat, lon, grid, country, last_vara_version,
             total_sessions, first_seen, last_seen)
        VALUES
            (:cs, :lat, :lon, :grid, :country, :vara_version,
             1, :date_a, :date_b)
        ON DUPLICATE KEY UPDATE
            last_seen         = GREATEST(last_seen,  :date_c),
            first_seen        = LEAST(first_seen,    :date_d),
            lat               = COALESCE(:lat2,  lat),
            lon               = COALESCE(:lon2,  lon),
            grid              = COALESCE(:grid2, grid),
            country           = COALESCE(:country2,       country),
            last_vara_version = COALESCE(:vara_version2,  last_vara_version),
            total_sessions    = total_sessions + 1
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':cs'             => $callsign,
            ':lat'            => $lat,
            ':lon'            => $lon,
            ':grid'           => $grid,
            ':country'        => $country,
            ':vara_version'   => $vara_version,
            ':date_a'         => $date,
            ':date_b'         => $date,
            ':date_c'         => $date,
            ':date_d'         => $date,
            ':lat2'           => $lat,
            ':lon2'           => $lon,
            ':grid2'          => $grid,
            ':country2'       => $country,
            ':vara_version2'  => $vara_version,
        ]);
    } catch (PDOException $e) {
        error_log('tprfn_upsert_station error: ' . $e->getMessage());
    }
}

/**
 * Check whether the database is reachable.
 * Returns true/false — safe to call without try/catch at the call site.
 */
function tprfn_db_available(): bool {
    try {
        $pdo = tprfn_db();
        $pdo->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Convert syslog duration string "HH:MM" or "HH:MM:SS" to seconds.
 */
function tprfn_duration_to_secs(?string $duration): ?int {
    if (!$duration) return null;
    $parts = explode(':', $duration);
    if (count($parts) === 2) return (int)$parts[0] * 60 + (int)$parts[1];
    if (count($parts) === 3) return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
    return null;
}
