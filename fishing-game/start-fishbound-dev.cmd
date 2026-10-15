@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\local-services.ps1" -Action Start -Target Dev
if errorlevel 1 (
  pause
  exit /b 1
)
