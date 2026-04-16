# Schema Alignment Fix for deribit__mm_exec and deribit__pending_orders

## Problem
The UNION query in dashboard.php fails with: 
"The used SELECT statements have a different number of columns"

This indicates that `deribit__mm_exec` and `deribit__pending_orders` have different column structures in the actual database, despite having identical definitions in the SQL template.

## Root Cause Analysis
1. Template (`templates/trading-structure.sql`) defines both tables identically with 22 columns
2. Production database has diverged from the template (possible old migrations not applied, or manual alterations)
3. This schema mismatch can cause:
   - Silent data loss (columns missing in one table)
   - Incorrect trade results if columns are in different order
   - Application errors when accessing missing columns

## Current Workaround (dashboard.php)
- Fetches from both tables separately
- Merges results in PHP
- Logs schema differences to server error_log for diagnosis

## How to Find the Exact Difference

```sql
-- List all columns in mm_exec
SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='trading' AND TABLE_NAME='deribit__mm_exec'
ORDER BY ORDINAL_POSITION;

-- List all columns in pending_orders
SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='trading' AND TABLE_NAME='deribit__pending_orders'
ORDER BY ORDINAL_POSITION;

-- Show difference
SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) as mm_exec_cols
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='trading' AND TABLE_NAME='deribit__mm_exec';

SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) as pending_cols
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA='trading' AND TABLE_NAME='deribit__pending_orders';
```

## Proper Fix Strategy

Once you've identified which columns differ:

### Option 1: Align to template (if template is correct)
```sql
-- Backup the tables
CREATE TABLE deribit__mm_exec_backup LIKE deribit__mm_exec;
INSERT INTO deribit__mm_exec_backup SELECT * FROM deribit__mm_exec;

-- Drop and recreate with correct structure
DROP TABLE deribit__mm_exec;
DROP TABLE deribit__pending_orders;

-- Re-create from template
SOURCE templates/trading-structure.sql;

-- Restore data (adjust SELECT list to match old structure)
INSERT INTO deribit__mm_exec (...) SELECT ... FROM deribit__mm_exec_backup;
```

### Option 2: Alter existing tables in-place
If the DBs are identical except for column order or missing/extra columns:

```sql
-- Add missing columns
ALTER TABLE deribit__mm_exec ADD COLUMN missing_col_name TYPE;
ALTER TABLE deribit__pending_orders ADD COLUMN missing_col_name TYPE;

-- Drop extra columns if needed
ALTER TABLE deribit__mm_exec DROP COLUMN extra_col;
ALTER TABLE deribit__pending_orders DROP COLUMN extra_col;

-- Reorder columns to match (more complex, may need recreate)
-- See Option 1 if reordering is needed
```

## Verification After Fix

Once aligned:
```sql
-- This should now work without error
SELECT * FROM `deribit__mm_exec` WHERE account_id = 81081
UNION
SELECT * FROM `deribit__pending_orders` WHERE account_id = 81081
ORDER BY pair_id ASC, updated DESC;
```

Then update dashboard.php to use the proper UNION query:
```php
$union_query = "(SELECT * FROM $m_table WHERE account_id = $account_id) 
    UNION 
    (SELECT * FROM $p_table WHERE account_id = $account_id)
    ORDER BY pair_id ASC, updated DESC";
$orders = $mysqli->select_all($union_query);
```

## Timeline for Fix
1. **Immediate**: Keep workaround in place (current state)
2. **Short-term**: Run diagnostic SQL queries to identify exact differences
3. **Medium-term**: Plan schema alignment strategy
4. **Long-term**: Implement proper fix and remove workaround
