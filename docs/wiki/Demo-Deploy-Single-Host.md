# Demo Deploy (Single Host) Step by Step

This is the primary demo deployment flow for running all main containers on one host.
It is written for practical onboarding and links to detailed docs only when needed.

## Quick Mental Model (Before Commands)

On one host, you typically work with two container assemblies:

1. Legacy signals assembly (`docker-compose.signals-legacy.yml`)
	Role: expose legacy signals API bridge (`signals-legacy`) and its DB.
2. Trading assembly (`docker-compose.yml`)
	Role: run trading stack (`mariadb`, `web`, optional bot/datafeed).

For a minimal demo deploy, two assemblies are enough:

1. Bring up legacy signals first (dependency source for signals URLs).
2. Bring up trading group second (`deploy-simple.sh` or `deploy-simple.ps1`).

## Scope

This flow covers:

- `mariadb`
- `web`
- optional `signals-legacy`
- optional `datafeed`

Out of scope: two-host HA production rollout. For HA details, see [PAIR_REDUNDANCY_AUTOMATION.md](../PAIR_REDUNDANCY_AUTOMATION.md).

## 0) Prerequisites

1. Docker Desktop (or Docker Engine + Compose v2) installed.
2. Repository cloned locally.
3. `P:/GitHub/alpet-libs-php` available for bootstrap runtime includes.

If you are on Windows, keep [QUICKSTART_WINDOWS.md](../QUICKSTART_WINDOWS.md) open in parallel.

## 1) Prepare Environment

1. Ensure `.env` exists (copy from `.env.example` if needed).
2. Review `WEB_PORT`, `WEB_PUBLISH_IP`, and DB variables.
3. Keep secrets local; do not commit real tokens.

Security baseline: [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md).

## 2) Deploy Stage A: Legacy Signals (Dependency Source)

Linux/Git Bash:

```bash
sh scripts/deploy-signals-legacy.sh
```

PowerShell:

```powershell
./scripts/deploy-signals-legacy.ps1
```

What this stage does:

1. Ensures `secrets/signals_db_config.php` exists (copied from example if missing).
2. Starts `signals-legacy-db` and `signals-legacy` from overlay compose.
3. Runs quick HTTP probe for legacy endpoint.

Details: [SIGNALS_LEGACY_CONTAINER.md](../SIGNALS_LEGACY_CONTAINER.md).

## 3) Deploy Stage B: Trading Group

Linux/Git Bash:

```bash
sh scripts/deploy-simple.sh
```

PowerShell:

```powershell
./scripts/deploy-simple.ps1
```

What this stage does (high-level):

1. Bootstraps runtime files and generated configs.
2. Builds `mariadb` and `web` images.
3. Starts DB and waits for health.
4. Starts `web` and probes core endpoints.

Reference details: [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md).

## 4) Validate Base Services

Run:

```bash
docker compose ps
```

Then verify:

1. Admin page is reachable at `http://127.0.0.1:8088/basic-admin.php` (or your configured publish address).
2. API entrypoint is reachable at `http://127.0.0.1:8088/api/index.php`.

If checks fail, inspect logs:

```bash
docker compose logs --tail 100 mariadb web
```

## 5) Validate Cross-Assembly Path

1. Legacy endpoint responds on `${SIGNALS_LEGACY_PORT:-8090}`.
2. Trading API/admin endpoints respond on `${WEB_PORT:-8088}`.
3. If you use signals forwarding, verify `SIGNALS_API_URL` points to legacy bridge URL.

## 6) Optional: Enable Datafeed Container

If your scenario includes datafeed loaders, ensure datafeed source is available and then start the stack with current compose settings.

Datafeed notes: [DATAFEED_DEPS.md](../DATAFEED_DEPS.md).

## 7) Optional: Configure Bot Credentials

If you plan to execute trades, inject exchange credentials using project scripts.

Start from: [COMMANDS_REFERENCE.md](../COMMANDS_REFERENCE.md) and [BOT_MANAGER_AND_PASS.md](../BOT_MANAGER_AND_PASS.md).

## 8) Post-Deploy Checklist

1. `docker compose ps` shows healthy containers.
2. Base admin/API endpoints respond.
3. Legacy API endpoint responds.
4. Secrets are local and not exposed in git status.

## 9) Next Paths

1. Production hardening: [PRODUCTION_SECURITY.md](../PRODUCTION_SECURITY.md)
2. Volumes/secrets model: [DOCKER_VOLUMES_AND_SECRETS.md](../DOCKER_VOLUMES_AND_SECRETS.md)
3. HA roadmap: [PAIR_REDUNDANCY_AUTOMATION.md](../PAIR_REDUNDANCY_AUTOMATION.md)
