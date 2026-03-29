@echo off
REM ============================================================================
REM BPQ Dashboard Data Archiver for Windows
REM Version: 1.4.0
REM
REM Archives BPQ logs and dashboard data:
REM   - Weekly: 7-day incremental archives (run every week)
REM   - Monthly: Full calendar month archives (auto-triggered first week of month)
REM
REM Schedule with Task Scheduler to run weekly (e.g., Sunday 2:00 AM)
REM
REM The monthly archive collects ALL log files for every day of the previous
REM calendar month — not just a single week's snapshot. This gives you a
REM complete month of BBS, VARA, TCP, and CMSAccess logs in one archive.
REM
REM This script has NO impact on dashboard operation - it only creates copies.
REM ============================================================================

setlocal enabledelayedexpansion

REM ============================================================================
REM CONFIGURATION - Edit these paths for your installation
REM ============================================================================

REM Dashboard installation directory
set DASHBOARD_DIR=C:\UniServer\www\bpq

REM BPQ32 logs directory (if different from dashboard logs)
set BPQ_LOGS_DIR=C:\BPQ32\logs

REM Archive storage location
set ARCHIVE_DIR=%DASHBOARD_DIR%\archives

REM Retention (number of archives to keep)
set RETENTION_WEEKS=12
set RETENTION_MONTHS=12

REM ============================================================================
REM INITIALIZATION
REM ============================================================================

echo ============================================
echo BPQ Dashboard Data Archiver v1.4.0
echo ============================================
echo.

REM Check if dashboard directory exists
if not exist "%DASHBOARD_DIR%" (
    echo ERROR: Dashboard directory not found: %DASHBOARD_DIR%
    echo Please edit this script and set DASHBOARD_DIR correctly.
    pause
    exit /b 1
)

REM Create archive directories
if not exist "%ARCHIVE_DIR%\weekly" mkdir "%ARCHIVE_DIR%\weekly"
if not exist "%ARCHIVE_DIR%\monthly" mkdir "%ARCHIVE_DIR%\monthly"
if not exist "%ARCHIVE_DIR%\current" mkdir "%ARCHIVE_DIR%\current"

REM Get current date components via wmic (reliable across locales)
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set datetime=%%I
set CUR_YEAR=%datetime:~0,4%
set CUR_MONTH=%datetime:~4,2%
set CUR_DAY=%datetime:~6,2%

echo Dashboard: %DASHBOARD_DIR%
echo Date: %CUR_YEAR%-%CUR_MONTH%-%CUR_DAY%
echo.

REM ============================================================================
REM WEEKLY ARCHIVE (Last 7 days)
REM ============================================================================

set ARCHIVE_NAME=bpq-data-%CUR_YEAR%-%CUR_MONTH%-%CUR_DAY%

REM Check if this week's archive already exists
if exist "%ARCHIVE_DIR%\weekly\%ARCHIVE_NAME%.zip" (
    echo Weekly archive already exists for today. Skipping weekly.
    goto :monthly
)

echo --- Weekly Archive ---
echo Archive: %ARCHIVE_NAME%
echo.

REM Create temp staging directory
set TEMP_DIR=%TEMP%\bpq-archive-%RANDOM%
mkdir "%TEMP_DIR%\logs" 2>nul
mkdir "%TEMP_DIR%\data\messages" 2>nul

REM Collect recent log files (last 7 days)
echo Collecting log files from last 7 days...

set FILE_COUNT=0

REM Copy BPQ logs from dashboard logs directory
powershell -NoProfile -Command ^
    "Get-ChildItem '%DASHBOARD_DIR%\logs\log_*.txt' -ErrorAction SilentlyContinue | Where-Object {$_.LastWriteTime -gt (Get-Date).AddDays(-7)} | ForEach-Object { Copy-Item $_.FullName '%TEMP_DIR%\logs\' }"

REM Copy CMSAccess logs from dashboard logs directory
powershell -NoProfile -Command ^
    "Get-ChildItem '%DASHBOARD_DIR%\logs\CMSAccess_*' -ErrorAction SilentlyContinue | Where-Object {$_.LastWriteTime -gt (Get-Date).AddDays(-7)} | ForEach-Object { Copy-Item $_.FullName '%TEMP_DIR%\logs\' }"

REM Also check BPQ32 logs directory if different
if exist "%BPQ_LOGS_DIR%" (
    if not "%BPQ_LOGS_DIR%"=="%DASHBOARD_DIR%\logs" (
        echo Checking BPQ32 logs directory...
        powershell -NoProfile -Command ^
            "Get-ChildItem '%BPQ_LOGS_DIR%\log_*.txt' -ErrorAction SilentlyContinue | Where-Object {$_.LastWriteTime -gt (Get-Date).AddDays(-7)} | ForEach-Object { Copy-Item $_.FullName '%TEMP_DIR%\logs\' -ErrorAction SilentlyContinue }"
        powershell -NoProfile -Command ^
            "Get-ChildItem '%BPQ_LOGS_DIR%\CMSAccess_*' -ErrorAction SilentlyContinue | Where-Object {$_.LastWriteTime -gt (Get-Date).AddDays(-7)} | ForEach-Object { Copy-Item $_.FullName '%TEMP_DIR%\logs\' -ErrorAction SilentlyContinue }"
    )
)

REM Count collected log files
for /f %%A in ('dir /b "%TEMP_DIR%\logs\*.*" 2^>nul ^| find /c /v ""') do set FILE_COUNT=%%A
echo   Collected %FILE_COUNT% log files

REM Collect dashboard data files
echo Collecting dashboard data files...

set DATA_COUNT=0

if exist "%DASHBOARD_DIR%\data\messages\messages.json" (
    copy "%DASHBOARD_DIR%\data\messages\messages.json" "%TEMP_DIR%\data\messages\" >nul
    set /a DATA_COUNT+=1
)
if exist "%DASHBOARD_DIR%\data\messages\folders.json" (
    copy "%DASHBOARD_DIR%\data\messages\folders.json" "%TEMP_DIR%\data\messages\" >nul
    set /a DATA_COUNT+=1
)
if exist "%DASHBOARD_DIR%\data\messages\addresses.json" (
    copy "%DASHBOARD_DIR%\data\messages\addresses.json" "%TEMP_DIR%\data\messages\" >nul
    set /a DATA_COUNT+=1
)
if exist "%DASHBOARD_DIR%\logs\dashboard.log" (
    copy "%DASHBOARD_DIR%\logs\dashboard.log" "%TEMP_DIR%\logs\" >nul
    set /a DATA_COUNT+=1
)
if exist "%DASHBOARD_DIR%\data\stations\locations.json" (
    if not exist "%TEMP_DIR%\data\stations\" mkdir "%TEMP_DIR%\data\stations"
    copy "%DASHBOARD_DIR%\data\stations\*.json" "%TEMP_DIR%\data\stations\" >nul
    set /a DATA_COUNT+=1
)
if exist "%DASHBOARD_DIR%\station-locations.json" (
    copy "%DASHBOARD_DIR%\station-locations.json" "%TEMP_DIR%\data\" >nul
    set /a DATA_COUNT+=1
)

echo   Collected %DATA_COUNT% data files

REM Create manifest
(
echo BPQ Dashboard Weekly Data Archive
echo ==================================
echo Archive Name: %ARCHIVE_NAME%
echo Created: %DATE% %TIME%
echo Computer: %COMPUTERNAME%
echo Dashboard Version: 1.4.0
echo.
echo Archive Contents
echo ----------------
echo Log files: %FILE_COUNT%
echo Data files: %DATA_COUNT%
echo.
echo Notes
echo -----
echo - This archive contains a 7-day snapshot of BPQ logs and dashboard data
echo - To restore: extract and copy files to appropriate directories
echo - Dashboard password is NOT included
) > "%TEMP_DIR%\MANIFEST.txt"

REM Create compressed archive
echo Creating weekly compressed archive...
powershell -NoProfile -Command ^
    "Compress-Archive -Path '%TEMP_DIR%\*' -DestinationPath '%ARCHIVE_DIR%\weekly\%ARCHIVE_NAME%.zip' -Force"

REM Copy as latest
copy "%ARCHIVE_DIR%\weekly\%ARCHIVE_NAME%.zip" "%ARCHIVE_DIR%\current\bpq-data-latest.zip" >nul

REM Report size
for %%A in ("%ARCHIVE_DIR%\weekly\%ARCHIVE_NAME%.zip") do set ARCHIVE_SIZE=%%~zA
set /a ARCHIVE_SIZE_KB=%ARCHIVE_SIZE% / 1024
echo   Weekly archive created: %ARCHIVE_NAME%.zip (%ARCHIVE_SIZE_KB% KB)

REM Cleanup weekly temp
rmdir /s /q "%TEMP_DIR%" 2>nul

echo.

REM ============================================================================
REM MONTHLY ARCHIVE (Full calendar month)
REM
REM Triggered during the first 7 days of each month.
REM Collects ALL log files for every day of the previous calendar month.
REM Example: Running on Feb 3 creates an archive for all of January (days 1-31).
REM ============================================================================

:monthly

REM Only run monthly during the first 7 days of the month
set /a CUR_DAY_NUM=%CUR_DAY%
if %CUR_DAY_NUM% GTR 7 goto :cleanup

REM Calculate previous month and year
REM Use PowerShell for reliable date math
for /f "tokens=1,2,3 delims=-" %%a in ('powershell -NoProfile -Command "(Get-Date).AddMonths(-1).ToString('yyyy-MM-dd')"') do (
    set PREV_YEAR=%%a
    set PREV_MONTH=%%b
)

set MONTHLY_ARCHIVE=bpq-data-%PREV_YEAR%-%PREV_MONTH%

REM Check if monthly archive already exists
if exist "%ARCHIVE_DIR%\monthly\%MONTHLY_ARCHIVE%.zip" (
    echo Monthly archive already exists: %MONTHLY_ARCHIVE%.zip
    goto :cleanup
)

echo --- Monthly Archive: %PREV_YEAR%-%PREV_MONTH% ---

REM Get number of days in the previous month and friendly name via PowerShell
for /f %%D in ('powershell -NoProfile -Command "[DateTime]::DaysInMonth(%PREV_YEAR%, %PREV_MONTH%)"') do set DAYS_IN_MONTH=%%D
for /f "tokens=*" %%N in ('powershell -NoProfile -Command "(Get-Date -Year %PREV_YEAR% -Month %PREV_MONTH% -Day 1).ToString('MMMM yyyy')"') do set MONTH_NAME=%%N

echo Month: %MONTH_NAME% (%DAYS_IN_MONTH% days)
echo Collecting log files for every day of the month...

REM Create staging directory for monthly archive
set MONTHLY_TEMP=%TEMP%\bpq-monthly-%RANDOM%
mkdir "%MONTHLY_TEMP%\logs" 2>nul
mkdir "%MONTHLY_TEMP%\data\messages" 2>nul

REM Use PowerShell to iterate through every day of the previous month
REM and copy all matching log files (BBS, VARA, TCP, CMSAccess)
powershell -NoProfile -Command ^
    "$prevYear = %PREV_YEAR%; $prevMonth = %PREV_MONTH%;" ^
    "$daysInMonth = [DateTime]::DaysInMonth($prevYear, $prevMonth);" ^
    "$logsDir = '%DASHBOARD_DIR%\logs';" ^
    "$bpqDir = '%BPQ_LOGS_DIR%';" ^
    "$target = '%MONTHLY_TEMP%\logs';" ^
    "$count = 0;" ^
    "for ($day = 1; $day -le $daysInMonth; $day++) {" ^
    "    $d = Get-Date -Year $prevYear -Month $prevMonth -Day $day;" ^
    "    $yy = $d.ToString('yyMMdd');" ^
    "    $yyyy = $d.ToString('yyyyMMdd');" ^
    "    $patterns = @(" ^
    "        \"log_${yy}_BBS.txt\"," ^
    "        \"log_${yy}_VARA.txt\"," ^
    "        \"log_${yy}_TCP.txt\"," ^
    "        \"CMSAccess_${yyyy}.log\"," ^
    "        \"CMSAccess_${yyyy}.txt\"" ^
    "    );" ^
    "    foreach ($pat in $patterns) {" ^
    "        $src = Join-Path $logsDir $pat;" ^
    "        if (Test-Path $src) { Copy-Item $src $target -ErrorAction SilentlyContinue; $count++ }" ^
    "        if ($bpqDir -ne $logsDir) {" ^
    "            $src2 = Join-Path $bpqDir $pat;" ^
    "            if (Test-Path $src2) {" ^
    "                $dest = Join-Path $target $pat;" ^
    "                if (-not (Test-Path $dest)) { Copy-Item $src2 $target -ErrorAction SilentlyContinue; $count++ }" ^
    "            }" ^
    "        }" ^
    "    }" ^
    "}" ^
    "Write-Host \"  Collected $count log files for %MONTH_NAME%\""

REM Collect dashboard data snapshot
echo Collecting dashboard data snapshot...

set MONTHLY_DATA=0
if exist "%DASHBOARD_DIR%\data\messages\messages.json" (
    copy "%DASHBOARD_DIR%\data\messages\messages.json" "%MONTHLY_TEMP%\data\messages\" >nul
    set /a MONTHLY_DATA+=1
)
if exist "%DASHBOARD_DIR%\data\messages\folders.json" (
    copy "%DASHBOARD_DIR%\data\messages\folders.json" "%MONTHLY_TEMP%\data\messages\" >nul
    set /a MONTHLY_DATA+=1
)
if exist "%DASHBOARD_DIR%\data\messages\addresses.json" (
    copy "%DASHBOARD_DIR%\data\messages\addresses.json" "%MONTHLY_TEMP%\data\messages\" >nul
    set /a MONTHLY_DATA+=1
)
if exist "%DASHBOARD_DIR%\logs\dashboard.log" (
    copy "%DASHBOARD_DIR%\logs\dashboard.log" "%MONTHLY_TEMP%\logs\" >nul
    set /a MONTHLY_DATA+=1
)
if exist "%DASHBOARD_DIR%\data\stations\locations.json" (
    if not exist "%MONTHLY_TEMP%\data\stations\" mkdir "%MONTHLY_TEMP%\data\stations"
    copy "%DASHBOARD_DIR%\data\stations\*.json" "%MONTHLY_TEMP%\data\stations\" >nul
    set /a MONTHLY_DATA+=1
)
if exist "%DASHBOARD_DIR%\station-locations.json" (
    copy "%DASHBOARD_DIR%\station-locations.json" "%MONTHLY_TEMP%\data\" >nul
    set /a MONTHLY_DATA+=1
)

echo   Collected %MONTHLY_DATA% data files

REM Count total log files collected for monthly
set MONTHLY_LOG_COUNT=0
for /f %%A in ('dir /b "%MONTHLY_TEMP%\logs\log_*.*" "%MONTHLY_TEMP%\logs\CMSAccess_*.*" 2^>nul ^| find /c /v ""') do set MONTHLY_LOG_COUNT=%%A

REM Create monthly manifest
(
echo BPQ Dashboard Monthly Data Archive
echo ====================================
echo Archive Name: %MONTHLY_ARCHIVE%
echo Created: %DATE% %TIME%
echo Period: %PREV_YEAR%-%PREV_MONTH%-01 to %PREV_YEAR%-%PREV_MONTH%-%DAYS_IN_MONTH%
echo Month: %MONTH_NAME%
echo Days in month: %DAYS_IN_MONTH%
echo Computer: %COMPUTERNAME%
echo Dashboard Version: 1.4.0
echo.
echo Archive Contents
echo ----------------
echo Log files: %MONTHLY_LOG_COUNT% (full calendar month, all log types)
echo Data files: %MONTHLY_DATA% (snapshot at archive time)
echo.
echo Notes
echo -----
echo - FULL MONTH archive: contains every BBS/VARA/TCP/CMSAccess log for %MONTH_NAME%
echo - Weekly archives overlap with this data; monthly is the comprehensive record
echo - To restore: extract and copy files to appropriate directories
echo - Dashboard password is NOT included
) > "%MONTHLY_TEMP%\MANIFEST.txt"

REM Create monthly compressed archive
echo Creating monthly compressed archive...
powershell -NoProfile -Command ^
    "Compress-Archive -Path '%MONTHLY_TEMP%\*' -DestinationPath '%ARCHIVE_DIR%\monthly\%MONTHLY_ARCHIVE%.zip' -Force"

REM Report monthly size
for %%A in ("%ARCHIVE_DIR%\monthly\%MONTHLY_ARCHIVE%.zip") do set MONTHLY_SIZE=%%~zA
set /a MONTHLY_SIZE_KB=%MONTHLY_SIZE% / 1024
echo   Monthly archive created: %MONTHLY_ARCHIVE%.zip (%MONTHLY_SIZE_KB% KB)

REM Cleanup monthly temp
rmdir /s /q "%MONTHLY_TEMP%" 2>nul

echo.

REM ============================================================================
REM CLEANUP OLD ARCHIVES
REM ============================================================================

:cleanup

echo Checking for old archives to remove...

REM Weekly cleanup (keep last N)
set WEEKLY_COUNT=0
for %%F in ("%ARCHIVE_DIR%\weekly\*.zip") do set /a WEEKLY_COUNT+=1

if %WEEKLY_COUNT% GTR %RETENTION_WEEKS% (
    set /a DELETE_COUNT=%WEEKLY_COUNT% - %RETENTION_WEEKS%
    echo   Removing !DELETE_COUNT! old weekly archives...
    powershell -NoProfile -Command ^
        "Get-ChildItem '%ARCHIVE_DIR%\weekly\*.zip' | Sort-Object LastWriteTime | Select-Object -First (%WEEKLY_COUNT% - %RETENTION_WEEKS%) | Remove-Item -Force"
)

REM Monthly cleanup (keep last N)
set MONTHLY_COUNT=0
for %%F in ("%ARCHIVE_DIR%\monthly\*.zip") do set /a MONTHLY_COUNT+=1

if %MONTHLY_COUNT% GTR %RETENTION_MONTHS% (
    set /a MDELETE_COUNT=%MONTHLY_COUNT% - %RETENTION_MONTHS%
    echo   Removing !MDELETE_COUNT! old monthly archives...
    powershell -NoProfile -Command ^
        "Get-ChildItem '%ARCHIVE_DIR%\monthly\*.zip' | Sort-Object LastWriteTime | Select-Object -First (%MONTHLY_COUNT% - %RETENTION_MONTHS%) | Remove-Item -Force"
)

REM ============================================================================
REM SUMMARY
REM ============================================================================

echo.
echo ============================================
echo Archive Summary
echo ============================================

REM Recount after cleanup
set WEEKLY_COUNT=0
set MONTHLY_COUNT=0
for %%F in ("%ARCHIVE_DIR%\weekly\*.zip") do set /a WEEKLY_COUNT+=1
for %%F in ("%ARCHIVE_DIR%\monthly\*.zip") do set /a MONTHLY_COUNT+=1

echo Weekly archives:  %WEEKLY_COUNT% (retention: %RETENTION_WEEKS%)
echo Monthly archives: %MONTHLY_COUNT% (retention: %RETENTION_MONTHS%)
echo Archive location: %ARCHIVE_DIR%
echo.
echo Archive complete!
echo ============================================

REM If running interactively, pause
if "%1"=="" pause

endlocal
