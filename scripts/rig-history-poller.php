#!/usr/bin/env php
<?php
/**
 * rig-history-poller.php — BPQ Dashboard v1.5.13
 *
 * Keeps rig-history.jsonl populated with TX power / SWR samples
 * continuously, independent of whether anyone has rig-status.html or
 * rf-power-monitor.html open in a browser.
 *
 * WHY THIS EXISTS:
 * The FLRIG-based power/SWR history (added when rf-power-monitor.html
 * was migrated off the old WaveNode hardware) only ever got logged as a
 * side effect of the browser polling vara-api.php's action=get_rig_info
 * every 3 seconds. The old WaveNode setup had its own always-on
 * hardware/daemon and kept logging regardless of whether the dashboard
 * was open. That got silently lost in the migration — if nobody had a
 * dashboard tab open, history simply stopped, even during real
 * transmissions. (Confirmed 2026-07-27: a ~16-hour gap in reporting
 * that lined up with no browser tab being open, not an actual radio or
 * FLRIG problem.)
 *
 * This script closes that gap by polling the same pollRigStatus()
 * function vara-api.php's HTTP handler uses, on its own timer, as a
 * long-running systemd service (see rig-history-poller.service).
 *
 * Interval matches the browser's live-poll cadence (3s) so behavior is
 * identical to "someone has the dashboard open" at all times. This is
 * intentionally more frequent than the 60s idle-throttle inside
 * logRigHistory() — that throttle still applies here exactly as it does
 * for the browser-driven polls, so idle periods are still downsampled
 * to ~1 sample/min while real TX samples always get through.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "rig-history-poller.php is meant to be run from the CLI only.\n");
    exit(1);
}

// bootstrap.php checks basename($_SERVER['PHP_SELF']) and
// $_SERVER['REQUEST_METHOD'] === 'OPTIONS' — neither key is reliably
// set under CLI, so set them defensively to avoid PHP warnings on every
// single poll (this runs every 3s, so an unaddressed warning here means
// ~28,800 journal lines/day of noise). Neither check actually needs to
// behave any particular way under CLI — PHP_SELF just needs to not
// equal 'bootstrap.php', and REQUEST_METHOD just needs to not equal
// 'OPTIONS' — so any harmless placeholder value is fine.
$_SERVER['PHP_SELF']       = __FILE__;
$_SERVER['REQUEST_METHOD'] = 'CLI';

// vara-api.php defines pollRigStatus() and all the FLRIG/config plumbing
// it needs, then returns early (without running its HTTP dispatcher)
// when php_sapi_name() === 'cli' — see the guard near the bottom of
// that file. This require is intentionally the single source of truth
// for all the FLRIG-reading logic; nothing here duplicates it.
require_once __DIR__ . '/../vara-api.php';

$POLL_INTERVAL_S = 3;
$running = true;

// Graceful shutdown on SIGTERM/SIGINT (systemd sends SIGTERM on stop).
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $shutdown = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT, $shutdown);
}

fwrite(STDOUT, "[rig-history-poller] starting, polling every {$POLL_INTERVAL_S}s\n");

while ($running) {
    $start = microtime(true);

    try {
        $status = pollRigStatus();
        if (!$status['ok']) {
            fwrite(STDERR, "[rig-history-poller] " . ($status['error'] ?? 'unknown error') . "\n");
        }
        // pollRigStatus() already calls logRigHistory() internally when
        // pwr_ok is true — nothing further to do here on success. We
        // don't echo per-poll status on success to keep the journal quiet;
        // failures are what matter for `journalctl -u rig-history-poller`.
    } catch (\Throwable $e) {
        fwrite(STDERR, "[rig-history-poller] exception: " . $e->getMessage() . "\n");
    }

    $elapsed = microtime(true) - $start;
    $sleepFor = max(0.0, $POLL_INTERVAL_S - $elapsed);
    if ($sleepFor > 0) {
        usleep((int)round($sleepFor * 1_000_000));
    }
}

fwrite(STDOUT, "[rig-history-poller] shutting down\n");
