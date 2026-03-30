@echo off
chcp 65001 >nul
echo === Инжекция ключей API бирж (интерактивная) ===
echo.
echo Убедитесь, что:
echo 1. Финализировали Шаг A и Шаг B (оба скрипта завершились с SUCCESS)
echo 2. Создали бота с номером аккаунта через админку
echo.
powershell -ExecutionPolicy Bypass -File "%~dp0inject-api-keys-interactive.ps1" -ProjectRoot "%~dp0.."
if %errorlevel% neq 0 (
    echo.
    echo [ОШИБКА] Процесс завершился с ошибкой. Прочитайте сообщения выше.
)
pause
