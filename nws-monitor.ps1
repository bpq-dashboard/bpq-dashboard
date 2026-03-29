#
# ============================================================================
# NWS Alert Monitor - Windows Background Service
# ============================================================================
#
# This script runs in the background and fetches NWS alerts periodically,
# saving them to a JSON file that the NWS Dashboard reads automatically.
#
# Usage:
#   .\nws-monitor.ps1                     # Run once
#   .\nws-monitor.ps1 -Daemon             # Run continuously (every 5 min)
#   .\nws-monitor.ps1 -Config             # Show current configuration
#   .\nws-monitor.ps1 -Install            # Install as Windows Task
#
# ============================================================================

param(
    [switch]$Daemon,
    [switch]$Config,
    [switch]$Install,
    [switch]$Help
)

# =========================
# CONFIGURATION - EDIT THIS
# =========================

$script:OUTPUT_DIR = "C:\UniServerZ\www\bpq\wx"
$script:OUTPUT_FILE = "$OUTPUT_DIR\nws-alerts.json"

# Regions to monitor (comma-separated: SR,CR,ER,WR,AR,PR or ALL)
$script:REGIONS = "ALL"

# Alert types to monitor (tornado, severe, flood, hurricane, winter, all)
$script:ALERT_TYPES = "tornado,severe,winter"

# Fetch interval in seconds (default: 300 = 5 minutes)
$script:FETCH_INTERVAL = 300

# Your station info
$script:FROM_CALLSIGN = "K1AJD"
$script:TO_ADDRESS = "WX@ALLUS"

# Logging
$script:LOG_FILE = "$OUTPUT_DIR\nws-monitor.log"
$script:PROCESSED_FILE = "$OUTPUT_DIR\nws-processed.txt"

# =========================
# END CONFIGURATION
# =========================

# Create output directory
if (!(Test-Path $OUTPUT_DIR)) {
    New-Item -ItemType Directory -Path $OUTPUT_DIR -Force | Out-Null
}

# Create processed file if not exists
if (!(Test-Path $PROCESSED_FILE)) {
    New-Item -ItemType File -Path $PROCESSED_FILE -Force | Out-Null
}

# Logging function
function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logEntry = "$timestamp [$Level] $Message"
    Write-Host $logEntry
    Add-Content -Path $LOG_FILE -Value $logEntry -ErrorAction SilentlyContinue
}

# Build event list based on alert types
function Get-EventList {
    param([string]$Types)
    
    $events = @()
    
    foreach ($type in $Types.Split(',')) {
        switch ($type.Trim()) {
            "tornado" {
                $events += "Tornado Warning", "Tornado Watch"
            }
            "severe" {
                $events += "Severe Thunderstorm Warning", "Severe Thunderstorm Watch"
            }
            "flood" {
                $events += "Flash Flood Warning", "Flash Flood Watch", "Flood Warning", "Flood Watch"
            }
            "hurricane" {
                $events += "Hurricane Warning", "Hurricane Watch", "Tropical Storm Warning", "Tropical Storm Watch"
            }
            "winter" {
                $events += "Winter Storm Warning", "Winter Storm Watch", "Winter Weather Advisory", "Blizzard Warning", "Ice Storm Warning", "Freeze Warning", "Wind Chill Warning", "Wind Chill Advisory"
            }
            "all" {
                $events += "Tornado Warning", "Tornado Watch"
                $events += "Severe Thunderstorm Warning", "Severe Thunderstorm Watch"
                $events += "Flash Flood Warning", "Flash Flood Watch", "Flood Warning", "Flood Watch"
                $events += "Hurricane Warning", "Hurricane Watch", "Tropical Storm Warning", "Tropical Storm Watch"
                $events += "Winter Storm Warning", "Winter Storm Watch", "Winter Weather Advisory", "Blizzard Warning", "Ice Storm Warning", "Freeze Warning", "Wind Chill Warning", "Wind Chill Advisory"
            }
        }
    }
    
    return ($events | Select-Object -Unique) -join ","
}

# Fetch alerts from NWS API
function Invoke-FetchAlerts {
    $events = Get-EventList -Types $ALERT_TYPES
    $encodedEvents = [System.Web.HttpUtility]::UrlEncode($events)
    
    $url = "https://api.weather.gov/alerts/active?status=actual&message_type=alert&event=$encodedEvents"
    
    if ($REGIONS -ne "ALL") {
        $url += "&region=$REGIONS"
    }
    
    Write-Log "Fetching alerts from NWS API..."
    Write-Log "URL: $url" "DEBUG"
    
    try {
        $headers = @{
            "User-Agent" = "K1AJD-NWS-Monitor/1.0 (Amateur Radio Emergency Comms)"
        }
        
        $response = Invoke-RestMethod -Uri $url -Headers $headers -TimeoutSec 30
        $alertCount = $response.features.Count
        
        Write-Log "Received $alertCount alerts"
        
        # Add metadata
        $timestamp = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
        $metadata = @{
            fetched = $timestamp
            regions = $REGIONS
            alert_types = $ALERT_TYPES
            source = "NWS API"
            monitor_version = "1.0"
        }
        
        $response | Add-Member -NotePropertyName "metadata" -NotePropertyValue $metadata -Force
        
        # Save to JSON file
        $response | ConvertTo-Json -Depth 10 | Out-File -FilePath $OUTPUT_FILE -Encoding UTF8
        Write-Log "Saved alerts to $OUTPUT_FILE"
        
        # Check for new alerts
        $processed = @()
        if (Test-Path $PROCESSED_FILE) {
            $processed = Get-Content $PROCESSED_FILE
        }
        
        $newCount = 0
        foreach ($alert in $response.features) {
            $alertId = $alert.properties.id
            $shortId = $alertId.Split("/")[-1]
            
            if ($processed -notcontains $shortId) {
                $newCount++
                $event = $alert.properties.event
                $headline = $alert.properties.headline
                
                Write-Log "NEW ALERT: $event - $headline" "ALERT"
                
                # Mark as processed
                Add-Content -Path $PROCESSED_FILE -Value $shortId
            }
        }
        
        if ($newCount -gt 0) {
            Write-Log "Found $newCount new alerts" "ALERT"
        }
        
        return $true
    }
    catch {
        Write-Log "Error fetching alerts: $($_.Exception.Message)" "ERROR"
        return $false
    }
}

# Show configuration
function Show-Configuration {
    Write-Host "============================================"
    Write-Host "NWS Alert Monitor Configuration"
    Write-Host "============================================"
    Write-Host ""
    Write-Host "Output Directory: $OUTPUT_DIR"
    Write-Host "Output File:      $OUTPUT_FILE"
    Write-Host "Regions:          $REGIONS"
    Write-Host "Alert Types:      $ALERT_TYPES"
    Write-Host "Fetch Interval:   ${FETCH_INTERVAL}s"
    Write-Host "Callsign:         $FROM_CALLSIGN"
    Write-Host "Destination:      $TO_ADDRESS"
    Write-Host ""
    Write-Host "Log File:         $LOG_FILE"
    Write-Host "Processed File:   $PROCESSED_FILE"
    Write-Host ""
}

# Install as Windows Task
function Install-ScheduledTask {
    $scriptPath = $MyInvocation.PSCommandPath
    
    Write-Host "Creating scheduled task for NWS Monitor..."
    
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptPath`""
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 9999)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
    
    try {
        Register-ScheduledTask -TaskName "NWS Alert Monitor" -Action $action -Trigger $trigger -Settings $settings -Force
        Write-Host "Scheduled task created successfully!"
        Write-Host "Task will run every 5 minutes."
    }
    catch {
        Write-Host "Error creating scheduled task: $($_.Exception.Message)"
        Write-Host "Try running PowerShell as Administrator."
    }
}

# Show help
function Show-Help {
    Write-Host "NWS Alert Monitor for BPQ BBS"
    Write-Host ""
    Write-Host "Usage: .\nws-monitor.ps1 [options]"
    Write-Host ""
    Write-Host "Options:"
    Write-Host "  -Daemon     Run continuously in background"
    Write-Host "  -Config     Show current configuration"
    Write-Host "  -Install    Install as Windows scheduled task"
    Write-Host "  -Help       Show this help"
    Write-Host ""
    Write-Host "Without options, runs a single fetch."
    Write-Host ""
    Write-Host "Edit the CONFIGURATION section at the top of this script"
    Write-Host "to customize regions, alert types, and output location."
}

# Main
if ($Help) {
    Show-Help
    exit 0
}

if ($Config) {
    Show-Configuration
    exit 0
}

if ($Install) {
    Install-ScheduledTask
    exit 0
}

if ($Daemon) {
    Write-Log "Starting NWS Alert Monitor in daemon mode"
    Write-Log "Fetch interval: ${FETCH_INTERVAL}s"
    Write-Log "Regions: $REGIONS"
    Write-Log "Alert types: $ALERT_TYPES"
    
    # Initial fetch
    Invoke-FetchAlerts
    
    # Continuous monitoring
    while ($true) {
        Start-Sleep -Seconds $FETCH_INTERVAL
        Invoke-FetchAlerts
    }
}
else {
    # Single fetch
    Write-Log "Running single fetch"
    Invoke-FetchAlerts
}
