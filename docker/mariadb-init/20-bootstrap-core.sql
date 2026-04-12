-- Auto-generated from trading-structure.sql
-- Source: /work/trading-structure.sql
-- Table list: /work/shell/bootstrap-core-tables.txt

CREATE DATABASE IF NOT EXISTS `trading`;
USE `trading`;
SET FOREIGN_KEY_CHECKS = 0;

-- config__hosts
CREATE TABLE `config__hosts` (
  `id` int(11) NOT NULL,
  `host` varchar(32) NOT NULL,
  `last_alive` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `priority` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `config__hosts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `host` (`host`);

ALTER TABLE `config__hosts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- config__table_map
CREATE TABLE `config__table_map` (
  `table_name` varchar(16) NOT NULL,
  `account_id` int(11) NOT NULL DEFAULT 0,
  `applicant` varchar(16) NOT NULL,
  PRIMARY KEY (`applicant`),
  UNIQUE KEY `uq_table_name` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

-- config__bot_manager
CREATE TABLE `config__bot_manager` (
  `account_id` int(11) NOT NULL DEFAULT 0,
  `param` varchar(32) NOT NULL,
  `value` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

ALTER TABLE `config__bot_manager`
  ADD UNIQUE KEY `strictor` (`account_id`,`param`);

INSERT INTO `config__bot_manager` (`account_id`, `param`, `value`) VALUES
  (0, 'backup_enabled', '1'),
  (0, 'backup_time_utc', '23:59'),
  (0, 'backup_dir', '/var/backup/mysql'),
  (0, 'backup_retention_days', '7')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- bot__activity
CREATE TABLE `bot__activity` (
  `ts` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_start` timestamp NOT NULL DEFAULT current_timestamp(),
  `applicant` varchar(16) NOT NULL,
  `account_id` int(11) NOT NULL,
  `funds_usage` float NOT NULL DEFAULT 0,
  `uptime` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` varchar(256) COMPRESSED NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

ALTER TABLE `bot__activity`
  ADD PRIMARY KEY (`applicant`,`account_id`);

-- bot__orders_ids
CREATE TABLE `bot__orders_ids` (
  `order_id` int(11) UNSIGNED NOT NULL,
  `ts` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  `account_id` int(11) DEFAULT NULL,
  `busy` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='For global unique order id generation';

ALTER TABLE `bot__orders_ids`
  ADD PRIMARY KEY (`order_id`);

ALTER TABLE `bot__orders_ids`
  MODIFY `order_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

-- bot__redudancy
CREATE TABLE `bot__redudancy` (
  `exchange` varchar(16) NOT NULL,
  `account_id` int(11) NOT NULL,
  `master_host` varchar(16) NOT NULL,
  `master_pid` int(11) NOT NULL,
  `errors` int(11) NOT NULL DEFAULT 0,
  `ts_alive` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(32) NOT NULL,
  `uptime` int(11) NOT NULL,
  `reserve_status` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

ALTER TABLE `bot__redudancy`
  ADD PRIMARY KEY (`exchange`,`account_id`);


SET FOREIGN_KEY_CHECKS = 1;
