<?php
/**
 * TPRFN Network Hub Health Report
 * Deploy to: /var/www/tprfn/tprfn-hub-report.php
 * Access: https://tprfn.k1ajd.net/tprfn-hub-report.php
 */

// ── Database connection ───────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=tprfn;charset=utf8mb4',
        'tprfn_app',
        'TprfnDb2026!',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('<pre style="color:red">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</pre>');
}

$generated = date('Y-m-d H:i:s') . ' UTC';

// ── 1. Network Overview ───────────────────────────────────────
$overview = $pdo->query("
    SELECT
        COUNT(*)                                                        AS total_sessions,
        MIN(session_date)                                               AS earliest,
        MAX(session_date)                                               AS latest,
        DATEDIFF(MAX(session_date), MIN(session_date))                  AS span_days,
        COUNT(DISTINCT hub)                                             AS active_hubs,
        COUNT(DISTINCT station)                                         AS unique_stations,
        SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)         AS successful,
        SUM(CASE WHEN bytes_tx+bytes_rx = 0 THEN 1 ELSE 0 END)         AS failed,
        ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
              / COUNT(*) * 100, 1)                                      AS success_pct,
        ROUND(AVG(CASE WHEN avg_snr IS NOT NULL THEN avg_snr END), 1)   AS avg_snr,
        ROUND(SUM(bytes_tx+bytes_rx)/1048576, 1)                        AS total_mb
    FROM sessions
")->fetch();

// ── 2. Hub Performance ────────────────────────────────────────
$hubs = $pdo->query("
    SELECT
        hub,
        COUNT(*)                                                        AS sessions,
        SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)         AS successful,
        SUM(CASE WHEN bytes_tx+bytes_rx = 0 THEN 1 ELSE 0 END)         AS failed,
        ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
              / COUNT(*) * 100, 1)                                      AS success_pct,
        ROUND(AVG(CASE WHEN avg_snr IS NOT NULL THEN avg_snr END), 1)   AS avg_snr,
        ROUND(MAX(avg_snr), 1)                                          AS best_snr,
        ROUND(MIN(avg_snr), 1)                                          AS worst_snr,
        COUNT(DISTINCT station)                                         AS unique_stations,
        MAX(session_date)                                               AS last_session,
        CASE
            WHEN SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
                 / COUNT(*) >= 0.80 THEN 'HEALTHY'
            WHEN SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
                 / COUNT(*) >= 0.60 THEN 'MARGINAL'
            ELSE 'POOR'
        END                                                             AS status
    FROM sessions
    GROUP BY hub
    ORDER BY sessions DESC
")->fetchAll();

// ── 3. Today's Activity ───────────────────────────────────────
$today = $pdo->query("
    SELECT
        hub,
        COUNT(*)                                                        AS sessions,
        SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)         AS successful,
        ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
              / COUNT(*) * 100, 1)                                      AS success_pct,
        ROUND(AVG(CASE WHEN avg_snr IS NOT NULL THEN avg_snr END), 1)   AS avg_snr,
        MAX(connected_at)                                               AS last_connect
    FROM sessions
    WHERE session_date = CURDATE()
    GROUP BY hub
    ORDER BY sessions DESC
")->fetchAll();

// ── 4. Hubs Requiring Attention ───────────────────────────────
$attention = $pdo->query("
    SELECT
        hub,
        COUNT(*)                                                        AS sessions,
        ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
              / COUNT(*) * 100, 1)                                      AS success_pct,
        ROUND(AVG(CASE WHEN avg_snr IS NOT NULL THEN avg_snr END), 1)   AS avg_snr,
        MAX(session_date)                                               AS last_session
    FROM sessions
    GROUP BY hub
    HAVING success_pct < 60
    ORDER BY success_pct ASC
")->fetchAll();

// ── 5. Hub-to-Hub Links ───────────────────────────────────────
$h2h = $pdo->query("
    SELECT
        hub, station,
        COUNT(*)                                                        AS sessions,
        ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
              / COUNT(*) * 100, 1)                                      AS success_pct,
        ROUND(AVG(CASE WHEN avg_snr IS NOT NULL THEN avg_snr END), 1)   AS avg_snr,
        ROUND(AVG(duration_secs)/60, 1)                                 AS avg_dur_min,
        ROUND(SUM(bytes_tx+bytes_rx)/1024, 0)                           AS total_kb,
        MAX(session_date)                                               AS last_session
    FROM sessions
    WHERE is_hub_to_hub = 1
    GROUP BY hub, station
    ORDER BY sessions DESC
    LIMIT 20
")->fetchAll();

// ── 6. Top Polling Stations ───────────────────────────────────
$stations = $pdo->query("
    SELECT
        station,
        COUNT(*)                                                        AS sessions,
        ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
              / COUNT(*) * 100, 1)                                      AS success_pct,
        ROUND(AVG(CASE WHEN avg_snr IS NOT NULL THEN avg_snr END), 1)   AS avg_snr,
        COUNT(DISTINCT hub)                                             AS hubs_connected,
        MAX(session_date)                                               AS last_seen
    FROM sessions
    WHERE is_hub_to_hub = 0
    GROUP BY station
    ORDER BY sessions DESC
    LIMIT 15
")->fetchAll();

// ── 7. Daily Trend (14 days) ──────────────────────────────────
$trend = $pdo->query("
    SELECT
        session_date,
        COUNT(*)                                                        AS total,
        SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)         AS successful,
        SUM(CASE WHEN bytes_tx+bytes_rx = 0 THEN 1 ELSE 0 END)         AS failed,
        ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
              / COUNT(*) * 100, 1)                                      AS success_pct,
        ROUND(AVG(CASE WHEN avg_snr IS NOT NULL THEN avg_snr END), 1)   AS avg_snr,
        COUNT(DISTINCT hub)                                             AS hubs_active
    FROM sessions
    WHERE session_date >= CURDATE() - INTERVAL 14 DAY
    GROUP BY session_date
    ORDER BY session_date DESC
")->fetchAll();

// ── 8. VARA Speed Distribution ────────────────────────────────
$vara = $pdo->query("
    SELECT
        CASE
            WHEN max_bps = 0       THEN '0 bps — No data / failed'
            WHEN max_bps < 175     THEN 'FSK  SL1–3  (<175 bps)'
            WHEN max_bps < 550     THEN 'FSK  SL4    (175–549 bps)'
            WHEN max_bps < 1000    THEN '4PSK SL5–9  (550–999 bps)'
            WHEN max_bps < 3000    THEN '4PSK SL10–12 (1–3 kbps)'
            WHEN max_bps < 5000    THEN '8PSK SL13–14 (3–5 kbps)'
            WHEN max_bps < 7000    THEN '16QAM SL15  (5–7 kbps)'
            ELSE                        '32QAM SL16–17 (7+ kbps)'
        END                                                             AS tier,
        COUNT(*)                                                        AS sessions,
        ROUND(COUNT(*) / (SELECT COUNT(*) FROM sessions) * 100, 1)     AS pct
    FROM sessions
    GROUP BY tier
    ORDER BY MIN(max_bps)
")->fetchAll();

// ── 9. Prop Scheduler — Decision History (last 30 days) ─────
$prop_decisions = [];
try {
    $prop_decisions = $pdo->query("
        SELECT run_at, run_mode, season, sfi, kp, partner,
               changed, historical_summary, old_script, new_script, blocks_json
        FROM prop_decisions
        WHERE run_at >= NOW() - INTERVAL 30 DAY
        ORDER BY run_at DESC, partner ASC
        LIMIT 200
    ")->fetchAll();
} catch (PDOException $e) { /* table may not exist yet */ }

// ── 10. Band Decision Correlation ─────────────────────────────
$band_correlation = [];
try {
    $band_correlation = $pdo->query("
        SELECT
            pd.partner,
            DATE(pd.run_at)                                         AS decision_date,
            pd.sfi, pd.kp, pd.season,
            pd.historical_summary,
            COALESCE(SUM(pre.sessions), 0)                          AS pre_sessions,
            COALESCE(ROUND(AVG(pre.success_pct), 1), 0)             AS pre_success_pct,
            COALESCE(ROUND(AVG(pre.avg_snr), 1), 0)                 AS pre_avg_snr,
            COALESCE(SUM(post.sessions), 0)                         AS post_sessions,
            COALESCE(ROUND(AVG(post.success_pct), 1), 0)            AS post_success_pct,
            COALESCE(ROUND(AVG(post.avg_snr), 1), 0)                AS post_avg_snr,
            COALESCE(ROUND(AVG(post.success_pct), 1), 0) -
                COALESCE(ROUND(AVG(pre.success_pct), 1), 0)         AS success_delta
        FROM prop_decisions pd
        LEFT JOIN (
            SELECT SUBSTRING_INDEX(station, '-', 1) AS base_call,
                   DATE(connected_at)               AS conn_date,
                   COUNT(*)                         AS sessions,
                   ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
                         / COUNT(*) * 100, 1)       AS success_pct,
                   ROUND(AVG(avg_snr), 1)           AS avg_snr
            FROM sessions GROUP BY base_call, conn_date
        ) pre ON pre.base_call = pd.partner
              AND pre.conn_date BETWEEN DATE(pd.run_at) - INTERVAL 7 DAY
                                   AND DATE(pd.run_at) - INTERVAL 1 DAY
        LEFT JOIN (
            SELECT SUBSTRING_INDEX(station, '-', 1) AS base_call,
                   DATE(connected_at)               AS conn_date,
                   COUNT(*)                         AS sessions,
                   ROUND(SUM(CASE WHEN bytes_tx+bytes_rx > 0 THEN 1 ELSE 0 END)
                         / COUNT(*) * 100, 1)       AS success_pct,
                   ROUND(AVG(avg_snr), 1)           AS avg_snr
            FROM sessions GROUP BY base_call, conn_date
        ) post ON post.base_call = pd.partner
               AND post.conn_date BETWEEN DATE(pd.run_at) + INTERVAL 1 DAY
                                     AND DATE(pd.run_at) + INTERVAL 7 DAY
        WHERE pd.changed = 1
          AND pd.run_at >= NOW() - INTERVAL 90 DAY
        GROUP BY pd.id, pd.partner, pd.run_at, pd.sfi, pd.kp,
                 pd.season, pd.historical_summary
        ORDER BY pd.run_at DESC
        LIMIT 30
    ")->fetchAll();
} catch (PDOException $e) { /* table may not exist yet */ }

// ── Helpers ───────────────────────────────────────────────────
function statusClass($s) {
    return match($s) { 'HEALTHY' => 'status-ok', 'MARGINAL' => 'status-warn', default => 'status-poor' };
}
function snrClass($v) {
    if ($v === null) return '';
    if ($v >= 5)  return 'snr-good';
    if ($v >= 0)  return 'snr-ok';
    return 'snr-poor';
}
function pctClass($v) {
    if ($v >= 80) return 'pct-good';
    if ($v >= 60) return 'pct-warn';
    return 'pct-poor';
}
function pctBar($v) {
    $w = max(0, min(100, (float)$v));
    $c = $w >= 80 ? '#3fb950' : ($w >= 60 ? '#d29922' : '#f85149');
    return "<div class='bar-wrap'><div class='bar' style='width:{$w}%;background:{$c}'></div><span>{$v}%</span></div>";
}
function snrBadge($v) {
    if ($v === null) return '<span class="na">–</span>';
    $c = $v >= 5 ? '#3fb950' : ($v >= 0 ? '#8b949e' : '#f85149');
    return "<span style='color:{$c};font-weight:700'>{$v} dB</span>";
}
function h($s) { return htmlspecialchars($s ?? '–'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TPRFN Hub Health Report — <?= date('Y-m-d') ?></title>
<style>
/* ── FONTS ── */
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&family=IBM+Plex+Sans:wght@300;400;600;700&display=swap');

/* ── VARIABLES ── */
:root {
  --bg:      #0d1117;
  --surface: #161b22;
  --s2:      #1c2128;
  --border:  rgba(48,54,61,0.9);
  --text:    #e6edf3;
  --text2:   #8b949e;
  --text3:   #6e7681;
  --green:   #3fb950;
  --amber:   #d29922;
  --red:     #f85149;
  --blue:    #58a6ff;
  --purple:  #bc8cff;
  --cyan:    #39d0d0;
  --mono:    'IBM Plex Mono', monospace;
  --sans:    'IBM Plex Sans', sans-serif;
}

/* ── BASE ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 13px; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  line-height: 1.6;
  min-height: 100vh;
}

/* ── TOOLBAR ── */
.toolbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 12px 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  position: sticky;
  top: 0;
  z-index: 100;
}
.toolbar-brand { font-family: var(--mono); font-size: 13px; font-weight: 700; color: var(--green); letter-spacing: 0.05em; }
.toolbar-meta  { font-family: var(--mono); font-size: 10px; color: var(--text3); }
.toolbar-right { display: flex; gap: 8px; align-items: center; }

.btn {
  font-family: var(--mono); font-size: 11px; font-weight: 700;
  padding: 6px 16px; border-radius: 5px; cursor: pointer;
  border: 1px solid; transition: all 0.15s; text-decoration: none;
  display: inline-flex; align-items: center; gap: 6px;
}
.btn-pdf   { background: rgba(248,81,73,0.12);  color: var(--red);   border-color: rgba(248,81,73,0.4); }
.btn-print { background: rgba(88,166,255,0.12); color: var(--blue);  border-color: rgba(88,166,255,0.4); }
.btn-pdf:hover   { background: rgba(248,81,73,0.25); }
.btn-print:hover { background: rgba(88,166,255,0.25); }

/* ── REPORT WRAPPER ── */
.report {
  max-width: 1300px;
  margin: 0 auto;
  padding: 28px 28px 60px;
}

/* ── REPORT HEADER ── */
.report-header {
  border-bottom: 2px solid var(--border);
  padding-bottom: 20px;
  margin-bottom: 28px;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.report-title { font-family: var(--mono); font-size: 22px; font-weight: 700; color: var(--text); letter-spacing: -0.02em; }
.report-title span { color: var(--green); }
.report-subtitle { font-size: 11px; color: var(--text3); margin-top: 4px; font-family: var(--mono); }
.report-stamp {
  text-align: right;
  font-family: var(--mono);
  font-size: 10px;
  color: var(--text3);
  line-height: 1.8;
}

/* ── SECTION ── */
.section { margin-bottom: 32px; }
.section-title {
  font-family: var(--mono);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--text3);
  border-bottom: 1px solid var(--border);
  padding-bottom: 6px;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.section-title::before {
  content: '';
  display: inline-block;
  width: 6px; height: 6px;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}
.section-title.green  { color: var(--green); }
.section-title.amber  { color: var(--amber); }
.section-title.red    { color: var(--red); }
.section-title.blue   { color: var(--blue); }
.section-title.purple { color: var(--purple); }
.section-title.cyan   { color: var(--cyan); }

/* ── OVERVIEW CARDS ── */
.overview-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px;
  margin-bottom: 0;
}
.ov-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 14px 16px;
  position: relative;
  overflow: hidden;
}
.ov-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
}
.ov-card.green::before  { background: var(--green); }
.ov-card.blue::before   { background: var(--blue); }
.ov-card.amber::before  { background: var(--amber); }
.ov-card.red::before    { background: var(--red); }
.ov-card.purple::before { background: var(--purple); }
.ov-card.cyan::before   { background: var(--cyan); }
.ov-label { font-family: var(--mono); font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text3); margin-bottom: 6px; }
.ov-value { font-family: var(--mono); font-size: 24px; font-weight: 700; line-height: 1; }
.ov-card.green  .ov-value { color: var(--green); }
.ov-card.blue   .ov-value { color: var(--blue); }
.ov-card.amber  .ov-value { color: var(--amber); }
.ov-card.red    .ov-value { color: var(--red); }
.ov-card.purple .ov-value { color: var(--purple); }
.ov-card.cyan   .ov-value { color: var(--cyan); }
.ov-sub { font-family: var(--mono); font-size: 10px; color: var(--text3); margin-top: 3px; }

/* ── TABLES ── */
.tbl-wrap { overflow-x: auto; }
table {
  width: 100%;
  border-collapse: collapse;
  font-family: var(--mono);
  font-size: 11px;
}
thead tr { background: var(--s2); }
th {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text3);
  padding: 8px 10px;
  text-align: left;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
td {
  padding: 8px 10px;
  color: var(--text2);
  border-bottom: 1px solid rgba(48,54,61,0.5);
  white-space: nowrap;
}
tbody tr:hover { background: var(--s2); }
tbody tr:last-child td { border-bottom: none; }

/* ── STATUS BADGES ── */
.status-ok   { background: rgba(63,185,80,0.15);  color: var(--green); border: 1px solid rgba(63,185,80,0.4);  padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; }
.status-warn { background: rgba(210,153,34,0.15); color: var(--amber); border: 1px solid rgba(210,153,34,0.4); padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; }
.status-poor { background: rgba(248,81,73,0.15);  color: var(--red);   border: 1px solid rgba(248,81,73,0.4);  padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; }
.pct-good { color: var(--green); font-weight: 700; }
.pct-warn { color: var(--amber); font-weight: 700; }
.pct-poor { color: var(--red);   font-weight: 700; }
.snr-good { color: var(--green); font-weight: 700; }
.snr-ok   { color: var(--text2); }
.snr-poor { color: var(--red);   font-weight: 700; }
.na { color: var(--text3); }
.hub-id { color: var(--text); font-weight: 700; }

/* ── BAR ── */
.bar-wrap { display: flex; align-items: center; gap: 8px; min-width: 120px; }
.bar { height: 5px; border-radius: 2px; flex-shrink: 0; }
.bar-wrap span { font-size: 10px; font-weight: 700; white-space: nowrap; }

/* ── ALERT BOX ── */
.alert-box {
  background: rgba(248,81,73,0.08);
  border: 1px solid rgba(248,81,73,0.3);
  border-left: 3px solid var(--red);
  border-radius: 6px;
  padding: 12px 16px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.alert-box .alert-call { font-weight: 700; color: var(--text); min-width: 100px; }
.alert-box .alert-stat { font-size: 11px; color: var(--text2); }
.alert-box .alert-badge { font-size: 9px; font-weight: 700; background: rgba(248,81,73,0.2); color: var(--red); border: 1px solid rgba(248,81,73,0.4); padding: 2px 7px; border-radius: 3px; white-space: nowrap; }

/* ── TREND INDICATOR ── */
.trend-up   { color: var(--green); }
.trend-down { color: var(--red); }

/* ── FOOTER ── */
.report-footer {
  margin-top: 40px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  font-family: var(--mono);
  font-size: 10px;
  color: var(--text3);
}

/* ══════════════════════════════════════════
   PRINT / PDF STYLES
══════════════════════════════════════════ */
@media print {
  @page {
    size: A4 landscape;
    margin: 12mm 14mm;
  }
  body {
    background: #fff !important;
    color: #111 !important;
    font-size: 10px;
  }
  .toolbar { display: none !important; }
  .report { padding: 0; max-width: 100%; }
  :root {
    --bg: #fff; --surface: #fff; --s2: #f5f5f5;
    --border: #ddd; --text: #111; --text2: #333; --text3: #666;
    --green: #1a7f37; --amber: #9a6700; --red: #cf222e;
    --blue: #0969da; --purple: #6639ba; --cyan: #1b7c83;
  }
  .ov-card, table, .alert-box { border: 1px solid #ddd !important; }
  thead tr { background: #f0f0f0 !important; }
  tbody tr:hover { background: transparent !important; }
  .section { break-inside: avoid; }
  table { break-inside: auto; }
  tr { break-inside: avoid; }
  .report-header, .overview-grid { break-after: avoid; }
  .section-title { break-after: avoid; }
  .bar { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  .status-ok, .status-warn, .status-poor { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
}
</style>
</head>
<body>

<!-- ── TOOLBAR ─────────────────────────────────────────── -->
<div class="toolbar">
  <div>
    <div class="toolbar-brand">📡 TPRFN Hub Health Report</div>
    <div class="toolbar-meta">Generated <?= $generated ?> · <?= $overview['active_hubs'] ?> hubs · <?= number_format($overview['total_sessions']) ?> sessions</div>
  </div>
  <div class="toolbar-right">
    <button class="btn btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
  </div>
</div>

<!-- ── REPORT ───────────────────────────────────────────── -->
<div class="report">

  <!-- HEADER -->
  <div class="report-header">
    <div>
      <div class="report-title">TPRFN Network <span>Hub Health Report</span></div>
      <div class="report-subtitle">K1AJD · ARSSYSTEM · All-time data from <?= h($overview['earliest']) ?> to <?= h($overview['latest']) ?></div>
    </div>
    <div class="report-stamp">
      Generated: <?= $generated ?><br>
      Database: tprfn (MariaDB)<br>
      Report version: 1.0
    </div>
  </div>

  <!-- 1. OVERVIEW -->
  <div class="section">
    <div class="section-title green">1 · Network Overview</div>
    <div class="overview-grid">
      <div class="ov-card green">
        <div class="ov-label">Total Sessions</div>
        <div class="ov-value"><?= number_format($overview['total_sessions']) ?></div>
        <div class="ov-sub"><?= $overview['span_days'] ?> day span</div>
      </div>
      <div class="ov-card blue">
        <div class="ov-label">Successful</div>
        <div class="ov-value"><?= number_format($overview['successful']) ?></div>
        <div class="ov-sub"><?= $overview['success_pct'] ?>% success rate</div>
      </div>
      <div class="ov-card red">
        <div class="ov-label">Failed</div>
        <div class="ov-value"><?= number_format($overview['failed']) ?></div>
        <div class="ov-sub"><?= round(100 - $overview['success_pct'], 1) ?>% fail rate</div>
      </div>
      <div class="ov-card amber">
        <div class="ov-label">Active Hubs</div>
        <div class="ov-value"><?= $overview['active_hubs'] ?></div>
        <div class="ov-sub"><?= number_format($overview['unique_stations']) ?> unique stations</div>
      </div>
      <div class="ov-card purple">
        <div class="ov-label">Avg S/N</div>
        <div class="ov-value"><?= $overview['avg_snr'] ?></div>
        <div class="ov-sub">dB network-wide</div>
      </div>
      <div class="ov-card cyan">
        <div class="ov-label">Total Data</div>
        <div class="ov-value"><?= $overview['total_mb'] ?></div>
        <div class="ov-sub">MB transferred</div>
      </div>
    </div>
  </div>

  <!-- 2. HUB PERFORMANCE -->
  <div class="section">
    <div class="section-title blue">2 · Hub Performance (all-time)</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Hub</th>
            <th>Sessions</th>
            <th>Successful</th>
            <th>Failed</th>
            <th>Success Rate</th>
            <th>Avg S/N</th>
            <th>Best S/N</th>
            <th>Worst S/N</th>
            <th>Stations</th>
            <th>Last Session</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hubs as $r): ?>
          <tr>
            <td class="hub-id"><?= h($r['hub']) ?></td>
            <td><?= number_format($r['sessions']) ?></td>
            <td class="pct-good"><?= number_format($r['successful']) ?></td>
            <td class="<?= $r['failed'] > 0 ? 'pct-poor' : '' ?>"><?= number_format($r['failed']) ?></td>
            <td><?= pctBar($r['success_pct']) ?></td>
            <td class="<?= snrClass($r['avg_snr']) ?>"><?= $r['avg_snr'] !== null ? $r['avg_snr'].' dB' : '–' ?></td>
            <td class="snr-good"><?= $r['best_snr'] !== null ? $r['best_snr'].' dB' : '–' ?></td>
            <td class="snr-poor"><?= $r['worst_snr'] !== null ? $r['worst_snr'].' dB' : '–' ?></td>
            <td><?= $r['unique_stations'] ?></td>
            <td><?= h($r['last_session']) ?></td>
            <td><span class="<?= statusClass($r['status']) ?>"><?= h($r['status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 3. TODAY -->
  <div class="section">
    <div class="section-title green">3 · Today's Activity (<?= date('Y-m-d') ?> UTC)</div>
    <?php if (empty($today)): ?>
      <p style="color:var(--text3);font-family:var(--mono);font-size:11px;">No sessions recorded today yet.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Hub</th><th>Sessions Today</th><th>Successful</th><th>Success Rate</th><th>Avg S/N</th><th>Last Connect</th></tr>
        </thead>
        <tbody>
          <?php foreach ($today as $r): ?>
          <tr>
            <td class="hub-id"><?= h($r['hub']) ?></td>
            <td><?= number_format($r['sessions']) ?></td>
            <td class="pct-good"><?= number_format($r['successful']) ?></td>
            <td><?= pctBar($r['success_pct']) ?></td>
            <td class="<?= snrClass($r['avg_snr']) ?>"><?= $r['avg_snr'] !== null ? $r['avg_snr'].' dB' : '–' ?></td>
            <td><?= h($r['last_connect']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- 4. ATTENTION -->
  <div class="section">
    <div class="section-title red">4 · Hubs Requiring Attention (success rate &lt; 60%)</div>
    <?php if (empty($attention)): ?>
      <p style="color:var(--green);font-family:var(--mono);font-size:11px;">✓ All hubs above 60% success threshold.</p>
    <?php else: ?>
      <?php foreach ($attention as $r): ?>
      <div class="alert-box">
        <span class="alert-call"><?= h($r['hub']) ?></span>
        <span class="alert-stat">
          <?= number_format($r['sessions']) ?> sessions &nbsp;·&nbsp;
          <?= snrBadge($r['avg_snr']) ?> avg S/N &nbsp;·&nbsp;
          Last: <?= h($r['last_session']) ?>
        </span>
        <span class="alert-badge"><?= $r['success_pct'] ?>% success</span>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- 5. HUB-TO-HUB -->
  <div class="section">
    <div class="section-title purple">5 · Hub-to-Hub Link Quality (top 20)</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Hub</th><th>Station</th><th>Sessions</th><th>Success Rate</th><th>Avg S/N</th><th>Avg Duration</th><th>Total Data</th><th>Last Session</th></tr>
        </thead>
        <tbody>
          <?php foreach ($h2h as $r): ?>
          <tr>
            <td class="hub-id"><?= h($r['hub']) ?></td>
            <td class="hub-id"><?= h($r['station']) ?></td>
            <td><?= number_format($r['sessions']) ?></td>
            <td><?= pctBar($r['success_pct']) ?></td>
            <td class="<?= snrClass($r['avg_snr']) ?>"><?= $r['avg_snr'] !== null ? $r['avg_snr'].' dB' : '–' ?></td>
            <td><?= $r['avg_dur_min'] !== null ? $r['avg_dur_min'].' min' : '–' ?></td>
            <td><?= number_format($r['total_kb']) ?> KB</td>
            <td><?= h($r['last_session']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 6. TOP STATIONS -->
  <div class="section">
    <div class="section-title amber">6 · Top 15 Polling Stations</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Station</th><th>Sessions</th><th>Success Rate</th><th>Avg S/N</th><th>Hubs Connected</th><th>Last Seen</th></tr>
        </thead>
        <tbody>
          <?php foreach ($stations as $i => $r): ?>
          <tr>
            <td style="color:var(--text3)"><?= $i+1 ?></td>
            <td class="hub-id"><?= h($r['station']) ?></td>
            <td><?= number_format($r['sessions']) ?></td>
            <td><?= pctBar($r['success_pct']) ?></td>
            <td class="<?= snrClass($r['avg_snr']) ?>"><?= $r['avg_snr'] !== null ? $r['avg_snr'].' dB' : '–' ?></td>
            <td><?= $r['hubs_connected'] ?></td>
            <td><?= h($r['last_seen']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 7. DAILY TREND -->
  <div class="section">
    <div class="section-title cyan">7 · Daily Session Trend (last 14 days)</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Date</th><th>Total</th><th>Successful</th><th>Failed</th><th>Success Rate</th><th>Avg S/N</th><th>Hubs Active</th></tr>
        </thead>
        <tbody>
          <?php foreach ($trend as $r): ?>
          <tr>
            <td><?= h($r['session_date']) ?></td>
            <td><?= number_format($r['total']) ?></td>
            <td class="pct-good"><?= number_format($r['successful']) ?></td>
            <td class="<?= $r['failed'] > 0 ? 'pct-poor' : '' ?>"><?= number_format($r['failed']) ?></td>
            <td><?= pctBar($r['success_pct']) ?></td>
            <td class="<?= snrClass($r['avg_snr']) ?>"><?= $r['avg_snr'] !== null ? $r['avg_snr'].' dB' : '–' ?></td>
            <td><?= $r['hubs_active'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 8. VARA SPEED -->
  <div class="section">
    <div class="section-title green">8 · VARA Speed Distribution</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr><th>Speed Tier</th><th>Sessions</th><th>% of Total</th><th>Distribution</th></tr>
        </thead>
        <tbody>
          <?php foreach ($vara as $r): ?>
          <tr>
            <td><?= h($r['tier']) ?></td>
            <td><?= number_format($r['sessions']) ?></td>
            <td class="<?= pctClass($r['pct']) ?>"><?= $r['pct'] ?>%</td>
            <td>
              <div class="bar-wrap">
                <div class="bar" style="width:<?= min(100,$r['pct']) ?>%;background:var(--blue);height:6px;"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 9. PROP SCHEDULER DECISIONS -->
  <div class="section">
    <div class="section-title purple">9 · Prop Scheduler Decision History (last 30 days)</div>
    <?php if (empty($prop_decisions)): ?>
      <p style="color:var(--text3);font-family:var(--mono);font-size:11px;">No prop_decisions data yet. Deploy updated prop-scheduler.py and run once.</p>
    <?php else:
      // Group by run_at
      $runs = [];
      foreach ($prop_decisions as $r) {
          $key = $r['run_at'];
          if (!isset($runs[$key])) $runs[$key] = ['meta' => $r, 'rows' => []];
          $runs[$key]['rows'][] = $r;
      }
    ?>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Run (UTC)</th>
            <th>Mode</th>
            <th>SFI</th>
            <th>Kp</th>
            <th>Season</th>
            <th>Partner</th>
            <th>Changed</th>
            <th>Historical Summary</th>
            <th>New Schedule (excerpt)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($prop_decisions as $r):
            $changedBadge = $r['changed']
              ? '<span class="status-ok">YES</span>'
              : '<span style="color:var(--text4);font-family:var(--mono);font-size:10px;">–</span>';
            $newScript = $r['new_script'] ? substr($r['new_script'], 0, 60) . '…' : '–';
            $kpClass = '';
            if ($r['kp'] !== null) {
              $kpClass = $r['kp'] >= 5 ? 'pct-poor' : ($r['kp'] >= 3 ? 'pct-warn' : 'pct-good');
            }
            $sfiClass = '';
            if ($r['sfi'] !== null) {
              $sfiClass = $r['sfi'] >= 150 ? 'pct-good' : ($r['sfi'] >= 100 ? 'pct-warn' : 'pct-poor');
            }
          ?>
          <tr>
            <td style="color:var(--text3)"><?= h($r['run_at']) ?></td>
            <td><span class="<?= $r['run_mode']==='apply'?'status-ok':'status-warn' ?>"><?= h(strtoupper($r['run_mode'])) ?></span></td>
            <td class="<?= $sfiClass ?>"><?= $r['sfi'] ?? '–' ?></td>
            <td class="<?= $kpClass ?>"><?= $r['kp'] ?? '–' ?></td>
            <td><?= h($r['season']) ?></td>
            <td class="hub-id"><?= h($r['partner']) ?></td>
            <td><?= $changedBadge ?></td>
            <td style="font-size:10px;color:var(--text3);max-width:240px;white-space:normal;"><?= h($r['historical_summary']) ?></td>
            <td style="font-size:10px;color:var(--cyan);font-family:var(--mono);max-width:200px;white-space:normal;"><?= h($newScript) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- 10. BAND DECISION CORRELATION -->
  <div class="section">
    <div class="section-title cyan">10 · Schedule Change Impact (7-day before vs after)</div>
    <?php if (empty($band_correlation)): ?>
      <p style="color:var(--text3);font-family:var(--mono);font-size:11px;">No correlation data yet — requires prop_decisions with changed=1 entries and session data before and after each decision.</p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Decision Date</th>
            <th>Partner</th>
            <th>SFI</th>
            <th>Kp</th>
            <th>Season</th>
            <th>Pre Sessions</th>
            <th>Pre Success%</th>
            <th>Pre Avg S/N</th>
            <th>Post Sessions</th>
            <th>Post Success%</th>
            <th>Post Avg S/N</th>
            <th>Delta</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($band_correlation as $r):
            $delta = (float)$r['success_delta'];
            $deltaClass = $delta > 5 ? 'pct-good' : ($delta < -5 ? 'pct-poor' : '');
            $deltaStr = ($delta > 0 ? '+' : '') . number_format($delta, 1) . '%';
          ?>
          <tr>
            <td style="color:var(--text3)"><?= h(substr($r['decision_date'], 0, 10)) ?></td>
            <td class="hub-id"><?= h($r['partner']) ?></td>
            <td><?= $r['sfi'] ?? '–' ?></td>
            <td><?= $r['kp'] ?? '–' ?></td>
            <td><?= h($r['season']) ?></td>
            <td><?= number_format($r['pre_sessions']) ?></td>
            <td class="<?= pctClass($r['pre_success_pct']) ?>"><?= $r['pre_success_pct'] ?>%</td>
            <td class="<?= snrClass($r['pre_avg_snr']) ?>"><?= $r['pre_avg_snr'] ?> dB</td>
            <td><?= number_format($r['post_sessions']) ?></td>
            <td class="<?= pctClass($r['post_success_pct']) ?>"><?= $r['post_success_pct'] ?>%</td>
            <td class="<?= snrClass($r['post_avg_snr']) ?>"><?= $r['post_avg_snr'] ?> dB</td>
            <td class="<?= $deltaClass ?>" style="font-weight:700;"><?= $deltaStr ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="font-size:10px;color:var(--text3);font-family:var(--mono);margin-top:8px;">
      Delta = post-change success rate minus pre-change success rate. Positive = improvement. Requires 7 days of data both before and after each decision.
    </p>
    <?php endif; ?>
  </div>

  <!-- FOOTER -->
  <div class="report-footer">
    <span>TPRFN Hub Health Report · K1AJD ARSSYSTEM · <?= $generated ?></span>
    <span>CONFIDENTIAL — K1AJD Station Use Only</span>
  </div>

</div><!-- /report -->

</body>
</html>
