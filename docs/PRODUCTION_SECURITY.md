# Production Security: Credentials Management

> Migration path from development `%no-protection` to production-grade security

---

## Current State: Development vs. Production

### Development (Current: `%no-protection`)

```
secrets/gnupg/private.gpg
  ↓
  No password protection
  ↓
  Anyone with file access → reads all credentials ❌
```

**Risk Level:** 🔴 HIGH (acceptable locally only)

### Production Goal

```
secrets/gnupg/private.gpg
  ↓
  Protected by gpg-agent passphrase
  ↓
  Only authorized processes with cached passphrase → reads credentials ✅
```

**Risk Level:** 🟢 LOW

---

## Security Tiers: Choose Your Path

| Level | Method | Setup Time | Cost | Security | Scale |
|-------|--------|-----------|------|----------|-------|
| **Tier 1** | gpg-agent + passphrase | 30 min | $0 | High ✅ | Single host |
| **Tier 2** | HashiCorp Vault | 1-2 hours | $0 (OSS) | Enterprise ✅✅ | Multi-host |
| **Tier 3** | AWS Secrets Manager | 30 min | $0.40/month | Cloud-native ✅✅ | AWS only |
| **Tier 4** | Azure Key Vault | 30 min | ~$7/month | Cloud-native ✅✅ | Azure only |

---

## Tier 1: GPG Agent + Passphrase (On-Premises Production)

### Step 1: Create Production init-pass Script

Save as `scripts/init-pass-production.sh`:

```bash
#!/bin/bash
set -e

# Production Pass + GPG initialization with passphrase protection
# 
# Usage:
#   export PASSPHRASE="mystring32characters"
#   bash scripts/init-pass-production.sh

if [ -z "$PASSPHRASE" ]; then
  echo "ERROR: PASSPHRASE environment variable not set"
  echo "Generate with: openssl rand -base64 32"
  exit 1
fi

if [ ${#PASSPHRASE} -lt 20 ]; then
  echo "ERROR: PASSPHRASE must be at least 20 characters"
  exit 1
fi

echo "[*] Installing dependencies..."
apt-get update -qq
apt-get install -y pass gnupg dirmngr gpg-agent pinentry-curses > /dev/null 2>&1

echo "[*] Configuring GPG for agent mode..."
mkdir -p ~/.gnupg
chmod 700 ~/.gnupg

# Enable loopback pinentry (allows piping passphrase)
cat > ~/.gnupg/gpg.conf <<EOF
# GPG Agent Configuration
use-agent
pinentry-mode loopback
batch
no-tty
allow-loopback-pinentry
EOF

chmod 600 ~/.gnupg/gpg.conf

# Configure gpg-agent
cat > ~/.gnupg/gpg-agent.conf <<EOF
# GPG Agent Configuration
default-cache-ttl 3600
max-cache-ttl 7200
enable-ssh-support
disable-scdaemon
allow-loopback-pinentry
EOF

chmod 600 ~/.gnupg/gpg-agent.conf

echo "[*] Generating GPG master key (4096-bit RSA) with passphrase protection..."
cat > /tmp/gpg-batch <<EOG
%echo Generating GPG key for production
%seckey-algo rsa4096
Key-Type: RSA
Key-Length: 4096
Name-Real: TradeBot-Production
Name-Email: tradebot@trading.local
Expire-Date: 2y
Passphrase: ${PASSPHRASE}
%commit
%echo Done
EOG

GPG_KEY_ID=$(gpg --batch --import-ownertrust <(echo "$(gpg --batch --passphrase "$PASSPHRASE" --generate-key /tmp/gpg-batch 2>&1 | grep "key ID" | awk '{print $NF}') 5" 2>/dev/null) 2>&1 | tail -1)

# Alternative: Extract from fingerprint
GPG_KEY_ID=$(gpg --list-secret-keys --keyid-format LONG | grep sec | tail -1 | awk '{print $2}' | cut -d'/' -f2)

if [ -z "$GPG_KEY_ID" ]; then
  echo "ERROR: Could not extract GPG key ID"
  exit 1
fi

echo "[*] GPG Key ID: $GPG_KEY_ID"

echo "[*] Initializing Pass store..."
pass init "$GPG_KEY_ID" 2>/dev/null || true

echo "[*] Creating test credential..."
PASS_STORE_DIR="${PASS_STORE_DIR:-.password-store}"
echo "test_api_key_value" | pass insert -f trading/api_keys/test

echo "[*] Caching passphrase in gpg-agent..."
# This pre-loads the passphrase into agent cache
echo -n "$PASSPHRASE" | gpg --batch --passphrase-fd 0 --symmetric /tmp/test.txt >/dev/null 2>&1 || true
rm -f /tmp/test.txt /tmp/test.txt.gpg

# Cleanup
rm -f /tmp/gpg-batch
unset PASSPHRASE

echo "[✓] Production Pass initialization complete!"
echo ""
echo "Next steps:"
echo "1. Verify credentials: pass show trading/api_keys/test"
echo "2. Backup GPG keys: tar czf gnupg-backup-\$(date +%s).tar.gz ~/.gnupg"
echo "3. Store passphrase securely (encrypted or in secure vault)"
echo "4. Distribute read-only secrets/ to production hosts"
echo ""
echo "Key ID for sharing: $GPG_KEY_ID"
```

### Step 2: Initialize in Docker

Create `docker-compose.init-pass-prod.yml`:

```yaml
version: '3.9'

services:
  pass-init-prod:
    image: ubuntu:24.04
    container_name: trd-pass-init-prod
    restart: "no"
    
    volumes:
      - ./secrets/gnupg:/root/.gnupg
      - ./secrets/pass-store:/root/.password-store
      - ./scripts/init-pass-production.sh:/init-pass.sh:ro
    
    environment:
      GNUPGHOME: /root/.gnupg
      PASSPHRASE: ${PASSPHRASE}  # ← Pass via environment variable
      PASS_STORE_DIR: /root/.password-store
    
    command: [ "bash", "/init-pass.sh" ]
    
    profiles: ["init-prod"]
```

### Step 3: Run Production Initialization

```bash
# Generate secure passphrase
PASSPHRASE=$(openssl rand -base64 32)
echo "Your passphrase: $PASSPHRASE"
echo "SAVE THIS SOMEWHERE SECURE (encrypted, vault, etc)"

# Initialize with passphrase
export PASSPHRASE
docker-compose -f docker-compose.init-pass-prod.yml --profile init-prod run pass-init-prod

# Verify
docker-compose run --rm pass-init-prod pass show trading/api_keys/test
```

### Step 4: Configure Containers with gpg-agent

```yaml
# docker-compose.yml
services:
  trd-bot-manager:
    volumes:
      - ./secrets/gnupg:/root/.gnupg:ro
      - ./secrets/pass-store:/root/.password-store:ro
    
    environment:
      GNUPGHOME: /root/.gnupg
      GPG_TTY: /dev/tty               # ← For gpg-agent interaction
      GPG_AGENT_SOCK: /root/.gnupg/S.gpg-agent
    
    # Pass passphrase via secure secret (NOT in plaintext!)
    secrets:
      - gpg_passphrase
    
    command:
      - /bin/bash
      - -c
      - |
        # Pre-load passphrase into agent
        echo "$${GPG_PASSPHRASE}" | gpg --batch --passphrase-fd 0 -c /dev/null 2>/dev/null || true
        
        # Now run bot-manager (uses cached passphrase)
        exec node /app/index.js

secrets:
  gpg_passphrase:
    file: ./secrets/gpg_passphrase.txt  # Not committed to Git!
```

### Step 5: Backup & Rotation

```bash
#!/bin/bash
# backup-production-keys.sh

BACKUP_DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="gnupg-backup-${BACKUP_DATE}.tar.gz"

# Encrypt backup with GPG
tar czf - secrets/gnupg | gpg --symmetric --cipher-algo AES256 \
  --output "${BACKUP_FILE}.gpg"

echo "✓ Backup created: ${BACKUP_FILE}.gpg"
echo "ℹ For restore: gpg --decrypt ${BACKUP_FILE}.gpg | tar xzf -"

# Store locally AND upload to secure location
# scp ${BACKUP_FILE}.gpg backup-server:/secure/location/
```

---

## Tier 2: HashiCorp Vault (Multi-Host Enterprise)

### Setup with Docker Compose

```yaml
# docker-compose.vault.yml
version: '3.9'

services:
  # Vault server
  vault:
    image: vault:latest
    container_name: trd-vault
    restart: unless-stopped
    
    ports:
      - "8200:8200"
    
    environment:
      VAULT_DEV_ROOT_TOKEN_ID: ${VAULT_ROOT_TOKEN}
      VAULT_DEV_LISTEN_ADDRESS: 0.0.0.0:8200
    
    volumes:
      - ./secrets/vault:/vault/file
    
    command: vault server -dev -dev-listen-address=0.0.0.0:8200
    
    cap_add:
      - IPC_LOCK

  # Initialize Vault with secrets
  vault-init:
    image: vault:latest
    container_name: trd-vault-init
    restart: "no"
    
    depends_on:
      - vault
    
    environment:
      VAULT_ADDR: http://vault:8200
      VAULT_TOKEN: ${VAULT_ROOT_TOKEN}
    
    volumes:
      - ./scripts/vault-init.sh:/vault-init.sh:ro
    
    command: bash /vault-init.sh
    
    profiles: ["init-vault"]

  # bot-manager with Vault integration
  trd-bot-manager:
    depends_on:
      - vault-init
    
    environment:
      VAULT_ADDR: http://vault:8200
      VAULT_TOKEN: ${VAULT_BOT_TOKEN}
    
    volumes:
      - ./scripts/read-from-vault.sh:/read-vault.sh:ro
```

### Initialize Vault Secrets

```bash
#!/bin/bash
# scripts/vault-init.sh

set -e

# Wait for Vault to be ready
sleep 2

echo "[*] Creating Vault secret engine..."
vault secrets enable -path=trading kv

echo "[*] Storing trading credentials..."
vault kv put trading/binance \
  api_key="your_binance_key" \
  api_secret="your_binance_secret"

vault kv put trading/kraken \
  api_key="your_kraken_key" \
  api_secret="your_kraken_secret"

echo "[✓] Vault initialized!"
vault kv list trading
```

### Read Secrets in Application

```python
# bot-manager: fetch credentials from Vault
import requests
import os

def get_trading_creds(exchange):
    vault_url = os.getenv('VAULT_ADDR', 'http://localhost:8200')
    token = os.getenv('VAULT_TOKEN')
    
    response = requests.get(
        f"{vault_url}/v1/trading/{exchange}",
        headers={"X-Vault-Token": token}
    )
    
    if response.status_code == 200:
        return response.json()['data']['data']
    else:
        raise Exception(f"Failed to fetch credentials: {response.text}")

# Usage
creds = get_trading_creds('binance')
api_key = creds['api_key']
api_secret = creds['api_secret']
```

**Benefits:**
- ✅ Credentials never on disk
- ✅ Centralized management (multi-host)
- ✅ Audit trail of all access
- ✅ TTL-based auto-expiration
- ✅ Dynamic secret generation
- ✅ Encryption in transit + at rest

---

## Tier 3: AWS Secrets Manager (Cloud Native)

### Store Secret in AWS

```bash
# Create secret in AWS Secrets Manager
aws secretsmanager create-secret \
  --name trading/binance-api \
  --secret-string '{"api_key":"xxx","api_secret":"yyy"}'

# Rotate automatically every 30 days
aws secretsmanager rotate-secret \
  --secret-id trading/binance-api \
  --rotation-rules AutomaticallyAfterDays=30
```

### Read in Application

```python
import boto3
import json

def get_trading_creds(exchange):
    client = boto3.client('secretsmanager', region_name='us-east-1')
    
    response = client.get_secret_value(
        SecretId=f'trading/{exchange}-api'
    )
    
    return json.loads(response['SecretString'])

# Usage in docker-compose
# environment:
#   AWS_REGION: us-east-1
#   AWS_ROLE_ARN: arn:aws:iam::123456789:role/bot-manager
```

### Cost & Security

| Feature | AWS | Azure |
|---------|-----|-------|
| **Price** | ~$0.40/secret/month | ~$7/month |
| **Encryption** | KMS + TLS | ✅ |
| **Rotation** | Built-in ✅ | Lambda required |
| **Audit** | CloudTrail ✅ | ✅ |
| **IAM Integration** | ✅✅ | ✅ |

---

## Migration Checklist: Dev → Production

### Phase 1: Assessment (Week 1)
- [ ] Identify all credentials currently using `%no-protection`
- [ ] Choose security tier (1=on-prem, 2=multi-host, 3+=cloud)
- [ ] Design credential rotation schedule

### Phase 2: Implementation (Week 2-3)
- [ ] Set up chosen secure storage method
- [ ] Test credential loading in staging
- [ ] Verify bot-manager can read credentials

### Phase 3: Migration (Week 4)
- [ ] Rotate all existing credentials
- [ ] Update production docker-compose
- [ ] Test failover with new credentials
- [ ] Document rotation procedures

### Phase 4: Monitoring (Ongoing)
- [ ] Log all credential access attempts
- [ ] Alert on failed authentications
- [ ] Review access logs monthly
- [ ] Refresh credentials per schedule

---

## Compliance & Auditing

### SOC 2 Requirements Met By:

| Requirement | Tier 1 (gpg-agent) | Tier 2 (Vault) | Tier 3 (AWS) |
|------------|---|---|---|
| Encryption at rest | ✅ GPG | ✅✅ AES-256 | ✅✅ KMS |
| Encryption in transit | ⚠️ Manual TLS | ✅ Built-in | ✅ Built-in |
| Access control | ⚠️ File permissions | ✅✅ RBAC | ✅✅ IAM |
| Audit trails | ⚠️ Manual logging | ✅✅ Built-in | ✅✅ CloudTrail |
| Credential rotation | ⚠️ Manual | ✅✅ Automatic | ✅✅ Automatic |

---

## Implementation Decision Matrix

```
Do you have:
├─ 1 host?
│  └─ Use Tier 1 (gpg-agent + passphrase)
│
├─ 2-10 hosts?
│  └─ Use Tier 2 (HashiCorp Vault)
│
└─ Cloud infrastructure (AWS/Azure)?
   └─ Use Tier 3 (Secrets Manager / Key Vault)
```

---

## Quick Reference: Commands

### Tier 1 (GPG Agent)

```bash
# Initialize with passphrase
PASSPHRASE=$(openssl rand -base64 32)
docker-compose -f docker-compose.init-pass-prod.yml \
  --profile init-prod run pass-init-prod

# Read credential
docker-compose run --rm pass-init pass show trading/api_keys/test

# Backup keys
tar czf gnupg-backup.tar.gz secrets/gnupg | gpg --symmetric --output gnupg-backup.tar.gz.gpg
```

### Tier 2 (Vault)

```bash
# Start Vault
docker-compose -f docker-compose.vault.yml up -d vault vault-init

# Store credential
vault kv put trading/binance api_key=xxx api_secret=yyy

# Read credential
vault kv get trading/binance
```

### Tier 3 (AWS)

```bash
# Store in Secrets Manager
aws secretsmanager create-secret --name trading/binance --secret-string '{"api_key":"xxx"}'

# Read in Python
import boto3
secret = boto3.client('secretsmanager').get_secret_value(SecretId='trading/binance')
```

---

## Next Steps

1. **Choose your tier** based on infrastructure
2. **Review implementation** in your environment
3. **Test migration** in staging first
4. **Plan rotation** schedule
5. **Update runbooks** and documentation
6. **Audit access** post-migration

**Questions?** See [BOT_MANAGER_AND_PASS.md](./BOT_MANAGER_AND_PASS.md) or troubleshooting in [WINDOWS_PASS_INITIALIZATION.md](./WINDOWS_PASS_INITIALIZATION.md).
