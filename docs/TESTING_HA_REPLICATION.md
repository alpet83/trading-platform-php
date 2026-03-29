# Testing MariaDB HA Replication Pair

## Overview

This document explains how to test the MariaDB HA pair setup locally using embedded test credentials and two different approaches:

1. **Single-container test** (simple validation)
2. **Full pair test** (realistic primary + standby scenario)

---

## Approach 1: Single-Instance Test (docker-compose.override.yml)

### When to Use
- Quick validation of a single MariaDB instance
- Testing backup/restore functionality
- Verifying environment variables work correctly
- Integration testing with web service

### Setup

```bash
# Use override file with test credentials
docker-compose -f docker-compose.yml -f docker-compose.override.yml up -d

# Watch startup
docker-compose -f docker-compose.yml -f docker-compose.override.yml logs -f mariadb
```

### Running Tests

```bash
# Option A: Use the script
./test-ha-replication.sh setup       # Bring up container
./test-ha-replication.sh test        # Run validation tests
./test-ha-replication.sh teardown    # Clean up

# Option B: Manual testing
mysql -h127.0.0.1 -uroot -p'test_root_123' -e "SHOW VARIABLES LIKE 'server_id'; SHOW VARIABLES LIKE 'log_bin';"

# Write test data
mysql -h127.0.0.1 -u'trading' -p'test_trading_456' trading -e \
  "CREATE TABLE test_write (id INT AUTO_INCREMENT PRIMARY KEY, data VARCHAR(255)); \
   INSERT INTO test_write (data) VALUES ('test_1'), ('test_2');"

# Verify backup was created
ls -la ./var/backup/mysql-test/
```

### Embedded Test Credentials

From `docker-compose.override.yml`:

```env
MARIADB_ROOT_PASSWORD = test_root_123
MARIADB_USER / MARIADB_PASSWORD = trading / test_trading_456
MARIADB_REPL_USER / MARIADB_REPL_PASSWORD = test_repl / test_repl_789
MARIADB_REMOTE_USER / MARIADB_REMOTE_PASSWORD = test_remote_master / test_remote_pass_999
```

### Cleanup

```bash
docker-compose -f docker-compose.yml -f docker-compose.override.yml down

# Remove test data directories
rm -rf ./var/lib/mysql-test ./var/backup/mysql-test
```

---

## Approach 2: Full HA Pair Test (docker-compose.test.yml)

### When to Use
- End-to-end replication validation
- Testing failover/recovery scenarios
- Verifying watchdog health checks
- Confirming GTID replication works
- Stress testing with multiple containers

### Architecture

```
┌─────────────────────────────────────┐
│                                     │
│  PRIMARY (localhost:3306)           │
│  - MariaDB Server ID: 1             │
│  - GTIDs: binary log enabled        │
│  - read_only: OFF                   │
│  - Watchdog: enforces role          │
│                                     │
└────────────────┬────────────────────┘
                 │ GTID replication
                 │ (test_repl user)
                 ▼
┌─────────────────────────────────────┐
│                                     │
│  STANDBY (localhost:3307)           │
│  - MariaDB Server ID: 2             │
│  - GTIDs: relay log enabled         │
│  - read_only: ON                    │
│  - Watchdog: auto-rejoin on lag     │
│                                     │
└─────────────────────────────────────┘
```

### Setup & Automated Testing

```bash
# Complete test suite (recommended)
./test-ha-replication-pair.sh full

# Or step by step:
./test-ha-replication-pair.sh setup       # Start PRIMARY + STANDBY + watchdogs
./test-ha-replication-pair.sh configure   # Set up GTID replication
./test-ha-replication-pair.sh test        # Run validation tests
./test-ha-replication-pair.sh monitor     # Watch live status (Ctrl+C to exit)
./test-ha-replication-pair.sh teardown    # Clean up everything
```

### Manual Testing

If you want to manually test the pair:

```bash
# 1. Bring up containers
docker-compose -f docker-compose.test.yml up -d

# 2. Wait for both to be healthy
docker-compose -f docker-compose.test.yml ps

# 3. Check PRIMARY status
mysql -h127.0.0.1 -P3306 -uroot -p'test_root_123' -e "SHOW MASTER STATUS\G"

# 4. Configure replication on STANDBY
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "CHANGE MASTER TO
     MASTER_HOST='127.0.0.1',
     MASTER_PORT=3306,
     MASTER_USER='test_repl',
     MASTER_PASSWORD='test_repl_789',
     MASTER_USE_GTID=slave_pos;
   START REPLICA;"

# 5. Check replication status
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e "SHOW SLAVE STATUS\G"

# 6. Write test data on PRIMARY
mysql -h127.0.0.1 -P3306 -u'trading' -p'test_trading_456' trading -e \
  "CREATE TABLE test_pair (
     id INT AUTO_INCREMENT PRIMARY KEY,
     test_value VARCHAR(255),
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );
   INSERT INTO test_pair (test_value) VALUES ('data_1'), ('data_2'), ('data_3');"

# 7. Verify replication on STANDBY
mysql -h127.0.0.1 -P3307 -u'trading' -p'test_trading_456' trading -e \
  "SELECT * FROM test_pair;"

# 8. Monitor in real-time
./test-ha-replication-pair.sh monitor

# 9. Cleanup
docker-compose -f docker-compose.test.yml down -v
```

### Test Credentials

From `docker-compose.test.yml`:

```env
# PRIMARY + STANDBY (same)
MARIADB_ROOT_PASSWORD = test_root_123
MARIADB_USER / MARIADB_PASSWORD = trading / test_trading_456

# Replication
MARIADB_REPL_USER / MARIADB_REPL_PASSWORD = test_repl / test_repl_789
MARIADB_REMOTE_USER / MARIADB_REMOTE_PASSWORD = test_remote_master / test_remote_pass_999

# Ports
PRIMARY:  127.0.0.1:3306
STANDBY:  127.0.0.1:3307
```

---

## Test Coverage Matrix

| Scenario | Script | Manual | Notes |
|----------|--------|--------|-------|
| Single write + backup | ✅ | ✅ | Validates startup backup, MARIADB_BACKUP_ON_START |
| Replication config | ❌ | ✅ | Requires two instances |
| GTID position sync | ✅ | ✅ | Full pair test only |
| Read-only enforcement | ⚠️ | ✅ | Watchdog enforces; need to verify SET GLOBAL |
| Data replication | ❌ | ✅ | Full pair test only |
| Watchdog auto-rejoin | ❌ | ⚠️ | Requires manual lag simulation; see Troubleshooting |
| Failover scenario | ❌ | ⚠️ | Advanced test; [see PAIR_REDUNDANCY_AUTOMATION.md](./PAIR_REDUNDANCY_AUTOMATION.md#failover-runbook) |

---

## Troubleshooting

### Container won't start

```bash
# Check logs
docker-compose -f docker-compose.test.yml logs mariadb-primary
docker-compose -f docker-compose.test.yml logs mariadb-standby

# Inspect volume mounts
ls -la ./var/lib/mysql-test-primary/
ls -la ./var/lib/mysql-test-standby/

# Clean up stuck volumes and retry
rm -rf ./var/lib/mysql-test-* ./var/backup/mysql-test-*
docker-compose -f docker-compose.test.yml up -d
```

### Replication lag or errors

```bash
# Check STANDBY replication status
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "SHOW SLAVE STATUS\G" | grep -E "Slave_IO_Running|Slave_SQL_Running|Last_Error|Seconds_Behind_Master"

# If replication is stopped, restart it
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "STOP REPLICA; START REPLICA;"

# Check for duplicate key errors
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "SHOW SLAVE STATUS\G" | grep "Last_Error"

# Reset and reconfigure if needed
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "RESET REPLICA; \
   CHANGE MASTER TO \
     MASTER_HOST='127.0.0.1', \
     MASTER_PORT=3306, \
     MASTER_USER='test_repl', \
     MASTER_PASSWORD='test_repl_789', \
     MASTER_USE_GTID=slave_pos; \
   START REPLICA;"
```

### Watchdog not running or erroring

```bash
# Check watchdog container logs
docker-compose -f docker-compose.test.yml logs watchdog-primary
docker-compose -f docker-compose.test.yml logs watchdog-standby

# Verify replication scripts exist
ls -la ./scripts/replication/

# Check if PRIMARY/STANDBY containers are healthy
docker-compose -f docker-compose.test.yml ps
```

### Data not replicating

```bash
# 1. Verify replication channel exists and is running
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e "SHOW SLAVE STATUS\G"

# 2. Force resync from PRIMARY
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "STOP REPLICA; \
   RESET REPLICA ALL; \
   CHANGE MASTER TO \
     MASTER_HOST='127.0.0.1', \
     MASTER_PORT=3306, \
     MASTER_USER='test_repl', \
     MASTER_PASSWORD='test_repl_789', \
     MASTER_USE_GTID=slave_pos; \
   START REPLICA;"

# 3. Wait a moment and check
sleep 2
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "SHOW SLAVE STATUS\G" | grep -E "Slave_IO_Running|Slave_SQL_Running"
```

---

## Integration with Production

### Moving test credentials to .env

Once testing is complete:

1. Update `.env` with production-grade credentials:
   ```env
   MARIADB_ROOT_PASSWORD=<strong-prod-pass>
   MARIADB_PASSWORD=<app-user-pass>
   MARIADB_REPL_USER=replication
   MARIADB_REPL_PASSWORD=<strong-repl-pass>
   MARIADB_REMOTE_USER=<master-user>
   MARIADB_REMOTE_PASSWORD=<strong-remote-pass>
   ```

2. Remove `docker-compose.override.yml` from production deployments

3. Use separate `.env` files per host:
   - Host A (primary): `MARIADB_SERVER_ID=1`, `MARIADB_BACKUP_ON_START=1`
   - Host B (standby): `MARIADB_SERVER_ID=2`, `PRIMARY_HOST=host-a-ip`

4. Use `docker-compose.replication.yml` instead of `docker-compose.test.yml`:
   ```bash
   docker-compose -f docker-compose.yml \
                  -f docker-compose.replication.yml \
                  --profile ha-primary \
                  up -d
   ```

---

## Performance Baseline (Test Reference)

Expected performance with test setup:

- **Startup time**: 30–45 seconds (both containers)
- **Replication lag**: < 1 second (on same host)
- **Data write → replicate**: < 500ms
- **Watchdog tick**: 5 seconds (configured in override)
- **Auto-rejoin after error**: < 10 seconds

---

## Next Steps

- Follow [PAIR_REDUNDANCY_AUTOMATION.md](./PAIR_REDUNDANCY_AUTOMATION.md) for two-host deployment
- Test failover scenarios (manual switchover, disaster recovery)
- Integrate with monitoring/alerting (Prometheus metrics, Grafana dashboards)
- Load test with bot-manager writes during replication

