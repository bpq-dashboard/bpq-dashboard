<?php
/**
 * nts-forwarding-report.php — NTS traffic forwarding report.
 *
 * Queries bpq_messages for NTS traffic (msg_type = 'T'), pulls each
 * message's BID out of its stored body (the "Bid: 12345_CALL" header
 * line every BPQ message carries), then scans BPQ's daily BBS log for
 * "FA"/"FC" forward-announcement lines matching that BID — the same
 * log format and parsing bbs-forwards.php already uses for the
 * dashboard's live forward-events panel. The result: for each piece of
 * NTS traffic, which station(s) it was actually forwarded to and when.
 *
 * There's no PDF library anywhere in this project or on a typical bare
 * PHP install, and installing one isn't something that can be verified
 * without hands-on access to the server. Rather than hand-roll raw PDF
 * bytes with no way to confirm they're valid, this renders a clean,
 * print-ready HTML report instead — the "Save as PDF" button just
 * triggers the browser's native Print dialog with the destination set
 * to "Save as PDF". Same end result (a real PDF file), zero new
 * dependencies, nothing that can come out corrupted.
 *
 * Query params:
 *   days=N   how many days of NTS traffic + forwarding log to cover
 *            (default 30, max 90 — matches bbs-forwards.php's own cap
 *            of 7 days for a single BID lookup, extended here since a
 *            report reasonably covers more ground than a live panel)
 */

declare(strict_types=1);

// Same bootstrap pattern message-storage.php uses — skip the BBS
// password check for this internal report endpoint.
if (file_exists(__DIR__ . '/includes/bootstrap.php')) {
    $SKIP_BBS_CHECK = true;
    require_once __DIR__ . '/includes/bootstrap.php';
}
require_once __DIR__ . '/includes/web-login.php';
// 2026-10-27: NTS traffic and who it went to — admin login only (was public)
bpq_require_web_login('The NTS forwarding report');
require_once __DIR__ . '/tprfn-db.php';

// ─── Input ────────────────────────────────────────────────────
$days = max(1, min((int)($_GET['days'] ?? 30), 365));

// ─── BID extraction (matches the client-side extractor's pattern) ──
function extractBid(?string $body): ?string {
    if (!$body) return null;
    if (preg_match('/^Bid:\s*(\S+)/mi', $body, $m)) return trim($m[1]);
    return null;
}

// ─── Forward-log scanning ───────────────────────────────────────
// Duplicated (not shared) from bbs-forwards.php deliberately — that
// file is a self-contained action-dispatch script that runs its own
// logic top-to-bottom the moment it's included, so it can't safely be
// require()'d from here. Keeping a second small copy of just the
// parsing logic avoids touching a file that's already working.
// BPQ's log folder (2026-10-25): env BPQ_LOG_DIR, else config.php paths.logs,
// else <web root>/logs. The fallback was /var/www/html/logs, which a standard
// install doesn't have, so every message showed "Not Yet Forwarded".
require_once __DIR__ . '/includes/station-config.php';
$LOG_DIR = rtrim((string)(getenv('BPQ_LOG_DIR') ?: (bpq_config_section('paths')['logs'] ?? '') ?: (__DIR__ . '/logs')), '/');
$MAX_LOG_BYTES = 50 * 1024 * 1024;

function logFilesForDays(string $logDir, int $days, int $maxBytes): array {
    $files = [];
    $now = time();
    for ($d = 0; $d <= $days; $d++) {
        $ts = $now - $d * 86400;
        $name = sprintf('%s/log_%s_BBS.txt', $logDir, date('ymd', $ts));
        if (file_exists($name) && filesize($name) < $maxBytes) {
            $files[] = $name;
        }
    }
    sort($files);
    return $files;
}

function parseForwardLine(string $line): ?array {
    $line = rtrim($line);
    if ($line === '') return null;
    if (!preg_match('/^(\d{6}) (\d{2}:\d{2}:\d{2}) >(\S+)\s+(.*)$/', $line, $m)) return null;
    $date = $m[1]; $time = $m[2]; $destBbs = $m[3]; $payload = $m[4];
    $year = 2000 + (int)substr($date, 0, 2);
    $month = (int)substr($date, 2, 2);
    $day = (int)substr($date, 4, 2);
    [$hh, $mm, $ss] = sscanf($time, '%d:%d:%d');
    $unix = mktime($hh, $mm, $ss, $month, $day, $year);

    if (preg_match('/^FA (\S) (\S+) (\S+) (\S+) (\S+) (\d+)/', $payload, $f)) {
        return ['time' => $unix, 'time_iso' => date('Y-m-d H:i', $unix), 'dest_bbs' => $destBbs, 'bid' => $f[5]];
    }
    if (preg_match('/^FC (\S+) (\S+) (\d+) (\d+) (\S+) (\S+) (\S+) (\S)$/', $payload, $f)) {
        return ['time' => $unix, 'time_iso' => date('Y-m-d H:i', $unix), 'dest_bbs' => $destBbs, 'bid' => $f[2]];
    }
    return null;
}

function scanLogsForBids(array $files, int $sinceTs): array {
    $byBid = [];
    foreach (array_reverse($files) as $path) {
        $fh = @fopen($path, 'r');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            $parsed = parseForwardLine($line);
            if (!$parsed || $parsed['time'] < $sinceTs) continue;
            $byBid[$parsed['bid']][] = $parsed;
        }
        fclose($fh);
    }
    foreach ($byBid as &$events) {
        usort($events, fn($a, $b) => $a['time'] <=> $b['time']);
    }
    return $byBid;
}

// ─── Pull NTS traffic from the DB ────────────────────────────────
$pdo = tprfn_db();
$sinceTs = time() - $days * 86400;
$sinceSql = date('Y-m-d H:i:s', $sinceTs);
$rows = tprfn_query($pdo,
    "SELECT bbs_number, from_call, to_call, subject, msg_date, body, first_seen_at
     FROM bpq_messages
     WHERE msg_type = 'T' AND first_seen_at >= ?
     ORDER BY first_seen_at DESC",
    [$sinceSql]
);

$files = logFilesForDays($LOG_DIR, $days, $MAX_LOG_BYTES);
$eventsByBid = scanLogsForBids($files, $sinceTs);

$report = [];
foreach ($rows as $r) {
    $bid = extractBid($r['body']);
    $events = ($bid && isset($eventsByBid[$bid])) ? $eventsByBid[$bid] : [];
    $report[] = [
        'number'   => $r['bbs_number'],
        'date'     => $r['msg_date'],
        'from'     => $r['from_call'],
        'to'       => $r['to_call'],
        'subject'  => $r['subject'],
        'bid'      => $bid,
        'events'   => $events,
    ];
}

$forwardedCount = count(array_filter($report, fn($r) => count($r['events']) > 0));
$totalCount = count($report);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NTS Traffic Forwarding Report — <?= h(date('Y-m-d')) ?></title>
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        margin: 0; padding: 32px; background: #fff; color: #1a1a1a;
        font-size: 13px; line-height: 1.5;
    }
    .toolbar {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #333;
    }
    .toolbar button {
        background: #2563eb; color: #fff; border: none; border-radius: 6px;
        padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer;
    }
    .toolbar button:hover { background: #1d4ed8; }
    .toolbar .controls { display: flex; gap: 10px; align-items: center; }
    .toolbar label { font-size: 13px; color: #444; }
    .toolbar select {
        padding: 6px 10px; border-radius: 6px; border: 1px solid #ccc; font-size: 13px;
    }
    h1 { font-size: 20px; margin: 0 0 4px 0; }
    .subtitle { color: #555; font-size: 13px; margin-bottom: 0; }
    .summary {
        display: flex; gap: 24px; margin: 20px 0; padding: 14px 18px;
        background: #f4f6f8; border-radius: 8px; border: 1px solid #e2e5e9;
    }
    .summary .stat { text-align: center; }
    .summary .stat .n { font-size: 22px; font-weight: 700; color: #1a1a1a; }
    .summary .stat .l { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.04em; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th {
        text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em;
        color: #555; border-bottom: 2px solid #333; padding: 8px 10px;
    }
    td { padding: 8px 10px; border-bottom: 1px solid #e2e5e9; vertical-align: top; }
    tr:nth-child(even) td { background: #fafbfc; }
    .num { color: #888; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .subj { max-width: 280px; }
    .bid { font-family: ui-monospace, monospace; font-size: 11px; color: #666; white-space: nowrap; }
    .dest-list { display: flex; flex-direction: column; gap: 2px; }
    .dest { font-size: 12px; }
    .dest .call { font-weight: 600; }
    .dest .time { color: #888; font-size: 11px; }
    .status-badge {
        display: inline-block; padding: 2px 8px; border-radius: 10px;
        font-size: 11px; font-weight: 600;
    }
    .status-forwarded { background: #dcfce7; color: #166534; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .no-bid { color: #999; font-style: italic; font-size: 12px; }
    footer { margin-top: 24px; color: #999; font-size: 11px; }

    @media print {
        body { padding: 12px; font-size: 11px; }
        .toolbar { display: none; }
        table { page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    }
</style>
</head>
<body>

    <div class="toolbar">
        <div>
            <h1>NTS Traffic Forwarding Report</h1>
            <p class="subtitle">Generated <?= h(date('Y-m-d H:i')) ?> — covering the last <?= h($days) ?> days</p>
        </div>
        <div class="controls">
            <form method="get" style="display:flex; gap:8px; align-items:center;">
                <label for="days">Range:</label>
                <select name="days" id="days" onchange="this.form.submit()">
                    <?php foreach ([7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days', 90 => '90 days', 180 => '180 days', 365 => '1 year'] as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $val === $days ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button onclick="window.print()">🖨 Save as PDF</button>
        </div>
    </div>

    <div class="summary">
        <div class="stat"><div class="n"><?= h($totalCount) ?></div><div class="l">NTS Messages</div></div>
        <div class="stat"><div class="n"><?= h($forwardedCount) ?></div><div class="l">Forwarded</div></div>
        <div class="stat"><div class="n"><?= h($totalCount - $forwardedCount) ?></div><div class="l">Not Yet Forwarded</div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>From</th>
                <th>To</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Forwarded To</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($report)): ?>
                <tr><td colspan="7" style="text-align:center; color:#999; padding:24px;">No NTS traffic found in this range.</td></tr>
            <?php endif; ?>
            <?php foreach ($report as $r): ?>
                <tr>
                    <td class="num">#<?= h($r['number']) ?></td>
                    <td class="num"><?= h($r['date']) ?></td>
                    <td><?= h($r['from']) ?></td>
                    <td><?= h($r['to']) ?></td>
                    <td class="subj"><?= h($r['subject']) ?></td>
                    <td>
                        <?php if (count($r['events']) > 0): ?>
                            <span class="status-badge status-forwarded">Forwarded</span>
                        <?php else: ?>
                            <span class="status-badge status-pending">Not Yet Forwarded</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (count($r['events']) > 0): ?>
                            <div class="dest-list">
                                <?php foreach ($r['events'] as $e): ?>
                                    <div class="dest"><span class="call"><?= h($e['dest_bbs']) ?></span> <span class="time"><?= h($e['time_iso']) ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!$r['bid']): ?>
                            <span class="no-bid">no BID found in body</span>
                        <?php else: ?>
                            <span class="bid">BID <?= h($r['bid']) ?> — no forward events in range</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <footer>
        NTS traffic sourced from the message archive (bpq_messages). Forwarding events parsed from
        BPQ's BBS log<?= !empty($files) ? ' (' . h(count($files)) . ' day' . (count($files) === 1 ? '' : 's') . ' of logs read)' : ' — no log files found for this range' ?>.
        A message with no BID in its body can't be matched to forward events even if it was in fact forwarded.
    </footer>

</body>
</html>
