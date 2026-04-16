-- Unified order table template.
--
-- Placeholder #exchange is replaced with bot prefix (e.g. deribit, bybit, bitmex).
-- Placeholder #order_table is replaced with exact table suffix
-- (archive_orders, pending_orders, mm_exec, mm_limit, mm_asks, mm_bids, ...).
--
-- IMPORTANT:
-- - Template schema keeps order_no as BIGINT UNSIGNED.
-- - Exchange-specific string order IDs must be handled via runtime override/patching,
--   not by changing DDL templates.

CREATE TABLE IF NOT EXISTS `#exchange__#order_table` (
    id              INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id         INT UNSIGNED NOT NULL DEFAULT 0,
    predecessor     INT DEFAULT 0,
    ts              TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    ts_fix          TIMESTAMP NULL COMMENT 'Time when status is fixed',
    account_id      INT NOT NULL,
    pair_id         INT NOT NULL,
    batch_id        INT DEFAULT 0,
    signal_id       INT(10) NOT NULL DEFAULT 0,
    avg_price       FLOAT NOT NULL DEFAULT 0,
    avg_pos_price   DOUBLE NOT NULL DEFAULT 0,
    init_price      DOUBLE NOT NULL DEFAULT 0,
    price           DOUBLE(16,8) NOT NULL,
    amount          DECIMAL(16,8) NOT NULL DEFAULT 0,
    buy             TINYINT(1) NOT NULL,
    matched         DECIMAL(16,8) NOT NULL DEFAULT 0,
    order_no        BIGINT UNSIGNED NOT NULL,
    status          VARCHAR(16) NOT NULL,
    flags           INT UNSIGNED NOT NULL,
    in_position     DECIMAL(16,8) NOT NULL DEFAULT 0,
    out_position    FLOAT NOT NULL DEFAULT 0 COMMENT 'Position after order updated',
    comment         VARCHAR(64) NOT NULL,
    updated         TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX account_id (account_id),
    INDEX batch_id   (batch_id),
    INDEX order_no   (order_no),
    INDEX pair_id    (pair_id),
    INDEX ts         (ts),
    INDEX ts_fix     (ts_fix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
