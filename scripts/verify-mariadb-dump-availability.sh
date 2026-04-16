#!/bin/bash
# ============================================================================
# verify-mariadb-dump-availability.sh — Diagnostic tool
# ============================================================================
# verify-mariadb-dump-availability.sh — Diagnostic tool
# ============================================================================
# Checks that mariadb-dump (NOT mysqldump) is available in all DB containers
#
# Usage: ./verify-mariadb-dump-availability.sh
#
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILE="${PROJECT_ROOT}/docker-compose.yml"

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

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
}

# ============================================================================
# Main checks
# ============================================================================

echo ""
echo "============================================================================"
echo "Verifying mariadb-dump Availability in Container Stack"
echo "============================================================================"
echo ""

# Get list of running containers
log "Scanning for database containers..."

mapfile -t containers < <(docker ps --format "table {{.Names}}" | grep -E "mariadb|mysql|db|sigsys" || echo "")

if [[ ${#containers[@]} -eq 0 ]]; then
    error "No database containers currently running"
    echo ""
    echo "Start services and try again:"
    echo "  docker-compose -f $COMPOSE_FILE up -d"
    echo ""
    exit 1
fi

echo "Found database containers:"
for container in "${containers[@]}"; do
    echo "  - $container"
done
echo ""

# Check each container
failed=0
for container in "${containers[@]}"; do
    echo -n "Checking $container ... "
    
    # Try mariadb-dump
    if docker exec "$container" which mariadb-dump > /dev/null 2>&1; then
        success "mariadb-dump found ✓"
        docker exec "$container" mariadb-dump --version 2>/dev/null || true
    else
        error "mariadb-dump NOT found ✗"
        ((failed++))
        
        # Check if mysqldump exists (should NOT)
        if docker exec "$container" which mysqldump > /dev/null 2>&1; then
            warn "  mysqldump found (legacy/unmaintained—should use mariadb-dump instead)"
        fi
    fi
done

echo ""

if [[ $failed -gt 0 ]]; then
    echo -e "${RED}════════════════════════════════════════════════════════════════${NC}"
    echo -e "${RED}FAILURE: $failed container(s) missing mariadb-dump${NC}"
    echo -e "${RED}════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "Action Required:"
    echo "1. Update Dockerfile to include mariadb-client package"
    echo "2. Rebuild container image"
    echo "3. Redeploy services"
    echo ""
    exit 1
else
    echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}SUCCESS: All database containers have mariadb-dump available${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo "Backup scripts are ready to use:"
    echo "  ${GREEN}./scripts/prepare-clean-deploy.sh --force${NC}"
    echo ""
    exit 0
fi
