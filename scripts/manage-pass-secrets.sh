#!/bin/bash
# scripts/manage-pass-secrets.sh
#
# Admin utility for managing encrypted API secrets via pass (password store)
# Used by bot-manager service for API credential rotation
#
# Usage:
#   ./manage-pass-secrets.sh init                   # Initialize pass store
#   ./manage-pass-secrets.sh add api/bitmex         # Add new API secret
#   ./manage-pass-secrets.sh show api/bitmex        # Decrypt and display
#   ./manage-pass-secrets.sh sync                   # Sync to docker container
#   ./manage-pass-secrets.sh deploy                 # Deploy secrets to bot-manager

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PASS_STORE_HOST="${PROJECT_ROOT}/secrets/pass-store"
GNUPG_HOME="${PROJECT_ROOT}/secrets/gnupg"
CONTAINER_NAME="${COMPOSE_PROJECT_NAME:-trading-platform-php}-bot-manager"

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

# =====================================================
# COMMAND: init
# =====================================================
cmd_init() {
    log_info "Initializing pass store..."
    
    if command -v pass &> /dev/null; then
        log_info "pass is already installed"
    else
        log_error "pass is not installed. Install with: brew install pass (macOS) or apt-get install pass (Linux)"
        return 1
    fi
    
    if command -v gpg &> /dev/null; then
        log_info "gpg is available"
    else
        log_error "gpg is not installed. Install with: brew install gnupg (macOS) or apt-get install gnupg2 (Linux)"
        return 1
    fi
    
    # Check if GPG key exists
    if gpg --list-secret-keys | grep -q "trading-platform-bots"; then
        log_warn "GPG key 'trading-platform-bots' already exists"
        GPG_KEY_ID=$(gpg --list-secret-keys --with-colons | grep trading-platform-bots -A1 | grep fpr | cut -d: -f10)
    else
        log_info "Creating new GPG key pair (interactive)..."
        log_info "Use name: trading-platform-bots, email: bots@trading-platform"
        gpg --gen-key || return 1
        GPG_KEY_ID=$(gpg --list-secret-keys --with-colons | grep fpr | head -1 | cut -d: -f10)
    fi
    
    log_info "Initializing pass store with GPG key..."
    export PASSWORD_STORE_DIR="$PASS_STORE_HOST"
    pass init "${GPG_KEY_ID}" || {
        log_error "Failed to initialize pass store"
        return 1
    }
    
    log_info "✓ Pass store initialized at: $PASS_STORE_HOST"
    log_info "✓ GPG key: $GPG_KEY_ID"
}

# =====================================================
# COMMAND: add
# =====================================================
cmd_add() {
    local secret_name="$1"
    
    if [ -z "$secret_name" ]; then
        log_error "Usage: manage-pass-secrets.sh add <secret/name>"
        return 1
    fi
    
    if [ ! -d "$PASS_STORE_HOST" ]; then
        log_error "Pass store not initialized. Run: manage-pass-secrets.sh init"
        return 1
    fi
    
    log_info "Adding secret: $secret_name"
    export PASSWORD_STORE_DIR="$PASS_STORE_HOST"
    pass insert -m "$secret_name" || {
        log_error "Failed to add secret"
        return 1
    }
    
    log_info "✓ Secret added: $secret_name"
}

# =====================================================
# COMMAND: show
# =====================================================
cmd_show() {
    local secret_name="$1"
    
    if [ -z "$secret_name" ]; then
        log_error "Usage: manage-pass-secrets.sh show <secret/name>"
        return 1
    fi
    
    if [ ! -d "$PASS_STORE_HOST" ]; then
        log_error "Pass store not initialized. Run: manage-pass-secrets.sh init"
        return 1
    fi
    
    export PASSWORD_STORE_DIR="$PASS_STORE_HOST"
    pass show "$secret_name" || {
        log_error "Failed to show secret"
        return 1
    }
}

# =====================================================
# COMMAND: sync
# =====================================================
cmd_sync() {
    log_info "Syncing pass store and GPG keys to Docker volume..."
    
    if [ ! -d "$PASS_STORE_HOST" ]; then
        log_error "Pass store not found at: $PASS_STORE_HOST"
        return 1
    fi
    
    if [ ! -d "$GNUPG_HOME" ]; then
        mkdir -p "$GNUPG_HOME"
    fi
    
    # Copy pass store
    log_info "Syncing pass store..."
    cp -r ~/.password-store/* "$PASS_STORE_HOST/" 2>/dev/null || {
        log_warn "Could not sync ~/.password-store (may not exist yet)"
    }
    
    # Copy GPG keys
    log_info "Syncing GPG keyring..."
    cp -r ~/.gnupg/* "$GNUPG_HOME/" 2>/dev/null || {
        log_warn "Could not sync ~/.gnupg (may be restricted)"
    }
    
    # Set restrictive permissions
    chmod -R 700 "$PASS_STORE_HOST"
    chmod -R 700 "$GNUPG_HOME"
    
    log_info "✓ Secrets synced to Docker volumes"
    log_info "  Pass store: $PASS_STORE_HOST"
    log_info "  GPG keys:   $GNUPG_HOME"
}

# =====================================================
# COMMAND: deploy
# =====================================================
cmd_deploy() {
    log_info "Deploying API secrets to bot-manager container..."
    
    # Check if container is running
    if ! docker ps | grep -q "$CONTAINER_NAME"; then
        log_error "Container not running: $CONTAINER_NAME"
        log_info "Start with: docker compose --profile pass up -d bot-manager"
        return 1
    fi
    
    # Ensure pass is synced first
    cmd_sync
    
    log_info "Extracting API secrets from pass store..."
    export PASSWORD_STORE_DIR="$PASS_STORE_HOST"
    
    # Example API keys (customize as needed)
    BITMEX_KEY=$(pass show api/bitmex 2>/dev/null || echo "")
    BINANCE_KEY=$(pass show api/binance 2>/dev/null || echo "")
    SIGNALBOX_TOKEN=$(pass show api/signalbox 2>/dev/null || echo "")
    
    if [ -z "$BITMEX_KEY" ] && [ -z "$BINANCE_KEY" ] && [ -z "$SIGNALBOX_TOKEN" ]; then
        log_warn "No API secrets found in pass store"
        log_info "Add with: ./manage-pass-secrets.sh add api/bitmex"
        return 0
    fi
    
    log_info "Deploying to container..."
    
    # Create temporary PHP script to load secrets
    TEMP_SCRIPT=$(mktemp)
    cat > "$TEMP_SCRIPT" << 'EOF'
<?php
$errors = [];

// Load from pass store (via php -r)
$bitmex = getenv('API_BITMEX') ?: null;
$binance = getenv('API_BINANCE') ?: null;
$signalbox = getenv('API_SIGNALBOX') ?: null;

if ($bitmex) {
    // Store in Redis/Cache/DB as configured
    error_log("[API-SECRETS] Loaded Bitmex API key (length: " . strlen($bitmex) . ")");
} else {
    error_log("[API-SECRETS] Warning: Bitmex API key not provided");
}

if ($binance) {
    error_log("[API-SECRETS] Loaded Binance API key (length: " . strlen($binance) . ")");
} else {
    error_log("[API-SECRETS] Warning: Binance API key not provided");
}

if ($signalbox) {
    error_log("[API-SECRETS] Loaded SignalBox token (length: " . strlen($signalbox) . ")");
} else {
    error_log("[API-SECRETS] Warning: SignalBox token not provided");
}

echo json_encode([
    'status' => 'success',
    'loaded' => [
        'bitmex' => !empty($bitmex),
        'binance' => !empty($binance),
        'signalbox' => !empty($signalbox),
    ]
]);
?>
EOF
    
    # Execute in container
    docker exec \
        -e API_BITMEX="$BITMEX_KEY" \
        -e API_BINANCE="$BINANCE_KEY" \
        -e API_SIGNALBOX="$SIGNALBOX_TOKEN" \
        "$CONTAINER_NAME" \
        php -r "$(cat "$TEMP_SCRIPT")" 2>&1 || {
        log_error "Failed to deploy secrets to container"
        rm -f "$TEMP_SCRIPT"
        return 1
    }
    
    rm -f "$TEMP_SCRIPT"
    
    # Verify deployment
    log_info "Verifying deployment..."
    docker logs "$CONTAINER_NAME" | tail -5 | grep -i "api-secrets" || log_warn "Could not verify deployment (check logs)"
    
    log_info "✓ Secrets deployed to bot-manager"
}

# =====================================================
# COMMAND: list
# =====================================================
cmd_list() {
    if [ ! -d "$PASS_STORE_HOST" ]; then
        log_error "Pass store not initialized"
        return 1
    fi
    
    log_info "Secrets in pass store:"
    export PASSWORD_STORE_DIR="$PASS_STORE_HOST"
    pass ls || {
        log_error "Failed to list secrets"
        return 1
    }
}

# =====================================================
# COMMAND: help
# =====================================================
cmd_help() {
    cat << EOF
trading-platform-php: Pass Secrets Manager

Usage:
    $(basename "$0") <command> [arguments]

Commands:
    init                Initialize pass store and GPG keys
    add <path>         Add new secret (e.g., add api/bitmex)
    show <path>        Decrypt and display secret
    list               List all stored secrets
    sync               Sync pass-store and GPG keys to Docker volumes
    deploy             Deploy API secrets to bot-manager container
    help               Show this help message

Examples:
    # First-time setup
    ./$(basename "$0") init
    ./$(basename "$0") add api/bitmex
    ./$(basename "$0") add api/binance
    ./$(basename "$0") sync
    
    # Verify
    ./$(basename "$0") list
    ./$(basename "$0") show api/bitmex
    
    # Deploy to container
    docker compose --profile pass up -d bot-manager
    ./$(basename "$0") deploy

Environment Variables:
    PASS_STORE_DIR     Override pass store location (default: ./secrets/pass-store)
    GPG_HOME_DIR       Override GPG home (default: ./secrets/gnupg)

For more info: see docs/DOCKER_VOLUMES_AND_SECRETS.md
EOF
}

# =====================================================
# MAIN
# =====================================================

if [ $# -eq 0 ]; then
    cmd_help
    exit 0
fi

COMMAND="$1"
shift || true

case "$COMMAND" in
    init)
        cmd_init "$@"
        ;;
    add)
        cmd_add "$@"
        ;;
    show)
        cmd_show "$@"
        ;;
    list)
        cmd_list "$@"
        ;;
    sync)
        cmd_sync "$@"
        ;;
    deploy)
        cmd_deploy "$@"
        ;;
    help|--help|-h)
        cmd_help
        ;;
    *)
        log_error "Unknown command: $COMMAND"
        cmd_help
        exit 1
        ;;
esac
