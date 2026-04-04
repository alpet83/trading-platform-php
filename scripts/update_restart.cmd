@echo off
:: update_restart.cmd — Extract the working override copy from the web container,
:: back up the active docker-compose.override.yml, then apply and restart.
:: Run from the project root directory on the Docker host.
setlocal EnableDelayedExpansion

set COMPOSE_FILE=docker-compose.override.yml
set BACKUP_DIR=var\log\override-backups
set CONTAINER_PATH=web:/app/var/data/sys-config/docker-compose.override.yml

:: Move to project root (parent of scripts\)
cd /d "%~dp0.."
echo [update_restart] Project dir: %CD%

:: Verify the container is running
docker compose ps web | find "Up" >nul 2>&1
if errorlevel 1 (
    echo [update_restart] ERROR: web container is not running. Cannot extract working copy.
    exit /b 1
)

:: Create backup directory
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

:: Back up current override file if it exists
if exist "%COMPOSE_FILE%" (
    for /f "tokens=1-3 delims=/-" %%A in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do (
        set STAMP=%%A
    )
    set STAMP_FULL=
    for /f %%T in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set STAMP_FULL=%%T
    set BACKUP_PATH=%BACKUP_DIR%\%COMPOSE_FILE%.!STAMP_FULL!
    copy "%COMPOSE_FILE%" "!BACKUP_PATH!" >nul
    echo [update_restart] Backed up current override to: !BACKUP_PATH!
)

:: Extract working copy from container
echo [update_restart] Extracting working copy from container...
docker compose cp %CONTAINER_PATH% .\%COMPOSE_FILE%
if errorlevel 1 (
    echo [update_restart] ERROR: docker compose cp failed.
    exit /b 1
)
echo [update_restart] Extracted to: .\%COMPOSE_FILE%

:: Validate that the extracted file is non-empty
for %%F in ("%COMPOSE_FILE%") do (
    if %%~zF==0 (
        echo [update_restart] ERROR: Extracted file is empty. Aborting restart.
        exit /b 1
    )
)

:: Apply: restart affected services
echo [update_restart] Running docker compose up -d ...
docker compose up -d
if errorlevel 1 (
    echo [update_restart] ERROR: docker compose up -d failed.
    exit /b 1
)
echo [update_restart] Done.
