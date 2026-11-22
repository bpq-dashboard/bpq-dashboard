<?php
/**
 * mail-log-api.php — the lines the Messages page needs from LinBPQ's daily
 * logs, and nothing else (v1.5.86).
 *
 * The Messages page used to download every log_YYMMDD_BBS.txt and
 * log_YYMMDD_TCP.txt for 30 days and search them in the browser. A busy
 * BBS writes megabytes a day (whole message listings, routing traces), so
 * the page could take minutes to load. Here the server keeps only:
 *   BBS: offers and answers (FA / FB / FC / FS) and connect / disconnect
 *        lines ("|CALL ...");
 *   TCP: POP3/SMTP/NNTP connects, USER / RETR / MAIL From, and the login
 *        and relay refusals the Security view counts.
 * Each day's result is cached in cache/mail-log/ by file size and time, so
 * old days are read once.
 *
 * GET ?days=N (1..31)  →  { ok, bbs: [{date, text}], tcp: [{date, text}] }
 * Days are UTC dates, newest first, plus tomorrow (LinBPQ may roll early).
 * Read-only; file names come from dates only (no path from the request).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/includes/station-config.php';

$LOG_DIR = rtrim((string)(getenv('BPQ_LOG_DIR') ?: (bpq_config_section('paths')['logs'] ?? '') ?: (__DIR__ . '/logs')), '/');
$CACHE   = __DIR__ . '/cache/mail-log';
$days    = max(1, min(31, (int)($_GET['days'] ?? 7)));

if (!is_dir($LOG_DIR)) {
    echo json_encode(['ok' => false, 'error' => 'logs folder not found']);
    exit;
}
if (!is_dir($CACHE)) @mkdir($CACHE, 0775, true);

/** Keep only the lines the page reads. */
function mla_filter(string $path, string $kind): string
{
    $out = [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($kind === 'BBS') {
            // "261110 00:14:27 <N4SD-11   FA B ..." / "... |N4SD-11   Incoming Connect ..."
            if (preg_match('/^\d{6}\s+\d\d:\d\d:\d\d\s+(?:[<>]\S*\s+F[ABCS]\s|\|\S+\s+(?:.*Connect|.*Disconnected))/', $line)) $out[] = $line;
        } else {
            if (preg_match('/Incoming (?:POP3|SMTP|NNTP) Connect|<\s+USER\s|<\s+RETR\s|MAIL\s+From:|530 Authentication required|Authentication failed|\\\\x/i', $line)) $out[] = $line;
        }
    }
    fclose($fh);
    return implode("\n", $out);
}

$res = ['ok' => true, 'bbs' => [], 'tcp' => []];
$today = gmmktime(0, 0, 0, (int)gmdate('n'), (int)gmdate('j'), (int)gmdate('Y'));
for ($i = -1; $i < $days; $i++) {
    $date = gmdate('ymd', $today - $i * 86400);
    foreach (['BBS' => 'bbs', 'TCP' => 'tcp'] as $kind => $key) {
        $f = "$LOG_DIR/log_{$date}_{$kind}.txt";
        if (!is_file($f) || !is_readable($f)) continue;
        $st = @stat($f);
        $c = "$CACHE/{$date}_{$kind}.json";
        $text = null;
        if ($st && is_file($c)) {
            $j = json_decode((string)@file_get_contents($c), true);
            if (is_array($j) && ($j['size'] ?? -1) === $st['size'] && ($j['mtime'] ?? -1) === $st['mtime']) $text = (string)$j['text'];
        }
        if ($text === null) {
            $text = mla_filter($f, $kind);
            if ($st) {
                $tmp = $c . '.' . getmypid();
                if (@file_put_contents($tmp, json_encode(['size' => $st['size'], 'mtime' => $st['mtime'], 'text' => $text])) !== false) @rename($tmp, $c);
            }
        }
        $res[$key][] = ['date' => $date, 'text' => $text];
    }
}
echo json_encode($res, JSON_INVALID_UTF8_SUBSTITUTE);
