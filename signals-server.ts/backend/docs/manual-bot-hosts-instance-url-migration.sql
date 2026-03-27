-- One-off manual migration.
-- Do not wire this into runtime startup or automated migrations.
-- Run once against the trading database before deploying code that expects bot_hosts.instance_url.

ALTER TABLE bot_hosts
  CHANGE COLUMN stats_url instance_url VARCHAR(255) NOT NULL;

-- Verification:
-- SHOW COLUMNS FROM bot_hosts LIKE 'instance_url';
-- SHOW COLUMNS FROM bot_hosts LIKE 'stats_url';