#!/bin/bash
# vara-analysis.sh - Analyze VARA HF and BBS logs for frequency recommendations
# Part of BPQ BBS Dashboard Suite v1.0.4
# 
# Usage: ./vara-analysis.sh [vara_log] [bbs_log]
#
# Installation:
#   1. Run setup.sh to configure paths automatically, OR
#   2. Manually edit DEFAULT_VARA_LOG and DEFAULT_LOGS_DIR below
#   3. chmod +x vara-analysis.sh
#   4. Run: ./vara-analysis.sh
#
# Cron: 0 6 * * * /var/www/html/bpq/vara-analysis.sh > /var/www/html/bpq/logs/vara-report.txt

# === CONFIGURATION - Edit these values for your station ===
DEFAULT_VARA_LOG="/var/www/html/bpq/logs/yourcall.vara"
DEFAULT_LOGS_DIR="/var/www/html/bpq/logs"
# ==========================================================

VARA_LOG="${1:-$DEFAULT_VARA_LOG}"
BBS_LOG="${2:-}"

# Auto-detect BBS log if not specified
if [ -z "$BBS_LOG" ]; then
    BBS_LOG=$(ls -t "$DEFAULT_LOGS_DIR"/log_*_BBS.txt 2>/dev/null | head -1)
fi

# Temp directory
TEMP_DIR=$(mktemp -d)
trap "rm -rf $TEMP_DIR" EXIT

echo "════════════════════════════════════════════════════════════════"
echo "  VARA HF Connection Analysis Report"
echo "════════════════════════════════════════════════════════════════"
echo ""
echo "  Generated: $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "  VARA Log:  $VARA_LOG"
echo "  BBS Log:   ${BBS_LOG:-Not found}"
echo ""

# Check files
if [ ! -f "$VARA_LOG" ]; then
    echo "ERROR: VARA log not found: $VARA_LOG"
    exit 1
fi

#───────────────────────────────────────────────────────────────────
# QUICK STATS
#───────────────────────────────────────────────────────────────────
echo "════════════════════════════════════════════════════════════════"
echo "  QUICK STATS"
echo "════════════════════════════════════════════════════════════════"

total_success=$(grep -c "VARAHF.*connected\|VARAHF Connected to" "$VARA_LOG" 2>/dev/null)
total_failed=$(grep "VARAHF Connecting to" "$VARA_LOG" 2>/dev/null | grep -c "15/15")
total=$((total_success + total_failed))
[ "$total" -gt 0 ] && rate=$((total_success * 100 / total)) || rate=0

echo ""
echo "  Successful Connections: $total_success"
echo "  Failed Connections:     $total_failed"
echo "  Overall Success Rate:   ${rate}%"
echo ""

#───────────────────────────────────────────────────────────────────
# OUTGOING ANALYSIS
#───────────────────────────────────────────────────────────────────
echo "════════════════════════════════════════════════════════════════"
echo "  OUTGOING CONNECTION ANALYSIS"
echo "════════════════════════════════════════════════════════════════"

echo ""
echo "── Successful Outgoing ──"
grep "VARAHF Connected to" "$VARA_LOG" 2>/dev/null | \
    awk -F'Connected to ' '{print $2}' | awk '{print $1}' | tr -d '\r' | \
    sort | uniq -c | sort -rn | \
    awk '{printf "  ✓ %-15s %3d successful\n", $2, $1}'

echo ""
echo "── Failed Outgoing (Timeout) ──"
grep "VARAHF Connecting to" "$VARA_LOG" 2>/dev/null | grep "15/15" | \
    sed 's/.*Connecting to //' | sed 's/\.\.\..*//' | tr -d '\r' | \
    sort | uniq -c | sort -rn | \
    awk '{printf "  ✗ %-15s %3d failed\n", $2, $1}'

#───────────────────────────────────────────────────────────────────
# STATION SUCCESS RATES (using temp files for efficiency)
#───────────────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  STATION SUCCESS RATES"
echo "════════════════════════════════════════════════════════════════"
echo ""
printf "  %-15s %8s %8s %8s\n" "Station" "Success" "Failed" "Rate"
echo "  ─────────────────────────────────────────────"

# Pre-calculate successes and failures into temp files
grep "VARAHF Connected to" "$VARA_LOG" 2>/dev/null | \
    awk -F'Connected to ' '{print $2}' | awk '{print $1}' | tr -d '\r' | \
    sort | uniq -c > "$TEMP_DIR/success.txt"

grep "VARAHF Connecting to" "$VARA_LOG" 2>/dev/null | grep "15/15" | \
    sed 's/.*Connecting to //' | sed 's/\.\.\..*//' | tr -d '\r' | \
    sort | uniq -c > "$TEMP_DIR/failed.txt"

# Combine and calculate rates using awk
awk '
    NR==FNR { success[$2] = $1; next }
    { failed[$2] = $1 }
    END {
        for (s in success) all[s] = 1
        for (f in failed) all[f] = 1
        for (station in all) {
            s = (station in success) ? success[station] : 0
            f = (station in failed) ? failed[station] : 0
            total = s + f
            if (total > 0) {
                rate = int(s * 100 / total)
                printf "  %-15s %8d %8d %7d%%\n", station, s, f, rate
            }
        }
    }
' "$TEMP_DIR/success.txt" "$TEMP_DIR/failed.txt" | sort -t'%' -k1 -rn | head -15

#───────────────────────────────────────────────────────────────────
# INCOMING ANALYSIS
#───────────────────────────────────────────────────────────────────
if [ -n "$BBS_LOG" ] && [ -f "$BBS_LOG" ]; then
    echo ""
    echo "════════════════════════════════════════════════════════════════"
    echo "  INCOMING CONNECTION ANALYSIS"
    echo "════════════════════════════════════════════════════════════════"
    
    echo ""
    echo "── Connections by Band ──"
    
    count_80m=$(grep "Incoming Connect" "$BBS_LOG" 2>/dev/null | grep -c "359[0-9]")
    count_40m=$(grep "Incoming Connect" "$BBS_LOG" 2>/dev/null | grep -c "710[0-9]")
    count_30m=$(grep "Incoming Connect" "$BBS_LOG" 2>/dev/null | grep -c "1014[0-9]")
    count_20m=$(grep "Incoming Connect" "$BBS_LOG" 2>/dev/null | grep -c "1410[0-9]")
    
    printf "  80m (3.5 MHz):  %3d connections\n" "$count_80m"
    printf "  40m (7 MHz):    %3d connections\n" "$count_40m"
    printf "  30m (10 MHz):   %3d connections\n" "$count_30m"
    printf "  20m (14 MHz):   %3d connections\n" "$count_20m"
    
    echo ""
    echo "── Top Incoming Stations ──"
    grep "Incoming Connect from" "$BBS_LOG" 2>/dev/null | \
        sed 's/.*from //' | awk '{print $1}' | tr -d '\r' | \
        sort | uniq -c | sort -rn | head -10 | \
        awk '{printf "  %-15s %3d connections\n", $2, $1}'
    
    echo ""
    echo "── Frequency Usage (RADIO commands) ──"
    grep ">.*RADIO" "$BBS_LOG" 2>/dev/null | \
        awk '{gsub(/>/, "", $3); print $3, $5}' | tr -d '\r' | \
        sort | uniq -c | sort -rn | head -10 | \
        awk '{
            band = "?"
            if ($3 ~ /^3\./) band = "80m"
            else if ($3 ~ /^7\./) band = "40m"
            else if ($3 ~ /^10\./) band = "30m"
            else if ($3 ~ /^14\./) band = "20m"
            printf "  %-10s %12s MHz (%s) - %3d attempts\n", $2, $3, band, $1
        }'
fi

#───────────────────────────────────────────────────────────────────
# TIME ANALYSIS
#───────────────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  ACTIVITY BY TIME PERIOD (UTC)"
echo "════════════════════════════════════════════════════════════════"

echo ""
echo "── Connections per Hour ──"
grep "VARAHF.*connected\|VARAHF Connected to" "$VARA_LOG" 2>/dev/null | \
    awk '{print $3}' | cut -d: -f1 | sort | uniq -c | sort -k2n | \
    awk '{
        bar = ""
        for (i = 0; i < $1; i++) bar = bar "█"
        printf "  %02dZ: %3d %s\n", $2, $1, bar
    }'

echo ""
echo "── Summary by 6-Hour Period ──"

count_night=$(grep "VARAHF.*connected\|VARAHF Connected to" "$VARA_LOG" 2>/dev/null | \
    awk '{split($3,t,":"); h=int(t[1]); if(h>=0 && h<6) print}' | wc -l)
count_morning=$(grep "VARAHF.*connected\|VARAHF Connected to" "$VARA_LOG" 2>/dev/null | \
    awk '{split($3,t,":"); h=int(t[1]); if(h>=6 && h<12) print}' | wc -l)
count_afternoon=$(grep "VARAHF.*connected\|VARAHF Connected to" "$VARA_LOG" 2>/dev/null | \
    awk '{split($3,t,":"); h=int(t[1]); if(h>=12 && h<18) print}' | wc -l)
count_evening=$(grep "VARAHF.*connected\|VARAHF Connected to" "$VARA_LOG" 2>/dev/null | \
    awk '{split($3,t,":"); h=int(t[1]); if(h>=18 && h<24) print}' | wc -l)

for period in "00-06:Night:$count_night" "06-12:Morning:$count_morning" "12-18:Afternoon:$count_afternoon" "18-24:Evening:$count_evening"; do
    range=$(echo "$period" | cut -d: -f1)
    name=$(echo "$period" | cut -d: -f2)
    count=$(echo "$period" | cut -d: -f3)
    
    if [ "$count" -gt 20 ]; then status="★★★ Excellent"
    elif [ "$count" -gt 10 ]; then status="★★  Good"
    elif [ "$count" -gt 5 ]; then status="★   Fair"
    else status="    Low"
    fi
    
    printf "  %sZ (%s): %3d connections - %s\n" "$range" "$name" "$count" "$status"
done

#───────────────────────────────────────────────────────────────────
# MESSAGE TRACKING
#───────────────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  📨 MESSAGE TRACKING (>400 bytes = message)"
echo "════════════════════════════════════════════════════════════════"

echo ""
echo "── HF Sessions with Message Data ──"
echo ""
printf "  %-10s %-15s %8s %8s %s\n" "Time" "Station" "TX" "RX" "Direction"
echo "  ────────── ─────────────── ──────── ──────── ─────────────"

# Parse VARA log for sessions with >400 bytes using awk
awk '
/ connected / && !/Disconnected/ {
    n = split($0, a, "VARAHF ")
    if (n > 1) { split(a[2], b, " "); station = b[1] }
}
/Connected to / {
    n = split($0, a, "Connected to ")
    if (n > 1) { split(a[2], b, " "); station = b[1] }
}
/VARAHF Disconnected/ {
    tx = 0; rx = 0
    n = split($0, parts, "TX: ")
    if (n > 1) { split(parts[2], txparts, " "); tx = txparts[1] + 0 }
    n = split($0, parts, "RX: ")
    if (n > 1) { split(parts[2], rxparts, " "); rx = rxparts[1] + 0 }
    time = $3
    if (tx > 400 || rx > 400) {
        dir = (tx > 400 && rx > 400) ? "↔ BOTH" : (tx > 400 ? "→ SENT" : "← RECV")
        printf "  %-10s %-15s %8d %8d %s\n", time, station, tx, rx, dir
    }
    station = ""
}
' "$VARA_LOG" | tr -d '\r'

# Message summary by station using awk
echo ""
echo "── Messages per Station (HF) ──"
echo ""
printf "  %-15s %8s %8s %10s %10s\n" "Station" "Msgs↑" "Msgs↓" "TX Bytes" "RX Bytes"
echo "  ─────────────── ──────── ──────── ────────── ──────────"

awk '
/ connected / && !/Disconnected/ {
    n = split($0, a, "VARAHF ")
    if (n > 1) { split(a[2], b, " "); station = b[1] }
}
/Connected to / {
    n = split($0, a, "Connected to ")
    if (n > 1) { split(a[2], b, " "); station = b[1] }
}
/VARAHF Disconnected/ {
    tx = 0; rx = 0
    n = split($0, parts, "TX: ")
    if (n > 1) { split(parts[2], txparts, " "); tx = txparts[1] + 0 }
    n = split($0, parts, "RX: ")
    if (n > 1) { split(parts[2], rxparts, " "); rx = rxparts[1] + 0 }
    if (station != "" && station != "Disconnected") {
        tx_total[station] += tx
        rx_total[station] += rx
        if (tx > 400) msg_sent[station]++
        if (rx > 400) msg_recv[station]++
    }
    station = ""
}
END {
    for (s in tx_total) {
        sent = msg_sent[s] + 0
        recv = msg_recv[s] + 0
        if (sent > 0 || recv > 0) {
            printf "  %-15s %8d %8d %10d %10d\n", s, sent, recv, tx_total[s], rx_total[s]
        }
    }
}
' "$VARA_LOG" | tr -d '\r' | sort -k2 -rn

# BBS message stats if available
if [ -n "$BBS_LOG" ] && [ -f "$BBS_LOG" ]; then
    echo ""
    echo "── BBS Message Activity ──"
    echo ""
    echo "  Messages RECEIVED (Uncompressing):"
    grep "Uncompressing Message" "$BBS_LOG" 2>/dev/null | \
        awk '{print $3}' | tr -d '|' | sort | uniq -c | sort -rn | \
        awk '{printf "    %2d msgs from %s\n", $1, $2}'
    
    total_recv=$(grep -c "Uncompressing Message" "$BBS_LOG" 2>/dev/null || echo 0)
    total_sent=$(grep -c "Compressed Message" "$BBS_LOG" 2>/dev/null || echo 0)
    
    echo ""
    echo "  Summary:"
    echo "    Total Received:  $total_recv messages"
    echo "    Total Sent:      $total_sent messages"
fi

#───────────────────────────────────────────────────────────────────
# ISSUES DETECTION
#───────────────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  ⚠️  ISSUES DETECTED"
echo "════════════════════════════════════════════════════════════════"

echo ""
echo "── Stations with 0% Success (check config!) ──"

# Find stations with failures but no successes
awk '
    NR==FNR { success[$2] = $1; next }
    { 
        if (!($2 in success) && $1 > 3) {
            printf "  ✗ %s: %d failures, 0 successes\n", $2, $1
        }
    }
' "$TEMP_DIR/success.txt" "$TEMP_DIR/failed.txt"

# Check for callsign typos (W7MBH vs W7BMH pattern)
if [ -n "$BBS_LOG" ] && [ -f "$BBS_LOG" ]; then
    echo ""
    echo "── Potential Callsign Typos ──"
    
    vara_w7=$(grep "Connecting to W7" "$VARA_LOG" 2>/dev/null | grep -oE 'W7[A-Z]{2,3}' | sort -u | tr '\n' ' ')
    bbs_w7=$(grep "RADIO" "$BBS_LOG" 2>/dev/null | grep -oE 'W7[A-Z]{2,3}' | sort -u | tr '\n' ' ')
    
    if [ -n "$vara_w7" ] && [ -n "$bbs_w7" ] && [ "$vara_w7" != "$bbs_w7" ]; then
        echo "  ⚠️  Mismatch detected:"
        echo "     VARA tries: $vara_w7"
        echo "     BBS config: $bbs_w7"
        echo "     → Check BPQ32 config for typo!"
    else
        echo "  ✓ No obvious callsign typos found"
    fi
fi

#───────────────────────────────────────────────────────────────────
# RECOMMENDATIONS
#───────────────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  📡 FREQUENCY RECOMMENDATIONS"
echo "════════════════════════════════════════════════════════════════"

echo ""
echo "── Best Frequencies by Time ──"
echo ""
echo "  00:00-06:00Z (Night):"
echo "    Primary:   3.585-3.597 MHz (80m)"
echo "    Alternate: 7.102-7.104 MHz (40m)"
echo ""
echo "  06:00-12:00Z (Morning):"
echo "    Primary:   7.102-7.104 MHz (40m)"
echo "    Alternate: 14.103-14.108 MHz (20m)"
echo ""
echo "  12:00-18:00Z (Afternoon):"
echo "    Primary:   14.103-14.108 MHz (20m)"
echo "    Alternate: 7.102-7.104 MHz (40m)"
echo ""
echo "  18:00-24:00Z (Evening):"
echo "    Primary:   14.106-14.108 MHz (20m)"
echo "    Alternate: 7.104 MHz (40m)"
echo ""
echo "── Top Recommended ──"
echo ""
echo "  1. 7.1032 MHz (40m)  - High success rate"
echo "  2. 14.108 MHz (20m)  - Most incoming traffic"  
echo "  3. 3.5975 MHz (80m)  - Best for nighttime"
echo ""

echo "════════════════════════════════════════════════════════════════"
echo "  73 de K1AJD! 📡"
echo "════════════════════════════════════════════════════════════════"
