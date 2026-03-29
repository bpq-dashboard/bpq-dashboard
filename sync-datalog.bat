@echo off
REM ============================================
REM DataLog Sync Script for WinSCP
REM Syncs DataLog*.txt from WN-2m to Linux server
REM ============================================

REM Set your credentials here
set REMOTE_USER=pi
set REMOTE_HOST=10.0.0.82
set REMOTE_PATH=/var/www/tprfn/logs/
set LOCAL_PATH=C:\Program Files (x86)\WN-2m Version 2.4\

REM Path to WinSCP (adjust if installed elsewhere)
set WINSCP="C:\Program Files (x86)\WinSCP\WinSCP.com"

REM Run WinSCP with script commands
%WINSCP% ^
  /log="C:\Logs\winscp-datalog.log" /ini=nul ^
  /command ^
    "open sftp://%REMOTE_USER%@%REMOTE_HOST%/ -hostkey=*" ^
    "cd %REMOTE_PATH%" ^
    "lcd ""%LOCAL_PATH%""" ^
    "put DataLog*.txt" ^
    "exit"

REM Check if successful
if %ERRORLEVEL% neq 0 (
    echo ERROR: File transfer failed!
    exit /b 1
) else (
    echo SUCCESS: DataLog files synced to %REMOTE_HOST%
)
