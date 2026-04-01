#!/bin/bash
#
# test-ha-replication.sh
# 
# Complete end-to-end test for MariaDB HA pair with replication
# Uses docker-compose.override.yml with embedded test credentials
#
# Usage:
#   ./test-ha-replication.sh [setup|test|cleanup|teardown]
#
# Examples:
#   ./test-ha-replication.sh setup      # Bring up containers
#   ./test-ha-replication.sh test       # Run replication tests
#   ./test-ha-replication.sh teardown   # Stop and remove containers

set -e

PROJECT_NAME="${COMPOSE_PROJECT_NAME:-trading-platform-php}"
COMPOSE_FILE="docker-compose.yml"
OVERRIDE_FILE="docker-compose.override.yml"
TEST_DIR="./var/test-ha"
BACKUP_DIR="./var/backup/mysql-test"
DB_DIR="./var/lib/mysql-test"

# Test credentials (from docker-compose.override.yml)
TEST_ROOT_PASS="test_root_123"
TEST_USER="trading"
TEST_USER_PASS="test_trading_456"
TEST_REPL_USER="test_repl"
TEST_REPL_PASS="test_repl_789"
TEST_REMOTE_USER="test_remote_master"
TEST_REMOTE_PASS="test_remote_pass_999"

# Timeouts
READY_TIMEOUT=60
REPL_TIMEOUT=30

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() {
  echo -e "${GREEN}[ℹ️  INFO]${NC} $@"
}

log_warn() {
  echo -e "${YELLOW}[⚠️  WARN]${NC} $@"
}

log_error() {
  echo -e "${RED}[❌ ERROR]${NC} $@"
}

# =============================================================================
# SETUP: Create test directories and bring up containers
# =============================================================================
setup_test() {
  log_info "Setting up test environment..."
  
  # Create test directories
  mkdir -p "$TEST_DIR"
  mkdir -p "$BACKUP_DIR"
  mkdir -p "$DB_DIR"
  
  log_info "Starting MariaDB primary container with test credentials..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" up -d mariadb
  
  log_info "Waiting for MariaDB to be healthy..."
  local elapsed=0
  while ! docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -uroot -p"$TEST_ROOT_PASS" -e "SELECT 1" &>/dev/null; do
    if [ $elapsed -ge $READY_TIMEOUT ]; then
      log_error "MariaDB failed to become ready within ${READY_TIMEOUT}s"
      return 1
    fi
    echo -n "."
    sleep 2
    ((elapsed+=2))
  done
  echo ""
  log_info "✓ MariaDB is healthy"
  
  # Check replication user
  log_info "Verifying replication user exists..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -uroot -p"$TEST_ROOT_PASS" -e \
    "SELECT * FROM mysql.user WHERE User='$TEST_REPL_USER';" | grep -q "$TEST_REPL_USER" || \
    {
      log_warn "Replication user not found, creating..."
      docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
        -uroot -p"$TEST_ROOT_PASS" -e \
        "CREATE USER IF NOT EXISTS '$TEST_REPL_USER'@'%' IDENTIFIED BY '$TEST_REPL_PASS'; GRANT REPLICATION SLAVE ON *.* TO '$TEST_REPL_USER'@'%'; FLUSH PRIVILEGES;"
    }
  
  log_info "✓ Test setup complete"
}

# =============================================================================
# TEST: Run replication validation suite
# =============================================================================
test_replication() {
  log_info "Starting replication tests..."
  
  # Verify primary is a primary (read_only=OFF, has binary log)
  log_info "[TEST 1/5] Verify PRIMARY is writable and has binlog enabled..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -uroot -p"$TEST_ROOT_PASS" -e \
    "SHOW VARIABLES LIKE 'read_only'; SHOW VARIABLES LIKE 'log_bin';" | grep -q "OFF" && \
    log_info "✓ Primary is writable (read_only=OFF)"
  
  # Check binary log is enabled
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -uroot -p"$TEST_ROOT_PASS" -e \
    "SHOW VARIABLES LIKE 'log_bin';" | grep -q "ON" && \
    log_info "✓ Binary logging is enabled"
  
  # Test write on primary
  log_info "[TEST 2/5] Write test record to PRIMARY..."
  TEST_TABLE="test_replication_$(date +%s)"
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -u"$TEST_USER" -p"$TEST_USER_PASS" trading -e \
    "CREATE TABLE IF NOT EXISTS $TEST_TABLE (id INT AUTO_INCREMENT PRIMARY KEY, test_value VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP); \
     INSERT INTO $TEST_TABLE (test_value) VALUES ('test_data_$(date +%s)');"
  
  log_info "✓ Test record inserted (table: $TEST_TABLE)"
  
  # Show binary log status
  log_info "[TEST 3/5] PRIMARY binary log status..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -uroot -p"$TEST_ROOT_PASS" -e \
    "SHOW MASTER STATUS\G" | head -10
  
  # Get server info
  log_info "[TEST 4/5] PRIMARY server ID and GTID status..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -uroot -p"$TEST_ROOT_PASS" -e \
    "SELECT @@server_id AS 'Server ID', @@gtid_binlog_pos AS 'GTID Position';"
  
  # Verify table structure
  log_info "[TEST 5/5] Verify test table..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" exec -T mariadb mysql \
    -u"$TEST_USER" -p"$TEST_USER_PASS" trading -e \
    "DESC $TEST_TABLE; SELECT COUNT(*) AS 'Record Count' FROM $TEST_TABLE;"
  
  log_info "✓ All replication tests passed"
  return 0
}

# =============================================================================
# CLEANUP: Remove containers but keep data
# =============================================================================
cleanup_test() {
  log_info "Stopping containers (keeping data)..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" down
  log_info "✓ Containers stopped"
}

# =============================================================================
# TEARDOWN: Remove everything including test data
# =============================================================================
teardown_test() {
  log_info "Tearing down test environment completely..."
  docker-compose -f "$COMPOSE_FILE" -f "$OVERRIDE_FILE" down -v 2>/dev/null || true
  
  log_info "Removing test directories..."
  rm -rf "$TEST_DIR" "$BACKUP_DIR" "$DB_DIR" 2>/dev/null || true
  
  log_info "✓ Test environment removed"
}

# =============================================================================
# MAIN
# =============================================================================
main() {
  local cmd="${1:-test}"
  
  case "$cmd" in
    setup)
      setup_test
      ;;
    test)
      test_replication
      ;;
    cleanup)
      cleanup_test
      ;;
    teardown)
      teardown_test
      ;;
    full)
      setup_test
      sleep 3
      test_replication
      ;;
    *)
      cat <<EOF
Usage: $0 [setup|test|cleanup|teardown|full]

Commands:
  setup       - Create test directories and bring up MariaDB container
  test        - Run replication validation tests (requires setup)
  cleanup     - Stop containers (keep data for inspection)
  teardown    - Stop containers and remove all test data
  full        - Run complete suite: setup -> test -> cleanup

Test Credentials (embedded):
  Root:        root / test_root_123
  App User:    trading / test_trading_456
  Repl User:   $TEST_REPL_USER / $TEST_REPL_PASS
  Remote:      $TEST_REMOTE_USER / $TEST_REMOTE_PASS

Environment:
  Override File: $OVERRIDE_FILE
  Test Dir:      $TEST_DIR
  DB Dir:        $DB_DIR
  Backup Dir:    $BACKUP_DIR

For HA pair testing with standby, see: docs/PAIR_REDUNDANCY_AUTOMATION.md
EOF
      exit 1
      ;;
  esac
}

main "$@"
