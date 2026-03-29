#!/bin/bash
# ============================================
# VARA Log Fetcher for BPQ Dashboard
# Fetches your VARA log from TPRFN server
# and appends it to your local copy
#
# Usage: 
#   1. Edit CALLSIGN below to your callsign
#   2. chmod +x fetch-vara-tprfn.sh
#   3. Add to crontab to run every 15 minutes:
#      */15 * * * * /home/pi/scripts/fetch-vara-tprfn.sh >> /home/pi/scripts/vara-fetch.log 2>&1
# ============================================

# ============================================
# CONFIGURATION - Edit these settings
# ============================================
CALLSIGN="YOURCALL"
LOG_DIR="/var/www/html/bpq/logs"
# ============================================

# Build filenames
VARA_URL="https://tprfn.k1ajd.net/${CALLSIGN}.vara"
VARA_FILE="${LOG_DIR}/${CALLSIGN}.vara"
TEMP_FILE="/tmp/${CALLSIGN}_new.vara"

# Create log directory if needed
mkdir -p "$LOG_DIR" 2>/dev/null

echo "$(date): Fetching VARA log for ${CALLSIGN} from TPRFN server..."

# Download the VARA log to temp file
wget -q -O "$TEMP_FILE" "$VARA_URL"

# Check if download was successful
if [ $? -ne 0 ]; then
    echo "ERROR: Failed to download VARA log from $VARA_URL"
    echo "       Make sure wget is installed: sudo apt install wget"
    exit 1
fi

# Check if file has content
if [ ! -s "$TEMP_FILE" ]; then
    echo "WARNING: Downloaded file is empty."
    echo "         Check if ${CALLSIGN}.vara exists on server:"
    echo "         https://tprfn.k1ajd.net/${CALLSIGN}.vara"
    rm -f "$TEMP_FILE"
    exit 1
fi

# Count lines in downloaded file
NEW_LINES=$(wc -l < "$TEMP_FILE")

# Append new data to existing file (or create if doesn't exist)
if [ -f "$VARA_FILE" ]; then
    # Get current line count
    OLD_LINES=$(wc -l < "$VARA_FILE")
    
    # Append new content
    cat "$TEMP_FILE" >> "$VARA_FILE"
    
    echo "Appended $NEW_LINES lines to $VARA_FILE (was $OLD_LINES lines)"
else
    # First run - copy the file
    cp "$TEMP_FILE" "$VARA_FILE"
    
    # Set ownership for web server
    chown www-data:www-data "$VARA_FILE" 2>/dev/null || true
    
    echo "Created $VARA_FILE with $NEW_LINES lines"
fi

# Cleanup temp file
rm -f "$TEMP_FILE"

echo "$(date): SUCCESS - VARA log updated"
