@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"

where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Node.js not found in PATH.
  pause
  exit /b 1
)

node "tools\commit-push.mjs" %*
set "CODE=%ERRORLEVEL%"

echo.
if not "%CODE%"=="0" (
  echo Commit/push failed. Exit code: %CODE%.
) else (
  echo Done.
)

pause
exit /b %CODE%
