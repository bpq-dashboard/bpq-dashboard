#!/bin/bash
# wavenode-archive.sh — daily archive of WaveNode log
#
# Runs once per day at 00:05 UTC via /etc/cron.d/wavenode-archive.
# Extracts yesterday's entries from the live wavenode-log.json (which holds
# a rolling 7-day window) and writes them to a gzipped per-day archive
# file. Live file is NOT modified — the reader on Windows owns it and
# prunes its own retention.
#
# Output: /var/www/bpq-dashboard/wavenode-logs/archive/wavenode-log-YYMMDD.json.gz
# Retention: 90 days (configurable below)
#
# Idempotent: re-running on the same day overwrites the day's archive.
# Safe: works on a copy, never touches the live file.
#
# Created 2026-04-25 as part of WaveNode integration finalization.

set -uo pipefail

# ── Configuration ────────────────────────────────────────────────────────────
LIVE_FILE="/var/www/bpq-dashboard/wavenode-logs/wavenode-log.json"
ARCHIVE_DIR="/var/www/bpq-dashboard/wavenode-logs/archive"
RETENTION_DAYS=90
LOG_TAG="[wavenode-archive]"

# Yesterday's date in UTC (since we run at 00:05 UTC, "yesterday" is the day
# just ended and is what we want to archive).
YESTERDAY_DATE="$(date -u -d 'yesterday' +%Y-%m-%d)"
YESTERDAY_TAG="$(date -u -d 'yesterday' +%y%m%d)"
ARCHIVE_FILE="${ARCHIVE_DIR}/wavenode-log-${YESTERDAY_TAG}.json"

ts() { date -u '+%Y-%m-%d %H:%M:%S UTC'; }
log() { echo "$(ts) ${LOG_TAG} $*"; }

# ── Sanity checks ────────────────────────────────────────────────────────────
mkdir -p "${ARCHIVE_DIR}"

if [ ! -f "${LIVE_FILE}" ]; then
    log "ERROR: live file ${LIVE_FILE} not found — nothing to archive"
    exit 1
fi

LIVE_SIZE=$(stat -c %s "${LIVE_FILE}")
if [ "${LIVE_SIZE}" -lt 100 ]; then
    log "WARN: live file is suspiciously small (${LIVE_SIZE} bytes) — skipping archive"
    exit 1
fi

log "Archiving entries from ${YESTERDAY_DATE} to ${ARCHIVE_FILE}.gz"

# ── Extract yesterday's entries ──────────────────────────────────────────────
# Use Python instead of jq for portability and JSON safety. The live file is
# a JSON array of objects with 'ts' field in ISO8601 UTC format.
python3 - "${LIVE_FILE}" "${ARCHIVE_FILE}" "${YESTERDAY_DATE}" << 'PYEOF'
import json, sys, os
src, dst, day = sys.argv[1], sys.argv[2], sys.argv[3]
prefix = day  # e.g. "2026-04-24" matches "2026-04-24T..."
try:
    with open(src) as f:
        data = json.load(f)
except Exception as e:
    print(f"ERROR loading source: {e}", file=sys.stderr)
    sys.exit(2)
if not isinstance(data, list):
    print("ERROR: source is not a JSON array", file=sys.stderr)
    sys.exit(2)
filtered = [e for e in data if isinstance(e, dict) and e.get('ts', '').startswith(prefix)]
# Atomic write via temp file
tmp = dst + '.tmp'
with open(tmp, 'w') as f:
    json.dump(filtered, f, separators=(',', ':'))
os.replace(tmp, dst)
print(f"Wrote {len(filtered)} entries to {dst}")
PYEOF

ARCHIVE_RC=$?
if [ "${ARCHIVE_RC}" -ne 0 ]; then
    log "ERROR: extraction failed with exit code ${ARCHIVE_RC}"
    exit "${ARCHIVE_RC}"
fi

# ── Verify entry count, then compress ────────────────────────────────────────
if [ ! -f "${ARCHIVE_FILE}" ]; then
    log "ERROR: ${ARCHIVE_FILE} was not created"
    exit 3
fi

ENTRY_COUNT=$(python3 -c "import json,sys; print(len(json.load(open('${ARCHIVE_FILE}'))))" 2>/dev/null || echo "?")
RAW_SIZE=$(stat -c %s "${ARCHIVE_FILE}")

if [ "${ENTRY_COUNT}" = "0" ]; then
    log "WARN: no entries found for ${YESTERDAY_DATE} (file may be empty or live data missed yesterday)"
    # Keep the empty archive file so we have a record that the day was processed
fi

# Compress with gzip (replaces raw .json with .json.gz)
gzip -f "${ARCHIVE_FILE}"
GZ_FILE="${ARCHIVE_FILE}.gz"
GZ_SIZE=$(stat -c %s "${GZ_FILE}" 2>/dev/null || echo "?")

log "Archive complete: ${ENTRY_COUNT} entries, raw=${RAW_SIZE}B, compressed=${GZ_SIZE}B"

# ── Prune archives older than RETENTION_DAYS ─────────────────────────────────
DELETED=$(find "${ARCHIVE_DIR}" -maxdepth 1 -name 'wavenode-log-*.json.gz' -type f \
              -mtime +"${RETENTION_DAYS}" -print -delete 2>/dev/null | wc -l)
if [ "${DELETED}" -gt 0 ]; then
    log "Pruned ${DELETED} archive file(s) older than ${RETENTION_DAYS} days"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
TOTAL_FILES=$(find "${ARCHIVE_DIR}" -maxdepth 1 -name 'wavenode-log-*.json.gz' -type f | wc -l)
TOTAL_SIZE=$(du -sb "${ARCHIVE_DIR}" 2>/dev/null | awk '{print $1}')
log "Archive dir: ${TOTAL_FILES} files, ${TOTAL_SIZE} bytes total"

exit 0
