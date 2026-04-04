#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
BOT_NAME="${TEST_BOT_NAME:-bitmex_bot}"
ACCOUNT_A="${TEST_ACCOUNT_A:-1}"
ACCOUNT_B="${TEST_ACCOUNT_B:-2}"
START_WAIT_SECONDS="${TEST_START_WAIT_SECONDS:-20}"

API_KEY_A="${TEST_API_KEY_A:-}"
API_SECRET_A="${TEST_API_SECRET_A:-}"
API_KEY_B="${TEST_API_KEY_B:-}"
API_SECRET_B="${TEST_API_SECRET_B:-}"

log() {
  printf '%s\n' "$*"
}

fail() {
  log "#ERROR: $*"
  exit 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "required command not found: $1"
}

require_test_keys() {
  if [ -z "$API_KEY_A" ] || [ -z "$API_SECRET_A" ] || [ -z "$API_KEY_B" ] || [ -z "$API_SECRET_B" ]; then
    log "#SKIP: test API keys are not set"
    log "#HINT: export TEST_API_KEY_A TEST_API_SECRET_A TEST_API_KEY_B TEST_API_SECRET_B"
    exit 2
  fi
}

resolve_cfg_table() {
  docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -N -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" "${MARIADB_DATABASE:-trading}" -e "SELECT table_name FROM config__table_map WHERE applicant='${BOT_NAME}' LIMIT 1" | tr -d '\r\n'
}

set_cfg_param() {
  cfg_table="$1"
  account_id="$2"
  param="$3"
  value="$4"
  docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" "${MARIADB_DATABASE:-trading}" -e "INSERT INTO ${cfg_table} (account_id,param,value) VALUES (${account_id},'${param}','${value}') ON DUPLICATE KEY UPDATE value=VALUES(value)"
}

inject_keys_db() {
  account_id="$1"
  api_key="$2"
  api_secret="$3"

  CREDENTIAL_SOURCE=db \
  COMPOSE_FILE="$COMPOSE_FILE" \
  BOT_NAME="$BOT_NAME" \
  ACCOUNT_ID="$account_id" \
  API_KEY="$api_key" \
  API_SECRET="$api_secret" \
  sh scripts/inject-api-keys.sh
}

verify_runtime_layout() {
  data_root="$ROOT_DIR/var/data/$BOT_NAME"
  log_root="$ROOT_DIR/var/log/$BOT_NAME"

  for account_id in "$ACCOUNT_A" "$ACCOUNT_B"; do
    [ -d "$data_root/$account_id" ] || fail "missing worker data dir: $data_root/$account_id"
    [ -d "$log_root/$account_id" ] || fail "missing worker log dir: $log_root/$account_id"
  done

  if [ "$ACCOUNT_A" = "$ACCOUNT_B" ]; then
    fail "account ids must be different for parallel worker test"
  fi

  log "#OK: isolated worker runtime dirs detected"
  log "#OK: $data_root/$ACCOUNT_A"
  log "#OK: $data_root/$ACCOUNT_B"
  log "#OK: $log_root/$ACCOUNT_A"
  log "#OK: $log_root/$ACCOUNT_B"
}

main() {
  require_cmd docker-compose
  require_test_keys

  log "#STEP 1/6: start mariadb + bots-hive"
  docker-compose -f "$COMPOSE_FILE" up -d mariadb bots-hive

  log "#STEP 2/6: resolve bot config table"
  cfg_table="$(resolve_cfg_table)"
  [ -n "$cfg_table" ] || fail "config table not found for BOT_NAME=$BOT_NAME"
  log "#INFO: using config table $cfg_table"

  log "#STEP 3/6: inject credentials for account $ACCOUNT_A"
  inject_keys_db "$ACCOUNT_A" "$API_KEY_A" "$API_SECRET_A"

  log "#STEP 4/6: inject credentials for account $ACCOUNT_B"
  inject_keys_db "$ACCOUNT_B" "$API_KEY_B" "$API_SECRET_B"

  log "#STEP 5/6: enable trade/monitor flags and restart bots-hive"
  set_cfg_param "$cfg_table" "$ACCOUNT_A" "trade_enabled" "1"
  set_cfg_param "$cfg_table" "$ACCOUNT_A" "monitor_enabled" "1"
  set_cfg_param "$cfg_table" "$ACCOUNT_B" "trade_enabled" "1"
  set_cfg_param "$cfg_table" "$ACCOUNT_B" "monitor_enabled" "1"
  docker-compose -f "$COMPOSE_FILE" restart bots-hive

  log "#STEP 6/6: wait ${START_WAIT_SECONDS}s and verify per-worker runtime isolation"
  sleep "$START_WAIT_SECONDS"
  verify_runtime_layout

  log "#SUCCESS: parallel worker smoke test passed"
}

main "$@"
