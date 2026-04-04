#!/bin/sh
set -eu

# Restart bots-hive service and run quick post-checks.
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(dirname "$SCRIPT_DIR")
SERVICE_NAME=${HIVE_SERVICE:-bots-hive}
COMPOSE_FILE_PATH=${COMPOSE_FILE:-docker-compose.yml}
LOG_TAIL=${LOG_TAIL:-120}
WAIT_SECONDS=${WAIT_SECONDS:-30}
EXCHANGE=${1:-}

if docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD="docker-compose"
else
    echo "#ERROR: docker compose command is not available" >&2
    exit 1
fi

PROJECT_NAME=${COMPOSE_PROJECT_NAME:-}
if [ -z "$PROJECT_NAME" ] && [ -f "$ROOT_DIR/.env" ]; then
    PROJECT_NAME=$(grep -E '^COMPOSE_PROJECT_NAME=' "$ROOT_DIR/.env" | tail -n1 | cut -d'=' -f2- | tr -d '"\r')
fi
if [ -z "$PROJECT_NAME" ]; then
    PROJECT_NAME=trd
fi
CONTAINER_NAME=${HIVE_CONTAINER:-${PROJECT_NAME}-bots-hive}

cd "$ROOT_DIR"

echo "#INFO: compose command = $COMPOSE_CMD"
echo "#INFO: service = $SERVICE_NAME"
echo "#INFO: container = $CONTAINER_NAME"

echo "#STEP: restarting hive service"
$COMPOSE_CMD -f "$COMPOSE_FILE_PATH" restart "$SERVICE_NAME"

echo "#STEP: waiting for running container"
i=0
while [ "$i" -lt "$WAIT_SECONDS" ]; do
    state=$(docker inspect -f '{{.State.Status}}' "$CONTAINER_NAME" 2>/dev/null || echo "missing")
    if [ "$state" = "running" ]; then
        echo "#OK: container is running"
        break
    fi
    i=$((i + 1))
    sleep 1
done

if [ "$i" -ge "$WAIT_SECONDS" ]; then
    echo "#ERROR: container did not become running in ${WAIT_SECONDS}s" >&2
    docker ps -a --filter "name=$CONTAINER_NAME"
    exit 1
fi

echo "#STEP: compose ps"
$COMPOSE_CMD -f "$COMPOSE_FILE_PATH" ps "$SERVICE_NAME" || true

echo "#STEP: tail logs ($LOG_TAIL lines)"
docker logs --tail "$LOG_TAIL" "$CONTAINER_NAME" 2>&1 || true

echo "#STEP: quick market maker signal check"
if docker logs --tail 400 "$CONTAINER_NAME" 2>&1 | grep -E "#MM|BLOCK_MM_EXEC|max_exec_cost" >/dev/null 2>&1; then
    echo "#OK: market maker markers found in recent logs"
else
    echo "#WARN: no market maker markers in recent logs"
    echo "#HINT: if table ${EXCHANGE:-<exchange>}__mm_config is empty, market maker is not enabled"
fi

echo "#DONE: hive restart completed"
