#!/bin/bash
#
# BPQ Dashboard Data Archiver
# Version: 1.4.0
#
# Archives BPQ logs and dashboard data:
#   - Weekly: 7-day incremental archives (run every Sunday)
#   - Monthly: Full calendar month archives (auto-triggered first week of month)
#
# Run via cron: 0 2 * * 0 /var/www/html/bpq/scripts/archive-bpq-data.sh
#
# The monthly archive collects ALL log files for every day of the previous
# calendar month — not just a single week's snapshot. This gives you a
# complete month of BBS, VARA, TCP, and CMSAccess logs in one archive.
#
# This script has NO impact on dashboard operation - it only creates copies.
#

set -e

# =============================================================================
# CONFIGURATION
# =============================================================================

# Dashboard installation directory
DASHBOARD_DIR="${DASHBOARD_DIR:-/var/www/html/bpq}"

# Archive storage location
ARCHIVE_DIR="${ARCHIVE_DIR:-$DASHBOARD_DIR/archives}"

# Source directories
LOGS_DIR="$DASHBOARD_DIR/logs"
DATA_DIR="$DASHBOARD_DIR/data"

# Retention settings
RETENTION_WEEKS=12      # Keep 12 weekly archives (3 months)
RETENTION_MONTHS=12     # Keep 12 monthly archives (1 year)

# Archive format: tar.gz or zip
ARCHIVE_FORMAT="tar.gz"

# =============================================================================
# FUNCTIONS
# =============================================================================

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1" >&2
}

get_week_number() {
    date +%V
}

get_year() {
    date +%Y
}

get_date_n_days_ago() {
    local days=$1
    local format=${2:-%Y%m%d}
    date -d "$days days ago" +$format 2>/dev/null || date -v-${days}d +$format
}

file_size_human() {
    local file=$1
    if [ -f "$file" ]; then
        du -h "$file" | cut -f1
    else
        echo "0"
    fi
}

# Collect BPQ log files for a given date into a target directory
# Usage: collect_logs_for_date YYMMDD YYYYMMDD TARGET_DIR
# Prints the number of files collected to stdout
collect_logs_for_date() {
    local date_yy=$1
    local date_yyyy=$2
    local target=$3
    local count=0

    # BBS logs (log_YYMMDD_BBS.txt)
    if [ -f "$LOGS_DIR/log_${date_yy}_BBS.txt" ]; then
        cp "$LOGS_DIR/log_${date_yy}_BBS.txt" "$target/"
        ((count++)) || true
    fi

    # VARA logs (log_YYMMDD_VARA.txt)
    if [ -f "$LOGS_DIR/log_${date_yy}_VARA.txt" ]; then
        cp "$LOGS_DIR/log_${date_yy}_VARA.txt" "$target/"
        ((count++)) || true
    fi

    # TCP logs (log_YYMMDD_TCP.txt)
    if [ -f "$LOGS_DIR/log_${date_yy}_TCP.txt" ]; then
        cp "$LOGS_DIR/log_${date_yy}_TCP.txt" "$target/"
        ((count++)) || true
    fi

    # CMSAccess logs (CMSAccess_YYYYMMDD.log)
    if [ -f "$LOGS_DIR/CMSAccess_${date_yyyy}.log" ]; then
        cp "$LOGS_DIR/CMSAccess_${date_yyyy}.log" "$target/"
        ((count++)) || true
    fi

    # Alternative CMSAccess format (CMSAccess_YYYYMMDD.txt)
    if [ -f "$LOGS_DIR/CMSAccess_${date_yyyy}.txt" ]; then
        cp "$LOGS_DIR/CMSAccess_${date_yyyy}.txt" "$target/"
        ((count++)) || true
    fi

    echo $count
}

# Collect dashboard data files into a target directory
# Usage: collect_data_files TARGET_DIR
# Prints the number of files collected to stdout
collect_data_files() {
    local target=$1
    local count=0

    mkdir -p "$target/messages"

    # Saved messages
    if [ -f "$DATA_DIR/messages/messages.json" ]; then
        cp "$DATA_DIR/messages/messages.json" "$target/messages/"
        ((count++)) || true
    fi

    # Folders
    if [ -f "$DATA_DIR/messages/folders.json" ]; then
        cp "$DATA_DIR/messages/folders.json" "$target/messages/"
        ((count++)) || true
    fi

    # Bulletin addresses
    if [ -f "$DATA_DIR/messages/addresses.json" ]; then
        cp "$DATA_DIR/messages/addresses.json" "$target/messages/"
        ((count++)) || true
    fi

    # Dashboard activity log
    if [ -f "$LOGS_DIR/dashboard.log" ]; then
        cp "$LOGS_DIR/dashboard.log" "$target/"
        ((count++)) || true
    fi

    # Station locations — server-side storage (v1.4.0+)
    if [ -d "$DATA_DIR/stations" ] && [ "$(ls -A "$DATA_DIR/stations" 2>/dev/null)" ]; then
        mkdir -p "$target/stations"
        cp "$DATA_DIR/stations/"*.json "$target/stations/" 2>/dev/null
        ((count++)) || true
    fi

    # Station locations — legacy file (pre-1.4.0)
    if [ -f "$DASHBOARD_DIR/station-locations.json" ]; then
        cp "$DASHBOARD_DIR/station-locations.json" "$target/"
        ((count++)) || true
    fi

    # Config file (backup, exclude sensitive fields)
    if [ -f "$DASHBOARD_DIR/config.php" ]; then
        grep -v "pass\|password" "$DASHBOARD_DIR/config.php" > "$target/config-backup.php" 2>/dev/null || true
    fi

    echo $count
}

# Create a compressed archive from a staging directory
# Usage: create_archive STAGING_DIR OUTPUT_FILE
create_archive() {
    local staging=$1
    local output=$2

    if [ "$ARCHIVE_FORMAT" = "tar.gz" ]; then
        tar -czf "$output" -C "$staging" .
    elif [ "$ARCHIVE_FORMAT" = "zip" ]; then
        (cd "$staging" && zip -rq "$output" .)
    fi
}

# =============================================================================
# MAIN
# =============================================================================

log "=== BPQ Dashboard Data Archiver v1.4.0 ==="
log "Dashboard: $DASHBOARD_DIR"
log "Archive destination: $ARCHIVE_DIR"

# Verify dashboard directory exists
if [ ! -d "$DASHBOARD_DIR" ]; then
    error "Dashboard directory not found: $DASHBOARD_DIR"
    exit 1
fi

# Create archive directories
mkdir -p "$ARCHIVE_DIR/weekly"
mkdir -p "$ARCHIVE_DIR/monthly"
mkdir -p "$ARCHIVE_DIR/current"

# =============================================================================
# WEEKLY ARCHIVE (Last 7 days)
# =============================================================================

YEAR=$(get_year)
WEEK=$(get_week_number)
ARCHIVE_NAME="bpq-data-${YEAR}-W${WEEK}"
ARCHIVE_FILE="$ARCHIVE_DIR/weekly/${ARCHIVE_NAME}.${ARCHIVE_FORMAT}"

# Skip if this week's archive already exists
if [ -f "$ARCHIVE_FILE" ]; then
    log "Weekly archive already exists: $ARCHIVE_FILE"
    log "Skipping weekly. Delete existing archive to regenerate."
else
    END_DATE=$(date +%Y-%m-%d)
    START_DATE=$(get_date_n_days_ago 7 %Y-%m-%d)

    log ""
    log "--- Weekly Archive ---"
    log "Date range: $START_DATE to $END_DATE"
    log "Creating: $ARCHIVE_FILE"

    # Create temporary staging directory
    TEMP_DIR=$(mktemp -d)
    trap "rm -rf $TEMP_DIR" EXIT

    mkdir -p "$TEMP_DIR/logs"
    mkdir -p "$TEMP_DIR/data"

    # Collect log files for 7 days
    log "Collecting BPQ log files..."
    FILE_COUNT=0

    for i in {0..6}; do
        DATE_YY=$(get_date_n_days_ago $i %y%m%d)
        DATE_YYYY=$(get_date_n_days_ago $i %Y%m%d)
        DAY_COUNT=$(collect_logs_for_date "$DATE_YY" "$DATE_YYYY" "$TEMP_DIR/logs")
        FILE_COUNT=$((FILE_COUNT + DAY_COUNT))
    done

    log "Collected $FILE_COUNT log files"

    # Collect dashboard data
    log "Collecting dashboard data files..."
    DATA_COUNT=$(collect_data_files "$TEMP_DIR/data")
    log "Collected $DATA_COUNT data files"

    # Create manifest
    cat > "$TEMP_DIR/MANIFEST.txt" << EOF
BPQ Dashboard Weekly Data Archive
==================================
Archive Name: $ARCHIVE_NAME
Created: $(date -Iseconds)
Period: $START_DATE to $END_DATE
Week: $YEAR-W$WEEK
Hostname: $(hostname)
Dashboard Version: 1.4.0

Archive Contents
----------------
Log files: $FILE_COUNT
Data files: $DATA_COUNT
Total files: $((FILE_COUNT + DATA_COUNT))

File Listing
------------
$(cd "$TEMP_DIR" && find . -type f | sort)

Sizes
-----
$(cd "$TEMP_DIR" && du -sh logs/ data/ 2>/dev/null || echo "N/A")

Notes
-----
- This archive contains a 7-day snapshot of BPQ logs and dashboard data
- To restore saved messages: copy data/messages/*.json to dashboard data/messages/
- Log files are for historical reference only
- Dashboard password is NOT included in this archive
EOF

    # Create archive
    log "Creating weekly compressed archive..."
    create_archive "$TEMP_DIR" "$ARCHIVE_FILE"

    # Copy as "latest"
    cp "$ARCHIVE_FILE" "$ARCHIVE_DIR/current/bpq-data-latest.${ARCHIVE_FORMAT}"

    ARCHIVE_SIZE=$(file_size_human "$ARCHIVE_FILE")
    log "Weekly archive created: $ARCHIVE_FILE ($ARCHIVE_SIZE)"

    # Cleanup temp
    rm -rf "$TEMP_DIR"
    trap - EXIT
fi

# =============================================================================
# MONTHLY ARCHIVE (Full calendar month)
#
# Triggered during the first 7 days of each month.
# Collects ALL log files for every day of the previous calendar month.
# Example: Running on Feb 3 creates an archive for all of January (days 1-31).
# =============================================================================

DAY_OF_MONTH=$(date +%d)
if [ "$DAY_OF_MONTH" -le 7 ]; then
    # Determine previous month and year
    PREV_MONTH_YYYY_MM=$(date -d "last month" +%Y-%m 2>/dev/null || date -v-1m +%Y-%m)
    PREV_YEAR=$(echo "$PREV_MONTH_YYYY_MM" | cut -d- -f1)
    PREV_MONTH_NUM=$(echo "$PREV_MONTH_YYYY_MM" | cut -d- -f2)
    MONTHLY_FILE="$ARCHIVE_DIR/monthly/bpq-data-${PREV_MONTH_YYYY_MM}.${ARCHIVE_FORMAT}"

    if [ -f "$MONTHLY_FILE" ]; then
        log ""
        log "Monthly archive already exists: $MONTHLY_FILE"
    else
        log ""
        log "--- Monthly Archive: $PREV_MONTH_YYYY_MM ---"

        # Calculate number of days in the previous month
        # Method: get the last day of prev month by going to 1st of current month minus 1 day
        DAYS_IN_MONTH=$(date -d "${PREV_YEAR}-${PREV_MONTH_NUM}-01 +1 month -1 day" +%d 2>/dev/null || \
                        date -j -f "%Y-%m-%d" "${PREV_YEAR}-${PREV_MONTH_NUM}-28" +%d)

        # Friendly month name for the manifest
        MONTH_NAME=$(date -d "${PREV_YEAR}-${PREV_MONTH_NUM}-01" +"%B %Y" 2>/dev/null || echo "$PREV_MONTH_YYYY_MM")

        log "Month: $MONTH_NAME ($DAYS_IN_MONTH days)"
        log "Collecting log files for every day of the month..."

        # Create staging directory
        MONTHLY_TEMP=$(mktemp -d)
        trap "rm -rf $MONTHLY_TEMP" EXIT

        mkdir -p "$MONTHLY_TEMP/logs"
        mkdir -p "$MONTHLY_TEMP/data"

        MONTHLY_LOG_COUNT=0
        DAYS_WITH_LOGS=0

        # Iterate through every day of the previous month
        for day_num in $(seq 1 "$DAYS_IN_MONTH"); do
            DAY_PAD=$(printf "%02d" "$day_num")

            # BPQ log filename format: YYMMDD
            DATE_YY="${PREV_YEAR:2:2}${PREV_MONTH_NUM}${DAY_PAD}"
            # CMSAccess filename format: YYYYMMDD
            DATE_YYYY="${PREV_YEAR}${PREV_MONTH_NUM}${DAY_PAD}"

            DAY_COUNT=$(collect_logs_for_date "$DATE_YY" "$DATE_YYYY" "$MONTHLY_TEMP/logs")
            MONTHLY_LOG_COUNT=$((MONTHLY_LOG_COUNT + DAY_COUNT))

            if [ "$DAY_COUNT" -gt 0 ]; then
                ((DAYS_WITH_LOGS++)) || true
            fi
        done

        log "Collected $MONTHLY_LOG_COUNT log files across $DAYS_WITH_LOGS days"

        # Collect dashboard data snapshot
        log "Collecting dashboard data snapshot..."
        MONTHLY_DATA_COUNT=$(collect_data_files "$MONTHLY_TEMP/data")
        log "Collected $MONTHLY_DATA_COUNT data files"

        # Create monthly manifest
        cat > "$MONTHLY_TEMP/MANIFEST.txt" << EOF
BPQ Dashboard Monthly Data Archive
====================================
Archive Name: bpq-data-${PREV_MONTH_YYYY_MM}
Created: $(date -Iseconds)
Period: ${PREV_YEAR}-${PREV_MONTH_NUM}-01 to ${PREV_YEAR}-${PREV_MONTH_NUM}-${DAYS_IN_MONTH}
Month: $MONTH_NAME
Days in month: $DAYS_IN_MONTH
Days with log files: $DAYS_WITH_LOGS
Hostname: $(hostname)
Dashboard Version: 1.4.0

Archive Contents
----------------
Log files: $MONTHLY_LOG_COUNT (full calendar month, all log types)
Data files: $MONTHLY_DATA_COUNT (snapshot at archive time)
Total files: $((MONTHLY_LOG_COUNT + MONTHLY_DATA_COUNT))

File Listing
------------
$(cd "$MONTHLY_TEMP" && find . -type f | sort)

Sizes
-----
$(cd "$MONTHLY_TEMP" && du -sh logs/ data/ 2>/dev/null || echo "N/A")

Notes
-----
- FULL MONTH archive: contains every BBS/VARA/TCP/CMSAccess log for $MONTH_NAME
- Weekly archives overlap with this data; monthly is the comprehensive record
- To restore saved messages: copy data/messages/*.json to dashboard data/messages/
- Dashboard password is NOT included in this archive
EOF

        if [ "$MONTHLY_LOG_COUNT" -gt 0 ] || [ "$MONTHLY_DATA_COUNT" -gt 0 ]; then
            log "Creating monthly compressed archive..."
            create_archive "$MONTHLY_TEMP" "$MONTHLY_FILE"
            MONTHLY_SIZE=$(file_size_human "$MONTHLY_FILE")
            log "Monthly archive created: $MONTHLY_FILE ($MONTHLY_SIZE)"
        else
            log "WARNING: No files found for $MONTH_NAME - skipping monthly archive"
            log "  (This may mean BPQ logs are stored elsewhere or the month had no activity)"
        fi

        # Cleanup
        rm -rf "$MONTHLY_TEMP"
        trap - EXIT
    fi
fi

# =============================================================================
# CLEANUP OLD ARCHIVES
# =============================================================================

log ""
log "Cleaning old archives..."

# Weekly cleanup (keep last N)
WEEKLY_DELETED=0
for old_archive in $(ls -t "$ARCHIVE_DIR/weekly/"*.${ARCHIVE_FORMAT} 2>/dev/null | tail -n +$((RETENTION_WEEKS + 1))); do
    rm -f "$old_archive"
    ((WEEKLY_DELETED++)) || true
done

if [ $WEEKLY_DELETED -gt 0 ]; then
    log "Deleted $WEEKLY_DELETED old weekly archives"
fi

# Monthly cleanup (keep last N)
MONTHLY_DELETED=0
for old_archive in $(ls -t "$ARCHIVE_DIR/monthly/"*.${ARCHIVE_FORMAT} 2>/dev/null | tail -n +$((RETENTION_MONTHS + 1))); do
    rm -f "$old_archive"
    ((MONTHLY_DELETED++)) || true
done

if [ $MONTHLY_DELETED -gt 0 ]; then
    log "Deleted $MONTHLY_DELETED old monthly archives"
fi

# =============================================================================
# SUMMARY
# =============================================================================

WEEKLY_COUNT=$(ls "$ARCHIVE_DIR/weekly/"*.${ARCHIVE_FORMAT} 2>/dev/null | wc -l)
MONTHLY_COUNT=$(ls "$ARCHIVE_DIR/monthly/"*.${ARCHIVE_FORMAT} 2>/dev/null | wc -l)
TOTAL_SIZE=$(du -sh "$ARCHIVE_DIR" 2>/dev/null | cut -f1)

log ""
log "=== Archive Summary ==="
log "Weekly archives:  $WEEKLY_COUNT (retention: $RETENTION_WEEKS)"
log "Monthly archives: $MONTHLY_COUNT (retention: $RETENTION_MONTHS)"
log "Total archive storage: $TOTAL_SIZE"
log "=== Archive Complete ==="
