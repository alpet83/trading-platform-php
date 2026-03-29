# Architecture Diagrams (Sanitized)

## 1. High-Level Subcomponent Structure
```mermaid
flowchart LR
    UI[Web UI Layer\nweb-ui/*] --> API[API Layer\nroute_api.php + handlers]
    API --> CORE[Trading Core\ntrading_engine.php\ntrading_core.php]
    CORE --> EX1[EXCHANGE_X Adapter]
    CORE --> EX2[EXCHANGE_Y Adapter]
    CORE --> DB[(State Store)]
    CORE --> EVT[Event Sender\nevent_sender.php]
    EVT --> OBS[Observability\nHOST_CORE / SERVICE_API]
```

## 2. Trading Signal Data Flow
```mermaid
sequenceDiagram
    participant Src as Signal Source
    participant In as ext_signals.php
    participant Core as trading_engine.php
    participant Exec as Exchange Adapter
    participant Rep as trades_report.php

    Src->>In: New signal payload
    In->>Core: Normalized signal (ACCOUNT_A scope)
    Core->>Core: Validate risk and config
    Core->>Exec: Place/adjust/cancel order
    Exec-->>Core: Execution status
    Core->>Rep: Persist trade outcome
    Rep-->>Src: Sanitized status summary
```

## 3. Deployment and Validation Cycle
```mermaid
flowchart TD
    A[Preflight\nconfig + masking checks] --> B[Build/Package]
    B --> C[Deploy to HOST_CORE]
    C --> D[Smoke Tests\nAPI + signal path]
    D --> E{Pass?}
    E -- Yes --> F[Publish docs/wiki]
    E -- No --> G[Rollback]
    G --> H[Fix + re-validate]
    H --> B
```

## Notes
- Use placeholders in all shared diagrams and docs.
- Keep environment-specific values in private operational docs only.
