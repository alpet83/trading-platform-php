#!/bin/bash
# ============================================================================
# restore-from-backup.sh — Restore deployment state from pre-deploy backup
# ============================================================================
# ============================================================================
# restore-from-backup.sh — Restore deployment state from pre-deploy backup
# ============================================================================
# Usage: ./scripts/restore-from-backup.sh <TIMESTAMP>
#
# Example:
#   ./scripts/restore-from-backup.sh 20260413_123045
#
# This script:
# 1. Restores all database dumps using mariadb (NOT mysql CLI)
# 2. Recreates and restores named volume (mariadb-data)
# 3. Restores configuration files (.env, db_config.php)
# 4. Provides status check
#
# Database Tool: mariadb-dump (NOT mysqldump, which is legacy/unmaintained)
#
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="${PROJECT_ROOT}/docker-compose.yml"
COMPOSE_PROJECT="${COMPOSE_PROJECT_NAME:-trd}"

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ============================================================================
# Helper functions
# ============================================================================

log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $*"
}

warn() {
    echo -e "${YELLOW}⚠ WARNING:${NC} $*"
}

success() {
    echo -e "${GREEN}✓${NC} $*"
}

error() {
    echo -e "${RED}✗ ERROR:${NC} $*"
    exit 1
}

confirm() {
    local prompt="$1"
    local response
    echo -ne "${YELLOW}${prompt}${NC} (yes/no): "
    read -r response
    [[ "$response" == "yes" ]]
}

# ============================================================================
# Validation
# ============================================================================

if [[ $# -eq 0 ]]; then
    echo "Usage: $0 <TIMESTAMP>"
    echo ""
    echo "Available backups:"
    find "${PROJECT_ROOT}/var/backup/pre-deploy" -maxdepth 1 -type d -name "[0-9]*" | sort -r | head -10 | xargs -I {} basename {} | while read ts; do
        local dir="${PROJECT_ROOT}/var/backup/pre-deploy/${ts}"
        local size=$(du -sh "$dir" | cut -f1)
        echo "  - $ts ($size)"
    done
    exit 1
fi

TIMESTAMP="$1"
BACKUP_DIR="${PROJECT_ROOT}/var/backup/pre-deploy/${TIMESTAMP}"

if [[ ! -d "$BACKUP_DIR" ]]; then
    error "Backup directory not found: $BACKUP_DIR"
fi

log "Using backup: $TIMESTAMP"
log "Backup location: $BACKUP_DIR"
echo ""

# ============================================================================
# Pre-restore checks
# ============================================================================

echo -e "${RED}WARNING:${NC} This will restore services to their pre-deployment state."
echo "  - Running containers will be stopped"
echo "  - Configuration files will be restored"
echo "  - Database will be restored from dump"
echo "  - Named volume will be restored (if backup exists)"
echo ""
if ! confirm "Proceed with restoration?"; then
    echo "Cancelled by user"
    exit 0
fi

# ============================================================================
# Main restoration
# ============================================================================

echo ""
echo "============================================================================"
echo "Starting restoration from backup: $TIMESTAMP"
echo "============================================================================"
echo ""

# Stop services if running
log "Stopping any running services..."
if docker ps | grep -q "${COMPOSE_PROJECT}-"; then
    docker-compose -f "$COMPOSE_FILE" down 2>/dev/null || warn "Services already stopped"
    success "Services stopped"
fi

sleep 1

# Restore configurations
log "Restoring configuration files..."

if [[ -f "${BACKUP_DIR}/.env.bak" ]]; then
    cp "${BACKUP_DIR}/.env.bak" "${PROJECT_ROOT}/.env"
    success "Restored: .env"
fi

if [[ -f "${BACKUP_DIR}/db_config.php.bak" ]]; then
    mkdir -p "${PROJECT_ROOT}/secrets"
    cp "${BACKUP_DIR}/db_config.php.bak" "${PROJECT_ROOT}/secrets/db_config.php"
    success "Restored: secrets/db_config.php"
fi

if [[ -f "${BACKUP_DIR}/docker-compose.override.yml.bak" ]]; then
    cp "${BACKUP_DIR}/docker-compose.override.yml.bak" "${PROJECT_ROOT}/docker-compose.override.yml"
    success "Restored: docker-compose.override.yml"
fi

echo ""

# Restore volume backup if exists
log "Checking for volume backup..."

local VOLUME_BACKUP="${BACKUP_DIR}/${COMPOSE_PROJECT}_mariadb_data.backup.${TIMESTAMP}.tar.gz"
if [[ -f "$VOLUME_BACKUP" ]]; then
    log "Restoring named volume..."
    
    # Remove existing volume if present
    if docker volume ls --format "table {{.Name}}" | grep -q "^${COMPOSE_PROJECT}_mariadb_data$"; then
        log "  → Removing existing volume..."
        docker volume rm "${COMPOSE_PROJECT}_mariadb_data" 2>/dev/null || warn "Could not remove volume"
    fi
    
    # Recreate volume and restore data
    log "  → Creating volume..."
    docker volume create "${COMPOSE_PROJECT}_mariadb_data"
    
    log "  → Restoring data from archive..."
    docker run --rm \
        -v "${COMPOSE_PROJECT}_mariadb_data":/data \
        -v "$BACKUP_DIR":/backup:ro \
        alpine tar xzf "/backup/$(basename "$VOLUME_BACKUP")" -C /data
    
    success "Volume restored"
else
    warn "No volume backup found at: $(basename "$VOLUME_BACKUP")"
    warn "Volume will be empty; use database restoration instead"
fi

echo ""

# Start services
log "Starting services..."
docker-compose -f "$COMPOSE_FILE" up -d 2>&1 | grep -E "Creating|Starting|^[a-z]" || true
success "Services started"

echo ""

# Wait for mariadb health
log "Waiting for database to become healthy..."
local max_attempts=30
local attempt=1
while [[ $attempt -le $max_attempts ]]; do
    if docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" \
        -e "SELECT 1;" > /dev/null 2>&1; then
        success "Database is ready"
        break
    fi
    echo -n "."
    sleep 1
    ((attempt++)
done

if [[ $attempt -gt $max_attempts ]]; then
    warn "Database did not become healthy within timeout; continuing anyway"
fi

echo ""

# Restore databases (if volume restore didn't work)
log "Checking database dumps for direct restoration..."

find "$BACKUP_DIR" -maxdepth 1 -name "*_dump_*.sql.gz" | while read dump_file; do
    local db_name=$(basename "$dump_file" | sed "s/_dump_.*/")
    log "  → Restoring database: $db_name"
    
    # Check if database already exists (from volume restore)
    if docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -uroot \
        -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" \
        -e "SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$db_name';" 2>/dev/null | grep -q 1; then
        warn "  Database $db_name already exists (skipping dump restore)"
    else
        zcat "$dump_file" | docker-compose -f "$COMPOSE_FILE" exec -T mariadb \
            mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" || warn "Could not restore $db_name from dump"
        success "  Restored dump: $db_name"
    fi
done

echo ""

# Final status
log "Restoration complete. Current status:"
echo ""
docker-compose -f "$COMPOSE_FILE" ps

echo ""
echo "============================================================================"
echo -e "${GREEN}✓ Restoration completed${NC}"
echo "============================================================================"
echo ""
echo "To verify restored state, run:"
echo "  ${GREEN}./scripts/verify-simple-deploy.sh${NC}"
echo ""
echo "To return to clean deployment testing:"
echo "  ${GREEN}./scripts/prepare-clean-deploy.sh --force${NC}"
echo ""
