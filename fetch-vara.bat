@echo off
REM ============================================================================
REM BPQ Dashboard - VARA Log Fetch Script (Windows)
REM ============================================================================
REM
REM This script downloads your VARA log file from a remote web server and
REM APPENDS new entries to your local log file (preserving historical data).
REM
REM Run via Task Scheduler every 15 minutes.
REM
REM Configuration: Edit the variables below or use fetch-vara-config.bat
REM
REM ============================================================================

setlocal enabledelayedexpansion

REM Load configuration if exists
if exist "%~dp0fetch-vara-config.bat" (
    call "%~dp0fetch-vara-config.bat"
) else (
    REM Default configuration - EDIT THESE VALUES
    REM VARA_URL = URL of the REMOTE station's VARA log file
    REM VARA_FILE = Name to save as LOCALLY (typically YOUR callsign)
    set VARA_URL=http://example.com/logs/remotecall.vara
    set VARA_FILE=localcall.vara
    set OUTPUT_DIR=%~dp0logs
)

REM Create output directory if needed
if not exist "%OUTPUT_DIR%" mkdir "%OUTPUT_DIR%"

set LOCAL_FILE=%OUTPUT_DIR%\%VARA_FILE%
set TEMP_FILE=%OUTPUT_DIR%\vara_temp.txt
set TRACKING_FILE=%OUTPUT_DIR%\.vara_last_lines

REM Download the remote VARA log to temp file
wget -q -O "%TEMP_FILE%" "%VARA_URL%"

if %ERRORLEVEL% neq 0 (
    echo %date% %time% - Failed to download from %VARA_URL%
    del /q "%TEMP_FILE%" 2>nul
    exit /b 1
)

REM Count lines in downloaded file
set REMOTE_LINES=0
for /f %%a in ('find /c /v "" ^< "%TEMP_FILE%"') do set REMOTE_LINES=%%a

REM If local file doesn't exist, just use the downloaded file
if not exist "%LOCAL_FILE%" (
    move /y "%TEMP_FILE%" "%LOCAL_FILE%" >nul
    echo %REMOTE_LINES% > "%TRACKING_FILE%"
    echo %date% %time% - Created %VARA_FILE% (%REMOTE_LINES% lines)
    exit /b 0
)

REM Get last known line count (or 0 if tracking file doesn't exist)
set LAST_LINES=0
if exist "%TRACKING_FILE%" (
    set /p LAST_LINES=<"%TRACKING_FILE%"
)

REM If remote file has more lines than we last saw, append the new lines
if %REMOTE_LINES% gtr %LAST_LINES% (
    set /a NEW_LINES=%REMOTE_LINES% - %LAST_LINES%
    
    REM Use PowerShell to get the last N lines and append
    powershell -Command "Get-Content '%TEMP_FILE%' | Select-Object -Last %NEW_LINES% | Add-Content '%LOCAL_FILE%'"
    
    echo %REMOTE_LINES% > "%TRACKING_FILE%"
    echo %date% %time% - Appended !NEW_LINES! new lines to %VARA_FILE%
) else if %REMOTE_LINES% lss %LAST_LINES% (
    REM Remote file was rotated/reset - append all (it's new data)
    type "%TEMP_FILE%" >> "%LOCAL_FILE%"
    echo %REMOTE_LINES% > "%TRACKING_FILE%"
    echo %date% %time% - Remote log rotated, appended %REMOTE_LINES% lines to %VARA_FILE%
) else (
    echo %date% %time% - No new data in %VARA_FILE%
)

REM Cleanup
del /q "%TEMP_FILE%" 2>nul

endlocal
