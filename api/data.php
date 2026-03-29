<?php
/**
 * BPQ Dashboard Data API
 * Version: 1.2.0
 *
 * Server-side log parser and cache layer.
 * Reads raw log files, parses them once, caches as JSON, serves to client.
 *
 * Usage:
 *   GET api/data.php?source=datalog&days=1          Power monitor samples
 *   GET api/data.php?source=connections&days=7       VARA connections with frequencies
 *   GET api/data.php?source=datalog&days=1&debug=1   Full diagnostics
 */

// =========================================================================
// GLOBAL ERROR SAFETY NET — capture fatal errors as JSON
// =========================================================================
ob_start();  // Buffer all output so a crash doesn't produce partial HTML

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'error' => $err['message'],
            'file'  => basename($err['file']) . ':' . $err['line'],
            'type'  => 'fatal'
        ]);
    }
});

// Memory headroom for large DataLog files
@ini_set('memory_limit', '512M');

// =========================================================================
// BOOTSTRAP — load config (do NOT convert warnings to exceptions here)
// =========================================================================
$debug = isset($_GET['debug']);

// Data API doesn't need BBS access — skip password check
$SKIP_BBS_CHECK = true;

try {
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'Bootstrap failed: ' . $e->getMessage(),
        'file'  => basename($e->getFile()) . ':' . $e->getLine()
    ]);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// =========================================================================
// CONFIGURATION
// =========================================================================

// Resolve logs directory — handle both config.php and bbs-config.php paths
$configLogsPath = getConfig('paths', 'logs', './logs/');
$logsDir = false;

// Try 1: resolve relative to dashboard root
$tryPath = dirname(__DIR__) . '/' . ltrim($configLogsPath, './');
if (is_dir($tryPath)) $logsDir = realpath($tryPath);

// Try 2: resolve from config exactly as given
if (!$logsDir && is_dir($configLogsPath)) $logsDir = realpath($configLogsPath);

// Try 3: hardcoded fallback
if (!$logsDir) {
    $tryPath = dirname(__DIR__) . '/logs';
    if (is_dir($tryPath)) $logsDir = realpath($tryPath);
}

$cacheDir  = dirname(__DIR__) . '/cache';
$varaFile  = getConfig('logs', 'vara_file', '');

// Auto-detect VARA log if not specified
if (empty($varaFile) && $logsDir && is_dir($logsDir)) {
    $varaMatches = @glob($logsDir . '/*.vara');
    if ($varaMatches && count($varaMatches) > 0) {
        $varaFile = basename($varaMatches[0]);
    }
}

// Ensure cache directory exists
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// DataLog timezone: the timezone of the Windows PC that writes DataLog CSVs.
// If the server is UTC but DataLog timestamps are US/Eastern, we need to
// convert them properly.
//
// Resolution order:
//   1. Config: 'logs' => ['datalog_timezone' => 'America/New_York']
//   2. Config (old format): $config['datalog_timezone']
//   3. Query parameter: ?tz=America/New_York  (sent by client)
//   4. Query parameter: ?tz_offset=-300  (browser's UTC offset in minutes)
//   5. Default: server timezone (no adjustment)
$datalogTzObj = null;

// Try config first
$datalogTz = getConfig('logs', 'datalog_timezone', null);

// Try old-format config
if (!$datalogTz && isset($config) && isset($config['datalog_timezone'])) {
    $datalogTz = $config['datalog_timezone'];
}

// Try query parameter (named timezone)
if (!$datalogTz && !empty($_GET['tz'])) {
    $datalogTz = $_GET['tz'];
}

if ($datalogTz) {
    try {
        $datalogTzObj = new DateTimeZone($datalogTz);
    } catch (Exception $e) {
        $datalogTzObj = null;
    }
}

// Try query parameter (numeric offset in minutes, e.g. -300 for US/Eastern standard)
if (!$datalogTzObj && isset($_GET['tz_offset'])) {
    $offsetMin = intval($_GET['tz_offset']);
    // Only apply if server is UTC and offset is non-zero (meaning DataLog source is not UTC)
    if ($offsetMin !== 0 && date_default_timezone_get() === 'UTC') {
        // JavaScript getTimezoneOffset() returns positive for west of UTC
        // PHP needs the sign flipped for timezone_name_from_abbr
        $offsetSec = -$offsetMin * 60;
        $tzName = @timezone_name_from_abbr('', $offsetSec, 0);
        if ($tzName) {
            try {
                $datalogTzObj = new DateTimeZone($tzName);
            } catch (Exception $e) {
                // Fall through
            }
        }
        // Fallback: use fixed offset
        if (!$datalogTzObj) {
            $sign = $offsetSec >= 0 ? '+' : '-';
            $absH = abs(intval($offsetSec / 3600));
            $absM = abs(intval(($offsetSec % 3600) / 60));
            $fixedTz = sprintf('%s%02d:%02d', $sign, $absH, $absM);
            try {
                $datalogTzObj = new DateTimeZone($fixedTz);
            } catch (Exception $e) {
                $datalogTzObj = null;
            }
        }
    }
}

// =========================================================================
// ROUTING
// =========================================================================

$source = $_GET['source'] ?? '';
$days   = max(1, min(90, intval($_GET['days'] ?? 1)));

try {
    switch ($source) {
        case 'datalog':
            $result = getDataLog($logsDir, $cacheDir, $days, $debug, $datalogTzObj);
            break;

        case 'connections':
            $result = getConnections($logsDir, $cacheDir, $varaFile, $days, $debug);
            break;

        default:
            http_response_code(400);
            $result = ['error' => 'Unknown source. Use: datalog, connections'];
    }

    ob_end_clean();
    echo json_encode($result);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'error'  => $e->getMessage(),
        'file'   => basename($e->getFile()) . ':' . $e->getLine(),
        'trace'  => $debug ? $e->getTraceAsString() : null
    ]);
}
exit;


// =========================================================================
// SOURCE: datalog — Power monitor CSV files
// =========================================================================

function getDataLog($logsDir, $cacheDir, $days, $debug = false, $datalogTzObj = null) {
    $diag = [];

    if (!$logsDir || !is_dir($logsDir)) {
        return [
            'error' => 'Logs directory not found',
            'tried' => [$logsDir, dirname(__DIR__) . '/logs'],
            'samples' => 0,
            'files' => 0,
            'data' => []
        ];
    }

    if ($debug) $diag[] = "logsDir: $logsDir";

    // Find all DataLog*.txt files
    $allFiles = @glob($logsDir . '/DataLog*.txt');
    if (!$allFiles || count($allFiles) === 0) {
        $result = ['samples' => 0, 'files' => 0, 'data' => []];
        if ($debug) {
            $dirContents = @scandir($logsDir);
            $datalogLike = [];
            if ($dirContents) {
                foreach ($dirContents as $f) {
                    if (stripos($f, 'datalog') !== false || stripos($f, 'DataLog') !== false) {
                        $datalogLike[] = $f;
                    }
                }
            }
            $result['debug'] = [
                'logsDir' => $logsDir,
                'dirExists' => is_dir($logsDir),
                'readable' => is_readable($logsDir),
                'totalFilesInDir' => count($dirContents ?: []),
                'datalogFiles' => $datalogLike,
                'globPattern' => $logsDir . '/DataLog*.txt',
                'note' => 'No DataLog files matched the glob pattern',
                'diag' => $diag
            ];
        }
        return $result;
    }

    sort($allFiles);
    if ($debug) $diag[] = "Found " . count($allFiles) . " DataLog files";

    $cutoff = time() - ($days * 86400);
    $allSamples = [];
    $filesLoaded = 0;
    $filesSkipped = 0;
    $parseErrors = [];
    $today = date('Ymd');

    foreach ($allFiles as $filepath) {
        $filename = basename($filepath);

        // Extract date: DataLog~MMDDYYYY~HHMMSS.txt or DataLog_MMDDYYYY_HHMMSS.txt
        if (!preg_match('/DataLog[_~](\d{2})(\d{2})(\d{4})[_~]/', $filename, $dm)) {
            $filesSkipped++;
            if ($debug) $parseErrors[] = "No date in filename: $filename";
            continue;
        }

        $fileDate = $dm[3] . $dm[1] . $dm[2]; // YYYYMMDD
        $fileTs   = @mktime(0, 0, 0, intval($dm[1]), intval($dm[2]), intval($dm[3]));

        // Skip files older than requested range (with 1-day buffer)
        if ($fileTs && $fileTs < $cutoff - 86400) {
            $filesSkipped++;
            continue;
        }

        // Cache key per file — include timezone hash so cache invalidates when TZ config changes
        $safeFilename = preg_replace('/[^a-zA-Z0-9_~.\-]/', '_', $filename);
        $tzSuffix = $datalogTzObj ? '_tz' . crc32($datalogTzObj->getName()) : '';
        $cacheKey  = $cacheDir . '/datalog_' . $safeFilename . $tzSuffix . '.json';
        $fileMtime = @filemtime($filepath);
        $isToday   = ($fileDate === $today);

        // Try cache first
        if (file_exists($cacheKey) && $fileMtime !== false) {
            $cacheMtime = @filemtime($cacheKey);
            if ($cacheMtime !== false) {
                $cacheAge = time() - $cacheMtime;
                if ($cacheMtime >= $fileMtime && (!$isToday || $cacheAge < 120)) {
                    $cacheContent = @file_get_contents($cacheKey);
                    if ($cacheContent !== false) {
                        $cached = @json_decode($cacheContent, true);
                        if (is_array($cached) && isset($cached['d']) && is_array($cached['d'])) {
                            $allSamples = array_merge($allSamples, $cached['d']);
                            $filesLoaded++;
                            continue;
                        }
                    }
                }
            }
        }

        // Parse the CSV file
        $samples = parseDataLogFile($filepath, $cutoff, $datalogTzObj);
        if (is_array($samples) && count($samples) > 0) {
            $allSamples = array_merge($allSamples, $samples);
            $filesLoaded++;

            // Cache it (silently fail if not writable)
            @file_put_contents($cacheKey, json_encode(['d' => $samples]));
        } elseif ($debug) {
            $parseErrors[] = "$filename: 0 samples after parsing";
        }
    }

    // Sort by timestamp
    usort($allSamples, function($a, $b) { return $a[0] - $b[0]; });

    // Deduplicate (same timestamp)
    $seen = [];
    $unique = [];
    foreach ($allSamples as $s) {
        if (!is_array($s) || !isset($s[0])) continue;
        $key = $s[0];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $s;
        }
    }

    $result = [
        'samples' => count($unique),
        'files'   => $filesLoaded,
        'data'    => $unique
    ];

    if ($debug) {
        $sampleTs = count($unique) > 0 ? $unique[count($unique)-1][0] : null;
        $result['debug'] = [
            'logsDir' => $logsDir,
            'totalDataLogFiles' => count($allFiles),
            'filesLoaded' => $filesLoaded,
            'filesSkipped' => $filesSkipped,
            'parseErrors' => $parseErrors,
            'cutoffDate' => date('Y-m-d H:i:s', $cutoff),
            'sampleFiles' => array_map('basename', array_slice($allFiles, 0, 10)),
            'memoryUsed' => round(memory_get_peak_usage(true) / 1048576, 1) . ' MB',
            'cacheDir' => $cacheDir,
            'cacheDirWritable' => is_writable($cacheDir),
            'phpVersion' => PHP_VERSION,
            'serverTz' => date_default_timezone_get(),
            'datalogTz' => $datalogTzObj ? $datalogTzObj->getName() : '(server default: ' . date_default_timezone_get() . ')',
            'latestSampleTs' => $sampleTs,
            'latestSampleUtc' => $sampleTs ? gmdate('Y-m-d H:i:s', $sampleTs) . ' UTC' : null,
            'diag' => $diag
        ];
    }

    return $result;
}


/**
 * Parse one DataLog CSV file into compact arrays.
 * Returns: [[timestamp, ch1peak, ch1rfld, ch1avg, ch2peak, ch2rfld, ch2avg, wh1, wh2], ...]
 * 
 * $datalogTzObj: DateTimeZone of the machine that writes DataLog files.
 *   When set, timestamps in the CSV are interpreted in that timezone and
 *   converted to UTC Unix epoch. When null, mktime() uses the server's
 *   default timezone (correct if server TZ matches the DataLog source).
 */
function parseDataLogFile($filepath, $cutoff, $datalogTzObj = null) {
    if (!file_exists($filepath) || !is_readable($filepath)) return [];

    $fileSize = filesize($filepath);
    // For large files (>5MB), downsample: keep every Nth line to reduce memory
    // ~14MB file ≈ 100K lines at 1/sec; 1 sample per 30s is plenty for graphs
    $skipInterval = $fileSize > 5000000 ? 30 : ($fileSize > 2000000 ? 10 : 1);

    // Pre-compute timezone offset cache
    $tzOffsetCache = [];
    $serverTzObj = new DateTimeZone(date_default_timezone_get());

    $samples = [];
    $lineNum = 0;
    $handle = @fopen($filepath, 'r');
    if (!$handle) return [];

    // Skip header line
    fgets($handle);

    while (($line = fgets($handle)) !== false) {
        $lineNum++;
        if ($skipInterval > 1 && ($lineNum % $skipInterval) !== 0) continue;

        $line = trim($line);
        if ($line === '') continue;

        $cols = explode(',', $line);
        if (count($cols) < 10) continue;

        $dateStr = trim($cols[0]);
        $timeStr = trim($cols[1]);

        $dp = explode('/', $dateStr);
        $tp = explode(':', $timeStr);
        if (count($dp) < 3 || count($tp) < 3) continue;

        $mm = intval($dp[0]);
        $dd = intval($dp[1]);
        $yyyy = intval($dp[2]);
        $hh = intval($tp[0]);
        $mi = intval($tp[1]);
        $ss = intval($tp[2]);

        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31 || $yyyy < 2020) continue;

        $ts = @mktime($hh, $mi, $ss, $mm, $dd, $yyyy);
        if (!$ts) continue;

        if ($datalogTzObj) {
            $dateKey = sprintf('%04d%02d%02d', $yyyy, $mm, $dd);
            if (!isset($tzOffsetCache[$dateKey])) {
                $refDt = new DateTime(sprintf('%04d-%02d-%02d 12:00:00', $yyyy, $mm, $dd), $datalogTzObj);
                $localOffset = $datalogTzObj->getOffset($refDt);
                $serverOffset = $serverTzObj->getOffset($refDt);
                $tzOffsetCache[$dateKey] = $serverOffset - $localOffset;
            }
            $ts += $tzOffsetCache[$dateKey];
        }

        if ($ts < $cutoff) continue;

        $samples[] = [
            $ts,
            round((float)$cols[2], 1),
            round((float)$cols[3], 1),
            round((float)$cols[4], 1),
            round((float)$cols[5], 1),
            round((float)$cols[6], 1),
            round((float)$cols[7], 1),
            round((float)$cols[8], 1),
            round((float)$cols[9], 1),
        ];
    }

    fclose($handle);
    return $samples;
}


// =========================================================================
// SOURCE: connections — VARA connections with BBS frequency correlation
// =========================================================================

function getConnections($logsDir, $cacheDir, $varaFile, $days, $debug = false) {
    $diag = [];
    if (!$logsDir || !is_dir($logsDir)) {
        return ['connections' => [], 'debug' => $debug ? ['error' => 'logsDir not found: ' . $logsDir] : null];
    }

    // ---- Load VARA log ----
    $varaPath = $logsDir . '/' . $varaFile;
    $varaLines = [];
    if ($varaFile && file_exists($varaPath)) {
        $content = @file_get_contents($varaPath);
        if ($content !== false) {
            $varaLines = array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $content)), 'strlen');
        }
        if ($debug) $diag[] = "VARA log: $varaPath — " . count($varaLines) . " lines";
    } else {
        if ($debug) $diag[] = "VARA log NOT FOUND: varaFile='$varaFile', path='$varaPath', exists=" . (file_exists($varaPath) ? 'yes' : 'no');
        // Try to find any .vara file
        $varaGlob = @glob($logsDir . '/*.vara');
        if ($debug) $diag[] = "VARA glob($logsDir/*.vara): " . ($varaGlob ? implode(', ', array_map('basename', $varaGlob)) : 'none');
    }

    // ---- Load BBS logs for radio commands ----
    // BPQ32 rotates BBS logs at 00:00 UTC, so filenames use UTC dates.
    // Timestamps inside BBS logs are also UTC.
    $radioCommands   = [];
    $incomingFreqs   = [];
    $allFreqEvents   = [];   // All frequency changes for nearest-freq fallback
    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $bbsFilesFound   = [];

    for ($i = 0; $i < min($days + 1, 31); $i++) {
        $date = clone $nowUtc;
        $date->modify("-{$i} days");
        $yy = $date->format('y');
        $mm = $date->format('m');
        $dd = $date->format('d');
        $bbsFile = $logsDir . "/log_{$yy}{$mm}{$dd}_BBS.txt";

        if (!file_exists($bbsFile)) continue;
        $bbsFilesFound[] = basename($bbsFile);

        $content = @file_get_contents($bbsFile);
        if ($content === false) continue;
        $bbsLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        unset($content);

        foreach ($bbsLines as $line) {
            // RADIO commands: "260107 00:33:22 >K7EK RADIO 3.597000"
            // BBS timestamps are UTC — use gmmktime to get correct Unix epoch
            if (preg_match('/(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+>([A-Z0-9-]+)\s+RADIO\s+([\d.]+)/i', $line, $rm)) {
                $call = preg_replace('/-\d+$/', '', $rm[5]);
                $ts = bbsDateToTimestamp($rm[1], $rm[2], $rm[3], $rm[4]);
                $freq = (float)$rm[6];
                $radioCommands[] = ['ts' => $ts, 'call' => $call, 'freq' => $freq, 'date' => $rm[1]];
                $allFreqEvents[] = ['ts' => $ts, 'freq' => $freq, 'date' => $rm[1]];
            }

            // Incoming connects with freq: "260107 00:10:49 |KK4DIV-1 Incoming Connect from KK4DIV on Port 3 Freq 7104700 Mode VARA"
            if (preg_match('/(\d{6})\s+(\d{2}):(\d{2}):(\d{2})\s+\|([A-Z0-9-]+)\s+Incoming Connect from ([A-Z0-9-]+).*Freq (\d+) Mode VARA/i', $line, $im)) {
                $ts = bbsDateToTimestamp($im[1], $im[2], $im[3], $im[4]);
                $call = preg_replace('/-\d+$/', '', $im[6]);
                $freq = intval($im[7]) / 1e6;
                $key = $im[1] . ':' . $im[2] . ':' . $im[3];
                $incomingFreqs[$key] = ['call' => $call, 'freq' => $freq, 'ts' => $ts];
                $allFreqEvents[] = ['ts' => $ts, 'freq' => $freq, 'date' => $im[1]];
            }
        }
    }

    if ($debug) {
        $diag[] = "BBS files found: " . (count($bbsFilesFound) > 0 ? implode(', ', $bbsFilesFound) : 'NONE');
        $diag[] = "RADIO commands: " . count($radioCommands);
        $diag[] = "Incoming freqs: " . count($incomingFreqs);
        $diag[] = "All freq events: " . count($allFreqEvents);
        if (count($radioCommands) > 0) {
            $rc = $radioCommands[0];
            $diag[] = "Sample RADIO: call={$rc['call']} freq={$rc['freq']} ts={$rc['ts']} (" . gmdate('Y-m-d H:i:s', $rc['ts']) . " UTC)";
        }
    }

    // Sort frequency events by timestamp for nearest-freq fallback
    usort($allFreqEvents, function($a, $b) { return $a['ts'] - $b['ts']; });

    // ---- Parse VARA log into connection windows ----
    // VARA .vara log timestamps are UTC (BPQ32 is UTC-based).
    $MONTH_MAP = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
                  'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
    $currentYear  = intval(gmdate('Y'));
    $currentMonth = intval(gmdate('n'));
    $cutoff = time() - ($days * 86400);

    $connections = [];
    $curr = null;
    $freqHits = 0;
    $freqMisses = 0;

    foreach ($varaLines as $line) {
        if (!preg_match('/^(\w+)\s+(\d+)\s+(\d{2}):(\d{2}):(\d{2})/', $line, $tm)) continue;
        $mon = $tm[1]; $day = intval($tm[2]);
        $h = intval($tm[3]); $m = intval($tm[4]); $s = intval($tm[5]);

        $mi = $MONTH_MAP[$mon] ?? 0;
        if (!$mi) continue;
        $yr = ($mi > $currentMonth) ? $currentYear - 1 : $currentYear;
        // VARA timestamps are UTC — use gmmktime for correct epoch
        $ts = @gmmktime($h, $m, $s, $mi, $day, $yr);
        if (!$ts || $ts < $cutoff) continue;

        // Build BBS-style date string (YYMMDD) for incoming freq lookup
        $bbsDate = sprintf('%02d%02d%02d', $yr % 100, $mi, $day);
        $hPad = str_pad($h, 2, '0', STR_PAD_LEFT);
        $mPad = str_pad($m, 2, '0', STR_PAD_LEFT);

        // Outgoing: "Connecting to K7EK... 1/3"
        if (preg_match('/Connecting to ([A-Z0-9-]+)\.\.\. 1\//i', $line, $cm)) {
            // If there's an unclosed connection window, save it as failed before starting new one
            if ($curr) {
                $curr['endTs'] = $ts;  // Closed at start of next attempt
                $curr['band']  = $curr['freq'] ? freqToBand($curr['freq']) : null;
                $curr['failed'] = true;
                if ($curr['freq']) $freqHits++; else $freqMisses++;
                $connections[] = $curr;
            }
            $call = preg_replace('/-\d+$/', '', $cm[1]);
            $freq = findRadioFreq($radioCommands, $call, $ts);
            if (!$freq) $freq = findNearestFreq($allFreqEvents, $ts);
            $curr = ['callsign' => $call, 'startTs' => $ts, 'freq' => $freq, 'type' => 'out'];
            continue;
        }

        // Connected: "Connected to K7EK VARA HF"
        if (preg_match('/Connected to ([A-Z0-9-]+)\s+VARA HF/i', $line, $cm)) {
            $call = preg_replace('/-\d+$/', '', $cm[1]);
            if (!$curr || $curr['callsign'] !== $call) {
                // If there's an unclosed window for a different callsign, save as failed
                if ($curr) {
                    $curr['endTs'] = $ts;
                    $curr['band']  = $curr['freq'] ? freqToBand($curr['freq']) : null;
                    $curr['failed'] = true;
                    if ($curr['freq']) $freqHits++; else $freqMisses++;
                    $connections[] = $curr;
                }
                $freq = findRadioFreq($radioCommands, $call, $ts);
                if (!$freq) $freq = findNearestFreq($allFreqEvents, $ts);
                $curr = ['callsign' => $call, 'startTs' => $ts, 'freq' => $freq, 'type' => 'out'];
            }
            continue;
        }

        // Incoming: "VARAHF KK4DIV-1 connected VARA HF"
        if (preg_match('/VARAHF\s+([A-Z0-9-]+)\s+connected\s+VARA HF/i', $line, $cm)) {
            // If there's an unclosed outgoing window, save as failed
            if ($curr) {
                $curr['endTs'] = $ts;
                $curr['band']  = $curr['freq'] ? freqToBand($curr['freq']) : null;
                $curr['failed'] = true;
                if ($curr['freq']) $freqHits++; else $freqMisses++;
                $connections[] = $curr;
            }
            $call = preg_replace('/-\d+$/', '', $cm[1]);
            $freq = findIncomingFreq($incomingFreqs, $bbsDate, $hPad, $m);
            if (!$freq) $freq = findNearestFreq($allFreqEvents, $ts);
            $curr = ['callsign' => $call, 'startTs' => $ts, 'freq' => $freq, 'type' => 'in'];
            continue;
        }

        // Disconnected — close the window (normal or timeout)
        if (preg_match('/Disconnected\s+(?:\(Timeout\)\s+)?TX:/i', $line) && $curr) {
            $curr['endTs'] = $ts;
            $curr['band']  = $curr['freq'] ? freqToBand($curr['freq']) : null;
            // Mark as failed if 0 bytes transferred (timeout or no-connect)
            if (preg_match('/TX:\s*0\s+Bytes.*RX:\s*0\s+Bytes/i', $line)) {
                $curr['failed'] = true;
            }
            if ($curr['freq']) $freqHits++; else $freqMisses++;
            $connections[] = $curr;
            $curr = null;
            continue;
        }

        // Incoming connection failed — close any open incoming window
        if (preg_match('/Incoming Connection failed/i', $line) && $curr) {
            $curr['endTs'] = $ts;
            $curr['band']  = $curr['freq'] ? freqToBand($curr['freq']) : null;
            $curr['failed'] = true;
            if ($curr['freq']) $freqHits++; else $freqMisses++;
            $connections[] = $curr;
            $curr = null;
        }
    }

    // If there's still an unclosed connection at end of file, save it as failed
    if ($curr) {
        $curr['endTs'] = $curr['startTs'] + 120;  // Assume 2-minute window
        $curr['band']  = $curr['freq'] ? freqToBand($curr['freq']) : null;
        $curr['failed'] = true;
        if ($curr['freq']) $freqHits++; else $freqMisses++;
        $connections[] = $curr;
        $curr = null;
    }

    usort($connections, function($a, $b) { return $a['startTs'] - $b['startTs']; });

    if ($debug) {
        $failedCount = count(array_filter($connections, function($c) { return !empty($c['failed']); }));
        $diag[] = "Connections parsed: " . count($connections) . " (failed: $failedCount)";
        $diag[] = "With frequency: $freqHits, Without: $freqMisses";
        $diag[] = "Server TZ: " . date_default_timezone_get() . ", UTC offset: " . date('P');
        if (count($connections) > 0) {
            $c = $connections[0];
            $diag[] = "First conn: call={$c['callsign']} freq=" . ($c['freq'] ?: 'NULL') . " band=" . ($c['band'] ?: 'NULL') . " startTs={$c['startTs']} (" . gmdate('Y-m-d H:i:s', $c['startTs']) . " UTC)";
        }
    }

    $result = ['connections' => $connections];
    if ($debug) $result['debug'] = $diag;
    return $result;
}

/**
 * Find RADIO command frequency within ±5 minutes of timestamp.
 * Prefers closest match before the timestamp, but also checks after
 * (BBS may log the RADIO command slightly after VARA starts connecting).
 */
function findRadioFreq($radioCommands, $callsign, $ts) {
    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($radioCommands as $rc) {
        if ($rc['call'] !== $callsign) continue;
        $diff = abs($ts - $rc['ts']);
        if ($diff < 300 && $diff < $bestDiff) {
            $bestDiff = $diff;
            $best = $rc['freq'];
        }
    }
    return $best;
}

/**
 * Find the most recent frequency event within 10 minutes before a timestamp.
 * Fallback when callsign-specific lookup fails — uses any RADIO or incoming freq.
 */
function findNearestFreq($allFreqEvents, $ts) {
    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($allFreqEvents as $evt) {
        $diff = $ts - $evt['ts'];  // positive = event was before $ts
        if ($diff >= 0 && $diff < 600 && $diff < $bestDiff) {
            $bestDiff = $diff;
            $best = $evt['freq'];
        }
    }
    return $best;
}

/**
 * Find incoming connection frequency from BBS log.
 * $bbsDate: YYMMDD string, $hPad: zero-padded hour, $m: minute (int)
 */
function findIncomingFreq($incomingFreqs, $bbsDate, $hPad, $m) {
    // Try exact and ±2 minutes
    for ($offset = 0; $offset <= 2; $offset++) {
        $mStr = str_pad($m + $offset, 2, '0', STR_PAD_LEFT);
        $key = $bbsDate . ':' . $hPad . ':' . $mStr;
        if (isset($incomingFreqs[$key])) return $incomingFreqs[$key]['freq'];

        if ($offset > 0) {
            $mStr2 = str_pad(max(0, $m - $offset), 2, '0', STR_PAD_LEFT);
            $key2 = $bbsDate . ':' . $hPad . ':' . $mStr2;
            if (isset($incomingFreqs[$key2])) return $incomingFreqs[$key2]['freq'];
        }
    }

    // Fallback: search by timestamp proximity (±120 seconds)
    foreach ($incomingFreqs as $data) {
        if (!isset($data['ts'])) continue;
        // We don't have $ts here, so reconstruct from bbsDate + hPad + m
        $yy = intval(substr($bbsDate, 0, 2));
        $mm = intval(substr($bbsDate, 2, 2));
        $dd = intval(substr($bbsDate, 4, 2));
        $yr = ($yy > 90) ? 1900 + $yy : 2000 + $yy;
        $ts = @gmmktime(intval($hPad), $m, 0, $mm, $dd, $yr);
        if ($ts && abs($ts - $data['ts']) <= 120) {
            return $data['freq'];
        }
    }
    return null;
}

/**
 * Convert BBS date "YYMMDD" + time to Unix timestamp.
 * BBS logs are UTC — use gmmktime for correct epoch values.
 */
function bbsDateToTimestamp($dateStr, $h, $m, $s) {
    $yy = intval(substr($dateStr, 0, 2));
    $mm = intval(substr($dateStr, 2, 2));
    $dd = intval(substr($dateStr, 4, 2));
    $yr = ($yy > 90) ? 1900 + $yy : 2000 + $yy;
    return @gmmktime(intval($h), intval($m), intval($s), $mm, $dd, $yr) ?: 0;
}

/**
 * Convert frequency in MHz to band name.
 */
function freqToBand($f) {
    if ($f >= 1.8  && $f <= 2.0)    return '160m';
    if ($f >= 3.5  && $f <= 4.0)    return '80m';
    if ($f >= 7.0  && $f <= 7.3)    return '40m';
    if ($f >= 10.1 && $f <= 10.15)  return '30m';
    if ($f >= 14.0 && $f <= 14.35)  return '20m';
    if ($f >= 18.068 && $f <= 18.168) return '17m';
    if ($f >= 21.0 && $f <= 21.45)  return '15m';
    if ($f >= 24.89 && $f <= 24.99) return '12m';
    if ($f >= 28.0 && $f <= 29.7)   return '10m';
    return null;
}
