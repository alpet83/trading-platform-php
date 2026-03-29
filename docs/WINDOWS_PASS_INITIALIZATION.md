# Windows/Docker Desktop: Pass Initialization

> Guide for non-interactive Pass + GPG setup on Windows 10/11 with Docker Desktop

## Overview

Pass (Unix password manager) requires:
- GPG key for encryption
- Pass store initialized
- Mounted at `/root/.password-store` in containers

**Windows Challenge:** Docker Desktop containers can't interact with Windows `gpg` GUI prompts.  
**Solution:** Batch-mode initialization script runs **inside container** with no TTY required.

---

## Prerequisites

- Windows 10/11 with Docker Desktop installed
- WSL2 backend enabled (Docker Desktop for Windows defaults to this)
- `docker-compose` v2+ available in terminal
- PowerShell or Git Bash terminal

---

## Method 1: Automatic Initialization (Recommended)

### Step 1: Create Pass Store Directories

```powershell
# PowerShell
mkdir -Force secrets\gnupg, secrets\pass-store
```

or

```bash
# Git Bash / WSL2 terminal
mkdir -p secrets/gnupg secrets/pass-store
```

### Step 2: Run Pass Initialization Container

```bash
# From project root
docker-compose -f docker-compose.init-pass.yml --profile init run pass-init
```

**Expected output:**

```
[*] Installing dependencies...
[*] Configuring batch GPG key generation...
[*] Generating GPG master key (4096-bit RSA)...
[*] Initializing Pass store...
[*] Creating test credential: trading/api_keys/test
[*] Verification output:
    └── trading/
        └── api_keys/
            └── test (test_api_key_value)
[✓] Pass initialization complete!
```

### Step 3: Verify GPG Keys

```bash
# Check GPG keys were created in secrets/gnupg/
ls -la secrets/gnupg/
```

Expected files:
```
pubring.gpg       # Public key ring
trustdb.gpg       # Trust database
random_seed       # Random seed for GPG
```

### Step 4: Verify Pass Store

```bash
# Check Pass store structure
ls -la secrets/pass-store/
```

Expected directory:
```
.gpg-id           # File containing GPG key ID
trading/          # Encrypted credential directory
```

### Step 5: Read Test Credential

```bash
# Verify Pass can decrypt credentials
docker-compose run --rm pass-init pass show trading/api_keys/test
```

Expected output:
```
test_api_key_value
```

---

## Method 2: Manual WSL2 Initialization (Alternative)

If automatic method fails, initialize Pass directly in WSL2:

### Step 1: Open WSL2 Terminal

```powershell
# PowerShell
wsl
```

### Step 2: Install Pass & GPG (if not already present)

```bash
# Inside WSL2
sudo apt-get update
sudo apt-get install -y pass gnupg
```

### Step 3: Check Docker Desktop WSL2 Integration

```bash
# Inside WSL2, verify Docker commands work
docker ps
docker-compose --version
```

### Step 4: Run from Project Directory

```bash
# Inside WSL2, navigate to project
cd /mnt/c/Projects/trading-platform-php  # Adjust path as needed

# Run initialization
docker-compose -f docker-compose.init-pass.yml --profile init run pass-init
```

### Step 5: Verify

```bash
# Check local directories were created on Windows
ls -la secrets/gnupg/
ls -la secrets/pass-store/
```

---

## Method 3: Pre-initialized Docker Image (Advanced)

If you have pre-configured GPG keys + Pass store from another machine:

### Step 1: Copy Secrets Directory

```powershell
# Copy from backup / previous setup
Copy-Item -Recurse "D:\backup\secrets" ".\secrets"
```

### Step 2: Verify Permissions

```bash
# Inside WSL2 or Git Bash
chmod 700 secrets/gnupg
chmod 700 secrets/pass-store
```

### Step 3: No Further Steps Needed

Containers will automatically mount these directories as read-only.

---

## Troubleshooting

### Problem: "Batch key generation failed" or GPG errors

**Cause:** Docker container missing dependencies  
**Solution:**

```bash
# Reinstall Pass + GPG in container
docker-compose run --rm pass-init \
  bash -c "apt-get update && apt-get install -y pass gnupg"
```

### Problem: "Pass store already initialized" error on re-run

**Cause:** Pass was initialized, container run again  
**Solution:** Safe to ignore; re-run script with:

```bash
docker-compose -f docker-compose.init-pass.yml --profile init run --rm pass-init
```

The `--rm` flag cleans up the container after completion.

### Problem: "Permission denied" when mounting secrets/

**Cause:** WSL2 / Docker permission issue with Windows directory  
**Solution:**

```bash
# From WSL2, ensure directory is readable
chmod 755 secrets/gnupg secrets/pass-store

# Restart Docker Desktop (Settings → Reset → Restart)
```

### Problem: "GPG command not found"

**Cause:** Docker image doesn't have GPG installed  
**Solution:** Use dockerfile-based approach:

```dockerfile
# Dockerfile.pass-init
FROM ubuntu:24.04
RUN apt-get update && apt-get install -y pass gnupg dirmngr
COPY scripts/init-pass.sh /init-pass.sh
ENTRYPOINT [ "bash", "/init-pass.sh" ]
```

Then:
```bash
docker build -f Dockerfile.pass-init -t trd-pass-init .
docker run --rm \
  -v ./secrets/gnupg:/root/.gnupg \
  -v ./secrets/pass-store:/root/.password-store \
  trd-pass-init
```

---

## Next Steps: Using Pass with Containers

### 1. Mount Pass Store in bot-manager

Add to `docker-compose.yml`:

```yaml
services:
  trd-bot-manager:
    volumes:
      - ./secrets/pass-store:/root/.password-store:ro
    environment:
      GNUPGHOME: /root/.gnupg
```

### 2. Start bot-manager

```bash
docker-compose up -d trd-bot-manager
```

### 3. Verify bot-manager can read credentials

```bash
docker logs trd-bot-manager | grep -i "pass\|credential"
```

---

## Pass Store Structure

Recommended organization:

```
secrets/pass-store/
├── trading/
│   ├── api_keys/
│   │   ├── binance
│   │   ├── kraken
│   │   └── coinbase
│   ├── databases/
│   │   ├── mariadb-primary
│   │   ├── mariadb-standby
│   │   └── trading-db
│   └── services/
│       ├── telegram-bot
│       └── tradebot-php
├── docker/
│   ├── registry-credentials
│   └── ssh-keys
└── monitoring/
    ├── prometheus
    └── grafana
```

### Example: Create Bot Credentials

```bash
# Run Pass container interactively
docker-compose run --rm pass-init bash

# Inside container:
pass insert -m trading/databases/mariadb-primary
# You'll be prompted to paste multi-line content:
# <paste username>
# <paste password>
# <paste hostname>

# Exit container
exit
```

Or non-interactively:

```bash
# Using echo piping
echo -e "trading_user\nstrong_password_123\nmaria.example.local" | \
  docker-compose run --rm pass-init \
  pass insert -m trading/databases/mariadb-primary
```

---

## Automation: Add to Your Pipeline

### docker-compose.yml Integration

```yaml
services:
  pass-init:
    extends:
      file: docker-compose.init-pass.yml
      service: pass-init
    profiles: ["init"]

  # Other services...
  trd-bot-manager:
    depends_on:
      - pass-init  # Ensures Pass is initialized first
    volumes:
      - ./secrets/pass-store:/root/.password-store:ro
```

Then:

```bash
# Start full stack
docker-compose up -d
# pass-init runs only on first start, then marked as complete
```

---

## Security Notes

### 🚨 CRITICAL: Passphrase-less GPG (Development Only)

**Current approach uses `%no-protection`** = GPG key WITHOUT password protection.

**What this means:**
```
┌─────────────────────────────────┐
│ Pass Store (GPG-encrypted)      │  ← Still encrypted
├─────────────────────────────────┤
│ GPG Private Key                 │
│  ├─ With password: Protected    │  ← ✅ Need password to use
│  └─ No password (`%no-protect`) │  ← ❌ Anyone with GPG key = full access
└─────────────────────────────────┘
```

**ACCEPTABLE FOR:**
- ✅ Local testing on Windows/Docker Desktop
- ✅ Development environments (not committed to Git)
- ✅ Isolated CI/CD containers

**NOT ACCEPTABLE FOR:**
- ❌ Production servers
- ❌ Multi-tenant environments
- ❌ Long-term credential storage
- ❌ Compliance-regulated services

### Production Alternatives

**Option 1: GPG Agent + Passphrase (Recommended for On-Premises)**

```bash
#!/bin/bash
# Generate key WITH password, store in gpg-agent

export PASSPHRASE="$(openssl rand -base64 32)"

gpg --batch \
  --passphrase "$PASSPHRASE" \
  --passphrase-repeat "$PASSPHRASE" \
  --generate-key <<EOF
%echo Generating GPG key for production
%seckey-algo rsa4096
Key-Type: RSA
Key-Length: 4096
Name-Real: TradeBot-Production
Name-Email: tradebot@prod.local
Expire-Date: 2y
%commit
EOF

# Enable daemon mode (passphrase stored in agent, not repeatedly requested)
echo "enable-loopback" >> ~/.gnupg/gpg.conf

pass init "$GPG_KEY_ID"

# Secure cleanup
unset PASSPHRASE
```

**How it works:**
- GPG key protected by passphrase
- `gpg-agent` caches passphrase in memory
- Containers access credentials through agent (no repeated password entry)
- Passphrase rotated on key expiry (recommended: 1-2 years)

---

**Option 2: HashiCorp Vault (Recommended for Cloud)**

```yaml
# docker-compose.vault.yml
services:
  vault:
    image: vault:latest
    environment:
      VAULT_DEV_ROOT_TOKEN_ID: myroot
    command: server -dev

  bot-manager:
    depends_on:
      - vault
    environment:
      VAULT_ADDR: http://vault:8200
      VAULT_TOKEN: myroot
```

**Benefits:**
- ✅ No private keys on disk
- ✅ Centralized secret management
- ✅ Automatic credential rotation
- ✅ Full audit trail
- ✅ Multi-factor auth support

---

**Option 3: AWS Secrets Manager / Azure Key Vault (Cloud Native)**

```python
# bot-manager with AWS SDK
import boto3

def get_trading_credentials():
    client = boto3.client('secretsmanager', region_name='us-east-1')
    secret = client.get_secret_value(SecretId='trading/binance-api-key')
    return json.loads(secret['SecretString'])
```

**Benefits:**
- ✅ Credentials never leave cloud provider
- ✅ IAM-based access control
- ✅ Automatic logging and audit trails
- ✅ Built-in rotation policies

---

### Summary: Choose Your Security Level

| Environment | Strategy | Security |
|------|----------|----------|
| **Development (Windows)** | `%no-protection` (current) | ⚠️ Low (acceptable) |
| **On-Premises Production** | gpg-agent + passphrase | ✅ High |
| **Cloud (AWS/Azure)** | Secrets Manager / Key Vault | ✅✅ Very High |
| **Enterprise** | HashiCorp Vault | ✅✅✅ Enterprise-Grade |

---

### Immediate Action Items

1. **For Development:** Current setup is fine; just DON'T commit `secrets/` to Git
   ```bash
   # .gitignore
   secrets/gnupg/
   secrets/pass-store/
   ```

2. **Before Production Rollout:** Implement one of the alternatives above

3. **Monitor Access:**
   ```bash
   # Who accessed credentials?
   docker logs trd-bot-manager | grep -i "pass\|credential"
   ```

2. **secrets/ directory:** Add to `.gitignore`:
   ```
   secrets/
   .gnupg/
   .password-store/
   ```

3. **Docker volume mounts:** Always mount as read-only (`:ro`) in services:
   ```yaml
   volumes:
     - ./secrets/pass-store:/root/.password-store:ro
   ```

---

## bot-manager Credential Modes (env)

Set mode in `.env`:

```bash
# Source of credentials for bot_manager.php
# pass: read from pass store
# db:   read API creds from DB config params + BOT_TRADER_PASSWORD env
BOT_CREDENTIAL_SOURCE=pass

# Pass encryption mode
# none:      no gpg-agent wait, suitable for unprotected/dev pass keys
# encrypted: bot-manager waits until `pass users/trader` becomes available
BOT_PASS_ENCRYPTION_MODE=none

# Startup synchronization for encrypted mode
BOT_GPG_WAIT_ENABLED=1
BOT_GPG_WAIT_TIMEOUT_SECONDS=180
BOT_GPG_WAIT_INTERVAL_SECONDS=2
BOT_GPG_PROBE_PATH=users/trader
BOT_GPG_PROBE_PATHS=users/trader,api/bitmex@1

# Used when BOT_CREDENTIAL_SOURCE=db
BOT_TRADER_PASSWORD=
BOT_DB_API_KEY_PARAM=api_key
BOT_DB_API_SECRET_PARAM=api_secret
```

Run bot-manager:

```bash
docker-compose --profile pass up -d gpg-agent trd-bot-manager
```

Mode behavior:
- `BOT_CREDENTIAL_SOURCE=pass` + `BOT_PASS_ENCRYPTION_MODE=none`: starts immediately, reads from pass store.
- `BOT_CREDENTIAL_SOURCE=pass` + `BOT_PASS_ENCRYPTION_MODE=encrypted`: waits for unlock/readiness (`pass users/trader`) up to timeout.
- `BOT_CREDENTIAL_SOURCE=db`: does not require `pass`, reads API values from DB config params and trader password from `BOT_TRADER_PASSWORD`.

---

## Quick Reference

| Task | Command |
|------|---------|
| Initialize Pass | `docker-compose -f docker-compose.init-pass.yml --profile init run pass-init` |
| List credentials | `docker-compose run --rm pass-init pass ls` |
| Show credential | `docker-compose run --rm pass-init pass show trading/api_keys/test` |
| Add credential | `docker-compose run --rm pass-init pass insert trading/databases/mydb` |
| Generate password | `docker-compose run --rm pass-init pass generate -n trading/api_keys/newkey 32` |
| Re-initialize | `rm -rf secrets/; docker-compose -f docker-compose.init-pass.yml --profile init run pass-init` |

---

## Support

For additional help:
- See [BOT_MANAGER_AND_PASS.md](./BOT_MANAGER_AND_PASS.md) for detailed architecture
- Check `docker logs trd-pass-init` for initialization errors
- Review [CONTAINER_NAMING.md](./CONTAINER_NAMING.md) for container reference

