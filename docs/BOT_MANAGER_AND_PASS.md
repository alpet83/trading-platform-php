# Bot Manager & Pass Integration

## Overview

The `bot_manager` service is an optional container (enabled via `--profile pass`) that handles API key management through **Pass** (Unix Password Manager) with GPG encryption.

---

## Behavior Without Pass Initialization

### Default Startup (No Pass)

If `bot_manager` is launched **without Pass initialized**:

1. **First Request**: Bot manager tries to access Pass store
2. **Missing GPG Keys**: Pass requires GPG keys for decryption
3. **Interactive Authentication**: Pass prompts for GPG passphrase → **BLOCKS WAITING FOR INPUT**
4. **Container Hangs**: Docker container will not respond (interactive terminal not available in containerized context)
5. **Exception**: Bot manager crashes after timeout (typically 30–60 seconds)

### Without Credentials in Database

If `bot_manager` starts without API credentials in the database:

- Bot manager checks `bots` and `api_keys` tables
- No records found → logs warning or error
- Gracefully exits or runs in degraded mode (waiting for admin to provision bots)

### Healthy State Without Pass

Without `--profile pass`, the system runs:
- ✅ **trd-web** (Apache2) — Admin API active, can create bots
- ✅ **trd-bot** — Executes bots (reads credentials from database)
- ✅ **trd-mariadb** — Database healthy
- ❌ **trd-bot-manager** — Not running

**In this mode:**
- Use web API (`http://localhost:8088/admin/`) to provision bots
- Store credentials in database directly (less secure)
- No Pass integration needed

---

## Enabling Pass & Bot Manager

### Prerequisites

```bash
# 1. Initialize Pass locally
pass init your-gpg-key-id

# 2. Mount Pass store to Docker
PASS_STORE_DIR=./secrets/pass-store

# 3. Ensure GPG is configured
gpg --list-keys
```

### Start with Pass Profile

```bash
# From project root
docker-compose --profile pass up -d

# Or with custom env
PASS_STORE_DIR=/path/to/pass/store docker-compose --profile pass up -d
```

### Container Startup Sequence

1. **trd-mariadb** starts → database ready
2. **trd-web** starts → admin API available
3. **trd-bot-manager** tries to start
4. **Pass Store Check**:
   - If GPG keys mounted and Pass initialized → Success
   - If GPG keys missing or Pass not initialized → Waits for interactive input (blocks)

### Handling Interactive Authentication

If `trd-bot-manager` gets stuck waiting for GPG passphrase:

```bash
# Option 1: Provide passphrase via environment
export GNUPGHOME=/root/.gnupg
echo "your-passphrase" | gpg --passphrase-fd 0 --decrypt /root/.password-store/some-key.gpg

# Option 2: Use gpg-agent with pinentry
eval "$(gpg-agent --daemon)"
docker-compose --profile pass up -d

# Option 3: Restart with proper GPG setup
docker-compose --profile pass restart trd-bot-manager
```

---

## Architecture Summary

### Without `--profile pass` (Recommended for Quick Start)

```
┌─────────────────────────────────┐
│ Admin API (trd-web)             │ ← Create/manage bots
│ Port: 8088                      │
└─────────────────────────────────┘
                  │
         POST /api/bot/create
                  │
                  ▼
┌─────────────────────────────────┐
│ Database (trd-mariadb)          │ ← Store bot config & API keys
│ Port: 3306                      │
└─────────────────────────────────┘
                  │
          STORED CREDENTIALS
                  │
                  ▼
┌─────────────────────────────────┐
│ Bot Worker (trd-bot)            │ ← Read & execute bots
│ Connects on startup             │
└─────────────────────────────────┘
```

### With `--profile pass` (Secure API Key Management)

```
┌─────────────────────────────────┐
│ Bot Manager (trd-bot-manager)   │ ← Polls database for new bots
│ Requires: Pass + GPG            │   Routes credentials via Pass
└─────────────────────────────────┘
                  │
        READ from Pass Store
                  │
                  ▼
     ┌──────────────────────┐
     │ GPG Encrypted (Pass) │ ← /root/.password-store/
     │ Mounted read-only    │
     └──────────────────────┘
                  │
          (decrypted in memory)
                  │
                  ▼
┌─────────────────────────────────┐
│ Database (trd-mariadb)          │ ← Read bot definitions only
└─────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────┐
│ Bot Worker receives credentials │ ← From bot-manager via IPC/API
│ from bot-manager (trd-bot)      │
└─────────────────────────────────┘
```

---

## Use Cases

| Scenario | Recommended | Why |
|----------|-------------|-----|
| **Local dev/test** | No Pass | Simpler setup, database-backed secrets OK |
| **Production** | With Pass | GPG-encrypted store, audit trail, key rotation |
| **CI/CD pipeline** | No Pass* | Use CI secrets, not containers |
| **Multi-host deployment** | With Pass | Shared encrypted credential store |

*For CI/CD: Use environment variables or CI secret vaults, bypass Pass integration.

---

## Troubleshooting

### trd-bot-manager stuck on startup

```bash
# Check logs
docker logs trd-bot-manager

# Look for:
# - "Waiting for GPG passphrase" → Need to provide passphrase
# - "Pass store not found" → Mount PASS_STORE_DIR or initialize
# - "error 'Operation CREATE USER failed'" → Replication lag, retry
```

### Pass store mounted but GPG not found

```bash
# Verify GPG keys are accessible
docker exec trd-bot-manager gpg --list-keys

# If empty, mount GPG home:
docker-compose -f docker-compose.yml \
  -e "GNUPGHOME=/root/.gnupg" \
  --profile pass up -d
```

### Credentials not loaded

```bash
# Check database has bot records
docker exec trd-mariadb mariadb -utrading -p'trading_change_me' trading \
  -e "SELECT COUNT(*) FROM bots; SELECT COUNT(*) FROM api_keys;"

# If empty, create via web admin API first
curl -X POST http://localhost:8088/admin/api/bot/create \
  -d '{"name":"TestBot","impl":"bitmex_bot"}'
```

---

## Typical Deployment Workflow

### 1. Local Development (No Pass)

```bash
# Start without Pass
docker-compose up -d

# Create bot via admin API
curl -X POST http://localhost:8088/admin/api/bot/create \
  -d '{"name":"MyBot","impl":"bitmex_bot"}'

# Bot runs immediately, credentials stored in database
```

### 2. Production (With Pass)

```bash
# Initialize Pass locally
pass init myteam-gpg-key

# Mount to Docker
export PASS_STORE_DIR=/secure/location/pass-store

# Start with bot-manager
docker-compose --profile pass up -d

# Bot-manager reads from Pass, bot-worker executes with credentials
```

### 3. Credential Rotation

```bash
# With bot-manager running
pass generate trading/api_keys/bitmex_testnet --force

# Bot-manager picks up new key on next poll
# Old keys remain in database but unused
```

---

## Environment Variables

```env
# Required for Pass integration
PASS_STORE_DIR=./secrets/pass-store
GNUPGHOME=/root/.gnupg  # If GPG keys not in default location

# Bot manager behavior
BOT_MANAGER_POLL_INTERVAL=5  # Seconds between database polls
BOT_MANAGER_LOG_LEVEL=info   # debug, info, warn, error
```

---

## Security Notes

- ✅ Pass store is mounted **read-only** into container
- ✅ GPG decryption happens in-memory (never written to disk)
- ✅ Private keys stay on host (Docker only gets public encrypted blobs)
- ⚠️ Container has access to plaintext credentials during execution
- ⚠️ Database stores plain credentials if Pass is not used

**Best Practice**: Use Pass for production, database secrets only for dev/test.

