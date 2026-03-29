#!/usr/bin/env bash
set -euo pipefail

# Enforces role policy to avoid split-brain writes.
# ROLE=primary  -> read_only=OFF
# ROLE=standby  -> read_only=ON and keep replica attached to primary

ROLE="${ROLE:?ROLE must be primary or standby}"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"

MYSQL=(mariadb -h"$MYSQL_HOST" -P"$MYSQL_PORT" -uroot -p"$MYSQL_ROOT_PASSWORD" --batch --skip-column-names)

case "$ROLE" in
  primary)
    echo "[single-writer] setting writable primary"
    "${MYSQL[@]}" -e "SET GLOBAL read_only=OFF;"
    ;;
  standby)
    echo "[single-writer] setting readonly standby"
    "${MYSQL[@]}" -e "SET GLOBAL read_only=ON;"

    if [[ -n "${PRIMARY_HOST:-}" && -n "${REPL_PASSWORD:-}" ]]; then
      "$(dirname "$0")/auto-rejoin.sh"
    else
      echo "[single-writer] PRIMARY_HOST/REPL_PASSWORD not set; skip auto-rejoin"
    fi
    ;;
  *)
    echo "[single-writer] invalid ROLE=$ROLE (expected primary|standby)" >&2
    exit 1
    ;;
esac
