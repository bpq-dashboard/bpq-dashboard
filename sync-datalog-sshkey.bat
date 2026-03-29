@echo off
REM ============================================
REM DataLog Sync Script for WinSCP (SSH Key Version)
REM More secure - uses SSH key instead of password
REM SSH Port: 2222
REM ============================================

REM Configuration
set REMOTE_USER=pi
set REMOTE_HOST=10.0.0.82
set REMOTE_PORT=2222
set REMOTE_PATH=/var/www/tprfn/logs/
set LOCAL_PATH=C:\Program Files (x86)\WN-2m Version 2.4\
set SSH_KEY=C:\Keys\linux-server.ppk

REM Path to WinSCP
set WINSCP="C:\Program Files (x86)\WinSCP\WinSCP.com"

REM Create log directory if it doesn't exist
if not exist "C:\Logs" mkdir "C:\Logs"

REM Run WinSCP with SSH key on port 2222
%WINSCP% ^
  /log="C:\Logs\winscp-datalog.log" /ini=nul ^
  /command ^
    "open sftp://%REMOTE_USER%@%REMOTE_HOST%:%REMOTE_PORT%/ -privatekey=""%SSH_KEY%"" -hostkey=*" ^
    "cd %REMOTE_PATH%" ^
    "lcd ""%LOCAL_PATH%""" ^
    "put DataLog*.txt" ^
    "exit"

if %ERRORLEVEL% neq 0 (
    echo ERROR: File transfer failed! Check C:\Logs\winscp-datalog.log
    exit /b 1
) else (
    echo SUCCESS: DataLog files synced at %date% %time%
)
