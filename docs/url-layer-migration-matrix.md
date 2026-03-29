# URL Layer Migration Matrix

Updated: 2026-03-27

## Scope

This matrix tracks legacy transport call sites inside `trading-platform-php` and maps them to logical URL layers.

## Layers

- `SIGNALS_API_URL`: legacy PHP signals/control server
- `INSTANCE_API_URL`: per-instance stats/control endpoint, currently represented by `bot_hosts.instance_url`
- `PUBLIC_FRONTEND`: `DOMAIN + APP_BASE_PATH`, browser-facing TS application layer
- `DB`: direct MySQL access, not an HTTP transport layer

## Migrated To `SIGNALS_API_URL`

| Path | Old endpoint role | Current access pattern | Layer | Notes |
|---|---|---|---|---|
| `src/bot_manager.php` | `trade_event.php` | `signals_api_url("trade_event.php?..." )` | `SIGNALS_API_URL` | event delivery from trading engine |
| `src/pairs_map.php` | `pairs_map.php?field=...` | `signals_api_url('pairs_map.php?field=...')` | `SIGNALS_API_URL` | local cache refresher |
| `src/web-ui/index.php` | `pairs_map.php?full_dump=1` | `signals_api_url('pairs_map.php?full_dump=1')` | `SIGNALS_API_URL` | risk table metadata |
| `src/web-ui/index-last.php` | `pairs_map.php?full_dump=1` | `signals_api_url('pairs_map.php?full_dump=1')` | `SIGNALS_API_URL` | legacy page variant |
| `src/web-ui/api/index.php` | `pairs_map.php?full_dump=1` | `signals_api_url('pairs_map.php?full_dump=1')` | `SIGNALS_API_URL` | API main table metadata |
| `src/web-ui/exec_report.php` | `pairs_map.php?field=...` | `signals_api_url('pairs_map.php?field='.$exch.'_pair')` | `SIGNALS_API_URL` | report symbol resolution |
| `src/web-ui/trading_stats.php` | `pairs_map.php` | `signals_api_url('pairs_map.php')` | `SIGNALS_API_URL` | cache refresh |
| `src/web-ui/session_sync.php` | host ACL for signal server | `signals_api_host()` | `SIGNALS_API_URL` | source-IP validation |
| `src/reporting.php` | connectivity check | `signals_api_host()` | `SIGNALS_API_URL` | ping in failure branch |
| `src/pos_feed.php` | `pairs_map.php`, `lastpos.php` | default `SIGNALS_API_URL`, overridable by config | `SIGNALS_API_URL` | runtime override remains via `position_feed_url` |
| `src/ext_signals.php` | `get_signals.php` | default `SIGNALS_API_URL`, overridable by feed server field | `SIGNALS_API_URL` | signal loader |

## Compatibility Layer Added

| Path | Compatibility behavior |
|---|---|
| `src/lib/hosts_cfg.php` | defines `SIGNALS_API_URL`, `signals_api_url()`, `signals_api_host()`, and legacy-compatible `$msg_servers = [SIGNALS_API_URL]` |

## Still Using Direct `SIGNALS_API_URL` Or Equivalent

| Path | Current form | Recommendation |
|---|---|---|
| `signals-server/trade_ctrl_bot.php` | `getenv('SIGNALS_API_URL') ?: 'http://localhost'` | acceptable for now; optionally include shared helper later |
| `signals-server.ts/frontend/composables/api.ts` | frontend uses configured signals API base | keep as TS-side explicit config |
| `signals-server.ts/backend/src/modules/signals/signals.service.ts` | `Env.SIGNALS_API_URL` | canonical TS path |

## Future `INSTANCE_API_URL` Migration Targets

| Path | Current source | Target layer |
|---|---|---|
| `signals-server.ts/backend/src/modules/instance/instance.service.ts` | `bot_hosts.instance_url` + env fallback | `INSTANCE_API_URL` |
| `signals-server.ts/backend/src/modules/instance/instance-hosts.repository.ts` | `bot_hosts.instance_url` | `INSTANCE_API_URL` |
| `signals-server.ts/frontend/components/admin/AdminBots.vue` | host editor form | `INSTANCE_API_URL` |
| `signals-server.ts/frontend/pages/instance/index.vue` | host selector display | `INSTANCE_API_URL` |

## Manual Migration Requirement

Schema rename is intentionally kept outside runtime code:

- `signals-server.ts/backend/docs/manual-bot-hosts-instance-url-migration.sql`

Required one-off DB change:

- `bot_hosts.stats_url -> bot_hosts.instance_url`

## Follow-Up Work

1. Replace remaining semantic uses of generic `feed_server` naming with `signalsApiUrl` or `instanceApiUrl` where the role is unambiguous.
2. Introduce a dedicated helper for `INSTANCE_API_URL` consumers once PHP code starts calling instance endpoints directly.
3. Sync runtime `sigsys-ts` rename changes into the publish copy before commit.
