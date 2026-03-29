@echo off
REM ============================================
REM VARA Log Fetcher for BPQ Dashboard
REM Fetches your VARA log from TPRFN server
REM Schedule this to run every 15 minutes
REM ============================================

REM ============================================
REM CONFIGURATION - Edit these settings
REM ============================================
set CALLSIGN=YOURCALL
set LOG_DIR=C:\UniServerZ\www\bpq\logs
REM ============================================

REM Build the URL and filename
set VARA_URL=https://tprfn.k1ajd.net/%CALLSIGN%.vara
set VARA_FILE=%LOG_DIR%\%CALLSIGN%.vara

REM Create log directory if it doesn't exist
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

REM Create temp directory for download
if not exist "%TEMP%\bpq-vara" mkdir "%TEMP%\bpq-vara"
set TEMP_FILE=%TEMP%\bpq-vara\%CALLSIGN%_new.vara

REM Fetch the VARA log using wget
echo Fetching VARA log for %CALLSIGN% from TPRFN server...
wget -q -O "%TEMP_FILE%" "%VARA_URL%"

REM Check if download was successful
if %ERRORLEVEL% neq 0 (
    echo ERROR: Failed to download VARA log from %VARA_URL%
    echo Make sure wget is installed and your callsign is correct.
    exit /b 1
)

REM Check if file has content
for %%A in ("%TEMP_FILE%") do set FILESIZE=%%~zA
if %FILESIZE% EQU 0 (
    echo WARNING: Downloaded file is empty. Check if %CALLSIGN%.vara exists on server.
    del "%TEMP_FILE%" 2>nul
    exit /b 1
)

REM Append new data to existing file (or create if doesn't exist)
if exist "%VARA_FILE%" (
    REM Append only new lines (avoid duplicates)
    type "%TEMP_FILE%" >> "%VARA_FILE%"
    echo Appended new data to %VARA_FILE%
) else (
    REM First run - just copy the file
    copy "%TEMP_FILE%" "%VARA_FILE%" >nul
    echo Created %VARA_FILE%
)

REM Cleanup temp file
del "%TEMP_FILE%" 2>nul

echo SUCCESS: VARA log updated at %date% %time%
exit /b 0
