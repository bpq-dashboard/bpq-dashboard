# BPQ Dashboard Data Files Analysis & Archival System

## Overview

The BPQ Dashboard archival system creates two types of archives:

- **Weekly archives** — 7-day rolling snapshots of BPQ logs and dashboard data
- **Monthly archives** — Full calendar month collections containing every log file for all 28–31 days

Both archive types are created by the same script, scheduled to run weekly. Monthly archives are automatically triggered during the first week of each new month.

## Data Files Inventory

### 1. BPQ Log Files (Read-Only - Source Data)

| File Pattern | Location | Source | Size/Day | Dashboard Usage |
|--------------|----------|--------|----------|-----------------|
| `log_YYMMDD_BBS.txt` | `./logs/` | BPQ32 | 50KB-500KB | System Logs, RF Connections, Traffic |
| `log_YYMMDD_VARA.txt` | `./logs/` | BPQ32/VARA | 10KB-100KB | RF Connections |
| `log_YYMMDD_TCP.txt` | `./logs/` | BPQ32 | 20KB-200KB | Email Monitor |
| `CMSAccess_YYYYMMDD.log` | `./logs/` | Winlink | 5KB-50KB | System Logs (RMS status) |

**Notes:**
- These are **source files** from BPQ32, not created by dashboard
- Dashboard reads but never writes to these
- BPQ32 creates new file each day automatically
- Retention depends on BPQ32 settings

### 2. Dashboard-Generated Data Files

| File | Location | Purpose | Update Frequency | Size |
|------|----------|---------|------------------|------|
| `messages.json` | `./data/messages/` | Saved BBS messages | On user action | 0-5MB |
| `folders.json` | `./data/messages/` | Custom folder list | On user action | <1KB |
| `addresses.json` | `./data/messages/` | Saved bulletin addresses | On user action | <1KB |
| `nodesrtt.txt` | `./logs/` | Node RTT measurements | Cron (5 min) | <10KB |
| `nws-alerts.json` | `./wx/` | Cached weather alerts | Cron (5 min) | 10KB-500KB |
| `dashboard.log` | `./logs/` | Dashboard activity log | Continuous | 0-5MB |

### 3. External API Data (Fetched, Not Stored)

| Source | URL | Dashboard Usage |
|--------|-----|-----------------|
| NOAA K-Index | `services.swpc.noaa.gov/.../noaa-planetary-k-index.json` | RF Connections (3-day K-index) |
| NOAA DGD | `services.swpc.noaa.gov/text/daily-geomagnetic-indices.txt` | RF Connections (30-day A-index) |
| HamQSL Solar | `hamqsl.com/solarxml.php` | RF Connections (propagation) |
| NWS Alerts | `api.weather.gov/alerts/active` | Weather Dashboard |
| Callook.info | `callook.info/CALL/json` | Station lookup |

---

## Current Data Retention Status

| Data Type | Current Retention | Risk |
|-----------|-------------------|------|
| BPQ Logs | Depends on BPQ32 config | May grow indefinitely |
| Saved Messages | Indefinite (10MB cap) | Low - auto-pruned at 1000 msgs |
| Dashboard Log | 5MB rotating | Low - auto-rotates |
| RTT Data | Overwritten each run | None - ephemeral |
| NWS Cache | Overwritten each run | None - ephemeral |

---

## Archival System: Weekly + Full-Month Archives

### Archive Structure

```
/archives/
├── weekly/
│   ├── bpq-data-2026-W05.tar.gz    ← Week 5 of 2026 (7-day snapshot)
│   ├── bpq-data-2026-W04.tar.gz
│   └── bpq-data-2026-W03.tar.gz
├── monthly/
│   ├── bpq-data-2026-01.tar.gz     ← January 2026 (all 31 days)
│   └── bpq-data-2025-12.tar.gz     ← December 2025 (all 31 days)
└── current/
    └── bpq-data-latest.tar.gz      ← Most recent weekly backup
```

### How Monthly Archives Work

Monthly archives are triggered automatically during the first 7 days of each month. When triggered, the script iterates through **every day** of the previous calendar month and collects all matching log files:

| Day | Files Checked |
|-----|--------------|
| Each day of month | `log_YYMMDD_BBS.txt`, `log_YYMMDD_VARA.txt`, `log_YYMMDD_TCP.txt`, `CMSAccess_YYYYMMDD.log` |

For example, running on February 3 would create `bpq-data-2026-01.tar.gz` containing all BBS, VARA, TCP, and CMSAccess logs from January 1–31.

### What to Archive

**Include in 7-Day Archives:**

| Data | Reason | Approx Size/Week |
|------|--------|------------------|
| `log_*_BBS.txt` (7 days) | Connection history, message logs | 350KB - 3.5MB |
| `log_*_VARA.txt` (7 days) | RF connection details | 70KB - 700KB |
| `log_*_TCP.txt` (7 days) | Email session history | 140KB - 1.4MB |
| `CMSAccess_*.log` (7 days) | Winlink RMS history | 35KB - 350KB |
| `messages.json` | Saved messages snapshot | 0 - 5MB |
| `folders.json` | Folder structure | <1KB |
| `addresses.json` | Bulletin addresses | <1KB |
| `station-locations.json` | Station map locations (legacy export) | <1KB |
| `data/stations/*.json` | Server-side station locations & partners | <1KB |
| `dashboard.log` | Activity audit trail | 0 - 5MB |

**Exclude from Archives:**

| Data | Reason |
|------|--------|
| `nodesrtt.txt` | Ephemeral, regenerated every 5 min |
| `nws-alerts.json` | Ephemeral, regenerated every 5 min |
| External API responses | Not stored locally |

**Estimated Archive Sizes:**
- Weekly: 1MB - 15MB (compressed)
- Monthly: 4MB - 60MB (compressed, ~4× weekly due to full month of logs)

---

## Archive Scripts

### Linux: `scripts/archive-bpq-data.sh`

Full script is included in the dashboard at `scripts/archive-bpq-data.sh`.

**Setup:**
```bash
chmod +x /var/www/html/bpq/scripts/archive-bpq-data.sh
```

**Key features:**
- Collects BBS, VARA, TCP, and CMSAccess logs for the past 7 days (weekly)
- On days 1–7 of each month, also collects **every day** of the previous calendar month
- Creates MANIFEST.txt with file counts, date range, and listing
- Uses `collect_logs_for_date()` helper that iterates `seq 1 $DAYS_IN_MONTH`
- Calculates days-in-month via `date -d "${YEAR}-${MONTH}-01 +1 month -1 day"`
- Archives station data (`station-locations.json` and `data/stations/`) and config (passwords excluded)
- Configurable retention: `RETENTION_WEEKS=12`, `RETENTION_MONTHS=12`
- Configurable format: `tar.gz` (default) or `zip`
- Environment overrides: `DASHBOARD_DIR`, `ARCHIVE_DIR`

### Windows: `scripts\archive-bpq-data.bat`

Full script is included in the dashboard at `scripts\archive-bpq-data.bat`.

**Setup:**
Schedule in Task Scheduler to run weekly (e.g., Sunday 2:00 AM).

**Key features:**
- Uses PowerShell for reliable date math and file operations
- Monthly collection iterates `for ($day = 1; $day -le $daysInMonth; $day++)`
- Uses `[DateTime]::DaysInMonth()` for accurate month length
- Checks both `DASHBOARD_DIR\logs` and `BPQ_LOGS_DIR` (configurable, for split installs)
- Creates `.zip` archives via `Compress-Archive`
- Monthly retention cleanup via PowerShell `Sort-Object | Select-Object -First`

---

## Impact on Dashboard

### No Negative Impact

The archival system has **zero impact** on dashboard functionality because:

1. **Archives are copies** - Original files remain untouched
2. **Dashboard reads live data** - Never reads from archives
3. **Non-blocking** - Archive runs on schedule, not during user activity
4. **Separate storage** - Archives in `/archives/`, dashboard uses `/logs/` and `/data/`

### Potential Benefits

| Benefit | Description |
|---------|-------------|
| **Disaster Recovery** | Restore saved messages if database corrupted |
| **Historical Analysis** | Analyze connection patterns over months |
| **Storage Management** | Can delete old source logs after archiving |
| **Compliance** | Audit trail for emergency communications |
| **Migration** | Easy to move to new server |

### Optional Enhancement: Archive Viewer

Could add a dashboard page to browse/restore archives:

```
/bpq/archive-viewer.html
- List available archives
- Preview archive contents
- Restore saved messages from archive
- Download archive files
```

---

## Recommended Cron Schedule

```bash
# Weekly archive - Sunday 2:00 AM
0 2 * * 0 /var/www/html/bpq/scripts/archive-bpq-data.sh >> /var/log/bpq-archive.log 2>&1

# Optional: Daily backup of messages.json only
0 3 * * * cp /var/www/html/bpq/data/messages/messages.json /var/www/html/bpq/archives/current/messages-$(date +\%Y\%m\%d).json
```

---

## Storage Projections

| Time Period | Weekly Archives | Monthly Archives | Total Size |
|-------------|-----------------|------------------|------------|
| 1 month | 4 × 5MB = 20MB | 1 × 20MB = 20MB | ~40MB |
| 3 months | 12 × 5MB = 60MB | 3 × 20MB = 60MB | ~120MB |
| 1 year | 52 × 5MB = 260MB | 12 × 20MB = 240MB | ~500MB |

**With retention policy (12 weeks + 12 months):** ~300MB maximum

Monthly archives are larger than weekly because they contain the full calendar month (~30 days vs 7 days of logs). The exact size depends on station activity.

---

## Implementation Steps

1. **Create archive script** - Copy script above to `/scripts/archive-bpq-data.sh`
2. **Make executable** - `chmod +x /scripts/archive-bpq-data.sh`
3. **Test manually** - `./scripts/archive-bpq-data.sh`
4. **Add to cron** - `crontab -e` and add weekly schedule
5. **Monitor** - Check `/var/log/bpq-archive.log` for issues

---

## Summary

| Question | Answer |
|----------|--------|
| What data to archive? | BPQ logs (BBS, VARA, TCP, CMSAccess) + saved messages + dashboard log + station locations |
| Where to store? | `/archives/weekly/` and `/archives/monthly/` |
| How often? | Weekly (Sunday 2 AM via cron/Task Scheduler) |
| Weekly contains? | Last 7 days of logs + dashboard data snapshot |
| Monthly contains? | **Every day** of the previous calendar month + dashboard data snapshot |
| Monthly trigger? | Automatic — runs during days 1–7 of each month |
| How long to keep? | 12 weeks rolling + 12 months rolling (configurable) |
| Impact on dashboard? | None — archives are independent copies |
| Estimated storage? | Weekly: ~5MB, Monthly: ~20MB, Max with retention: ~300MB |
