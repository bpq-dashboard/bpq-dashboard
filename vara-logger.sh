#!/bin/bash
# vara-logger.sh - VARA HF Log Aggregator
# Part of BPQ BBS Dashboard Suite v1.0.4
#
# Continuously captures VARA HF logs, survives syslog rotation
# Prevents duplicates efficiently by checking last 100 lines only
#
# Installation:
#   1. Run setup.sh to configure paths automatically, OR
#   2. Manually edit BPQ_LOG_DIR and OUTPUT_FILE below
#   3. chmod +x vara-logger.sh
#   4. Copy vara-logger.service to /etc/systemd/system/
#   5. sudo systemctl enable vara-logger
#   6. sudo systemctl start vara-logger
#
# Check status: sudo systemctl status vara-logger
# View output:  tail -f /var/www/html/bpq/logs/yourcall.vara

# === CONFIGURATION - Edit these values for your station ===
BPQ_LOG_DIR="/opt/oarc/bpq"
OUTPUT_FILE="/var/www/html/bpq/logs/yourcall.vara"
# ==========================================================

LOGFILE="/var/log/syslog"
FILTER="VARAHF"

# Ensure output directory exists
mkdir -p "$(dirname "$OUTPUT_FILE")"

# Touch file if it doesn't exist
touch "$OUTPUT_FILE"

# tail -F follows through rotation
# -n 0 means start at END of file, only capture NEW lines
tail -F -n 0 "$LOGFILE" 2>/dev/null | grep --line-buffered "$FILTER" | while read -r line; do
    # Only check last 100 lines for duplicates (efficient, handles restarts)
    if ! tail -100 "$OUTPUT_FILE" 2>/dev/null | grep -Fxq "$line"; then
        echo "$line" >> "$OUTPUT_FILE"
    fi
done
