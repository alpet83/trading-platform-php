#!/bin/bash
#
# test-ha-replication-pair.sh
# 
# Advanced end-to-end test for MariaDB HA pair with replication
# Tests PRIMARY + STANDBY containers on localhost (different ports)
# Uses docker-compose.test.yml with all watchdog services
#
# Usage:
#   ./test-ha-replication-pair.sh [setup|configure|test|monitor|teardown|full]
#
# Ports:
#   PRIMARY:  localhost:3306
#   STANDBY:  localhost:3307
#
# Examples:
#   ./test-ha-replication-pair.sh full      # Complete test suite
#   ./test-ha-replication-pair.sh setup     # Bring up containers
#   ./test-ha-replication-pair.sh configure # Configure replication channel
#   ./test-ha-replication-pair.sh test      # Run data replication tests
#   ./test-ha-replication-pair.sh monitor   # Watch replication status
#   ./test-ha-replication-pair.sh teardown  # Clean up

set -e

COMPOSE_FILE="docker-compose.test.yml"
TEST_DIR="./var/test-ha-pair"
LOG_DIR="./var/log/test-ha-pair"

# Test credentials (must match docker-compose.test.yml)
TEST_ROOT_PASS="test_root_123"
TEST_USER="trading"
TEST_USER_PASS="test_trading_456"
TEST_REPL_USER="test_repl"
TEST_REPL_PASS="test_repl_789"

# Container endpoints
PRIMARY_HOST="127.0.0.1"
PRIMARY_PORT="3306"
STANDBY_HOST="127.0.0.1"
STANDBY_PORT="3307"

# Timeouts (seconds)
READY_TIMEOUT=60
REPL_SYNC_TIMEOUT=45

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() {
  echo -e "${GREEN}[ℹ️ INFO]${NC} $@"
}

log_warn() {
  echo -e "${YELLOW}[⚠️ WARN]${NC} $@"
}

log_error() {
  echo -e "${RED}[❌ ERROR]${NC} $@"
}

log_debug() {
  echo -e "${BLUE}[🔍 DEBUG]${NC} $@"
}

# =============================================================================
# HEALTH CHECKS
# =============================================================================
check_mysql_ready() {
  local host=$1
  local port=$2
  local label=$3
  
  log_info "Waiting for $label to be ready ($host:$port)..."
  local elapsed=0
  while ! mysql -h"$host" -P"$port" -uroot -p"$TEST_ROOT_PASS" -e "SELECT 1" &>/dev/null; do
    if [ $elapsed -ge $READY_TIMEOUT ]; then
      log_error "$label failed to become ready"
      return 1
    fi
    echo -n "."
    sleep 1
    ((elapsed+=1))
  done
  echo ""
  log_info "✓ $label is ready"
  return 0
}

# =============================================================================
# SETUP: Bring up all containers
# =============================================================================
setup_test() {
  log_info "Setting up HA pair test environment..."
  
  # Create directories
  mkdir -p "$TEST_DIR" "$LOG_DIR"
  
  log_info "Starting containers (PRIMARY + STANDBY + watchdogs)..."
  docker-compose -f "$COMPOSE_FILE" up -d
  
  # Wait for both MariaDB instances
  check_mysql_ready "$PRIMARY_HOST" "$PRIMARY_PORT" "PRIMARY" || return 1
  check_mysql_ready "$STANDBY_HOST" "$STANDBY_PORT" "STANDBY" || return 1
  
  # Verify replication users exist
  log_info "Ensuring replication users are created..."
  mysql -h"$PRIMARY_HOST" -P"$PRIMARY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
    "CREATE USER IF NOT EXISTS '$TEST_REPL_USER'@'%' IDENTIFIED BY '$TEST_REPL_PASS'; \
     GRANT REPLICATION SLAVE ON *.* TO '$TEST_REPL_USER'@'%'; \
     FLUSH PRIVILEGES;" 2>/dev/null || log_warn "Replication users may already exist"
  
  log_info "✓ Setup complete"
  echo ""
  echo -e "Listening on:"
  echo -e "  PRIMARY: ${BLUE}mysql://127.0.0.1:3306${NC} (user: $TEST_USER / root)"
  echo -e "  STANDBY: ${BLUE}mysql://127.0.0.1:3307${NC} (user: $TEST_USER / root)"
}

# =============================================================================
# CONFIGURE: Set up replication channel from PRIMARY -> STANDBY
# =============================================================================
configure_replication() {
  log_info "Configuring replication from PRIMARY to STANDBY..."
  
  # Get PRIMARY GTID position
  log_info "[Step 1/3] Reading PRIMARY GTID position..."
  PRIMARY_GTID=$(mysql -h"$PRIMARY_HOST" -P"$PRIMARY_PORT" -uroot -p"$TEST_ROOT_PASS" -sNe \
    "SELECT @@gtid_binlog_pos;")
  log_debug "PRIMARY GTID: $PRIMARY_GTID"
  
  # Set STANDBY to read from PRIMARY using GTID
  log_info "[Step 2/3] Configuring STANDBY replication channel..."
  mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
    "STOP REPLICA;
     CHANGE MASTER TO
       MASTER_HOST='$PRIMARY_HOST',
       MASTER_PORT=$PRIMARY_PORT,
       MASTER_USER='$TEST_REPL_USER',
       MASTER_PASSWORD='$TEST_REPL_PASS',
       MASTER_USE_GTID=slave_pos,
       MASTER_CONNECT_RETRY=10;
     START REPLICA;" 2>/dev/null || {
    log_warn "CHANGE MASTER failed; trying MariaDB 11 syntax..."
    mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
      "STOP SLAVE;
       CHANGE MASTER TO
         MASTER_HOST='$PRIMARY_HOST',
         MASTER_PORT=$PRIMARY_PORT,
         MASTER_USER='$TEST_REPL_USER',
         MASTER_PASSWORD='$TEST_REPL_PASS',
         MASTER_USE_GTID=slave_pos,
         MASTER_CONNECT_RETRY=10;
       START SLAVE;"
  }
  
  log_info "[Step 3/3] Waiting for replication to sync..."
  local elapsed=0
  while true; do
    SLAVE_STATUS=$(mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" -uroot -p"$TEST_ROOT_PASS" -sNe \
      "SHOW SLAVE STATUS\G" | grep "Seconds_Behind_Master:" | awk '{print $2}')
    
    if [ "$SLAVE_STATUS" == "0" ] || [ "$SLAVE_STATUS" == "NULL" ]; then
      log_info "✓ Replication synchronized (lag: 0s)"
      break
    fi
    
    if [ $elapsed -ge $REPL_SYNC_TIMEOUT ]; then
      log_warn "Replication sync timeout; lag: ${SLAVE_STATUS}s (may recover)"
      break
    fi
    
    echo -n "."
    sleep 1
    ((elapsed+=1))
  done
  echo ""
  
  log_info "✓ Replication configured"
}

# =============================================================================
# TEST: Verify data replication
# =============================================================================
test_replication() {
  log_info "Running replication tests..."
  
  # Test 1: PRIMARY status
  log_info "[TEST 1/5] PRIMARY status..."
  mysql -h"$PRIMARY_HOST" -P"$PRIMARY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
    "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_binlog_pos AS 'GTID';"
  
  # Test 2: STANDBY status
  log_info "[TEST 2/5] STANDBY status..."
  mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
    "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_slave_pos AS 'GTID';"
  
  # Test 3: Create test data on PRIMARY
  log_info "[TEST 3/5] Writing test data to PRIMARY..."
  TEST_TIMESTAMP=$(date +%s)
  TEST_TABLE="test_pair_${TEST_TIMESTAMP}"
  
  mysql -h"$PRIMARY_HOST" -P"$PRIMARY_PORT" -u"$TEST_USER" -p"$TEST_USER_PASS" trading -e \
    "CREATE TABLE IF NOT EXISTS $TEST_TABLE (
       id INT AUTO_INCREMENT PRIMARY KEY,
       test_value VARCHAR(255),
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
     );
     INSERT INTO $TEST_TABLE (test_value) VALUES 
       ('replica_test_1'),
       ('replica_test_2'),
       ('replica_test_3');"
  
  log_info "✓ Test table created: $TEST_TABLE (3 rows)"
  
  # Test 4: Verify replication lag
  log_info "[TEST 4/5] Replication lag..."
  SLAVE_LAG=$(mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" -uroot -p"$TEST_ROOT_PASS" -sNe \
    "SHOW SLAVE STATUS\G" | grep "Seconds_Behind_Master:" | awk '{print $2}')
  
  if [ "$SLAVE_LAG" == "0" ] || [ "$SLAVE_LAG" == "NULL" ]; then
    log_info "✓ Replication lag: 0 seconds (or not applicable)"
  else
    log_warn "Replication lag: ${SLAVE_LAG}s"
  fi
  
  # Test 5: Verify data replicated to STANDBY
  log_info "[TEST 5/5] Verifying replicated data on STANDBY..."
  STANDBY_ROW_COUNT=$(mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" \
    -u"$TEST_USER" -p"$TEST_USER_PASS" trading -sNe \
    "SELECT COUNT(*) FROM $TEST_TABLE;")
  
  if [ "$STANDBY_ROW_COUNT" == "3" ]; then
    log_info "✓ All 3 rows replicated to STANDBY"
    mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" \
      -u"$TEST_USER" -p"$TEST_USER_PASS" trading -e \
      "SELECT * FROM $TEST_TABLE;"
  else
    log_error "Expected 3 rows on STANDBY, got $STANDBY_ROW_COUNT"
    return 1
  fi
  
  log_info "✓ All replication tests passed"
}

# =============================================================================
# MONITOR: Continuous replication status monitoring
# =============================================================================
monitor_replication() {
  log_info "Monitoring replication status (Ctrl+C to stop)..."
  echo ""
  
  local iteration=0
  while true; do
    clear
    echo -e "${GREEN}=== HA Pair Replication Monitor ===${NC}"
    echo "Iteration: $((++iteration)) | $(date '+%Y-%m-%d %H:%M:%S')"
    echo ""
    
    echo -e "${BLUE}--- PRIMARY (localhost:3306) ---${NC}"
    mysql -h"$PRIMARY_HOST" -P"$PRIMARY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
      "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_binlog_pos AS 'GTID Position';" 2>/dev/null || echo "PRIMARY: UNREACHABLE"
    
    echo ""
    echo -e "${BLUE}--- STANDBY (localhost:3307) ---${NC}"
    mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
      "SELECT @@server_id AS 'Server ID', @@read_only AS 'read_only', @@gtid_slave_pos AS 'GTID Position';" 2>/dev/null || echo "STANDBY: UNREACHABLE"
    
    echo ""
    echo -e "${BLUE}--- Replication Status ---${NC}"
    mysql -h"$STANDBY_HOST" -P"$STANDBY_PORT" -uroot -p"$TEST_ROOT_PASS" -e \
      "SHOW SLAVE STATUS\G" 2>/dev/null | grep -E "Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Last_Error" || echo "STANDBY SLAVE: NOT RUNNING"
    
    echo ""
    echo "Updated every 3 seconds... Press Ctrl+C to exit"
    sleep 3
  done
}

# =============================================================================
# TEARDOWN: Stop and remove all containers and test data
# =============================================================================
teardown_test() {
  log_info "Tearing down HA pair test environment..."
  
  log_info "Stopping containers..."
  docker-compose -f "$COMPOSE_FILE" down 2>/dev/null || true
  
  log_info "Removing test directories..."
  rm -rf ./var/lib/mysql-test-* ./var/backup/mysql-test-* ./var/log/mysql-test-* "$TEST_DIR" "$LOG_DIR" 2>/dev/null || true
  
  log_info "✓ Test environment removed"
}

# =============================================================================
# MAIN
# =============================================================================
main() {
  local cmd="${1:-full}"
  
  case "$cmd" in
    setup)
      setup_test
      ;;
    configure)
      configure_replication || exit 1
      ;;
    test)
      test_replication || exit 1
      ;;
    monitor)
      monitor_replication
      ;;
    teardown)
      teardown_test
      ;;
    full)
      setup_test || exit 1
      sleep 2
      configure_replication || exit 1
      sleep 2
      test_replication || exit 1
      log_info ""
      log_info "✅ FULL TEST SUITE PASSED"
      log_info ""
      echo "To monitor replication: ./test-ha-replication-pair.sh monitor"
      echo "To stop everything:     ./test-ha-replication-pair.sh teardown"
      ;;
    *)
      cat <<EOF
Usage: $0 [setup|configure|test|monitor|teardown|full]

Commands:
  setup       - Bring up PRIMARY + STANDBY + watchdog containers
  configure   - Configure GTID replication from PRIMARY → STANDBY
  test        - Run full replication test suite
  monitor     - Watch replication status (real-time, Ctrl+C to exit)
  teardown    - Stop all containers and remove test data
  full        - Complete test: setup → configure → test (recommended)

Test Environment:
  Compose File: $COMPOSE_FILE
  PRIMARY:      $PRIMARY_HOST:$PRIMARY_PORT (Server ID: 1)
  STANDBY:      $STANDBY_HOST:$STANDBY_PORT (Server ID: 2)
  
  Root Pass:    $TEST_ROOT_PASS
  App User:     $TEST_USER / $TEST_USER_PASS
  Repl User:    $TEST_REPL_USER / $TEST_REPL_PASS

Quick Start:
  1. ./test-ha-replication-pair.sh full      # Run complete test
  2. ./test-ha-replication-pair.sh monitor   # Monitor in real-time
  3. Ctrl+C then ./test-ha-replication-pair.sh teardown

Docs:
  See: docs/PAIR_REDUNDANCY_AUTOMATION.md for architecture details
EOF
      exit 1
      ;;
  esac
}

# Verify required tools
command -v docker-compose &>/dev/null || { log_error "docker-compose not found"; exit 1; }
command -v mysql &>/dev/null || { log_error "mysql CLI not found"; exit 1; }

main "$@"
