@echo off
setlocal EnableExtensions EnableDelayedExpansion

set TARGET=trd-bots-hive

echo #INFO: opening log viewer in container %TARGET%
docker exec -it %TARGET% php /app/src/cli/log_view.php %*
exit /b %ERRORLEVEL%
