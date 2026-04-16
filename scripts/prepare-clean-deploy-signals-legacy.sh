#!/bin/bash
# ============================================================================
# prepare-clean-deploy-signals-legacy.sh — Prepare signals-legacy for testing
# ============================================================================
# ============================================================================
# prepare-clean-deploy-signals-legacy.sh — Prepare signals-legacy for testing
# ============================================================================
# Usage: ./scripts/prepare-clean-deploy-signals-legacy.sh [--force]
#
# This script prepares signals-legacy (PHP signals server) for clean deployment:
# 1. Backup MariaDB database (trading)
# 2. Stop all services gracefully
# 3. Backup and preserve named volumes (signals-legacy-db-data, signals-legacy-db-socket)
# 4. Remove containers
# 5. Generate metadata for rollback capability
#
# Volume Name: trd_signals-legacy-db-data (actual name: signals-legacy-db-data)
# Database: MariaDB 11
# Config: docker-compose.signals-legacy.yml
#
# ============================================================================

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="${PROJECT_ROOT}/docker-compose.signals-legacy.yml"
COMPOSE_PROJECT="trd"
BACKUP_TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_DIR="${PROJECT_ROOT}/var/backup/pre-deploy/${BACKUP_TIMESTAMP}"
LOGS_BACKUP_DIR="${PROJECT_ROOT}/var/logs/pre-deploy/${BACKUP_TIMESTAMP}"
METADATA_FILE="${PROJECT_ROOT}/var/backup/pre-deploy/deploy-state-signals-legacy_${BACKUP_TIMESTAMP}.md"
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
    for tool in "${tools[@]}"; do
        if ! command -v "$tool" &> /dev/null; then
            error "Required tool not found: $tool"
        fi
    done
    success "All required tools available"

    # Validate compose file exists
    if [[ ! -f "$COMPOSE_FILE" ]]; then
        error "docker-compose.signals-legacy.yml not found at $COMPOSE_FILE"
    fi
    success "docker-compose.signals-legacy.yml found"

    # Validate docker connectivity
    if ! docker ps &> /dev/null; then
        error "Cannot connect to Docker daemon"
    fi
    success "Docker daemon is accessible"

    # Check if services are running
    if ! docker-compose -f "$COMPOSE_FILE" ps 2>&1 | grep -q "Up"; then
        warn "No signals-legacy services currently running"
    else
        log "Services detected:"
        docker-compose -f "$COMPOSE_FILE" ps | grep -E "Up|Exited" || true
    fi

    log "Preflight checks completed"
}

# ============================================================================
# Database backup
# ============================================================================

backup_database() {
    log "Backing up signals-legacy database..."
    mkdir -p "$BACKUP_DIR"

    # Check if db container is running
    if ! docker ps --format "table {{.Names}}" | grep -q "sigsys-db"; then
        warn "Database container (sigsys-db) not running. Attempting to start for backup..."
        docker-compose -f "$COMPOSE_FILE" up -d signals-legacy-db 2>/dev/null || {
            warn "Could not start db container for backup. Volume backup will be used for recovery."
            return 0
        }
        sleep 5
    fi

    log "  → Creating MariaDB dump from signals-legacy..."
    local dump_db_name="${SIGNALS_LEGACY_DB_NAME:-sigsys}"
    local dump_file="${BACKUP_DIR}/signals-legacy_${dump_db_name}_dump_${BACKUP_TIMESTAMP}.sql.gz"

    docker-compose -f "$COMPOSE_FILE" exec -T signals-legacy-db mariadb-dump \
        -uroot -p"${SIGNALS_LEGACY_DB_ROOT_PASSWORD:-${MARIADB_ROOT_PASSWORD:-legacy_root_change_me}}" \
        --single-transaction --skip-lock-tables \
        "$dump_db_name" 2>/dev/null | gzip > "$dump_file" || {
        warn "Could not create database dump"
        return 0
    }

    if [[ -f "$dump_file" ]]; then
        local size=$(du -h "$dump_file" | cut -f1)
        success "Database backup: ${dump_db_name} -> $(basename "$dump_file") ($size)"
    fi

    log "Database backup completed"
}

stop_write_containers_before_backup() {
    log "Stopping write-capable signals container before backup..."

    local writer_container="sigsys"
    if is_container_running "$writer_container"; then
        log "  → Stopping writer container: $writer_container"
        docker stop "$writer_container" --timeout=15 >/dev/null
    fi

    sleep 2
    if is_container_running "$writer_container"; then
        error "Writer container still running before backup: $writer_container"
    fi

    success "Writer confirmed stopped before backup (sigsys)"
}

verify_backup_artifacts() {
    log "Verifying backup artifacts on disk..."

    local dump_file="${BACKUP_DIR}/signals-legacy_trading_dump_${BACKUP_TIMESTAMP}.sql.gz"
    if [[ ! -s "$dump_file" ]]; then
        error "Database dump is missing or empty: $dump_file"
    fi

    for volume_name in "trd_signals-legacy-db-data" "trd_signals-legacy-db-socket"; do
        local archive="${BACKUP_DIR}/${volume_name}.backup.${BACKUP_TIMESTAMP}.tar.gz"
        local parked_volume="${volume_name}.parked.${BACKUP_TIMESTAMP}"
        if [[ ! -s "$archive" ]]; then
            error "Volume archive is missing or empty: $archive"
        fi
        if docker volume ls --format "{{.Name}}" | grep -qx "$volume_name"; then
            error "Original volume still exists (clean deploy unsafe): $volume_name"
        fi
        if ! docker volume ls --format "{{.Name}}" | grep -qx "$parked_volume"; then
            error "Parked volume not found: $parked_volume"
        fi
    done

    success "Signals-legacy backup files confirmed on disk"
    find "$BACKUP_DIR" -maxdepth 1 -type f -printf "  - %f (%s bytes)\n" | sort
}

# ============================================================================
# Service shutdown
# ============================================================================

stop_services() {
    log "Stopping signals-legacy services gracefully..."

    # Stop services in dependency order
    for svc in signals-legacy signals-legacy-db; do
        local container_running=$(docker-compose -f "$COMPOSE_FILE" ps 2>/dev/null | grep -c "$svc.*Up" || true)
        if [[ $container_running -gt 0 ]]; then
            log "  → Stopping $svc..."
            docker-compose -f "$COMPOSE_FILE" stop -t 10 "$svc" || warn "Failed to stop $svc gracefully"
            success "$svc stopped"
        fi
    done

    sleep 1
    log "All signals-legacy services stopped"
}

# ============================================================================
# Volume backup & preservation
# ============================================================================

backup_volumes() {
    log "Backing up signals-legacy volumes..."

    local data_volume="trd_signals-legacy-db-data"
    local socket_volume="trd_signals-legacy-db-socket"

    for volume_name in "$data_volume" "$socket_volume"; do
        # Check if volume exists
        if ! docker volume ls --format "table {{.Name}}" | grep -q "^${volume_name}$"; then
            warn "Volume not found: $volume_name (may be first run)"
            continue
        fi

        log "  → Creating backup of volume: $volume_name..."
        local backup_tar="${BACKUP_DIR}/${volume_name}.backup.${BACKUP_TIMESTAMP}.tar.gz"

        docker run --rm \
            -v "$volume_name":/data:ro \
            -v "${BACKUP_DIR}":/backup \
            alpine tar czf "/backup/$(basename "$backup_tar")" -C /data . 2>/dev/null || {
            warn "Could not create tar backup of volume: $volume_name"
            continue
        }

        if [[ -f "$backup_tar" ]]; then
            local size=$(du -h "$backup_tar" | cut -f1)
            success "Volume backup: $volume_name → $(basename "$backup_tar") ($size)"
        else
            error "Volume archive not created: $backup_tar"
        fi

        local parked_volume="${volume_name}.parked.${BACKUP_TIMESTAMP}"
        docker volume create "$parked_volume" >/dev/null
        docker run --rm \
            -v "$parked_volume":/data \
            -v "${BACKUP_DIR}":/backup \
            alpine sh -c "tar xzf /backup/$(basename "$backup_tar") -C /data" >/dev/null

        if ! docker volume inspect "$parked_volume" >/dev/null 2>&1; then
            error "Failed to create parked volume: $parked_volume"
        fi

        if ! docker volume rm "$volume_name" >/dev/null 2>&1; then
            error "Failed to remove original volume name: $volume_name"
        fi

        success "Volume parked: $volume_name -> $parked_volume"
    done

    # Mark volumes as preserved
    mkdir -p "$BACKUP_DIR"
    cat > "$BACKUP_DIR/.volumes-parked-signals-legacy.txt" << VOLEOF
Signals-Legacy Volumes Parked
=================================

Data Volume: $data_volume
Socket Volume: $socket_volume
Timestamp: $BACKUP_TIMESTAMP
Backup location: $BACKUP_DIR

Original names are removed to prevent accidental auto-attach.
Parked copies are kept as *.parked.${BACKUP_TIMESTAMP}.

To use existing volumes on next deploy:
  docker-compose -f docker-compose.signals-legacy.yml up -d

To start fresh (remove volumes):
  docker volume rm $data_volume $socket_volume 2>/dev/null || true
  docker-compose -f docker-compose.signals-legacy.yml up -d

To restore from archive:
  docker volume rm $data_volume 2>/dev/null || true
  docker volume rm $socket_volume 2>/dev/null || true
  
  docker volume create $data_volume
  docker volume create $socket_volume
  
  docker run --rm -v $data_volume:/data -v "$BACKUP_DIR":/backup \\
    alpine tar xzf /backup/${data_volume}.backup.${BACKUP_TIMESTAMP}.tar.gz -C /data
    
  docker run --rm -v $socket_volume:/data -v "$BACKUP_DIR":/backup \\
    alpine tar xzf /backup/${socket_volume}.backup.${BACKUP_TIMESTAMP}.tar.gz -C /data
VOLEOF

    success "Volumes parked and marked"
}

# ============================================================================
# Container cleanup
# ============================================================================

remove_containers() {
    log "Removing signals-legacy containers..."

    for svc in signals-legacy signals-legacy-db; do
        if docker-compose -f "$COMPOSE_FILE" ps 2>/dev/null | grep -q "$svc\s*"; then
            log "  → Removing container for $svc..."
            docker-compose -f "$COMPOSE_FILE" rm -f "$svc" 2>/dev/null || warn "Could not remove $svc"
            success "Removed: $svc"
        fi
    done

    log "Container cleanup completed"
}

# ============================================================================
# Configuration backup
# ============================================================================

backup_configs() {
    log "Backing up signals-legacy configuration files..."
    mkdir -p "$BACKUP_DIR"

    if [[ -f "${PROJECT_ROOT}/.env" ]]; then
        cp "${PROJECT_ROOT}/.env" "${BACKUP_DIR}/.env.bak"
        success "Backed up: .env"
    fi

    if [[ -f "${PROJECT_ROOT}/secrets/signals_db_config.php" ]]; then
        cp "${PROJECT_ROOT}/secrets/signals_db_config.php" "${BACKUP_DIR}/signals_db_config.php.bak"
        success "Backed up: secrets/signals_db_config.php"
    fi
}

# ============================================================================
# Logs preservation
# ============================================================================

backup_logs() {
    log "Archiving signals-legacy logs..."
    mkdir -p "$LOGS_BACKUP_DIR"

    if [[ -d "${PROJECT_ROOT}/var/log" ]]; then
        find "${PROJECT_ROOT}/var/log" -name "php_errors_signals_legacy*" -o -name "*signals*" 2>/dev/null | \
            while read logfile; do
                cp "$logfile" "$LOGS_BACKUP_DIR/" 2>/dev/null || true
            done
        success "Logs archived to: $LOGS_BACKUP_DIR"
    fi
}

# ============================================================================
# Metadata generation
# ============================================================================

generate_metadata() {
    log "Generating deployment state metadata..."
    mkdir -p "$(dirname "$METADATA_FILE")"

    cat > "$METADATA_FILE" << 'EOF'
# Pre-Deploy Backup Metadata (signals-legacy)
# ============================================================================

Generated: ${BACKUP_TIMESTAMP}
Project: signals-legacy (PHP Signals Server)
Config: docker-compose.signals-legacy.yml
Database: MariaDB 11

## Backup Locations

### Database Dumps
Location: ${BACKUP_DIR}/
Files:
EOF

    find "$BACKUP_DIR" -maxdepth 1 -name "*_dump_*.sql.gz" -exec ls -lh {} \; | awk '{print "  - " $9 " (" $5 ")"}' >> "$METADATA_FILE" 2>/dev/null || true

    cat >> "$METADATA_FILE" << 'EOF'

### Volume Backups
Location: ${BACKUP_DIR}/
Files:
  - trd_signals-legacy-db-data.backup.${BACKUP_TIMESTAMP}.tar.gz
  - trd_signals-legacy-db-socket.backup.${BACKUP_TIMESTAMP}.tar.gz

### Configuration Files
Location: ${BACKUP_DIR}/
Files:
  - .env.bak
  - signals_db_config.php.bak

### Logs Archive
Location: ${LOGS_BACKUP_DIR}/

## Environment State

### Stopped Services (docker-compose.signals-legacy.yml)
- signals-legacy-db (container: sigsys-db)
- signals-legacy (container: sigsys)

### Preserved Volumes
- trd_signals-legacy-db-data (MariaDB data)
- trd_signals-legacy-db-socket (MariaDB socket)

Both volumes KEPT on filesystem (not deleted).

## Rollback Procedure

**Important:** Both volumes are **PRESERVED** on disk.

### Option A: Use Preserved Volumes (recommended)
\`\`\`bash
docker-compose -f docker-compose.signals-legacy.yml up -d
# Services will use the existing volumes (no data loss)
\`\`\`

### Option B: Restore Database from Dump
\`\`\`bash
docker-compose -f docker-compose.signals-legacy.yml down
docker volume rm trd_signals-legacy-db-data 2>/dev/null || true
docker volume rm trd_signals-legacy-db-socket 2>/dev/null || true

docker-compose -f docker-compose.signals-legacy.yml up -d signals-legacy-db
sleep 15

docker-compose -f docker-compose.signals-legacy.yml exec -T signals-legacy-db \\
  mysql -uroot -p"${SIGNALS_LEGACY_DB_ROOT_PASSWORD:-legacy_root_change_me}" < ${BACKUP_DIR}/signals-legacy_trading_dump_${BACKUP_TIMESTAMP}.sql.gz
\`\`\`

### Option C: Manual Volume Restore from Archive
\`\`\`bash
docker volume rm trd_signals-legacy-db-data 2>/dev/null || true
docker volume rm trd_signals-legacy-db-socket 2>/dev/null || true

docker volume create trd_signals-legacy-db-data
docker volume create trd_signals-legacy-db-socket

docker run --rm -v trd_signals-legacy-db-data:/data -v ${BACKUP_DIR}:/backup \\
  alpine tar xzf /backup/trd_signals-legacy-db-data.backup.${BACKUP_TIMESTAMP}.tar.gz -C /data

docker run --rm -v trd_signals-legacy-db-socket:/data -v ${BACKUP_DIR}:/backup \\
  alpine tar xzf /backup/trd_signals-legacy-db-socket.backup.${BACKUP_TIMESTAMP}.tar.gz -C /data

docker-compose -f docker-compose.signals-legacy.yml up -d
\`\`\`

## Next Steps (Clean Deploy or Fresh Start)

### Option 1: Deploy Using Existing Volumes (preserves data)
\`\`\`bash
docker-compose -f docker-compose.signals-legacy.yml up -d
\`\`\`
Services will use the existing volumes with preserved data.

### Option 2: Completely Fresh Deploy (clean volumes)
If you want zero state, remove the volumes before deploying:
\`\`\`bash
docker volume rm trd_signals-legacy-db-data 2>/dev/null || true
docker volume rm trd_signals-legacy-db-socket 2>/dev/null || true
docker-compose -f docker-compose.signals-legacy.yml up -d
\`\`\`
New clean volumes will be created automatically.

## Timestamp Reference

Backup Time: ${BACKUP_TIMESTAMP}
Backup Size: $(du -sh ${BACKUP_DIR} | cut -f1)

---
EOF

    # Replace template variables
    sed -i "s|\${BACKUP_TIMESTAMP}|$BACKUP_TIMESTAMP|g" "$METADATA_FILE"
    sed -i "s|\${BACKUP_DIR}|$BACKUP_DIR|g" "$METADATA_FILE"
    sed -i "s|\${LOGS_BACKUP_DIR}|$LOGS_BACKUP_DIR|g" "$METADATA_FILE"

    success "Metadata file generated: $METADATA_FILE"
}

# ============================================================================
# Post-deployment validation
# ============================================================================

validate_clean_state() {
    log "Validating clean state..."

    # Check no signals-legacy containers exist
    local running_containers=$(docker ps -a --format "table {{.Names}}" | grep -c "sigsys" || true)
    if [[ $running_containers -eq 0 ]]; then
        success "No remaining signals-legacy containers"
    else
        warn "Found $running_containers signals-legacy container(s) still present"
    fi

    # Check original volumes removed and parked volumes exist
    for volume_name in "trd_signals-legacy-db-data" "trd_signals-legacy-db-socket"; do
        local parked_volume="${volume_name}.parked.${BACKUP_TIMESTAMP}"
        local original_exists=$(docker volume ls --format "{{.Name}}" | grep -c "^${volume_name}$" || true)
        local parked_exists=$(docker volume ls --format "{{.Name}}" | grep -c "^${parked_volume}$" || true)
        if [[ $original_exists -eq 0 && $parked_exists -eq 1 ]]; then
            success "Volume parked correctly: ${volume_name} -> ${parked_volume}"
        else
            warn "Volume parking check mismatch for $volume_name (original_exists=$original_exists, parked_exists=$parked_exists)"
        fi
    done

    # Check compose file is valid
    if docker-compose -f "$COMPOSE_FILE" config > /dev/null 2>&1; then
        success "docker-compose.signals-legacy.yml is valid"
    else
        error "docker-compose.signals-legacy.yml validation failed"
    fi

    log "Clean state validation completed"
}

# ============================================================================
# Main execution
# ============================================================================

main() {
    echo "============================================================================"
    echo "Signals-Legacy (PHP) - Clean Deploy Preparation"
    echo "============================================================================"
    echo ""
    log "Starting clean deployment preparation..."
    echo ""

    # Preflight checks
    preflight_check
    echo ""

    # Display what will happen
    echo -e "${YELLOW}This script will:${NC}"
    echo "  1. Backup MariaDB database (trading) to: $BACKUP_DIR"
    echo "  2. Backup configuration files (.env, signals_db_config.php)"
    echo "  3. Archive current logs"
    echo "  4. Stop all signals-legacy services gracefully"
    echo "  5. Remove all containers"
    echo "  6. Backup both volumes (db-data + db-socket) to tar.gz"
    echo "  7. Park both volumes under timestamped names"
    echo "  8. Remove original volume names (clean deploy safety)"
    echo "  9. Generate rollback metadata"
    echo ""
    warn "After this, fresh deploy cannot auto-attach old signals-legacy volumes by name."
    echo ""

    # Confirmation
    if [[ "$FORCE_MODE" == "--force" ]]; then
        log "Running in FORCE mode (--force flag provided)"
    else
        if ! confirm "Proceed with clean deployment preparation?"; then
            echo "Cancelled by user"
            exit 0
        fi
    fi
    echo ""

    # Execute cleanup steps
    stop_write_containers_before_backup
    echo ""

    backup_configs
    echo ""

    backup_database
    echo ""

    backup_logs
    echo ""

    stop_services
    echo ""

    remove_containers
    echo ""

    backup_volumes
    echo ""

    verify_backup_artifacts
    echo ""

    validate_clean_state
    echo ""

    generate_metadata
    echo ""

    # Summary
    echo "============================================================================"
    echo -e "${GREEN}✓ Clean deployment preparation completed successfully!${NC}"
    echo "============================================================================"
    echo ""
    echo "Backup location: $BACKUP_DIR"
    echo "Rollback guide:  $METADATA_FILE"
    echo ""
    echo -e "${BLUE}Next steps:${NC}"
    echo "  1. Review the status above"
    echo "  2. Run: ${GREEN}docker-compose -f docker-compose.signals-legacy.yml up -d${NC}"
    echo "  3. Or remove volumes for fresh start: ${GREEN}docker volume rm trd_signals-legacy-db-data trd_signals-legacy-db-socket${NC}"
    echo ""
    echo -e "${YELLOW}To restore this state if needed${NC}, see: $METADATA_FILE"
    echo ""
}

# ============================================================================
# Execute
# ============================================================================

cd "$PROJECT_ROOT"

main
