<?php
/*
 * bbs-forwards.php — Parses BPQ-Mail's daily BBS log for outbound
 * forwarding events.
 *
 * BPQ writes its BBS log to <LOG_DIR>/log_YYMMDD_BBS.txt
 * (path comes from `logdir` argument to linbpq, which on this system
 * is set in the systemd unit). Each forwarding event appears as an
 * "FA" (Forward Available) or "FC" (Forward Compressed) line.
 *
 * Line formats:
 *
 *   FA: announce uncompressed forward to a destination BBS
 *   260520 00:00:40 >N5MDT-4   FA B NC8Q USA PKTNET 49637_NC8Q 628
 *                              │  │ │    │   │      │          │
 *                              │  │ │    │   │      │          └ size bytes
 *                              │  │ │    │   │      └ BID (cross-network msg id)
 *                              │  │ │    │   └ subject/title
 *                              │  │ │    └ destination address (TO)
 *                              │  │ └ FROM callsign
 *                              │  └ type: B=bulletin, P=personal, T=traffic
 *                              └ direction marker (>=outbound from this BBS)
 *
 *   FC: announce compressed forward (newer protocol)
 *   260520 00:06:10 >EXAMPLE     FC EM 49637_NC8Q 851 535 NC8Q USA PKTNET B
 *                              │  │  │          │   │   │    │   │      │
 *                              │  │  │          │   │   │    │   │      └ type
 *                              │  │  │          │   │   │    │   └ subject
 *                              │  │  │          │   │   │    └ destination
 *                              │  │  │          │   │   └ FROM
 *                              │  │  │          │   └ compressed size
 *                              │  │  │          └ uncompressed size
 *                              │  │  └ BID
 *                              │  └ encoding (EM=plain, EB=base64)
 *                              └ FC marker
 *
 * The line BEFORE the direction marker is the destination BBS this
 * forward was sent TO ("N5MDT-4", "EXAMPLE", etc).
 *
 * Endpoint:
 *
 *   GET ?action=list&limit=N&hours=H
 *     → recent forward events, optionally filtered to last H hours
 *
 *   GET ?action=for_bid&bid=49637_NC8Q
 *     → all forward events for a specific BID (cross-day)
 *
 *   GET ?action=stats
 *     → summary counts (today's total, top destination BBSes)
 *
 * All output is JSON.
 *
 * Cache: results cached for 30 seconds via a small file in /tmp
 * so we don't re-parse the entire log on every dashboard click.
 */

declare(strict_types=1);

header('Content-Type: application/json');

// ─── Configuration ────────────────────────────────────────────
// BPQ's log folder (2026-10-25): env BPQ_LOG_DIR, else config.php paths.logs,
// else <web root>/logs. The fallback was /var/www/html/logs, which a standard
// install doesn't have, so every message showed "Not Yet Forwarded".
require_once __DIR__ . '/includes/station-config.php';
$LOG_DIR = rtrim((string)(getenv('BPQ_LOG_DIR') ?: (bpq_config_section('paths')['logs'] ?? '') ?: (__DIR__ . '/logs')), '/');
$CACHE_DIR  = '/tmp';
$CACHE_TTL  = 30;   // seconds
$MAX_LOG_BYTES = 50 * 1024 * 1024;  // 50 MB safety cap — refuse to read more

// ─── Error helper ─────────────────────────────────────────────
function apiError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ─── Input ────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'list';
$limit  = max(1, min((int)($_GET['limit'] ?? 200), 1000));
$hours  = max(1, min((int)($_GET['hours'] ?? 24), 168));   // up to 7 days
$bid    = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['bid'] ?? '');

// ─── Cache layer ──────────────────────────────────────────────
function cacheKey(array $params): string {
    return $GLOBALS['CACHE_DIR'] . '/bbs-fwd-' . md5(serialize($params)) . '.json';
}

function cacheGet(string $key): ?array {
    if (!file_exists($key)) return null;
    if (time() - filemtime($key) > $GLOBALS['CACHE_TTL']) return null;
    $raw = @file_get_contents($key);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function cacheSet(string $key, array $data): void {
    @file_put_contents($key, json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
}

// ─── Log file selection ───────────────────────────────────────

/**
 * Generate the list of BBS log filenames to read for the requested
 * window. Files are named log_YYMMDD_BBS.txt. We span however many
 * days the `hours` parameter requires.
 */
function logFilesForHours(int $hours): array {
    $files = [];
    $now = time();
    $daysBack = (int)ceil($hours / 24);
    for ($d = 0; $d <= $daysBack; $d++) {
        $ts = $now - $d * 86400;
        $name = sprintf('%s/log_%s_BBS.txt',
            $GLOBALS['LOG_DIR'], date('ymd', $ts));
        if (file_exists($name) && filesize($name) < $GLOBALS['MAX_LOG_BYTES']) {
            $files[] = $name;
        }
    }
    // Sort newest last so we can read newest-first when needed
    sort($files);
    return $files;
}

// ─── Line parser ──────────────────────────────────────────────

/**
 * Parse a single log line. Returns one of:
 *
 *   ['type' => 'FA', 'time' => unix-ts, 'dest_bbs' => 'N5MDT-4',
 *    'msg_type' => 'B', 'from' => 'NC8Q', 'to_addr' => 'USA',
 *    'subject' => 'PKTNET', 'bid' => '49637_NC8Q', 'size' => 628]
 *
 *   ['type' => 'FC', 'time' => unix-ts, 'dest_bbs' => 'EXAMPLE',
 *    'encoding' => 'EM', 'bid' => '49637_NC8Q', 'size' => 851,
 *    'compressed_size' => 535, 'from' => 'NC8Q', 'to_addr' => 'USA',
 *    'subject' => 'PKTNET', 'msg_type' => 'B']
 *
 *   null if not a forward event we care about
 *
 * Lines look like:
 *   YYMMDD HH:MM:SS >DEST   PAYLOAD
 *
 * We only care about lines with > (outbound from this BBS).
 */
function parseForwardLine(string $line): ?array {
    // Strip trailing newlines/whitespace
    $line = rtrim($line);
    if ($line === '') return null;

    // Match the prefix: 6 digits date, space, HH:MM:SS, space, >DEST
    // The DEST callsign is followed by whitespace and then the payload
    if (!preg_match('/^(\d{6}) (\d{2}:\d{2}:\d{2}) >(\S+)\s+(.*)$/', $line, $m)) {
        return null;
    }
    $date    = $m[1];   // YYMMDD
    $time    = $m[2];   // HH:MM:SS
    $destBbs = $m[3];   // e.g. N5MDT-4
    $payload = $m[4];   // remainder

    // Convert YYMMDD HH:MM:SS to unix timestamp.
    // Year 2-digit: 26 → 2026 (we'll be wrong about this in 2100, oh well).
    $year   = 2000 + (int)substr($date, 0, 2);
    $month  = (int)substr($date, 2, 2);
    $day    = (int)substr($date, 4, 2);
    [$hh, $mm, $ss] = sscanf($time, '%d:%d:%d');
    $unix = mktime($hh, $mm, $ss, $month, $day, $year);

    // Now look at the payload to identify forwarding events
    if (preg_match('/^FA (\S) (\S+) (\S+) (\S+) (\S+) (\d+)/', $payload, $f)) {
        // FA <type> <from> <to_addr> <subject> <bid> <size>
        return [
            'type'      => 'FA',
            'time'      => $unix,
            'time_iso'  => date('Y-m-d H:i:s', $unix),
            'dest_bbs'  => $destBbs,
            'msg_type'  => $f[1],
            'from'      => $f[2],
            'to_addr'   => $f[3],
            'subject'   => $f[4],
            'bid'       => $f[5],
            'size'      => (int)$f[6],
        ];
    }

    if (preg_match('/^FC (\S+) (\S+) (\d+) (\d+) (\S+) (\S+) (\S+) (\S)$/', $payload, $f)) {
        // FC <encoding> <bid> <size> <comp_size> <from> <to_addr> <subject> <type>
        return [
            'type'            => 'FC',
            'time'            => $unix,
            'time_iso'        => date('Y-m-d H:i:s', $unix),
            'dest_bbs'        => $destBbs,
            'encoding'        => $f[1],
            'bid'             => $f[2],
            'size'            => (int)$f[3],
            'compressed_size' => (int)$f[4],
            'from'            => $f[5],
            'to_addr'         => $f[6],
            'subject'         => $f[7],
            'msg_type'        => $f[8],
        ];
    }

    return null;
}

/**
 * Read the given log files line by line, parse, and return all
 * forwarding events found. Files are read in reverse order (newest
 * day first) so the caller can paginate with limit easily.
 *
 * Streaming approach via fopen to avoid loading the whole 4.8MB
 * log into memory at once.
 */
function scanLogs(array $files, int $sinceTs): array {
    $events = [];
    // Reverse so newest file first
    $reversed = array_reverse($files);
    foreach ($reversed as $path) {
        $fh = @fopen($path, 'r');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            $parsed = parseForwardLine($line);
            if (!$parsed) continue;
            if ($parsed['time'] < $sinceTs) continue;
            $events[] = $parsed;
        }
        fclose($fh);
    }
    // Sort by time desc (newest first)
    usort($events, fn($a, $b) => $b['time'] <=> $a['time']);
    return $events;
}

// ─── Action dispatch ──────────────────────────────────────────

switch ($action) {

case 'list':
    // Recent forward events from the last N hours
    $cacheKey = cacheKey(['list', $hours, $limit]);
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        echo json_encode($cached, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $sinceTs = time() - $hours * 3600;
    $files = logFilesForHours($hours);
    $events = scanLogs($files, $sinceTs);
    $events = array_slice($events, 0, $limit);
    $out = [
        'success'      => true,
        'events'       => $events,
        'count'        => count($events),
        'hours'        => $hours,
        'files_read'   => array_map('basename', $files),
        'generated_at' => date('c'),
    ];
    cacheSet($cacheKey, $out);
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
    break;

case 'for_bid':
    if ($bid === '') apiError('Missing or invalid bid parameter');
    $cacheKey = cacheKey(['for_bid', $bid]);
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        echo json_encode($cached, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    // BID search spans the last 7 days (NTS message could have been
    // sitting on this BBS for a few days before being forwarded out)
    $sinceTs = time() - 7 * 86400;
    $files = logFilesForHours(7 * 24);
    $allEvents = scanLogs($files, $sinceTs);
    $events = array_values(array_filter($allEvents, fn($e) => $e['bid'] === $bid));
    $out = [
        'success'      => true,
        'bid'          => $bid,
        'events'       => $events,
        'count'        => count($events),
        'generated_at' => date('c'),
    ];
    cacheSet($cacheKey, $out);
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
    break;

case 'stats':
    $cacheKey = cacheKey(['stats']);
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        echo json_encode($cached, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    // Stats for the last 24 hours
    $sinceTs = time() - 86400;
    $files = logFilesForHours(24);
    $events = scanLogs($files, $sinceTs);
    // Count by destination BBS
    $byDest = [];
    foreach ($events as $e) {
        $d = $e['dest_bbs'];
        $byDest[$d] = ($byDest[$d] ?? 0) + 1;
    }
    arsort($byDest);
    // Count by type
    $byType = [];
    foreach ($events as $e) {
        $t = $e['msg_type'] ?? '?';
        $byType[$t] = ($byType[$t] ?? 0) + 1;
    }
    $out = [
        'success'      => true,
        'total_24h'    => count($events),
        'top_dests'    => array_slice($byDest, 0, 10, true),
        'by_type'      => $byType,
        'generated_at' => date('c'),
    ];
    cacheSet($cacheKey, $out);
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
    break;

default:
    apiError('Unknown action: ' . htmlspecialchars($action));
}
