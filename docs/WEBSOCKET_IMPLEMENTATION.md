# WebSocket Implementation Transformation Document

**Created:** 2026-04-04  
**Updated:** 2026-04-05 (Fast MM Reaction on WS Fills; Bot Reactivity Model)  
**Status:** Phase 0–1 Complete (Bybit WS live)  
**Version:** 1.3

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current Architecture Analysis](#2-current-architecture-analysis)
3. [Target Architecture](#3-target-architecture)
4. [WebsockAPIEngine Design](#4-websockapiengine-design)
5. [Trading Cycle Redesign](#5-trading-cycle-redesign)
6. [Iterative Migration Plan](#6-iterative-migration-plan)
7. [Implementation Details](#7-implementation-details)
8. [Risk Assessment](#8-risk-assessment)
9. [Synchronous vs Asynchronous Data Flow Analysis](#9-synchronous-vs-asynchronous-data-flow-analysis)
10. [Fast Market-Maker Reaction on WS Fills](#10-fast-market-maker-reaction-on-ws-fills)
11. [Bot Reactivity Model](#11-bot-reactivity-model)

---

## 1. Executive Summary

### 1.1 Objective
Introduce `WebsockAPIEngine` as an intermediate abstract class inheriting from `RestAPIEngine`, implementing WebSocket-based data retrieval for both public and private data with REST API fallback when WebSocket is unavailable.

### 1.2 Key Findings
- **No formal obstacles** for WebSocket implementation
- Existing WebSocket infrastructure in `datafeed/` project (`CEXWebSocketClient`, `BinanceClient`, `BybitClient`, `BitfinexClient`)
- Trading cycle currently relies entirely on REST polling (every 60 seconds)
- Real-time order updates would significantly improve latency and reliability

### 1.3 Benefits
- **Latency reduction:** Order status updates in milliseconds vs 60s polling
- **Reduced API load:** WebSocket subscription model vs REST polling
- **Better UX:** Real-time fill notifications and order state changes
- **Fault tolerance:** Dual-transport redundancy with automatic fallback

---

## 2. Current Architecture Analysis

### 2.1 Class Hierarchy

```
TradingEngine (abstract base)
    └── RestAPIEngine (abstract)
            ├── BinanceEngine
            ├── BitfinexEngine
            ├── BitMEXEngine
            ├── BybitEngine
            └── DeribitEngine
```

### 2.2 RestAPIEngine Structure (`rest_api_common.php`)

**Key Properties:**
| Property | Type | Purpose |
|----------|------|---------|
| `$apiKey` | string | Exchange API key |
| `$secretKey` | mixed | Encoded secret key |
| `$public_api` | string | REST endpoint base |
| `$private_api` | string | Authenticated endpoint |
| `$rate_limit`, `$rate_remain` | int | Rate limiting state |
| `$last_nonce`, `$prev_nonce` | int | Nonce management |

**Key Methods:**
| Method | Access | Purpose |
|--------|--------|---------|
| `MakeNonce()` | protected | Generate unique nonce |
| `RequestPublicAPI()` | protected | HTTP GET/POST requests |
| `ProcessRateLimit()` | protected | Handle rate limits |
| `CheckAPIKeyRights()` | abstract | Verify key permissions |
| `InitializeAPIKey()` | protected | Load credentials |

### 2.3 TradingCore Cycle (`trading_core.php`)

**Main Loop Flow:**
```
Run() [infinite loop with 1s sleep]
    └─> Update()
            ├─> LoadTickers() [REST polling every 60s]
            ├─> LoadOrders() [REST polling]
            ├─> LoadPositions() [REST polling]
            ├─> CheckRedudancy() [DB-based]
            └─> Trade() [trait TradingLoop]
                    └─> ScanForTrade()
                    └─> CalcTargetPos()
                    └─> PlaceOrders via NewOrder()
```

**Current Update Interval:** 60 seconds (configurable `update_period`)

### 2.4 DataFeed WebSocket Clients

**Base Class:** `CEXWebSocketClient` (`datafeed/lib/cex_websocket.php`)
- Inherits from `WebSocketClient` (WSSC library)
- Methods: `ping()`, `reconnect()`, `subscribe()`, `unsubscribe()`
- Connection management with retry logic

**Concrete Implementations:**
| Exchange | File | Ping Method | Subscribe Format |
|----------|------|-------------|------------------|
| Binance | `bnc_websocket.php` | None (server-driven) | `SUBSCRIBE` JSON-RPC |
| Bybit | `bbt_websocket.php` | `{"op": "ping"}` | `{"op": "subscribe", "args": [...]}` |
| Bitfinex | `bfx_websocket.php` | `ping` message | `{"event": "subscribe", ...}` |
| BitMEX | `bmx_websocket.php` | [pending review] | [pending review] |

---

## 3. Target Architecture

### 3.1 New Class Hierarchy

```
TradingEngine (abstract base)
    └── RestAPIEngine (abstract)
            └── WebsockAPIEngine (NEW - abstract)
                    ├── BinanceEngine (modified)
                    ├── BitfinexEngine (modified)
                    ├── BitMEXEngine (modified)
                    ├── BybitEngine (modified)
                    └── DeribitEngine (modified)
```

### 3.2 Dual-Transport Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                     TradingCore::Update()                           │
│                          (every 60s)                                │
└─────────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     WebsockAPIEngine                                │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ State: ws_active, ws_connected, ws_authenticated            │    │
│  └─────────────────────────────────────────────────────────────┘    │
│                              │                                      │
│              ┌───────────────┴───────────────┐                      │
│              ▼                               ▼                      │
│     [WS Connected]                  [WS Disconnected/Error]         │
│              │                               │                      │
│     ┌────────┴────────┐             ┌────────┴──────────┐           │
│     │ Real-time events│             │ REST Fallback     │           │
│     │ - Order updates │             │ - LoadTickers()   │           │
│     │ - Trade fills   │             │ - LoadOrders()    │           │
│     │ - Position sync │             │ - LoadPositions() │           │
│     └─────────────────┘             └───────────────────┘           │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.3 Connection State Machine

```
                    ┌──────────────────┐
                    │    DISCONNECTED  │◄─────────────────┐
                    └────────┬─────────┘                  │
                             │ connect()          error / timeout
                             ▼                            │
                    ┌──────────────────┐                  │
                    │   CONNECTING     │──────────────────┘
                    └────────┬─────────┘
                             │ onOpen
                             ▼
                    ┌──────────────────┐
                    │  AUTHENTICATING  │──── onTimeout ──► DISCONNECTED
                    │  (if required)   │
                    └────────┬─────────┘
                             │ onAuthOk
                             │ (skipped if wsRequiresAuth()=false)
                             ▼
                    ┌──────────────────┐
                    │      READY       │  ws_connected=true
                    └──────────────────┘
```

---

## 4. WebsockAPIEngine Design

### 4.1 Class Definition

```php
abstract class WebsockAPIEngine extends RestAPIEngine {

    // Connection state
    protected bool $ws_active = false;
    protected bool $ws_connected = false;
    protected bool $ws_authenticated = false;
    
    // WebSocket configuration
    protected string $ws_public_url = '';
    protected string $ws_private_url = '';
    protected int    $ws_ping_interval = 30;  // seconds
    protected int    $ws_ping_timeout = 10;   // seconds
    protected int    $ws_reconnect_delay = 5; // seconds
    
    // Message handling
    protected array $ws_subscriptions = [];
    protected array $ws_pending_queue = []; // for messages sent before auth
    protected int   $ws_last_pong = 0;
    
    // Event callbacks (to be implemented by descendants)
    protected ?callable $on_order_update = null;
    protected ?callable $on_trade_fill = null;
    protected ?callable $on_position_update = null;
    protected ?callable $on_ticker_update = null;
    
    // Abstract methods for exchange-specific implementation
    abstract protected function wsBuildAuthMessage(): string;
    abstract protected function wsParseMessage(string $data): ?array;
    abstract protected function wsOnPong(): void;
    abstract protected function wsGetSubscribeChannels(): array;
    abstract protected function wsRequiresAuth(): bool;
    
    // Abstract connection methods
    abstract public function wsConnect(): bool;
    abstract public function wsDisconnect(): void;
    abstract public function wsSend(string $data): bool;
    abstract public function wsPing(): void;
    
    // Concrete implementation of shared logic
    public function isWsConnected(): bool;
    protected function wsEnqueueMessage(string $data): void;
    protected function wsProcessQueue(): void;
    protected function wsSubscribeChannels(array $channels): void;
    protected function wsHandleReconnection(): void;
    
    // Override parent methods with WS preference
    public function LoadTickers(): bool;  // Uses WS if connected, fallback REST
    public function LoadOrders(bool $force_all): int;
    public function LoadPositions(): int;
}
```

### 4.2 State Flags and Accessors

| Flag | Type | Default | Meaning |
|------|------|---------|---------|
| `ws_active` | bool | false | WebSocket mode enabled in config |
| `ws_connected` | bool | false | TCP connection established |
| `ws_authenticated` | bool | false | Auth message sent/verified |

**Accessor Methods:**
```php
public function isWsActive(): bool { return $this->ws_active; }
public function isWsConnected(): bool { return $this->ws_connected && $this->ws_active; }
public function isWsReady(): bool { return $this->ws_connected && $this->ws_authenticated; }
```

### 4.3 Fallback Logic Pattern

```php
public function LoadTickers(): bool {
    if ($this->isWsConnected() && $this->ws_ticker_subscribed) {
        // Data arrives via WebSocket callbacks
        // Check if we have fresh data within last 30 seconds
        if ($this->tickers_fresh_at > time() - 30) {
            return true;
        }
        // WS data stale - fall through to REST
        $this->LogMsg("~C93#WS_STALE:~C00 WebSocket ticker data outdated, using REST fallback");
    }
    
    // REST fallback
    return $this->restLoadTickers();
}

public function LoadOrders(bool $force_all): int {
    if ($this->isWsConnected() && $this->ws_authenticated) {
        // Orders update via WS - but for full sync use REST
        if (!$force_all) {
            return 0; // WS handles incremental updates
        }
        $this->LogMsg("~C93#WS_LOAD:~C00 Full order sync requested, using REST");
    }
    
    return $this->restLoadOrders($force_all);
}
```

---

## 5. Trading Cycle Redesign

### 5.1 Modified Update Loop

**Current Flow (every 60s):**
```
Update()
├── LoadTickers()           # REST poll all tickers
├── CheckRedudancy()       # DB-based master election
├── LoadOrders()           # REST poll open orders
├── LoadPositions()        # REST poll account positions
├── SignalFeed->Update()   # DB signal processing
├── Trade()               # Execute trading logic
└── SaveTickersToDB()      # Persist ticker history
```

**Proposed Flow (main cycle reduced to 1–5s; WS events trigger MiniCycle immediately):**
```
Update()
├── drainWsBuffer()        # Collect all WS events since last cycle
│   ├── onOrderFilled()   # fill_pending[] += {pair, order}
│   └── onTicker()        # Update in-memory ticker cache
├── if fill_pending not empty:
│   └── MiniCycle()       # MM response: only affected pairs
│       ├── CalcTargetPos(pair)
│       └── PlaceOrders(pair)  # immediate, no wait for full cycle
├── LoadTickers()          # WS cache if fresh, REST fallback
├── CheckRedudancy()       # DB-based master election
├── LoadOrders()           # WS for updates, REST for full sync
├── LoadPositions()        # WS if connected, REST fallback
├── SignalFeed->Update()   # DB signal processing
├── Trade()               # Execute trading logic (full scan)
└── SaveTickersToDB()      # Persist ticker history
```

WS reading (`drainWsBuffer`) remains **synchronous within the cycle** — no concurrent access, no locking required in Phase 1. See Section 9 for detailed analysis.

### 5.2 Event-Driven Order Updates

**Current:** Order state determined by polling every cycle
**Proposed:** Order updates via WebSocket events + periodic polling

```php
// WebSocket message handler (called from event loop)
protected function wsOnOrderUpdate(array $data): void {
    $order = $this->FindOrder($data['order_id']);
    if (!$order) {
        $this->LogMsg("~C93#WS_NEW_ORDER:~C00 %s", json_encode($data));
        $order = $this->CreateOrder($data['order_id']);
        // ... import order data
    }
    
    $prev_status = $order->status;
    $order->matched = $data['filled_qty'];
    $order->status = $this->mapWsStatus($data['status']);
    $order->updated = date_ms(SQL_TIMESTAMP_MS, $data['update_time']);
    
    if ($order->matched > 0 && $prev_status !== 'filled') {
        $this->TradeCore()->ProcessFilled($order);
    }
    
    $this->DispatchOrder($order, 'WS/order_update');
}
```

### 5.3 Fallback Conditions

| Condition | Action | Fallback |
|-----------|--------|----------|
| WS connection fails | Log warning, continue | Use REST |
| WS disconnected mid-session | Attempt reconnect 3x | Fall back to REST |
| WS auth fails | Log error, retry once | Use REST |
| WS subscription fails | Log warning | Use REST for that data |
| WS stale data (>60s no update) | Log warning | Force REST sync |
| WS ping timeout | Mark disconnected | Attempt reconnect |

### 5.4 Configuration

#### Bot-level control (per bot instance in `bot__config`)

```php
$bot_config = [
    'ws_enabled'            => true,       // master kill-switch: false = REST-only mode
    'ws_priority'           => 'ws_first', // 'ws_first' | 'rest_first' | 'ws_only'
    'ws_ping_interval'      => 30,         // seconds between pings
    'ws_reconnect_attempts' => 3,          // max reconnect tries before REST fallback
    'ws_reconnect_delay'    => 5,          // base delay in seconds (multiplied per attempt)
    'ws_fallback_threshold' => 60,         // seconds of stale WS data before forcing REST
];
```

#### Exchange-level endpoint configuration (per exchange profile)

Exchange profiles are stored as **YAML files** in `src/config/exchanges/<exchange>.yml`.
Each named profile (main, testnet, demo…) can declare its own WS endpoints.
On disconnect or error the engine rotates to the next available endpoint before
falling back to REST.

```yaml
# src/config/exchanges/binance.yml  — example with WS fields added
version: 1
exchange: binance
default_profile: main
profiles:
    main:
        public_api:  https://api.binance.com/
        private_api: https://api2.binance.com/
        ws_public:   ["wss://stream.binance.com:9443/stream",
                      "wss://stream1.binance.com:9443/stream",
                      "wss://stream2.binance.com:9443/stream"]
        ws_private:  ["wss://stream.binance.com:9443/ws",
                      "wss://stream1.binance.com:9443/ws"]
        ws_endpoint_strategy: failover   # failover | round_robin | primary_only
        ws_disabled: false               # admin kill-switch for this exchange
    testnet:
        public_api:  https://testnet.binance.vision/
        private_api: https://testnet.binance.vision/
        ws_public:   ["wss://testnet.binance.vision/stream"]
        ws_private:  ["wss://testnet.binance.vision/ws"]
        ws_endpoint_strategy: primary_only
```

The engine loads these fields via the same YAML-parsing path used for REST endpoints.
The PHP runtime properties `$ws_endpoints`, `$ws_endpoint_strategy`, `$ws_disabled`
are populated from the loaded profile array — no separate config format or DB table needed.

#### Endpoint rotation implementation in `WebsockAPIEngine`

```php
protected int   $ws_endpoint_index  = 0;
protected array $ws_endpoints       = ['public' => [], 'private' => []];
protected string $ws_endpoint_strategy = 'failover';

protected function wsNextEndpoint(string $type = 'public'): ?string {
    $endpoints = $this->ws_endpoints[$type] ?? [];
    if (empty($endpoints)) return null;

    if ($this->ws_endpoint_strategy === 'round_robin') {
        $url = $endpoints[$this->ws_endpoint_index % count($endpoints)];
        $this->ws_endpoint_index++;
    } else { // failover: walk forward, stay on last if exhausted
        $idx = min($this->ws_endpoint_index, count($endpoints) - 1);
        $url = $endpoints[$idx];
        $this->ws_endpoint_index++;
    }
    return $url;
}

protected function wsResetEndpointRotation(): void {
    $this->ws_endpoint_index = 0;
}

// Call on successful connect to reset the failure counter
protected function wsOnConnected(): void {
    $this->wsResetEndpointRotation();
    $this->ws_connected = true;
}
```

#### Administrative disable precedence

| Level | Setting | Effect |
|-------|---------|--------|
| Bot instance | `bot__config.ws_enabled = 0` | WS off for entire bot, all exchanges |
| Exchange | `exchange_profile.ws_disabled = 1` | WS off for one exchange, others unaffected |
| Priority mode | `ws_priority = 'rest_first'` | WS used only when REST fails |
| Priority mode | `ws_priority = 'ws_only'` | No REST fallback (use with caution) |

---

## 6. Iterative Migration Plan

### 6.1 Phase 0: Infrastructure

**Tasks:**
1. Create `WebsockAPIEngine` base class in `rest_api_common.php`
2. Add WebSocket configuration support to `TradeConfig`
3. Implement connection state machine
4. Add ping/pong heartbeat mechanism
5. Create abstract methods requiring implementation

**Deliverables:**
- `WebsockAPIEngine` skeleton
- Configuration schema
- Basic connection test script

**Testing:**
- Unit tests for connection state transitions
- Mock WebSocket server for testing

### 6.2 Phase 1: Binance

**Rationale:** Binance has most mature WS infrastructure, well-documented API

**Tasks:**
1. Override `wsBuildAuthMessage()` - Binance signature via HMAC SHA256
2. Implement `wsParseMessage()` - Parse Binance WS format
3. Implement `wsGetSubscribeChannels()` - `btc@trade`, `<symbol>@depth` streams
4. Override `LoadTickers()` - Use `@bookTicker` stream
5. Override `LoadOrders()` - Use `_broker.accountSnapshot` or `O` execution reports
6. Implement `wsRequiresAuth()` - true

**Implementation:**
```php
final class BinanceEngine extends WebsockAPIEngine {
    
    protected function wsRequiresAuth(): bool {
        return true;
    }
    
    protected function wsBuildAuthMessage(): string {
        // Binance Future/spot uses different auth
        // Spot: listenKey based
        // Future: signature based
        return json_encode([
            'method' => 'AUTH',
            'params' => [...],
            'id' => 1
        ]);
    }
    
    protected function wsParseMessage(string $data): ?array {
        $obj = json_decode($data);
        if (!$obj) return null;
        
        // Handle different event types
        if (isset($obj->e)) { // Execution report
            return ['type' => 'order', 'data' => $obj];
        }
        if (isset($obj->lastPrice)) { // Ticker
            return ['type' => 'ticker', 'data' => $obj];
        }
        
        return null;
    }
}
```

### 6.3 Phase 2: Bybit

**Rationale:** Clean API structure, good documentation

**Tasks:**
1. Override `wsBuildAuthMessage()` - `{"op": "auth", "args": [...]}`
2. Implement `wsParseMessage()` - Handle v5 public/private topics
3. Implement `wsGetSubscribeChannels()` - `order`, `execution`, `position`
4. Override `LoadTickers()` - Use ticker health from WS
5. Implement `wsOnPong()` - Track last pong time

### 6.4 Phase 3: Bitfinex

**Tasks:**
1. Override `wsBuildAuthMessage()` - `{"event": "auth", ...}`
2. Implement `wsParseMessage()` - Channel-based messages with chanId
3. Implement `wsGetSubscribeChannels()` - `trades`, `orders`, `positions`
4. Handle channel-specific subscription responses

### 6.5 Phase 4: BitMEX

**Tasks:**
1. Override `wsBuildAuthMessage()` - Signature generation
2. Implement `wsParseMessage()` - Table-based subscriptions
3. Implement `wsGetSubscribeChannels()` - `order`, `position`, `execution`
4. Handle partial message assembly (BitMEX sends large frames)

### 6.6 Phase 5: Deribit

**Tasks:**
1. Override `wsBuildAuthMessage()` - Bearer token or signature
2. Implement `wsParseMessage()` - JSON-RPC 2.0 format
3. Implement `wsGetSubscribeChannels()` - `orders`, `positions`, `trades`
4. Handle subscription confirmation messages

### 6.7 Phase 6: Integration & Testing

**Tasks:**
1. Full integration testing across all engines
2. Load testing with simulated market conditions
3. Failover testing - verify REST fallback works correctly
4. Performance profiling - compare latency improvements
5. Documentation update

### 6.8 Migration Sequence

Start with the exchange where the Market Maker is most active — that yields the earliest measurable benefit.

| Phase | Scope | Dependency |
|-------|-------|------------|
| 0 | Infrastructure: `WebsockAPIEngine` base, config schema, state machine | — |
| 1 | Primary MM exchange (Binance or Bybit) | Phase 0 |
| 2+ | Remaining exchanges | Phase 1 |
| Final | Integration & cross-exchange testing | All phases |

---

## 7. Implementation Details

### 7.1 Message Queue System

```php
protected array $ws_message_queue = [];

protected function wsEnqueueMessage(string $data): void {
    if ($this->ws_authenticated) {
        $this->wsSend($data);
    } else {
        $this->ws_message_queue[] = $data;
        $this->LogMsg("~C93#WS_QUEUE:~C00 Message queued, waiting for auth");
    }
}

protected function wsProcessQueue(): void {
    while (!empty($this->ws_message_queue)) {
        $msg = array_shift($this->ws_message_queue);
        if (!$this->wsSend($msg)) {
            array_unshift($this->ws_message_queue, $msg);
            break;
        }
    }
}
```

### 7.2 Reconnection Logic

The scheme is ported from `datafeed/src/proto_manager.php` (`ReconnectWS`) and
`datafeed/lib/cex_websocket.php` — these were debugged under live production load
and are considered stable.

Key rules:
- **Never `sleep()` inside a disconnect handler** — the trading cycle must keep ticking.
  Reconnect is triggered on the _next_ `drainWsBuffer()` call, not inline.
- **Cooldown guard** — prevents reconnect thrashing: minimum 100 s between attempts.
- **Endpoint rotation** — on reconnect, `wsNextEndpoint()` advances to the next mirror
  before recreating the socket (see Section 5.4).
- All per-pair subscription flags are reset so `wsSubscribeAll()` re-registers everything
  after the new connection is ready.

```php
protected int   $ws_reconnect_t        = 0;  // timestamp of last reconnect attempt
protected int   $ws_reconnects         = 0;  // lifetime counter
protected int   $ws_reconnect_cooldown = 100; // seconds between attempts
protected int   $ws_subscribe_after    = 0;  // scheduled re-subscribe timestamp

public function wsReconnect(string $reason): void {
    $elps = time() - $this->ws_reconnect_t;
    if ($elps < $this->ws_reconnect_cooldown) {
        // Inside cooldown window — mark disconnected but don't attempt yet
        $this->ws_connected    = false;
        $this->ws_authenticated = false;
        return;
    }

    $this->ws_reconnect_t   = time();
    $this->ws_reconnects   ++;
    $this->ws_connected     = false;
    $this->ws_authenticated = false;
    $this->ws_last_ping     = 0;
    $this->ws_empty_reads   = 0;

    $this->LogMsg("~C91#WS_RECONNECT:~C00 reason: %s (attempt #%d)", $reason, $this->ws_reconnects);

    // Close old socket cleanly if still present
    $this->wsClose();

    // Advance to next endpoint before reconnecting
    $url = $this->wsNextEndpoint('private');
    if (null === $url) {
        $this->LogMsg("~C91#WS_NO_ENDPOINTS:~C00 no endpoints configured, REST fallback active");
        $this->ws_active = false;
        return;
    }

    // Reset subscription state for all pairs
    foreach ($this->ws_subscribers as $pair => &$sub)
        $sub['confirmed'] = false;
    unset($sub);

    // Attempt reconnect; schedule re-subscribe 10 s after open
    if ($this->wsConnect($url)) {
        $this->ws_subscribe_after = time() + 10;
        $this->wsResetEndpointRotation();
    } else {
        $this->ws_active = false;  // fall back to REST until next cycle
    }
}
```

### 7.3 Stall / Hang Detection and Ping Keepalive

`pcntl_alarm` / `SIGALRM` **must not be used** for WS keepalive — it is dangerous in
PHP because the signal interrupts blocking syscalls (`socket_read`, `curl_exec`, DB
queries), corrupting state in unrelated code paths.

Instead, all checks run synchronously inside `drainWsBuffer()` on every Update() cycle.
This guarantees deadlock-free execution and matches the datafeed `ProcessWS()` / 
`LoadPacketWS()` pattern.

```php
protected int   $ws_last_ping    = 0;
protected int   $ws_last_data_t  = 0;  // wall-clock time of last received frame
protected int   $ws_empty_reads  = 0;  // consecutive empty/broken frame counter
protected int   $ws_ping_interval   = 30;   // seconds between pings
protected int   $ws_data_stall_sec  = 300;  // no data for this long → re-subscribe or reconnect
protected int   $ws_ping_timeout    = 120;  // no pong after this long → reconnect

/**
 * Called once per Update() cycle — drives all WS health logic without sleep() or signals.
 */
public function drainWsBuffer(): void {
    if (!$this->ws_active || !$this->ws_connected) return;

    // 1. Ping keepalive -----------------------------------------------------
    $ping_elps = time() - $this->ws_last_ping;
    if ($ping_elps > $this->ws_ping_interval) {
        try {
            $this->ws_last_ping = time() - 20; // write before send: prevents re-entry ping DDoS
            $this->wsPing();
        } catch (Throwable $E) {
            $this->ws_exceptions ++;
            $this->LogMsg("~C91#WS_PING_FAIL:~C00 %s (exceptions total: %d)", $E->getMessage(), $this->ws_exceptions);
            if ($ping_elps >= 60 || $this->ws_exceptions > 5) {
                $this->ws_exceptions = 0;
                $this->wsReconnect('ping failed / exception storm');
                return;
            }
        }
    }

    // 2. Data stall detection -----------------------------------------------
    $data_elps = time() - $this->ws_last_data_t;
    if ($this->ws_last_data_t > 0 && $data_elps > $this->ws_data_stall_sec) {
        if ($ping_elps > $this->ws_ping_timeout) {
            // No ping reply either → full reconnect
            $this->wsReconnect("data stall {$data_elps}s + no pong");
            return;
        }
        // Ping is alive — subscription may have dropped; re-subscribe without reconnect
        $this->LogMsg("~C31#WS_DATA_LAG:~C00 no data for %d s, ping ok — re-subscribing", $data_elps);
        foreach ($this->ws_subscribers as $pair => &$sub)
            $sub['confirmed'] = false;
        unset($sub);
        $this->wsSubscribeAll();
    }

    // 3. isConnected() sanity check -----------------------------------------
    if (!$this->wsIsConnected()) {
        $this->wsReconnect('disconnect status');
        return;
    }

    // 4. Read all available frames (1 s budget max) -------------------------
    $t_start = microtime(true);
    while ($this->wsUnreaded() > 0) {
        $this->wsReadFrame();
        if (!$this->ws_connected || (microtime(true) - $t_start) >= 1.0) break;
    }

    // 5. Deferred re-subscribe after reconnect ------------------------------
    if ($this->ws_subscribe_after > 0 && time() >= $this->ws_subscribe_after) {
        $this->ws_subscribe_after = time() + 5; // retry in 5 s if still not done
        if ($this->ws_active)
            $this->wsSubscribeAll();
    }
}

/**
 * Read and dispatch one WS frame. Called only from drainWsBuffer().
 */
protected function wsReadFrame(): void {
    try {
        $avail = $this->wsUnreaded();
        if ($avail <= 0) return;

        $raw    = $this->wsReceive();
        $opcode = $this->wsLastOpcode();

        if (null === $raw || '' === $raw) return;

        $this->ws_empty_reads  = 0;
        $this->ws_last_data_t  = time();

        if ('{' === $raw[0] || '[' === $raw[0]) {
            $data = json_decode($raw, false);
            if (is_object($data) || is_array($data))
                $this->wsDispatch($data);
            return;
        }

        if ('close' === $opcode) {
            $this->LogMsg("~C91#WS_CLOSE:~C00 server sent close: %s", $raw);
            $this->ws_connected = false;
            $this->ws_active    = false;
            return;
        }

        if ('ping' === $opcode) {
            $this->ws_last_ping = time();
            $this->wsPong($raw);
            return;
        }

        if ('pong' === $opcode) {
            $this->ws_last_ping = time();
            return;
        }

    } catch (Exception $E) {
        $msg = $E->getMessage();
        // Known benign low-level exceptions — count but do not reconnect immediately
        $benign = str_contains($msg, 'Empty read')
               || str_contains($msg, 'Broken frame')
               || str_contains($msg, 'Bad opcode');
        if ($benign) {
            $this->ws_empty_reads ++;
        } else {
            $this->ws_exceptions ++;
            $this->LogMsg("~C91#WS_FRAME_ERR:~C00 %s", $msg);
        }

        $ping_elps = time() - $this->ws_last_ping;
        if ($this->ws_empty_reads > 4 && $ping_elps > $this->ws_ping_timeout) {
            $this->wsReconnect("too many empty/broken frames: $msg");
        }
    }
}
```

#### New properties required in `WebsockAPIEngine`

```php
protected int   $ws_exceptions   = 0;  // consecutive exception counter (reset on reconnect)
protected array $ws_subscribers  = []; // ['pair' => ['confirmed' => bool, 'channels' => []]]
```

#### Summary: what changed vs the original plan

| Old (broken) | New (datafeed-proven) |
|---|---|
| `sleep($delay)` in `wsOnDisconnect()` | No sleep anywhere; reconnect fires on next cycle |
| `pcntl_alarm` / `SIGALRM` heartbeat | Synchronous ping check in `drainWsBuffer()` |
| Single reconnect path | Distinct paths: reconnect vs re-subscribe-only (stall without lost ping) |
| No cooldown | 100 s cooldown guard prevents thrash |
| No stall detection | 300 s data-stall + 120 s pong-timeout, checked every cycle |
| Benign exceptions trigger reconnect | Only reconnect after >4 benign failures AND no pong for 120 s |

### 7.4 Order Status Mapping

```php
protected function mapWsStatus(string $ws_status): string {
    $map = [
        'NEW' => 'active',
        'PARTIALLY_FILLED' => 'partially_filled',
        'FILLED' => 'filled',
        'CANCELED' => 'canceled',
        'REJECTED' => 'rejected',
        'EXPIRED' => 'expired',
        // Binance specific
        'NEW_INSERT' => 'active',
        'TRADE' => 'active',
    ];
    
    return $map[$ws_status] ?? $ws_status;
}
```

### 7.5 Subscription Management

```php
protected array $ws_active_subscriptions = [];

public function wsSubscribe(string $channel, array $params = []): bool {
    $sub = [
        'channel' => $channel,
        'params' => $params,
        'subscribed_at' => time()
    ];
    
    $msg = $this->buildSubscriptionMessage($channel, $params);
    $this->wsEnqueueMessage($msg);
    
    $this->ws_active_subscriptions[$channel] = $sub;
    return true;
}

public function wsUnsubscribe(string $channel): bool {
    if (!isset($this->ws_active_subscriptions[$channel])) {
        return false;
    }
    
    $msg = $this->buildUnsubscriptionMessage($channel);
    $this->wsEnqueueMessage($msg);
    
    unset($this->ws_active_subscriptions[$channel]);
    return true;
}
```

---

## 8. Risk Assessment

### 8.1 Technical Risks

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| WS library compatibility | Medium | Medium | Use existing datafeed CEXWebSocketClient |
| Exchange API changes | Low | High | Version-specific handlers, graceful degradation |
| Connection storms on reconnect | Medium | Medium | Exponential backoff, connection limits |
| Memory leaks in event loop | Low | High | Proper cleanup, periodic garbage collection |
| Out-of-order messages | Medium | Medium | Sequence number validation, message buffering |

### 8.2 Operational Risks

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| REST fallback not tested | High | High | Mandatory fallback testing in each phase |
| Increased complexity debugging | Medium | Medium | Detailed logging, structured error messages |
| Performance regression | Low | Medium | Profiling before/after, gradual rollout |

### 8.3 Mitigation Strategies

1. **Feature Flags:** Each engine has `ws_enabled` config, default off
2. **Parallel Operation:** REST continues as fallback, not replaced
3. **Monitoring:** Add WS-specific metrics to existing monitoring
4. **Rollback Plan:** If WS fails, single config change restores REST-only mode

### 8.4 Testing Requirements

| Test Type | Coverage |
|-----------|----------|
| Unit Tests | State machine, message parsing, status mapping |
| Integration Tests | WS connection, auth, subscriptions per exchange |
| Failover Tests | Disconnect simulation, REST fallback verification |
| Load Tests | 1000+ order updates/second, connection stability |
| Chaos Tests | Network interruption, exchange API outage |

---

## 9. Synchronous vs Asynchronous Data Flow Analysis

### 9.1 Current Architecture

**Datafeed WebSocket Model (Synchronous Read in Loop):**

```
WS Read Loop (single point in datafeed cycle)
    │
    └─> socket_read() 
        └─> while ($data = socket_read()) {
                parse_message($data)
                process_update($parsed)
            }
```

In datafeed, WebSocket reading is embedded in a **single synchronous loop** that drains the socket buffer. Data arrives and is processed in order, within that loop iteration.

**TradingCore Current Model:**

```
Update() [synchronous sequence]
│
├─ LoadTickers()      → REST polling
├─ LoadPositions()     → REST polling  
├─ LoadOrders()        → REST polling
├─ CalcPositions()     → Sequential calculation
└─ Trade()            → Sequential execution
```

### 9.2 Implication for WebSocket Integration

**Current Design (Phase 1):** WebSocket reading occurs at **defined points** in the cycle, not concurrently. The main concern is **data sequencing**, not race conditions.

```
┌─────────────────────────────────────────────────────────────────┐
│                 Proposed WS Integration (Phase 1)                 │
└─────────────────────────────────────────────────────────────────┘

Update() cycle:
│
├─ WS: Drain socket buffer → Process messages → Update state
├─ LoadTickers()      ← May read WS-updated values
├─ LoadPositions()     ← May read WS-updated values
├─ LoadOrders()        ← May read WS-updated values
├─ CalcPositions()     ← WS deltas applied
└─ Trade()            ← Data includes WS updates from same cycle
```

**Data Sequencing Issues (not Race Conditions):**

| Scenario | Problem | Mitigation |
|----------|---------|------------|
| WS delivers order fill, then `NewOrder()` places another order for same pair | Order execution order unclear | REST `NewOrder()` respects API ordering |
| WS delivers ticker update, then `Trade()` uses stale price | Price used may differ from actual | Acceptable - Trade uses whatever price is latest |
| WS delivers position update mid-cycle | Position calculations use inconsistent snapshot | Use REST snapshot as base, WS as delta only |

### 9.3 No Lock Required for Phase 1

Since WebSocket reading is **synchronous within the cycle loop**, there are no concurrent modifications:

```php
// NO concurrent execution:
// WS reading and Trade() do NOT run in parallel
// WS messages are processed in sequence within one loop iteration
```

**Order of operations is preserved:**
1. WS buffer drained → state updated
2. REST Load*() called → reads current state (includes WS updates)
3. CalcPositions() → calculates with latest available data
4. Trade() → executes with consistent snapshot

### 9.4 Future Async Architecture Warning

> **⚠️ WARNING:** If the system transitions to a fully asynchronous architecture (separate processes/threads for WS reading and trading), the following protections become **mandatory**:

```
┌─────────────────────────────────────────────────────────────┐
│         WARNING: Full Async Architecture Requirements        │
└─────────────────────────────────────────────────────────────┘

If WS reading runs in a SEPARATE process/thread from Trade():
│
├─ Socket read lock required OUTSIDE Load*() section
│   └─ No WS messages processed during Trade()
│   └─ Or: Queue WS updates, apply after Trade()
│
├─ Data version tracking
│   └─ Version/timestamp on all shared state
│   └─ Optimistic locking on writes
│
├─ Batch state versioning
│   └─ Re-validate batch state before mutations
│   └─ CAS (Compare-And-Swap) on lock variable
│
└─ OrderList iteration safety
    └─ Clone before iteration
    └─ Or: Lock during iteration
```

**Design Rule for Future:**
```
┌─────────────────────────────────────────────────────────────────┐
│  WebSocket reading should remain within the Load*()              │
│  section of the cycle, NOT as background processing.            │
│                                                                 │
│  If background WS processing is required:                        │
│  1. Implement Cycle Lock (wsBeginTradeCycle/wsEndTradeCycle)     │
│  2. Queue updates, apply after Trade() completes                 │
│  3. Use atomic operations for shared state                      │
└─────────────────────────────────────────────────────────────────┘
```

### 9.5 Current Approach is Safe

**Phase 1 is safe because:**
1. WS reading is synchronous in main loop
2. No concurrent modifications
3. Data arrives in order
4. Trade() sees consistent snapshot

**The only data inconsistency possible:**
- WS delivers fill for pair X
- `Trade()` for pair X uses price slightly different from fill price
- **This is acceptable** - trading decision is based on latest available data

### 9.6 Summary

| Aspect | Current (Sync) | Future (Async) |
|--------|----------------|----------------|
| WS reading | In loop | Separate thread/process |
| Race conditions | None | Requires lock |
| Data consistency | Guaranteed | Requires versioning |
| Complexity | Low | Medium-High |
| Performance | Limited by cycle | Full real-time |

**Recommendation:** Maintain synchronous WS reading within the cycle for Phase 1. Document async requirements clearly for future architecture changes.

---

## Appendix A: File Locations

| File | Path | Purpose |
|------|------|---------|
| RestAPIEngine | `trading-platform-php/src/rest_api_common.php` | Base REST class |
| WebsockAPIEngine | `trading-platform-php/src/rest_api_common.php` | NEW class |
| CEXWebSocketClient | `datafeed/lib/cex_websocket.php` | Base WS client |
| BinanceClient | `datafeed/src/bnc_websocket.php` | Binance WS reference |
| BybitClient | `datafeed/src/bbt_websocket.php` | Bybit WS reference |
| TradingCore | `trading-platform-php/src/trading_core.php` | Main loop |
| TradingLoop | `trading-platform-php/src/trading_loop.php` | Trade execution |

## Appendix B: Configuration Schema

```sql
-- Bot-level WebSocket control (bot__config)
ALTER TABLE bot__config
    ADD COLUMN ws_enabled            TINYINT(1)                              DEFAULT 0
        COMMENT 'Master WS kill-switch for this bot instance (0=REST-only)',
    ADD COLUMN ws_priority           ENUM('ws_first','rest_first','ws_only') DEFAULT 'ws_first',
    ADD COLUMN ws_ping_interval      INT                                     DEFAULT 30,
    ADD COLUMN ws_reconnect_attempts INT                                     DEFAULT 3,
    ADD COLUMN ws_reconnect_delay    INT                                     DEFAULT 5,
    ADD COLUMN ws_fallback_threshold INT                                     DEFAULT 60
        COMMENT 'Seconds of stale WS data before forcing REST sync';

-- Exchange-level WS settings are NOT stored in the DB.
-- They live in src/config/exchanges/<exchange>.yml, per-profile.
-- See Section 5.4 for the YAML field names (ws_public, ws_private,
-- ws_endpoint_strategy, ws_disabled).
```

## Appendix C: Metrics for Monitoring

| Metric | Description | Alert Threshold |
|--------|-------------|-----------------|
| `ws_connection_uptime` | % of time WS connected | < 95% |
| `ws_messages_per_sec` | Message throughput | < 10 or > 10000 |
| `ws_latency_ms` | Message processing time | > 100ms avg |
| `ws_pong_timeout_count` | Ping timeout events | > 5 per hour |
| `ws_rest_fallback_count` | Fallback to REST events | > 10 per hour |
| `ws_reconnect_count` | Reconnection attempts | > 20 per hour |

---

---

## 10. Fast Market-Maker Reaction on WS Fills

### 10.1 Motivation

On a volatile market a fully-executed grid order can trigger the need to re-post a
replacement order within seconds. The regular Update() cycle (≥ 1 s poll + DB round-trip
overhead) introduces unnecessary latency. Since the WS `order` push already delivers the
fill event mid-cycle (inside `drainWsBuffer()`), all data needed to respond is already
available — no additional REST calls are required.

### 10.2 Implementation (as of 2026-04-05)

#### Accumulator in `WebsockAPIEngine`

```php
protected array $ws_filled_pairs = []; // pair_id => fill-count, accumulated during drain
```

Each concrete engine calls `wsMarkFilledPair()` when an order fill is detected:

```php
protected function wsMarkFilledPair(int $pair_id): void {
    $this->ws_filled_pairs[$pair_id] = ($this->ws_filled_pairs[$pair_id] ?? 0) + 1;
}
```

#### Detection in `BybitEngine::wsOnOrderUpdate()`

```php
private function wsOnOrderUpdate(\stdClass $order): void {
    $changed = $this->UpdateOrder($order);
    $status  = strval($order->orderStatus ?? '');
    // ... log #WS_ORDER ...

    if ($changed && ($status === 'Filled' || $status === 'PartiallyFilled')) {
        if (isset($this->pairs_map_rev[$symbol]))
            $this->wsMarkFilledPair($this->pairs_map_rev[$symbol]);
    }
}
```

Bybit sends `orderStatus` in PascalCase (`Filled`, `PartiallyFilled`) — matched literally
to avoid false positives from `New` / `Cancelled` pushes.

#### Step 4b in `drainWsBuffer()` — fast MM trigger

This step runs **after** the full frame-drain loop (step 4), before the deferred
re-subscribe logic (step 5):

```php
// 4b. Fast MM reaction — fires for pairs that got fills during this drain pass
if (!empty($this->ws_filled_pairs)) {
    foreach (array_keys($this->ws_filled_pairs) as $fid)
        $this->ProcessMM($fid);
    $this->ws_filled_pairs = [];
}
```

#### Pair-scoped `ProcessMM(int $pair_id = 0)` in `TradingEngine`

```php
public function ProcessMM(int $pair_id = 0) {
    if ($pair_id > 0) {
        $mm = $this->market_makers[$pair_id] ?? null;
        if (!$mm) return;
        $this->TradeCore()->LogMM(
            "~C93#PROCESS_MM_FAST:~C00 pair_id=%d (WS fill trigger)",
            $pair_id
        );
        $mm->Process();
        return;
    }
    // pair_id = 0: full scan (called from regular Update() cycle)
    $this->TradeCore()->LogMM("~C93#PROCESS_MM:~C00 count configured %d",
        count($this->market_makers));
    foreach ($this->market_makers as $mm)
        $mm->Process();
}
```

The `$pair_id > 0` branch is an O(1) hash-map lookup — no iteration over all MM
instances.

### 10.3 Execution Flow

```
drainWsBuffer()
│
├─ [step 1–3]  ping keepalive, stall detection, isConnected check
│
├─ [step 4]    read frames loop (1 s budget)
│              │
│              └─ wsReadFrame() → wsDispatch()
│                               └─ wsOnOrderUpdate()
│                                  ├─ UpdateOrder()    ← status, matched updated in-memory
│                                  ├─ DispatchOrder()  ← moved to matched_orders if filled
│                                  └─ wsMarkFilledPair(pair_id)  ← accumulates fill count
│
├─ [step 4b]   if ws_filled_pairs not empty:
│              ├─ ProcessMM(pair_id) for each filled pair
│              │   └─ mm->Process()
│              │       ├─ CheckOrders()   ← removes freshly-filled orders from grid blocks
│              │       └─ AdjustBlock()   ← re-posts replacement orders at new price levels
│              └─ ws_filled_pairs = []
│
├─ [step 5]    deferred re-subscribe
└─ [step 6]    periodic WS stats log (#WS_STATS every 300 s)
```

### 10.4 Safety Properties

| Property | Guarantee |
|----------|-----------|
| **No race conditions** | WS reading and MM processing are both synchronous, sequential within one drainWsBuffer() call |
| **No duplicate processing** | `ws_filled_pairs` cleared immediately after the fast trigger; regular ProcessMM() in Update() runs the full scan and is idempotent |
| **MM config guard** | `$this->market_makers[$pair_id]` is undefined until `ConfigureMM()` loads `{exchange}__mm_config` — no MM created = fast trigger is a no-op |
| **No extra REST calls** | The entire fast path operates on in-memory state updated by WS push |
| **Partial fills included** | `PartiallyFilled` also triggers fast MM so the grid can adjust sooner |

### 10.5 Log Signatures

| Tag | Meaning |
|-----|---------|
| `#WS_ORDER` | Every order push received: symbol, linkId, status, cumExecQty |
| `#PROCESS_MM_FAST` | Fast trigger fired for a specific pair_id (WS fill path) |
| `#PROCESS_MM` | Normal full-scan from Update() cycle |
| `#WS_STATS` | Periodic stats: packets, KB received, reconnects, exceptions (every 300 s) |

**Document Status:** Ready for review and implementation planning  
**Next Steps:**
1. Phase 0 implementation - create WebsockAPIEngine base class
2. Integrate WS reading into Load*() section of Update() cycle
3. Implement REST fallback logic

---

## 11. Bot Reactivity Model

This section describes the end-to-end timing behaviour of the trading bot — from external
events (signals, triggers, order fills) to the moment the exchange receives the next
order.

### 11.1 Reaction Classes

The bot has three distinct reaction classes with very different latency budgets:

| Class | Trigger | Latency | Mechanism |
|-------|---------|---------|----------|
| **A — Fast fill reaction** | WS order fill (Filled / PartiallyFilled) | < 1 s | `wsMarkFilledPair` → `ProcessMM` in step 4b of `drainWsBuffer()` |
| **B — Trading cycle** | Normal `Update()` tick | 1–5 s | Full MM scan, position check, order adjustment |
| **C — Signal / trigger** | DB signal record, stop-loss, take-profit | start of the next minute (≤ 60 s) | `SignalFeed->Update()` polling at `minute == 0` of each cycle |

### 11.2 Class C — Signal and Trigger Timing

Signals (open/close orders from the signal server) and autonomous triggers such as
stop-loss and take-profit are **processed at the beginning of each minute** when the
trading cycle variable `$minute` changes.

```php
$minute = date('i');
// ...
if (0 == $minute)
    $this->mm_errors = 0;   // example minute-boundary reset
```

Consequences:
- In the default configuration the **worst-case reaction latency to a new signal is 60 s**.
- If the trading loop interval is reduced (e.g. to 5 s), the bot checks the minute
  boundary more frequently but still fires signal actions only once per minute change.
- Stop-loss and take-profit triggers share this cadence unless they are delivered
  through the WS order-fill path (Class A) from an already-placed limit order.

### 11.3 Class A — Fast MM Execution Reaction

The MM execution logic is designed around **incremental, porportioned order placement**:

1. `OpenOrders()` places a limited initial tranche at the nearest grid level.
2. When the exchange fills (or partially fills) that order, a WS push arrives
   inside `drainWsBuffer()` in the **same trading cycle**.
3. `wsMarkFilledPair(pair_id)` accumulates the event.
4. Step 4b of `drainWsBuffer()` fires `ProcessMM(pair_id)` immediately — before the
   caller's `Update()` loop continues.
5. `mm->Process()` → `CheckOrders()` removes the filled order, then `AdjustBlock()`
   / `OpenOrders()` places the next tranche.

This means the bot **does not wait for the next full Update() tick** to respond to a
fill — the replacement order can be posted within the same sub-second drain window.

### 11.4 Overall Signal-to-Goal Timeline

Achieving the full target position for a signal depends on three factors:

| Factor | Effect on timeline |
|--------|-------------------|
| **Volume / lot constraints** | A large signal is split into multiple tranches; each tranche requires at least one exchange round-trip |
| **Market liquidity** | Thin books → slower fill rate → more cycles between tranches |
| **Aggression settings** (`max_mm_cost`, `max_exec_cost`, cost coef) | Higher limits = larger tranches = fewer cycles needed |

Typical observed ranges:

```
Small signal  (<$500 equivalent)  →  1 – 3 minutes
Medium signal ($500 – $5 000)     →  3 – 8 minutes
Large signal  (>$5 000)           →  8 – 15 minutes
```

The above assumes normal market conditions and the default aggression settings.  
In low-liquidity or high-volatility conditions the upper bound can be exceeded.

### 11.5 Interaction Between Reaction Classes

```
[Signal arrives in DB]
        │
        ▼  (≤ 60 s, Class C)
[SignalFeed->Update() picks up signal]
        │
        ▼  (same Update() cycle)
[OpenOrders() — places tranche 1]
        │
        ▼  (exchange executes, WS push, < 1 s, Class A)
[wsOnOrderUpdate() → wsMarkFilledPair()]
        │
        ▼  (step 4b of drainWsBuffer(), same drain pass)
[ProcessMM(pair_id) — places tranche 2]
        │
       ... repeat until target reached or tranches exhausted ...
        │
        ▼  (signal position = target)
[Batch closed, signal marked complete]
```

Class B (regular Update() cycle) runs in parallel as a safety net: it will catch any
fills that arrived between WS drains or during a temporary WS disconnect, and will also
fire `ProcessMM()` for pairs that did not get a fast trigger.

### 11.6 Configuration Knobs

| Config key | Default | Effect on reactivity |
|------------|---------|---------------------|
| `max_mm_cost` | exchange-specific | Max USD cost per single MM order — limits tranche size |
| `max_exec_cost` | exchange-specific | Max USD cost per execution order — limits signal tranche |
| `max_cost_coef` | 1.0 | Multiplier on cost limits; > 1.0 increases aggression |
| `ws_enabled` | 1 | 0 = WS disabled, Class A reactions fall back to Class B |
| `ws_ping_interval` | 30 s | Heartbeat interval; does not affect order reaction speed |
| `ws_data_stall_sec` | 300 s | Time without data before forced reconnect |

Reducing `ws_data_stall_sec` or increasing heartbeat frequency does **not** improve
fill-reaction latency — the bottleneck is `max_mm_cost` / liquidity, not WS health.

**Async Lock Note:** Section 9 clarifies that for Phase 1 (synchronous WS reading), no lock mechanism is required. Warning added for future async architecture migration.