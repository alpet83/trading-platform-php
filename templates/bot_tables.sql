-- Bot-scoped table templates.
-- Placeholder #exchange is replaced at runtime with the actual bot prefix
-- (e.g. bybit, bitmex, binance) before execution.
-- All statements use CREATE TABLE IF NOT EXISTS so they are safe to re-run.

CREATE TABLE IF NOT EXISTS `#exchange__pairs_map` (
    pair_id INT NULL,
    pair    VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__archive_orders` (
    id              INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id         INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor     INT DEFAULT 0 NOT NULL,
    ts              TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix          TIMESTAMP NULL COMMENT 'Time when status is fixed',
    account_id      INT NOT NULL,
    pair_id         INT NOT NULL,
    batch_id        INT DEFAULT 0 NULL,
    signal_id       INT(10) DEFAULT 0 NOT NULL,
    avg_price       FLOAT DEFAULT 0 NOT NULL,
    init_price      DOUBLE DEFAULT 0 NOT NULL,
    price           DOUBLE(16,8) NOT NULL,
    amount          DECIMAL(16,8) NOT NULL,
    buy             TINYINT(1) NOT NULL,
    matched         DECIMAL(16,8) NOT NULL,
    order_no        BIGINT UNSIGNED NOT NULL,
    status          VARCHAR(16) NOT NULL,
    flags           INT UNSIGNED NOT NULL,
    in_position     DECIMAL(16,8) NOT NULL,
    out_position    FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
    comment         VARCHAR(64) NOT NULL,
    updated         TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX batch_id   (batch_id),
    INDEX order_no   (order_no),
    INDEX pair_id    (pair_id),
    INDEX ts         (ts),
    INDEX ts_fix     (ts_fix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__batches` (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    account_id    INT NOT NULL,
    pair_id       INT NOT NULL,
    ts            TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    parent        INT DEFAULT 0 NOT NULL COMMENT 'relation to ext_signals',
    source_pos    FLOAT NULL COMMENT 'RAW copy-trading position',
    start_pos     FLOAT DEFAULT 0 NOT NULL,
    target_pos    DECIMAL(20,8) NOT NULL,
    price         FLOAT NOT NULL,
    exec_price    FLOAT DEFAULT 0 NULL,
    btc_price     FLOAT NULL,
    exec_amount   FLOAT DEFAULT 0 NOT NULL,
    exec_qty      FLOAT DEFAULT 0 NOT NULL COMMENT 'Natural quantity, not contracts',
    slippage      FLOAT DEFAULT 0 NULL,
    last_order    INT UNSIGNED NOT NULL,
    flags         INT DEFAULT 0 NOT NULL,
    INDEX account_id (account_id),
    INDEX flags      (flags),
    INDEX last_order (last_order),
    INDEX pair_id    (pair_id),
    INDEX parent     (parent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__deposit_history` (
    ts           TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL PRIMARY KEY,
    account_id   INT NOT NULL,
    withdrawal   TINYINT(1) DEFAULT 0 NOT NULL,
    value_btc    FLOAT NOT NULL,
    value_eth    FLOAT DEFAULT 0 NOT NULL,
    value_usd    FLOAT DEFAULT 0 NOT NULL,
    INDEX account_id (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__events` (
    ts         TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    account_id INT NOT NULL,
    host_id    INT DEFAULT 0 NOT NULL,
    event      VARCHAR(16) NOT NULL,
    message    VARCHAR(64) NULL,
    INDEX account_id (account_id),
    INDEX event      (event),
    INDEX ts         (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__exec_context` (
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    ts           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    context_json MEDIUMTEXT NOT NULL,
    PRIMARY KEY (account_id, pair_id),
    KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__ext_signals` (
    id            INT NOT NULL,
    account_id    INT DEFAULT 0 NOT NULL,
    buy           TINYINT(1) NOT NULL,
    pair_id       INT NOT NULL,
    ts            TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL,
    ts_checked    TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NULL,
    limit_price   DECIMAL(16,9) DEFAULT 0.000000000 NOT NULL,
    recalc_price  DOUBLE DEFAULT 0 NOT NULL,
    stop_loss     DECIMAL(16,9) DEFAULT 0.000000000 NOT NULL,
    take_profit   DECIMAL(16,9) DEFAULT 0.000000000 NOT NULL,
    take_order    INT DEFAULT 0 NOT NULL,
    limit_order   INT DEFAULT 0 NOT NULL,
    amount        INT NOT NULL,
    mult          INT NOT NULL,
    ttl           INT NOT NULL,
    flags         INT NOT NULL,
    open_coef     FLOAT NOT NULL,
    exec_prio     FLOAT DEFAULT 0 NOT NULL,
    setup         INT DEFAULT 0 NOT NULL,
    qty           INT DEFAULT 0 NOT NULL COMMENT 'Grid orders qty',
    active        TINYINT(1) NOT NULL,
    closed        TINYINT(1) NOT NULL,
    comment       VARCHAR(64) NULL,
    PRIMARY KEY (id, account_id),
    INDEX ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__funds_history` (
    ts             TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL ON UPDATE CURRENT_TIMESTAMP(),
    account_id     INT DEFAULT 0 NOT NULL,
    value          FLOAT NOT NULL,
    value_btc      FLOAT DEFAULT 0 NOT NULL,
    position_coef  FLOAT DEFAULT 0.01 NOT NULL,
    PRIMARY KEY (ts, account_id),
    INDEX account_id (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__last_errors` (
    ts         TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL PRIMARY KEY,
    account_id INT NOT NULL,
    host_id    INT NULL,
    code       INT NULL,
    message    VARCHAR(4096) DEFAULT '' NOT NULL,
    source     VARCHAR(32) NULL,
    backtrace  VARCHAR(1024) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__lost_orders` (
    id           INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT DEFAULT 0 NOT NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL COMMENT 'Time when status is fixed',
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL COMMENT 'for better slippage calculation',
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) DEFAULT 0.00000000 NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     BIGINT UNSIGNED NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX batch_id   (batch_id),
    INDEX order_no   (order_no),
    INDEX pair_id    (pair_id),
    INDEX ts         (ts),
    INDEX ts_fix     (ts_fix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__matched_orders` (
    id           INT(11) UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT DEFAULT 0 NOT NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL,
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL,
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) NOT NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     BIGINT UNSIGNED NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL,
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX batch_id   (batch_id),
    INDEX order_no   (order_no),
    INDEX pair_id    (pair_id),
    INDEX ts         (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__mm_asks` (
    id           INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT(10) DEFAULT 0 NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL COMMENT 'Time when status is fixed',
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL,
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) DEFAULT 0.00000000 NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     VARCHAR(40) NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX batch_id   (batch_id),
    INDEX order_no   (order_no),
    INDEX pair_id    (pair_id),
    INDEX ts         (ts),
    INDEX ts_fix     (ts_fix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__mm_bids` (
    id           INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT(10) DEFAULT 0 NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL COMMENT 'Time when status is fixed',
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL,
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) DEFAULT 0.00000000 NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     VARCHAR(40) NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX batch_id   (batch_id),
    INDEX order_no   (order_no),
    INDEX pair_id    (pair_id),
    INDEX ts         (ts),
    INDEX ts_fix     (ts_fix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__mm_config` (
    pair_id       INT NOT NULL,
    account_id    INT NOT NULL,
    enabled       TINYINT(1) DEFAULT 0 NOT NULL,
    delta         FLOAT NOT NULL,
    step          FLOAT NOT NULL,
    max_orders    INT DEFAULT 4 NOT NULL,
    order_cost    FLOAT DEFAULT 100 NOT NULL COMMENT 'MM default order cost',
    max_exec_cost FLOAT DEFAULT 5000 NOT NULL,
    PRIMARY KEY (pair_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__mm_exec` (
    id           INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT DEFAULT 0 NOT NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL,
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL,
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) NOT NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     BIGINT UNSIGNED NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL,
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX pair_id    (pair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__mm_limit` (
    id           INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT DEFAULT 0 NOT NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL,
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL,
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) NOT NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     BIGINT UNSIGNED NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL,
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX pair_id    (pair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__other_orders` (
    id           INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT(10) DEFAULT 0 NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL COMMENT 'Time when status is fixed',
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL,
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) DEFAULT 0.00000000 NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     VARCHAR(40) NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    UNIQUE KEY order_no (order_no),
    INDEX account_id   (account_id),
    INDEX batch_id     (batch_id),
    INDEX pair_id      (pair_id),
    INDEX ts           (ts),
    INDEX ts_fix       (ts_fix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__pending_orders` (
    id           INT UNSIGNED NOT NULL PRIMARY KEY,
    host_id      INT UNSIGNED DEFAULT 0 NOT NULL,
    predecessor  INT DEFAULT 0 NOT NULL,
    ts           TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_fix       TIMESTAMP NULL,
    account_id   INT NOT NULL,
    pair_id      INT NOT NULL,
    batch_id     INT DEFAULT 0 NULL,
    signal_id    INT(10) DEFAULT 0 NOT NULL,
    avg_price    FLOAT DEFAULT 0 NOT NULL,
    init_price   DOUBLE DEFAULT 0 NOT NULL,
    price        DOUBLE(16,8) NOT NULL,
    amount       DECIMAL(16,8) DEFAULT 0 NULL,
    buy          TINYINT(1) NOT NULL,
    matched      DECIMAL(16,8) NOT NULL,
    order_no     BIGINT UNSIGNED NOT NULL,
    status       VARCHAR(16) NOT NULL,
    flags        INT UNSIGNED NOT NULL,
    in_position  DECIMAL(16,8) NOT NULL,
    out_position FLOAT DEFAULT 0 NOT NULL,
    comment      VARCHAR(64) NOT NULL,
    updated      TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    INDEX account_id (account_id),
    INDEX batch_id   (batch_id),
    INDEX order_no   (order_no),
    INDEX pair_id    (pair_id),
    INDEX ts         (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__position_history` (
    ts         TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL,
    pair_id    INT NOT NULL,
    account_id INT NOT NULL,
    value      FLOAT NOT NULL,
    value_qty  FLOAT NOT NULL,
    target     FLOAT NULL,
    `offset`   FLOAT DEFAULT 0 NOT NULL,
    PRIMARY KEY (ts, pair_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__positions` (
    pair_id     INT NOT NULL PRIMARY KEY,
    account_id  INT NOT NULL,
    ts_target   TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    ts_current  TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
    target      DECIMAL(20,8) NOT NULL,
    current     DECIMAL(20,8) NOT NULL,
    `offset`    DECIMAL(16,8) NOT NULL,
    rpnl        FLOAT DEFAULT 0 NOT NULL,
    upnl        FLOAT DEFAULT 0 NOT NULL,
    UNIQUE KEY pair_id (pair_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__tasks` (
    ts         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
    account_id INT NOT NULL,
    action     VARCHAR(32) NOT NULL,
    param      VARCHAR(64) NOT NULL,
    UNIQUE KEY uniq_task (account_id, action, param)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__ticker_map` (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    ticker   VARCHAR(16) NOT NULL,
    symbol   VARCHAR(16) NOT NULL,
    pair_id  INT NULL,
    UNIQUE KEY pair_id (pair_id),
    UNIQUE KEY symbol  (symbol),
    UNIQUE KEY ticker  (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `#exchange__tickers` (
    pair_id     INT NOT NULL PRIMARY KEY,
    symbol      VARCHAR(20) NOT NULL,
    last_price  FLOAT NOT NULL,
    lot_size    INT NOT NULL,
    tick_size   FLOAT NOT NULL,
    multiplier  FLOAT DEFAULT 1 NOT NULL,
    flags       INT NOT NULL,
    trade_coef  FLOAT DEFAULT 1 NOT NULL,
    ts_updated  TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `#exchange__ws_stats` (
    `account_id`    INT             NOT NULL,
    `ts`            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    `packets_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `recv_bytes`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `reconnects`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `fallback`      TINYINT(1)      NOT NULL DEFAULT 0,
    `pub_packets`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `priv_packets`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
