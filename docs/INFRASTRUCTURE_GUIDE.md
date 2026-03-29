# HA MariaDB + Pass Credentials Infrastructure

> Complete setup for high-availability MariaDB with automated failover, credential management, and bot-manager integration

---

## What Is This?

**trading-platform-php** defines complete infrastructure for:

- **HA MariaDB pair** (Primary + Standby) with GTID replication
- **Automatic failover** via watchdog services (enforce single-writer policy)
- **Pass credentials** for secure credential storage and distribution
- **bot-manager service** that reads trading signals from encrypted credential store
- **Docker Compose** for local testing + production two-host deployment

---

## Quick Links

| Goal | Start Here |
|------|------------|
| **Get running in 5 minutes (Windows)** | [QUICKSTART_WINDOWS.md](./QUICKSTART_WINDOWS.md) |
| **Detailed Windows Pass setup** | [WINDOWS_PASS_INITIALIZATION.md](./WINDOWS_PASS_INITIALIZATION.md) |
| **Understand HA architecture** | [PAIR_REDUNDANCY_AUTOMATION.md](./PAIR_REDUNDANCY_AUTOMATION.md) |
| **Test HA failover scenarios** | [TESTING_HA_REPLICATION.md](./TESTING_HA_REPLICATION.md) |
| **bot-manager + Pass integration** | [BOT_MANAGER_AND_PASS.md](./BOT_MANAGER_AND_PASS.md) |
| **Container naming reference** | [CONTAINER_NAMING.md](./CONTAINER_NAMING.md) |

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     Docker Compose                           │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────────┐         ┌──────────────────┐          │
│  │ trd-mariadb-     │         │ trd-mariadb-     │          │
│  │   PRIMARY        │◄────────┤   STANDBY        │          │
│  │ (3306, writable) │ GTID    │ (3307, read-only)│          │
│  │                  │ Repl.   │                  │          │
│  └──────────────────┘         └──────────────────┘          │
│         △                              △                     │
│         │                              │                     │
│    ┌────┴───────────────────────────┬─┘                     │
│    │                                │                        │
│  ┌─┴──────────────────┐   ┌────────┴──┐                     │
│  │ trd-watchdog-      │   │ trd-       │                     │
│  │   PRIMARY          │   │ watchdog-  │                     │
│  │ (enforce writer)   │   │ STANDBY    │                     │
│  └────────────────────┘   │ (enforce   │                     │
│                           │  read-only)│                     │
│                           └────────────┘                     │
│                                                               │
│  ┌──────────────────────────────────────────┐               │
│  │ Credentials Store (Pass + GPG)           │               │
│  │ secrets/                                 │               │
│  │  ├── gnupg/         (GPG master keys)    │               │
│  │  └── pass-store/    (Encrypted creds)    │               │
│  └──────────────────────────────────────────┘               │
│           ▲ (mounted read-only)                             │
│           │                                                  │
│  ┌────────┴──────────────────┐                              │
│  │ trd-bot-manager           │                              │
│  │ (reads Pass credentials)  │                              │
│  └───────────────────────────┘                              │
│                                                               │
└─────────────────────────────────────────────────────────────┘

Replication Flow:
  PRIMARY (write) → GTID log → STANDBY (apply)
  PRIMARY writes go to both DBs simultaneously
  Watchdogs enforce: PRIMARY writable, STANDBY read_only

Credential Flow:
  Pass store (GPG-encrypted) → bot-manager (via key ID)
  → Trading signals sent to broker APIs
  → Market orders placed
```

---

## File Structure

```
trading-platform-php/
│
├── docker-compose.yml                        # Production config
├── docker-compose.test.yml                   # HA pair testing (PRIMARY + STANDBY + watchdogs)
├── docker-compose.init-pass.yml              # Standalone Pass initialization
├── docker-compose.replication.yml            # Two-host HA profiles
│
├── .env                                       # Central configuration (COMPOSE_PROJECT_NAME=trd, passwords, etc.)
│
├── scripts/
│   ├── init-pass.sh                          # Batch-mode Pass + GPG initialization
│   ├── configure-replica.sh                  # Configure STANDBY → PRIMARY replication
│   ├── auto-rejoin.sh                        # Auto-rejoin after transient failures
│   ├── reseed-from-primary.sh                # Full resync from PRIMARY
│   ├── ensure-single-writer.sh               # Enforce PRIMARY writable, STANDBY read-only
│   └── watchdog-loop.sh                      # Continuous policy enforcement
│
├── docs/
│   ├── INFRASTRUCTURE_GUIDE.md              # This guide
│   ├── QUICKSTART_WINDOWS.md                # 5-minute quick start (Windows)
│   ├── COMMANDS_REFERENCE.md                # Copy-paste command cheat sheet
│   ├── WINDOWS_PASS_INITIALIZATION.md        # Windows/Docker Desktop setup guide
│   ├── PAIR_REDUNDANCY_AUTOMATION.md         # HA architecture deep-dive
│   ├── TESTING_HA_REPLICATION.md             # Test scripts & validation
│   ├── BOT_MANAGER_AND_PASS.md               # Credential management architecture
│   ├── CONTAINER_NAMING.md                   # Container names quick ref
│   └── TESTING_QUICK_REFERENCE.md            # One-page test cheatsheet
│
└── README.md                                 # Legacy project overview
```

---

## Container Names

All container names use prefix: `trd-*` (controlled by `COMPOSE_PROJECT_NAME` in `.env`)

| Service | Container Name | Port | Mode |
|---------|---|---|---|
| MariaDB Primary | `trd-mariadb-primary` | 3306 | read-write |
| MariaDB Standby | `trd-mariadb-standby` | 3307 | read-only |
| Watchdog Primary | `trd-watchdog-primary` | internal | monitor |
| Watchdog Standby | `trd-watchdog-standby` | internal | monitor |
| Web API | `trd-web` | 80/443 | - |
| Bot Manager | `trd-bot-manager` | internal | reads Pass |
| Pass Init | `trd-pass-init` | N/A | one-time |

---

## Environment Configuration (.env)

```bash
# Project prefix
COMPOSE_PROJECT_NAME=trd

# MariaDB
MARIADB_ROOT_PASSWORD=root
MARIADB_ROOT_HOST=%
MARIADB_REPL_USER=repl_user
MARIADB_REPL_PASSWORD=repl_password
MARIADB_DATABASE=trading

# Watchdog
MARIADB_MAX_REPL_LAG_SECONDS=300
MARIADB_REPL_CHECK_INTERVAL=10

# Paths
PASS_STORE_DIR=./secrets/pass-store
```

---

## ⚠️  Security Notice

**Current Pass initialization uses passphrase-less GPG.** This is acceptable for:
- ✅ Development on Windows/Docker Desktop
- ✅ Local testing before production

**NOT acceptable for production.** Before deploying to production, see:
- [Security Notes in WINDOWS_PASS_INITIALIZATION.md](./WINDOWS_PASS_INITIALIZATION.md#security-notes)
- Implement: gpg-agent + passphrase, HashiCorp Vault, or Secrets Manager

---

## Getting Started

### Option 1: Windows/Docker Desktop (Recommended)

```bash
# 1. Initialize Pass (batch mode, no prompts)
docker-compose -f docker-compose.init-pass.yml --profile init run pass-init

# 2. Start HA pair (if testing locally)
docker-compose -f docker-compose.test.yml up -d

# 3. Configure replication (one-time)
docker exec trd-mariadb-standby mariadb -uroot -proot << 'EOF'
CHANGE MASTER TO
  MASTER_HOST='trd-mariadb-primary',
  MASTER_AUTO_POSITION=1,
  MASTER_USER='repl_user',
  MASTER_PASSWORD='repl_password';
START REPLICA;
EOF

# 4. Verify replication
docker exec trd-mariadb-standby mariadb -uroot -proot -e "SHOW REPLICA STATUS\G"
```

See [QUICKSTART_WINDOWS.md](./QUICKSTART_WINDOWS.md) for details.

### Option 2: Two-Host HA Deployment

```bash
# Host 1 (Primary):
docker-compose -f docker-compose.replication.yml --profile ha-primary up -d trd-mariadb-watchdog-primary

# Host 2 (Standby):
docker-compose -f docker-compose.replication.yml --profile ha-standby up -d trd-mariadb-watchdog-standby
# (export PRIMARY_HOST=<host1-ip> before this)
```

See [PAIR_REDUNDANCY_AUTOMATION.md](./PAIR_REDUNDANCY_AUTOMATION.md) for two-host setup.

---

## Pass Credentials Setup

### Initialize (first time)

```bash
# Batch-mode initialization (no TTY required)
docker-compose -f docker-compose.init-pass.yml --profile init run pass-init

# Verify
docker-compose run --rm pass-init pass ls
```

### Add Credentials

```bash
# Interactive
docker-compose run pass-init bash
pass insert trading/api_keys/binance
# enter username, password, etc.

# Or non-interactive
echo "my_api_key_secret" | docker-compose run --rm pass-init pass insert -f trading/api_keys/binance
```

### Use in Containers

```yaml
# In docker-compose.yml
services:
  trd-bot-manager:
    volumes:
      - ./secrets/pass-store:/root/.password-store:ro
    environment:
      GNUPGHOME: /root/.gnupg
```

See [WINDOWS_PASS_INITIALIZATION.md](./WINDOWS_PASS_INITIALIZATION.md) for troubleshooting.

---

## Testing & Validation

### Quick Test Suite

```bash
# Test 1: Verify HA pair configuration
bash scripts/test-ha-replication-pair.sh

# Test 2: Verify Pass initialization
docker-compose run --rm pass-init pass ls -la

# Test 3: Write test data to PRIMARY, verify on STANDBY
docker exec trd-mariadb-primary mariadb -uroot -proot << 'EOF'
INSERT INTO replication_test (message) VALUES ('Test data');
EOF
docker exec trd-mariadb-standby mariadb -uroot -proot \
  -e "SELECT * FROM replication_test;"
```

### Full Test Suite

See [TESTING_HA_REPLICATION.md](./TESTING_HA_REPLICATION.md) for comprehensive test scenarios including:
- Failover validation
- Data consistency checks
- Watchdog policy enforcement
- Credential rotation under load

---

## Common Operations

| Operation | Command |
|-----------|---------|
| **Start full stack** | `docker-compose up -d` |
| **Start HA test pair** | `docker-compose -f docker-compose.test.yml up -d` |
| **Stop all containers** | `docker-compose down` |
| **View logs** | `docker logs trd-mariadb-primary` |
| **Database shell (PRIMARY)** | `docker exec -it trd-mariadb-primary mariadb -uroot -proot` |
| **Database shell (STANDBY)** | `docker exec -it trd-mariadb-standby mariadb -uroot -proot -P 3307` |
| **Check replication status** | `docker exec trd-mariadb-standby mariadb -uroot -proot -e "SHOW REPLICA STATUS\G"` |
| **List credentials** | `docker-compose run --rm pass-init pass ls` |
| **Show credential** | `docker-compose run --rm pass-init pass show <path>` |
| **Add credential** | `docker-compose run --rm pass-init pass insert <path>` |
| **Restart watchdog** | `docker-compose restart trd-watchdog-primary trd-watchdog-standby` |

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Replication not syncing" | Check replication channel: `SHOW REPLICA STATUS\G` |
| "Standby not read-only" | Run: `docker exec trd-mariadb-standby mariadb -uroot -proot -e "SET GLOBAL read_only=ON;"` |
| "Pass initialization failed" | See [WINDOWS_PASS_INITIALIZATION.md](./WINDOWS_PASS_INITIALIZATION.md) troubleshooting section |
| "bot-manager can't read credentials" | Verify mount: `docker exec trd-bot-manager ls /root/.password-store` |
| "Container names are long" | Already fixed! COMPOSE_PROJECT_NAME=trd shortens all names |

---

## Security Checklist

- [ ] GPG keys stored in `secrets/gnupg/` (not committed to Git)
- [ ] Pass store in `secrets/pass-store/` (not committed to Git)
- [ ] `.gitignore` includes `secrets/` directory
- [ ] Pass volumes mounted as read-only (`:ro`) in containers
- [ ] Database passwords rotated from defaults in production
- [ ] Watchdog policy enforcement active (no manual PRIMARY state changes)
- [ ] Replication lag monitored (alert if > MARIADB_MAX_REPL_LAG_SECONDS)

---

## Production Readiness Checklist

- [ ] HA pair tested on two separate hosts
- [ ] Failover scenario simulated (STOP PRIMARY, verify STANDBY takes over)
- [ ] Data backup strategy defined (backup PRIMARY, STANDBY, or both)
- [ ] Monitoring configured (Prometheus metrics, Grafana dashboards)
- [ ] On-call runbook created ([TESTING_QUICK_REFERENCE.md](./TESTING_QUICK_REFERENCE.md))
- [ ] Credentials rotation process documented
- [ ] Disaster recovery tested (reseed-from-primary.sh validated)

---

## Support & Documentation

- **Quick Start (5 min):** [QUICKSTART_WINDOWS.md](./QUICKSTART_WINDOWS.md)
- **Windows Setup (detailed):** [WINDOWS_PASS_INITIALIZATION.md](./WINDOWS_PASS_INITIALIZATION.md)
- **HA Architecture:** [PAIR_REDUNDANCY_AUTOMATION.md](./PAIR_REDUNDANCY_AUTOMATION.md)
- **Testing Guide:** [TESTING_HA_REPLICATION.md](./TESTING_HA_REPLICATION.md)
- **Quick Reference:** [TESTING_QUICK_REFERENCE.md](./TESTING_QUICK_REFERENCE.md)
- **bot-manager Guide:** [BOT_MANAGER_AND_PASS.md](./BOT_MANAGER_AND_PASS.md)
- **Container Names:** [CONTAINER_NAMING.md](./CONTAINER_NAMING.md)

---

## Version History

| Date | Version | Changes |
|------|---------|---------|
| 2026-03-28 | 1.0 | Initial HA pair infrastructure with Pass credentials |

---

**Last Updated:** 2026-03-28  
**Maintainer:** trading-platform-php team  
**Status:** Production-ready for single-host testing; two-host deployment documentation complete
