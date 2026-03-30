@echo off
chcp 65001 >nul
echo === Запуск центра сигналов (Сборка 1) ===
echo.
powershell -ExecutionPolicy Bypass -File "%~dp0deploy-signals-legacy.ps1" -ProjectRoot "%~dp0.."
if %errorlevel% neq 0 (
    echo.
    echo [ОШИБКА] Процесс завершился с ошибкой. Прочитайте сообщения выше.
)
pause
