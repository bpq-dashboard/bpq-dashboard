@echo off
REM ============================================
REM BPQ32 Log Sync Script
REM Copies BPQ32 logs to web server for dashboard
REM Schedule to run every 5 minutes via Task Scheduler
REM ============================================

REM ============================================
REM CONFIGURATION
REM ============================================
REM Uses %APPDATA% which automatically finds your user folder
REM No need to edit this unless BPQ32 is installed elsewhere
set BPQ_LOGS=%APPDATA%\BPQ32\Logs
set WEB_LOGS=C:\UniServerZ\www\bpq\logs
REM ============================================

REM Create web logs directory if it doesn't exist
if not exist "%WEB_LOGS%" mkdir "%WEB_LOGS%"

REM Copy BBS logs (for Traffic Report, Email Monitor, System Logs)
copy /Y "%BPQ_LOGS%\Log_*_BBS.txt" "%WEB_LOGS%\" >nul 2>&1

REM Copy TCP logs (for System Logs connection tracking)
copy /Y "%BPQ_LOGS%\Log_*_TCP.txt" "%WEB_LOGS%\" >nul 2>&1

REM Copy CMS Access logs (for Winlink/RMS Gateway tracking)
copy /Y "%BPQ_LOGS%\CMSAccess*.log" "%WEB_LOGS%\" >nul 2>&1

REM Copy MHeard stations file (for RF Connections)
if exist "%BPQ_LOGS%\MHSave.txt" copy /Y "%BPQ_LOGS%\MHSave.txt" "%WEB_LOGS%\" >nul 2>&1

REM Copy Known stations routing table (for RF Connections)
if exist "%BPQ_LOGS%\RTKnown.txt" copy /Y "%BPQ_LOGS%\RTKnown.txt" "%WEB_LOGS%\" >nul 2>&1

REM Copy node RTT data (optional - for System Logs)
if exist "%BPQ_LOGS%\nodesrtt.txt" copy /Y "%BPQ_LOGS%\nodesrtt.txt" "%WEB_LOGS%\" >nul 2>&1

REM Copy VARA log if it exists
if exist "%BPQ_LOGS%\*.vara" copy /Y "%BPQ_LOGS%\*.vara" "%WEB_LOGS%\" >nul 2>&1

REM Copy Connect logs (for Connect Log page - connection mode tracking)
copy /Y "%BPQ_LOGS%\ConnectLog*.log" "%WEB_LOGS%\" >nul 2>&1

echo BPQ32 logs synced at %date% %time%
