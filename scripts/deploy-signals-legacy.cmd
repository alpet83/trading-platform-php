@echo off
setlocal EnableExtensions

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy-signals-legacy.ps1" %*
exit /b %ERRORLEVEL%
