@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "SCRIPT_DIR=%~dp0"
for %%I in ("%SCRIPT_DIR%..") do set "ROOT_DIR=%%~fI"

if "%HIVE_SERVICE%"=="" (
    set "SERVICE_NAME=bots-hive"
) else (
    set "SERVICE_NAME=%HIVE_SERVICE%"
)

if "%COMPOSE_FILE%"=="" (
    set "COMPOSE_FILE_PATH=docker-compose.yml"
) else (
    set "COMPOSE_FILE_PATH=%COMPOSE_FILE%"
)

if "%LOG_TAIL%"=="" (
    set "LOG_TAIL=120"
)

if "%WAIT_SECONDS%"=="" (
    set "WAIT_SECONDS=30"
)

set "PROJECT_NAME=%COMPOSE_PROJECT_NAME%"
if "%PROJECT_NAME%"=="" (
    if exist "%ROOT_DIR%\.env" (
        for /f "usebackq tokens=1,* delims==" %%A in (`findstr /r /c:"^COMPOSE_PROJECT_NAME=" "%ROOT_DIR%\.env"`) do set "PROJECT_NAME=%%B"
    )
)
if "%PROJECT_NAME%"=="" set "PROJECT_NAME=trd"

if "%HIVE_CONTAINER%"=="" (
    set "CONTAINER_NAME=%PROJECT_NAME%-bots-hive"
) else (
    set "CONTAINER_NAME=%HIVE_CONTAINER%"
)

set "EXCHANGE=%~1"
if "%EXCHANGE%"=="" set "EXCHANGE=<exchange>"

cd /d "%ROOT_DIR%"

echo #INFO: service = %SERVICE_NAME%
echo #INFO: container = %CONTAINER_NAME%

echo #STEP: restarting hive service
docker compose -f "%COMPOSE_FILE_PATH%" restart "%SERVICE_NAME%"
if errorlevel 1 (
    echo #WARN: docker compose restart failed, trying docker-compose
    docker-compose -f "%COMPOSE_FILE_PATH%" restart "%SERVICE_NAME%"
    if errorlevel 1 (
        echo #ERROR: failed to restart service
        exit /b 1
    )
)

echo #STEP: waiting for running container
set /a I=0
:wait_loop
set "STATE=missing"
for /f %%S in ('docker inspect -f "{{.State.Status}}" "%CONTAINER_NAME%" 2^>nul') do set "STATE=%%S"
if /i "%STATE%"=="running" goto wait_ok
set /a I+=1
if %I% GEQ %WAIT_SECONDS% goto wait_fail
timeout /t 1 /nobreak >nul
goto wait_loop

:wait_ok
echo #OK: container is running

echo #STEP: compose ps
docker compose -f "%COMPOSE_FILE_PATH%" ps "%SERVICE_NAME%" >nul 2>&1
docker compose -f "%COMPOSE_FILE_PATH%" ps "%SERVICE_NAME%"
if errorlevel 1 docker-compose -f "%COMPOSE_FILE_PATH%" ps "%SERVICE_NAME%"

echo #STEP: tail logs (%LOG_TAIL% lines)
docker logs --tail %LOG_TAIL% "%CONTAINER_NAME%"

echo #STEP: quick market maker signal check
docker logs --tail 400 "%CONTAINER_NAME%" 2>&1 | findstr /r "#MM BLOCK_MM_EXEC max_exec_cost" >nul
if errorlevel 1 (
    echo #WARN: no market maker markers in recent logs
    echo #HINT: if table %EXCHANGE%__mm_config is empty, market maker is not enabled
) else (
    echo #OK: market maker markers found in recent logs
)

echo #DONE: hive restart completed
exit /b 0

:wait_fail
echo #ERROR: container did not become running in %WAIT_SECONDS%s
docker ps -a --filter "name=%CONTAINER_NAME%"
exit /b 1
