#!/bin/bash
# ============================================================================
# prepare-clean-deploy.sh — Prepare environment for clean test deployment
# ============================================================================
# ============================================================================
# prepare-clean-deploy.sh — Prepare environment for clean test deployment
# ============================================================================
# Usage: ./scripts/prepare-clean-deploy.sh [--force]
#
# This script performs pre-deployment cleanup while preserving current state:
# 1. Backup all databases (trading, exchange-specific, datafeed)
# 2. Stop all services gracefully (bots-hive → web → datafeed → mariadb)
# 3. Rename/backup named volume (mariadb-data → mariadb-data.backup.TIMESTAMP)
# 4. Remove containers and cleanup orphaned resources
# 5. Create metadata file for rollback capability
#
# Database Backup Method:
# - Uses mariadb-dump (NOT mysqldump, which is legacy/unreliable)
# - Text-based SQL with gzip compression for portability
# - .env and db_config.php also backed up for password reference
#
# Safety features:
# - Requires explicit --force flag or interactive confirmation
# - Creates timestamped backups with recovery instructions
# - Validates compose.yml and .env before proceeding
# - Provides detailed rollback procedures
# ============================================================================

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="${PROJECT_ROOT}/docker-compose.yml"
COMPOSE_PROJECT="${COMPOSE_PROJECT_NAME:-trd}"
BACKUP_TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_DIR="${PROJECT_ROOT}/var/backup/pre-deploy/${BACKUP_TIMESTAMP}"
LOGS_BACKUP_DIR="${PROJECT_ROOT}/var/logs/pre-deploy/${BACKUP_TIMESTAMP}"
METADATA_FILE="${PROJECT_ROOT}/var/backup/pre-deploy/deploy-state_${BACKUP_TIMESTAMP}.md"
FORCE_MODE="${1:-}"

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

is_container_running() {
    local name="$1"
    docker ps --format "{{.Names}}" | grep -qx "$name"
}

# ============================================================================
# Pre-flight checks
# ============================================================================

preflight_check() {
    log "Running preflight checks..."

    # Check required tools (mariadb-dump is used via docker-compose exec, not directly on host)
    local tools=("docker" "docker-compose")
    for tool in "${tools[@]}"; do
        if ! command -v "$tool" &> /dev/null; then
            error "Required tool not found: $tool"
        fi
    done
    success "All required tools available"

    # Validate compose file exists
    if [[ ! -f "$COMPOSE_FILE" ]]; then
        error "docker-compose.yml not found at $COMPOSE_FILE"
    fi
    success "docker-compose.yml found"

    # Validate docker connectivity
    if ! docker ps &> /dev/null; then
        error "Cannot connect to Docker daemon"
    fi
    success "Docker daemon is accessible"

    # Check if services are running
    if ! docker-compose -f "$COMPOSE_FILE" ps 2>&1 | grep -q "Up"; then
        warn "No services currently running"
    else
        log "Services detected:"
        docker-compose -f "$COMPOSE_FILE" ps | grep -E "Up|Exited" || true
    fi

    log "Preflight checks completed"
}

# ============================================================================
# Database operations
# ============================================================================

backup_databases() {
    log "Backing up all databases..."
    mkdir -p "$BACKUP_DIR"

    # Get container status
    local db_container="${COMPOSE_PROJECT}-mariadb"
    if ! docker ps --format "table {{.Names}}" | grep -q "^${db_container}$"; then
        error "Database container ($db_container) is not running. Cannot backup."
    fi

    # Get database list
    local db_list
    db_list=$(docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb -uroot \
        -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" \
        -e "SELECT GROUP_CONCAT(schema_name) FROM information_schema.schemata WHERE schema_name NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys');" \
        2>/dev/null | tail -1)

    if [[ -z "$db_list" ]]; then
        warn "No user databases found to backup"
        return 0
    fi

    IFS=',' read -ra databases <<< "$db_list"
    log "Found ${#databases[@]} database(s) to backup"

    for db in "${databases[@]}"; do
        db=$(echo "$db" | xargs)  # trim whitespace
        log "  → Backing up database: $db"

        local dump_file="${BACKUP_DIR}/${db}_dump_${BACKUP_TIMESTAMP}.sql.gz"
        docker-compose -f "$COMPOSE_FILE" exec -T mariadb mariadb-dump \
            -uroot -p"${MARIADB_ROOT_PASSWORD:-root_change_me}" \
            --single-transaction --skip-lock-tables \
            "$db" 2>/dev/null | gzip > "$dump_file"

        if [[ -f "$dump_file" ]]; then
            local size=$(du -h "$dump_file" | cut -f1)
            success "Database backup: $db → $(basename "$dump_file") ($size)"
        else
            error "Failed to backup database: $db"
        fi
    done

    log "Database backups completed → $BACKUP_DIR"
}

stop_write_containers_before_backup() {
    log "Stopping write-capable containers before backup..."

    local writers=("${COMPOSE_PROJECT}-bots-hive" "${COMPOSE_PROJECT}-web")
    for container in "${writers[@]}"; do
        if is_container_running "$container"; then
            log "  → Stopping writer container: $container"
            docker stop "$container" --timeout=15 >/dev/null
        fi
    done

    sleep 2

    for container in "${writers[@]}"; do
        if is_container_running "$container"; then
            error "Writer container still running before backup: $container"
        fi
    done

    success "Writers confirmed stopped before backup (trd-bots-hive, trd-web)"
}

verify_backup_artifacts() {
    log "Verifying backup artifacts on disk..."

    local dump_count
    dump_count=$(find "$BACKUP_DIR" -maxdepth 1 -name "*_dump_${BACKUP_TIMESTAMP}.sql.gz" -type f | wc -l | xargs)
    if [[ "$dump_count" -lt 1 ]]; then
        error "No database dump files found in $BACKUP_DIR"
    fi

    local original_volume="${COMPOSE_PROJECT}_mariadb_data"
    local parked_volume="${original_volume}.parked.${BACKUP_TIMESTAMP}"
    local volume_archive="${BACKUP_DIR}/${original_volume}.backup.${BACKUP_TIMESTAMP}.tar.gz"
    if [[ ! -s "$volume_archive" ]]; then
        error "Expected volume archive is missing or empty: $volume_archive"
    fi

    success "All backup artifacts verified"
}

# ============================================================================
# Configuration file backup
# ============================================================================

backup_configuration_files() {
    log "Backing up configuration files..."

    if [[ -f "${PROJECT_ROOT}/.env" ]]; then
        cp "${PROJECT_ROOT}/.env" "${BACKUP_DIR}/.env.bak"
        chmod 600 "${BACKUP_DIR}/.env.bak"
        success "Backup: .env"
    else
        warn ".env not found"
    fi

    if [[ -f "${PROJECT_ROOT}/secrets/db_config.php" ]]; then
        cp "${PROJECT_ROOT}/secrets/db_config.php" "${BACKUP_DIR}/db_config.php.bak"
        chmod 600 "${BACKUP_DIR}/db_config.php.bak"
        success "Backup: secrets/db_config.php"
    else
        warn "secrets/db_config.php not found"
    fi

    if [[ -f "${PROJECT_ROOT}/docker-compose.override.yml" ]]; then
        cp "${PROJECT_ROOT}/docker-compose.override.yml" "${BACKUP_DIR}/docker-compose.override.yml.bak"
        success "Backup: docker-compose.override.yml"
    fi

    log "Configuration file backups completed"
}

# ============================================================================
# Volume backup
# ============================================================================

backup_named_volumes() {
    log "Backing up named volumes..."

    local original_volume="${COMPOSE_PROJECT}_mariadb_data"
    local parked_volume="${original_volume}.parked.${BACKUP_TIMESTAMP}"

    # Backup volume using tar through container mount
    log "  → Archiving volume $original_volume..."

    local archive_file="${BACKUP_DIR}/${original_volume}.backup.${BACKUP_TIMESTAMP}.tar.gz"
    local tmp_archive="${archive_file}.tmp"

    docker run --rm \
        -v "${original_volume}:/source:ro" \
        -v "$(cd "$BACKUP_DIR" && pwd)":/backup \
        alpine sh -c "tar czf /backup/$(basename "$archive_file") -C /source ." || {
        rm -f "$tmp_archive"
        error "Failed to archive volume $original_volume"
    }

    if [[ -f "$archive_file" ]]; then
        local size=$(du -h "$archive_file" | cut -f1)
        success "Volume backup: $original_volume → $(basename "$archive_file") ($size)"
    else
        error "Archive file was not created"
    fi

    # Rename the actual volume
    log "  → Renaming volume $original_volume → $parked_volume..."
    docker volume rename "${original_volume}" "${parked_volume}" || error "Failed to rename volume"

    success "Volume renamed: $parked_volume"
}

# ============================================================================
# Cleanup operations
# ============================================================================

cleanup_containers() {
    log "Cleaning up containers..."

    log "  → Stopping remaining services..."
    docker-compose -f "$COMPOSE_FILE" down --remove-orphans 2>/dev/null || warn "Some containers failed to stop"

    success "Containers stopped and cleaned"
}

# ============================================================================
# Metadata generation
# ============================================================================

generate_metadata() {
    log "Generating metadata file..."
    mkdir -p "$(dirname "$METADATA_FILE")"

    cat > "$METADATA_FILE" <<EOF
# Pre-Deployment State Snapshot
**Timestamp:** $BACKUP_TIMESTAMP
**Location:** $BACKUP_DIR

## Backup Contents

### Database Dumps
$(find "$BACKUP_DIR" -maxdepth 1 -name "*.sql.gz" -exec basename {} \; | sed 's/^/- /')

### Configuration Files
$(find "$BACKUP_DIR" -maxdepth 1 -name "*.bak" -exec basename {} \; | sed 's/^/- /')

### Volume Archives
$(find "$BACKUP_DIR" -maxdepth 1 -name "*.tar.gz" -exec basename {} \; | sed 's/^/- /')

## Restore Instructions

### Complete Restoration
\`\`\`bash
./scripts/restore-from-backup.sh $BACKUP_TIMESTAMP
\`\`\`

### Database Restoration Only
\`\`\`bash
# Requires: mariadb running, .env configured
docker-compose exec -T mariadb mariadb -uroot -p\$MARIADB_ROOT_PASSWORD < <(zcat $BACKUP_DIR/trading_dump_*.sql.gz)
\`\`\`

### Volume Restoration Only
\`\`\`bash
docker volume rename ${COMPOSE_PROJECT}_mariadb_data.parked.${BACKUP_TIMESTAMP} ${COMPOSE_PROJECT}_mariadb_data
# or restore from archive:
docker volume create ${COMPOSE_PROJECT}_mariadb_data
docker run --rm -v ${COMPOSE_PROJECT}_mariadb_data:/data -v $BACKUP_DIR:/backup:ro alpine tar xzf /backup/trd_mariadb_data.backup.${BACKUP_TIMESTAMP}.tar.gz -C /data
\`\`\`

## Important Notes

- **Passwords:** All password hashes are in uploaded dumps; reference .env.bak for credentials
- **Backup Type:** Text-based SQL with gzip (platform-portable, not binary MariaDB format)
- **Tool Used:** mariadb-dump (NOT mysqldump—which is legacy/unmaintained)
- **Volume:** Named volume archived as tar.gz for cross-platform restore capability

---
Generated by: prepare-clean-deploy.sh
EOF

    success "Metadata: $(basename "$METADATA_FILE")"
}

# ============================================================================
# User confirmation
# ============================================================================

show_confirmation_prompt() {
    echo ""
    echo -e "${RED}════════════════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}WARNING: This will prepare the environment for CLEAN DEPLOYMENT TESTING${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "This script will:"
    echo "  1. Stop all running services (bots-hive, web, datafeed, mariadb)"
    echo "  2. Backup all databases to SQL (.gz) files"
    echo "  3. Backup all configuration files (.env, db_config.php)"
    echo "  4. Archive the named volume (mariadb-data)"
    echo "  5. Rename the volume to preserve old data"
    echo "  6. Remove all stopped containers"
    echo ""
    echo "Data will be preserved in: $BACKUP_DIR"
    echo ""
    echo "To restore later, run:"
    echo "  ./scripts/restore-from-backup.sh $BACKUP_TIMESTAMP"
    echo ""

    if [[ "$FORCE_MODE" != "--force" ]]; then
        if ! confirm "Proceed with clean deployment preparation?"; then
            echo "Cancelled by user"
            exit 0
        fi
    fi

    echo ""
    log "Proceeding with clean deployment preparation..."
    echo ""
}

# ============================================================================
# Main execution
# ============================================================================

main() {
    log "================================================================================"
    log "Preparing for clean test deployment"
    log "================================================================================"
    echo ""

    preflight_check
    echo ""

    show_confirmation_prompt

    # Execute backup sequence
    stop_write_containers_before_backup
    echo ""

    backup_databases
    echo ""

    backup_configuration_files
    echo ""

    backup_named_volumes
    echo ""

    cleanup_containers
    echo ""

    verify_backup_artifacts
    echo ""

    generate_metadata
    echo ""

    # Final summary
    echo ""
    echo -e "${GREEN}════════════════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}✓ Clean deployment preparation completed successfully${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "Backup location: $BACKUP_DIR"
    echo "Metadata file: $(basename "$METADATA_FILE")"
    echo ""
    echo "To restore this backup:"
    echo "  ${GREEN}./scripts/restore-from-backup.sh $BACKUP_TIMESTAMP${NC}"
    echo ""
    echo "To deploy fresh:"
    echo "  ${GREEN}./scripts/deploy-simple.ps1 -GeneratePasswords 'yes'${NC}"
    echo ""
}

main "$@"
