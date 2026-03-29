#!/bin/bash
#
# init-pass.sh — Initialize Pass & GPG in batch mode (non-interactive)
#
# Usage:
#   docker-compose --profile init run pass-init
#
# Or standalone:
#   bash scripts/init-pass.sh
#
# This script:
#   1. Creates GPG master key (batch mode)
#   2. Initializes Pass store
#   3. Creates test credentials
#   4. Verifies everything works

set -e

# Configuration
GPG_USER_EMAIL="${GPG_USER_EMAIL:-tradebot@local.example.com}"
GPG_USER_NAME="${GPG_USER_NAME:-TradeBot}"
PASS_STORE="${PASS_STORE_DIR:-.password-store}"
TEST_KEY_PATH="trading/api_keys/test"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() {
  echo -e "${GREEN}[ℹ️ INFO]${NC} $@"
}

log_warn() {
  echo -e "${YELLOW}[⚠️  WARN]${NC} $@"
}

log_error() {
  echo -e "${RED}[❌ ERROR]${NC} $@"
}

# =============================================================================
# Step 1: Install dependencies
# =============================================================================
log_info "Installing dependencies..."
apt-get update -qq
apt-get install -y -qq pass gnupg2 >/dev/null 2>&1

# =============================================================================
# Step 2: Create GPG key in batch mode
# =============================================================================
log_info "Checking for existing GPG keys..."

if gpg --list-keys "$GPG_USER_EMAIL" >/dev/null 2>&1; then
  log_warn "GPG key already exists for $GPG_USER_EMAIL, skipping key generation"
  GPG_KEY_ID=$(gpg --list-keys "$GPG_USER_EMAIL" 2>/dev/null | grep "pub" | head -1 | awk '{print $2}' | cut -d'/' -f2)
else
  log_info "Generating GPG key ($GPG_USER_NAME <$GPG_USER_EMAIL>)..."
  
  # Batch key generation parameters
  cat > /tmp/gpg-batch << EOF
Key-Type: RSA
Key-Length: 4096
Name-Real: $GPG_USER_NAME
Name-Email: $GPG_USER_EMAIL
Expire-Date: 0
%no-ask-passphrase
%no-protection
EOF
  
  gpg --batch --generate-key /tmp/gpg-batch 2>&1 | grep -v "gpg: " || true
  
  GPG_KEY_ID=$(gpg --list-keys "$GPG_USER_EMAIL" 2>/dev/null | grep "pub" | head -1 | awk '{print $2}' | cut -d'/' -f2)
  log_info "✓ GPG key generated: $GPG_KEY_ID"
  
  rm -f /tmp/gpg-batch
fi

# =============================================================================
# Step 3: Initialize Pass store
# =============================================================================
log_info "Initializing Pass store..."

if [ -d "$PASS_STORE/.git" ]; then
  log_warn "Pass store already initialized in $PASS_STORE, skipping init"
else
  if [ -d "$PASS_STORE" ]; then
    log_warn "$PASS_STORE exists but not initialized, reinitializing..."
  fi
  
  pass init "$GPG_KEY_ID" >/dev/null 2>&1 || {
    log_error "Failed to initialize Pass store"
    exit 1
  }
  
  log_info "✓ Pass store initialized"
fi

# =============================================================================
# Step 4: Create test credentials
# =============================================================================
log_info "Creating test credentials in Pass store..."

# Create directory structure
mkdir -p "$PASS_STORE/trading/api_keys"

# Check if test key already exists
if pass show "$TEST_KEY_PATH" >/dev/null 2>&1; then
  log_warn "Test key already exists at $TEST_KEY_PATH"
else
  # Generate test API key (example format)
  TEST_API_KEY="sk_test_$(openssl rand -hex 16)"
  
  # Store in Pass (non-interactively)
  echo "$TEST_API_KEY" | pass insert -f "$TEST_KEY_PATH" >/dev/null 2>&1
  
  log_info "✓ Test key created: $TEST_KEY_PATH"
fi

# =============================================================================
# Step 5: Verification
# =============================================================================
log_info "Verifying installation..."

echo ""
echo "GPG Keys:"
gpg --list-keys --with-colons 2>/dev/null | grep "^pub" | cut -d: -f10 | head -3

echo ""
echo "Pass Store Status:"
pass ls 2>/dev/null | head -5

echo ""
log_info "✓ Pass & GPG initialized successfully!"
echo ""
echo "Next steps:"
echo "  1. Mount ./secrets/gnupg and ./secrets/pass-store in Docker"
echo "  2. Start bot-manager: docker-compose --profile pass up -d trd-bot-manager"
echo "  3. Bot-manager will read credentials from Pass"
echo ""
echo "To add more credentials:"
echo "  pass insert trading/api_keys/my_new_key"
echo "  echo 'sk_live_xxx' | pass insert -f trading/api_keys/another_key"
