@echo off
setlocal EnableExtensions

call "%~dp0scripts\restart_hive.cmd" %*
exit /b %ERRORLEVEL%
