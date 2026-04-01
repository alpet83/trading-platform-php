param(
    [string]$Service = "signals-legacy"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

function New-RandomToken {
    param([int]$Length = 48)
    $alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
    $chars = 1..$Length | ForEach-Object { $alphabet[(Get-Random -Minimum 0 -Maximum $alphabet.Length)] }
    return -join $chars
}

$token = New-RandomToken
Write-Host "Using random TELEGRAM_API_KEY length=$($token.Length)"

$composeArgs = @(
    "-f", "docker-compose.yml",
    "-f", "docker-compose.override.yml",
    "-f", "docker-compose.signals-legacy.yml",
    "exec", "-T",
    "-e", "TELEGRAM_API_KEY=$token",
    "-e", "TELEGRAM_API_TOKEN_FILE=/tmp/telegram-token-not-set",
    "-e", "BOT_SERVER_HOST=bot",
    $Service,
    "php", "/app/signals-server/trade_ctrl_bot.php"
)

& docker compose @composeArgs
$exitCode = $LASTEXITCODE
Write-Host "telegram_notify_bot.ps1 exit code: $exitCode"
exit $exitCode