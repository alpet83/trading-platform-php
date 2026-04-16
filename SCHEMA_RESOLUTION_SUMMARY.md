# Schema Mismatch Issue - Complete Resolution Plan

## Problem Discovery & Resolution Journey

### 1. Initial Issue (dashboard.php)
User reported: MySQL UNION query error in dashboard.php
```
ERROR: The used SELECT statements have a different number of columns
SELECT * FROM `deribit__mm_exec` UNION SELECT * FROM deribit__pending_orders WHERE (account_id = 81081)
```

### 2. Root Cause Analysis
Discovery that `deribit__mm_exec` and `deribit__pending_orders` have different column structures in production database (despite identical definitions in template).

### 3. Initial Approach (REJECTED as inadequate)
- Tried UNION query fix: Failed because MySQL cannot parse subquery as table name
- Implemented PHP-level workaround: Fetches separately, merges in PHP
  - ⚠️ **Problem**: Masks the underlying schema drift without fixing it
  - ⚠️ **Risk**: Can cause silent data loss if different columns are used

### 4. Corrected Approach (CURRENT)
Implemented three-layer diagnostic and resolution strategy:

#### Layer 1: Immediate Diagnostics (✅ DONE)
- File: `src/web-ui/dashboard.php`
- Added error_log() output that captures:
  - Actual column count in each table
  - Complete column list for both tables
  - When mismatch detected, exact differences logged
- Purpose: Identify exact schema divergence when dashboard runs

#### Layer 2: Schema Alignment Instructions (✅ DONE)
- File: `SCHEMA_ALIGNMENT_FIXME.md`
- Contains:
  - SQL queries to diagnose exact differences
  - Two strategies to fix (Option 1: Recreate, Option 2: ALTER)
  - Verification steps
  - Timeline for implementation

#### Layer 3: Schema Consolidation (✅ DONE)
- File: `templates/bot_orders_table.sql` - Unified template
- File: `ORDERS_TABLE_CONSOLIDATION.md` - Implementation roadmap
- File: `templates/bot_tables.sql` - Added deprecation notice
- Purpose: Prevent future schema drift by enforcing single source of truth

## Architectural Solution Details

### Unified bot_orders_table.sql
Consolidates all order table schemas with:
- **order_no**: VARCHAR(40) (universal format for BIGINT strings OR custom Deribit IDs)
- **Consistent defaults**: amount=0, matched=0 (all tables)
- **Comprehensive indexing**: account_id, batch_id, order_no, pair_id, ts, ts_fix
- **Timestamp management**: ON UPDATE CURRENT_TIMESTAMP(3) for automatic tracking
- **Universal columns**: avg_pos_price present in ALL tables

### Migration Strategy (5 Phases)

| Phase | Status | Action | Timeline |
|-------|--------|--------|----------|
| 1. Template Creation | ✅ DONE | Created unified bot_orders_table.sql | Completed |
| 2. bot_tables.sql Refactor | ⏳ PENDING | Replace individual defs with unified | 1-2 weeks |
| 3. OrdersBlock Integration | ⏳ PENDING | Update bot_creator.php to use template | 1-2 weeks |
| 4. DB Migration | ⏳ PENDING | Align production schema to unified template | As-needed |
| 5. Validation & Cleanup | ⏳ PENDING | Remove workarounds, verify UNION works | 1 week |

## Files Involved in Resolution

```
Runtime Repo Root: /p/opt/docker/trading-platform-php/
├── templates/
│   ├── bot_tables.sql (updated - added deprecation notice)
│   ├── bot_orders_table.sql (NEW - unified template)
│   └── trading-structure.sql (existing - deribit specific)
├── src/
│   ├── web-ui/dashboard.php (updated - added diagnostics)
│   ├── lib/bot_creator.php (uses bot_tables.sql - to be refactored)
│   └── lib/trading_core.php (uses bot_tables.sql - to be refactored)
├── SCHEMA_ALIGNMENT_FIXME.md (NEW - diagnostic guide)
├── ORDERS_TABLE_CONSOLIDATION.md (NEW - implementation roadmap)
└── OTHER CHANGES:
    ├── docker-compose.override.yml (UTF-8 encoding fix)
    └── src/impl_deribit.php (prevent duplicate archive registration)
```

## Commits Created

1. **077378e** - fix: prevent duplicate archive order re-registration in bitfinex_bot
2. **e42d535** - fix: restore UTF-8 encoding in docker-compose.override.yml comments
3. **6940f72** - fix: dashboard.php orders query - workground (separate queries + merge in PHP)
4. **0d22254** - fix: dashboard.php - add diagnostic logging for schema mismatch
5. **faa4643** - docs: add schema alignment fix instructions
6. **b8384fa** - refactor: create unified bot_orders_table.sql template
7. **0bb3827** - docs: add deprecation notice to bot_tables.sql

## How to Use This Resolution

### For Developers
1. Read ORDERS_TABLE_CONSOLIDATION.md - understand the problem and solution
2. Read SCHEMA_ALIGNMENT_FIXME.md - understand how to diagnose mismatch
3. Use diagnostic SQL queries to check current DB schema divergence
4. Plan migration strategy based on current schema state

### For Operations/Infrastructure
1. Run SQL queries from SCHEMA_ALIGNMENT_FIXME.md to identify divergence
2. Decide migration timing (recreate vs ALTER)
3. Execute schema alignment on production DBs
4. Verify UNION queries work after migration

### For QA/Testing
1. Test UNION queries on different exchanges after schema alignment
2. Verify dashboard.php displays correct order data
3. Check that no data loss occurred during migration
4. Confirm error_log diagnostics are clean

## Key Insights

### Why This Matters
1. **Trading Data Integrity**: Schema drift can cause missing columns in queries, affecting trade results
2. **Maintainability**: 9+ order table definitions scattered in bot_tables.sql increase entropy
3. **New Exchange Support**: Adding new exchange becomes risky when schema templates diverge
4. **Production Stability**: UNION errors mask deeper data access issues

### Architectural Principles Applied
- **Single Source of Truth**: One template for all order tables
- **Fail-Fast**: Diagnostics log actual problems instead of hiding them
- **Gradual Migration**: 5-phase approach minimizes risk
- **Documentation-First**: All decisions recorded for future reference

## Prevention Measures Going Forward

1. **Use unified template**: All new order tables created from bot_orders_table.sql
2. **Automated schema validation**: Test UNION queries in unit tests
3. **Deprecation notices**: Mark old scattered definitions clearly
4. **Code review guidelines**: Reject order table changes that don't reference unified template

## Status Summary

- ✅ Problem identified and root cause documented
- ✅ Immediate workaround in place with diagnostics
- ✅ Long-term solution designed (unified template)
- ✅ Migration roadmap created
- ⏳ Next: Implement phases 2-5 (bot_tables.sql refactor → database migration)

---

**Repository**: /p/opt/docker/trading-platform-php  
**Last Updated**: 2026-04-15  
**Responsible**: Automated schema consolidation initiative  
**Related Issues**: Schema mismatch in deribit__mm_exec / deribit__pending_orders (UNION query failure)
