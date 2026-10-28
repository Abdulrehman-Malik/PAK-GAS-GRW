@echo off
rem Double-click to build installer\output\PerfectLPG-Setup-<version>.exe. It asks for the version
rem and release notes (see version.ini) and checks the prerequisites first.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0build.ps1"
echo.
pause
