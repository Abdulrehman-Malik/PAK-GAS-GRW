@echo off
rem Double-click to issue a license key for one client computer (see keygen.ps1).
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0keygen.ps1"
echo.
pause
