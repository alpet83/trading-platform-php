-- Orders table prototype used by OrderList::__construct when a runtime table must be created.
-- The placeholder `__TABLE_NAME__` is replaced by PHP before execution.
-- Schema mirrors the canonical `{exchange}__pending_orders` definition from trading-structure.sql.

CREATE TABLE IF NOT EXISTS `__TABLE_NAME__` (
  `id`          int(10) UNSIGNED NOT NULL,
  `host_id`     int(10) UNSIGNED NOT NULL DEFAULT 0,
  `predecessor` int(11)          NOT NULL DEFAULT 0,
  `ts`          timestamp(3)     NOT NULL DEFAULT current_timestamp(3),
  `ts_fix`      timestamp        NULL     DEFAULT NULL COMMENT 'Time when status is fixed',
  `account_id`  int(11)          NOT NULL,
  `pair_id`     int(11)          NOT NULL,
  `batch_id`    int(11)                   DEFAULT 0,
  `signal_id`   int(10)          NOT NULL DEFAULT 0,
  `avg_price`   float            NOT NULL DEFAULT 0,
  `init_price`  double           NOT NULL DEFAULT 0 COMMENT 'for better slippage calculation',
  `price`       double(16,8)     NOT NULL,
  `amount`      decimal(16,8)             DEFAULT 0.00000000,
  `buy`         tinyint(1)       NOT NULL,
  `matched`     decimal(16,8)    NOT NULL,
  `order_no`    bigint(20) UNSIGNED NOT NULL,
  `status`      varchar(16)      NOT NULL,
  `flags`       int(10) UNSIGNED NOT NULL,
  `in_position` decimal(16,8)    NOT NULL,
  `out_position` float           NOT NULL DEFAULT 0 COMMENT 'After order updated',
  `comment`     varchar(64)      NOT NULL,
  `updated`     timestamp(3)     NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`),
  KEY `batch_id`   (`batch_id`),
  KEY `order_no`   (`order_no`) USING BTREE,
  KEY `pair_id`    (`pair_id`),
  KEY `ts`         (`ts`),
  KEY `ts_fix`     (`ts_fix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
