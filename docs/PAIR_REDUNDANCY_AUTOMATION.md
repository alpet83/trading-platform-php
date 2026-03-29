# Pair Redundancy Automation (Master/Rescuer)

## Goal

Document current redundancy behavior in `trading-platform-php` and define a low-risk automation plan that keeps existing dominance logic intact:

- "who captured `bot__redudancy` first becomes `master`"
- the second instance runs as `rescuer`
- replication is used for state convergence and failover

## Existing Role Logic (Already in Project)

### Source of truth for role election

- Table: `bot__redudancy`
- Primary key: `(exchange, account_id)`
- Main fields:
  - `master_host`, `master_pid`
  - `ts_alive`
  - `reserve_status`
  - `status`

Schema references:
- `docker/mariadb-init/20-bootstrap-core.sql`
- `trading-structure.sql`

### Runtime role transitions

Core path in `src/trading_core.php`:

1. `CheckRedudancy()` reads current `bot__redudancy` row by `(exchange, account_id)`
2. If row does not exist: `SetActiveRole(true, 'registered')`
3. If row exists and points to another host/pid:
   - local instance becomes `rescuer`
   - if master looks stale (`ts_alive` old), instance attempts master capture (`SetActiveRole(...)`)
4. If local instance is master: refreshes `ts_alive`, `status`, `uptime`

Additional stabilizers:
- `SetActiveRole()` checks `config__hosts.priority` and `reserve_status` before overriding master in contested situations
- On role change to master: `OnSetMasterRole()` reloads hot order lists from DB
- Trading loop enforces single active trader path: `TradingAllowed()` requires `active_role == 'master'`

### Replication health usage

`CheckReplication()` compares `bot__redudancy` row between local DB and `mysql_remote` connection. Divergence means replication not healthy.

This already supports your current behavior:
- if only replication breaks but remote DB is reachable, pair logic can still evaluate liveness and converge later.

## Gaps Found

1. `bot_manager.php` had legacy recovery code with hardcoded replication credentials and host heuristics.
2. DB-side replication orchestration was not packaged as a dedicated compose profile.
3. Automatic rejoin/reseed path was not standardized in one operational playbook.

## Added in This Iteration

### 1) Startup DB backup on container start

- `docker/entrypoints/mariadb-entrypoint-wrapper.sh`
- wired in `docker-compose.yml` for `mariadb`
- creates compressed dump in `./var/backup/mysql`
- rotates old backups by retention days

### 2) Replication automation scripts

- `scripts/replication/configure-replica.sh`
- `scripts/replication/auto-rejoin.sh`
- `scripts/replication/reseed-from-primary.sh`
- `scripts/replication/ensure-single-writer.sh`
- `scripts/replication/watchdog-loop.sh`

### 3) Replication compose profiles

- new file: `docker-compose.replication.yml`
- profile `ha-primary`: starts `mariadb-watchdog-primary`
- profile `ha-standby`: starts `mariadb-watchdog-standby`

Watchdog responsibilities:
- enforce DB write role (`read_only`) according to profile
- on standby: periodically re-check and auto-rejoin replication channel

### 4) Legacy recovery hardcode removal

`src/bot_manager.php` recovery path now uses environment credentials instead of hardcoded values:

- `MARIADB_REMOTE_USER`
- `MARIADB_REMOTE_PASSWORD`
- `MARIADB_REMOTE_DB` (default `trading`)

This keeps existing role arbitration logic untouched while removing static secrets from code.

## Deployment Model (Recommended)

Keep your app-level dominance (`bot__redudancy`) as-is and combine it with DB-level single-writer policy:

- Host A: `ha-primary`
- Host B: `ha-standby`
- bot instances on both hosts still run role election based on `bot__redudancy`
- only DB on active side accepts writes

This avoids split-brain at storage layer while preserving your existing bot-level master/rescuer semantics.

## Operational Commands

### Host A (active DB side)

```bash
docker compose -f docker-compose.yml -f docker-compose.replication.yml --profile ha-primary up -d mariadb mariadb-watchdog-primary
```

### Host B (standby DB side)

```bash
docker compose -f docker-compose.yml -f docker-compose.replication.yml --profile ha-standby up -d mariadb mariadb-watchdog-standby
```

### Bootstrap standby replication

Run on host B after both DBs are online:

```bash
PRIMARY_HOST=10.0.0.1 \
MYSQL_ROOT_PASSWORD=... \
REPL_PASSWORD=... \
./scripts/replication/configure-replica.sh
```

### Forced reseed after long outage

Run on host B:

```bash
PRIMARY_HOST=10.0.0.1 \
PRIMARY_PASSWORD=... \
LOCAL_ROOT_PASSWORD=... \
REPL_PASSWORD=... \
./scripts/replication/reseed-from-primary.sh
```

## What To Add Next (Without Breaking Current Logic)

1. Replace hardcoded credentials in `bot_manager.php` recovery path with env-driven config.
2. Add a tiny SQL migration for replication metadata table (`bot__replication_state`) to store last successful rejoin/reseed timestamps.
3. Add alerting hooks:
   - lag > threshold
   - repeated rejoin failures
   - standby drift requiring reseed
4. Add runbook `docs/PAIR_FAILOVER_RUNBOOK.md` for on-call switchover steps.

## Why This Fits Your Existing Architecture

- Preserves current dominance principle in app layer (`bot__redudancy`)
- Adds deterministic DB guardrails (single-writer + auto-rejoin)
- Prevents uncontrolled growth of binlog/relaylog via retention + limits
- Keeps recovery path practical when one host was fully offline
