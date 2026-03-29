@echo off
REM NWS Alert Monitor - Windows Batch Wrapper
REM Usage:
REM   nws-monitor.bat              - Run single fetch
REM   nws-monitor.bat -Daemon      - Run continuously
REM   nws-monitor.bat -Config      - Show configuration
REM   nws-monitor.bat -Install     - Install as scheduled task

echo.
echo NWS Alert Monitor for BPQ BBS
echo.

powershell -ExecutionPolicy Bypass -File "%~dp0nws-monitor.ps1" %*
