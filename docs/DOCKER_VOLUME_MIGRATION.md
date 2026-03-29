# Docker Volume Export & Secrets Architecture Summary

**Date:** 2026-03-28

## Changes Made

### 1. **trading-platform-php**: Database Export to Host

**Problem:** MariaDB data was stored in Docker named volume `db-data:`, making it inaccessible on host.

**Solution:** 
- Converted `db-data:` named volume → bind mount `./var/lib/mysql:/var/lib/mysql`
- Updated `docker-compose.yml` to remove named volume declaration
- Created `var/lib/mysql/` directory structure on host with `.gitkeep`

**Result:** 
- ✅ Database files now visible on host at `./var/lib/mysql/`
- ✅ Manual backups easier: `tar -czf backup.tar.gz ./var/lib/mysql/`
- ✅ Direct file-level recovery possible

### 2. **Logs Export (Both Systems)**

Already properly configured:

| System | Logs Location | Status |
|--------|---------------|--------|
| **trading-platform-php** | `./var/log/` | ✅ Bind mount, already mapped |
| **CQDS** | `./logs/` | ✅ Bind mount, already mapped |

### 3. **Application Data Export**

| System | Path | Status |
|--------|------|--------|
| **trading-platform-php** | `./var/data/` | ✅ Bind mount, already mapped |
| **CQDS** | `./projects/` | ✅ Bind mount, already mapped |

### 4. **Secrets Management: pass Integration**

**Architecture:**
- `pass` (Unix password manager) stores API credentials in encrypted form
- GPG keyring controls access (passphrase-protected)
- `bot-manager` service has read-only access to secrets
- Admin scripts enable credential rotation without container rebuild

**Files:**
- `./secrets/pass-store/` — GnuPG-encrypted credential database
- `./secrets/gnupg/` — GPG keyring and configuration

**Scripts Provided:**
- `scripts/manage-pass-secrets.sh` — CLI utility for secret management
  - `init` — Initialize pass store with GPG key
  - `add` — Add new API secret
  - `show` — Decrypt and display secret
  - `sync` — Sync secrets to Docker volumes
  - `deploy` — Deploy secrets to bot-manager container

## Directory Structure

```
trading-platform-php/
├── docker-compose.yml          (UPDATED: db bind mount instead of named volume)
├── var/
│   ├── lib/mysql/              (NEW: Database files exported to host)
│   ├── log/                    (Existing: Application logs)
│   ├── data/                   (Existing: Runtime application data)
│   └── tmp/                    (Existing: Temporary files)
├── secrets/
│   ├── pass-store/             (Existing: Encrypted passwordstore)
│   ├── gnupg/                  (Existing: GPG keyring)
│   └── db_config.php           (Existing: DB credentials)
├── scripts/
│   └── manage-pass-secrets.sh  (NEW: Admin utility for secrets)
└── docs/
    └── DOCKER_VOLUMES_AND_SECRETS.md  (NEW: Comprehensive guide)

/opt/docker/cqds/
├── logs/                       (Existing: All CQDS service logs)
├── data/
│   └── pgdata/                 (PostgreSQL data)
├── projects/                   (Project files, indices)
└── [backups to p:/opt/data/backups/pg/]  (Existing: DB backups)

/opt/docker/docs/
└── CQDS_LOGS_DATA_EXPORT.md    (NEW: CQDS log architecture guide)
```

## Key Benefits

### Storage & Durability
- ✅ **No more lost data on container restart** — all data persists on host
- ✅ **Easy backups** — tar/rsync directly from host filesystem
- ✅ **Recovery** — restore from backup without redeploying container

### Observability
- ✅ **Human-readable logs** — text editors, grep, tail directly accessible
- ✅ **No MCP tool dependency** — logs visible without cq_exec overhead
- ✅ **Analytics** — external tools (ELK, Splunk, CloudWatch) can ingest

### Secrets Management
- ✅ **Encrypted credentials** — pass/GPG ensures secrets never touch plaintext
- ✅ **Admin automation** — scripts enable credential rotation without downtime
- ✅ **Audit trail** — all secret access logged to bot-manager container logs

## Deployment Checklist

### Pre-Deployment

- [ ] Review `docs/DOCKER_VOLUMES_AND_SECRETS.md` (trading-platform-php)
- [ ] Review `docs/CQDS_LOGS_DATA_EXPORT.md` (general CQDS overview)
- [ ] Confirm `docker-compose.yml` updated with bind mounts
- [ ] Verify host directory structure exists: `var/lib/mysql`, `var/log`, `var/data`, `var/tmp`

### First Run

- [ ] Stop old containers: `docker compose down`
- [ ] Migrate DB data if needed: (see recovery section below)
- [ ] Start with new compose: `docker compose up -d`
- [ ] Verify DB files on host: `ls -la ./var/lib/mysql/`
- [ ] Check logs readable: `tail ./var/log/*.log`

### Secrets Setup (Optional: bot-manager)

- [ ] Run: `./scripts/manage-pass-secrets.sh init`
- [ ] Add API credentials: `./scripts/manage-pass-secrets.sh add api/bitmex`
- [ ] Sync to volumes: `./scripts/manage-pass-secrets.sh sync`
- [ ] Start bot-manager: `docker compose --profile pass up -d bot-manager`
- [ ] Deploy secrets: `./scripts/manage-pass-secrets.sh deploy`
- [ ] Verify logs: `docker logs trading-platform-php-bot-manager | grep API-SECRETS`

## Migration Guide (From Named Volume)

If you have existing `db-data` named volume:

```bash
#!/bin/bash
# 1. Backup old database
docker run --rm -v db-data:/dbdata \
  -v "$(pwd)/backup":/backup \
  alpine tar czf /backup/db-backup.tar.gz -C /dbdata .

# 2. Update docker-compose.yml (pull latest)
git pull

# 3. Start fresh MySQL with bind mount
docker compose up -d mariadb

# 4. Wait for MariaDB ready
sleep 10

# 5. Restore data
mkdir -p ./var/lib/mysql
docker run --rm -v db-data:/dbdata \
  -v "$(pwd)/var/lib/mysql":/restore \
  alpine tar xzf /restore/../backup/db-backup.tar.gz -C /restore

# 6. Stop and remove old volume
docker compose down
docker volume rm trading-platform-php_db-data

# 7. Restart with new config
docker compose up -d
```

## Monitoring & Maintenance

### Disk Space

```bash
# Check volume sizes
du -sh ./var/lib/mysql     # Database
du -sh ./var/log           # Logs (watch this!)
du -sh ./var/data          # App data
du -sh ./secrets/          # Secrets (usually small)

# Alert if logs exceed 50 GB
```

### Log Rotation

Implement `/etc/logrotate.d/trading-platform-php`:

```
/opt/docker/trading-platform-php/var/log/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    create 0644 root root
}
```

### Backup Schedule

```bash
# Daily automated backup (add to cron)
0 3 * * * tar -czf /backups/trading-db-$(date +\%Y\%m\%d).tar.gz /opt/docker/trading-platform-php/var/lib/mysql/
```

## Security Notes

1. **Permissions:**
   ```bash
   chmod 700 ./secrets/gnupg/
   chmod 700 ./secrets/pass-store/
   ```

2. **No Plaintext Secrets in Git:**
   ```bash
   # Verify .gitignore
   grep -E 'secrets/pass-store|secrets/gnupg|\.env' .gitignore
   ```

3. **GPG Key Protection:**
   - Passphrase-protect GPG keys
   - Back up private keys (Yubikey, hardware wallet, offline storage)
   - Distribute public keys only via secure channels

4. **Log Privacy:**
   - Logs are NOT encrypted; assume system admins can read them
   - Don't log sensitive values (use `[REDACTED]` placeholders)
   - Archive old logs to secure storage (AWS S3, encrypted NAS)

## Troubleshooting

### Database Won't Start After Migration

```bash
# Check permissions on host directory
ls -la ./var/lib/mysql/
chmod 755 ./var/lib/mysql/

# Restart MariaDB
docker compose restart mariadb
docker logs trading-platform-php-mariadb | head -30
```

### Logs Not Appearing on Host

```bash
# Verify bind mount is active
docker inspect trading-platform-php-bot | grep Mounts -A 10

# Check host path permissions
touch ./var/log/test.txt
docker exec trading-platform-php-bot echo "test" >> /app/var/log/test2.txt
cat ./var/log/test2.txt
```

### Pass Secrets Not Available in bot-manager

```bash
# Verify volumes are mounted
docker inspect trading-platform-php-bot-manager | grep Mounts

# Check pass-store structure inside container
docker exec trading-platform-php-bot-manager ls -la /root/.password-store/

# Try manual secret retrieval
docker exec trading-platform-php-bot-manager \
  bash -c 'export PASSWORD_STORE_DIR=/root/.password-store && pass show api/bitmex'
```

## Reference Documents

- [DOCKER_VOLUMES_AND_SECRETS.md](./DOCKER_VOLUMES_AND_SECRETS.md) — Complete volume & secrets guide
- [CQDS_LOGS_DATA_EXPORT.md](../docs/CQDS_LOGS_DATA_EXPORT.md) — CQDS logging architecture
- `scripts/manage-pass-secrets.sh --help` — Secrets management CLI

---

**Status:** Ready for deployment
**Last Updated:** 2026-03-28
