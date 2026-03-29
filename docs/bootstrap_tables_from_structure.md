# Bootstrap Tables From trading-structure.sql

Date: 2026-03-28
Source dump: `trading-structure.sql` (project root)

## Purpose

This note defines how to derive bootstrap table sets from the current structure dump without relying on historical sigsys paths.

## Quick extraction

PowerShell:

```powershell
Select-String -Path trading-structure.sql -Pattern 'CREATE TABLE' |
  ForEach-Object { $_.Line.Trim() }
```

Semi-automatic generation:

```sh
sh shell/generate_bootstrap_schema.sh
```

Windows host fallback:

```powershell
powershell -ExecutionPolicy Bypass -File .\shell\generate_bootstrap_schema.ps1
```

Default output:
- `docker/mariadb-init/20-bootstrap-core.sql`

## Mandatory baseline tables

These are the first tables to ensure for instance API and bot runtime control:

- `config__hosts`
- `config__table_map`
- `bot__activity`
- `bot__orders_ids`
- `bot__redudancy`

Optional but commonly used global tables:

- `levels_map`
- `trader__sessions`
- `portfolios__map`
- `portfolios__positions`
- `portfolios__history`

## Per-exchange families in dump

The dump contains prefixed families (`binance__*`, `bitfinex__*`, `bitmex__*`, `deribit__*`).

Frequent runtime/API suffixes:

- `__archive_orders`
- `__batches`
- `__events`
- `__ext_signals`
- `__last_errors`
- `__lost_orders`
- `__matched_orders`
- `__other_orders`
- `__pending_orders`
- `__positions`
- `__position_history`
- `__pairs_map`
- `__tickers`
- `__ticker_map`

Additional exchange-specific analytical/aux tables may appear (`__summary`, `__wallet_history`, `__funding`, `__trades`, etc.) and can be enabled incrementally.

## Practical bootstrap strategy

1. Apply mandatory baseline tables.
2. Use instance API bot creation flow to create per-bot config and major per-prefix tables.
3. Validate runtime and only then add extra analytical tables from the dump.

This keeps bootstrap small while preserving a canonical source of truth in `trading-structure.sql`.