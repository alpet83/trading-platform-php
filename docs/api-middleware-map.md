# Trading Platform PHP: API and Middleware Map

Updated: 2026-03-27

## URL Layers

This codebase currently spans several transport layers. They should be named by logical URL path / service role rather than by host aliases.

### Layer 1: `SIGNALS_API_URL`

Purpose:
- legacy PHP signals/control server
- source for `pairs_map.php`, `get_signals.php`, `lastpos.php`, `trade_event.php`

Examples of paths on this layer:
- `/pairs_map.php`
- `/get_signals.php`
- `/lastpos.php`
- `/trade_event.php`

Configuration rule:
- use `SIGNALS_API_URL` from env / central PHP host config
- do not hardcode concrete hostnames in application code

### Layer 2: `PUBLIC_FRONTEND`

Definition:
- `DOMAIN + APP_BASE_PATH`

Purpose:
- public browser-facing entry for the TS/Nuxt + Nest application

Important rule:
- legacy PHP scripts should not call this layer directly
- this layer is for browser entry and reverse-proxied TS backend exposure

### Layer 3: `INSTANCE_API_URL`

Purpose:
- per-bot instance/stats API

Current source of truth:
- `bot_hosts.instance_url`
- not a single global env at runtime today

Refactor rule:
- treat this as a separate service layer from `SIGNALS_API_URL`
- avoid mixing instance URLs with signal-server URLs

### Layer 4: DB Layer

Purpose:
- direct MySQL access to `trading`, `datafeed`, and related tables

This is not an HTTP layer, but it is a core dependency layer for most legacy endpoints.

## Scope

This document maps API/middleware flows represented inside this repository for:
- `src/web-ui/api/**`
- `signals-server/*.php`
- shared auth layers in `src/web-ui/api_helper.php` and `signals-server/api_helper.php`

## Global Request Flow (web-ui API)

1. Endpoint entrypoint executes `chdir(...)` and `require_once('api_helper.php')`.
2. `api_helper.php` runs `check_auth()` before endpoint logic:
   - validates `Authorization: Bearer <FRONTEND_TOKEN>`
   - rejects unauthorized requests with HTTP 401
3. Endpoint reads user rights via `get_user_rights()` using `x-user-id` header.
4. Endpoint enforces role gate (`view`, `trade`, or `admin`) and returns 403 on mismatch.
5. Endpoint opens `trading` DB connection with `init_remote_db('trading')`.
6. Endpoint executes domain logic and returns JSON via `send_response()`.

## Middleware/Auth Components

### `src/web-ui/api_helper.php`

Responsibilities:
- global JSON headers
- bearer token auth (`check_auth()`)
- user rights lookup (`get_user_rights()`)
- response/error shaping (`send_response()`, `send_error()`)
- exception logging

Security behavior:
- hard fail on missing/invalid bearer token
- rights default to `none` when `x-user-id` is absent
- role checks are endpoint-specific

## Endpoint Map (`src/web-ui/api`)

### View endpoints

- `GET /api/index.php`
  - rights: `view`
  - output: bots status + `risk_mapping` + volume aggregates
  - dependencies: `config__table_map`, `bot__activity`, `bot__redudancy`, `{exch}__positions`, `{exch}__*`

- `GET /api/chart/index.php`
  - rights: `view`
  - output: chart data

- `GET /api/dashboard/index.php`
  - rights: `view`
  - params: `bot`, `account`, optional `exchange`

- `GET /api/last-errors/index.php`
  - rights: `view`
  - params: `bot`, optional `account`, `ts`

### Trade endpoints

- `POST /api/cancel-order/index.php`
  - rights: `trade`
  - body/form: `bot`, `order_id`

- `POST /api/update-offset/index.php`
  - rights: `trade`
  - body/form: `exchange`, `account`, `pair_id`, `offset`, `bot`

- `POST /api/update-position-coef/index.php`
  - rights: `trade`
  - body/form: `bot`, `position_coef`

- `POST /api/update-trade-enabled/index.php`
  - rights: `trade`
  - body/form: `bot`, `enabled`

### Admin endpoints

- `GET /api/bots/index.php`
  - rights: `admin`
  - output: bots and config map

- `POST /api/bots/create/index.php`
  - rights: `admin`
  - body/form: `bot_name`, `account_id`, `config[]`
  - side effects: creates config and bot tables

- `POST /api/bots/update/index.php`
  - rights: `admin`
  - body/form: `applicant`, `config[]`

- `POST /api/bots/delete/index.php`
  - rights: `admin`
  - body/form: `applicant`
  - side effects: drops bot tables and removes map entries

## Symbol Data Dependency: Current State

### What was inconsistent

- `src/web-ui/index.php` and `src/web-ui/index-last.php` already used the `SIGNALS_API_URL` layer semantically via `/pairs_map.php?full_dump=1`
- but `src/web-ui/api/index.php` previously used local static file `cm_symbols.json`

This created a split data path for symbol metadata.

### What was changed

`src/web-ui/api/index.php` now loads pair metadata from the `SIGNALS_API_URL` layer using `/pairs_map.php?full_dump=1`.

Fallback behavior remains in place: if remote metadata is temporarily unavailable, symbol names are derived from already loaded runtime pair config objects (`$pairs_configs`).

### Notes

- `load_sym.inc.php` remains used in legacy `signals-server` scripts (`grid_edit.php`, `sig_edit.php`) and belongs to the `SIGNALS_API_URL` layer.
- remaining legacy PHP call sites that still implicitly target the signal server should be migrated to a shared constant/helper layer instead of raw hostnames.

## Signals Server Layer (`signals-server/*.php`)

Repository paths for the PHP signals layer include:
- `signals-server/get_signals.php`
- `signals-server/lastpos.php`
- `signals-server/pairs_map.php`
- `signals-server/sig_edit.php`
- `signals-server/grid_edit.php`
- `signals-server/trade_event.php`
- `signals-server/get_user_rights.php`

Common pattern:
- direct include chain via `signals-server/api_helper.php` or `/usr/local/etc/php/db_config.php`
- DB-backed script routing rather than file-based `/api/*` subtree
- this layer belongs to `SIGNALS_API_URL`

## Gaps / Technical Debt

1. Mixed transport style (`$_POST` forms vs query params) without explicit schema docs.
2. No centralized route registry; endpoint discovery is file-system based.
3. Middleware contracts are implicit (include order + helper side effects).
4. Symbol source was historically inconsistent across pages vs API.
5. URL layers were implicit and encoded as host aliases rather than config-backed constants.
6. `INSTANCE_API_URL` is still represented structurally by DB field naming rather than a stable transport abstraction.

## Recommended Next Steps

1. Add one machine-readable API contract file (`openapi-lite` or custom YAML).
2. Normalize all endpoints to explicit HTTP method + JSON body contract.
3. Move role checks to reusable helper wrappers (`require_view()`, `require_trade()`, `require_admin()`).
4. Define single symbol source contract:
  - canonical endpoint path: `/pairs_map.php?full_dump=1` on `SIGNALS_API_URL`
  - deprecate: `cm_symbols.json` and unmaintained CoinMarketCap path.
5. Introduce shared URL helpers in legacy PHP config layer (`src/lib/hosts_cfg.php`) and migrate raw hostnames to layer constants.
6. Treat `bot_hosts.instance_url` as the canonical DB representation of the instance transport layer and keep manual schema migration outside runtime code.
