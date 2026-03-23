# signals-server-ts  v0.1-alpha

> Part of the [trading-platform-php](../README.md) project.

A web-based management interface for a crypto trading bot — signals control panel,
position monitoring, and user management with Telegram-based authentication.

This sub-product runs as a **Docker overlay** on top of the existing PHP trading
infrastructure. It does not contain the trading engine itself; it provides a
comfortable browser UI and a secure API proxy layer in front of the PHP signals server.

## What's in this directory

| Component | Technology | Role |
|---|---|---|
| Frontend/ | Nuxt 3, Vue 3, TypeScript, Tailwind CSS | Signals table, profile, Telegram OAuth login |
| Backend/ | NestJS, TypeScript, Passport JWT | Auth gateway, API proxy to PHP signals server |
| docker/ | Nginx, Docker Compose | Static file serving, reverse proxy, TLS termination |
| server/ | PostgreSQL init scripts | User database setup |

The NestJS backend exists specifically to:
1. Validate JWT tokens issued after Telegram OAuth
2. Inject a secret `Authorization: Bearer <FRONTEND_TOKEN>` header into requests
   forwarded to the PHP server — this token never reaches the browser

## Architecture overview

```
Browser
  └─ HTTPS → Nginx
               ├─ /           → Nuxt static SPA (dist/)
               └─ /api/*      → NestJS (node container)
                                   ├─ Auth: JWT guard
                                   └─ /api/signals/* → PHP signals server
                                                        (sig_edit.php?format=json)
```

### Relation to the rest of TradeBot

```
TradeBot/
├── signals-server/       ← PHP signals API + Telegram bot (required, deploy separately)
├── signals-server.ts/    ← THIS directory (NestJS + Nuxt overlay)
├── trading_core.php      ← Trading engine (runs independently)
└── ...
```

This component **requires** a running `signals-server/` instance.
See [`../signals-server/README.md`](../signals-server/README.md) for setup instructions.

## Stack

| Layer | Technology |
|---|---|
| Frontend | Nuxt 3, Vue 3, TypeScript, Tailwind CSS, vue-i18n |
| Backend | NestJS, TypeScript, Passport JWT |
| Database | PostgreSQL 16 |
| PHP signals API | PHP 8.1+, MariaDB, Apache 2.4 (separate server) |
| Infrastructure | Docker Compose, Nginx, sops+age (secret encryption) |

## Prerequisites

- Docker + Docker Compose
- [sops](https://github.com/getsops/sops/releases)
- [age](https://github.com/FiloSottile/age/releases)
- [yq](https://github.com/mikefarah/yq/releases)
- A running `../signals-server/` deployment
- Loki Docker driver (optional, for log aggregation):
  ```bash
  docker plugin install grafana/loki-docker-driver:2.9.4 --alias loki --grant-all-permissions
  ```

## Configuration

Environment is managed via `sops`-encrypted YAML files in `.envs/<instance>/`:

| File | Purpose |
|---|---|
| `.envs/main/public.yaml` | Non-secret config: `APP_BASE_PATH`, `DOMAIN`, `TELEGRAM_BOT_USERNAME` |
| `.envs/main/secret.enc.yaml` | Encrypted secrets: tokens, DB passwords, API keys |

### Setting up secrets

1. Generate an age key pair:
   ```bash
   age-keygen -o .agekey
   ```
   The public key is printed to stdout — copy it into `.agekey.public`.

2. Create `.envs/main/secret.yaml` (unencrypted draft), fill it analogously to
   `public.yaml`, then encrypt:
   ```bash
   bash scripts/encrypt.sh main
   ```

3. To edit secrets interactively:
   ```bash
   bash scripts/edit.sh main
   ```

4. To decrypt for inspection:
   ```bash
   bash scripts/decrypt.sh main
   ```

## Running

All environment variables are assembled from both YAML files by `run.sh` (via `yq`),
exported to the shell, then passed to `docker-compose` for `\` substitution:

```bash
bash scripts/run.sh "docker-compose up -d --build"
```

## Environment variables reference

| Variable | Source | Description |
|---|---|---|
| `APP_BASE_PATH` | `public.yaml` | URL prefix for the app, e.g. `/botctl` |
| `DOMAIN` | `public.yaml` | Server domain, e.g. `myserver.com` |
| `TELEGRAM_BOT_USERNAME` | `public.yaml` | Telegram bot username (without @) |
| `SIGNALS_API_URL` | `secret.yaml` | Base URL of the PHP signals server |
| `AUTH_TOKEN` | `secret.yaml` | Bearer token matching `FRONTEND_TOKEN` in PHP config |
| `BOT_TOKEN` | `secret.yaml` | Telegram Bot API token |
| `DATABASE_URL` | `secret.yaml` | PostgreSQL connection string |
| `TRADING_DB_AUTH_URL` | `secret.yaml` | MySQL connection string for trading DB |

## Development

```bash
cd frontend
yarn install
APP_BASE_PATH=/dev yarn dev
```

```bash
cd backend
yarn install
yarn start:dev
```