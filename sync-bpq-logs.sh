#!/bin/bash
# ============================================
# LinBPQ Log Sync Script
# Copies LinBPQ logs to web server for dashboard
#
# Usage:
#   1. Edit BPQ_DIR below to your LinBPQ directory
#   2. chmod +x sync-bpq-logs.sh
#   3. Add to crontab to run every 5 minutes:
#      */5 * * * * /home/pi/scripts/sync-bpq-logs.sh >> /home/pi/scripts/sync.log 2>&1
# ============================================

# ============================================
# CONFIGURATION - Edit these paths
# ============================================
BPQ_DIR="/home/pi/linbpq"
WEB_LOGS="/var/www/html/bpq/logs"
# ============================================

# Check if LinBPQ directory exists
if [ ! -d "$BPQ_DIR" ]; then
    echo "ERROR: LinBPQ directory not found: $BPQ_DIR"
    echo "       Edit this script and set BPQ_DIR to your LinBPQ location"
    exit 1
fi

# Create web logs directory if needed
mkdir -p "$WEB_LOGS" 2>/dev/null

echo "$(date): Syncing LinBPQ logs..."

# Copy BBS logs (for Traffic Report, Email Monitor, System Logs)
cp -u ${BPQ_DIR}/log_*_BBS.txt "$WEB_LOGS/" 2>/dev/null
BBS_COUNT=$(ls -1 ${WEB_LOGS}/log_*_BBS.txt 2>/dev/null | wc -l)

# Copy TCP logs (for System Logs connection tracking)
cp -u ${BPQ_DIR}/log_*_TCP.txt "$WEB_LOGS/" 2>/dev/null

# Copy CMS Access logs (for Winlink/RMS Gateway tracking)
cp -u ${BPQ_DIR}/CMSAccess*.log "$WEB_LOGS/" 2>/dev/null

# Copy VARA logs (if they exist locally)
cp -u ${BPQ_DIR}/*.vara "$WEB_LOGS/" 2>/dev/null

# Connect logs (for Connect Log page)
cp -u ${BPQ_DIR}/ConnectLog*.log "$WEB_LOGS/" 2>/dev/null

# Copy MHeard stations file (for RF Connections)
cp -u ${BPQ_DIR}/MHSave.txt "$WEB_LOGS/" 2>/dev/null

# Copy Known stations routing table (for RF Connections)
cp -u ${BPQ_DIR}/RTKnown.txt "$WEB_LOGS/" 2>/dev/null

# Copy node RTT data (optional - for System Logs)
cp -u ${BPQ_DIR}/nodesrtt.txt "$WEB_LOGS/" 2>/dev/null

# Set permissions so web server can read
chown -R www-data:www-data "$WEB_LOGS/" 2>/dev/null || true
chmod -R 644 "$WEB_LOGS/"* 2>/dev/null || true

echo "$(date): Synced $BBS_COUNT BBS log files to $WEB_LOGS"
