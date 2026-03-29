#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
DEPLOY_TIMEOUT_SECONDS="${DEPLOY_TIMEOUT_SECONDS:-180}"
DEPLOY_DB_WAIT_INTERVAL="${DEPLOY_DB_WAIT_INTERVAL:-3}"
WEB_PORT_VALUE="${WEB_PORT:-8088}"
WEB_PUBLISH_IP_VALUE="${WEB_PUBLISH_IP:-127.0.0.1}"

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
}

main() {
  require_cmd docker-compose
  require_cmd docker
  require_cmd sh

  log "#STEP 1/6: bootstrap runtime and generate secrets/db_config.php with random trading password"
  sh shell/bootstrap_container_env.sh

  log "#STEP 2/6: build images for mariadb/web"
  docker-compose -f "$COMPOSE_FILE" build mariadb web

  log "#STEP 3/6: start mariadb"
  docker-compose -f "$COMPOSE_FILE" up -d mariadb

  log "#STEP 4/6: wait for mariadb health"
  wait_for_db_health || fail "mariadb was not healthy within ${DEPLOY_TIMEOUT_SECONDS}s"

  log "#STEP 5/6: start web(api + admin ui)"
  docker-compose -f "$COMPOSE_FILE" up -d web

  log "#STEP 6/6: test admin/api endpoints"
  test_web_endpoints || fail "web endpoint checks failed"

  log ""
  log "#SUCCESS: simple deploy completed"
  log "#URL admin: http://${WEB_PUBLISH_IP_VALUE}:${WEB_PORT_VALUE}/basic-admin.php"
  log "#URL api:   http://${WEB_PUBLISH_IP_VALUE}:${WEB_PORT_VALUE}/api/index.php"
  log "#NOTE: local admin UI is intended for trusted local access"
  log "#NEXT: run scripts/inject-api-keys.sh to load exchange keys"
}

main "$@"
