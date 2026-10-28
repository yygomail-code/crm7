@echo off
setlocal EnableExtensions
chcp 65001 >nul
cd /d "%~dp0"

set "NO_BROWSER="
set "DRY_RUN="

:parse_args
if "%~1"=="" goto :args_done
if /I "%~1"=="--no-browser" set "NO_BROWSER=1"
if /I "%~1"=="--dry-run" set "DRY_RUN=1"
shift
goto :parse_args

:args_done
echo CRM7 dev launcher
echo.

where php >nul 2>nul
if errorlevel 1 goto :missing_php

where node >nul 2>nul
if errorlevel 1 goto :missing_node

where npm >nul 2>nul
if errorlevel 1 goto :missing_node

where powershell >nul 2>nul
if errorlevel 1 goto :missing_powershell

if not exist "api\config\.env" goto :missing_env

if not exist "frontend\node_modules" (
  echo [INFO] frontend\node_modules not found. Installing dependencies...
  pushd frontend
  call npm install
  if errorlevel 1 (
    popd
    echo [ERROR] npm install failed.
    pause
    exit /b 1
  )
  popd
)

call :find_port 8080 API_PORT
if not defined API_PORT goto :no_api_port

call :find_port 5173 WEB_PORT
if not defined WEB_PORT goto :no_web_port

echo API port: %API_PORT%
echo Web port: %WEB_PORT%
echo.

if defined DRY_RUN goto :dry_run

start "CRM7 API :%API_PORT%" cmd /k php -S 127.0.0.1:%API_PORT% -d upload_max_filesize=12M -d post_max_size=12M -t api/public api/public/router.php
start "CRM7 WEB :%WEB_PORT%" cmd /k "set CRM_API_TARGET=http://127.0.0.1:%API_PORT%&& cd /d frontend&& npm run dev -- --port %WEB_PORT% --strictPort --host localhost"

echo Waiting for API...
powershell -NoProfile -ExecutionPolicy Bypass -Command "for($i=0;$i -lt 40;$i++){ try { $c=New-Object System.Net.Sockets.TcpClient; $c.Connect('127.0.0.1', %API_PORT%); $c.Close(); exit 0 } catch { Start-Sleep -Milliseconds 500 } }; exit 1"
if errorlevel 1 echo [WARN] API did not start yet.

echo Waiting for frontend...
powershell -NoProfile -ExecutionPolicy Bypass -Command "for($i=0;$i -lt 40;$i++){ try { $c=New-Object System.Net.Sockets.TcpClient; $c.Connect('localhost', %WEB_PORT%); $c.Close(); exit 0 } catch { Start-Sleep -Milliseconds 500 } }; exit 1"
if errorlevel 1 echo [WARN] Frontend did not start yet.

if not defined NO_BROWSER start "" "http://localhost:%WEB_PORT%/"

echo.
echo API:   http://127.0.0.1:%API_PORT%/api/v2
echo Web:   http://localhost:%WEB_PORT%/
echo Close the "CRM7 API" and "CRM7 WEB" windows to stop the servers.
echo.
pause
exit /b 0

:dry_run
echo [DRY RUN] php -S 127.0.0.1:%API_PORT% -d upload_max_filesize=12M -d post_max_size=12M -t api/public api/public/router.php
echo [DRY RUN] set CRM_API_TARGET=http://127.0.0.1:%API_PORT% ^&^& cd /d frontend ^&^& npm run dev -- --port %WEB_PORT% --strictPort --host localhost
if not defined NO_BROWSER echo [DRY RUN] open http://localhost:%WEB_PORT%/
exit /b 0

:find_port
set "%~2="
for /f "usebackq delims=" %%P in (`powershell -NoProfile -ExecutionPolicy Bypass -File "tools\free-port.ps1" %~1`) do set "%~2=%%P"
exit /b 0

:missing_php
echo [ERROR] PHP not found in PATH.
pause
exit /b 1

:missing_node
echo [ERROR] Node.js/npm not found in PATH.
pause
exit /b 1

:missing_powershell
echo [ERROR] PowerShell not found in PATH.
pause
exit /b 1

:missing_env
if exist "api\config\.env.example" (
  copy /Y "api\config\.env.example" "api\config\.env" >nul
  echo [WARN] Created api\config\.env from .env.example.
)
echo [ERROR] Configure DB_* and APP_KEY in api\config\.env, then run again.
pause
exit /b 1

:no_api_port
echo [ERROR] No free TCP port found for API (8080-8279).
pause
exit /b 1

:no_web_port
echo [ERROR] No free TCP port found for frontend (5173-5372).
pause
exit /b 1
