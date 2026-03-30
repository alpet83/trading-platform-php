# Legacy signals-server containerization

This guide runs the PHP legacy API (`signals-server`) as a dedicated container and optionally wires the current services to it.

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

`FRONTEND_TOKEN` must match `AUTH_TOKEN` used by `signals-server.ts` backend when proxying requests.

## 2) Start stack with legacy signals API

```bash
docker-compose -f docker-compose.yml -f docker-compose.signals-legacy.yml up -d
```

Published endpoint:
- `http://127.0.0.1:${SIGNALS_LEGACY_PORT:-8090}`

Internal service URL (inside compose network):
- `http://signals-legacy:8081`

## 3) Optional bridge with signals-server.ts

If `signals-server.ts` runs in parallel, keep auth aligned:
- `signals-server.ts` `AUTH_TOKEN` == legacy `FRONTEND_TOKEN`

Then point consumers to the legacy bridge URL through environment:
- `SIGNALS_API_URL`
- `BOT_SIGNALS_API_URL`
- `BOT_SIGNALS_FEED_URL`

The legacy compose file already sets these values to `http://signals-legacy:8081` by default when included.

## 4) Quick checks

```bash
curl -sS http://127.0.0.1:${SIGNALS_LEGACY_PORT:-8090}/docs.html | head
curl -sS http://127.0.0.1:${SIGNALS_LEGACY_PORT:-8090}/get_signals.php | head
```

## Notes

- This container is bridge-oriented migration support, not a final architecture target.
- Keep `signals-server.ts` as the long-term primary API/control plane.
