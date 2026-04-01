#!/bin/sh
set -eu

WEB_DOCROOT="${WEB_DOCROOT:-/app/src/web-ui}"
WEB_BIND_HOST="${WEB_BIND_HOST:-0.0.0.0}"
WEB_BIND_PORT="${WEB_BIND_PORT:-80}"

require_file() {
  if [ ! -f "$1" ]; then
    echo "Missing required file: $1"
    return 1
  fi
  return 0
}

mkdir -p /app/var/log /app/var/tmp /app/var/data

if [ ! -f /app/src/lib/db_config.php ]; then
  echo "Missing /app/src/lib/db_config.php. Provide ./secrets/db_config.php on host."
  exit 1
fi

require_file /app/src/common.php
require_file /app/src/esctext.php
require_file /app/src/lib/db_tools.php
require_file /app/src/lib/auth_check.php
require_file /app/src/web-ui/api_helper.php
require_file "$WEB_DOCROOT/index.php"

cd "$WEB_DOCROOT"
echo "Starting PHP API/Web UI server at ${WEB_BIND_HOST}:${WEB_BIND_PORT}, docroot=${WEB_DOCROOT}"
exec php -d include_path=".:/app/src/web-ui:/app/src:/app/src/lib:/usr/local/lib/php" -S "${WEB_BIND_HOST}:${WEB_BIND_PORT}" -t "$WEB_DOCROOT" "$WEB_DOCROOT/router.php"
