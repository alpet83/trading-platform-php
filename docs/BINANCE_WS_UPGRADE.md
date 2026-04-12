# Binance WebSocket Upgrade — Testing Context

## Status
Implementation complete. Blocked on API key / sub-account creation.

## What was built

### Phase 0 — `ws_api_common.php`
Abstract base class `WebsockAPIEngine extends RestAPIEngine` with:
- 16 state/health properties; `ws_connect_type` selects endpoint type (`public` / `private`)
- 9 abstract transport methods + 4 abstract protocol methods
- `drainWsBuffer()` — one-per-cycle health driver (ping, stall, sanity, frame read, deferred subscribe)
- `wsReconnect()` — 100 s cooldown, no sleep
- `wsReadFrame()` / `wsEnqueueMessage()` / `wsProcessQueue()`
- `wsLoadConfig(array $profile)` — reads `ws_*` keys from YAML + `bot__config` overrides
- `wsTickersFresh(int $max_age)` / `isWsReady()` — used in `trading_core.php::Update()`

### Phase 1 — `impl_binance.php`
`BinanceEngine extends WebsockAPIEngine`:

| Concern | Implementation |
|---|---|
| Transport lib | `arthurkushman/php-wss` (`WSSC\WebSocketClient`), added to `composer.json` |
| Socket wrapper | `BinanceWsClient` (exposes `getSocket()` for non-blocking peek) |
| WS stream type | **Private user-data stream** (`sapi/v1/userDataStream`) — listenKey auth |
| Auth fail-fast | `wsObtainListenKey()` in `Initialize()` — throws + sends ALERT on first denial |
| listenKey keepalive | `wsKeepAliveListenKey()` via `PUT sapi/v1/userDataStream` every 30 min |
| Dispatch | `executionReport` → `UpdateOrder()`, `outboundAccountPosition` (logged), `marginCall` (ALERT), `listenKeyExpired` (force reconnect) |
| Testnet switch | `BINANCE_WS_PROFILE=testnet` env var — flips both REST and WS endpoints |

### `trading_core.php` — integration hooks
- `require_once 'ws_api_common.php'` at top
- `drainWsBuffer()` call in `Run()` after `$this->mysqli_deffered->try_commit()`
- WS-first skip for `LoadTickers` / `LoadPositions` / `LoadOrders` in `Update()`

## Testing plan (when API key / sub-account is available)

### Prerequisites
- Binance sub-account with Cross Margin enabled
- API key with `READ + TRADE` permissions on Margin
- Key/secret in `.binance.api_key` / `.binance.key` (or env `BINANCE_API_KEY` + `BINANCE_API_SECRET`)

> **Security recommendation:** always use a dedicated **sub-account** with a small working balance
> (e.g. 20–100 USDC) rather than keys from the main account. This limits exposure if a key is
> compromised and prevents the bot from accidentally touching unrelated funds. Grant only the
> permissions actually needed (`READ + TRADE` on Margin; no withdraw, no transfer).

### Step 1 — listenKey probe
```bash
# Start bot with ws_enabled=0 in bot__config first
# Confirm "#WS_LISTEN_KEY: obtained" in log.
# If "#WS_AUTH_FAIL" appears — key or permissions are wrong.
```

### Step 2 — WS connect
```
# Set ws_enabled=1 in bot__config
# Confirm in log: #WS_RECONNECT reason: initial (attempt #1)
# Then: #WS_USER_DATA: stream active
```

### Step 3 — executionReport round-trip
Place a tiny test order via Binance UI, confirm `#WS_ORDER` log line appears within 1 s without a REST `LoadOrders` cycle.

### Step 4 — listenKey expiry simulation
```bash
# DELETE sapi/v1/userDataStream?listenKey=<key>  (forces server-side expiry)
# Bot should log #WS_KEY_EXPIRED and reconnect within 100 s cooldown.
```

### Known margin-API limitations
- Testnet (`testnet.binance.vision`) does **not** support margin SAPI — REST margin calls will fail; WS connect will work if listenKey endpoint is available on testnet.
- Paper Trading demo keys: no margin API either.
- For full integration test: real sub-account with ≥ 20 USDC is the minimum viable path.
- `testnet` exchange profile is hidden in the admin UI (marked `spot_only: true` in `binance.yml`)
  because the current engine architecture does not support spot-only trading.

## Files changed
- `src/ws_api_common.php` — new file (Phase 0)
- `src/impl_binance.php` — extended to `WebsockAPIEngine`
- `src/trading_core.php` — integration hooks
- `src/composer.json` — added `arthurkushman/php-wss: ^1.3`
- `src/config/exchanges/binance.yml` — `ws_public` / `ws_private` / `ws_endpoint_strategy` fields (already present)
