<?php
/**
 * generate-report.php — RF Activity Report PDF generator endpoint
 *
 * Invoked by the "Generate Report" button on bpq-rf-connections.html.
 * Calls scripts/generate-rf-report.py with validated parameters and
 * streams the resulting PDF back to the browser as a download.
 *
 * Query params:
 *   callsign  — base callsign (3-7 alphanumeric chars, SSID stripped)
 *   hours     — integer 1..8760
 *   direction — 'all' | 'outgoing' | 'incoming'
 *
 * Example:
 *   GET /generate-report.php?callsign=N4SD&hours=24&direction=all
 */

declare(strict_types=1);

// Load shared bootstrap so we get $CONFIG (DB credentials, paths)
require_once __DIR__ . '/includes/bootstrap.php';

// ─── Helpers ───────────────────────────────────────────────────────────
function bail(int $http_code, string $msg): void
{
    http_response_code($http_code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

// ─── Validate inputs ───────────────────────────────────────────────────
$callsign_raw = $_GET['callsign'] ?? '';
$hours        = (int)($_GET['hours'] ?? 24);
$direction    = $_GET['direction'] ?? 'all';

// Callsign: uppercase, strip SSID, validate format
$callsign = strtoupper(trim((string)$callsign_raw));
if (str_contains($callsign, '-')) {
    $callsign = explode('-', $callsign, 2)[0];
}
if (!preg_match('/^[A-Z0-9]{3,7}$/', $callsign)) {
    bail(400, 'Invalid callsign — must be 3-7 alphanumeric characters');
}

// Hours: 1 to 365 days
if ($hours < 1 || $hours > 24 * 365) {
    bail(400, 'Invalid hours — must be 1 to 8760 (one year)');
}

// Direction: enum
if (!in_array($direction, ['all', 'outgoing', 'incoming'], true)) {
    bail(400, 'Invalid direction');
}

// ─── Locate the Python script ──────────────────────────────────────────
$script_paths = [
    __DIR__ . '/scripts/generate-rf-report.py',
    '/var/www/html/bpq/scripts/generate-rf-report.py',
    '/var/www/html/bpq/scripts/generate-rf-report.py',
];
$script = null;
foreach ($script_paths as $p) {
    if (is_file($p) && is_readable($p)) {
        $script = $p;
        break;
    }
}
if (!$script) {
    bail(500, 'generate-rf-report.py not found in scripts directory');
}

// ─── Temp file for output ──────────────────────────────────────────────
$tmp_pdf = tempnam(sys_get_temp_dir(), 'rf-report-') . '.pdf';
register_shutdown_function(function () use ($tmp_pdf) {
    if (is_file($tmp_pdf)) {
        @unlink($tmp_pdf);
    }
});

// ─── Build environment for the subprocess ──────────────────────────────
$env = [];
// Same credential source as every other DB-backed page (db-config.php,
// then config.php's db section) — see tprfn-db.php. (2026-09-26)
require_once __DIR__ . '/tprfn-db.php';
$db = tprfn_db_settings() ?: ($CONFIG['db'] ?? []);
$env['BPQ_DB_HOST'] = $db['host'] ?? '127.0.0.1';
$env['BPQ_DB_NAME'] = $db['name'] ?? 'bpqdash';
$env['BPQ_DB_USER'] = $db['user'] ?? 'bpqdash_user';
$env['BPQ_DB_PASS'] = $db['pass'] ?? '';
$env['PATH']        = '/usr/local/bin:/usr/bin:/bin';
$env['HOME']        = '/tmp';

// ─── Invoke the generator ──────────────────────────────────────────────
$cmd = [
    '/usr/bin/python3',
    $script,
    '--callsign', $callsign,
    '--hours',    (string)$hours,
    '--direction', $direction,
    '--output',   $tmp_pdf,
];

$descriptors = [
    0 => ['pipe', 'r'],   // stdin
    1 => ['pipe', 'w'],   // stdout
    2 => ['pipe', 'w'],   // stderr
];

$proc = proc_open($cmd, $descriptors, $pipes, null, $env);
if (!is_resource($proc)) {
    bail(500, 'Failed to launch report generator');
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit_code = proc_close($proc);

if ($exit_code !== 0) {
    // Log the actual error to PHP error log so admin can debug
    error_log("generate-rf-report.py exit=$exit_code stderr=$stderr");
    bail(500, "Report generation failed (exit $exit_code). "
              . "Check PHP error log for details.");
}

if (!is_file($tmp_pdf) || filesize($tmp_pdf) === 0) {
    bail(500, 'Report generated empty output file');
}

// ─── Stream the PDF back ───────────────────────────────────────────────
$filename = sprintf('rf-activity-%s-%dh-%s.pdf',
                    strtolower($callsign),
                    $hours,
                    date('Ymd-His'));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp_pdf));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($tmp_pdf);
// shutdown function will clean up the temp file
exit;
