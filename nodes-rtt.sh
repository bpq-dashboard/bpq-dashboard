#!/bin/bash
#
# nodes-rtt.sh - Automated telnet script to collect BPQ32 Nodes RTT data
# 
# This script connects to the local BPQ32 telnet port, logs in,
# executes the "n t" command to get nodes with RTT times, and
# saves the output to a log file.
#
# Usage: ./nodes-rtt.sh
# Recommended: Run via cron every 5-15 minutes
#
# Requirements: expect (apt install expect)
#

# Configuration
TELNET_HOST="localhost"
TELNET_PORT="8010"
TELNET_USER="TonyD"
TELNET_PASS="Dawgs!958"
OUTPUT_FILE="/var/www/tprfn/logs/nodesrtt.txt"

# Ensure output directory exists
mkdir -p "$(dirname "$OUTPUT_FILE")"

# Check if expect is installed
if ! command -v expect &> /dev/null; then
    echo "Error: 'expect' is not installed. Install with: apt install expect"
    exit 1
fi

# Run expect script to automate telnet session
expect << 'EOF' > "$OUTPUT_FILE"
set timeout 30
log_user 1

# Start telnet connection
spawn telnet localhost 8010

# Wait for login prompt and send username
expect {
    -re {[Cc]allsign:} { send "TonyD\r" }
    -re {[Ll]ogin:} { send "TonyD\r" }
    -re {[Uu]sername:} { send "TonyD\r" }
    timeout { puts "Timeout waiting for login prompt"; exit 1 }
}

# Wait for password prompt and send password
expect {
    -re {[Pp]assword:} { send "Dawgs!958\r" }
    timeout { puts "Timeout waiting for password prompt"; exit 1 }
}

# Wait 5 seconds for login to complete and any welcome messages
sleep 5

# Just send the command - don't wait for prompt (it may have already appeared)
send "n t\r"

# Wait for output to complete (look for prompt or just wait)
set timeout 10
expect {
    -re {\n[A-Z0-9]+-?[0-9]*>} { }
    -re {cmd:} { }
    eof { }
    timeout { }
}

# Small delay to capture any remaining output
sleep 1

# Disconnect cleanly
send "b\r"
expect {
    eof { }
    timeout { }
}
EOF

# Add timestamp to the output file
TIMESTAMP=$(date -u '+%Y-%m-%d %H:%M:%SZ')
sed -i "1i# Nodes RTT Data - Captured: $TIMESTAMP\n" "$OUTPUT_FILE"

# Clean up expect artifacts from output (remove control characters)
sed -i 's/\r//g' "$OUTPUT_FILE"
sed -i '/^spawn telnet/d' "$OUTPUT_FILE"
sed -i '/^Trying/d' "$OUTPUT_FILE"
sed -i '/^Connected to/d' "$OUTPUT_FILE"
sed -i '/^Escape character/d' "$OUTPUT_FILE"

echo "Nodes RTT data saved to: $OUTPUT_FILE"
