#!/usr/bin/env php
<?php
/**
 * purge-old-bulletins.php — BPQ Dashboard retention policy
 *
 * Deletes Bulletins older than the configured retention window from
 * bpq_messages. Personal mail and NTS traffic are NEVER touched by
 * this script — retention for those is "indefinite" by simply never
 * running a purge against them.
 *
 * Policy lives in message-db.php (msgdb_bulletin_purge_where()) and is
 * shared with the "Run retention cleanup now" button, so the two can't
 * drift apart. Revised 2026-09-26. A bulletin is deleted if:
 *   - it's in Trash (any age), OR
 *   - it's older than the retention window (default 14 days) AND it is
 *     unfiled, or in a "Bulletins - GROUP" folder that is NOT pinned 📌.
 * Kept indefinitely: pinned 📌 folders, and folders you created yourself
 * ("My Folders"). Personal mail and NTS traffic are never touched.
 *
 * This is a real, permanent DELETE — not the is_archived soft-delete
 * used for Kill elsewhere in the dashboard. The whole point here is
 * reclaiming space from routine, endlessly-repeating bulletin traffic
 * (the same NEWS/PKTNET groups arrive daily), so a soft-delete that
 * left the rows in place wouldn't accomplish anything.
 *
 * RUN MANUALLY:
 *   php purge-old-bulletins.php                # purge per policy, 14-day window
 *   php purge-old-bulletins.php --days=60      # custom window
 *   php purge-old-bulletins.php --dry-run      # per-folder counts only, deletes nothing
 *
 * SCHEDULED RUN:
 *   Installed as a systemd timer — see bulletin-retention.service and
 *   bulletin-retention.timer. Runs once daily.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is for command-line/cron use only.');
}

require_once __DIR__ . '/../tprfn-db.php';
require_once __DIR__ . '/../message-db.php';   // msgdb_bulletin_purge_where()

// ─── Args ─────────────────────────────────────────────────────
$days = MSGDB_BULLETIN_RETENTION_DAYS;   // 14
$dryRun = false;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) $days = max(1, min(3650, (int)$m[1]));
    if ($arg === '--dry-run') $dryRun = true;
}

$startedAt = date('Y-m-d H:i:s');
echo "[$startedAt] Bulletin retention purge starting (retention: $days days, dry-run: " . ($dryRun ? 'yes' : 'no') . ")\n";

try {
    $pdo = tprfn_db();

    $where = msgdb_bulletin_purge_where($days);
    if (!msgdb_keep_schema_ready()) {
        echo "NOTE: keep_on_kill column missing (sql/2026-09-26-message-keep-and-bodies.sql not applied) —\n";
        echo "      using the old conservative rule: unfiled bulletins + Trash only.\n";
    }

    // Per-folder breakdown first regardless of dry-run, so both modes
    // report exactly what is (or would be) affected.
    $rows = $pdo->query(
        "SELECT COALESCE(f.name, '(unfiled)') AS folder, COUNT(*) AS c
         FROM bpq_messages m
         LEFT JOIN bpq_msg_folders f ON f.id = m.folder_id
         WHERE $where
         GROUP BY folder ORDER BY c DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $toDelete = array_sum(array_map(fn($r) => (int)$r['c'], $rows));

    if ($toDelete === 0) {
        echo "Nothing to purge under the $days-day policy.\n";
        exit(0);
    }
    foreach ($rows as $r) {
        printf("  %-32s %6d\n", $r['folder'], $r['c']);
    }

    if ($dryRun) {
        echo "DRY RUN: would delete $toDelete bulletin(s). Nothing was changed.\n";
        exit(0);
    }

    $delStmt = $pdo->prepare(
        "DELETE m FROM bpq_messages m
         LEFT JOIN bpq_msg_folders f ON f.id = m.folder_id
         WHERE $where"
    );
    $delStmt->execute();
    $deleted = $delStmt->rowCount();

    $finishedAt = date('Y-m-d H:i:s');
    echo "[$finishedAt] Purged $deleted bulletin(s) ($days-day policy).\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Bulletin retention purge FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
