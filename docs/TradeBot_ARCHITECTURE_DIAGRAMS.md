# Architecture Diagrams

## Overview
Key system components and data flows rendered as Mermaid diagrams for clarity.

---

## Diagram 1: Component Map

```mermaid
graph LR
    subgraph ext["External Systems"]
        BIN["Exchange<br/>EXCHANGE_X"]
        TGSRC["Telegram Signal<br/>Source"]
    end
    subgraph api["Public API Layer"]
        APIGW["API Gateway<br/>HOST_API"]
    end
    subgraph core["Core Trading Engine"]
        TRAD["Trading Engine<br/>bot_manager.php"]
        SIG["Signal Processor<br/>ext_signals.php"]
    end
    subgraph db["Data Layer"]
        PGSQL["PostgreSQL<br/>Orders / Positions"]
    end
    subgraph admin["Admin Interface"]
        PANEL["Admin Dashboard<br/>admin_trade.php"]
    end
    
    BIN -->|REST| TRAD
    TGSRC -->|Webhook| SIG
    SIG -->|execute| TRAD
    TRAD -->|query/store| PGSQL
    APIGW -->|proxy| TRAD
    PANEL -->|query/update| PGSQL
    APIGW -->|serve| PANEL
```

**Legend:**
- `EXCHANGE_X` = Live trading exchange (Binance/Bitfinex/etc).
- `HOST_API` = Public API endpoint for internal tools and integrations.
- `bot_manager.php` = Central orchestrator for trade execution and position monitoring.
- `ext_signals.php` = External signal intake and normalization.
- `admin_trade.php` = Admin trading dashboard and controls.

---

## Diagram 2: Trade Execution Flow

```mermaid
sequenceDiagram
    actor TS as Telegram Signal
    participant SIG as Signal Processor<br/>(ext_signals.php)
    participant ENG as Trading Engine<br/>(bot_manager.php)
    participant ACC as Account Validator<br/>(common.php)
    participant EXCH as Exchange REST<br/>(impl_EXCHANGE_X.php)
    participant DB as Database<br/>(PostgreSQL)
    
    TS->>SIG: send signal (pair, size, side)
    SIG->>SIG: normalize pair
    SIG->>ENG: queue trade request
    ENG->>ACC: validate account permissions
    ACC->>DB: fetch account_A rights
    DB-->>ACC: rights confirmed
    ACC-->>ENG: proceed
    ENG->>EXCH: POST /trade request
    EXCH-->>EXCH: execute on exchange
    EXCH-->>ENG: {"order_id": "...", "status": "filled"}
    ENG->>DB: record order + position
    DB-->>ENG: success
    ENG-->>TS: confirm (via admin panel)
    
```

**Steps:**
1. External signal arrives via Telegram webhook.
2. Signal processor normalizes asset pair.
3. Trade engine validates caller account permissions.
4. Permission check fetches account rights from database.
5. If approved, exchange REST adapter executes trade.
6. Order and position recorded in database.
7. Confirmation visible in admin dashboard.

---

## Diagram 3: Deployment Architecture (Sanitized)

```mermaid
graph TB
    subgraph deploy["Production Deployment"]
        LB["Load Balancer<br/>nginx"]
        WEB["Frontend<br/>HOST_WEB<br/>Vue/Nuxt"]
        API["API Service<br/>HOST_API<br/>PHP-FPM"]
        JOB["Job Workers<br/>bot_manager.php<br/>cron tasks"]
        CACHE["Redis<br/>Session/Cache"]
    end
    subgraph storage["Persistent Storage"]
        MAINDB["Main DB<br/>PostgreSQL<br/>orders, positions, users"]
        TRADEDB["Trading DB<br/>PostgreSQL<br/>account_scoped"]
    end
    subgraph external["External"]
        EXCH1["EXCHANGE_X"]
        TGRAM["Telegram"]
    end
    
    LB -->|HTTP/S| WEB
    LB -->|HTTP/S| API
    API -->|TCP| MAINDB
    API -->|TCP| TRADEDB
    API -->|TCP| CACHE
    JOB -->|TCP| MAINDB
    JOB -->|TCP| TRADEDB
    JOB -->|REST| EXCH1
    TGRAM -->|Webhook| API
    WEB -->|API calls| API
```

**Notes:**
- Frontend and API share load balancer.
- Main database hosts core trading state (orders, positions, users).
- Separate trading database for account-scoped operations.
- Redis for session and cache layer.
- Job workers (bot_manager.php via cron) execute scheduled tasks and rebalancing.
- Both databases replicated for DR (not shown).
