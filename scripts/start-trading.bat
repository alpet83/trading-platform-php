@echo off
chcp 65001 >nul
echo === Запуск торговой группы (Сборка 2) ===
echo.
powershell -ExecutionPolicy Bypass -File "%~dp0deploy-simple.ps1" -ProjectRoot "%~dp0.."
if %errorlevel% neq 0 (
    echo.
    echo [ОШИБКА] Процесс завершился с ошибкой. Прочитайте сообщения выше.
)
pause
