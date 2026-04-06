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
define('TPRFN_DB_HOST', 'localhost');
define('TPRFN_DB_NAME', 'tprfn');
define('TPRFN_DB_USER', 'tprfn_app');
define('TPRFN_DB_PASS', 'TprfnDb2026!');
define('TPRFN_DB_PORT', 3306);

/**
 * Return the singleton PDO connection.
 * Throws PDOException on failure (caught at call site or bubbles up as 500).
 */
function tprfn_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
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
 * Uses INSERT IGNORE so duplicate sessions (uq_session constraint) are
 * silently skipped without logging errors.
 * Returns the new session id, or 0 if insert was skipped (duplicate).
 */
function tprfn_insert_session(PDO $pdo, array $s): int {
    $sql = "
        INSERT IGNORE INTO sessions
            (hub, station, direction, is_hub_to_hub, session_date,
             connected_at, disconnected_at, duration_secs,
             avg_snr, bytes_tx, bytes_rx, max_bps, source, syslog_year)
        VALUES
            (:hub, :station, :direction, :is_hub_to_hub, :session_date,
             :connected_at, :disconnected_at, :duration_secs,
             :avg_snr, :bytes_tx, :bytes_rx, :max_bps, :source, :syslog_year)
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':hub'             => strtoupper($s['hub']),
            ':station'         => strtoupper($s['station']),
            ':direction'       => $s['direction']       ?? 'incoming',
            ':is_hub_to_hub'   => $s['is_hub_to_hub']   ?? 0,
            ':session_date'    => $s['session_date'],
            ':connected_at'    => $s['connected_at']    ?? null,
            ':disconnected_at' => $s['disconnected_at'] ?? null,
            ':duration_secs'   => $s['duration_secs']   ?? null,
            ':avg_snr'         => $s['avg_snr']         ?? null,
            ':bytes_tx'        => $s['bytes_tx']        ?? 0,
            ':bytes_rx'        => $s['bytes_rx']        ?? 0,
            ':max_bps'         => $s['max_bps']         ?? 0,
            ':source'          => $s['source']          ?? 'syslog',
            ':syslog_year'     => $s['syslog_year']     ?? (int)date('Y'),
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Log genuine errors (not duplicates — those are silently skipped by INSERT IGNORE)
        error_log('tprfn_insert_session error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Upsert a station record (insert or update last_seen/coordinates).
 */
function tprfn_upsert_station(PDO $pdo, string $callsign, string $date,
                               ?float $lat = null, ?float $lon = null,
                               ?string $grid = null): void {
    $callsign = strtoupper($callsign);
    $sql = "
        INSERT INTO stations (callsign, lat, lon, grid, first_seen, last_seen)
        VALUES (:cs, :lat, :lon, :grid, :date_a, :date_b)
        ON DUPLICATE KEY UPDATE
            last_seen  = GREATEST(last_seen,  :date_c),
            first_seen = LEAST(first_seen,    :date_d),
            lat        = COALESCE(:lat2,  lat),
            lon        = COALESCE(:lon2,  lon),
            grid       = COALESCE(:grid2, grid)
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':cs'     => $callsign,
            ':lat'    => $lat,
            ':lon'    => $lon,
            ':grid'   => $grid,
            ':date_a' => $date,
            ':date_b' => $date,
            ':date_c' => $date,
            ':date_d' => $date,
            ':lat2'   => $lat,
            ':lon2'   => $lon,
            ':grid2'  => $grid,
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
