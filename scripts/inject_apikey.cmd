@echo off
setlocal EnableExtensions EnableDelayedExpansion

set TARGET=trd-bots-hive

echo #INFO: opening API-key injector in container %TARGET%
docker exec -it %TARGET% php /app/src/cli/inject_apikey.php %*
exit /b %ERRORLEVEL%
