# Exchange Database Recovery Guide

## Overview
This document describes how to recover exchange database configurations using clean templates. After data loss incident (2026-04-13), these minimal templates provide a starting point with only essential BTC/ETH pairs configured.

## Templates Available
- `datafeed-binance-clean.sql` — Binance BTC/ETH USDC pairs
- `datafeed-bitfinex-clean.sql` — Bitfinex BTC/ETH USD pairs  
- `datafeed-bitmex-clean.sql` — BitMEX BTC/ETH USD perpetuals
- `datafeed-bybit-clean.sql` — Bybit BTC/ETH USDT pairs
- `datafeed-deribit-clean.sql` — Deribit BTC/ETH USD perpetuals

## Pair Configuration

Each template creates three tables per exchange DB:

### 1. `cross_pairs` — Multi-asset pairs
| ticker | base_id | quote_id | flags |
|--------|---------|----------|-------|
| btcusd | 5       | 1        | 0     |
| ethusd | 8       | 1        | 0     |

**Note**: `pair_id` references refer to the main `trading.pairs` table, not shown in these templates.

### 2. `data_config` — Data load settings
| id_ticker | load_candles | load_depth | load_ticks |
|-----------|--------------|------------|-----------|
| 1         | 2            | 0          | 0         |
| 3         | 2            | 0          | 0         |

**Load settings**:
- `load_candles = 2` — Load historical candles for this price tier
- `load_depth = 0` — Do not load order book depth
- `load_ticks = 0` — Do not load tick data

### 3. `ticker_map` — Exchange symbol mapping
Each exchange uses different symbol conventions:

**Binance**: 
- BTC → `BTCUSDC` (spot)
- ETH → `ETHUSDC` (spot)

**Bitfinex**:
- BTC → `tBTCUSD` (t-prefix standard)
- ETH → `tETHUSD`

**BitMEX**:
- BTC → `XBTUSD` (legacy Bitcoin code)
- ETH → `ETHUSD`

**Bybit**:
- BTC → `BTCUSDT` (perpetual)
- ETH → `ETHUSDT`

**Deribit**:
- BTC → `BTC-USD-PERPETUAL` (labeled format)
- ETH → `ETH-USD-PERPETUAL`

## Recovery Steps

### Step 1: Backup existing data (if any)
```bash
cd /p/opt/docker/trading-platform-php
docker-compose exec -T mariadb mariadb-dump \
    -utrading -p"${MARIADB_PASSWORD:-trading}" \
    binance > var/backup/binance-before-recovery.sql
```

### Step 2: Apply clean template
For **Binance**:
```bash
zcat templates/datafeed-binance-clean.sql | \
    docker-compose exec -T mariadb mariadb \
    -utrading -p"${MARIADB_PASSWORD:-trading}" binance
```

For **other exchanges** (replace `EXCHANGE` with bitfinex, bitmex, bybit, deribit):
```bash
zcat templates/datafeed-EXCHANGE-clean.sql | \
    docker-compose exec -T mariadb mariadb \
    -utrading -p"${MARIADB_PASSWORD:-trading}" EXCHANGE
```

### Step 3: Verify tables created
```bash
docker-compose exec -T mariadb mariadb \
    -utrading -p"${MARIADB_PASSWORD:-trading}" binance \
    -e "SELECT COUNT(*) as ticker_count FROM ticker_map; \
        SELECT COUNT(*) as config_count FROM data_config;"
```

Expected output:
```
ticker_count
2
config_count
2
```

## Important Notes

### Limitations
- These templates contain **only 2 trading pairs per exchange** (BTC and ETH)
- **No historical price data** — only configuration tables
- **No order history** — archive/pending/position tables are empty
- **Load configuration set to minimal** — download only candles, skip depth/ticks

### Next Steps
After recovery:
1. Verify bot can connect to exchange API with these configurations
2. Run datafeed workers to populate candle history
3. Test trading signals on minimal pair set
4. Add additional pairs as needed (modify `ticker_map` and `data_config`)

### Adding More Pairs
To add a new pair (e.g., LTCUSD on Binance):

1. Insert into `ticker_map`:
```sql
INSERT INTO `ticker_map` (`id`, `ticker`, `symbol`, `pair_id`)
VALUES (4, 'ltcusd', 'LTCUSDC', 12);
```

2. Configure data loading:
```sql
INSERT INTO `data_config` (`id_ticker`, `load_candles`, `load_depth`, `load_ticks`)
VALUES (4, 2, 0, 0);
```

3. Restart datafeed worker for the exchange

### Emergency Data Preservation
If you have partial recovery backups or configuration exports, preserve them in `var/backup/`:
```bash
# Archive recovery metadata
tar czf var/backup/recovery-metadata-$(date +%Y%m%d).tar.gz \
    templates/datafeed-*-clean.sql \
    docs/EXCHANGE_DB_RECOVERY.md
```

## Support & Troubleshooting

**Problem**: Cannot connect to exchange DB after recovery
- Check credentials in `.env` match database user password
- Verify database and user exist: `mysql -u[user] -p -e "SHOW DATABASES;"`

**Problem**: Ticker symbols not recognized by datafeed
- Compare `symbol` values in template against actual exchange API documentation
- Exchange symbol formats differ (Binance uses XXUSDC, BitMEX uses XBTUSD, etc.)

**Problem**: Data still not loading
- Check `data_config` row exists for ticker with `load_candles > 0`
- Verify datafeed worker is running and logs show no symbol errors
- Check bot configuration file points to correct database schema

## Reference
- Parent documentation: [docs/BACKUP_STRATEGY.md](BACKUP_STRATEGY.md)
- Backup workflows: [scripts/prepare-clean-deploy.sh](../scripts/prepare-clean-deploy.sh)
- Restore procedures: [scripts/restore-from-backup.sh](../scripts/restore-from-backup.sh)
