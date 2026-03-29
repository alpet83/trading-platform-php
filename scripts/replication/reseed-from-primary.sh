#!/usr/bin/env bash
set -euo pipefail

# Reseed standby from primary to recover after long offline period.
# This assumes single-writer mode (primary is source of truth).

PRIMARY_HOST="${PRIMARY_HOST:?PRIMARY_HOST is required}"
PRIMARY_PORT="${PRIMARY_PORT:-3306}"
PRIMARY_USER="${PRIMARY_USER:-root}"
PRIMARY_PASSWORD="${PRIMARY_PASSWORD:?PRIMARY_PASSWORD is required}"

LOCAL_HOST="${LOCAL_HOST:-127.0.0.1}"
LOCAL_PORT="${LOCAL_PORT:-3306}"
LOCAL_ROOT_PASSWORD="${LOCAL_ROOT_PASSWORD:?LOCAL_ROOT_PASSWORD is required}"
TARGET_DB="${TARGET_DB:-trading}"

REPL_USER="${REPL_USER:-repl}"
REPL_PASSWORD="${REPL_PASSWORD:?REPL_PASSWORD is required}"

echo "[reseed] stopping local replication"
mariadb -h"$LOCAL_HOST" -P"$LOCAL_PORT" -uroot -p"$LOCAL_ROOT_PASSWORD" -e "STOP SLAVE; RESET SLAVE ALL;" || true

echo "[reseed] recreating local database $TARGET_DB"
mariadb -h"$LOCAL_HOST" -P"$LOCAL_PORT" -uroot -p"$LOCAL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \\`$TARGET_DB\\`; CREATE DATABASE \\`$TARGET_DB\\`;"

echo "[reseed] streaming dump from primary"
mariadb-dump \
  -h"$PRIMARY_HOST" -P"$PRIMARY_PORT" \
  -u"$PRIMARY_USER" -p"$PRIMARY_PASSWORD" \
  --single-transaction --routines --events --databases "$TARGET_DB" \
  | mariadb -h"$LOCAL_HOST" -P"$LOCAL_PORT" -uroot -p"$LOCAL_ROOT_PASSWORD"

echo "[reseed] re-attaching as replica"
mariadb -h"$LOCAL_HOST" -P"$LOCAL_PORT" -uroot -p"$LOCAL_ROOT_PASSWORD" <<SQL
CHANGE MASTER TO
  MASTER_HOST='${PRIMARY_HOST}',
  MASTER_PORT=${PRIMARY_PORT},
  MASTER_USER='${REPL_USER}',
  MASTER_PASSWORD='${REPL_PASSWORD}',
  MASTER_USE_GTID=slave_pos;
START SLAVE;
SQL

echo "[reseed] done; current slave status"
mariadb -h"$LOCAL_HOST" -P"$LOCAL_PORT" -uroot -p"$LOCAL_ROOT_PASSWORD" -e "SHOW SLAVE STATUS\\G"
