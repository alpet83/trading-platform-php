#!/bin/sh
set -eu

require_file() {
  if [ ! -f "$1" ]; then
    echo "Missing required file: $1"
    return 1
  fi
  return 0
}

mkdir -p /app/var/log /app/var/tmp /app/var/data /app/datafeed/src/logs

if [ ! -e /app/src/logs ]; then
  ln -s /app/var/log /app/src/logs
fi

if [ ! -e /app/src/data ]; then
  ln -s /app/var/data /app/src/data
fi

require_file /app/datafeed/src/datafeed_manager.php
require_file /app/datafeed/lib/db_config.php

cd /app/src
exec php /app/datafeed/src/datafeed_manager.php
