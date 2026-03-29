#!/bin/bash
#
# ============================================================================
# BPQ Dashboard - VARA Log Fetch Script (Linux)
# ============================================================================
#
# This script downloads your VARA log file from a remote web server and
# APPENDS new entries to your local log file (preserving historical data).
#
# Run via cron every 15 minutes:
#   */15 * * * * /usr/local/bin/fetch-vara.sh >/dev/null 2>&1
#
# Configuration: Edit the variables below or use /etc/bpq-dashboard.conf
#
# ============================================================================

# Load configuration if exists
if [ -f /etc/bpq-dashboard.conf ]; then
    source /etc/bpq-dashboard.conf
else
    # Default configuration - EDIT THESE VALUES
    VARA_URL="http://example.com/logs/remotecall.vara"
    VARA_FILE="localcall.vara"
    OUTPUT_DIR="/var/www/html/bpq/logs"
fi

# Create output directory if needed
mkdir -p "$OUTPUT_DIR"

LOCAL_FILE="$OUTPUT_DIR/$VARA_FILE"
TEMP_FILE=$(mktemp)
TRACKING_FILE="$OUTPUT_DIR/.vara_last_lines"

# Download the remote VARA log to temp file
wget -q -O "$TEMP_FILE" "$VARA_URL"

if [ $? -ne 0 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Failed to download from $VARA_URL" >&2
    rm -f "$TEMP_FILE"
    exit 1
fi

# If local file doesn't exist, just use the downloaded file
if [ ! -f "$LOCAL_FILE" ]; then
    mv "$TEMP_FILE" "$LOCAL_FILE"
    wc -l < "$LOCAL_FILE" > "$TRACKING_FILE"
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Created $VARA_FILE ($(wc -l < "$LOCAL_FILE") lines)"
    exit 0
fi

# Get line count of downloaded file
REMOTE_LINES=$(wc -l < "$TEMP_FILE")

# Get last known line count (or 0 if tracking file doesn't exist)
if [ -f "$TRACKING_FILE" ]; then
    LAST_LINES=$(cat "$TRACKING_FILE")
else
    LAST_LINES=0
fi

# If remote file has more lines than we last saw, append the new lines
if [ "$REMOTE_LINES" -gt "$LAST_LINES" ]; then
    # Calculate how many new lines to append
    NEW_LINES=$((REMOTE_LINES - LAST_LINES))
    
    # Append only the new lines (tail from line LAST_LINES+1)
    tail -n "$NEW_LINES" "$TEMP_FILE" >> "$LOCAL_FILE"
    
    # Update tracking file
    echo "$REMOTE_LINES" > "$TRACKING_FILE"
    
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Appended $NEW_LINES new lines to $VARA_FILE"
elif [ "$REMOTE_LINES" -lt "$LAST_LINES" ]; then
    # Remote file was rotated/reset - start fresh but keep local history
    # Append entire remote file (it's new data after rotation)
    cat "$TEMP_FILE" >> "$LOCAL_FILE"
    echo "$REMOTE_LINES" > "$TRACKING_FILE"
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Remote log rotated, appended $REMOTE_LINES lines to $VARA_FILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') - No new data in $VARA_FILE"
fi

# Cleanup
rm -f "$TEMP_FILE"
