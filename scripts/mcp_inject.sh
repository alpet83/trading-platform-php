#!/bin/sh
# scripts/mcp_inject.sh — inject mcp_server.py INTO the bots-hive container
#
# Installs python3 + deps inside the running container, copies mcp_server.py
# and its lib/ dependencies, then starts the server as a background process.
# mcp_server.py runs side-by-side with bot_manager, with full access to
# /app/src, running processes, and env vars.
#
# Prerequisites:
#   - trd-bots-hive is running (started with docker-compose.mcp-debug.yml
#     so it is connected to mcp_debug_net)
#   - CQDS source available locally or reachable via CQDS_GIT_REPO
#
# Source: CQDS_SRC_DIR (default: /p/opt/docker/cqds)
# Or:     CQDS_GIT_REPO — git clone if source dir absent

set -e

CONTAINER="${MCP_TARGET_CONTAINER:-trd-bots-hive}"
CQDS_SRC="${CQDS_SRC_DIR:-/p/opt/docker/cqds}"
CQDS_REPO="${CQDS_GIT_REPO:-https://github.com/alpet83/Colloquium-DevSpace.git}"
MCP_DEST="/opt/mcp"
MCP_AUTH_TOKEN_VALUE="${MCP_AUTH_TOKEN:-Grok-xAI-Agent-The-Best}"

if [ ! -d "$CQDS_SRC/projects" ]; then
    echo "[mcp_inject] CQDS_SRC_DIR=$CQDS_SRC not found, cloning from $CQDS_REPO ..."
    git clone --depth=1 "$CQDS_REPO" "$CQDS_SRC"
fi

check_container() {
    docker inspect "$CONTAINER" --format '{{.State.Running}}' 2>/dev/null | grep -q true
}

if ! check_container; then
    echo "[mcp_inject] ERROR: container '$CONTAINER' is not running."
    echo "  Start it first: docker compose -f docker-compose.yml -f docker-compose.mcp-debug.yml up -d bots-hive"
    exit 1
fi

echo "[mcp_inject] Installing python3 inside $CONTAINER ..."
docker exec "$CONTAINER" apt-get update -qq 2>&1 | tail -1
docker exec "$CONTAINER" apt-get install -y -qq python3 python3-pip python3-venv 2>&1 | tail -3

echo "[mcp_inject] Creating venv and installing Python deps ..."
docker exec "$CONTAINER" sh -c "
    python3 -m venv /opt/mcp-venv &&
    /opt/mcp-venv/bin/pip install --quiet --no-cache-dir quart toml gitpython requests
"

echo "[mcp_inject] Copying mcp_server.py and lib/ into $CONTAINER:$MCP_DEST ..."
docker exec "$CONTAINER" mkdir -p "$MCP_DEST/lib"

docker cp "$CQDS_SRC/projects/mcp_server.py"    "$CONTAINER:$MCP_DEST/mcp_server.py"
# Copy the full lib/ directory — basic_logger transitively needs esctext, session_context, etc.
docker cp "$CQDS_SRC/agent/lib/."               "$CONTAINER:$MCP_DEST/lib/"

# globals.py stub — keep the injected server token aligned with copilot_mcp_tool.
printf '# globals stub for standalone mcp_server.py injection\nMCP_AUTH_TOKEN = "%s"\n' "$MCP_AUTH_TOKEN_VALUE" \
    | docker exec -i "$CONTAINER" sh -c "cat > $MCP_DEST/globals.py"

echo "[mcp_inject] Starting mcp_server.py inside $CONTAINER ..."
# Create required dirs and config if absent
docker exec "$CONTAINER" mkdir -p /app/logs /app/data
docker exec "$CONTAINER" sh -c "
    test -f /app/data/mcp_config.toml || printf '[security]\nadmin_ips = []\nadmin_subnet = \"\"\n' \
        > /app/data/mcp_config.toml
"
docker exec -d "$CONTAINER" sh -c "
    export PYTHONPATH=$MCP_DEST &&
    /opt/mcp-venv/bin/python3 $MCP_DEST/mcp_server.py \
        > /app/var/log/mcp_server.log 2>&1
"

sleep 2
echo "[mcp_inject] Checking mcp_server is listening ..."
docker exec "$CONTAINER" sh -c "ss -tlnp 2>/dev/null | grep 8084 || netstat -tlnp 2>/dev/null | grep 8084 || echo 'port check: ss/netstat not available'"

echo "[mcp_inject] Done. Test via nginx proxy:"
echo "  curl http://localhost:8008/mcp/$CONTAINER/process/list"
