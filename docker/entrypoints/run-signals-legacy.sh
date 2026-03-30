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

mkdir -p /app/var/log /app/var/tmp /app/var/data

require_file /usr/local/etc/php/db_config.php
require_file /app/src/common.php
require_file /app/src/esctext.php
require_file /app/src/lib/db_tools.php
require_file "$SIGNALS_WEB_DOCROOT/sig_edit.php"
require_file "$SIGNALS_WEB_DOCROOT/api_helper.php"

cd "$SIGNALS_WEB_DOCROOT"
echo "Starting legacy signals PHP API at ${SIGNALS_BIND_HOST}:${SIGNALS_BIND_PORT}, docroot=${SIGNALS_WEB_DOCROOT}"
exec php -d include_path=".:$SIGNALS_WEB_DOCROOT:/app/src:/app/src/lib:/usr/local/lib/php" -S "${SIGNALS_BIND_HOST}:${SIGNALS_BIND_PORT}" -t "$SIGNALS_WEB_DOCROOT"
