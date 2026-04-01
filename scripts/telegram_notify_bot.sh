#!/usr/bin/env bash
set -euo pipefail

SERVICE="${1:-signals-legacy}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"

random_token() {
  local alphabet="abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
  local token=""
  local i idx
  for ((i = 0; i < 48; i++)); do
    idx=$((RANDOM % ${#alphabet}))
    token+="${alphabet:idx:1}"
  done
  printf '%s' "$token"
}

TOKEN="$(random_token)"
echo "Using random TELEGRAM_API_KEY length=${#TOKEN}"

cd "$REPO_ROOT"
export MSYS_NO_PATHCONV=1
docker compose \
  -f docker-compose.yml \
  -f docker-compose.override.yml \
  -f docker-compose.signals-legacy.yml \
  exec -T \
  -e "TELEGRAM_API_KEY=$TOKEN" \
  -e "TELEGRAM_API_TOKEN_FILE=/tmp/telegram-token-not-set" \
  -e "BOT_SERVER_HOST=bot" \
  "$SERVICE" \
  php /app/signals-server/trade_ctrl_bot.php

EXIT_CODE=$?
echo "telegram_notify_bot.sh exit code: $EXIT_CODE"
exit $EXIT_CODE