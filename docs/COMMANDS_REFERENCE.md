# HA Infrastructure: Command Reference

> Copy-paste commands for all common operations

---

## Initialize

```bash
# Step 1: Create secrets directories
mkdir -p secrets/gnupg secrets/pass-store

# Step 2: Initialize Pass + GPG (batch mode, no prompts)
docker-compose -f docker-compose.init-pass.yml --profile init run pass-init

# Step 3: Verify Pass store initialized
docker-compose run --rm pass-init pass ls -la

# Expected output:
#   Password Store
#   └── trading/
#       └── api_keys/
#           └── test
```

---

## Simple Deploy (Novice Path)

```bash
# One command for full sequential deploy:
# bootstrap -> build -> start DB -> health wait -> start web -> endpoint tests
sh scripts/deploy-simple.sh
```

```powershell
# Windows-native wrapper
./scripts/deploy-simple.ps1
```

Output includes ready URLs:
- `http://localhost:8088/basic-admin.php`
- `http://localhost:8088/api/index.php`

---

## Activate API Server (web-ui)

```bash
# Optional: source for initialized DB config copy.
# Defaults to ./secrets/db_config.php.initialized
export DEPLOY_DB_CONFIG_SOURCE=./secrets/db_config.php.initialized

# Bootstrap missing runtime libs from ALPET_LIBS_REPO,
# copy initialized secrets/db_config.php,
# then start mariadb + web(api) services.
sh scripts/deploy-api-server.sh

# Verify API endpoint
curl http://localhost:${WEB_PORT:-8088}/api/
```

---

## Start Services

```bash
# Option A: Production single-instance
docker-compose up -d

# Option B: Local HA pair testing (PRIMARY + STANDBY + 2 watchdogs)
docker-compose -f docker-compose.test.yml up -d

# Option C: Two-host HA (run on each host differently)
# Host 1:
docker-compose -f docker-compose.replication.yml --profile ha-primary up -d

# Host 2:
export PRIMARY_HOST=<host1-ip>
docker-compose -f docker-compose.replication.yml --profile ha-standby up -d
```

---

## Configure Replication (One-Time Setup)

```bash
# Run on STANDBY to establish replication from PRIMARY
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
CHANGE MASTER TO
  MASTER_HOST='trd-mariadb-primary',
  MASTER_PORT=3306,
  MASTER_USER='repl_user',
  MASTER_PASSWORD='repl_password',
  MASTER_AUTO_POSITION=1;
START REPLICA;
EOF

# Verify replication is working
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
SHOW REPLICA STATUS\G
EOF

# Expected key fields:
#   Slave_IO_Running: Yes
#   Slave_SQL_Running: Yes
#   Seconds_Behind_Master: 0
```

---

## Database Operations

```bash
# Connect to PRIMARY (write)
docker exec -it trd-mariadb-primary mariadb -uroot -proot

# Connect to STANDBY (read-only)
docker exec -it trd-mariadb-standby mariadb -uroot -proot -P 3307

# Run SQL on PRIMARY
docker exec trd-mariadb-primary mariadb -uroot -proot -e "SELECT * FROM users;"

# Run SQL on STANDBY
docker exec trd-mariadb-standby mariadb -uroot -proot -e "SELECT * FROM users;"

# Create test table on PRIMARY
docker exec trd-mariadb-primary mariadb -uroot -proot << 'EOF'
CREATE TABLE IF NOT EXISTS replication_test (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO replication_test (message) VALUES 
  ('Row 1'),
  ('Row 2'),
  ('Row 3');
EOF

# Verify data replicated to STANDBY
docker exec trd-mariadb-standby mariadb -uroot -proot \
  -e "SELECT * FROM replication_test;"
```

---

## Pass Credentials

```bash
# List all credentials
docker-compose run --rm pass-init pass ls -la

# Show specific credential
docker-compose run --rm pass-init pass show trading/api_keys/test

# Add new credential (interactive)
docker-compose run pass-init bash
# Inside container:
# $ pass insert trading/api_keys/my_key
# [enter username, password, etc]

# Add credential (non-interactive)
echo "my_secret_api_key_123" | docker-compose run --rm pass-init pass insert -f trading/api_keys/my_key

# Generate random password and store
docker-compose run --rm pass-init pass generate trading/api_keys/random_pass 32

# Delete credential
docker-compose run --rm pass-init pass rm -f trading/api_keys/old_key
```

Inject keys via helper script (pass):

```bash
CREDENTIAL_SOURCE=pass EXCHANGE=bitmex ACCOUNT_ID=1 API_KEY='k' API_SECRET_S0='s0' API_SECRET_S1='s1' sh scripts/inject-api-keys.sh
```

Inject keys via helper script (db):

```bash
CREDENTIAL_SOURCE=db BOT_NAME=bitmex_bot ACCOUNT_ID=1 API_KEY='k' API_SECRET='full_secret' sh scripts/inject-api-keys.sh
```

DB helper behavior for secret splitting:

```text
1) Takes API_SECRET as a full raw secret string.
2) If DB already has api_secret_sep for this account, tries split by that separator first.
3) If no usable DB separator exists, auto-splits at midpoint: s0 + sep + s1.
3) Writes params:
  - api_key
  - api_secret      = '' (cleared intentionally)
  - api_secret_s0
  - api_secret_s1
  - api_secret_sep
4) Bot runtime reconstructs raw secret as api_secret_s0 + api_secret_sep + api_secret_s1.
```

Optional override for split point:

```bash
CREDENTIAL_SOURCE=db BOT_NAME=bitmex_bot ACCOUNT_ID=1 API_KEY='k' API_SECRET='full_secret' API_SECRET_SPLIT_POS=24 sh scripts/inject-api-keys.sh
```

---

## DB Secret Encryption (AES-256-GCM)

DB credentials can be encrypted at rest using AES-256-GCM, keyed by a master secret
(`BOT_MANAGER_SECRET_KEY`). Encryption is **optional**: unencrypted DB secrets still work,
but encrypting removes the risk of a DB dump exposing plaintext HMAC keys.

### How it Works

```text
Plaintext secret
  ↓  AES-256-GCM (key = SHA-256(BOT_MANAGER_SECRET_KEY))
  ↓  IV[12] + AuthTag[16] + Ciphertext
  ↓  base64-encode → "v1:<base64>"
  ↓  split at midpoint → api_secret_s0 + api_secret_sep + api_secret_s1

Flag secret_key_encrypted = 1 in DB config table marks the record as encrypted.

Boot path (run-bot.sh bootstrap):
  Reads s0+sep+s1 from DB → assembles v1: payload → detects prefix → decrypts
  → writes base64(plaintext) to runtime key file → PHP reads it normally.
```

### Prerequisite: Set Master Key

Add `BOT_MANAGER_SECRET_KEY` to `.env`:

```bash
# .env (use a strong random value in production)
BOT_MANAGER_SECRET_KEY=<your-master-key>
```

The same variable must be available in the `bots-hive` container; `docker-compose.yml`
already passes it via `BOT_MANAGER_SECRET_KEY: ${BOT_MANAGER_SECRET_KEY:-}`.

Key resolution order (PHP runtime):
1. `BOT_MANAGER_SECRET_KEY` env var
2. `BOT_MANAGER_SECRET_KEY_FILE` env var (path to file)
3. `/run/secrets/bot_manager_key`
4. `/run/secrets/bot_manager_secret_key`
5. `/run/secrets/bot_manager_master_key`

### Encrypt Existing DB Secret (migrate existing bot)

```bash
# Inside the running container:
docker exec -e BOT_MANAGER_SECRET_KEY='<key>' trd-bots-hive \
  sh -c "php /app/src/cli/encrypt_key.php bybit"

# Or, if BOT_MANAGER_SECRET_KEY is already in container env:
docker exec trd-bots-hive sh -c "php /app/src/cli/encrypt_key.php bybit"
```

The script:
- Finds the bot's config table via `config__table_map`
- Iterates all `account_id` rows
- Skips accounts already marked `secret_key_encrypted=1`
- Encrypts the secret, splits it, writes `s0`/`sep`/`s1`, clears `api_secret`, sets flag=1
- Is **idempotent**: safe to run multiple times

```text
Example output:
  #OK: account_id=10001 param=api_secret encrypted (len=91)
  #WARN: account_id=425992 has no secret params to encrypt
  #DONE: bot=bybit_bot, updated_accounts=1, skipped=0
```

### Verify Round-Trip (diagnostic)

```bash
docker exec trd-bots-hive \
  sh -c "php /app/src/cli/verify_secret.php bybit"
```

Output shows assembled payload, whether it is `v1:`-encrypted, and the decrypted plaintext:

```text
--- account_id=10001 (secret_key_encrypted=1) ---
  api_secret_s0     = 'v1:nwJU0a3F1...' (len=45)
  api_secret_sep    = 'G'
  api_secret_s1     = 'R56humXb...'     (len=45)
  > assembled: s0[45] + sep('G') + s1[45] = packed[91]
  > looks_encrypted   = YES (v1: prefix present)
  > DECRYPTED secret  = 'OT8AOaZxQkN1s38rPQXWm57lawMhS6kwcJyL' (len=36)
```

### Inject Keys with Encryption Enabled

The interactive script prompts whether to encrypt:

```bash
# Linux/macOS (interactive)
sh scripts/inject-api-keys-interactive.sh
# Prompted: "Encrypt API secret in DB using bot_manager key? (0/1) [1]: "

# Windows (interactive)
./scripts/inject-api-keys-interactive.ps1
```

Non-interactive with encryption:

```bash
CREDENTIAL_SOURCE=db BOT_NAME=bybit_bot ACCOUNT_ID=10001 \
  API_KEY='key' API_SECRET='plaintext_secret' SECRET_KEY_ENCRYPTED=1 \
  sh scripts/inject-api-keys.sh
```

> **Note:** `SECRET_KEY_ENCRYPTED=1` encrypts on the way into the DB. The bot decrypts
> transparently at boot provided `BOT_MANAGER_SECRET_KEY` is set.

---

## Container Management

```bash
# List all running containers
docker-compose ps

# View specific container logs
docker logs trd-mariadb-primary
docker logs trd-watchdog-primary
docker logs trd-bot-manager

# Follow logs in real-time
docker logs -f trd-mariadb-primary

# Restart all services
docker-compose restart

# Restart specific service
docker-compose restart trd-mariadb-primary
docker-compose restart trd-watchdog-standby

# Stop all containers
docker-compose down

# Remove all containers and volumes (WARNING: deletes data!)
docker-compose down -v

# Execute command in container
docker exec trd-mariadb-primary ls -la /var/lib/mysql

# Interactive bash in container
docker exec -it trd-mariadb-primary bash
```

---

## Replication Status & Debugging

```bash
# Full replication status on STANDBY
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
SHOW REPLICA STATUS\G
EOF

# Key columns to check:
#   Slave_IO_Running: Yes       ← Reading from PRIMARY
#   Slave_SQL_Running: Yes      ← Executing relay log
#   Seconds_Behind_Master: 0    ← No lag
#   Last_Error: (empty)         ← No errors

# Check binlog position on PRIMARY
docker exec trd-mariadb-primary mariadb -uroot -proot << 'EOF'
SHOW MASTER STATUS;
EOF

# Check relay log position on STANDBY
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
SHOW SLAVE STATUS\G
EOF

# View binary logs on PRIMARY
docker exec trd-mariadb-primary mariadb -uroot -proot << 'EOF'
SHOW BINARY LOGS;
EOF

# Check if replication is caught up
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
SELECT Master_Log_File, Read_Master_Log_Pos, Relay_Master_Log_File, Exec_Master_Log_Pos 
FROM INFORMATION_SCHEMA.PROCESSLIST WHERE command = 'Binlog Dump';
EOF
```

---

## Watchdog Policy Enforcement

```bash
# Check PRIMARY is writable
docker exec trd-mariadb-primary mariadb -uroot -proot \
  -e "SHOW VARIABLES LIKE 'read_only';"
# Expected: read_only = OFF

# Check STANDBY is read-only
docker exec trd-mariadb-standby mariadb -uroot -proot \
  -e "SHOW VARIABLES LIKE 'read_only';"
# Expected: read_only = ON

# Manually enforce (if watchdog fails)
docker exec trd-mariadb-primary mariadb -uroot -proot \
  -e "SET GLOBAL read_only=OFF;"

docker exec trd-mariadb-standby mariadb -uroot -proot \
  -e "SET GLOBAL read_only=ON;"

# View watchdog container logs
docker logs trd-watchdog-primary
docker logs trd-watchdog-standby
```

---

## Bot Manager Operations

```bash
# Start bot-manager with Pass credentials
docker-compose --profile pass up -d trd-bot-manager

# View bot-manager logs
docker logs trd-bot-manager

# Verify bot-manager can read Pass credentials
docker exec trd-bot-manager bash -c \
  "ls -la /root/.password-store/trading/api_keys/"

# Test credential reading (if bot-manager supports it)
docker exec trd-bot-manager bash -c \
  "pass show trading/api_keys/test"

# Stop bot-manager
docker-compose stop trd-bot-manager

# Restart bot-manager
docker-compose restart trd-bot-manager
```

---

## Network & Connectivity

```bash
# Test PRIMARY connectivity from STANDBY container
docker exec trd-mariadb-standby ping trd-mariadb-primary

# Test PRIMARY port 3306 from STANDBY
docker exec trd-mariadb-standby \
  bash -c "echo 'SELECT 1;' | nc -w 1 trd-mariadb-primary 3306"

# View container network
docker network ls
docker network inspect trading-platform-php_default

# DNS resolution test
docker exec trd-mariadb-standby nslookup trd-mariadb-primary
```

---

## Failover Simulation

```bash
# Step 1: Stop PRIMARY
docker-compose stop trd-mariadb-primary

# Step 2: Check STANDBY replication stops
sleep 5
docker logs trd-mariadb-standby | tail -20

# Step 3: Promote STANDBY to PRIMARY (manual)
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
STOP REPLICA;
SET GLOBAL read_only=OFF;
EOF

# Step 4: Point applications to STANDBY
# (Update connection strings to trd-mariadb-standby:3307)

# Step 5: When PRIMARY recovers, resync
docker-compose start trd-mariadb-primary
# Then run reseed-from-primary.sh on STANDBY

# Verify replication re-established
docker exec trd-mariadb-standby mariadb -uroot -proot \
  -e "SHOW REPLICA STATUS\G"
```

---

## Data Backup & Restore

```bash
# Backup PRIMARY database
docker exec trd-mariadb-primary mariadb-dump -uroot -proot \
  --all-databases > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup specific database
docker exec trd-mariadb-primary mariadb-dump -uroot -proot \
  trading > trading_backup_$(date +%Y%m%d_%H%M%S).sql

# Restore database from backup
docker exec -i trd-mariadb-primary mariadb -uroot -proot < backup_20260328_150000.sql

# Verify backup (size check)
ls -lh *.sql
```

---

## Cleanup & Maintenance

```bash
# Remove exited containers
docker container prune -f

# Remove unused volumes
docker volume prune -f

# Remove unused networks
docker network prune -f

# Clear Docker logs (if too large)
docker logs --tail 0 trd-mariadb-primary

# Rotate logs (if needed)
# On host: find /var/lib/docker/containers -name "*-json.log" -exec truncate -s 0 {} \;

# Verify disk usage
docker system df

# Full cleanup (removes all stopped containers, unused images, volumes, networks)
docker system prune -a -f --volumes
```

---

## Environment & Configuration

```bash
# View .env file
cat .env

# Source .env and check variables
set -a; source .env; set +a; env | grep MARIADB

# Update .env variable and reload
sed -i 's/MARIADB_ROOT_PASSWORD=.*/MARIADB_ROOT_PASSWORD=newpassword/' .env
docker-compose down
docker-compose up -d

# Verify docker-compose config
docker-compose config

# Validate docker-compose syntax
docker-compose config --quiet
```

---

## Troubleshooting Commands

```bash
# Check if DNS resolution works
docker-compose exec trd-mariadb-standby nslookup trd-mariadb-primary

# View container IP addresses
docker-compose exec trd-mariadb-primary hostname -I
docker-compose exec trd-mariadb-standby hostname -I

# Check port binding
docker ps | grep -E "(3306|3307)"

# Verify volume mounts
docker inspect trd-mariadb-primary | grep -A 10 "Mounts"

# Check container environment
docker inspect trd-mariadb-primary | grep -A 20 "Env"

# View container startup time
docker inspect trd-mariadb-primary | grep StartedAt

# Check recent errors
docker logs --tail 50 trd-mariadb-primary | grep -i error
```

---

## Performance & Monitoring

```bash
# Check replication lag in real-time
watch -n 1 'docker exec trd-mariadb-standby mariadb -uroot -proot -N -e \
  "SELECT Seconds_Behind_Master FROM INFORMATION_SCHEMA.PROCESSLIST;"'

# Monitor binlog growth
docker exec trd-mariadb-primary mariadb -uroot -proot << 'EOF'
SHOW BINARY LOGS;
EOF

# Check storage size
du -sh secrets/pass-store/
du -sh /var/lib/docker/volumes/

# Monitor connections
docker exec trd-mariadb-primary mariadb -uroot -proot \
  -e "SHOW PROCESSLIST;"

# Check memory usage
docker stats trd-mariadb-primary trd-mariadb-standby trd-watchdog-primary trd-watchdog-standby
```

---

## Common Issues & Fixes

```bash
# Issue: Replication stopped (Slave_IO_Running: No)
# Fix: Reconnect slave
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
STOP REPLICA;
START REPLICA;
EOF

# Issue: CREATE USER error on replica
# Fix: Skip error and continue
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
SET GLOBAL SQL_SLAVE_SKIP_COUNTER = 1;
START REPLICA;
EOF

# Issue: Replication lag increasing
# Fix: Check for slow queries, restart replica
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
SHOW FULL PROCESSLIST;
SHOW SLAVE STATUS\G
EOF

# Issue: Pass initialization fails with "command not found"
# Fix: Reinstall Pass + GPG
docker-compose run --rm pass-init \
  bash -c "apt-get update && apt-get install -y pass gnupg"

# Issue: bot-manager can't read credentials
# Fix: Verify mount and permissions
docker exec trd-bot-manager ls -la /root/.password-store/
docker exec trd-bot-manager pass ls
```

---

## Quick Diagnostics Script

```bash
#!/bin/bash
# Save as quick_check.sh
# Run with: bash quick_check.sh

echo "=== Container Status ===" 
docker ps | grep trd

echo -e "\n=== Replication Status ==="
docker exec trd-mariadb-standby mariadb -uroot -proot -N -e \
  "SELECT CONCAT('Slave_IO: ', Slave_IO_Running, ' | Slave_SQL: ', Slave_SQL_Running, ' | Lag: ', Seconds_Behind_Master, 's') FROM INFORMATION_SCHEMA.PROCESSLIST;" 2>/dev/null || echo "Replication check failed"

echo -e "\n=== PRIMARY read_only ==="
docker exec trd-mariadb-primary mariadb -uroot -proot -N -e "SHOW VARIABLES LIKE 'read_only';\G" 2>/dev/null

echo -e "\n=== STANDBY read_only ==="
docker exec trd-mariadb-standby mariadb -uroot -proot -N -e "SHOW VARIABLES LIKE 'read_only';\G" 2>/dev/null

echo -e "\n=== Pass Store Initialized ==="
docker-compose run --rm pass-init pass ls 2>/dev/null

echo -e "\n=== Watchdog Logs (last 10 lines) ==="
docker logs --tail 10 trd-watchdog-primary 2>/dev/null
```

---

**Version:** 1.0  
**Last Updated:** 2026-03-28
