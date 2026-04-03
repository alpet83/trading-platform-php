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
        APIGW["API Gateway<br/>signals-server"]
    end
    subgraph core["Core Trading Engine"]
        TRAD["Trading Engine<br/>bot_manager.php"]
        SIG["Signal Processor<br/>ext_signals.php"]
    end
    subgraph db["Data Layer"]
        MARIADB["MariaDB<br/>Orders / Positions"]
    end
    subgraph admin["Admin Interface"]
        PANEL["Admin Dashboard<br/>web-ui/basic-admin.php"]
    end
    
    BIN -->|REST| TRAD
    TGSRC -->|Webhook| SIG
    SIG -->|execute| TRAD
    TRAD -->|query/store| MARIADB
    APIGW -->|request| SIG
    PANEL -->|query/update| MARIADB
    PANEL -->|API calls| APIGW
```

**Legend:**
- `EXCHANGE_X` = Live trading exchange (Binance/Bitfinex/BitMEX/Deribit/Bybit).
- `signals-server` = Public API endpoint for internal tools and integrations.
- `bot_manager.php` = Central orchestrator for trade execution and position monitoring.
- `ext_signals.php` = External signal intake and normalization.
- `web-ui/basic-admin.php` = Admin trading dashboard and controls.

---

## Diagram 2: Trade Execution Flow

```mermaid
sequenceDiagram
    actor TS as Telegram Signal
    participant SIG as Signal Processor<br/>(ext_signals.php)
    participant ENG as Trading Engine<br/>(bot_manager.php)
    participant ACC as Account Validator<br/>(common.php)
    participant EXCH as Exchange REST<br/>(impl_EXCHANGE_X.php)
    participant DB as Database<br/>(MariaDB)
    
    TS->>SIG: send signal (pair, size, side)
    SIG->>SIG: normalize pair
    SIG->>ENG: queue trade request
    ENG->>ACC: validate account permissions
    ACC->>DB: fetch account_A rights
    DB-->>ACC: rights confirmed
    ACC-->>ENG: proceed
    ENG->>EXCH: POST /trade request
    EXCH-->>EXCH: execute order
    EXCH-->>ENG: {"order_id": "...", "status": "filled"}
    ENG->>DB: record order + position
    DB-->>ENG: success
    ENG-->>SIG: execution result
    SIG-->>TS: delivery status
    
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

## Diagram 3: Deployment Architecture (Docker)

```mermaid
graph TB
    subgraph docker["Docker Compose Stack"]
        MARIADB["mariadb<br/>MariaDB 11"]
        WEB["web<br/>PHP-FPM"]
        BOTSHIVE["bots-hive<br/>bot_manager.php"]
        DATAFEED["datafeed<br/>datafeed_manager.php"]
        PHPMYADMIN["phpmyadmin<br/>phpMyAdmin"]
        GPGAGENT["gpg-agent<br/>Password Store"]
    end
    subgraph external["External"]
        EXCH1["EXCHANGE_X<br/>REST API"]
        TGRAM["Telegram Bot"]
        SIGSRC["Signal Source"]
    end
    
    WEB -->|3306| MARIADB
    BOTSHIVE -->|3306| MARIADB
    DATAFEED -->|3306| MARIADB
    BOTSHIVE -->|REST| EXCH1
    WEB -->|HTTP| SIGSRC
    DATAFEED -->|HTTP| SIGSRC
    TGRAM -->|Webhook| SIGSRC
```

**Notes:**
- Compose runtime uses one MariaDB service (replication depends on external setup).
- bots-hive runs bot_manager.php for trade execution.
- datafeed runs datafeed_manager.php for market data ingestion.
- web serves PHP admin interface.
- gpg-agent can be used for encrypted secret storage where configured.
- phpmyadmin for database administration.

---

## Diagram 4: API Secret Delivery Pipeline

```mermaid
sequenceDiagram
    participant SRC as Secret Source<br/>(DB or pass)
    participant BM as BotManager<br/>(bot_manager.php)
    participant ENV as Process ENV<br/>(BMX_API_SECRET, __CREDS_FROM_ENV)
    participant CORE as RestAPIEngine<br/>(InitializeAPIKey)
    participant EX as Exchange Engine<br/>(impl_bitmex / impl_bybit / ...)
    participant SIG as Sign Method<br/>(SignRequest)

    SRC->>BM: plaintext API secret
    BM->>BM: encode to base64 for process handoff
    BM->>ENV: export *_API_SECRET + __CREDS_FROM_ENV=1
    ENV->>CORE: read secret payload
    CORE->>EX: pass secret without forced global decode
    EX->>SIG: exchange-specific decode/prep
    SIG->>SIG: HMAC signature build

    Note over CORE,EX: Decode must happen once per request in exchange-specific signing path
```

**Operational invariants:**
- No universal forced decode in `InitializeAPIKey` for all exchanges.
- Decode is owned by exchange-specific signing flow (`SignRequest` or equivalent).
- Any change in secret transport format requires smoke checks for at least BitMEX and Bybit.
