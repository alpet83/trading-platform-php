# Quick Test Reference

## Fastest Start (Full Pair Test)

```bash
# Complete automated test (5-10 minutes)
./test-ha-replication-pair.sh full

# Monitor replication in real-time
./test-ha-replication-pair.sh monitor

# Cleanup when done
./test-ha-replication-pair.sh teardown
```

---

## Single Instance Quick Test

```bash
# Bring up with test credentials
docker-compose -f docker-compose.yml -f docker-compose.override.yml up -d

# Run tests
./test-ha-replication.sh full

# Verify
mysql -h127.0.0.1 -uroot -p'test_root_123' -e "SELECT @@server_id, @@read_only, @@log_bin;"

# Cleanup
./test-ha-replication.sh teardown
```

---

## Manual Two-Container Test

```bash
# Start both containers
docker-compose -f docker-compose.test.yml up -d

# Get PRIMARY GTID
mysql -h127.0.0.1 -P3306 -uroot -p'test_root_123' -e \
  "SELECT @@gtid_binlog_pos;"

# Configure STANDBY replication channel
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "CHANGE MASTER TO
     MASTER_HOST='127.0.0.1', MASTER_PORT=3306,
     MASTER_USER='test_repl', MASTER_PASSWORD='test_repl_789',
     MASTER_USE_GTID=slave_pos;
   START REPLICA;"

# Check status
mysql -h127.0.0.1 -P3307 -uroot -p'test_root_123' -e \
  "SHOW SLAVE STATUS\G" | grep -E "Slave.*Running|Seconds_Behind"

# Write test data on PRIMARY
mysql -h127.0.0.1 -P3306 -u'trading' -p'test_trading_456' trading -e \
  "CREATE TABLE test (id INT, data VARCHAR(100)); \
   INSERT INTO test VALUES (1, 'replicated data');"

# Verify on STANDBY
mysql -h127.0.0.1 -P3307 -u'trading' -p'test_trading_456' trading -e \
  "SELECT * FROM test;"

# Cleanup
docker-compose -f docker-compose.test.yml down -v
```

---

## File Reference

| File | Purpose | Use Case |
|------|---------|----------|
| `docker-compose.override.yml` | Test env vars + single MariaDB | Quick validation, CI/CD |
| `docker-compose.test.yml` | PRIMARY + STANDBY + watchdogs (all local) | Full pair testing |
| `test-ha-replication.sh` | Single-instance validation | Basic checks |
| `test-ha-replication-pair.sh` | Automated pair testing with replication setup | Complete E2E test |
| `docs/TESTING_HA_REPLICATION.md` | Full documentation | Reference guide |

---

## Test Credentials (All Test Setups)

```
Root:          root / test_root_123
App User:      trading / test_trading_456
Replication:   test_repl / test_repl_789
Recovery:      test_remote_master / test_remote_pass_999
```

---

## Ports

Single instance: `localhost:3306`  
PRIMARY (pair): `localhost:3306`  
STANDBY (pair): `localhost:3307`

