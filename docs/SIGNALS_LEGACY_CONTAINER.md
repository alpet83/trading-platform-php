# Legacy signals-server containerization

This guide runs the PHP legacy API (`signals-server`) as a dedicated container and optionally wires the current services to it.

## Critical architecture rule

- Legacy signals DB must be isolated from the main TradeBot DB at host level.
- Even when both sides use DB name `trading`, schemas are different and not safely interchangeable.
- Never point legacy `signals_db_config.php` to the main `mariadb` service used by bots/web.
- Mixing both schemas in one DB engine/host can cause destructive, hard-to-predict side effects.
- If temporary co-location is unavoidable for lab experiments, first compare table prototypes and keep strict namespace isolation.

## 1) Prepare legacy secrets

Create runtime config file from template:

```bash
cp secrets/signals_db_config.php.example secrets/signals_db_config.php
```

Set real values in `secrets/signals_db_config.php`:
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `FRONTEND_TOKEN`
- `TELEGRAM_API_KEY`
- legacy DB host list (`$db_servers`) must target dedicated legacy DB host/service.

For this compose overlay, set legacy DB host to `signals-legacy-db`.

`FRONTEND_TOKEN` must match `AUTH_TOKEN` used by `signals-server.ts` backend when proxying requests.

`TRADEBOT_PHP_HOST` and `BOT_SERVER_HOST` are not required for image build/startup.
Leave them unset unless a specific runtime integration requires overrides.
Subscriber discovery/targeting is expected to come from DB tables such as `bot_hosts` during operations.

If deprecated `BOT_SERVER_HOST` must be set for compatibility, use `bot` on shared `trd_default` network.

## 2) Start stack with legacy signals API

```bash
docker-compose -f docker-compose.yml -f docker-compose.signals-legacy.yml up -d
```

This overlay now brings two dedicated legacy components:
- `signals-legacy` (legacy PHP API runtime)
- `signals-legacy-db` (dedicated MariaDB initialized from `signals-server/db_proto.sql`)

Published endpoint:
- `http://127.0.0.1:${SIGNALS_LEGACY_PORT:-8090}`

Internal service URL (inside compose network):
- `http://signals-legacy/`

Both `docker-compose.yml` and `docker-compose.signals-legacy.yml` use shared default network `trd_default`.
This allows same-host deployments to use service DNS defaults without extra per-service wiring.

## 3) Optional bridge with signals-server.ts

If `signals-server.ts` runs in parallel, keep auth aligned:
- `signals-server.ts` `AUTH_TOKEN` == legacy `FRONTEND_TOKEN`

Then point consumers to the legacy bridge URL through explicit environment overrides:
- `SIGNALS_API_URL`
- `BOT_SIGNALS_API_URL`
- `BOT_SIGNALS_FEED_URL`

Recommended same-host default:
- `SIGNALS_API_URL=http://signals-legacy/`

The legacy compose file does not auto-rewire `web`/`bot`/`bots-hive` anymore; set those env vars intentionally.

`TRADEBOT_PHP_HOST` meaning in legacy PHP code (optional override):
- This is a single control-plane endpoint used by helper flows (for example user-rights style calls).
- It is not related to subscriber count and should not enumerate worker hosts.
- Subscriber/workers can be deployed on many hosts independently; they consume this API via `SIGNALS_API_URL`/feed URL settings.

## 4) Quick checks

```bash
curl -sS http://127.0.0.1:${SIGNALS_LEGACY_PORT:-8090}/docs.html | head
curl -sS http://127.0.0.1:${SIGNALS_LEGACY_PORT:-8090}/get_signals.php | head
```

`/index.php` redirects to `/sig_edit.php` for simple browser entry.

## 5) Optional: run `trade_ctrl_bot.php` via docker exec

The overlay shares MySQL socket path `/run/mysqld/mysqld.sock` from `sigsys-db` to `sigsys`.
`trade_ctrl_bot.php` can use socket mode automatically (with host fallback).

Telegram token sources are supported in this order:
- `TELEGRAM_API_KEY` constant from `db_config.php`
- `TELEGRAM_API_KEY` env variable
- file from `TELEGRAM_API_TOKEN_FILE` (default `/etc/api-token`)

Run manually (on demand) from host:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.signals-legacy.yml exec -T signals-legacy \
	php /app/signals-server/trade_ctrl_bot.php
```

If `signals-server/telegram-bot/composer.json` exists and `vendor/autoload.php` is missing, container startup will run `composer install --no-dev --prefer-dist` automatically in that directory.

## Notes

- This container is bridge-oriented migration support, not a final architecture target.
- Target shape for isolated legacy hosting is two components on dedicated host(s):
	- legacy PHP web/runtime (Apache2 + PHP)
	- dedicated MariaDB with legacy schema
- In this repo overlay, runtime currently uses PHP built-in server for container simplicity,
  but DB isolation and schema bootstrap are implemented as mandatory defaults.
- Keep `signals-server.ts` as the long-term primary API/control plane.
