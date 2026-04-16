# Container Bootstrap

Date: 2026-03-28

## What was added

- `docker-compose.yml` with services:
  - `web` (Apache + PHP)
  - `bot` (single bot instance runner)
  - `mariadb`
  - `bot-manager` (optional, profile `pass`)
- `docker/php.Dockerfile` with `mysqli`, `pass`, and `gnupg2` installed.
- Entry points:
  - `docker/entrypoints/run-bot.sh`
  - `docker/entrypoints/run-bot-manager.sh`
- Secret mount contract:
  - host file `secrets/db_config.php` -> container `/app/src/lib/db_config.php`
- MariaDB init SQL mount:
   - host dir `docker/mariadb-init/` -> container `/docker-entrypoint-initdb.d`
- root schema dump:
   - `trading-structure.sql` (full structure snapshot for the `trading` database)

## External dependency

The runtime depends on shared PHP utility files from a separate repository:
- `alpet-libs-php`

Deployment bootstrap can clone this repository via plain `git clone` and copy required files into `src`.

## Important note about current source state

The current `src` tree is incomplete for runtime startup.
At minimum, these files are expected by bot launch scripts but are currently missing from source:
- `src/common.php`
- `src/esctext.php`
- `src/lib/db_tools.php`

Because of that, bot containers will fail preflight until these files are restored or code paths are refactored.

## First start sequence

1. Copy env template:
   - `.env.example` -> `.env`
2. Run deployment bootstrap:
   - `sh shell/bootstrap_container_env.sh`
3. Inspect generated artifacts:
   - `secrets/db_config.php`
   - `docker/mariadb-init/10-create-trading-role.sql`
   - `docker/mariadb-init/20-bootstrap-core.sql`
4. Build and inspect compose config:
   - `docker compose config`
5. Start base stack:
   - `docker compose up -d mariadb web`
6. Start one bot instance (after restoring any remaining missing include files):
   - `docker compose up -d bot`

Bootstrap script responsibilities:
- clones `ALPET_LIBS_REPO` into a temp directory and copies missing runtime files
- generates random password for DB role `trading`
- writes `secrets/db_config.php` with Docker hostname `mariadb`
- writes `docker/mariadb-init/10-create-trading-role.sql`
- generates `docker/mariadb-init/20-bootstrap-core.sql` from `trading-structure.sql` for baseline tables

## Bootstrap tables from trading-structure.sql

Use `trading-structure.sql` as the authoritative schema source when preparing a minimal bootstrap set.

Recommended extraction workflow:
1. Extract all table definitions:
   - `Select-String -Path trading-structure.sql -Pattern 'CREATE TABLE'`
2. Keep mandatory control/config tables first:
   - `bot__activity`
   - `bot__orders_ids`
   - `bot__redudancy`
   - `config__hosts`
   - `config__table_map`
3. For each bot/exchange, provision generated per-prefix tables based on API create flow or selected template suffixes from dump (for example `__positions`, `__tickers`, `__pending_orders`, `__matched_orders`, `__last_errors`, `__events`).

Semi-automatic helper:
- `sh shell/generate_bootstrap_schema.sh`
   - input: `trading-structure.sql`
   - table list: `shell/bootstrap-core-tables.txt`
   - output: `docker/mariadb-init/20-bootstrap-core.sql`

Windows host fallback:
- `powershell -ExecutionPolicy Bypass -File .\shell\generate_bootstrap_schema.ps1`

Notes:
- `signals-server/` is now part of this repository layout, while old `/vps.alpet.me/sigsys*` paths are no longer the valid source for this project structure.
- Keep bootstrap minimal for instance API, then expand table set as runtime paths require.

Important:
- MariaDB init scripts run only on first initialization of the data volume.
- If DB volume already exists, recreate it before expecting init SQL to apply:
  - `docker compose down -v`

## Optional bot-manager with pass

`bot-manager` is intentionally optional and disabled by default.
To run it, you need mounted pass and GPG material on host:
- `PASS_STORE_DIR` -> mounted as `/root/.password-store`
- `GPG_HOME_DIR` -> mounted as `/root/.gnupg`

Then run:
- `docker compose --profile pass up -d bot-manager`

This keeps pass-based secret decryption in a dedicated path without making baseline web/db startup depend on it.