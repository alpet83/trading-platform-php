#!/usr/bin/env bash
set -euo pipefail

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"
PRIMARY_HOST="${PRIMARY_HOST:?PRIMARY_HOST is required}"
PRIMARY_PORT="${PRIMARY_PORT:-3306}"
REPL_USER="${REPL_USER:-repl}"
REPL_PASSWORD="${REPL_PASSWORD:?REPL_PASSWORD is required}"

MYSQL=(mariadb -h"$MYSQL_HOST" -P"$MYSQL_PORT" -uroot -p"$MYSQL_ROOT_PASSWORD" --batch --skip-column-names)

echo "[configure-replica] stop slave if running"
"${MYSQL[@]}" -e "STOP SLAVE;" || true

echo "[configure-replica] configure GTID replica channel"
"${MYSQL[@]}" -e "
CHANGE MASTER TO
  MASTER_HOST='${PRIMARY_HOST}',
  MASTER_PORT=${PRIMARY_PORT},
  MASTER_USER='${REPL_USER}',
  MASTER_PASSWORD='${REPL_PASSWORD}',
  MASTER_USE_GTID=slave_pos;
"

echo "[configure-replica] start slave"
"${MYSQL[@]}" -e "START SLAVE;"

echo "[configure-replica] replication status"
"${MYSQL[@]}" -e "SHOW SLAVE STATUS\\G"
