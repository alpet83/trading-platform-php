# Windows Quick Start: HA MariaDB + Pass + bot-manager

> "Get trading-platform-php running on Windows/Docker Desktop in 5 minutes"

---

## ⚠️  Security Notice

**This quick start uses DEVELOPMENT credentials (`%no-protection` GPG).**

✅ Safe for: Local testing, development, CI/CD pipelines  
❌ NOT for: Production servers, sensitive data, compliance environments

**For production:** See [PRODUCTION_SECURITY.md](./PRODUCTION_SECURITY.md) for:
- GPG passphrase protection (Tier 1)
- HashiCorp Vault (Tier 2)  
- AWS Secrets Manager (Tier 3)

---

## Setup

### 0. One-command sequential deploy (recommended for non-Linux users)

```bash
# Full simple flow:
# 1) bootstrap libs + random db_config credentials
# 2) build mariadb/web images
# 3) start DB and wait for health
# 4) start web-api
# 5) test admin + API endpoints
sh scripts/deploy-simple.sh
```

PowerShell-native variant (recommended on Windows):

```powershell
./scripts/deploy-simple.ps1
```

After success:
- Admin UI: `http://localhost:8088/basic-admin.php`
- API entrypoint: `http://localhost:8088/api/index.php`

This bootstrap page is intentionally lightweight and separate from full TS admin (`signals-service.ts`).

---

### 1. Initialize Pass (2 minutes)

```bash
# Create credential storage directories
mkdir -Force secrets\gnupg, secrets\pass-store

# Initialize Pass & GPG (batch mode, no prompts)
docker-compose -f docker-compose.init-pass.yml --profile init run pass-init
```

**Output:**
```
[✓] Pass initialization complete!
```

---

## 2. Start HA Pair (1 minute)

```bash
# Production: Single-instance test setup
docker-compose up -d

# Full HA pair test (PRIMARY + STANDBY + watchdogs)
docker-compose -f docker-compose.test.yml up -d
```

**Verify:**
```powershell
docker ps --filter "name=trd"
```

Expected output:
```
CONTAINER ID  NAMES                         STATUS
abc123...     trd-mariadb-primary           Up 30s
def456...     trd-mariadb-standby           Up 30s
ghi789...     trd-watchdog-primary          Up 30s
jkl012...     trd-watchdog-standby          Up 30s
```

---

## 3. Configure Replication (1 minute)

```bash
# On STANDBY: Configure replication from PRIMARY
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
CHANGE MASTER TO
  MASTER_HOST='trd-mariadb-primary',
  MASTER_PORT=3306,
  MASTER_USER='repl_user',
  MASTER_PASSWORD='repl_password',
  MASTER_AUTO_POSITION=1;
START REPLICA;
SHOW REPLICA STATUS\G
EOF
```

**Expected output:**
```
Slave_IO_Running: Yes
Slave_SQL_Running: Yes
Seconds_Behind_Master: 0
```

---

## 4. Test Data Replication (1 minute)

```bash
# Write to PRIMARY
docker exec trd-mariadb-primary mariadb -uroot -proot << 'EOF'
CREATE TABLE IF NOT EXISTS replication_test (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO replication_test (message) VALUES 
  ('Test row 1'),
  ('Test row 2'),
  ('Test row 3');
EOF

# Verify on STANDBY
docker exec trd-mariadb-standby mariadb -uroot -proot \
  -e "SELECT * FROM replication_test;"
```

**Expected output (on STANDBY):**
```
id  message      created_at
1   Test row 1   2026-03-28 15:20:00
2   Test row 2   2026-03-28 15:20:00
3   Test row 3   2026-03-28 15:20:00
```

✅ **Replication confirmed working!**

---

## 5. Start bot-manager with Credentials (optional)

```bash
# Set credentials in Pass store
docker-compose run --rm pass-init bash -c \
  'echo "u=my_bot_user" | pass insert -f trading/api_keys/telegram'

# Mode A: Pass, unencrypted/dev keys
# .env: BOT_CREDENTIAL_SOURCE=pass
# .env: BOT_PASS_ENCRYPTION_MODE=none
docker-compose --profile pass up -d gpg-agent trd-bot-manager

# Mode B: Pass, encrypted keys + gpg-agent synchronization
# .env: BOT_CREDENTIAL_SOURCE=pass
# .env: BOT_PASS_ENCRYPTION_MODE=encrypted
# bot-manager will wait until `pass users/trader` is available
docker-compose --profile pass up -d gpg-agent trd-bot-manager

# Mode C: DB credentials source (no pass required by bot-manager)
# .env: BOT_CREDENTIAL_SOURCE=db
# .env: BOT_TRADER_PASSWORD=<required>
# .env: BOT_DB_API_KEY_PARAM=api_key
# .env: BOT_DB_API_SECRET_PARAM=api_secret
docker-compose --profile pass up -d trd-bot-manager

# Verify credentials loaded
docker logs trd-bot-manager | grep -i "credential\|loaded"
```

---

## 6. Activate API Server (web-ui + alpet libs + db_config copy)

```bash
# Optional source for initialized config copy
# (default: ./secrets/db_config.php.initialized)
export DEPLOY_DB_CONFIG_SOURCE=./secrets/db_config.php.initialized

# Bootstrap runtime libs from ALPET_LIBS_REPO if missing,
# copy initialized db_config.php into ./secrets/db_config.php,
# then start mariadb + web(api) services.
sh scripts/deploy-api-server.sh

# Verify API/web-ui is reachable
curl http://localhost:${WEB_PORT:-8088}/api/
```

---

## 7. Inject API keys (separate script)

Pass mode:

```bash
CREDENTIAL_SOURCE=pass \
EXCHANGE=bitmex \
ACCOUNT_ID=1 \
API_KEY='your_api_key' \
API_SECRET_S0='secret_part_0' \
API_SECRET_S1='secret_part_1' \
sh scripts/inject-api-keys.sh
```

DB mode:

```bash
CREDENTIAL_SOURCE=db \
BOT_NAME=bitmex_bot \
ACCOUNT_ID=1 \
API_KEY='your_api_key' \
API_SECRET='your_full_secret' \
sh scripts/inject-api-keys.sh
```

Secret split scheme (important):

```text
- Store secret in DB as split params, not as a synthetic separator.
- Effective runtime secret is reconstructed as:
  api_secret_s0 + api_secret_sep + api_secret_s1
- If your real secret naturally splits as:
  "hB3hmCgaGYDuInMYYQ8z6dZ" + "l" + "X53hFxpU8kydux6Un75g37kY"
  then separator must be exactly "l".
- Using a dummy separator (for example "--") changes HMAC and causes
  Signature not valid.
```

Interactive injection (recommended for DB mode):

```powershell
pwsh ./scripts/inject-api-keys-interactive.ps1
```

The interactive script now:

```text
1) Shows existing bots from config__table_map.
2) Lets you choose bot by number.
3) Shows available account_id values for that bot and lets you choose by number.
4) Accepts full API secret and auto-splits it into s0/sep/s1.
5) Writes api_key + split secret params and verifies rows.
```

---

## Container Names Reference

| Service | Container | Ports |
|---------|-----------|-------|
| Primary DB | `trd-mariadb-primary` | 3306 |
| Standby DB | `trd-mariadb-standby` | 3307 |
| Watchdog P | `trd-watchdog-primary` | (internal) |
| Watchdog S | `trd-watchdog-standby` | (internal) |
| Web / API | `trd-web` | 80/443 |
| bot-manager | `trd-bot-manager` | (internal) |

---

## Common Commands

| Task | Command |
|------|---------|
| Stop everything | `docker-compose down` |
| View logs | `docker logs trd-mariadb-primary` |
| DB shell (PRIMARY) | `docker exec -it trd-mariadb-primary mariadb -uroot -proot` |
| DB shell (STANDBY) | `docker exec -it trd-mariadb-standby mariadb -uroot -proot -P 3307` |
| Add Pass credential | `docker-compose run --rm pass-init pass insert trading/api_keys/mykey` |
| List all credentials | `docker-compose run --rm pass-init pass ls` |
| Replication status | `docker exec trd-mariadb-standby mariadb -uroot -proot -e "SHOW REPLICA STATUS\G"` |

---

## Troubleshooting

**Q: "docker-compose: command not found"**  
A: Use `docker compose` (v2 syntax) instead, or install docker-compose v1 via `pip install docker-compose`

**Q: "connection refused" on port 3306**  
A: Check PRIMARY is running: `docker ps | grep trd-mariadb-primary`

**Q: Replication stuck at "Slave_IO_Running: No"**  
A: Verify network: `docker exec trd-mariadb-standby ping trd-mariadb-primary`

**Q: Pass initialization failed**  
A: Check logs: `docker logs trd-pass-init`; then see [WINDOWS_PASS_INITIALIZATION.md](WINDOWS_PASS_INITIALIZATION.md)

---

## Next: Full Documentation

- [HA Replication Architecture](./PAIR_REDUNDANCY_AUTOMATION.md)
- [Testing HA Failover](./TESTING_HA_REPLICATION.md)
- [Pass Credential Management](./WINDOWS_PASS_INITIALIZATION.md)
- [bot-manager Integration](./BOT_MANAGER_AND_PASS.md)
- [Container Naming Reference](./CONTAINER_NAMING.md)
- [Infrastructure Guide](./INFRASTRUCTURE_GUIDE.md)
- [Commands Reference](./COMMANDS_REFERENCE.md)

---

## Environment File

All settings live in `.env`. Key variables:

```bash
# From .env
COMPOSE_PROJECT_NAME=trd           # Shortens all container names
MARIADB_ROOT_PASSWORD=root
MARIADB_REPL_USER=repl_user
MARIADB_REPL_PASSWORD=repl_password
MARIADB_MAX_REPL_LAG_SECONDS=300
PASS_STORE_DIR=./secrets/pass-store
BOT_CREDENTIAL_SOURCE=pass
BOT_PASS_ENCRYPTION_MODE=none
BOT_TRADER_PASSWORD=
BOT_DB_API_KEY_PARAM=api_key
BOT_DB_API_SECRET_PARAM=api_secret
```

Modify `.env` to change ports, passwords, or replica thresholds.

