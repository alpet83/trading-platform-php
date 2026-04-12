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

## Runtime bot table template: `src/sql/bot_tables.sql`

New bot-scoped tables that must exist both at creation time **and** at runtime are defined
as templates in `src/sql/bot_tables.sql`.  
The placeholder `#exchange` is substituted at execution time with the actual exchange prefix
(e.g. `bybit_bot`, `bitmex`).

```sql
-- example entry in bot_tables.sql
CREATE TABLE IF NOT EXISTS `#exchange__exec_context` (
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    ts           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    context_json MEDIUMTEXT NOT NULL,
    PRIMARY KEY (account_id, pair_id),
    KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Two code paths load the template — both must stay in sync:**

| Path | When | How |
|------|------|-----|
| `TradingCore::EnsureBotTables()` | Bot startup (`Initialize()`) | `str_replace('#exchange', $engine->exchange, ...)` |
| `BotCreator::Create()` (lib/bot_creator.php) | Web UI bot creation | same substitution, runs in same DB transaction |

**Rule:** When adding a new bot-scoped table, add only one `CREATE TABLE IF NOT EXISTS \`#exchange__...\`` block to `bot_tables.sql`.  
Do **not** duplicate it in `bot_creator.php`'s `$tables_to_create` array — that array is for
tables whose DDL cannot share the unified template pattern.

## Practical bootstrap strategy

1. Apply mandatory baseline tables.
2. Use instance API bot creation flow to create per-bot config and major per-prefix tables
   (this also applies `bot_tables.sql` via `BotCreator::Create()`).
3. Validate runtime — `TradingCore::EnsureBotTables()` will idempotently ensure any
   template tables that survived restarts or were added after creation.
4. Only then add extra analytical tables from the dump.

This keeps bootstrap small while preserving a canonical source of truth in `trading-structure.sql`.