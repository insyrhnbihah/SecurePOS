@echo off
setlocal
title SecurePOS FYP Demo Launcher
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-SecurePOS-Demo.ps1"
set "SECUREPOS_EXIT=%ERRORLEVEL%"
echo.
if not "%SECUREPOS_EXIT%"=="0" echo Review the message above, then try again.
pause
exit /b %SECUREPOS_EXIT%
