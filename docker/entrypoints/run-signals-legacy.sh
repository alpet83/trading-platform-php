#!/bin/sh
set -eu

SIGNALS_WEB_DOCROOT="${SIGNALS_WEB_DOCROOT:-/app/signals-server}"
SIGNALS_BIND_HOST="${SIGNALS_BIND_HOST:-0.0.0.0}"
SIGNALS_BIND_PORT="${SIGNALS_BIND_PORT:-8081}"

require_file() {
  if [ ! -f "$1" ]; then
    echo "Missing required file: $1"
    return 1
  fi
  return 0
}

ensure_telegram_sdk() {
  sdk_dir="$SIGNALS_WEB_DOCROOT/telegram-bot"
  autoload_file="$sdk_dir/vendor/autoload.php"
  manifest_file="$sdk_dir/composer.json"

  if [ -f "$autoload_file" ]; then
    return 0
  fi

  if [ ! -f "$manifest_file" ]; then
    return 0
  fi

  if ! command -v composer >/dev/null 2>&1; then
    echo "#WARN: composer is not available, Telegram SDK autoload is missing"
    return 0
  fi

  echo "#INFO: Telegram SDK autoload missing, running composer install in $sdk_dir"
  if (
    cd "$sdk_dir"
    composer install --no-dev --no-interaction --prefer-dist
  ); then
    return 0
  fi

  echo "#WARN: composer install failed, retrying with --ignore-platform-reqs"
  if (
    cd "$sdk_dir"
    composer install --no-dev --no-interaction --prefer-dist --ignore-platform-reqs
  ); then
    return 0
  fi

  echo "#WARN: Telegram SDK dependencies could not be installed; continuing without autoload"
  return 0
}

mkdir -p /app/var/log /app/var/tmp /app/var/data

require_file /usr/local/etc/php/db_config.php
require_file /app/src/common.php
require_file /app/src/esctext.php
require_file /app/src/lib/db_tools.php
require_file "$SIGNALS_WEB_DOCROOT/sig_edit.php"
require_file "$SIGNALS_WEB_DOCROOT/api_helper.php"
require_file "$SIGNALS_WEB_DOCROOT/router.php"

ensure_telegram_sdk

cd "$SIGNALS_WEB_DOCROOT"
echo "Starting legacy signals PHP API at ${SIGNALS_BIND_HOST}:${SIGNALS_BIND_PORT}, docroot=${SIGNALS_WEB_DOCROOT}"
exec php -d include_path=".:$SIGNALS_WEB_DOCROOT:/app/src:/app/src/lib:/usr/local/lib/php" -S "${SIGNALS_BIND_HOST}:${SIGNALS_BIND_PORT}" -t "$SIGNALS_WEB_DOCROOT" "$SIGNALS_WEB_DOCROOT/router.php"
