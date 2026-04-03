#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

SOURCE="${CREDENTIAL_SOURCE:-pass}"       # pass | db
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
PASS_INIT_COMPOSE_FILE="${PASS_INIT_COMPOSE_FILE:-docker-compose.init-pass.yml}"

EXCHANGE="${EXCHANGE:-}"
ACCOUNT_ID="${ACCOUNT_ID:-}"
RO_SUFFIX="${RO_SUFFIX:-}"                # "_ro" for readonly bot key naming

API_KEY_VALUE="${API_KEY:-}"
API_SECRET_VALUE="${API_SECRET:-}"
API_SECRET_S0_VALUE="${API_SECRET_S0:-}"
API_SECRET_S1_VALUE="${API_SECRET_S1:-}"
API_SECRET_SEP="${API_SECRET_SEP:--}"
API_SECRET_SPLIT_POS="${API_SECRET_SPLIT_POS:-}"

BOT_NAME_VALUE="${BOT_NAME:-}"
PARAM_KEY="${BOT_DB_API_KEY_PARAM:-api_key}"
PARAM_SECRET="${BOT_DB_API_SECRET_PARAM:-api_secret}"
SECRET_ENCRYPTED_FLAG="${SECRET_KEY_ENCRYPTED:-0}"
BOT_MANAGER_SECRET_KEY_VALUE="${BOT_MANAGER_SECRET_KEY:-}"
BOT_MANAGER_SECRET_KEY_FILE_VALUE="${BOT_MANAGER_SECRET_KEY_FILE:-/run/secrets/bot_manager_key}"

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

sql_escape() {
  printf "%s" "$1" | sed "s/'/''/g"
}

resolve_bot_manager_secret_key() {
  if [ -n "$BOT_MANAGER_SECRET_KEY_VALUE" ]; then
    printf '%s' "$BOT_MANAGER_SECRET_KEY_VALUE"
    return 0
  fi

  if [ -n "$BOT_MANAGER_SECRET_KEY_FILE_VALUE" ] && [ -f "$BOT_MANAGER_SECRET_KEY_FILE_VALUE" ]; then
    tr -d '\r\n' < "$BOT_MANAGER_SECRET_KEY_FILE_VALUE"
    return 0
  fi

  for candidate in /run/secrets/bot_manager_secret_key /run/secrets/bot_manager_master_key; do
    if [ -f "$candidate" ]; then
      tr -d '\r\n' < "$candidate"
      return 0
    fi
  done

  return 1
}

encrypt_db_secret() {
  plain="$1"
  master_key="$2"
  require_cmd php

  php -r '
$key = $argv[1] ?? "";
$plain = $argv[2] ?? "";
if ($key === "" || $plain === "") {
    fwrite(STDERR, "missing key/plain\n");
    exit(2);
}
$iv = random_bytes(12);
$tag = "";
$cipher = openssl_encrypt($plain, "aes-256-gcm", hash("sha256", $key, true), OPENSSL_RAW_DATA, $iv, $tag);
if ($cipher === false || strlen($tag) !== 16) {
    fwrite(STDERR, "encrypt failed\n");
    exit(3);
}
echo "v1:" . base64_encode($iv . $tag . $cipher);
' "$master_key" "$plain"
}

resolve_secret_parts() {
  if [ -n "$API_SECRET_S0_VALUE" ] && [ -n "$API_SECRET_S1_VALUE" ]; then
    return 0
  fi

  if [ -z "$API_SECRET_VALUE" ]; then
    fail "set API_SECRET or API_SECRET_S0/API_SECRET_S1"
  fi

  case "$API_SECRET_VALUE" in
    *"$API_SECRET_SEP"*)
      API_SECRET_S0_VALUE="${API_SECRET_VALUE%%${API_SECRET_SEP}*}"
      API_SECRET_S1_VALUE="${API_SECRET_VALUE#*${API_SECRET_SEP}}"
      ;;
    *)
      fail "API_SECRET does not contain separator '${API_SECRET_SEP}'. Provide API_SECRET_S0 and API_SECRET_S1 explicitly."
      ;;
  esac
}

auto_split_secret() {
  [ -n "$API_SECRET_VALUE" ] || fail "API_SECRET is required for auto split"

  secret_len=${#API_SECRET_VALUE}
  [ "$secret_len" -ge 3 ] || fail "API_SECRET too short for split (min length: 3)"

  split_pos="$API_SECRET_SPLIT_POS"
  if [ -z "$split_pos" ]; then
    split_pos=$((secret_len / 2))
  fi

  case "$split_pos" in
    ''|*[!0-9]*)
      fail "API_SECRET_SPLIT_POS must be integer"
      ;;
  esac

  [ "$split_pos" -ge 1 ] || fail "API_SECRET_SPLIT_POS must be >= 1"
  [ "$split_pos" -lt "$secret_len" ] || fail "API_SECRET_SPLIT_POS must be < API_SECRET length"

  API_SECRET_S0_VALUE="$(printf '%s' "$API_SECRET_VALUE" | awk -v p="$split_pos" '{print substr($0, 1, p)}')"
  API_SECRET_SEP="$(printf '%s' "$API_SECRET_VALUE" | awk -v p="$split_pos" '{print substr($0, p + 1, 1)}')"
  API_SECRET_S1_VALUE="$(printf '%s' "$API_SECRET_VALUE" | awk -v p="$split_pos" '{print substr($0, p + 2)}')"

  [ -n "$API_SECRET_S0_VALUE" ] || fail "auto split produced empty S0"
  [ -n "$API_SECRET_SEP" ] || fail "auto split produced empty separator"
  [ -n "$API_SECRET_S1_VALUE" ] || fail "auto split produced empty S1"

  log "#INFO: auto-split secret at pos=${split_pos} (s0_len=${#API_SECRET_S0_VALUE}, sep='${API_SECRET_SEP}', s1_len=${#API_SECRET_S1_VALUE})"
}

split_by_separator_literal() {
  [ -n "$API_SECRET_VALUE" ] || fail "API_SECRET is required"
  [ -n "$API_SECRET_SEP" ] || fail "API_SECRET_SEP is required"

  split_pos="$(printf '%s' "$API_SECRET_VALUE" | awk -v sep="$API_SECRET_SEP" '{p=index($0, sep); if (p>0) print p-1; else print 0}')"
  [ "$split_pos" -ge 1 ] || return 1

  API_SECRET_S0_VALUE="$(printf '%s' "$API_SECRET_VALUE" | awk -v p="$split_pos" '{print substr($0, 1, p)}')"
  API_SECRET_S1_VALUE="$(printf '%s' "$API_SECRET_VALUE" | awk -v p="$split_pos" -v s="$API_SECRET_SEP" '{print substr($0, p + length(s) + 1)}')"

  [ -n "$API_SECRET_S0_VALUE" ] || return 1
  [ -n "$API_SECRET_S1_VALUE" ] || return 1
  return 0
}

inject_pass() {
  [ -n "$EXCHANGE" ] || fail "EXCHANGE is required for pass mode"
  [ -n "$ACCOUNT_ID" ] || fail "ACCOUNT_ID is required for pass mode"
  [ -n "$API_KEY_VALUE" ] || fail "API_KEY is required for pass mode"
  resolve_secret_parts

  path_base="api/${EXCHANGE}@${ACCOUNT_ID}${RO_SUFFIX}"

  log "#INFO: writing keys into pass store path ${path_base}"
  docker-compose -f "$PASS_INIT_COMPOSE_FILE" --profile init run --rm pass-init sh -lc "
    printf '%s\n' \"$API_KEY_VALUE\" | pass insert -f '$path_base' >/dev/null
    printf '%s\n' \"$API_SECRET_S0_VALUE\" | pass insert -f '${path_base}_s0' >/dev/null
    printf '%s\n' \"$API_SECRET_S1_VALUE\" | pass insert -f '${path_base}_s1' >/dev/null
    pass show '$path_base' >/dev/null
  "

  log "#SUCCESS: pass credentials injected for ${EXCHANGE}@${ACCOUNT_ID}${RO_SUFFIX}"
}

inject_db() {
  [ -n "$BOT_NAME_VALUE" ] || fail "BOT_NAME is required for db mode"
  [ -n "$ACCOUNT_ID" ] || fail "ACCOUNT_ID is required for db mode"
  [ -n "$API_KEY_VALUE" ] || fail "API_KEY is required for db mode"

  bot_name_sql="$(sql_escape "$BOT_NAME_VALUE")"
  api_key_sql="$(sql_escape "$API_KEY_VALUE")"
  param_secret_s0_sql="$(sql_escape "${PARAM_SECRET}_s0")"
  param_secret_s1_sql="$(sql_escape "${PARAM_SECRET}_s1")"
  param_secret_sep_sql="$(sql_escape "${PARAM_SECRET}_sep")"
  param_secret_encrypted_sql="$(sql_escape 'secret_key_encrypted')"
  param_key_sql="$(sql_escape "$PARAM_KEY")"
  param_secret_sql="$(sql_escape "$PARAM_SECRET")"

  cfg_table="$(docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -N -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" "${MARIADB_DATABASE:-trading}" -e "SELECT table_name FROM config__table_map WHERE applicant='${bot_name_sql}' LIMIT 1")"
  [ -n "$cfg_table" ] || fail "config table not found in config__table_map for applicant '${BOT_NAME_VALUE}'"
  cfg_table_sql="$(sql_escape "$cfg_table")"

  db_secret_encrypted_value=0
  encrypted_secret_sql=''
  secret_s0_sql=''
  secret_s1_sql=''
  secret_sep_sql=''

  if [ "$SECRET_ENCRYPTED_FLAG" = "1" ]; then
    [ -n "$API_SECRET_VALUE" ] || fail "API_SECRET is required when SECRET_KEY_ENCRYPTED=1"
    manager_key="$(resolve_bot_manager_secret_key || true)"
    [ -n "$manager_key" ] || fail "secret encryption requested but BOT_MANAGER_SECRET_KEY (or BOT_MANAGER_SECRET_KEY_FILE) is empty"
    encrypted_secret="$(encrypt_db_secret "$API_SECRET_VALUE" "$manager_key")"
    [ -n "$encrypted_secret" ] || fail "failed to encrypt API secret"
    encrypted_secret_sql="$(sql_escape "$encrypted_secret")"
    db_secret_encrypted_value=1
    secret_s0_sql=''
    secret_s1_sql=''
    secret_sep_sql='-'
    log "#INFO: DB secret encrypted with bot_manager master key"
  else
    if [ -z "$API_SECRET_S0_VALUE" ] || [ -z "$API_SECRET_S1_VALUE" ]; then
      if [ -n "$API_SECRET_SPLIT_POS" ]; then
        auto_split_secret
      else
        existing_sep="$(docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -N -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" "${MARIADB_DATABASE:-trading}" -e "SELECT value FROM ${cfg_table} WHERE account_id=${ACCOUNT_ID} AND param='${PARAM_SECRET}_sep' LIMIT 1")"
        existing_sep="$(printf '%s' "$existing_sep" | tr -d '\r\n')"
        if [ -n "$existing_sep" ]; then
          API_SECRET_SEP="$existing_sep"
          if split_by_separator_literal; then
            log "#INFO: auto-split used existing DB separator '${API_SECRET_SEP}'"
          else
            auto_split_secret
          fi
        else
          auto_split_secret
        fi
      fi
    fi

    secret_s0_sql="$(sql_escape "$API_SECRET_S0_VALUE")"
    secret_s1_sql="$(sql_escape "$API_SECRET_S1_VALUE")"
    secret_sep_sql="$(sql_escape "$API_SECRET_SEP")"
    db_secret_encrypted_value=0
  fi

  query="SET @cfg_table='${cfg_table_sql}';\
SET @q1=CONCAT('INSERT INTO ', @cfg_table, ' (account_id,param,value) VALUES (', ${ACCOUNT_ID}, ',''', '${param_key_sql}', ''',''', '${api_key_sql}', ''') ON DUPLICATE KEY UPDATE value=VALUES(value)');\
PREPARE s1 FROM @q1; EXECUTE s1; DEALLOCATE PREPARE s1;\
SET @q2=CONCAT('INSERT INTO ', @cfg_table, ' (account_id,param,value) VALUES (', ${ACCOUNT_ID}, ',''', '${param_secret_sql}', ''',''', '${encrypted_secret_sql}', ''') ON DUPLICATE KEY UPDATE value=VALUES(value)');\
PREPARE s2 FROM @q2; EXECUTE s2; DEALLOCATE PREPARE s2;\
SET @q3=CONCAT('INSERT INTO ', @cfg_table, ' (account_id,param,value) VALUES (', ${ACCOUNT_ID}, ',''', '${param_secret_s0_sql}', ''',''', '${secret_s0_sql}', ''') ON DUPLICATE KEY UPDATE value=VALUES(value)');\
PREPARE s3 FROM @q3; EXECUTE s3; DEALLOCATE PREPARE s3;\
SET @q4=CONCAT('INSERT INTO ', @cfg_table, ' (account_id,param,value) VALUES (', ${ACCOUNT_ID}, ',''', '${param_secret_s1_sql}', ''',''', '${secret_s1_sql}', ''') ON DUPLICATE KEY UPDATE value=VALUES(value)');\
PREPARE s4 FROM @q4; EXECUTE s4; DEALLOCATE PREPARE s4;\
SET @q5=CONCAT('INSERT INTO ', @cfg_table, ' (account_id,param,value) VALUES (', ${ACCOUNT_ID}, ',''', '${param_secret_sep_sql}', ''',''', '${secret_sep_sql}', ''') ON DUPLICATE KEY UPDATE value=VALUES(value)');\
PREPARE s5 FROM @q5; EXECUTE s5; DEALLOCATE PREPARE s5;\
SET @q6=CONCAT('INSERT INTO ', @cfg_table, ' (account_id,param,value) VALUES (', ${ACCOUNT_ID}, ',''', '${param_secret_encrypted_sql}', ''',''', '${db_secret_encrypted_value}', ''') ON DUPLICATE KEY UPDATE value=VALUES(value)');\
PREPARE s6 FROM @q6; EXECUTE s6; DEALLOCATE PREPARE s6;"

  log "#INFO: writing keys into DB config for bot '${BOT_NAME_VALUE}', account '${ACCOUNT_ID}'"
  docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" "${MARIADB_DATABASE:-trading}" -e "$query"

  verify_query="SET @cfg_table='${cfg_table_sql}';\
SET @vq=CONCAT('SELECT param,value FROM ', @cfg_table, ' WHERE account_id=', ${ACCOUNT_ID}, ' AND param IN (''${param_key_sql}'',''${param_secret_sql}'',''${param_secret_s0_sql}'',''${param_secret_s1_sql}'',''${param_secret_sep_sql}'',''${param_secret_encrypted_sql}'') ORDER BY param');\
PREPARE vq FROM @vq; EXECUTE vq; DEALLOCATE PREPARE vq;"
  docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" "${MARIADB_DATABASE:-trading}" -e "$verify_query"

  log "#SUCCESS: db credentials injected for bot '${BOT_NAME_VALUE}', account '${ACCOUNT_ID}'"
}

main() {
  require_cmd docker-compose

  case "$SOURCE" in
    pass)
      inject_pass
      ;;
    db)
      inject_db
      ;;
    *)
      fail "CREDENTIAL_SOURCE must be 'pass' or 'db'"
      ;;
  esac
}

main "$@"
