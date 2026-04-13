#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
LEGACY_COMPOSE_FILE="${LEGACY_COMPOSE_FILE:-docker-compose.signals-legacy.yml}"
HEALTH_TIMEOUT_SECONDS="${HEALTH_TIMEOUT_SECONDS:-90}"
HEALTH_INTERVAL_SECONDS="${HEALTH_INTERVAL_SECONDS:-3}"

log() {
  printf '%s\n' "$*"
}

fail() {
  log "#ERROR: $*"
  exit 1
}

if [ ! -f "$COMPOSE_FILE" ]; then
  fail "missing $COMPOSE_FILE"
fi
if [ ! -f "$LEGACY_COMPOSE_FILE" ]; then
  fail "missing $LEGACY_COMPOSE_FILE"
fi

if [ ! -f "secrets/signals_db_config.php" ]; then
  if [ ! -f "secrets/signals_db_config.php.example" ]; then
    fail "missing secrets/signals_db_config.php and template"
  fi
  cp "secrets/signals_db_config.php.example" "secrets/signals_db_config.php"
  log "#INFO: created secrets/signals_db_config.php from template"
fi

SIGNALS_LEGACY_PORT_VALUE="${SIGNALS_LEGACY_PORT:-8480}"
if [ -f ".env" ]; then
  line="$(grep '^SIGNALS_LEGACY_PORT=' .env || true)"
  if [ -n "$line" ]; then
    value="${line#SIGNALS_LEGACY_PORT=}"
    if [ -n "$value" ]; then
      SIGNALS_LEGACY_PORT_VALUE="$value"
    fi
  fi
fi

log "#STEP 1/3: start legacy signals services"
docker-compose -f "$COMPOSE_FILE" -f "$LEGACY_COMPOSE_FILE" up -d signals-legacy-db signals-legacy

log "#STEP 2/3: wait for legacy API probe"
legacy_url="http://127.0.0.1:${SIGNALS_LEGACY_PORT_VALUE}/docs.html"
elapsed=0
while [ "$elapsed" -lt "$HEALTH_TIMEOUT_SECONDS" ]; do
  if command -v curl >/dev/null 2>&1; then
    if curl -fsS "$legacy_url" >/dev/null 2>&1; then
      break
    fi
  else
    if wget -q -O - "$legacy_url" >/dev/null 2>&1; then
      break
    fi
  fi
  sleep "$HEALTH_INTERVAL_SECONDS"
  elapsed=$((elapsed + HEALTH_INTERVAL_SECONDS))

  if [ "$elapsed" -ge "$HEALTH_TIMEOUT_SECONDS" ]; then
    docker-compose -f "$COMPOSE_FILE" -f "$LEGACY_COMPOSE_FILE" logs --tail 80 signals-legacy || true
    fail "legacy signals probe failed: $legacy_url"
  fi
done

log "#STEP 3/3: show running legacy services"
docker-compose -f "$COMPOSE_FILE" -f "$LEGACY_COMPOSE_FILE" ps signals-legacy-db signals-legacy

log ""
log "#SUCCESS: legacy signals stack is up"
log "#URL legacy docs: $legacy_url"
log "#NEXT: run scripts/deploy-simple.sh for trading group"
