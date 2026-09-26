#!/usr/bin/env php
<?php
/**
 * check-nts-messages.php — BPQ Dashboard diagnostic
 *
 * Quick command-line check of what's actually in bpq_messages for NTS
 * traffic (msg_type = 'T'), for when the forwarding report shows none
 * and you want to know why: are there really zero, are they outside
 * the report's date range, or did some get archived under the wrong
 * type?
 *
 * RUN:
 *   php check-nts-messages.php
 *   php check-nts-messages.php --days=90     (only check last 90 days)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is for command-line use only.');
}

require_once __DIR__ . '/../tprfn-db.php';

$days = null;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) $days = (int)$m[1];
}

function line() { echo str_repeat('-', 78) . "\n"; }

try {
    $pdo = tprfn_db();

    // ── 1. Total row count and breakdown by type ────────────────────
    line();
    echo "1. All message types currently in bpq_messages\n";
    line();
    $rows = $pdo->query("SELECT msg_type, COUNT(*) AS c, SUM(is_archived) AS archived
                          FROM bpq_messages GROUP BY msg_type ORDER BY msg_type")
                ->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "  (table is completely empty — nothing has ever been archived)\n";
    } else {
        printf("  %-10s %-10s %-10s\n", "Type", "Count", "Archived (not killed)");
        foreach ($rows as $r) {
            $label = ['P' => 'Personal', 'T' => 'Traffic/NTS', 'B' => 'Bulletin'][$r['msg_type']] ?? $r['msg_type'];
            printf("  %-10s %-10s %-10s\n", "{$r['msg_type']} ($label)", $r['c'], $r['archived']);
        }
    }
    $totalRows = array_sum(array_column($rows, 'c'));
    echo "\n  Total rows in table: $totalRows\n";

    // ── 2. The actual NTS/traffic rows, regardless of date ──────────
    line();
    echo "2. Every msg_type='T' row (Traffic/NTS), regardless of age or archive status\n";
    line();
    $ntsRows = $pdo->query("SELECT bbs_number, from_call, to_call, subject, msg_date,
                                    first_seen_at, is_archived, folder_id
                             FROM bpq_messages WHERE msg_type = 'T'
                             ORDER BY first_seen_at DESC")
                    ->fetchAll(PDO::FETCH_ASSOC);
    if (!$ntsRows) {
        echo "  ZERO rows with msg_type = 'T' found in the entire table.\n";
        echo "  This means either NTS traffic was never fetched/archived, or it's\n";
        echo "  being stored under a different msg_type. See section 3 below.\n";
    } else {
        printf("  Found %d row(s):\n\n", count($ntsRows));
        foreach ($ntsRows as $r) {
            $killed = $r['is_archived'] == 0 ? ' [KILLED/is_archived=0]' : '';
            $filed  = $r['folder_id'] ? " [filed, folder_id={$r['folder_id']}]" : '';
            printf("  #%-6s %-10s %-10s -> %-10s %-30s first_seen=%s%s%s\n",
                $r['bbs_number'], $r['msg_date'], $r['from_call'], $r['to_call'],
                substr($r['subject'], 0, 30), $r['first_seen_at'], $killed, $filed);
        }
    }

    // ── 3. Sanity check: any Personal-type rows that look like traffic ──
    // isTrafficMessage() client-side also checks for a 'T' status flag
    // separately from msg_type — if archiving ever stored one of these
    // under 'P' instead, this section catches it.
    line();
    echo "3. Personal-type rows whose subject/from suggests they might actually be traffic\n";
    line();
    $suspect = $pdo->query("SELECT bbs_number, from_call, to_call, subject, first_seen_at
                             FROM bpq_messages
                             WHERE msg_type = 'P'
                               AND (subject LIKE '%SITREP%' OR subject LIKE '%TRAFFIC%'
                                    OR to_call LIKE '%NTS%' OR to_call LIKE '%@%')
                             ORDER BY first_seen_at DESC LIMIT 20")
                    ->fetchAll(PDO::FETCH_ASSOC);
    if (!$suspect) {
        echo "  None found — no Personal-type rows look like misfiled traffic.\n";
    } else {
        foreach ($suspect as $r) {
            printf("  #%-6s %-10s -> %-10s %-30s first_seen=%s\n",
                $r['bbs_number'], $r['from_call'], $r['to_call'],
                substr($r['subject'], 0, 30), $r['first_seen_at']);
        }
    }

    // ── 4. If --days was given, show what the report's date filter would see ──
    if ($days !== null) {
        line();
        echo "4. What the report's WHERE first_seen_at >= NOW() - $days days would return\n";
        line();
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM bpq_messages
                                WHERE msg_type = 'T' AND first_seen_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC)['c'];
        echo "  $c row(s) would be included in a $days-day report.\n";
        if ($c == 0 && count($ntsRows) > 0) {
            echo "  --> Rows exist but are OLDER than $days days. Try a wider range on the report page.\n";
        }
    }

    line();
    echo "Done.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Query failed: " . $e->getMessage() . "\n");
    exit(1);
}
