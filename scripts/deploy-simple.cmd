@echo off
setlocal EnableExtensions

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy-simple.ps1" %*
exit /b %ERRORLEVEL%
