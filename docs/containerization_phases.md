# Trading Platform PHP Containerization Phases

Date: 2026-03-28
Project root: P:\opt\docker\trading-platform-php
Authoritative app source: P:\opt\docker\trading-platform-php\src

## Current layout

Observed project structure:
- `src/` contains the PHP application and exchange implementations
- `src/web-ui/` is the browser-facing UI and HTTP API surface
- `src/tele-bot/` contains Telegram control bot scripts
- `shell/` contains local bot start and log helper scripts
- `tests/`, `utils/`, `misc/` contain support code and ad hoc tooling
- `docs/` contains migration notes and archived legacy material

Important current facts:
- there is no `docker-compose.yml` in this project root yet
- there is no `Dockerfile` in this project root yet
- `shell/start_bot.sh` expects the working directory to expose `trading_core.php`, `orders_lib.php`, `impl_<exchange>.php`, and a writable `logs/` directory
- `src/bot_instance.php` expects DB config from `lib/db_config.php` and host information from the container or host filesystem
- `src/web-ui/api/index.php` reads bot state from the database and local files under `/tmp/<bot>`
- the project has already been added to CQDS, so code archaeology and planning can use CQDS-native project chats from this point onward
- `mcp_server.py` currently lives in the native `mcp-sandbox` container and should be treated as optional infrastructure, not as a dependency of the first containerization pass
- runtime shared includes are expected from a separate repository `P:\GitHub\alpet-libs-php`, which deployment should pull via git

## CQDS and MCP stance

Current practical stance:
1. Use CQDS project chats, indexing, log search, and DB inspection as the main coordination layer for the migration.
2. Do not make `mcp_server.py` a required runtime component of `php-apache`, `php-bot`, or `mariadb` in the first implementation step.
3. Treat `mcp_server.py` primarily as one of these until proven otherwise:
   - a unit-test target
   - an integration-test target
   - an optional debugging helper inside a dedicated sandbox container

Deferred option:
- later, CQDS `mcp-tool` may gain the ability to switch the active MCP endpoint to externally reachable local IPs, which would allow testing MCP-capable helper services outside the default sandbox container
- only evaluate embedding or sidecar-ing MCP helpers into the new project containers after the base compose stack is stable

## Tooling policy for this repo

This project is already included in CQDS.

Practical rule:
1. Prefer CQDS `cq_*` functions for project work (`cq_get_index`, `cq_rebuild_index`, `cq_query_db`, `cq_grep_logs`, chat/project tools).
2. Use Sandwich-pack tools primarily for projects that are not connected to CQDS.
3. For this repo, Sandwich-pack is a fallback only when CQDS tool coverage is unavailable.

Reason:
- CQDS gives broader project-level observability in one place, including index extraction, DB probing, chat-scoped history, and log access.

## Target service split

The project should be containerized as separate concerns rather than a single all-in-one container.

Recommended initial services:
1. `php-apache`
   - serves `src/web-ui/`
   - exposes HTTP endpoints and static assets
   - runs with the same shared project code volume as the bot service
2. `php-bot`
   - runs `bot_instance.php` or wrapper scripts from `shell/`
   - one container per active bot instance or one generic service with overridable command
3. `mariadb`
   - provides the trading database used by bot and web UI
4. optional `signals-api`
   - only if external signal ingestion is kept inside the same compose project
   - otherwise leave it external and point the PHP app to its URL

## Phase 1: Normalize project layout

Goal: make container paths predictable without refactoring trading logic yet.

Actions:
1. Keep `src/` as the application root for now; do not reshuffle PHP files until containers run.
2. Define writable runtime directories outside `src`, for example:
   - `var/log/`
   - `var/tmp/`
   - `var/data/`
3. Decide whether `shell/start_bot.sh` should be updated to run from project root or from `src/` explicitly.
4. Inventory secrets and host-specific files still missing from the migrated tree, especially anything previously left in `P:\Trade\Tradebot`.

Exit criteria:
- one documented project root
- one documented application root
- writable directories mapped explicitly

## Phase 2: Add container scaffolding

Goal: create the minimal Docker assets without changing business logic.

Actions:
1. Add `docker-compose.yml` with `php-apache`, `php-bot`, and `mariadb`.
2. Add a shared PHP image `Dockerfile` with required PHP extensions and Composer dependencies.
3. Mount `./src` into the containers read-only where possible.
4. Mount `./var` read-write for logs, temp files, and generated runtime artifacts.
5. Add `.env.example` with DB host, DB name, DB user, DB password, signal API URL, and bot instance parameters.
6. Keep MCP concerns out of the initial compose file except for comments or extension points.

Exit criteria:
- `docker compose config` succeeds
- containers can start even if the bot is not fully functional yet

## Phase 3: Externalize config and state

Goal: remove dependence on host-local absolute paths and implicit writable folders.

Actions:
1. Move DB connection parameters from PHP include files toward environment-driven config.
2. Replace hardcoded `/tmp/<bot>` assumptions with a configurable runtime root.
3. Ensure bot logs and runtime state go into mounted directories.
4. Separate example config from live secrets.

Exit criteria:
- a fresh machine can boot the stack from documented env files and mounted volumes

## Phase 4: Bring up the web tier

Goal: make the browser UI and API usable inside compose.

Actions:
1. Configure Apache document root to `src/web-ui`.
2. Validate the API entrypoints under `src/web-ui/api/` against container paths.
3. Check file includes that currently rely on `chdir('../');` or relative include paths.
4. Add a health endpoint or a simple readiness check.

Exit criteria:
- web UI opens in browser
- at least one API endpoint returns expected data from the DB

## Phase 5: Bring up bot instances

Goal: run at least one trading bot container in a controlled way.

Actions:
1. Create a parametrized bot command such as `php src/bot_instance.php bitmex_bot`.
2. Replace shell assumptions that depend on a mutable host directory.
3. Add restart policy and log routing.
4. Validate one exchange implementation end-to-end against MariaDB and external APIs.

Exit criteria:
- one bot instance starts, logs, and updates activity tables in DB

## Phase 6: Optional MCP-enabled helper path

Goal: add MCP-aware diagnostics only after the application stack is stable.

Actions:
1. Reassess the real role of `mcp_server.py` using unit and integration tests.
2. Decide whether it belongs in:
   - the existing `mcp-sandbox` only
   - a dedicated helper container in the trading platform compose project
   - no runtime container at all
3. If needed, extend CQDS `mcp-tool` to switch active server targets to local network endpoints.
4. Add the feature behind an explicit config switch so normal app startup does not depend on MCP availability.

Exit criteria:
- MCP usage is optional and isolated
- main app services remain bootable without MCP helper availability

## First implementation step

The safest first implementation step is:

1. add container scaffolding only
2. keep the current PHP code layout intact
3. introduce `var/` for writable state
4. make `shell/start_bot.sh` and `bot_instance.php` work from a deterministic container working directory

This avoids mixing infrastructure work with risky PHP refactoring in the same step.

Progress update (2026-03-28):
- minimal compose and Docker scaffold has been added
- bootstrap path now includes a deploy helper to pull missing libs from `alpet-libs-php`
- DB config sample and generated config are aligned to Docker-native MariaDB hostname `mariadb`
- canonical schema dump `trading-structure.sql` is now available in project root and should be used as primary source for bootstrap-table extraction