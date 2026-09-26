<?php
/**
 * print-bbs-config.php — CLI-only helper for bpq-chat-daemon.py
 *
 * Prints config.php's 'bbs' section as JSON on stdout, so the chat
 * daemon can read the real BPQ telnet host/port/user/pass from the
 * one shared config file instead of keeping its own hardcoded copy
 * that can silently drift out of sync (which is exactly what happened
 * here — see docs/SESSION-JOURNAL.md, 2026-08-30).
 *
 * Deliberately CLI-only — refuses to run under a web server SAPI.
 * Not wired into any nginx location, so it's never reachable over
 * HTTP anyway; this is defense in depth, not the primary guard.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "config.php not found at $configFile\n");
    exit(1);
}

$CONFIG = require $configFile;
$bbs    = $CONFIG['bbs'] ?? [];

echo json_encode([
    'host' => $bbs['host'] ?? 'localhost',
    'port' => $bbs['port'] ?? 8010,
    'user' => $bbs['user'] ?? '',
    'pass' => $bbs['pass'] ?? '',
]);
