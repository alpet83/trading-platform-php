# cm_symbols Dependency Context (2026-04-01)

## Why this note
This document captures where `cm_symbols` is still used, what data it provides, and practical options to replace CoinMarketCap-dependent flows with public APIs plus cache.

## Current runtime providers and schema

### Main provider in datafeed container
- Runtime file: `/app/datafeed/src/cm_symbols.php`
- Mirror runtime file: `/app/datafeed/server/cm_symbols.php`
- Behavior:
  - Reads `datafeed.cm__symbols` and enriches with latest fields from `datafeed.cm__listings`.
  - Returns JSON rows with fields such as:
    - `id`
    - `symbol`
    - `name`
    - `rank`
    - `last_price`
    - `ts_updated`
    - plus listing metrics (`circulating_supply`, `volume24_musd`, etc.).
  - Enforces freshness check for id=1 (BTC) and may fail if stale.

### Legacy consumers in signals-server
- `signals-server/load_sym.inc.php`
  - Produces `cm_symbols` map keyed by internal `pair_id`.
  - Fields actually read by consumers:
    - `symbol`
    - `name`
    - `last_price`
    - `ts_updated`
- `signals-server/sig_edit.php`
  - Uses `cm_symbols` for display and TP/SL helper logic.
  - Falls back to VWAP for some pricing paths.
- `signals-server/grid_edit.php`
  - Uses `cm_symbols` similarly; currently has separate runtime issue (`detect_output_format()` undefined).
- `signals-server/level_track.php`
  - Uses `cm_symbols[*].last_price` for level trigger checks and logs.

### Additional consumer in trading core
- `src/trading_engine.php` (`LoadCMCPrices()`):
  - Requests `http://cmc-source.vpn/bot/cm_symbols.php?max_rank=400`.
  - Writes `TickerInfo->cmc_price` when record is fresh.
  - This path appears auxiliary/diagnostic; execution pricing continues to rely on exchange tickers (`last_price`, `fair_price`, etc.).

## What is mandatory vs optional

### Mandatory for trade execution
- Exchange ticker prices in bot tickers tables (`*_tickers`) and runtime `TickerInfo.last_price`.
- Signal import, position updates, and order logic do not require CMC metadata to place orders.

### Optional/auxiliary
- `cmc_price` in `TickerInfo`.
- UI enrichment in legacy editors (`sig_edit`, `grid_edit`, `level_track`) when live exchange price can be used instead.
- Coin ranking and market-cap fields used by portfolio/tracker views are informational.

## Practical replacement options (public APIs)

### Option A: CoinGecko (recommended first)
- Public endpoint examples:
  - `/api/v3/simple/price`
  - `/api/v3/coins/markets`
- Pros:
  - No paid key required for basic use.
  - Includes market data and rank-like fields.
- Cons:
  - Rate limits; requires aggressive cache.
- Cache strategy:
  - Store normalized response in DB/JSON cache with TTL 30-120s for UI and 10-30s for alerts.

### Option B: Binance public ticker as fallback for price only
- Endpoints:
  - `/api/v3/ticker/price`
  - `/api/v3/ticker/24hr`
- Pros:
  - Very available and fast.
  - No API key for basic market data.
- Cons:
  - No global market cap/rank semantics.
  - Pair coverage differs from non-Binance markets.
- Best use:
  - Price-only fallback when metadata is not required.

### Option C: Combine existing internal datafeed + public fallback
- Keep current DB tables as cache source (`cm__symbols`, optional renamed abstraction table).
- Replace upstream loader source from CMC to CoinGecko/Binance or mixed adapters.
- Consumers stay unchanged initially.

## Suggested migration phases

### Phase 0: Stabilize interfaces (no product behavior change)
- Keep consumer schema (`symbol`, `name`, `last_price`, `ts_updated`) stable.
- Introduce adapter abstraction in datafeed loader:
  - provider=`coingecko`|`binance`|`legacy_cmc`.

### Phase 1: Remove hard dependency from signals-server runtime path
- Keep `signals-server/load_sym.inc.php` opt-in by env (`SIGNALS_CM_SYMBOLS_URL` / `CM_SYMBOLS_URL`).
- If URL missing/unavailable, do not block page load; rely on VWAP/exchange values and show non-fatal warning.

### Phase 2: Replace `LoadCMCPrices()` source in trading engine
- Switch from `cmc-source.vpn` to internal normalized endpoint fed by public provider cache.
- Keep writing to `cmc_price` for compatibility, but make it optional in strategy code paths.

### Phase 3: Deprecate CMC-specific naming
- Rename internal concepts where feasible:
  - `cm_symbols` -> `market_symbols_cache` (or similar).
  - `cmc_price` -> `ref_price`/`market_ref_price`.
- Keep backward compatibility aliases during transition.

### Phase 4: Cleanup and policy
- Add freshness metrics and fallback counters.
- Alert only on prolonged stale cache, not single refresh misses.
- Document rate-limit behavior and retry policy.

## Risks to watch
- Symbol mapping mismatches (e.g., ticker aliases, USD/USDT suffix handling).
- Freshness gaps causing stale advisory prices.
- Hidden assumptions in UI templates expecting rank/order from CMC-like datasets.

## Quick inventory of code touchpoints
- `signals-server/load_sym.inc.php`
- `signals-server/sig_edit.php`
- `signals-server/grid_edit.php`
- `signals-server/level_track.php`
- `src/trading_engine.php` (`LoadCMCPrices()`)
- `src/ticker_info.php` (`cmc_price` field)
- Datafeed provider scripts in container:
  - `/app/datafeed/src/cm_symbols.php`
  - `/app/datafeed/server/cm_symbols.php`
