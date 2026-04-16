# Engine Reimplementation Protocol

## Purpose

This document defines a mandatory protocol for adding or reimplementing an exchange engine in trading-platform-php.

Goals:

1. pick the closest existing engine first (do not start from scratch blindly);
2. verify market data readiness (at minimum candles provider) before trading rollout;
3. implement margin/derivatives behavior first by default;
4. reduce production regressions through hard phase gates.

---

## Scope

Applies to new or reworked exchange adapters in:

1. src/impl_*.php
2. datafeed loaders and schedules (candles/ticks)
3. runtime/bootstrap config needed for credentials and environment

Primary references in current codebase:

1. src/impl_bitmex.php
2. src/impl_bybit.php
3. src/impl_binance.php
4. src/trading_engine.php
5. docs/DATAFEED_DEPS.md
6. docker/mariadb-init/40-bootstrap-datafeed-loader-manager.sql

---

## Phase 0: Closest API Pattern Assessment (Required)

Do not code until this phase is completed.

### 0.1 Candidate baseline engines

Use these adapters as comparison anchors:

1. BitMEX: derivatives-first, position-centric, order state and amend/cancel lifecycle.
2. ByBit: V5 derivatives-first flow, category-based endpoints, modern signed headers.
3. Binance: spot/margin-like semantics plus symbol filters and quantity/price normalization.
4. Bitfinex/Deribit: specialized behavior for own instruments and API nuances.

### 0.2 Similarity scorecard

Score each candidate from 0 to 2 for every dimension:

1. Auth/signature model.
2. Endpoint style and pagination.
3. Instrument model (symbol format, contract spec, settle coin).
4. Position model (net/hedge, side handling, leverage context).
5. Order model (types, timeInForce, reduceOnly/postOnly, amend/cancel semantics).
6. Error model (error codes and recoverable paths).
7. Testnet/sandbox support.

Total score in range [0..14].

Rule:

1. choose the highest score as baseline;
2. if score difference between top-2 is <= 1, use hybrid baseline (copy structure from top-1, fallback logic from top-2);
3. record rationale in PR or task notes.

### 0.3 Mandatory output of Phase 0

1. Selected baseline engine.
2. Endpoint map draft (public/private, order, position, ticker, time).
3. Known incompatibilities list.

No Phase 1 coding without this output.

---

## Phase 1: Minimum Engine Contract (Compile + Runtime)

Implement the minimum operational contract first.

Required methods and behavior:

1. constructor with credentials/env loading, base URLs, safe defaults;
2. Initialize();
3. PlatformStatus() with exchange time sync and local time bias update;
4. LoadTickers();
5. LoadPositions();
6. LoadOrders();
7. NewOrder(...);
8. CancelOrder(...);
9. error mapping to SetLastError/SetLastErrorEx style.

Requirements:

1. no fallback secrets;
2. no auth bypass via environment shortcuts;
3. explicit error-code handling for not-found/canceled/already-closed cases;
4. do not break existing engine public behavior in src/trading_engine.php integration.

---

## Phase 2: Margin-First Implementation (Default)

All new engines are implemented as margin/derivatives-first unless explicitly approved otherwise.

Mandatory margin-first rules:

1. default category/market type must target derivatives (for example linear perpetual);
2. position APIs are first-class, not optional;
3. order placement must support derivatives flags where available:
   1. reduceOnly
   2. postOnly
   3. timeInForce variants
4. quantity normalization must respect contract/lot/tick constraints;
5. side and sign conventions must be consistent with existing engine math.

If exchange supports both spot and derivatives:

1. implement derivatives path first;
2. treat spot as later phase unless it blocks current business requirement.

---

## Phase 3: Datafeed Readiness Gate (Blocker)

Trading enablement is blocked until datafeed prerequisites are satisfied.

Minimum required provider:

1. candles loader for the new exchange must exist and be schedulable.

Validation checklist:

1. loader script exists in datafeed source (for example *_candles_dl.php);
2. loader is represented in datafeed.loader_control;
3. loader can run end-to-end and update heartbeat/activity metadata;
4. startup schedule is present and manageable from loader_control;
5. DB grants/databases for exchange data are available in runtime.

Related architecture and control tables are described in docs/DATAFEED_DEPS.md.

Hard rule:

1. no production trading rollout if candles data path is absent or unmanaged.

---

## Phase 4: Robustness and Recovery

Implement resilience before broad rollout.

Required resilience patterns:

1. recoverable API errors should not crash session;
2. map exchange-specific order-not-found to safe local state transitions;
3. add fallback reads for recent order state (active endpoint -> history endpoint);
4. avoid repetitive invalid requests (cache known-bad parameter combos when needed);
5. cap retries and log root cause with exchange code/message.

---

## Phase 5: Runtime Integration

Required integration touchpoints:

1. credentials bootstrap path (files/env/DB flow) in runtime entrypoint scripts when needed;
2. docker-compose environment variables for endpoint/category/testnet/runtime options;
3. .env.example documentation for every new runtime switch;
4. admin/bot creation path validation (exchange appears in UI/API flow if required);
5. startup logs must print selected API base and critical mode flags.

DB compatibility note (required for Bybit reintegration):

1. trading.pairs_map must contain column bybit_pair varchar(32) NULL;
2. baseline mappings must be present: BTCUSD -> BTCUSDT and ETHUSD -> ETHUSDT;
3. keep signals-server/db_proto.sql in sync with live schema before rollout.

---

## Phase 6: Verification Matrix

Run and record at least these checks:

1. static syntax check for changed PHP files;
2. dry-run connectivity test (platform time/status + auth check);
3. ticker load for configured symbols;
4. position load consistency with native exchange state;
5. create/cancel order happy path;
6. not-found/rejected/insufficient-precision negative path;
7. testnet flow if supported by exchange;
8. datafeed candles loader health check.

Promotion criteria:

1. all above checks pass;
2. no unresolved high-severity error in logs;
3. rollback path documented.

---

## Phase 7: Rollout Strategy

Recommended rollout:

1. testnet/sandbox only;
2. limited account and limited pair set;
3. reduced position coefficients;
4. gradual pair expansion;
5. full rollout after stable session window.

Rollback triggers:

1. repeated order-state mismatches;
2. precision/filter rejection loops;
3. position divergence against exchange state;
4. candles/datafeed outage for target exchange.

---

## Quick Checklist (Copy/Paste)

1. Phase 0 completed, closest engine selected and justified.
2. Minimum engine contract implemented.
3. Margin-first rules implemented and verified.
4. Candles provider exists and passes readiness gate.
5. Runtime env/bootstrap integrated.
6. Verification matrix passed.
7. Controlled rollout plan prepared.
8. Rollback triggers and actions documented.

---

## Notes for Future Engines

If you are uncertain where to start:

1. start from the nearest derivatives engine (usually BitMEX or ByBit pattern);
2. copy signing/request envelope first;
3. implement read-only paths before order placement;
4. enable trading only after datafeed candles gate passes.
