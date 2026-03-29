@echo off
REM ============================================================================
REM BPQ Dashboard - Windows Deployment Script
REM ============================================================================
REM
REM Deploys the BPQ Dashboard suite to a Windows web server
REM
REM Components:
REM   - RF Connections Dashboard (bpq-rf-connections.html)
REM   - System Logs Dashboard (bpq-system-logs.html)
REM   - Traffic Statistics Dashboard (bpq-traffic.html)
REM   - Email Monitor Dashboard (bpq-email-monitor.html)
REM   - VARA Analysis Tools
REM
REM Recommended Web Server:
REM   Uniform Server (free, lightweight): https://www.uniformserver.com/
REM
REM ============================================================================

setlocal enabledelayedexpansion

title BPQ Dashboard Deployment

REM Colors via escape codes (Windows 10+)
set "GREEN=[92m"
set "RED=[91m"
set "YELLOW=[93m"
set "BLUE=[94m"
set "CYAN=[96m"
set "NC=[0m"

REM Default settings
set "WEB_ROOT="
set "BPQ_DIR=bpq"
set "INSTALL_DIR="
set "VARA_URL=https://tprfn.k1ajd.net/callsign.txt"

REM ============================================================================
REM Main
REM ============================================================================

echo.
echo %CYAN%=======================================================================%NC%
echo %CYAN%                    BPQ Dashboard Deployment                          %NC%
echo %CYAN%                                                                       %NC%
echo %CYAN%     RF Connections - System Logs - Traffic Stats - Email             %NC%
echo %CYAN%=======================================================================%NC%
echo.

REM Detect web server
call :detect_web_server

if "%WEB_ROOT%"=="" (
    echo.
    echo %RED%=======================================================================%NC%
    echo %RED%                    WEB SERVER REQUIRED                               %NC%
    echo %RED%=======================================================================%NC%
    echo.
    echo BPQ Dashboard requires a web server to function.
    echo.
    echo %CYAN%=======================================================================%NC%
    echo %CYAN%  RECOMMENDED: Uniform Server (Free ^& Lightweight)                   %NC%
    echo %CYAN%                                                                       %NC%
    echo %CYAN%  Download: https://www.uniformserver.com/                             %NC%
    echo %CYAN%                                                                       %NC%
    echo %CYAN%  * Portable - no installation required                               %NC%
    echo %CYAN%  * Lightweight - minimal resource usage                              %NC%
    echo %CYAN%  * Easy to use - just extract and run                                %NC%
    echo %CYAN%  * Perfect for amateur radio applications                            %NC%
    echo %CYAN%=======================================================================%NC%
    echo.
    echo %YELLOW%Quick Start with Uniform Server:%NC%
    echo   1. Download UniServerZ from https://www.uniformserver.com/
    echo   2. Extract to C:\UniServerZ
    echo   3. Run UniServerZ\UniController.exe
    echo   4. Click "Start Apache"
    echo   5. Run this script again
    echo.
    echo Alternative: XAMPP ^(https://www.apachefriends.org/^)
    echo.
    set /p WEB_ROOT="Or enter web server root path manually: "
)

if "%WEB_ROOT%"=="" (
    echo %RED%[ERROR]%NC% No web root specified. Exiting.
    echo.
    echo Please install Uniform Server from https://www.uniformserver.com/
    pause
    exit /b 1
)

set "INSTALL_DIR=%WEB_ROOT%\%BPQ_DIR%"

echo.
echo %BLUE%[INFO]%NC% Installation Settings:
echo        Web Root: %WEB_ROOT%
echo        Install Dir: %INSTALL_DIR%
echo.

set /p CONFIRM="Proceed with installation? [Y/n]: "
if /i "%CONFIRM%"=="n" (
    echo Installation cancelled.
    pause
    exit /b 0
)

REM Create directories
call :create_directories

REM Copy files
call :copy_files

REM Fetch VARA callsign list
call :fetch_vara_callsigns

REM Create VARA update script
call :create_vara_update_script

REM Create index page
call :create_index

echo.
echo %GREEN%=======================================================================%NC%
echo %GREEN%                    Installation Complete!                            %NC%
echo %GREEN%=======================================================================%NC%
echo.
echo Dashboard URL: http://localhost/%BPQ_DIR%/
echo.
echo Installed Dashboards:
echo   - RF Connections:  http://localhost/%BPQ_DIR%/bpq-rf-connections.html
echo   - System Logs:     http://localhost/%BPQ_DIR%/bpq-system-logs.html
echo   - Traffic Stats:   http://localhost/%BPQ_DIR%/bpq-traffic.html
echo   - Email Monitor:   http://localhost/%BPQ_DIR%/bpq-email-monitor.html
echo.
echo VARA Callsign Data:
if exist "%INSTALL_DIR%\data\callsign.vara" (
    echo   - %INSTALL_DIR%\data\callsign.vara
) else (
    echo   - Not downloaded (run update-vara-callsigns.bat to fetch)
)
echo.
echo To update VARA callsigns, run:
echo   %INSTALL_DIR%\scripts\update-vara-callsigns.bat
echo.
echo %YELLOW%Note: Edit each HTML file to configure your BPQ connection settings.%NC%
echo.

pause
exit /b 0

REM ============================================================================
REM Functions
REM ============================================================================

:detect_web_server
echo %BLUE%[INFO]%NC% Detecting web server...

REM Check for Uniform Server (UniServerZ) - Primary recommendation
if exist "C:\UniServerZ\www" (
    set "WEB_ROOT=C:\UniServerZ\www"
    echo %GREEN%[OK]%NC% Found Uniform Server at C:\UniServerZ\www
    goto :eof
)

if exist "D:\UniServerZ\www" (
    set "WEB_ROOT=D:\UniServerZ\www"
    echo %GREEN%[OK]%NC% Found Uniform Server at D:\UniServerZ\www
    goto :eof
)

REM Check for XAMPP
if exist "C:\xampp\htdocs" (
    set "WEB_ROOT=C:\xampp\htdocs"
    echo %GREEN%[OK]%NC% Found XAMPP at C:\xampp\htdocs
    goto :eof
)

REM Check for IIS default
if exist "C:\inetpub\wwwroot" (
    set "WEB_ROOT=C:\inetpub\wwwroot"
    echo %GREEN%[OK]%NC% Found IIS at C:\inetpub\wwwroot
    goto :eof
)

echo %YELLOW%[WARN]%NC% No web server auto-detected
goto :eof

:create_directories
echo %BLUE%[INFO]%NC% Creating directories...

if not exist "%INSTALL_DIR%" mkdir "%INSTALL_DIR%"
if not exist "%INSTALL_DIR%\logs" mkdir "%INSTALL_DIR%\logs"
if not exist "%INSTALL_DIR%\data" mkdir "%INSTALL_DIR%\data"
if not exist "%INSTALL_DIR%\scripts" mkdir "%INSTALL_DIR%\scripts"

echo %GREEN%[OK]%NC% Directories created
goto :eof

:copy_files
echo %BLUE%[INFO]%NC% Copying dashboard files...

set "SCRIPT_DIR=%~dp0"

REM Copy HTML dashboards
if exist "%SCRIPT_DIR%bpq-rf-connections.html" (
    copy /Y "%SCRIPT_DIR%bpq-rf-connections.html" "%INSTALL_DIR%\" >nul
    echo %GREEN%[OK]%NC% Copied bpq-rf-connections.html
) else (
    echo %YELLOW%[WARN]%NC% bpq-rf-connections.html not found
)

if exist "%SCRIPT_DIR%bpq-system-logs.html" (
    copy /Y "%SCRIPT_DIR%bpq-system-logs.html" "%INSTALL_DIR%\" >nul
    echo %GREEN%[OK]%NC% Copied bpq-system-logs.html
) else (
    echo %YELLOW%[WARN]%NC% bpq-system-logs.html not found
)

if exist "%SCRIPT_DIR%bpq-traffic.html" (
    copy /Y "%SCRIPT_DIR%bpq-traffic.html" "%INSTALL_DIR%\" >nul
    echo %GREEN%[OK]%NC% Copied bpq-traffic.html
) else (
    echo %YELLOW%[WARN]%NC% bpq-traffic.html not found
)

if exist "%SCRIPT_DIR%bpq-email-monitor.html" (
    copy /Y "%SCRIPT_DIR%bpq-email-monitor.html" "%INSTALL_DIR%\" >nul
    echo %GREEN%[OK]%NC% Copied bpq-email-monitor.html
) else (
    echo %YELLOW%[WARN]%NC% bpq-email-monitor.html not found
)

REM Copy favicon
if exist "%SCRIPT_DIR%favicon.svg" (
    copy /Y "%SCRIPT_DIR%favicon.svg" "%INSTALL_DIR%\" >nul
)

REM Copy PHP proxy
if exist "%SCRIPT_DIR%solar-proxy.php" (
    copy /Y "%SCRIPT_DIR%solar-proxy.php" "%INSTALL_DIR%\" >nul
)

REM Copy scripts
if exist "%SCRIPT_DIR%fetch-vara.bat" (
    copy /Y "%SCRIPT_DIR%fetch-vara.bat" "%INSTALL_DIR%\scripts\" >nul
    echo %GREEN%[OK]%NC% Copied fetch-vara.bat
)

if exist "%SCRIPT_DIR%vara-analysis.sh" (
    copy /Y "%SCRIPT_DIR%vara-analysis.sh" "%INSTALL_DIR%\scripts\" >nul
)

echo %GREEN%[OK]%NC% Files copied
goto :eof

:fetch_vara_callsigns
echo %BLUE%[INFO]%NC% Fetching VARA callsign list...

set "VARA_FILE=%INSTALL_DIR%\data\callsign.vara"

REM Try PowerShell first (most reliable on modern Windows)
powershell -Command "try { Invoke-WebRequest -Uri '%VARA_URL%' -OutFile '%VARA_FILE%' -TimeoutSec 30; exit 0 } catch { exit 1 }" 2>nul

if exist "%VARA_FILE%" (
    for %%A in ("%VARA_FILE%") do set "FILESIZE=%%~zA"
    if !FILESIZE! GTR 0 (
        echo %GREEN%[OK]%NC% Downloaded callsign.vara
        goto :eof
    )
)

REM Try curl if available
where curl >nul 2>&1
if %ERRORLEVEL%==0 (
    curl -s -o "%VARA_FILE%" --connect-timeout 30 "%VARA_URL%" 2>nul
    if exist "%VARA_FILE%" (
        echo %GREEN%[OK]%NC% Downloaded callsign.vara using curl
        goto :eof
    )
)

REM Try certutil as last resort
certutil -urlcache -split -f "%VARA_URL%" "%VARA_FILE%" >nul 2>&1
if exist "%VARA_FILE%" (
    echo %GREEN%[OK]%NC% Downloaded callsign.vara using certutil
    goto :eof
)

echo %YELLOW%[WARN]%NC% Could not download callsign list
echo        You can manually download from: %VARA_URL%
echo        Save as: %VARA_FILE%
goto :eof

:create_vara_update_script
echo %BLUE%[INFO]%NC% Creating VARA callsign update script...

(
echo @echo off
echo REM Update VARA callsign list from TPRFN
echo REM Run this script periodically to keep callsigns current
echo.
echo set "VARA_URL=https://tprfn.k1ajd.net/callsign.txt"
echo set "VARA_FILE=%%~dp0..\data\callsign.vara"
echo set "BACKUP_FILE=%%VARA_FILE%%.bak"
echo.
echo echo Updating VARA callsign list...
echo echo Source: %%VARA_URL%%
echo.
echo REM Backup existing file
echo if exist "%%VARA_FILE%%" copy /Y "%%VARA_FILE%%" "%%BACKUP_FILE%%" ^>nul
echo.
echo REM Download using PowerShell
echo powershell -Command "try { Invoke-WebRequest -Uri '%%VARA_URL%%' -OutFile '%%VARA_FILE%%' -TimeoutSec 30; Write-Host 'Success: Downloaded callsign list'; exit 0 } catch { Write-Host 'Error: Download failed'; exit 1 }"
echo.
echo if exist "%%VARA_FILE%%" (
echo     echo Saved to: %%VARA_FILE%%
echo ^) else (
echo     echo Download failed. Restoring backup...
echo     if exist "%%BACKUP_FILE%%" copy /Y "%%BACKUP_FILE%%" "%%VARA_FILE%%" ^>nul
echo ^)
echo.
echo pause
) > "%INSTALL_DIR%\scripts\update-vara-callsigns.bat"

echo %GREEN%[OK]%NC% Created update-vara-callsigns.bat
goto :eof

:create_index
echo %BLUE%[INFO]%NC% Creating index page...

(
echo ^<!DOCTYPE html^>
echo ^<html lang="en"^>
echo ^<head^>
echo     ^<meta charset="UTF-8"^>
echo     ^<meta name="viewport" content="width=device-width, initial-scale=1.0"^>
echo     ^<title^>BPQ Dashboard^</title^>
echo     ^<link rel="icon" type="image/svg+xml" href="favicon.svg"^>
echo     ^<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700^&family=JetBrains+Mono:wght@400;500^&display=swap" rel="stylesheet"^>
echo     ^<style^>
echo         * { margin: 0; padding: 0; box-sizing: border-box; }
echo         :root {
echo             --bg-primary: #0a0a0f;
echo             --bg-card: #12121a;
echo             --bg-card-hover: #1a1a24;
echo             --border-color: #2a2a3a;
echo             --text-primary: #ffffff;
echo             --text-secondary: #a0a0b0;
echo             --accent-red: #ff2d55;
echo             --accent-orange: #ff9500;
echo             --accent-green: #30d158;
echo             --accent-blue: #0a84ff;
echo             --accent-purple: #bf5af2;
echo         }
echo         body {
echo             font-family: 'JetBrains Mono', monospace;
echo             background: var(--bg-primary^);
echo             color: var(--text-primary^);
echo             min-height: 100vh;
echo             display: flex;
echo             flex-direction: column;
echo             align-items: center;
echo             justify-content: center;
echo             padding: 2rem;
echo         }
echo         .header { text-align: center; margin-bottom: 3rem; }
echo         .header h1 {
echo             font-family: 'Oswald', sans-serif;
echo             font-size: 3rem;
echo             font-weight: 700;
echo             letter-spacing: 2px;
echo             margin-bottom: 0.5rem;
echo         }
echo         .header h1 span { color: var(--accent-red^); }
echo         .header p { color: var(--text-secondary^); font-size: 0.9rem; }
echo         .dashboard-grid {
echo             display: grid;
echo             grid-template-columns: repeat(auto-fit, minmax(280px, 1fr^)^);
echo             gap: 1.5rem;
echo             max-width: 1200px;
echo             width: 100%%;
echo         }
echo         .dashboard-card {
echo             background: var(--bg-card^);
echo             border: 1px solid var(--border-color^);
echo             border-radius: 12px;
echo             padding: 1.5rem;
echo             text-decoration: none;
echo             color: inherit;
echo             transition: all 0.3s ease;
echo             position: relative;
echo             overflow: hidden;
echo         }
echo         .dashboard-card:hover {
echo             background: var(--bg-card-hover^);
echo             transform: translateY(-4px^);
echo             border-color: var(--accent-red^);
echo             box-shadow: 0 10px 40px rgba(255, 45, 85, 0.2^);
echo         }
echo         .dashboard-card::before {
echo             content: '';
echo             position: absolute;
echo             top: 0; left: 0;
echo             width: 4px; height: 100%%;
echo             background: var(--card-accent, var(--accent-red^)^);
echo         }
echo         .dashboard-card h2 {
echo             font-family: 'Oswald', sans-serif;
echo             font-size: 1.4rem;
echo             font-weight: 600;
echo             margin-bottom: 0.5rem;
echo             display: flex;
echo             align-items: center;
echo             gap: 0.75rem;
echo         }
echo         .dashboard-card h2 .icon { font-size: 1.5rem; }
echo         .dashboard-card p { color: var(--text-secondary^); font-size: 0.8rem; line-height: 1.5; }
echo         .card-rf { --card-accent: var(--accent-green^); }
echo         .card-logs { --card-accent: var(--accent-orange^); }
echo         .card-traffic { --card-accent: var(--accent-blue^); }
echo         .card-email { --card-accent: var(--accent-purple^); }
echo         .footer { margin-top: 3rem; text-align: center; color: var(--text-secondary^); font-size: 0.75rem; }
echo         .footer a { color: var(--accent-red^); text-decoration: none; }
echo     ^</style^>
echo ^</head^>
echo ^<body^>
echo     ^<div class="header"^>
echo         ^<h1^>BPQ ^<span^>Dashboard^</span^>^</h1^>
echo         ^<p^>Packet Radio Network Monitoring Suite^</p^>
echo     ^</div^>
echo     ^<div class="dashboard-grid"^>
echo         ^<a href="bpq-rf-connections.html" class="dashboard-card card-rf"^>
echo             ^<h2^>^<span class="icon"^>📡^</span^> RF Connections^</h2^>
echo             ^<p^>Real-time RF connection monitoring with signal quality metrics, node mapping, and connection history.^</p^>
echo         ^</a^>
echo         ^<a href="bpq-system-logs.html" class="dashboard-card card-logs"^>
echo             ^<h2^>^<span class="icon"^>📋^</span^> System Logs^</h2^>
echo             ^<p^>Live BPQ system log viewer with filtering, search, and automatic refresh capabilities.^</p^>
echo         ^</a^>
echo         ^<a href="bpq-traffic.html" class="dashboard-card card-traffic"^>
echo             ^<h2^>^<span class="icon"^>📊^</span^> Traffic Statistics^</h2^>
echo             ^<p^>Network traffic analysis with charts, throughput metrics, and historical data visualization.^</p^>
echo         ^</a^>
echo         ^<a href="bpq-email-monitor.html" class="dashboard-card card-email"^>
echo             ^<h2^>^<span class="icon"^>📧^</span^> Email Monitor^</h2^>
echo             ^<p^>BBS message queue monitoring with delivery status tracking and queue management.^</p^>
echo         ^</a^>
echo     ^</div^>
echo     ^<div class="footer"^>
echo         ^<p^>BPQ Dashboard ^&bull; ^<a href="https://github.com/g8bpq/linbpq" target="_blank"^>LinBPQ^</a^>^</p^>
echo     ^</div^>
echo ^</body^>
echo ^</html^>
) > "%INSTALL_DIR%\index.html"

echo %GREEN%[OK]%NC% Index page created
goto :eof
