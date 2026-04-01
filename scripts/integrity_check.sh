#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_root"

containers=("trd-web" "trd-datafeed" "trd-bots-hive" "sigsys")
if [[ "$#" -gt 0 ]]; then
  containers=("$@")
fi

failed=0
for c in "${containers[@]}"; do
  if ! docker ps --format '{{.Names}}' | grep -qx "$c"; then
    echo "#INTEGRITY: skip $c (not running)"
    continue
  fi

  roots=("/app/src")
  if [[ "$c" == "sigsys" ]]; then
    roots=("/app/signals-server" "/app/src")
  fi

  echo "#INTEGRITY: checking $c"
  php_log_file="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" | sed -n 's/^PHP_ERROR_LOG_FILE=//p' | head -n1)"
  if [[ -n "$php_log_file" ]]; then
    echo "#INTEGRITY: $c PHP_ERROR_LOG_FILE=$php_log_file"
  else
    echo "#INTEGRITY: $c PHP_ERROR_LOG_FILE is not set (using container default)"
  fi
  if ! docker exec "$c" sh /usr/local/bin/integrity_check.sh "${roots[@]}"; then
    failed=1
  fi
  echo
 done

exit "$failed"
