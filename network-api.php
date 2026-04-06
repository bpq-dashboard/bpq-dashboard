<?php
/**
 * TPRFN Network Map - Data API
 * Version: 1.1.0 — MySQL dual-write integration
 *
 * Provides parsed syslog data and hub configuration for the network map.
 * All syslog parsing is done server-side with caching for performance.
 * Today's data always comes from live syslog parse.
 * Historical data (>today) served from MySQL when available, snapshots otherwise.
 *
 * Endpoints:
 *   ?action=overview                        - Hub-to-hub network overview (JSON)
 *   ?action=hubs                            - Hub configuration (JSON)
 *   ?action=hub&id=K1AJD-7                  - Connections for a specific hub (JSON)
 *   ?action=hub_info&id=K1AJD-7             - Station info (frequencies, services) (JSON)
 *   ?action=station_detail&hub=X&station=Y  - Detailed session data (JSON)
 *   ?action=all_stations                    - All polling stations across all hubs (JSON)
 *   ?action=network_history&days=N          - N-day merged overview (unlimited with MySQL)
 *   ?action=hub_history&id=X&days=N         - N-day hub history (unlimited with MySQL)
 *   ?action=syslog                          - Raw syslog text (backward compat)
 */

// Configuration
$SYSLOG_PATH = '/var/log/remotelogs/syslog';
$HUBS_CONFIG = __DIR__ . '/tprfn-hubs.php';
$HUB_INFO_CONFIG = __DIR__ . '/tprfn-hub-info.php';
$CACHE_DIR = __DIR__ . '/cache';
$CACHE_TTL = 120; // seconds — reparse syslog at most every 2 minutes

// ── MySQL integration (optional — degrades gracefully if DB unavailable) ────
$DB_ENABLED = false;
if (file_exists(__DIR__ . '/tprfn-db.php')) {
    require_once __DIR__ . '/tprfn-db.php';
    $DB_ENABLED = tprfn_db_available();
}

// Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// ========== HELPER FUNCTIONS ==========

function cleanLine($line) {
    return trim(str_replace("\r", "", $line));
}

function isValidCallsign($call) {
    return preg_match('/^[A-Z0-9]{1,7}(-\d{1,2})?$/i', $call);
}

function loadHubInfo($phpConfigPath) {
    $jsonFile = preg_replace('/\.php$/', '.json', $phpConfigPath);
    if (file_exists($jsonFile)) {
        $data = json_decode(file_get_contents($jsonFile), true);
        return is_array($data) ? $data : [];
    }
    if (file_exists($phpConfigPath)) {
        return include($phpConfigPath);
    }
    return [];
}

// ========== SYSLOG CACHING ==========

/**
 * Map max bps to VARA HF speed level (2300 Hz Standard mode)
 * Returns ['level' => 1-17, 'modulation' => 'FSK/4PSK/8PSK/16QAM/32QAM', 'quality' => 'Poor/Fair/Good/Excellent']
 */
function getVaraLevel($maxBps) {
    // VARA HF 2300 Hz Standard mode speed table
    $levels = [
        ['level' =>  1, 'bps' =>   18, 'mod' => 'FSK',   'quality' => 'Minimal'],
        ['level' =>  2, 'bps' =>   41, 'mod' => 'FSK',   'quality' => 'Minimal'],
        ['level' =>  3, 'bps' =>   82, 'mod' => 'FSK',   'quality' => 'Poor'],
        ['level' =>  4, 'bps' =>  175, 'mod' => 'FSK',   'quality' => 'Poor'],
        ['level' =>  5, 'bps' =>  270, 'mod' => '4PSK',  'quality' => 'Fair'],
        ['level' =>  6, 'bps' =>  363, 'mod' => '4PSK',  'quality' => 'Fair'],
        ['level' =>  7, 'bps' =>  549, 'mod' => '4PSK',  'quality' => 'Fair'],
        ['level' =>  8, 'bps' =>  735, 'mod' => '4PSK',  'quality' => 'Good'],
        ['level' =>  9, 'bps' =>  922, 'mod' => '4PSK',  'quality' => 'Good'],
        ['level' => 10, 'bps' => 2011, 'mod' => '4PSK',  'quality' => 'Good'],
        ['level' => 11, 'bps' => 2682, 'mod' => '4PSK',  'quality' => 'Very Good'],
        ['level' => 12, 'bps' => 3219, 'mod' => '4PSK',  'quality' => 'Very Good'],
        ['level' => 13, 'bps' => 4025, 'mod' => '8PSK',  'quality' => 'Excellent'],
        ['level' => 14, 'bps' => 4830, 'mod' => '8PSK',  'quality' => 'Excellent'],
        ['level' => 15, 'bps' => 5872, 'mod' => '16QAM', 'quality' => 'Excellent'],
        ['level' => 16, 'bps' => 7050, 'mod' => '32QAM', 'quality' => 'Outstanding'],
        ['level' => 17, 'bps' => 8489, 'mod' => '32QAM', 'quality' => 'Outstanding'],
    ];
    
    $best = $levels[0];
    foreach ($levels as $l) {
        if ($maxBps >= $l['bps']) $best = $l;
    }
    return $best;
}

/**
 * Compute VARA stats summary from max_bps_values array
 */
function computeVaraStats($maxBpsValues) {
    if (empty($maxBpsValues)) return null;
    $avgBps = round(array_sum($maxBpsValues) / count($maxBpsValues));
    $peakBps = max($maxBpsValues);
    $avgLevel = getVaraLevel($avgBps);
    $peakLevel = getVaraLevel($peakBps);
    return [
        'avg_bps' => $avgBps,
        'peak_bps' => $peakBps,
        'avg_level' => $avgLevel['level'],
        'avg_mod' => $avgLevel['mod'],
        'avg_quality' => $avgLevel['quality'],
        'peak_level' => $peakLevel['level'],
        'peak_mod' => $peakLevel['mod'],
        'peak_quality' => $peakLevel['quality'],
        'sessions' => count($maxBpsValues),
    ];
}

// ========== SYSLOG CACHING (original below) ==========

/**
 * Build hub node array and edge array from hub connection data.
 * Used by both 'overview' and 'network_history' endpoints.
 * $hubConnsMap: hub => station => {count, snr_values[], bytes_tx, bytes_rx, max_bps_values[]}
 * $hubToHubMap: "HUB1|HUB2" => {count, snr_values[], max_bps_values[]}
 * $hubsConfig:  callsign => hub data (array or string)
 * Returns ['nodes' => [], 'edges' => [], 'bytes_tx' => int, 'bytes_rx' => int]
 */
function buildHubNodesAndEdges($hubsConfig, $hubConnsMap, $hubToHubMap, $stationMessages = []) {
    $nodes = [];
    $edges = [];
    $totalBytesTx = 0;
    $totalBytesRx = 0;
    
    foreach ($hubsConfig as $callsign => $data) {
        $callUpper = strtoupper($callsign);
        $hubConns = $hubConnsMap[$callUpper] ?? [];
        $totalConnections = 0; $snrValues = [];
        $hubTx = 0; $hubRx = 0; $hubBpsValues = [];
        foreach ($hubConns as $stationData) {
            $totalConnections += $stationData['count'];
            $hubTx += $stationData['bytes_tx'] ?? 0;
            $hubRx += $stationData['bytes_rx'] ?? 0;
            if (!empty($stationData['snr_values'])) $snrValues = array_merge($snrValues, $stationData['snr_values']);
            if (!empty($stationData['max_bps_values'])) $hubBpsValues = array_merge($hubBpsValues, $stationData['max_bps_values']);
        }
        $totalBytesTx += $hubTx;
        $totalBytesRx += $hubRx;
        $avgSnr = count($snrValues) > 0 ? round(array_sum($snrValues) / count($snrValues), 1) : null;
        
        $nodeData = ['id' => $callsign, 'label' => $callsign, 'connections' => $totalConnections, 'avg_snr' => $avgSnr, 'isHub' => true,
            'bytes_tx' => $hubTx, 'bytes_rx' => $hubRx, 'est_messages' => $stationMessages[strtoupper($callsign)] ?? 0];
        $varaStats = computeVaraStats($hubBpsValues);
        if ($varaStats) $nodeData['vara'] = $varaStats;
        if (is_array($data)) {
            $nodeData['description'] = $data['desc'] ?? '';
            $nodeData['lat'] = $data['lat'] ?? null;
            $nodeData['lon'] = $data['lon'] ?? null;
            $nodeData['grid'] = $data['grid'] ?? null;
        } else {
            $nodeData['description'] = $data;
        }
        $nodes[] = $nodeData;
    }
    
    foreach ($hubToHubMap as $key => $hdata) {
        if ($hdata['count'] > 0) {
            $parts = explode('|', $key);
            $avgSnr = count($hdata['snr_values']) > 0 ? round(array_sum($hdata['snr_values']) / count($hdata['snr_values']), 1) : null;
            $edge = ['from' => $parts[0], 'to' => $parts[1], 'count' => $hdata['count'], 'avg_snr' => $avgSnr];
            $varaStats = computeVaraStats($hdata['max_bps_values'] ?? []);
            if ($varaStats) $edge['vara'] = $varaStats;
            $edges[] = $edge;
        }
    }
    
    return ['nodes' => $nodes, 'edges' => $edges, 'bytes_tx' => $totalBytesTx, 'bytes_rx' => $totalBytesRx];
}

/**
 * Save a daily snapshot of parsed data before it's lost to log rotation.
 * Keeps up to 30 days of snapshots in cache/snapshot-YYYY-MM-DD.json.
 * Each snapshot stores per-hub connection data (stations, counts, S/N, bytes).
 */
function saveDailySnapshot($cacheDir, $parsed, $date = null) {
    if (!$parsed || empty($parsed['hubConnections'])) return;
    
    // Default to yesterday's date (snapshot represents the day that just ended)
    if (!$date) $date = date('Y-m-d', strtotime('-1 day'));
    
    $snapshotFile = $cacheDir . '/snapshot-' . $date . '.json';
    
    // Don't overwrite if already exists (only save once per day)
    if (file_exists($snapshotFile)) return;
    
    // Build a compact snapshot — hub connection data + hub-to-hub edges for history
    $snapshot = [
        'date' => $date,
        'saved_at' => time(),
        'estMessages' => $parsed['estMessages'] ?? 0,
        'totalBytesTx' => 0,
        'totalBytesRx' => 0,
        'hubConnections' => [],
        'hubToHub' => [],
        'stationSessions' => $parsed['stationSessions'] ?? [],
    ];
    
    foreach ($parsed['hubConnections'] as $hub => $stations) {
        $snapshot['hubConnections'][$hub] = [];
        foreach ($stations as $station => $data) {
            $avgSnr = !empty($data['snr_values']) 
                ? round(array_sum($data['snr_values']) / count($data['snr_values']), 1) 
                : null;
            $snapshot['hubConnections'][$hub][$station] = [
                'count' => $data['count'],
                'avg_snr' => $avgSnr,
                'bytes_tx' => $data['bytes_tx'] ?? 0,
                'bytes_rx' => $data['bytes_rx'] ?? 0,
                'vara' => computeVaraStats($data['max_bps_values'] ?? []),
                'max_bps_values' => $data['max_bps_values'] ?? [],
            ];
            $snapshot['totalBytesTx'] += $data['bytes_tx'] ?? 0;
            $snapshot['totalBytesRx'] += $data['bytes_rx'] ?? 0;
        }
    }
    
    // Save pre-computed hub-to-hub edges
    foreach (($parsed['hubToHub'] ?? []) as $key => $data) {
        if ($data['count'] > 0) {
            $avgSnr = !empty($data['snr_values'])
                ? round(array_sum($data['snr_values']) / count($data['snr_values']), 1)
                : null;
            $snapshot['hubToHub'][$key] = [
                'count' => $data['count'],
                'avg_snr' => $avgSnr,
                'vara' => computeVaraStats($data['max_bps_values'] ?? []),
                'max_bps_values' => $data['max_bps_values'] ?? [],
            ];
        }
    }
    
    file_put_contents($snapshotFile, json_encode($snapshot), LOCK_EX);
    
    // Prune old snapshots — keep only the last 30 days
    $files = glob($cacheDir . '/snapshot-*.json');
    if (count($files) > 30) {
        sort($files); // Oldest first (alphabetical = chronological for YYYY-MM-DD)
        $toDelete = array_slice($files, 0, count($files) - 30);
        foreach ($toDelete as $old) {
            @unlink($old);
        }
    }
}

function getParsedSyslog($syslogPath, $hubIds, $cacheDir, $cacheTTL) {
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/syslog-parsed.json';
    $cacheMetaFile = $cacheDir . '/syslog-meta.json';
    $todayUTC = gmdate('Y-m-d');
    
    if (file_exists($cacheFile) && file_exists($cacheMetaFile)) {
        $meta = json_decode(file_get_contents($cacheMetaFile), true);
        $syslogMtime = filemtime($syslogPath);
        $syslogSize = filesize($syslogPath);
        
        // Check if UTC day changed since last parse — save yesterday's data as snapshot
        $lastParseDate = $meta['parse_date_utc'] ?? '';
        if ($lastParseDate && $lastParseDate !== $todayUTC) {
            // Day rolled over — the cached data is yesterday's full day
            $previousParsed = json_decode(file_get_contents($cacheFile), true);
            if ($previousParsed && !empty($previousParsed['hubConnections'])) {
                saveDailySnapshot($cacheDir, $previousParsed, $lastParseDate);
            }
        }
        
        // Check if syslog has rotated (different file identity)
        $oldSyslogId = ($meta['syslog_size'] ?? 0) . '-' . ($meta['syslog_mtime'] ?? 0);
        $newSyslogId = $syslogSize . '-' . $syslogMtime;
        
        if ($oldSyslogId !== $newSyslogId && $syslogSize < ($meta['syslog_size'] ?? 0)) {
            // Syslog rotated (new file is smaller than old) — save previous data as snapshot
            // Only if we haven't already saved it from date-change detection above
            $previousParsed = json_decode(file_get_contents($cacheFile), true);
            if ($previousParsed && !empty($previousParsed['hubConnections'])) {
                saveDailySnapshot($cacheDir, $previousParsed);
            }
        }
        
        if ($meta
            && ($meta['parse_date_utc'] ?? '') === $todayUTC
            && (time() - ($meta['parsed_at'] ?? 0)) < $cacheTTL
            && ($meta['syslog_mtime'] ?? 0) === $syslogMtime
            && ($meta['syslog_size'] ?? 0) === $syslogSize) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) return $cached;
        }
    }
    
    $parsed = parseSyslogFull($syslogPath, $hubIds);
    if (!$parsed) return null;
    
    // Write cache atomically via tmp files
    $tmpCache = $cacheFile . '.tmp';
    $tmpMeta = $cacheMetaFile . '.tmp';
    
    file_put_contents($tmpCache, json_encode($parsed), LOCK_EX);
    file_put_contents($tmpMeta, json_encode([
        'parsed_at'      => time(),
        'parse_date_utc' => gmdate('Y-m-d'),
        'syslog_mtime'   => filemtime($syslogPath),
        'syslog_size'    => filesize($syslogPath),
        'hub_count'      => count($hubIds),
    ]), LOCK_EX);
    
    rename($tmpCache, $cacheFile);
    rename($tmpMeta, $cacheMetaFile);

    // ── MySQL dual-write: persist today's completed sessions ─────────────────
    // Fire-and-forget — runs after cache is written; errors are logged not thrown
    global $DB_ENABLED;
    if ($DB_ENABLED) {
        tprfn_db_write_sessions($parsed, gmdate('Y-m-d'), array_map('strtoupper', $hubIds));
    }

    return $parsed;
}

/**
 * Write completed sessions from a fresh syslog parse into MySQL.
 * Called once per parse cycle (every 2 min max). Skips dates already fully
 * committed (tracked via import_log). Today's date is always re-written
 * since the day is still in progress.
 */
function tprfn_db_write_sessions(array $parsed, string $todayDate, array $hubIds): void {
    try {
        $pdo  = tprfn_db();
        $year = (int)date('Y');
        $hubIdSet = array_flip($hubIds);

        foreach ($parsed['stationSessions'] ?? [] as $key => $sessions) {
            $parts   = explode('|', $key);
            $hub     = strtoupper($parts[0] ?? '');
            $station = strtoupper($parts[1] ?? '');
            if (!$hub || !$station) continue;

            $isH2H = isset($hubIdSet[$station]) ? 1 : 0;

            foreach ($sessions as $sess) {
                // Build timestamps
                $connectedAt = null;
                if (!empty($sess['time'])) {
                    if (preg_match('/(\d{2}:\d{2}:\d{2})$/', $sess['time'], $tm)) {
                        $connectedAt = $todayDate . ' ' . $tm[1];
                    }
                }
                $durationSecs = tprfn_duration_to_secs($sess['duration'] ?? null);
                $disconnectedAt = null;
                if ($connectedAt && $durationSecs) {
                    $disconnectedAt = date('Y-m-d H:i:s', strtotime($connectedAt) + $durationSecs);
                }

                tprfn_insert_session($pdo, [
                    'hub'             => $hub,
                    'station'         => $station,
                    'direction'       => 'incoming',
                    'is_hub_to_hub'   => $isH2H,
                    'session_date'    => $todayDate,
                    'connected_at'    => $connectedAt,
                    'disconnected_at' => $disconnectedAt,
                    'duration_secs'   => $durationSecs,
                    'avg_snr'         => isset($sess['snr']) ? (float)$sess['snr'] : null,
                    'bytes_tx'        => (int)($sess['bytes_tx'] ?? 0),
                    'bytes_rx'        => (int)($sess['bytes_rx'] ?? 0),
                    'max_bps'         => (int)($sess['max_bps'] ?? 0),
                    'source'          => 'syslog',
                    'syslog_year'     => $year,
                ]);

                // Upsert station record
                tprfn_upsert_station($pdo, $station, $todayDate);
            }
        }
    } catch (Throwable $e) {
        error_log('TPRFN DB write error: ' . $e->getMessage());
    }
}

function parseSyslogFull($syslogPath, $hubIds) {
    $hubIdSet = array_flip(array_map('strtoupper', $hubIds));
    
    $hubConnections = [];
    $hubToHub = [];
    $stationSessions = [];
    $allStations = [];
    $currentSessions = [];
    $estMessages = 0; // Estimated messages (calculated after dedup)
    $msgCandidates = []; // Collect sessions that might be messages for dedup
    
    // Build today's UTC date prefix for filtering
    // Syslog format: "Feb 17" (double digit) or "Feb  7" (single digit with leading space)
    // Only count connections from 00:00 UTC to 23:59 UTC today
    $dayOfMonth = (int)gmdate('j');
    $todayPrefix = gmdate('M') . ($dayOfMonth < 10 ? '  ' : ' ') . $dayOfMonth;
    
    $handle = fopen($syslogPath, 'r');
    if (!$handle) return null;
    
    while (($line = fgets($handle)) !== false) {
        $line = cleanLine($line);
        if (empty($line)) continue;
        
        // Skip lines not from today (UTC)
        if (strncmp($line, $todayPrefix, strlen($todayPrefix)) !== 0) continue;
        
        // INCOMING: "H-STATION1 VARAHF STATION2 connected"
        if (preg_match('/^(\w+\s+\d+\s+[\d:]+)\s+H-([A-Z0-9]+-?\d*)\s+VARAHF?\s+([A-Z0-9]+-?\d*)\s+connected/i', $line, $m)) {
            $time = $m[1];
            $hub = strtoupper($m[2]);
            $station = strtoupper($m[3]);
            
            if (isset($hubIdSet[$hub])) {
                if (!isset($hubConnections[$hub])) $hubConnections[$hub] = [];
                if (!isset($hubConnections[$hub][$station])) {
                    $hubConnections[$hub][$station] = ['count' => 0, 'snr_values' => [], 'bytes_tx' => 0, 'bytes_rx' => 0, 'max_bps_values' => []];
                }
                $hubConnections[$hub][$station]['count']++;
            }
            
            if (isset($hubIdSet[$hub]) && isset($hubIdSet[$station])) {
                $sorted = [$hub, $station]; sort($sorted);
                $key = implode('|', $sorted);
                if (!isset($hubToHub[$key])) $hubToHub[$key] = ['count' => 0, 'snr_values' => [], 'max_bps_values' => []];
                $hubToHub[$key]['count']++;
            }
            
            if (isset($hubIdSet[$hub]) && !isset($hubIdSet[$station])) {
                $asKey = "$station|$hub";
                if (!isset($allStations[$asKey])) {
                    $allStations[$asKey] = ['id' => $station, 'hub' => $hub, 'connections' => 0, 'snr_values' => []];
                }
                $allStations[$asKey]['connections']++;
            }
            
            $sessionKey = "$hub|$station";
            $currentSessions[$sessionKey] = [
                'time' => $time, 'snr' => null,
                'bytes_tx' => 0, 'bytes_rx' => 0,
                'duration' => null, 'max_bps' => 0
            ];
            continue;
        }
        
        // OUTGOING: "H-STATION1 VARAHF Connected to STATION2"
        if (preg_match('/^(\w+\s+\d+\s+[\d:]+)\s+H-([A-Z0-9]+-?\d*)\s+VARAHF?\s+Connected to\s+([A-Z0-9]+-?\d*)/i', $line, $m)) {
            $time = $m[1];
            $hub = strtoupper($m[2]);
            $station = strtoupper($m[3]);
            
            if (isset($hubIdSet[$hub])) {
                if (!isset($hubConnections[$hub])) $hubConnections[$hub] = [];
                if (!isset($hubConnections[$hub][$station])) {
                    $hubConnections[$hub][$station] = ['count' => 0, 'snr_values' => [], 'bytes_tx' => 0, 'bytes_rx' => 0, 'max_bps_values' => []];
                }
                $hubConnections[$hub][$station]['count']++;
            }
            
            if (isset($hubIdSet[$hub]) && isset($hubIdSet[$station])) {
                $sorted = [$hub, $station]; sort($sorted);
                $key = implode('|', $sorted);
                if (!isset($hubToHub[$key])) $hubToHub[$key] = ['count' => 0, 'snr_values' => [], 'max_bps_values' => []];
                $hubToHub[$key]['count']++;
            }
            
            if (isset($hubIdSet[$hub]) && !isset($hubIdSet[$station])) {
                $asKey = "$station|$hub";
                if (!isset($allStations[$asKey])) {
                    $allStations[$asKey] = ['id' => $station, 'hub' => $hub, 'connections' => 0, 'snr_values' => []];
                }
                $allStations[$asKey]['connections']++;
            }
            
            $sessionKey = "$hub|$station";
            if (!isset($currentSessions[$sessionKey])) {
                $currentSessions[$sessionKey] = [
                    'time' => $time, 'snr' => null,
                    'bytes_tx' => 0, 'bytes_rx' => 0,
                    'duration' => null, 'max_bps' => 0
                ];
            }
            continue;
        }
        
        // S/N VALUES
        if (preg_match('/H-([A-Z0-9]+-?\d*)\s+VARAHF?\s+([A-Z0-9]+-?\d*)\s+Average S\/N:\s+([-\d.]+)\s+dB/i', $line, $m)) {
            $hub = strtoupper($m[1]);
            $station = strtoupper($m[2]);
            $snr = floatval($m[3]);
            
            if (isset($hubConnections[$hub][$station])) {
                $hubConnections[$hub][$station]['snr_values'][] = $snr;
            }
            if (isset($hubIdSet[$hub]) && isset($hubIdSet[$station])) {
                $sorted = [$hub, $station]; sort($sorted);
                $key = implode('|', $sorted);
                if (isset($hubToHub[$key])) $hubToHub[$key]['snr_values'][] = $snr;
            }
            $asKey = "$station|$hub";
            if (isset($allStations[$asKey])) {
                $allStations[$asKey]['snr_values'][] = $snr;
            }
            $sessionKey = "$hub|$station";
            if (isset($currentSessions[$sessionKey])) {
                $currentSessions[$sessionKey]['snr'] = $snr;
            }
            continue;
        }
        
        // DISCONNECT with stats (includes normal and timeout disconnects)
        if (preg_match('/H-([A-Z0-9]+-?\d*)\s+VARAHF?\s+Disconnected(?:\s*\(Timeout\))?\s+TX:\s+(\d+)\s+Bytes\s+\(Max:\s+(\d+)\s+bps\)\s+RX:\s+(\d+)\s+Bytes\s+\(Max:\s+(\d+)\s+bps\).*?Session Time:\s+([\d:]+)/i', $line, $m)) {
            $hub = strtoupper($m[1]);
            $bytesTx = intval($m[2]);
            $bytesRx = intval($m[4]);
            $maxBps = max(intval($m[3]), intval($m[5])); // Use higher of TX/RX max bps
            foreach ($currentSessions as $sessionKey => $session) {
                if (strpos($sessionKey, "$hub|") === 0) {
                    $session['bytes_tx'] = $bytesTx;
                    $session['max_bps'] = $maxBps;
                    $session['bytes_rx'] = $bytesRx;
                    $session['duration'] = $m[6];
                    if (!isset($stationSessions[$sessionKey])) $stationSessions[$sessionKey] = [];
                    $stationSessions[$sessionKey][] = $session;
                    
                    // Accumulate bytes into hubConnections for this hub+station
                    $station = substr($sessionKey, strlen($hub) + 1);
                    if (isset($hubConnections[$hub][$station])) {
                        $hubConnections[$hub][$station]['bytes_tx'] += $bytesTx;
                        $hubConnections[$hub][$station]['bytes_rx'] += $bytesRx;
                        if ($maxBps > 0) $hubConnections[$hub][$station]['max_bps_values'][] = $maxBps;
                    }
                    
                    // Accumulate max_bps into hubToHub for hub-to-hub edges
                    if (isset($hubIdSet[$hub]) && isset($hubIdSet[$station])) {
                        $sorted = [$hub, $station]; sort($sorted);
                        $h2hKey = implode('|', $sorted);
                        if (isset($hubToHub[$h2hKey]) && $maxBps > 0) {
                            $hubToHub[$h2hKey]['max_bps_values'][] = $maxBps;
                        }
                    }
                    
                    // Collect message candidates for deduplication
                    // CMS ground truth: real messages always have TX+RX >= 446 bytes
                    // Sessions below 446 total are FBB handshakes/negotiations only
                    $totalBytes = $bytesTx + $bytesRx;
                    if ($totalBytes >= 446) {
                        // Normalize key: smaller value first so mirror pairs match
                        $bLow = min($bytesTx, $bytesRx);
                        $bHigh = max($bytesTx, $bytesRx);
                        $dedupKey = "$bLow|$bHigh";
                        $msgCandidates[] = ['key' => $dedupKey, 'hub' => $hub, 'station' => $station];
                    }
                    
                    unset($currentSessions[$sessionKey]);
                    break;
                }
            }
            continue;
        }
    }
    fclose($handle);
    
    // Deduplicate estimated messages: hub-to-hub forwards log on BOTH sides
    // with mirrored TX/RX bytes (Hub A: TX=3955 RX=97, Hub B: TX=97 RX=3955)
    // Count each mirror pair as ONE message, not two
    $msgKeyCounts = [];
    foreach ($msgCandidates as $mc) {
        $k = $mc['key'];
        if (!isset($msgKeyCounts[$k])) $msgKeyCounts[$k] = 0;
        $msgKeyCounts[$k]++;
    }
    foreach ($msgKeyCounts as $k => $count) {
        // Each pair of matching sessions = 1 message (hub-to-hub forward)
        // Each unpaired session = 1 message (polling station or asymmetric)
        $estMessages += intdiv($count + 1, 2);
    }
    
    // Per-station message counts (each station sees its own message activity)
    // Uses 446-byte threshold but does NOT dedup — a forwarded message counts
    // for both the sending and receiving hub since both handled it
    $stationMessages = [];
    foreach ($msgCandidates as $mc) {
        $hub = $mc['hub'];
        $station = $mc['station'];
        if (!isset($stationMessages[$hub])) $stationMessages[$hub] = 0;
        $stationMessages[$hub]++;
        if (!isset($stationMessages[$station])) $stationMessages[$station] = 0;
        $stationMessages[$station]++;
    }
    
    foreach ($allStations as $call => &$s) {
        $s['avg_snr'] = !empty($s['snr_values'])
            ? round(array_sum($s['snr_values']) / count($s['snr_values']), 1) : null;
        unset($s['snr_values']);
    }
    unset($s);
    
    return [
        'hubConnections'  => $hubConnections,
        'hubToHub'        => $hubToHub,
        'stationSessions' => $stationSessions,
        'allStations'     => array_values($allStations),
        'estMessages'     => $estMessages,
        'stationMessages' => $stationMessages,
        'parsed_at'       => time(),
    ];
}

function getHubIds($configFile) {
    $hubs = getHubsFull($configFile);
    return array_keys($hubs);
}

function getHubsFull($configFile) {
    $hubs = [];
    if (file_exists($configFile)) {
        $phpHubs = include($configFile);
        if (is_array($phpHubs)) $hubs = $phpHubs;
    }
    // Merge admin-added hubs from JSON
    $jsonFile = dirname($configFile) . '/tprfn-hubs.json';
    if (file_exists($jsonFile)) {
        $jsonHubs = json_decode(file_get_contents($jsonFile), true);
        if (is_array($jsonHubs)) $hubs = array_merge($hubs, $jsonHubs);
    }
    return $hubs;
}

/**
 * Persistent monthly byte totals.
 * 
 * Survives syslog rotation by storing accumulated totals in a JSON file.
 * Each syslog parse adds its daily TX/RX to the running monthly total.
 * Resets automatically on the 1st of each month.
 * 
 * File format (cache/monthly-totals.json):
 * {
 *   "month": "2026-02",
 *   "bytes_tx": 123456,
 *   "bytes_rx": 789012,
 *   "last_syslog_id": "1234567-1739145600",  // size-mtime of last counted syslog
 *   "last_daily_tx": 5000,                     // TX from last syslog parse (to avoid double-counting)
 *   "last_daily_rx": 3000,
 *   "updated_at": 1739145600
 * }
 */
function getMonthlyTotals($cacheDir, $currentDailyTx, $currentDailyRx, $currentDailyMsgs, $syslogPath) {
    $currentMonth = gmdate('Y-m'); // e.g. "2026-03"
    
    // Sum from daily snapshots for all days in this month (excluding today)
    $monthlyTx = 0;
    $monthlyRx = 0;
    $monthlyMsgs = 0;
    $todayDate = gmdate('Y-m-d');
    
    $snapshotFiles = glob($cacheDir . '/snapshot-' . $currentMonth . '-*.json');
    foreach ($snapshotFiles as $file) {
        $snap = @json_decode(@file_get_contents($file), true);
        if (!$snap) continue;
        
        // Skip today's snapshot if one exists (we use live data for today)
        if (($snap['date'] ?? '') === $todayDate) continue;
        
        // Use stored totals if available (v1.0.4+ snapshots)
        if (isset($snap['estMessages'])) {
            $monthlyMsgs += $snap['estMessages'];
            $monthlyTx += $snap['totalBytesTx'] ?? 0;
            $monthlyRx += $snap['totalBytesRx'] ?? 0;
        } else {
            // Fallback for older snapshots without estMessages:
            // Build message candidates from session counts, then dedup
            // using the same (count+1)/2 formula as the live parser
            $msgKeyCounts = [];
            foreach ($snap['hubConnections'] ?? [] as $hub => $stations) {
                foreach ($stations as $station => $data) {
                    $tx = $data['bytes_tx'] ?? 0;
                    $rx = $data['bytes_rx'] ?? 0;
                    $monthlyTx += $tx;
                    $monthlyRx += $rx;
                    $count = $data['count'] ?? 0;
                    if (($tx + $rx) >= 446 && $count > 0) {
                        $key = strtoupper($hub) . ':' . strtoupper($station);
                        $msgKeyCounts[$key] = ($msgKeyCounts[$key] ?? 0) + $count;
                    }
                }
            }
            foreach ($msgKeyCounts as $k => $count) {
                $monthlyMsgs += intdiv($count + 1, 2);
            }
        }
    }
    
    // Add today's live data
    $monthlyTx += $currentDailyTx;
    $monthlyRx += $currentDailyRx;
    $monthlyMsgs += $currentDailyMsgs;
    
    return [
        'month' => $currentMonth,
        'bytes_tx' => $monthlyTx,
        'bytes_rx' => $monthlyRx,
        'est_messages' => $monthlyMsgs,
        'updated_at' => time()
    ];
}

function requireSyslog($path) {
    if (!file_exists($path) || !is_readable($path)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Log data currently unavailable. Contact the server administrator.']);
        error_log("TPRFN: Syslog not available at $path");
        exit;
    }
}

// ========== REQUEST ROUTING ==========

$action = isset($_GET['action']) ? $_GET['action'] : 'overview';

switch ($action) {

    case 'overview':
        header('Content-Type: application/json');
        requireSyslog($SYSLOG_PATH);
        
        $hubs = getHubsFull($HUBS_CONFIG);
        $hubIds = array_keys($hubs);
        
        $parsed = getParsedSyslog($SYSLOG_PATH, $hubIds, $CACHE_DIR, $CACHE_TTL);
        if (!$parsed) { echo json_encode(['error' => 'Failed to parse log data']); exit; }
        
        $result = buildHubNodesAndEdges($hubs, $parsed['hubConnections'], $parsed['hubToHub'], $parsed['stationMessages'] ?? []);
        $nodes = $result['nodes'];
        $edges = $result['edges'];
        $totalBytesTx = $result['bytes_tx'];
        $totalBytesRx = $result['bytes_rx'];
        
        
        // Calculate persistent monthly totals (survives log rotation)
        $dailyMsgs = $parsed['estMessages'] ?? 0;
        $monthly = getMonthlyTotals($CACHE_DIR, $totalBytesTx, $totalBytesRx, $dailyMsgs, $SYSLOG_PATH);
        
        echo json_encode([
            'nodes' => $nodes, 'edges' => $edges,
            'stats' => [
                'total_stations' => count($nodes),
                'total_connections' => array_sum(array_column($nodes, 'connections')),
                'unique_links' => count($edges),
                'total_bytes_tx' => $totalBytesTx,
                'total_bytes_rx' => $totalBytesRx,
                'est_messages' => $dailyMsgs,
                'monthly_bytes_tx' => $monthly['bytes_tx'] ?? 0,
                'monthly_bytes_rx' => $monthly['bytes_rx'] ?? 0,
                'monthly_est_messages' => $monthly['est_messages'] ?? 0,
                'month_label' => gmdate('F Y')
            ]
        ]);
        break;

    case 'hubs':
        header('Content-Type: application/json');
        if (!file_exists($HUBS_CONFIG)) { http_response_code(404); echo json_encode(['error' => 'Hub configuration not found']); exit; }
        $hubs = getHubsFull($HUBS_CONFIG);
        $hubList = [];
        foreach ($hubs as $callsign => $data) {
            if (is_array($data)) {
                $hubList[] = ['id' => $callsign, 'label' => $callsign, 'description' => $data['desc'] ?? '', 'lat' => $data['lat'] ?? null, 'lon' => $data['lon'] ?? null, 'grid' => $data['grid'] ?? null];
            } else {
                $hubList[] = ['id' => $callsign, 'label' => $callsign, 'description' => $data];
            }
        }
        echo json_encode(['hubs' => $hubList]);
        break;

    case 'station_detail':
        header('Content-Type: application/json');
        $hubId = isset($_GET['hub']) ? strtoupper(trim($_GET['hub'])) : '';
        $stationId = isset($_GET['station']) ? strtoupper(trim($_GET['station'])) : '';
        $days = isset($_GET['days']) ? intval($_GET['days']) : 0;
        if (empty($hubId) || empty($stationId)) { http_response_code(400); echo json_encode(['error' => 'Hub and station IDs required']); exit; }
        if (!isValidCallsign($hubId) || !isValidCallsign($stationId)) { http_response_code(400); echo json_encode(['error' => 'Invalid callsign format']); exit; }
        requireSyslog($SYSLOG_PATH);
        $hubIds = getHubIds($HUBS_CONFIG);
        $parsed = getParsedSyslog($SYSLOG_PATH, $hubIds, $CACHE_DIR, $CACHE_TTL);
        if (!$parsed) { echo json_encode(['error' => 'Failed to parse log data']); exit; }
        
        // Today's sessions
        $connections = $parsed['stationSessions']["$hubId|$stationId"] ?? [];
        $reverse = $parsed['stationSessions']["$stationId|$hubId"] ?? [];
        $connections = array_merge($connections, $reverse);
        
        // Include snapshot sessions for multi-day views
        if ($days > 0) {
            $snapshots = glob($CACHE_DIR . '/snapshot-*.json');
            sort($snapshots);
            $snapshots = array_slice($snapshots, -$days);
            foreach ($snapshots as $file) {
                $snap = json_decode(file_get_contents($file), true);
                if (!$snap) continue;
                // Check stationSessions in snapshot
                if (!empty($snap['stationSessions'])) {
                    $snapConns = $snap['stationSessions']["$hubId|$stationId"] ?? [];
                    $snapRev = $snap['stationSessions']["$stationId|$hubId"] ?? [];
                    $connections = array_merge($connections, $snapConns, $snapRev);
                }
            }
            // Sort by time descending
            usort($connections, function($a, $b) {
                return strcmp($b['time'] ?? '', $a['time'] ?? '');
            });
        }
        
        echo json_encode(['hub' => $hubId, 'station' => $stationId, 'connections' => $connections, 'total_sessions' => count($connections), 'days' => $days]);
        break;

    case 'hub':
        header('Content-Type: application/json');
        $hubId = isset($_GET['id']) ? strtoupper(trim($_GET['id'])) : '';
        if (empty($hubId) || !isValidCallsign($hubId)) { http_response_code(400); echo json_encode(['error' => 'Valid Hub ID required']); exit; }
        requireSyslog($SYSLOG_PATH);
        $hubIds = getHubIds($HUBS_CONFIG);
        $parsed = getParsedSyslog($SYSLOG_PATH, $hubIds, $CACHE_DIR, $CACHE_TTL);
        if (!$parsed) { echo json_encode(['error' => 'Failed to parse log data']); exit; }
        $stationMessages = $parsed['stationMessages'] ?? [];
        $connections = $parsed['hubConnections'][$hubId] ?? [];
        $nodes = []; $edges = [];
        foreach ($connections as $station => $data) {
            if ($data['count'] > 0) {
                $avgSnr = count($data['snr_values']) > 0 ? round(array_sum($data['snr_values']) / count($data['snr_values']), 1) : null;
                $node = ['id' => $station, 'label' => $station, 'connections' => $data['count'], 'avg_snr' => $avgSnr,
                    'bytes_tx' => $data['bytes_tx'] ?? 0, 'bytes_rx' => $data['bytes_rx'] ?? 0,
                    'est_messages' => $stationMessages[$station] ?? 0];
                $edge = ['from' => $hubId, 'to' => $station, 'count' => $data['count'], 'avg_snr' => $avgSnr,
                    'bytes_tx' => $data['bytes_tx'] ?? 0, 'bytes_rx' => $data['bytes_rx'] ?? 0];
                $varaStats = computeVaraStats($data['max_bps_values'] ?? []);
                if ($varaStats) { $node['vara'] = $varaStats; $edge['vara'] = $varaStats; }
                $nodes[] = $node;
                $edges[] = $edge;
            }
        }
        echo json_encode(['hub' => $hubId, 'nodes' => $nodes, 'edges' => $edges, 'stats' => ['total_stations' => count($nodes), 'total_connections' => array_sum(array_column($edges, 'count'))]]);
        break;

    case 'network_history':
        header('Content-Type: application/json');
        $days = isset($_GET['days']) ? intval($_GET['days']) : 5;
        if ($days < 1) $days = 1;
        // With MySQL, allow unlimited history; without DB cap at 5 (snapshot limit)
        if (!$DB_ENABLED && $days > 5) $days = 5;
        if ($days > 365) $days = 365; // hard cap at 1 year
        
        $hubs = getHubsFull($HUBS_CONFIG);
        $hubIds = array_keys($hubs);
        $hubIdSet = array_flip(array_map('strtoupper', $hubIds));
        
        // Collect snapshot files — for days beyond snapshot retention, supplement from MySQL
        $snapshots = glob($CACHE_DIR . '/snapshot-*.json');
        sort($snapshots);
        $nhAvailableSnapDays = count($snapshots);
        $nhUseDb = $DB_ENABLED && $days > $nhAvailableSnapDays;
        $snapshots = array_slice($snapshots, -min($days, $nhAvailableSnapDays));
        
        // Also get today's live data
        requireSyslog($SYSLOG_PATH);
        $parsed = getParsedSyslog($SYSLOG_PATH, $hubIds, $CACHE_DIR, $CACHE_TTL);
        
        // Merge hub nodes across all days
        $mergedHubConns = [];   // hub => station => {count, snr_values[], bytes_tx, bytes_rx}
        $mergedHubToHub = [];   // "HUB1|HUB2" => {count, snr_values[]}
        $mergedAllStations = []; // station => {connections, snr_values[], hub}
        $datesUsed = [];
        
        // Process snapshots
        foreach ($snapshots as $file) {
            $snap = json_decode(file_get_contents($file), true);
            if (!$snap || empty($snap['hubConnections'])) continue;
            $datesUsed[] = $snap['date'];
            
            // Use pre-computed hub-to-hub edges from snapshot
            foreach (($snap['hubToHub'] ?? []) as $key => $data) {
                if (!isset($mergedHubToHub[$key])) $mergedHubToHub[$key] = ['count' => 0, 'snr_values' => [], 'max_bps_values' => []];
                $mergedHubToHub[$key]['count'] += $data['count'];
                if ($data['avg_snr'] !== null) $mergedHubToHub[$key]['snr_values'][] = $data['avg_snr'];
                // Use raw max_bps_values if saved (new snapshots), otherwise reconstruct from VARA stats
                if (!empty($data['max_bps_values'])) {
                    $mergedHubToHub[$key]['max_bps_values'] = array_merge($mergedHubToHub[$key]['max_bps_values'], $data['max_bps_values']);
                } elseif (!empty($data['vara']) && isset($data['vara']['peak_bps'])) {
                    $varaSessions = $data['vara']['sessions'] ?? $data['count'];
                    for ($i = 0; $i < $varaSessions; $i++) {
                        $mergedHubToHub[$key]['max_bps_values'][] = $data['vara']['avg_bps'];
                    }
                    if ($data['vara']['peak_bps'] > $data['vara']['avg_bps']) {
                        $mergedHubToHub[$key]['max_bps_values'][] = $data['vara']['peak_bps'];
                    }
                }
            }
            
            foreach ($snap['hubConnections'] as $hub => $stations) {
                if (!isset($hubIdSet[$hub])) continue;
                if (!isset($mergedHubConns[$hub])) $mergedHubConns[$hub] = [];
                
                foreach ($stations as $station => $data) {
                    if (!isset($mergedHubConns[$hub][$station])) {
                        $mergedHubConns[$hub][$station] = ['count' => 0, 'snr_values' => [], 'bytes_tx' => 0, 'bytes_rx' => 0, 'max_bps_values' => []];
                    }
                    $mergedHubConns[$hub][$station]['count'] += $data['count'];
                    $mergedHubConns[$hub][$station]['bytes_tx'] += $data['bytes_tx'] ?? 0;
                    $mergedHubConns[$hub][$station]['bytes_rx'] += $data['bytes_rx'] ?? 0;
                    if ($data['avg_snr'] !== null) $mergedHubConns[$hub][$station]['snr_values'][] = $data['avg_snr'];
                    // Use raw max_bps_values if saved (new snapshots), otherwise reconstruct from VARA stats
                    if (!empty($data['max_bps_values'])) {
                        $mergedHubConns[$hub][$station]['max_bps_values'] = array_merge($mergedHubConns[$hub][$station]['max_bps_values'], $data['max_bps_values']);
                    } elseif (!empty($data['vara']) && isset($data['vara']['peak_bps'])) {
                        $varaSessions = $data['vara']['sessions'] ?? $data['count'];
                        for ($i = 0; $i < $varaSessions; $i++) {
                            $mergedHubConns[$hub][$station]['max_bps_values'][] = $data['vara']['avg_bps'];
                        }
                        if ($data['vara']['peak_bps'] > $data['vara']['avg_bps']) {
                            $mergedHubConns[$hub][$station]['max_bps_values'][] = $data['vara']['peak_bps'];
                        }
                    }
                    
                    // All non-hub stations
                    if (!isset($hubIdSet[$station])) {
                        if (!isset($mergedAllStations[$station])) {
                            $mergedAllStations[$station] = ['id' => $station, 'hub' => $hub, 'connections' => 0, 'snr_values' => []];
                        }
                        $mergedAllStations[$station]['connections'] += $data['count'];
                        if ($data['avg_snr'] !== null) $mergedAllStations[$station]['snr_values'][] = $data['avg_snr'];
                    }
                }
            }
        }
        
        // Process today's live data
        if ($parsed) {
            $datesUsed[] = date('Y-m-d');
            
            // Use pre-computed hub-to-hub edges from today's parse
            foreach (($parsed['hubToHub'] ?? []) as $key => $data) {
                if ($data['count'] > 0) {
                    if (!isset($mergedHubToHub[$key])) $mergedHubToHub[$key] = ['count' => 0, 'snr_values' => [], 'max_bps_values' => []];
                    $mergedHubToHub[$key]['count'] += $data['count'];
                    if (!empty($data['snr_values'])) {
                        $mergedHubToHub[$key]['snr_values'] = array_merge($mergedHubToHub[$key]['snr_values'], $data['snr_values']);
                    }
                }
            }
            
            foreach ($parsed['hubConnections'] as $hub => $stations) {
                if (!isset($hubIdSet[$hub])) continue;
                if (!isset($mergedHubConns[$hub])) $mergedHubConns[$hub] = [];
                
                foreach ($stations as $station => $data) {
                    if (!isset($mergedHubConns[$hub][$station])) {
                        $mergedHubConns[$hub][$station] = ['count' => 0, 'snr_values' => [], 'bytes_tx' => 0, 'bytes_rx' => 0, 'max_bps_values' => []];
                    }
                    $mergedHubConns[$hub][$station]['count'] += $data['count'];
                    $mergedHubConns[$hub][$station]['bytes_tx'] += $data['bytes_tx'] ?? 0;
                    $mergedHubConns[$hub][$station]['bytes_rx'] += $data['bytes_rx'] ?? 0;
                    if (!empty($data['snr_values'])) {
                        $mergedHubConns[$hub][$station]['snr_values'] = array_merge(
                            $mergedHubConns[$hub][$station]['snr_values'], $data['snr_values']
                        );
                    }
                    
                    if (!isset($hubIdSet[$station])) {
                        if (!isset($mergedAllStations[$station])) {
                            $mergedAllStations[$station] = ['id' => $station, 'hub' => $hub, 'connections' => 0, 'snr_values' => []];
                        }
                        $mergedAllStations[$station]['connections'] += $data['count'];
                        if (!empty($data['snr_values'])) {
                            $mergedAllStations[$station]['snr_values'] = array_merge(
                                $mergedAllStations[$station]['snr_values'], $data['snr_values']
                            );
                        }
                    }
                }
            }
        }
        
        // ── MySQL deep history for network_history ──────────────────────────────
        if ($nhUseDb) {
            try {
                $pdo = tprfn_db();
                $startDate = date('Y-m-d', strtotime("-{$days} days"));
                // Dates already covered
                $coveredNhDates = $datesUsed;

                $nhRows = tprfn_query($pdo, "
                    SELECT
                        hub,
                        station,
                        session_date,
                        is_hub_to_hub,
                        COUNT(*)    AS cnt,
                        AVG(avg_snr) AS avg_snr,
                        SUM(bytes_tx) AS bytes_tx,
                        SUM(bytes_rx) AS bytes_rx,
                        AVG(max_bps)  AS avg_bps,
                        MAX(max_bps)  AS peak_bps
                    FROM sessions
                    WHERE session_date >= ?
                      AND session_date < CURDATE()
                    GROUP BY hub, station, session_date, is_hub_to_hub
                ", [$startDate]);

                foreach ($nhRows as $row) {
                    if (in_array($row['session_date'], $coveredNhDates)) continue;
                    $hub     = $row['hub'];
                    $station = $row['station'];
                    $avgSnr  = $row['avg_snr'] !== null ? (float)$row['avg_snr'] : null;

                    if ((int)$row['is_hub_to_hub']) {
                        $sorted = [$hub, $station]; sort($sorted);
                        $h2hKey = implode('|', $sorted);
                        if (!isset($mergedHubToHub[$h2hKey])) {
                            $mergedHubToHub[$h2hKey] = ['count' => 0, 'snr_values' => [], 'max_bps_values' => []];
                        }
                        $mergedHubToHub[$h2hKey]['count'] += (int)$row['cnt'];
                        if ($avgSnr !== null) $mergedHubToHub[$h2hKey]['snr_values'][] = $avgSnr;
                        if ($row['avg_bps'] > 0) $mergedHubToHub[$h2hKey]['max_bps_values'][] = (int)$row['avg_bps'];
                    }

                    if (!isset($mergedHubConns[$hub])) $mergedHubConns[$hub] = [];
                    if (!isset($mergedHubConns[$hub][$station])) {
                        $mergedHubConns[$hub][$station] = ['count' => 0, 'snr_values' => [], 'bytes_tx' => 0, 'bytes_rx' => 0, 'max_bps_values' => []];
                    }
                    $mergedHubConns[$hub][$station]['count']    += (int)$row['cnt'];
                    $mergedHubConns[$hub][$station]['bytes_tx'] += (int)$row['bytes_tx'];
                    $mergedHubConns[$hub][$station]['bytes_rx'] += (int)$row['bytes_rx'];
                    if ($avgSnr !== null) $mergedHubConns[$hub][$station]['snr_values'][] = $avgSnr;
                    if ($row['avg_bps'] > 0) $mergedHubConns[$hub][$station]['max_bps_values'][] = (int)$row['avg_bps'];

                    if (!isset($hubIdSet[$station])) {
                        if (!isset($mergedAllStations[$station])) {
                            $mergedAllStations[$station] = ['id' => $station, 'hub' => $hub, 'connections' => 0, 'snr_values' => []];
                        }
                        $mergedAllStations[$station]['connections'] += (int)$row['cnt'];
                        if ($avgSnr !== null) $mergedAllStations[$station]['snr_values'][] = $avgSnr;
                    }
                }
            } catch (Throwable $e) {
                error_log('TPRFN network_history DB error: ' . $e->getMessage());
            }
        }

        // Build response using shared function (also adds VARA stats)
        $result = buildHubNodesAndEdges($hubs, $mergedHubConns, $mergedHubToHub);
        $nodes = $result['nodes'];
        $edges = $result['edges'];
        $totalBytesTx = $result['bytes_tx'];
        $totalBytesRx = $result['bytes_rx'];
        
        
        // Build all stations list
        $allStationsList = [];
        foreach ($mergedAllStations as $s) {
            $avgSnr = !empty($s['snr_values']) ? round(array_sum($s['snr_values']) / count($s['snr_values']), 1) : null;
            $allStationsList[] = ['id' => $s['id'], 'hub' => $s['hub'], 'connections' => $s['connections'], 'avg_snr' => $avgSnr];
        }
        
        echo json_encode([
            'nodes' => $nodes,
            'edges' => $edges,
            'all_stations' => $allStationsList,
            'days_available' => count($datesUsed),
            'dates' => $datesUsed,
            'stats' => [
                'total_stations' => count($nodes),
                'total_connections' => array_sum(array_column($nodes, 'connections')),
                'unique_links' => count($edges),
                'total_bytes_tx' => $totalBytesTx,
                'total_bytes_rx' => $totalBytesRx,
                'unique_polling_stations' => count($allStationsList),
            ]
        ]);
        break;

    case 'hub_history':
        header('Content-Type: application/json');
        $hubId = isset($_GET['id']) ? strtoupper(trim($_GET['id'])) : '';
        $days = isset($_GET['days']) ? intval($_GET['days']) : 5;
        if ($days < 1) $days = 1;
        // With MySQL, allow unlimited history; without DB cap at 5 (snapshot limit)
        if (!$DB_ENABLED && $days > 5) $days = 5;
        if ($days > 365) $days = 365; // hard cap at 1 year
        if (empty($hubId) || !isValidCallsign($hubId)) { http_response_code(400); echo json_encode(['error' => 'Valid Hub ID required']); exit; }
        
        // Collect snapshot files — for days beyond snapshot retention, use MySQL
        $snapshots = glob($CACHE_DIR . '/snapshot-*.json');
        sort($snapshots); // Oldest first

        // When requesting more days than we have snapshots, supplement from MySQL
        $availableSnapDays = count($snapshots);
        $useDbForHistory = $DB_ENABLED && $days > $availableSnapDays;

        // Keep only the last N days of snapshots (up to what's available)
        $snapshots = array_slice($snapshots, -min($days, $availableSnapDays));
        
        // Also include today's live data
        requireSyslog($SYSLOG_PATH);
        $hubIds = getHubIds($HUBS_CONFIG);
        $parsed = getParsedSyslog($SYSLOG_PATH, $hubIds, $CACHE_DIR, $CACHE_TTL);
        
        // Build per-day data for this hub
        $dailyData = [];
        
        // Historical days from snapshots
        foreach ($snapshots as $file) {
            $snap = json_decode(file_get_contents($file), true);
            if (!$snap || !isset($snap['hubConnections'][$hubId])) continue;
            
            $dayStations = [];
            $dayConnections = 0;
            $dayBytesTx = 0;
            $dayBytesRx = 0;
            $snrValues = [];
            
            foreach ($snap['hubConnections'][$hubId] as $station => $data) {
                if ($data['count'] > 0) {
                    $dayStations[] = [
                        'id' => $station,
                        'connections' => $data['count'],
                        'avg_snr' => $data['avg_snr'],
                        'bytes_tx' => $data['bytes_tx'] ?? 0,
                        'bytes_rx' => $data['bytes_rx'] ?? 0,
                    ];
                    $dayConnections += $data['count'];
                    $dayBytesTx += $data['bytes_tx'] ?? 0;
                    $dayBytesRx += $data['bytes_rx'] ?? 0;
                    if ($data['avg_snr'] !== null) $snrValues[] = $data['avg_snr'];
                }
            }
            
            $dailyData[] = [
                'date' => $snap['date'],
                'stations' => $dayStations,
                'total_connections' => $dayConnections,
                'total_stations' => count($dayStations),
                'bytes_tx' => $dayBytesTx,
                'bytes_rx' => $dayBytesRx,
                'avg_snr' => count($snrValues) > 0 ? round(array_sum($snrValues) / count($snrValues), 1) : null,
            ];
        }
        
        // Today's live data
        if ($parsed) {
            $todayConns = $parsed['hubConnections'][$hubId] ?? [];
            $todayStations = [];
            $todayConnections = 0;
            $todayBytesTx = 0;
            $todayBytesRx = 0;
            $todaySnr = [];
            
            foreach ($todayConns as $station => $data) {
                if ($data['count'] > 0) {
                    $avgSnr = !empty($data['snr_values']) 
                        ? round(array_sum($data['snr_values']) / count($data['snr_values']), 1) 
                        : null;
                    $todayStations[] = [
                        'id' => $station,
                        'connections' => $data['count'],
                        'avg_snr' => $avgSnr,
                        'bytes_tx' => $data['bytes_tx'] ?? 0,
                        'bytes_rx' => $data['bytes_rx'] ?? 0,
                    ];
                    $todayConnections += $data['count'];
                    $todayBytesTx += $data['bytes_tx'] ?? 0;
                    $todayBytesRx += $data['bytes_rx'] ?? 0;
                    if ($avgSnr !== null) $todaySnr[] = $avgSnr;
                }
            }
            
            $dailyData[] = [
                'date' => date('Y-m-d'),
                'is_today' => true,
                'stations' => $todayStations,
                'total_connections' => $todayConnections,
                'total_stations' => count($todayStations),
                'bytes_tx' => $todayBytesTx,
                'bytes_rx' => $todayBytesRx,
                'avg_snr' => count($todaySnr) > 0 ? round(array_sum($todaySnr) / count($todaySnr), 1) : null,
            ];
        }
        
        // ── MySQL deep history: fetch days not covered by snapshots ────────────
        if ($useDbForHistory) {
            try {
                $pdo = tprfn_db();
                // Dates already covered by snapshots + today
                $coveredDates = array_map(fn($d) => $d['date'], $dailyData);
                $startDate = date('Y-m-d', strtotime("-{$days} days"));

                $dbRows = tprfn_query($pdo, "
                    SELECT
                        session_date,
                        station,
                        COUNT(*)               AS cnt,
                        AVG(avg_snr)           AS avg_snr,
                        SUM(bytes_tx)          AS bytes_tx,
                        SUM(bytes_rx)          AS bytes_rx
                    FROM sessions
                    WHERE hub = ?
                      AND session_date >= ?
                      AND session_date < CURDATE()
                    GROUP BY session_date, station
                    ORDER BY session_date ASC
                ", [$hubId, $startDate]);

                // Group by date
                $dbByDate = [];
                foreach ($dbRows as $row) {
                    $d = $row['session_date'];
                    if (in_array($d, $coveredDates)) continue; // already have this from snapshot
                    if (!isset($dbByDate[$d])) $dbByDate[$d] = [];
                    $dbByDate[$d][] = $row;
                }

                foreach ($dbByDate as $d => $rows) {
                    $dayStations = [];
                    $dayConns = 0; $dayTx = 0; $dayRx = 0; $daySnr = [];
                    foreach ($rows as $r) {
                        $dayStations[] = [
                            'id'          => $r['station'],
                            'connections' => (int)$r['cnt'],
                            'avg_snr'     => $r['avg_snr'] !== null ? round((float)$r['avg_snr'], 1) : null,
                            'bytes_tx'    => (int)$r['bytes_tx'],
                            'bytes_rx'    => (int)$r['bytes_rx'],
                        ];
                        $dayConns += (int)$r['cnt'];
                        $dayTx    += (int)$r['bytes_tx'];
                        $dayRx    += (int)$r['bytes_rx'];
                        if ($r['avg_snr'] !== null) $daySnr[] = (float)$r['avg_snr'];
                    }
                    $dailyData[] = [
                        'date'              => $d,
                        'source'            => 'db',
                        'stations'          => $dayStations,
                        'total_connections' => $dayConns,
                        'total_stations'    => count($dayStations),
                        'bytes_tx'          => $dayTx,
                        'bytes_rx'          => $dayRx,
                        'avg_snr'           => count($daySnr) > 0 ? round(array_sum($daySnr)/count($daySnr), 1) : null,
                    ];
                }
                // Re-sort dailyData by date
                usort($dailyData, fn($a, $b) => strcmp($a['date'], $b['date']));
            } catch (Throwable $e) {
                error_log('TPRFN hub_history DB error: ' . $e->getMessage());
            }
        }

        // Build merged multi-day view (aggregate all days)
        $mergedStations = [];
        $mergedTotalConn = 0;
        $mergedBytesTx = 0;
        $mergedBytesRx = 0;
        $mergedSnr = [];
        
        foreach ($dailyData as $day) {
            foreach ($day['stations'] as $s) {
                $sid = $s['id'];
                if (!isset($mergedStations[$sid])) {
                    $mergedStations[$sid] = ['id' => $sid, 'connections' => 0, 'bytes_tx' => 0, 'bytes_rx' => 0, 'snr_values' => [], 'days_seen' => 0];
                }
                $mergedStations[$sid]['connections'] += $s['connections'];
                $mergedStations[$sid]['bytes_tx'] += $s['bytes_tx'];
                $mergedStations[$sid]['bytes_rx'] += $s['bytes_rx'];
                $mergedStations[$sid]['days_seen']++;
                if ($s['avg_snr'] !== null) $mergedStations[$sid]['snr_values'][] = $s['avg_snr'];
            }
            $mergedTotalConn += $day['total_connections'];
            $mergedBytesTx += $day['bytes_tx'];
            $mergedBytesRx += $day['bytes_rx'];
            if ($day['avg_snr'] !== null) $mergedSnr[] = $day['avg_snr'];
        }
        
        // Finalize merged station S/N averages
        $mergedNodes = [];
        $mergedEdges = [];
        foreach ($mergedStations as $sid => $data) {
            $avgSnr = count($data['snr_values']) > 0 ? round(array_sum($data['snr_values']) / count($data['snr_values']), 1) : null;
            $mergedNodes[] = ['id' => $sid, 'label' => $sid, 'connections' => $data['connections'], 'avg_snr' => $avgSnr, 'days_seen' => $data['days_seen'], 'bytes_tx' => $data['bytes_tx'], 'bytes_rx' => $data['bytes_rx']];
            $mergedEdges[] = ['from' => $hubId, 'to' => $sid, 'count' => $data['connections'], 'avg_snr' => $avgSnr];
        }
        
        echo json_encode([
            'hub' => $hubId,
            'days_available' => count($dailyData),
            'daily' => $dailyData,
            'merged' => [
                'nodes' => $mergedNodes,
                'edges' => $mergedEdges,
                'stats' => [
                    'total_stations' => count($mergedNodes),
                    'total_connections' => $mergedTotalConn,
                    'bytes_tx' => $mergedBytesTx,
                    'bytes_rx' => $mergedBytesRx,
                    'avg_snr' => count($mergedSnr) > 0 ? round(array_sum($mergedSnr) / count($mergedSnr), 1) : null,
                ]
            ]
        ]);
        break;

    case 'hub_info':
        header('Content-Type: application/json');
        $hubId = isset($_GET['id']) ? strtoupper(trim($_GET['id'])) : '';
        $hubInfo = loadHubInfo($HUB_INFO_CONFIG);
        if (empty($hubId)) {
            echo json_encode(['success' => true, 'info' => $hubInfo]);
        } else {
            echo json_encode(['success' => true, 'hub' => $hubId, 'info' => $hubInfo[$hubId] ?? null]);
        }
        break;

    case 'all_stations':
        header('Content-Type: application/json');
        requireSyslog($SYSLOG_PATH);
        $hubIds = getHubIds($HUBS_CONFIG);
        $parsed = getParsedSyslog($SYSLOG_PATH, $hubIds, $CACHE_DIR, $CACHE_TTL);
        if (!$parsed) { echo json_encode(['success' => false, 'error' => 'Failed to parse log data']); exit; }
        echo json_encode(['success' => true, 'stations' => $parsed['allStations'], 'total' => count($parsed['allStations'])]);
        break;

    case 'syslog':
        header('Content-Type: text/plain');
        if (!file_exists($SYSLOG_PATH) || !is_readable($SYSLOG_PATH)) {
            http_response_code(500);
            error_log("TPRFN: Syslog not available at $SYSLOG_PATH");
            die("Error: Log data currently unavailable.");
        }
        readfile($SYSLOG_PATH);
        break;

    case 'debug':
        header('Content-Type: application/json');
        session_start();
        if (empty($_SESSION['hub_admin_callsign']) || ($_SESSION['hub_admin_expires'] ?? 0) < time()) {
            http_response_code(403);
            echo json_encode(['error' => 'Authentication required. Login via Hub Admin first.']);
            exit;
        }
        $debug = [
            'syslog_exists' => file_exists($SYSLOG_PATH),
            'syslog_readable' => is_readable($SYSLOG_PATH),
            'syslog_size' => file_exists($SYSLOG_PATH) ? filesize($SYSLOG_PATH) : 0,
            'cache_dir_exists' => is_dir($CACHE_DIR),
            'cache_file_exists' => file_exists($CACHE_DIR . '/syslog-parsed.json'),
        ];
        $metaFile = $CACHE_DIR . '/syslog-meta.json';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            $debug['cache_age_seconds'] = time() - ($meta['parsed_at'] ?? 0);
            $debug['cache_hub_count'] = $meta['hub_count'] ?? 0;
        }
        echo json_encode($debug, JSON_PRETTY_PRINT);
        break;

    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
