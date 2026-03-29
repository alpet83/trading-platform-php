# Container Names Reference

## New Short Names (trd- prefix)

After setting `COMPOSE_PROJECT_NAME=trd` in `.env`:

```bash
# Production services
docker exec trd-mariadb mariadb -uroot -pPASS
docker exec trd-web bash              # Apache2 PHP container
docker exec trd-bot bash              # Bot worker

# Optional (with --profile pass)
docker exec trd-bot-manager bash      # Requires Pass + GPG

# HA/Replication (with docker-compose.replication.yml)
docker exec trd-mariadb-watchdog-primary bash
docker exec trd-mariadb-watchdog-standby bash

# Test (with docker-compose.test.yml)
docker exec trd-mariadb-primary mariadb       # Port 3306
docker exec trd-mariadb-standby mariadb       # Port 3307
docker exec trd-watchdog-primary bash
docker exec trd-watchdog-standby bash
```

## Quick Commands

```bash
# View running containers
docker ps | grep trd

# Check specific service
docker logs trd-web           # Apache2
docker logs trd-bot           # Bot worker  
docker logs trd-bot-manager   # Credential manager

# Stop all (with profile)
docker-compose --profile pass down

# Rebuild and restart
docker-compose build trd-web && docker compose up -d trd-web

# Database access
docker exec -it trd-mariadb mariadb -utrading -p
```

## Before vs After

| Old | New | Usage |
|-----|-----|-------|
| `trading-platform-php-mariadb` | `trd-mariadb` | Main database |
| `trading-platform-php-web` | `trd-web` | Admin API, web UI |
| `trading-platform-php-bot` | `trd-bot` | Bot worker process |
| `trading-platform-php-bot-manager` | `trd-bot-manager` | Pass integration |

## Why trd- prefix?

- Much easier to type: `docker exec trd-web` vs `docker exec trading-platform-php-web`
- Follows container naming conventions (short, memorable)
- Still descriptive for context: trd = trading
- Configured via single `.env` variable: `COMPOSE_PROJECT_NAME=trd`

## Changing Container Prefix

To use different prefix:

```bash
# Edit .env
COMPOSE_PROJECT_NAME=myprefix

# Containers will now be: myprefix-mariadb, myprefix-web, etc.
docker-compose up -d
```
