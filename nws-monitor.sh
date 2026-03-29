#!/bin/bash
#
# ============================================================================
# NWS Alert Monitor - Background Service
# ============================================================================
#
# This script runs in the background and fetches NWS alerts periodically,
# saving them to a JSON file that the NWS Dashboard reads automatically.
#
# Usage:
#   ./nws-monitor.sh                    # Run once
#   ./nws-monitor.sh --daemon           # Run continuously (every 5 min)
#   ./nws-monitor.sh --config           # Show current configuration
#
# Install as systemd service:
#   sudo cp nws-monitor.sh /usr/local/bin/
#   sudo cp nws-monitor.service /etc/systemd/system/
#   sudo systemctl enable nws-monitor
#   sudo systemctl start nws-monitor
#
# ============================================================================

# =========================
# CONFIGURATION - EDIT THIS
# =========================

# Output location (must be web-accessible)
OUTPUT_DIR="/var/www/html/bpq/wx"
OUTPUT_FILE="$OUTPUT_DIR/nws-alerts.json"

# Regions to monitor (comma-separated: SR,CR,ER,WR,AR,PR or ALL)
REGIONS="ALL"

# Alert types to monitor
# Options: tornado, severe, flood, hurricane, winter, all
ALERT_TYPES="tornado,severe,winter"

# Fetch interval in seconds (default: 300 = 5 minutes)
FETCH_INTERVAL=300

# Your station info (for BBS message formatting)
FROM_CALLSIGN="K1AJD"
TO_ADDRESS="WX@ALLUS"

# Auto-post to BBS (0=disabled, 1=enabled)
AUTO_POST=0
BBS_HOST="localhost"
BBS_PORT="8010"
BBS_USER="SYSOP"
BBS_PASS="password"

# Logging
LOG_FILE="/var/log/nws-monitor.log"
PROCESSED_FILE="$OUTPUT_DIR/.nws-processed.txt"

# =========================
# END CONFIGURATION
# =========================

# Create directories
mkdir -p "$OUTPUT_DIR"
touch "$PROCESSED_FILE"

# Logging function
log() {
    local msg="$(date '+%Y-%m-%d %H:%M:%S') - $1"
    echo "$msg"
    echo "$msg" >> "$LOG_FILE" 2>/dev/null
}

# Build event list based on alert types
get_events() {
    local types="$1"
    local events=""
    
    IFS=',' read -ra TYPE_ARRAY <<< "$types"
    for type in "${TYPE_ARRAY[@]}"; do
        case "$type" in
            tornado)
                events="${events},Tornado Warning,Tornado Watch"
                ;;
            severe)
                events="${events},Severe Thunderstorm Warning,Severe Thunderstorm Watch"
                ;;
            flood)
                events="${events},Flash Flood Warning,Flash Flood Watch,Flood Warning,Flood Watch"
                ;;
            hurricane)
                events="${events},Hurricane Warning,Hurricane Watch,Tropical Storm Warning,Tropical Storm Watch"
                ;;
            winter)
                events="${events},Winter Storm Warning,Winter Storm Watch,Winter Weather Advisory,Blizzard Warning,Ice Storm Warning,Freeze Warning,Freeze Watch,Wind Chill Warning,Wind Chill Watch,Wind Chill Advisory,Hard Freeze Warning,Hard Freeze Watch,Frost Advisory"
                ;;
            all)
                events="Tornado Warning,Tornado Watch,Severe Thunderstorm Warning,Severe Thunderstorm Watch,Flash Flood Warning,Flash Flood Watch,Flood Warning,Flood Watch,Hurricane Warning,Hurricane Watch,Tropical Storm Warning,Tropical Storm Watch,Winter Storm Warning,Winter Storm Watch,Winter Weather Advisory,Blizzard Warning,Ice Storm Warning,Freeze Warning,Wind Chill Warning,Wind Chill Advisory"
                ;;
        esac
    done
    
    # Remove leading comma
    echo "${events#,}"
}

# Fetch alerts from NWS API
fetch_alerts() {
    local events=$(get_events "$ALERT_TYPES")
    local encoded_events=$(echo "$events" | sed 's/ /%20/g' | sed 's/,/%2C/g')
    
    local url="https://api.weather.gov/alerts/active?status=actual&message_type=alert&event=${encoded_events}"
    
    # Add region filter unless ALL
    if [ "$REGIONS" != "ALL" ]; then
        url="${url}&region=${REGIONS}"
    fi
    
    log "Fetching alerts from NWS API..."
    log "URL: $url"
    
    local response=$(curl -s -H "User-Agent: K1AJD-NWS-Monitor/1.0 (Amateur Radio Emergency Comms)" "$url")
    
    if [ -z "$response" ]; then
        log "ERROR: Empty response from NWS API"
        return 1
    fi
    
    # Validate JSON
    if ! echo "$response" | jq empty 2>/dev/null; then
        log "ERROR: Invalid JSON response"
        return 1
    fi
    
    local count=$(echo "$response" | jq '.features | length')
    log "Received $count alerts"
    
    # Add metadata to response
    local timestamp=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    local enhanced=$(echo "$response" | jq --arg ts "$timestamp" --arg regions "$REGIONS" --arg types "$ALERT_TYPES" \
        '. + {
            metadata: {
                fetched: $ts,
                regions: $regions,
                alert_types: $types,
                source: "NWS API",
                monitor_version: "1.0"
            }
        }')
    
    # Save to file
    echo "$enhanced" > "$OUTPUT_FILE"
    log "Saved alerts to $OUTPUT_FILE"
    
    # Check for new alerts and optionally auto-post
    check_new_alerts "$response"
    
    return 0
}

# Check for new alerts
check_new_alerts() {
    local response="$1"
    local new_count=0
    
    echo "$response" | jq -r '.features[].properties.id' | while read -r alert_id; do
        local short_id="${alert_id##*/}"
        
        if ! grep -q "$short_id" "$PROCESSED_FILE" 2>/dev/null; then
            new_count=$((new_count + 1))
            local event=$(echo "$response" | jq -r ".features[] | select(.properties.id == \"$alert_id\") | .properties.event")
            local headline=$(echo "$response" | jq -r ".features[] | select(.properties.id == \"$alert_id\") | .properties.headline")
            
            log "NEW ALERT: $event - $headline"
            
            # Mark as seen
            echo "$short_id $(date '+%Y-%m-%d %H:%M:%S') $event" >> "$PROCESSED_FILE"
            
            # Auto-post if enabled
            if [ "$AUTO_POST" -eq 1 ]; then
                post_alert_to_bbs "$response" "$alert_id"
            fi
        fi
    done
    
    if [ $new_count -gt 0 ]; then
        log "Found $new_count new alerts"
    fi
}

# Post alert to BBS
post_alert_to_bbs() {
    local response="$1"
    local alert_id="$2"
    
    if ! command -v expect &> /dev/null; then
        log "WARNING: 'expect' not installed, cannot auto-post"
        return 1
    fi
    
    local alert=$(echo "$response" | jq ".features[] | select(.properties.id == \"$alert_id\")")
    local props=$(echo "$alert" | jq '.properties')
    
    local event=$(echo "$props" | jq -r '.event')
    local headline=$(echo "$props" | jq -r '.headline // .event')
    local description=$(echo "$props" | jq -r '.description // "No description"')
    local instruction=$(echo "$props" | jq -r '.instruction // "Follow NWS guidance"')
    local sender=$(echo "$props" | jq -r '.senderName // "NWS"')
    local expires=$(echo "$props" | jq -r '.expires // "Unknown"')
    local severity=$(echo "$props" | jq -r '.severity // "Unknown"')
    local urgency=$(echo "$props" | jq -r '.urgency // "Unknown"')
    local areas=$(echo "$props" | jq -r '.areaDesc // "Unknown"')
    local short_id="${alert_id##*/}"
    
    local type_indicator="***"
    if echo "$event" | grep -qi "warning"; then
        type_indicator="*** WARNING ***"
    elif echo "$event" | grep -qi "watch"; then
        type_indicator="*** WATCH ***"
    fi
    
    # Format expires
    local expires_fmt=$(date -d "$expires" '+%m/%d %H%MZ' 2>/dev/null || echo "$expires")
    
    log "Posting to BBS: $event ($short_id)"
    
    expect << EOFEXP
set timeout 30
spawn telnet $BBS_HOST $BBS_PORT
expect "Callsign:" { send "$BBS_USER\r" }
expect "Password:" { send "$BBS_PASS\r" }
expect ">" { send "SP $TO_ADDRESS\r" }
expect "ubject:" { send "$type_indicator $event\r" }
expect ":" {
    send "$headline\r\r"
    send "ISSUED BY: $sender\r"
    send "EXPIRES: $expires_fmt\r"
    send "SEVERITY: $severity\r"
    send "URGENCY: $urgency\r\r"
    send "AFFECTED AREAS:\r$areas\r\r"
    send "DESCRIPTION:\r$description\r\r"
    send "PROTECTIVE ACTIONS:\r$instruction\r\r"
    send "---\r"
    send "Alert ID: $short_id\r"
    send "Auto-posted by K1AJD NWS Monitor\r"
    send "73 de $FROM_CALLSIGN\r"
    send "/EX\r"
}
expect ">" { send "B\r" }
expect eof
EOFEXP

    log "Posted alert to BBS"
}

# Show configuration
show_config() {
    echo "============================================"
    echo "NWS Alert Monitor Configuration"
    echo "============================================"
    echo ""
    echo "Output Directory: $OUTPUT_DIR"
    echo "Output File:      $OUTPUT_FILE"
    echo "Regions:          $REGIONS"
    echo "Alert Types:      $ALERT_TYPES"
    echo "Fetch Interval:   ${FETCH_INTERVAL}s"
    echo "Auto-Post:        $([ $AUTO_POST -eq 1 ] && echo 'Enabled' || echo 'Disabled')"
    echo "Callsign:         $FROM_CALLSIGN"
    echo "Destination:      $TO_ADDRESS"
    echo ""
    echo "Log File:         $LOG_FILE"
    echo "Processed File:   $PROCESSED_FILE"
    echo ""
}

# Cleanup old processed entries (keep last 7 days)
cleanup_processed() {
    if [ -f "$PROCESSED_FILE" ]; then
        local cutoff=$(date -d '7 days ago' '+%Y-%m-%d')
        local temp=$(mktemp)
        awk -v cutoff="$cutoff" '$2 >= cutoff' "$PROCESSED_FILE" > "$temp"
        mv "$temp" "$PROCESSED_FILE"
        log "Cleaned up old processed entries"
    fi
}

# Main
main() {
    case "$1" in
        --config|-c)
            show_config
            exit 0
            ;;
        --daemon|-d)
            log "Starting NWS Alert Monitor in daemon mode"
            log "Fetch interval: ${FETCH_INTERVAL}s"
            log "Regions: $REGIONS"
            log "Alert types: $ALERT_TYPES"
            
            # Initial fetch
            fetch_alerts
            
            # Continuous monitoring
            while true; do
                sleep "$FETCH_INTERVAL"
                fetch_alerts
                
                # Cleanup once per hour
                if [ $(($(date +%s) % 3600)) -lt $FETCH_INTERVAL ]; then
                    cleanup_processed
                fi
            done
            ;;
        --help|-h)
            echo "Usage: $0 [--daemon|--config|--help]"
            echo ""
            echo "Options:"
            echo "  --daemon, -d   Run continuously in background"
            echo "  --config, -c   Show current configuration"
            echo "  --help, -h     Show this help"
            echo ""
            echo "Without options, runs a single fetch."
            exit 0
            ;;
        *)
            # Single fetch
            log "Running single fetch"
            fetch_alerts
            ;;
    esac
}

main "$@"
