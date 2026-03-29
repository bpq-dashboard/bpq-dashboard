# ============================================
# VARA Log Fetcher for BPQ Dashboard (PowerShell)
# Fetches your VARA log from TPRFN server
# No additional software required - uses built-in PowerShell
# Schedule this to run every 15 minutes
# ============================================

# ============================================
# CONFIGURATION - Edit these settings
# ============================================
$CALLSIGN = "YOURCALL"
$LOG_DIR = "C:\UniServerZ\www\bpq\logs"
# ============================================

# Build URL and paths
$VARA_URL = "https://tprfn.k1ajd.net/$CALLSIGN.vara"
$VARA_FILE = "$LOG_DIR\$CALLSIGN.vara"
$TEMP_FILE = "$env:TEMP\$CALLSIGN`_new.vara"

# Create log directory if needed
if (!(Test-Path $LOG_DIR)) {
    New-Item -ItemType Directory -Path $LOG_DIR -Force | Out-Null
    Write-Host "Created directory: $LOG_DIR"
}

Write-Host "Fetching VARA log for $CALLSIGN from TPRFN server..."

try {
    # Download the VARA log
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $VARA_URL -OutFile $TEMP_FILE -UseBasicParsing
    
    # Check if file has content
    $fileSize = (Get-Item $TEMP_FILE).Length
    if ($fileSize -eq 0) {
        Write-Host "WARNING: Downloaded file is empty. Check if $CALLSIGN.vara exists on server."
        Remove-Item $TEMP_FILE -ErrorAction SilentlyContinue
        exit 1
    }
    
    # Get new content
    $newContent = Get-Content $TEMP_FILE -Raw
    
    if (Test-Path $VARA_FILE) {
        # Append to existing file
        Add-Content -Path $VARA_FILE -Value $newContent
        Write-Host "Appended new data to $VARA_FILE"
    } else {
        # First run - copy the file
        Copy-Item $TEMP_FILE $VARA_FILE
        Write-Host "Created $VARA_FILE"
    }
    
    # Cleanup
    Remove-Item $TEMP_FILE -ErrorAction SilentlyContinue
    
    Write-Host "SUCCESS: VARA log updated at $(Get-Date)"
    
} catch {
    Write-Host "ERROR: Failed to download VARA log from $VARA_URL"
    Write-Host "Error details: $_"
    exit 1
}
