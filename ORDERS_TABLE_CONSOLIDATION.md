# Bot Orders Table Schema Consolidation

## Purpose  
Consolidate all order-related table schemas (mm_exec, pending_orders, mm_limit, lost_orders, matched_orders, etc.) into a single unified template to prevent schema drift and UNION query failures.

## Problem Addressed
The production database has diverged from DDL templates, causing:
- dashboard.php UNION query failures: "The used SELECT statements have a different number of columns"
- Silent data loss if columns are missing in one table
- Different column ordering breaking application assumptions
- Multiple schema definitions scatter throughout bot_tables.sql

## Solution
Created `templates/bot_orders_table.sql` with unified schema:

```sql
CREATE TABLE IF NOT EXISTS `#exchange__#tabletype_orders` (
    -- Standard fields common to ALL order tables
    id              INT UNSIGNED
    host_id         INT UNSIGNED
    predecessor     INT
    ts              TIMESTAMP(3)
    ts_fix          TIMESTAMP
    account_id      INT
    pair_id         INT
    batch_id        INT
    signal_id       INT(10)
    avg_price       FLOAT
    avg_pos_price   DOUBLE
    init_price      DOUBLE
    price           DOUBLE(16,8)
    amount          DECIMAL(16,8)
    buy             TINYINT(1)
    matched         DECIMAL(16,8)
    order_no        VARCHAR(40)  ← CRITICAL: Universal format (supports int or string IDs)
    status          VARCHAR(16)
    flags           INT UNSIGNED
    in_position     DECIMAL(16,8)
    out_position    FLOAT
    comment         VARCHAR(64)
    updated         TIMESTAMP(3)
    
    -- Comprehensive indexing for query performance
    INDEX idx_account_id
    INDEX idx_batch_id
    INDEX idx_order_no
    INDEX idx_pair_id  
    INDEX idx_ts
    INDEX idx_ts_fix
)
```

## Key Changes from Original bot_tables.sql

| Aspect | Original | Unified |
|--------|----------|---------|
| **order_no type** | Varies: BIGINT UNSIGNED (mm_exec) vs VARCHAR(40) (other_orders) | VARCHAR(40) (universal) |
| **amount DEFAULT** | Inconsistent (0, 0.00000000, NULL) | Consistent: 0 |
| **matched DEFAULT** | Inconsistent | Consistent: 0 |
| **avg_pos_price** | Present in some | Present in all |
| **Indexing** | Inconsistent | Comprehensive & unified |
| **ON UPDATE** | Not present | TIMESTAMP ON UPDATE |

## Migration Path

### Phase 1: Template Creation (DONE)
- ✅ Created bot_orders_table.sql with unified schema
- ✅ Documented schema consolidation rules

### Phase 2: Update bot_tables.sql (PENDING)
- Replace individual table definitions with includes/uses of unified template
- OR: Generate bot_tables.sql from bot_orders_table.sql by instantiating for each table type:
  - mm_exec_orders
  - pending_orders  
  - mm_limit_orders
  - mm_asks_orders
  - mm_bids_orders
  - lost_orders
  - matched_orders
  - other_orders
  - mixed_orders

### Phase 3: Update OrdersBlock Class (PENDING)
Create wrapper in bot_creator.php or new OrdersTableManager class:
```php
function CreateOrdersTable($mysqli, $exchange, $table_type) {
    $tpl = file_get_contents(__DIR__ . '/../templates/bot_orders_table.sql');
    $sql = strtr($tpl, ['#exchange' => $exchange, '#tabletype' => $table_type]);
    return $mysqli->query($sql);
}
```

### Phase 4: DB Schema Alignment (PENDING)
For each exchange with diverged schema:
```sql
-- Backup old table
CREATE TABLE {table}_backup LIKE {table};
INSERT INTO {table}_backup SELECT * FROM {table};

-- Drop and recreate from unified template
DROP TABLE {table};

-- Re-create from unified template with correct columns
-- ... execute unified DDL ...

-- Verify UNION queries work
SELECT * FROM (mm_exec) UNION SELECT * FROM (pending_orders);
```

### Phase 5: Verification
Once all tables use unified schema:
1. Test UNION queries in dashboard.php
2. Remove diagnostic logging from dashboard.php
3. Use proper SQL UNION with explicit column list
4. Update SCHEMA_ALIGNMENT_FIXME.md to mark as RESOLVED

## Benefits of Consolidation
- ✅ Prevents UNION query failures
- ✅ Guarantees schema consistency across exchanges
- ✅ Single source of truth for orders table structure
- ✅ Easier to maintain and update schema
- ✅ Reduced chance of deployment bugs
- ✅ Clear migration path for adding new exchanges

## Files Involved
- `templates/bot_orders_table.sql` — Unified template
- `templates/bot_tables.sql` — Original file (to be refactored)
- `src/lib/bot_creator.php` — Uses bot_tables.sql
- `src/lib/trading_core.php` — Also references bot_tables.sql
- `src/web-ui/dashboard.php` — Currently has schema mismatch workaround
- `SCHEMA_ALIGNMENT_FIXME.md` — Related to this issue

## Timeline
- **Short-term**: Create unified template (✅ DONE)
- **Medium-term**: Refactor bot_tables.sql to use unified template
- **Medium-term**: Update bot_creator.php for unified usage
- **Long-term**: Migrate existing production databases
- **Long-term**: Remove workarounds from dashboard.php
