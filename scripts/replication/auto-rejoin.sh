#!/usr/bin/env bash
set -euo pipefail

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"
PRIMARY_HOST="${PRIMARY_HOST:?PRIMARY_HOST is required}"
PRIMARY_PORT="${PRIMARY_PORT:-3306}"
REPL_USER="${REPL_USER:-repl}"
REPL_PASSWORD="${REPL_PASSWORD:?REPL_PASSWORD is required}"
MAX_LAG_SECONDS="${MAX_LAG_SECONDS:-300}"

MYSQL=(mariadb -h"$MYSQL_HOST" -P"$MYSQL_PORT" -uroot -p"$MYSQL_ROOT_PASSWORD" --batch --skip-column-names)

status_value() {
  local key="$1"
  "${MYSQL[@]}" -e "SHOW SLAVE STATUS\\G" | awk -F': ' -v k="$key" '$1 ~ k {print $2; exit}' | tr -d '\r'
}

io_running="$(status_value 'Slave_IO_Running')"
sql_running="$(status_value 'Slave_SQL_Running')"
lag="$(status_value 'Seconds_Behind_Master')"

if [[ -z "$io_running" || -z "$sql_running" ]]; then
  echo "[auto-rejoin] slave status unavailable, trying to bootstrap channel"
  "${MYSQL[@]}" -e "
  CHANGE MASTER TO
    MASTER_HOST='${PRIMARY_HOST}',
    MASTER_PORT=${PRIMARY_PORT},
    MASTER_USER='${REPL_USER}',
    MASTER_PASSWORD='${REPL_PASSWORD}',
    MASTER_USE_GTID=slave_pos;
  START SLAVE;
  "
  exit 0
fi

if [[ "$io_running" != "Yes" || "$sql_running" != "Yes" ]]; then
  echo "[auto-rejoin] replication thread down (IO=${io_running}, SQL=${sql_running}), restarting"
  "${MYSQL[@]}" -e "STOP SLAVE; START SLAVE;"
  exit 0
fi

if [[ "$lag" == "NULL" || -z "$lag" ]]; then
  echo "[auto-rejoin] lag is NULL, nothing to do"
  exit 0
fi

if (( lag > MAX_LAG_SECONDS )); then
  echo "[auto-rejoin] lag=${lag}s exceeds ${MAX_LAG_SECONDS}s, rebinding channel"
  "${MYSQL[@]}" -e "
  STOP SLAVE;
  CHANGE MASTER TO
    MASTER_HOST='${PRIMARY_HOST}',
    MASTER_PORT=${PRIMARY_PORT},
    MASTER_USER='${REPL_USER}',
    MASTER_PASSWORD='${REPL_PASSWORD}',
    MASTER_USE_GTID=slave_pos;
  START SLAVE;
  "
else
  echo "[auto-rejoin] replication healthy (lag=${lag}s)"
fi
