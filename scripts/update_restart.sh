#!/usr/bin/env bash
# update_restart.sh — Extract the working override copy from the web container,
# back up the active docker-compose.override.yml, then apply and restart.
# Run from the project root directory on the Docker host.
set -euo pipefail

COMPOSE_FILE="docker-compose.override.yml"
BACKUP_DIR="var/log/override-backups"
CONTAINER_PATH="web:/app/var/data/sys-config/docker-compose.override.yml"

# Resolve project root (directory of this script's parent)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_DIR"

echo "[update_restart] Project dir: $PROJECT_DIR"

# Verify the container is running
if ! docker compose ps web | grep -q "Up"; then
    echo "[update_restart] ERROR: web container is not running. Cannot extract working copy."
    exit 1
fi

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Back up current override file if it exists
if [ -f "$COMPOSE_FILE" ]; then
    STAMP="$(date +%Y-%m-%d_%H-%M-%S)"
    BACKUP_PATH="$BACKUP_DIR/$COMPOSE_FILE.$STAMP"
    cp "$COMPOSE_FILE" "$BACKUP_PATH"
    echo "[update_restart] Backed up current override to: $BACKUP_PATH"
fi

# Extract working copy from container
echo "[update_restart] Extracting working copy from container..."
docker compose cp "$CONTAINER_PATH" "./$COMPOSE_FILE"
echo "[update_restart] Extracted to: ./$COMPOSE_FILE"

# Validate that the extracted file is non-empty
if [ ! -s "$COMPOSE_FILE" ]; then
    echo "[update_restart] ERROR: Extracted file is empty. Aborting restart."
    exit 1
fi

# Apply: restart affected services
echo "[update_restart] Running docker compose up -d ..."
docker compose up -d

# Push updated clickhouse.php to datafeed container if local alpet-libs-php is available.
CLICKHOUSE_SRC="$PROJECT_DIR/../alpet-libs-php/clickhouse.php"
if [ -f "$CLICKHOUSE_SRC" ]; then
    echo "[update_restart] Copying clickhouse.php to datafeed container..."
    docker compose cp "$CLICKHOUSE_SRC" datafeed:/app/lib/clickhouse.php \
        || echo "[update_restart] WARN: datafeed not running, skip clickhouse.php copy"
fi

echo "[update_restart] Done."
