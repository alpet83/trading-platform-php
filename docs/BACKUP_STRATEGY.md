# Database Backup & Restore Strategy

## Problem Statement
Prior backup strategy had several critical gaps:
1. **Binary MySQL dumps** — unreliable without password integration, large file sizes
2. **No `mariadb-dump` verification** — scripts fell back to legacy `mysqldump` which doesn't exist in modern containers
3. **Incomplete volume backups** — only `signals-legacy-db` volume was archived; main `trd_mariadb_data` missing
4. **No credentials reference file** — SQL dumps alone insufficient for automatic restore; passwords hardcoded in compose/override
5. **No backup retention policy** — old pre-deploy snapshots accumulate without cleanup

## Current Solution (Post-Implementation)

### Tool Chain
- **Database Export:** `mariadb-dump` (native to MariaDB 11.x container)
  - Uses `--single-transaction --skip-lock-tables` for consistent snapshots
  - Output piped to `gzip` for compression (text-based, platform-portable)
  - NOT `mysqldump` (legacy, unmaintained, missing in many modern builds)

- **Volume Backup:** `tar` + `gzip` via temporary Alpine container
  - Preserves directory structure, permissions, ownership within limits
  - No binary format dependencies; works across hosts/OS

- **Configuration Preservation:**
  - `.env` → `.env.bak` (all generated passwords, secrets)
  - `secrets/db_config.php` → `db_config.php.bak` (legacy credentials)
  - `docker-compose.override.yml.bak` (if present, for restore reference)

### Backup Structure

```
var/backup/pre-deploy/
├── 20260413_152002/                          # Timestamp-based directory
│   ├── .env.bak                              # Generated passwords at time of backup
│   ├── db_config.php.bak                     # Legacy PHP credentials
│   ├── docker-compose.override.yml.bak       # (optional) deployment config
│   ├── trading_dump_20260413_152002.sql.gz   # Text SQL (portable)
│   ├── datafeed_dump_20260413_152002.sql.gz
│   ├── binance_dump_20260413_152002.sql.gz
│   ├── [other db]_dump_20260413_152002.sql.gz
│   └── trd_mariadb_data.backup.20260413_152002.tar.gz  # FULL VOLUME
├── deploy-state_20260413_152002.md          # Metadata + restore instructions
└── deploy-state-signals-legacy_20260413_152002.md
```

## Scripts Overview

### prepare-clean-deploy.sh
**Purpose:** Create pre-deployment backup snapshot for later restore/rollback

**Flow:**
```bash
1. stop_write_containers_before_backup()    [prevents mid-transaction backups]
2. backup_databases()                       [mariadb-dump → .sql.gz]
3. backup_configuration_files()             [.env, db_config.php]
4. backup_named_volumes()                   [tar.gz archive]
5. cleanup_containers()                     [docker-compose down]
6. verify_backup_artifacts()                [sanity check all files exist]
7. generate_metadata()                      [restore instructions]
```

**Key Changes:**
- ✅ Uses only `mariadb-dump` (NO fallback to `mysqldump`)
- ✅ Backs up **ALL** databases (trading, datafeed, exchange-specific)
- ✅ Archives **full volume** to `trd_mariadb_data.backup.TIMESTAMP.tar.gz`
- ✅ Saves `.env.bak` alongside dumps for password reference during restore

**Usage:**
```bash
# Interactive (prompts for confirmation)
./scripts/prepare-clean-deploy.sh

# Non-interactive (auto-confirm)
./scripts/prepare-clean-deploy.sh --force
```

### restore-from-backup.sh
**Purpose:** Restore to a previous pre-deployment state

**Flow:**
```bash
1. Validate backup timestamp directory exists
2. Stop running services (docker-compose down)
3. Restore .env, db_config.php from backup
4. (Optional) Restore named volume from .tar.gz
5. Start services (docker-compose up -d)
6. Wait for database health
7. (Fallback) Restore individual database dumps if volume didn't work
```

**Key Changes:**
- ✅ Uses `mariadb` CLI (NOT `mysql`—which is now an alias but semantically cleaner)
- ✅ Restores `.env.bak` first (ensures correct passwords for subsequent restores)
- ✅ Re-creates volume from archive before starting DB
- ✅ Falls back to SQL dump restoration if volume backup was unavailable

**Usage:**
```bash
# List available backups
./scripts/restore-from-backup.sh

# Restore specific backup
./scripts/restore-from-backup.sh 20260413_152002
```

### prepare-clean-deploy-signals-legacy.sh
**Purpose:** Similar backup flow for legacy signals stack (sigsys-db)

**Updates:**
- ✅ Fixed comment: now says `mariadb-dump` (was `mysqldump`)
- ✅ Uses `mariadb-dump` for signals-legacy database dump
- ✅ Backs up both volumes: `trd_signals-legacy-db-data` + `trd_signals-legacy-db-socket`

## Critical Implementation Details

### Why `mariadb-dump` and NOT `mysqldump`?

| Feature | mariadb-dump | mysqldump |
|---------|:---:|:---:|
| **Bundled with MariaDB 11.x** | ✅ | ❌ |
| **Modern PHP container** | ✅ (yes, in mariadb-client) | ❌ (often missing) |
| **Text output (portable)** | ✅ | ✅ |
| **Single-transaction support** | ✅ | ✅ |
| **Maintained/stable** | ✅ | ⚠️ (Legacy) |

**Test:**
```bash
# Inside trd-mariadb container:
docker exec trd-mariadb which mariadb-dump
# Output: /usr/bin/mariadb-dump ✅

docker exec trd-mariadb which mysqldump
# Output: NOT FOUND (Expected & OK)
```

### Password Handling

**Before:** Impossible to restore cleanly without hardcoded passwords
```bash
# ❌ BROKEN: mariadb-dump -uroot [-pHARDCODED] ...
```

**After:** Password sourced from `.env` at time of backup
```bash
# ✅ CORRECT: .env.bak saved at backup time
# ✅ restore-from-backup.sh restores .env first
# ✅ Then uses $MARIADB_ROOT_PASSWORD from restored .env
```

### Volume Backup Rationale

**Why both SQL dumps AND volume archives?**
1. **SQL dumps** — portable, text-based, can review/edit before restore
2. **Volume archives** — faster restore, preserves exact inode/permission state

**Priority during restore:**
1. Try volume restore first (faster, cleaner)
2. Fall back to SQL dump restore if volume wasn't backed up

## Trade-off: Exchange DB Data Loss

**Root Cause Identified:**
- `trd_mariadb_data` was **never comprehensively backed up** before 2026-04-13
- Only `signals-legacy-db` volume had archive (but it's signals-only, not exchange data)
- Partial SQL dumps exist, but exchange tables have minimal rows (4-32 rows)

**Conclusion:**
- Data loss is **real but not from this incident** (was never backed up properly)
- Recovery requires manual template-based reconstruction from user's schema

**Prevention Going Forward:**
1. ✅ Now `prepare-clean-deploy.sh` **always** archives `trd_mariadb_data`
2. ✅ Pre-deploy snapshots include credentials backup (`.env.bak`)
3. ✅ Metadata file documents what was backed up and recovery steps

## Backup Retention Policy (Future)

**Recommendation:**
```bash
# Add to cron or deploy script:
find "var/backup/pre-deploy" -maxdepth 1 -type d -mtime +14 -exec rm -rf {} \;  # Keep 14 days
```

**Reasoning:**
- Pre-deployment snapshots are **temporary** (for quick rollback during testing)
- Permanent backups should be **separate** (weekly full export, S3/NAS rotation)
- 14-day window allows:
  - Multiple deploy cycles without cleanup
  - Safe recovery from "oops, I deleted too much" scenarios
  - Automatic disk space reclamation

## Testing the Backup Chain

### Full Cycle Test
```bash
# 1. Make a backup
./scripts/prepare-clean-deploy.sh --force

# 2. Verify backup artifacts
ls -lh var/backup/pre-deploy/<TIMESTAMP>/

# 3. Simulate accidental deletion
docker volume rm trd_mariadb_data
docker-compose down

# 4. Restore
./scripts/restore-from-backup.sh <TIMESTAMP>

# 5. Verify API health
curl http://localhost:8088/api/index.php
# Expected: 200 OK
```

### Individual Component Tests
```bash
# Database dump only (portable, can transfer between hosts)
zcat var/backup/pre-deploy/<TS>/trading_dump_*.sql.gz | \
    docker-compose exec -T mariadb mariadb -uroot -p$PASS

# Volume restore without database restore
docker volume rm trd_mariadb_data
docker volume create trd_mariadb_data
docker run --rm -v trd_mariadb_data:/data -v var/backup/pre-deploy/<TS>:/b:ro \
    alpine tar xzf /b/trd_mariadb_data.backup.*.tar.gz -C /data
```

## Troubleshooting

### "mariadb-dump: command not found"
```bash
# ❌ WRONG: Inside wrong container or no mariadb-client package
# ✅ FIX: Use docker-compose exec -T mariadb mariadb-dump ...
```

### "Access denied for user 'root'@'localhost'"
```bash
# ❌ WRONG: Password mismatch between script and live DB
# ✅ FIX: Check that restore-from-backup.sh ran first
#         (it restores .env.bak, which sets $MARIADB_ROOT_PASSWORD)
```

### Backup file is 0 bytes / empty
```bash
# ❌ WRONG: Database was empty or mariadb-dump pipeline failed
# ✅ FIX: Check database is running & has data:
docker exec trd-mariadb mariadb -uroot -p$PASS -e "SELECT COUNT(*) FROM mysql.user;"
```

## References

- **MariaDB Dumps:** https://mariadb.com/kb/en/mysqldump/
- **Docker Volume Mount:** https://docs.docker.com/storage/volumes/
- **Gzip Compression:** https://www.gnu.org/software/gzip/
- **This Project:** `p:/opt/docker/trading-platform-php/`
