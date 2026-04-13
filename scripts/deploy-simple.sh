#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
DEPLOY_OVERRIDE_FILE="${DEPLOY_OVERRIDE_FILE:-docker-compose.override.yml}"
DEPLOY_ENV_FILE="${DEPLOY_ENV_FILE:-.env}"
DEPLOY_GENERATE_PASSWORDS="${DEPLOY_GENERATE_PASSWORDS:-auto}"
DEPLOY_TIMEOUT_SECONDS="${DEPLOY_TIMEOUT_SECONDS:-180}"
DEPLOY_DB_WAIT_INTERVAL="${DEPLOY_DB_WAIT_INTERVAL:-3}"
WEB_PORT_VALUE="${WEB_PORT:-8088}"
WEB_PUBLISH_IP_VALUE="${WEB_PUBLISH_IP:-127.0.0.1}"
FULL_DEPLOY_SERVICES="${FULL_DEPLOY_SERVICES:-web bots-hive datafeed phpmyadmin}"

if [ "$DEPLOY_DB_WAIT_INTERVAL" -le 0 ] 2>/dev/null; then
  DEPLOY_DB_WAIT_INTERVAL=3
fi

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

verify_datafeed_manager_source() {
  manager_path="$ROOT_DIR/../datafeed/src/datafeed_manager.php"
  if [ ! -f "$manager_path" ]; then
    log "#INFO: datafeed not found locally, cloning external repos..."
    sh "$ROOT_DIR/shell/ensure_external_repos.sh"
  fi
  if [ ! -f "$manager_path" ]; then
    fail "datafeed manager source still missing after clone: $manager_path"
  fi
}

generate_password() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 28
    return
  fi

  if [ -r /dev/urandom ]; then
    tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 28
    return
  fi

  date +%s | tr -dc '0-9'
}

set_env_value() {
  file="$1"
  key="$2"
  value="$3"

  if [ ! -f "$file" ]; then
    : > "$file"
  fi

  if grep -q "^${key}=" "$file"; then
    escaped_value="$(printf '%s' "$value" | sed 's/[\\/&]/\\\\&/g')"
    sed -i "s|^${key}=.*$|${key}=${escaped_value}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

set_override_value() {
  file="$1"
  key="$2"
  value="$3"

  if [ ! -f "$file" ]; then
    log "#WARN: $file not found, skip ${key} update"
    return 0
  fi

  tmp_file="${file}.tmp"
  awk -v key="$key" -v value="$value" '
    BEGIN { updated = 0 }
    {
      pattern = "^[[:space:]]*" key ":[[:space:]]*"
      if ($0 ~ pattern) {
        indent = ""
        if (match($0, /^[[:space:]]*/)) {
          indent = substr($0, RSTART, RLENGTH)
        }
        print indent key ": " value
        updated = 1
        next
      }
      print
    }
    END {
      if (updated == 0) {
        exit 2
      }
    }
  ' "$file" > "$tmp_file" || {
    rc="$?"
    rm -f "$tmp_file"
    if [ "$rc" -eq 2 ]; then
      log "#WARN: key ${key} not found in $file"
      return 0
    fi
    return "$rc"
  }

  mv "$tmp_file" "$file"
}

prepare_deploy_passwords() {
  mode="$DEPLOY_GENERATE_PASSWORDS"
  case "$mode" in
    yes|no|auto) ;;
    *) fail "DEPLOY_GENERATE_PASSWORDS must be one of: auto|yes|no" ;;
  esac

  if [ "$mode" = "auto" ]; then
    if [ -t 0 ]; then
      printf '%s' "#PROMPT: Generate random passwords and update $DEPLOY_OVERRIDE_FILE + $DEPLOY_ENV_FILE? [Y/n] "
      read -r answer || answer=""
      case "${answer:-Y}" in
        y|Y|yes|YES|'') mode="yes" ;;
        *) mode="no" ;;
      esac
    else
      mode="no"
      log "#INFO: non-interactive session; skip password generation (set DEPLOY_GENERATE_PASSWORDS=yes to force)."
    fi
  fi

  if [ "$mode" = "no" ]; then
    log "#INFO: password preflight skipped by user choice"
    return 0
  fi

  if [ ! -f "$DEPLOY_ENV_FILE" ]; then
    if [ -f ".env.example" ]; then
      cp ".env.example" "$DEPLOY_ENV_FILE"
      log "#INFO: created $DEPLOY_ENV_FILE from .env.example"
    else
      cat > "$DEPLOY_ENV_FILE" <<'EOF'
COMPOSE_PROJECT_NAME=trd
TZ=UTC
MARIADB_DATABASE=trading
MARIADB_USER=trading
WEB_PORT=8088
WEB_PUBLISH_IP=127.0.0.1
EOF
      log "#INFO: created minimal $DEPLOY_ENV_FILE"
    fi
  fi

  mariadb_root_password="$(generate_password)"
  mariadb_password="$(generate_password)"
  mariadb_repl_password="$(generate_password)"
  mariadb_remote_password="$(generate_password)"
  bot_trader_password="$(generate_password)"

  set_env_value "$DEPLOY_ENV_FILE" "MARIADB_ROOT_PASSWORD" "$mariadb_root_password"
  set_env_value "$DEPLOY_ENV_FILE" "MARIADB_PASSWORD" "$mariadb_password"
  set_env_value "$DEPLOY_ENV_FILE" "TRADING_DB_PASSWORD" "$mariadb_password"
  set_env_value "$DEPLOY_ENV_FILE" "MARIADB_REPL_PASSWORD" "$mariadb_repl_password"
  set_env_value "$DEPLOY_ENV_FILE" "MARIADB_REMOTE_PASSWORD" "$mariadb_remote_password"
  set_env_value "$DEPLOY_ENV_FILE" "BOT_TRADER_PASSWORD" "$bot_trader_password"

  set_override_value "$DEPLOY_OVERRIDE_FILE" "MARIADB_ROOT_PASSWORD" "$mariadb_root_password"
  set_override_value "$DEPLOY_OVERRIDE_FILE" "MARIADB_PASSWORD" "$mariadb_password"
  set_override_value "$DEPLOY_OVERRIDE_FILE" "MARIADB_REPL_PASSWORD" "$mariadb_repl_password"
  set_override_value "$DEPLOY_OVERRIDE_FILE" "MARIADB_REMOTE_PASSWORD" "$mariadb_remote_password"
  set_override_value "$DEPLOY_OVERRIDE_FILE" "DB_PASS" "$mariadb_password"

  log "#INFO: randomized credentials applied to $DEPLOY_ENV_FILE and $DEPLOY_OVERRIDE_FILE"
}

# Colloquium/CQDS: docker-compose ожидает ./secrets/cqds_db_password (без DB_ROOT_PASSWD в сессии).
ensure_cqds_db_secret_if_repo_present() {
  cqds_root="${CQDS_ROOT:-$ROOT_DIR/../cqds}"
  secrets_dir="$cqds_root/secrets"
  f="$secrets_dir/cqds_db_password"
  if [ ! -d "$cqds_root" ] || [ ! -f "$cqds_root/docker-compose.yml" ]; then
    log "#INFO: CQDS not found at $cqds_root, skip cqds_db_password"
    return 0
  fi
  mkdir -p "$secrets_dir"
  if [ -s "$f" ]; then
    log "#INFO: $f already present, leaving unchanged"
    return 0
  fi
  cqds_pw="$(generate_password)"
  (
    umask 077
    printf '%s' "$cqds_pw" >"$f"
  )
  chmod 600 "$f" 2>/dev/null || true
  log "#INFO: created $f (random password for CQDS PostgreSQL)"
}

wait_for_db_health() {
  elapsed=0
  while [ "$elapsed" -lt "$DEPLOY_TIMEOUT_SECONDS" ]; do
    if docker-compose -f "$COMPOSE_FILE" exec -T mariadb sh -lc 'mariadb-admin ping -h 127.0.0.1 -uroot -p"$MARIADB_ROOT_PASSWORD" >/dev/null 2>&1'; then
      log "#INFO: mariadb is healthy"
      return 0
    fi

    # Some local setups may have root auth via socket/no password.
    if docker-compose -f "$COMPOSE_FILE" exec -T mariadb sh -lc 'mariadb-admin ping -h 127.0.0.1 -uroot >/dev/null 2>&1'; then
      log "#INFO: mariadb is healthy"
      return 0
    fi
    sleep "$DEPLOY_DB_WAIT_INTERVAL"
    elapsed=$((elapsed + DEPLOY_DB_WAIT_INTERVAL))
  done

  log "#WARN: mariadb health timeout reached. Last container logs:"
  docker-compose -f "$COMPOSE_FILE" logs --tail 40 mariadb || true
  return 1
}

wait_for_service_running() {
  service="$1"
  elapsed=0
  while [ "$elapsed" -lt "$DEPLOY_TIMEOUT_SECONDS" ]; do
    cid="$(docker-compose -f "$COMPOSE_FILE" ps -q "$service" 2>/dev/null | head -n1)"
    if [ -n "$cid" ] && [ "$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null || true)" = "true" ]; then
      log "#INFO: $service is running"
      return 0
    fi
    sleep "$DEPLOY_DB_WAIT_INTERVAL"
    elapsed=$((elapsed + DEPLOY_DB_WAIT_INTERVAL))
  done

  log "#WARN: service start timeout for $service. Last logs:"
  docker-compose -f "$COMPOSE_FILE" logs --tail 40 "$service" || true
  return 1
}

test_web_endpoints() {
  # 1) base bootstrap admin page
  docker-compose -f "$COMPOSE_FILE" exec -T web php -r '
  $u = "http://127.0.0.1/basic-admin.php";
  $s = @file_get_contents($u);
  if ($s === false || strlen($s) < 50) {
    fwrite(STDERR, "basic admin page probe failed\n");
    exit(1);
  }
  echo "basic-admin-page-ok\n";
  '

  # 2) API entrypoint (php builtin server has no .htaccess routing)
  docker-compose -f "$COMPOSE_FILE" exec -T web php -r '
  $u = "http://127.0.0.1/api/index.php";
  $s = @file_get_contents($u);
  if ($s === false) {
    fwrite(STDERR, "api entrypoint probe failed\n");
    exit(1);
  }
  echo "api-entrypoint-ok\n";
  '

  docker-compose -f "$COMPOSE_FILE" exec -T web php -r '
  $u = "http://127.0.0.1/bot/get_vwap.php?pair_id=3&limit=5&exchange=bitmex";
  $s = @file_get_contents($u);
  if ($s === false) {
    fwrite(STDERR, "warn: get_vwap probe unavailable\n");
    exit(0);
  }
  echo "get-vwap-probe=" . substr(trim($s), 0, 80) . "\n";
  '
}

main() {
  require_cmd docker-compose
  require_cmd docker
  require_cmd sh

  log "#STEP 0/7: ensure external repos (alpet-libs-php, datafeed)"
  sh "$ROOT_DIR/shell/ensure_external_repos.sh"
  verify_datafeed_manager_source

  log "#STEP 1/7: optional password preflight (.env + docker-compose.override.yml)"
  prepare_deploy_passwords
  ensure_cqds_db_secret_if_repo_present

  log "#STEP 2/7: bootstrap runtime and generate secrets/db_config.php with random trading password"
  sh shell/bootstrap_container_env.sh

  log "#STEP 3/8: build images for mariadb/web/bots-hive/datafeed"
  docker-compose -f "$COMPOSE_FILE" build mariadb web bots-hive datafeed

  log "#STEP 4/8: start mariadb"
  docker-compose -f "$COMPOSE_FILE" up -d mariadb

  log "#STEP 5/8: wait for mariadb health"
  wait_for_db_health || fail "mariadb was not healthy within ${DEPLOY_TIMEOUT_SECONDS}s"

  log "#STEP 6/8: start full service group"
  docker-compose -f "$COMPOSE_FILE" up -d $FULL_DEPLOY_SERVICES

  for service in web bots-hive datafeed; do
    wait_for_service_running "$service" || fail "$service was not running within ${DEPLOY_TIMEOUT_SECONDS}s"
  done

  log "#STEP 7/8: test admin/api endpoints"
  test_web_endpoints || fail "web endpoint checks failed"

  log "#STEP 8/8: show running services"
  docker-compose -f "$COMPOSE_FILE" ps mariadb web bots-hive datafeed phpmyadmin || true

  log ""
  log "#SUCCESS: simple deploy completed"
  log "#URL admin: http://${WEB_PUBLISH_IP_VALUE}:${WEB_PORT_VALUE}/basic-admin.php"
  log "#URL api:   http://${WEB_PUBLISH_IP_VALUE}:${WEB_PORT_VALUE}/api/index.php"
  log "#NOTE: local admin UI is intended for trusted local access"
  log "#NEXT: run scripts/inject-api-keys.sh to load exchange keys"
}

main "$@"
