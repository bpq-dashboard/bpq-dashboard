# Auto Monitoring of Failed RF Connections

## Overview
Develop a Linux script that monitors RF connections and automatically stops repeated failed attempts to contact a callsign on a particular frequency, then restarts BPQ.

## Trigger Condition
- More than 2 failed attempts to contact the same callsign on the same frequency

## Information Needed

### 1. Log File
- Which log file shows failed RF connection attempts?
- Is it the DataLog file or a different BPQ log?

### 2. Log Format
- What does a failed attempt look like in the log?
- Need example lines showing:
  - Failed connection attempts
  - Callsign involved
  - Frequency/band used

### 3. Configuration File
- What BPQ file needs to be edited to stop the attempts?
- Options might include:
  - bpq32.cfg
  - Forwarding schedule file
  - Other config file

### 4. Edit Method
- How should the script stop the attempts?
  - Comment out the line?
  - Delete the line?
  - Change a parameter (e.g., enabled=0)?

### 5. BPQ Restart Command
- How is BPQ restarted on the Linux system?
  - systemctl restart bpq32?
  - Other command?

### 6. Recovery Policy
- Should the block be permanent (manual re-enable)?
- Or auto re-enable after a time period (e.g., 24 hours)?

## Script Design (Preliminary)

```bash
#!/bin/bash
# auto-rf-monitor.sh
# Monitor BPQ RF connections and disable repeated failures

# Configuration
LOG_FILE="/path/to/bpq/logfile"
CONFIG_FILE="/path/to/bpq/config"
MAX_FAILURES=2
CHECK_INTERVAL=300  # seconds

# Track failures: associative array [callsign:frequency] = count
declare -A failure_counts

# Main monitoring loop
while true; do
    # Parse log for recent failures
    # Count failures per callsign:frequency pair
    # If count > MAX_FAILURES:
    #   - Edit CONFIG_FILE to disable that entry
    #   - Restart BPQ
    #   - Log the action
    sleep $CHECK_INTERVAL
done
```

## Status
**PENDING** - Awaiting information from user:
- [ ] Log file location and format
- [ ] Sample log lines showing failures
- [ ] Config file to edit
- [ ] Edit method preference
- [ ] BPQ restart command
- [ ] Recovery policy

## Date
February 5, 2026

## Related
- BPQ Dashboard v1.4.1
- RF Connections monitoring
