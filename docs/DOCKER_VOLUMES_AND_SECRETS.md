# Docker Volumes & Secrets Management

## Overview

This document describes host directory mappings (volumes), database exports, logging architecture, and secrets management for the `trading-platform-php` Docker Compose stack.

## Volume Structure

All runtime data is exported to the **host filesystem** for durability, debugging, and administrative access. Named volumes are NOT used; all volumes are bind mounts.

### Directory Layout

```
./var/
├── lib/
│   └── mysql/               # MariaDB data (host bind mount)
├── log/                     # Bot, web server, application logs
├── data/                    # Runtime application data (orders, trades, cache)
└── tmp/                     # Temporary files, spool directories

./secrets/
├── db_config.php            # Readonly DB credentials (generated)
├── pass-store/              # GnuPG encrypted password store (for bot-manager)
└── gnupg/                   # GPG keyring (for bot-manager)
```

## Volumes Mapping

| Container | Source (Host) | Target (Container) | Access | Purpose |
|-----------|---------------|-------------------|--------|---------|
| **mariadb** | `./var/lib/mysql` | `/var/lib/mysql` | RW | Database files |
| **web** | `./var/log` | `/app/var/log` | RW | Application logs |
| — | `./var/data` | `/app/var/data` | RW | Runtime application data |
| — | `./var/tmp` | `/app/var/tmp` | RW | Temporary files |
| — | `./src` | `/app/src` | RW | PHP source code |
| — | `./secrets/db_config.php` | `/app/src/lib/db_config.php` | RO | DB credentials |
| **bot** | `./var/log` | `/app/var/log` | RW | Bot logs (heavy I/O!) |
| — | `./var/data` | `/app/var/data` | RW | Order state, signals cache |
| — | `./var/tmp` | `/app/var/tmp` | RW | Temporary files |
| — | `./src` | `/app/src` | RW | PHP source code |
| — | `./secrets/db_config.php` | `/app/src/lib/db_config.php` | RO | DB credentials |
| **bot-manager** | `./var/log` | `/app/var/log` | RW | Manager logs |
| — | `./var/data` | `/app/var/data` | RW | Runtime state |
| — | `./secrets/pass-store` | `/root/.password-store` | RO | Encrypted secrets (pass store) |
| — | `./secrets/gnupg` | `/root/.gnupg` | RO | GPG keyring |

## Log Management

### Why Host Export?

- **Storage**: Robot instances generate **high-volume logs** (~GB/day per instance)
- **Container limits**: Keeping logs inside containers will cause disk exhaustion
- **Debugging**: Logs must be **human-readable** outside container (via text editor, grep, tail)
- **Analytics**: External log aggregation tools (ELK, Splunk, etc.) require host access

### Log Directories

All services write to `./var/log/`:

```
./var/log/
├── bot/
│   ├── bitmex_bot.log          # Main bot running logs (highest volume!)
│   ├── orders.log              # Order tracking
│   └── signals.log             # Signal processing
├── web/
│   ├── access.log              # HTTP requests
│   └── error.log               # PHP errors
└── manager/
    ├── bot_manager.log         # Manager instance logs
    └── api_sync.log            # API secret sync logs
```

### Rotation & Cleanup

Docker logging driver options are configured in `docker-compose.yml`:

```yaml
logging:
  driver: "json-file"
  options:
    max-size: "1m"           # Single log file max size
    max-file: "10"           # Keep max 10 rotated files
    labels: "service=trading-platform-php"
```

For long-term retention, implement host-level log rotation via `logrotate` or external solution.

## Database Export

### MariaDB Data Directory

Database files are stored in `./var/lib/mysql/` on the host.

```
./var/lib/mysql/
├── trading/                # Main trading database
│   ├── orders.ibd
│   ├── signals.ibd
│   └── ...
├── mysql/                  # System database (users, privileges)
├── ib_buffer_pool
├── ibdata1                 # Shared tablespace
└── ...
```

### Backup & Recovery

**Manual backup** (while containers running):

```bash
docker exec trading-platform-php-mariadb mysqldump \
  -u trading \
  --password=trading_change_me \
  --all-databases > backup-$(date +%Y%m%d-%H%M%S).sql
```

**Restore from `.sql` file:**

```bash
docker exec -i trading-platform-php-mariadb mysql \
  -u trading \
  --password=trading_change_me \
  < backup-YYYYMMDD-HHMMSS.sql
```

**Direct filesystem backup** (containers must be stopped):

```bash
tar -czf trading-db-backup-$(date +%Y%m%d).tar.gz ./var/lib/mysql/
```

## Secrets Management: `pass` Integration

### Architecture

The `trading-platform-php` stack uses **[pass](https://www.passwordstore.org/)** (a Unix password manager) for sensitive credential management, accessed by the `bot-manager` service.

**pass** stores secrets in **GnuPG-encrypted files**, allowing secure retrieval in automated workflows (e.g., API refreshes, credential rotation).

### Directory Structure

```
./secrets/
├── pass-store/             # Encrypted password database (HOME/.password-store)
│   └── api/
│       ├── bitmex.gpg      # Encrypted Bitmex API key
│       ├── binance.gpg     # Encrypted Binance API key
│       └── signalbox.gpg   # Encrypted SignalBox token
├── gnupg/                  # GPG configuration & keys (HOME/.gnupg)
│   ├── pubring.gpg         # Public keyring
│   ├── secring.gpg         # Secret keyring (encrypted)
│   ├── trustdb.gpg         # Trust database
│   └── gpg-agent.conf      # Agent configuration
└── db_config.php           # Database credentials (separate from pass)
```

### bot-manager Profile Activation

`bot-manager` is configured with a Docker Compose profile:

```bash
# Start with bot-manager (requires pass setup)
docker compose --profile pass up -d

# Start WITHOUT bot-manager (default)
docker compose up -d
```

### Using pass in bot-manager

The `bot-manager` service has read-only access to encrypted secrets:

```bash
# Inside bot-manager container (as root)
export PASSWORD_STORE_DIR=/root/.password-store
pass show api/bitmex          # Decrypt and display
pass show api/binance
pass show api/signalbox
```

### Admin Workflow: Adding API Secrets

**1. Initialize pass store (on dev machine, once):**

```bash
# Create GPG key for the team/service
gpg --gen-key
# Follow prompts, use email like: trading-platform-bots@example.com

# Initialize pass with that key
pass init trading-platform-bots@example.com
```

**2. Add an API secret:**

```bash
# Add new secret (interactively)
pass insert -m api/bitmex
# Prompts for multi-line password (paste from secure source)

# Or from environment variable
echo "$BITMEX_API_SECRET" | pass insert -e api/bitmex
```

**3. Sync secrets to deployment container:**

```bash
# Copy encrypted pass-store directory to ./secrets/pass-store/
cp -r ~/.password-store/* ./secrets/pass-store/

# Copy GPG keys to ./secrets/gnupg/
cp -r ~/.gnupg/* ./secrets/gnupg/
```

**4. Start bot-manager with secrets:**

```bash
docker compose --profile pass pull && docker compose --profile pass up -d bot-manager
```

### Admin API-Deployment Script

Future admin scripts (e.g., `scripts/deploy-api-secrets.sh`) will:

1. Extract API credentials from `pass` on admin machine
2. Inject into bot-manager container via `docker exec`
3. Verify by checking bot logs for successful API authentication

**Example (pseudocode):**

```bash
#!/bin/bash
# scripts/deploy-api-secrets.sh

CONTAINER="trading-platform-php-bot-manager"

# Read from host pass store
BITMEX_KEY=$(pass show api/bitmex)
BINANCE_KEY=$(pass show api/binance)

# Inject via docker exec → bot-manager PHP script
docker exec $CONTAINER php /app/src/bin/load-api-keys.php \
  --bitmex="$BITMEX_KEY" \
  --binance="$BINANCE_KEY"

# Verify logs
docker logs $CONTAINER | grep "API keys loaded successfully"
```

## Security Best Practices

1. **Never commit secrets**: Add to `.gitignore`:
   ```
   secrets/pass-store/
   secrets/gnupg/
   secrets/db_config.php
   .env
   ```

2. **Use environment variables** for credentials in CI/CD:
   ```bash
   export PASS_STORE_DIR="/secure/path/to/pass-store"
   export GPG_HOME_DIR="/secure/path/to/gnupg"
   docker compose up
   ```

3. **Rotate DB credentials** regularly:
   - Update `MARIADB_PASSWORD` in `.env`
   - Restart containers: `docker compose down && docker compose up -d`
   - Verify connectivity in logs

4. **GPG key protection**:
   - Passphrase-protect GPG private key
   - Store key backup offline (yubikey, hardware wallet, etc.)
   - Restrict file permissions: `chmod 700 ./secrets/gnupg/`

5. **Audit logging**:
   - Monitor `./var/log/manager/api_sync.log` for credential access
   - Alert on failed API authentications

## Monitoring & Cleanup

### Check Volume Usage

```bash
du -sh ./var/lib/mysql/     # Database size
du -sh ./var/log/           # Logs size
du -sh ./var/data/          # App data size
```

### Archieve Old Logs

```bash
# Find and archive logs older than 30 days
find ./var/log -name "*.log" -mtime +30 -exec gzip {} \;
```

### Free Space

```bash
# Remove old bot-manager logs (keep last 10 files)
ls -t ./var/log/manager/*.log | tail -n +11 | xargs rm -f
```

## Example: Full Deployment Checklist

- [ ] Host directories created: `var/lib/mysql`, `var/log`, `var/data`, `var/tmp`
- [ ] `.gitkeep` files placed in all runtime directories
- [ ] `docker-compose.yml` updated with bind mounts (no named volumes)
- [ ] `./secrets/db_config.php` generated from template
- [ ] `./secrets/pass-store/` and `./secrets/gnupg/` populated (if using bot-manager)
- [ ] `.env` file set with `PASS_STORE_DIR` and `GPG_HOME_DIR`
- [ ] MariaDB initialized: `docker compose up mariadb`
- [ ] Bot manager started (optional): `docker compose --profile pass up bot-manager`
- [ ] Logs verified accessible on host: `cat ./var/log/bot/*.log`
- [ ] Database backed up: `docker exec trading-platform-php-mariadb mysqldump ...`

