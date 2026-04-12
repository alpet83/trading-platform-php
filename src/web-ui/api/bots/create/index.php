<?php

chdir('../../../');

require_once('api_helper.php');
require_once('../lib/bot_creator.php');

function normalize_signals_setup(array $cfg): array {
    $setupId = intval($cfg['setup_id'] ?? 0);
    if ($setupId >= 0) {
        $cfg['signals_setup'] = strval($setupId);
    } elseif (isset($cfg['signals_setup'])) {
        $raw = trim((string)$cfg['signals_setup']);
        if (preg_match('/^\d+$/', $raw)) {
            $cfg['signals_setup'] = strval(intval($raw));
        } elseif ($raw === '') {
            $cfg['signals_setup'] = '0';
        }
    }
    unset($cfg['setup_id']);
    return $cfg;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Only POST method allowed', 405);
    exit;
}

$user_rights = get_user_rights();

if (!str_in($user_rights, 'admin')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

$bot_name   = $_POST['bot_name'] ?? null;
$account_id = $_POST['account_id'] ?? null;
$config     = $_POST['config'] ?? null;

if (!$bot_name || !$account_id || !$config || !is_array($config)) {
    send_error('Missing required fields: bot_name, account_id, config (must be array)', 400);
    exit;
}

$config = normalize_signals_setup((array)$config);

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('Database connection failed', 500);
    exit;
}

$result = bot_create($mysqli, (string)$bot_name, (int)$account_id, (array)$config);

if (!$result['ok']) {
    send_error($result['error'], $result['code'] ?? 500);
    exit;
}

$created_config = $mysqli->select_map('param,value', 'config__' . strtolower((string)$bot_name));

send_response([
    'applicant'  => $result['applicant'],
    'account_id' => $result['account_id'],
    'config'     => $created_config,
]);
exit;

if (!str_in($user_rights, 'admin')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

$bot_name = $_POST['bot_name'] ?? null;
$account_id = $_POST['account_id'] ?? null;
$config = $_POST['config'] ?? null;

if (!$bot_name || !$account_id || !$config || !is_array($config)) {
    send_error('Missing required fields: bot_name, account_id, config (must be array)', 400);
    exit;
}

$required_fields = [
    'exchange',
    'trade_enabled',
    'position_coef',
    'monitor_enabled',
    'min_order_cost',
    'max_order_cost',
    'max_limit_distance',
    'signals_setup',
    'report_color',
    'debug_pair'
];

$missing = [];
foreach ($required_fields as $field) {
    if (!isset($config[$field]) || $config[$field] === '') {
        $missing[] = $field;
    }
}

if (!empty($missing)) {
    send_error('Missing required config fields: ' . implode(', ', $missing), 400);
    exit;
}

$exchange = $config['exchange'];

if (!preg_match('/^[a-z0-9_]+$/i', $bot_name)) {
    send_error('Invalid bot_name. Use only alphanumeric and underscore', 400);
    exit;
}

$applicant = strtolower($bot_name) . '_bot';

if (!is_numeric($account_id) || $account_id <= 0) {
    send_error('Invalid account_id. Must be positive integer', 400);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('Database connection failed', 500);
    exit;
}

$existing = $mysqli->select_value('applicant', 'config__table_map', "WHERE applicant = '$applicant'");
if ($existing) {
    send_error("Bot already exists: $applicant", 409);
    exit;
}

$config_table = 'config__' . strtolower($bot_name);
$bot_prefix = strtolower($bot_name);

$table_exists = $mysqli->select_value('TABLE_NAME', 'INFORMATION_SCHEMA.TABLES',
    "WHERE TABLE_SCHEMA = 'trading' AND TABLE_NAME = '$config_table'");
if ($table_exists) {
    send_error("Config table already exists: $config_table", 409);
    exit;
}

$mysqli->try_query("START TRANSACTION");

$create_config = "CREATE TABLE `$config_table` (
    param VARCHAR(32) NOT NULL,
    value VARCHAR(64) NOT NULL,
    PRIMARY KEY (param)
) COLLATE = utf8mb3_bin";

$result = $mysqli->try_query($create_config);
if ($result === false) {
    $mysqli->try_query("ROLLBACK");
    send_error('Failed to create config table: ' . $mysqli->error, 500);
    exit;
}

$insert_map = "INSERT INTO config__table_map (table_name, account_id, applicant)
               VALUES ('$config_table', $account_id, '$applicant')";
$result = $mysqli->try_query($insert_map);
if ($result === false) {
    $mysqli->try_query("ROLLBACK");
    send_error('Failed to register bot in table_map: ' . $mysqli->error, 500);
    exit;
}

foreach ($config as $param => $value) {
    if ($value === '' || $value === null) {
        continue;
    }

    $param_escaped = $mysqli->real_escape_string($param);
    $value_escaped = $mysqli->real_escape_string($value);

    $insert = "INSERT INTO `$config_table` (param, value) 
               VALUES ('$param_escaped', '$value_escaped')";
    $result = $mysqli->try_query($insert);
    if ($result === false) {
        $mysqli->try_query("ROLLBACK");
        send_error("Failed to insert config param '$param': " . $mysqli->error, 500);
        exit;
    }
}

$tables_to_create = [
    'archive_orders' => "CREATE TABLE `{$bot_prefix}__archive_orders` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT DEFAULT 0 NOT NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL COMMENT 'Time when status is fixed',
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) NOT NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX batch_id (batch_id),
        INDEX order_no (order_no),
        INDEX pair_id (pair_id),
        INDEX ts (ts),
        INDEX ts_fix (ts_fix)
    ) COLLATE = utf8mb3_bin",

    'batches' => "CREATE TABLE `{$bot_prefix}__batches` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        parent INT DEFAULT 0 NOT NULL COMMENT 'relation to ext_signals',
        source_pos FLOAT NULL COMMENT 'RAW copy-trading position',
        start_pos FLOAT DEFAULT 0 NOT NULL,
        target_pos DECIMAL(20,8) NOT NULL,
        price FLOAT NOT NULL,
        exec_price FLOAT DEFAULT 0 NULL,
        btc_price FLOAT NULL,
        exec_amount FLOAT DEFAULT 0 NOT NULL,
        exec_qty FLOAT DEFAULT 0 NOT NULL COMMENT 'Natural quantity, not contracts',
        slippage FLOAT DEFAULT 0 NULL,
        last_order INT UNSIGNED NOT NULL,
        flags INT DEFAULT 0 NOT NULL,
        INDEX account_id (account_id),
        INDEX flags (flags),
        INDEX last_order (last_order),
        INDEX pair_id (pair_id),
        INDEX parent (parent)
    ) COLLATE = utf8mb3_bin",

    'deposit_history' => "CREATE TABLE `{$bot_prefix}__deposit_history` (
        ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL PRIMARY KEY,
        account_id INT NOT NULL,
        withdrawal TINYINT(1) DEFAULT 0 NOT NULL,
        value_btc FLOAT NOT NULL,
        value_eth FLOAT DEFAULT 0 NOT NULL,
        value_usd FLOAT DEFAULT 0 NOT NULL,
        INDEX account_id (account_id)
    ) COLLATE = utf8mb4_general_ci",

    'events' => "CREATE TABLE `{$bot_prefix}__events` (
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        account_id INT NOT NULL,
        host_id INT DEFAULT 0 NOT NULL,
        event VARCHAR(16) NOT NULL,
        message VARCHAR(64) NULL,
        INDEX account_id (account_id),
        INDEX event (event),
        INDEX ts (ts)
    ) COLLATE = utf8mb4_general_ci",

    'ext_signals' => "CREATE TABLE `{$bot_prefix}__ext_signals` (
        id INT NOT NULL,
        account_id INT DEFAULT 0 NOT NULL,
        buy TINYINT(1) NOT NULL,
        pair_id INT NOT NULL,
        ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL,
        ts_checked TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NULL,
        limit_price DECIMAL(16,9) DEFAULT 0.000000000 NOT NULL,
        recalc_price DOUBLE DEFAULT 0 NOT NULL,
        stop_loss DECIMAL(16,9) DEFAULT 0.000000000 NOT NULL,
        take_profit DECIMAL(16,9) DEFAULT 0.000000000 NOT NULL,
        take_order INT DEFAULT 0 NOT NULL,
        limit_order INT DEFAULT 0 NOT NULL,
        amount INT NOT NULL,
        mult INT NOT NULL,
        ttl INT NOT NULL,
        flags INT NOT NULL,
        open_coef FLOAT NOT NULL,
        exec_prio FLOAT DEFAULT 0 NOT NULL,
        setup INT DEFAULT 0 NOT NULL,
        qty INT DEFAULT 0 NOT NULL COMMENT 'Grid orders qty',
        active TINYINT(1) NOT NULL,
        closed TINYINT(1) NOT NULL,
        comment VARCHAR(64) NULL,
        PRIMARY KEY (id, account_id),
        INDEX ts (ts)
    ) COLLATE = utf8mb4_general_ci",

    'funds_history' => "CREATE TABLE `{$bot_prefix}__funds_history` (
        ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL ON UPDATE CURRENT_TIMESTAMP(),
        account_id INT DEFAULT 0 NOT NULL,
        value FLOAT NOT NULL,
        value_btc FLOAT DEFAULT 0 NOT NULL,
        position_coef FLOAT DEFAULT 0.01 NOT NULL,
        PRIMARY KEY (ts, account_id),
        INDEX account_id (account_id)
    ) COLLATE = utf8mb3_bin",

    'last_errors' => "CREATE TABLE `{$bot_prefix}__last_errors` (
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL PRIMARY KEY,
        account_id INT NOT NULL,
        host_id INT NULL,
        code INT NULL,
        message VARCHAR(4096) DEFAULT '' NOT NULL,
        source VARCHAR(32) NULL,
        backtrace VARCHAR(1024) NULL
    ) COLLATE = utf8mb4_general_ci",

    'lost_orders' => "CREATE TABLE `{$bot_prefix}__lost_orders` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT DEFAULT 0 NOT NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL COMMENT 'Time when status is fixed',
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL COMMENT 'for better slippage calculation',
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) DEFAULT 0.00000000 NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX batch_id (batch_id),
        INDEX order_no (order_no),
        INDEX pair_id (pair_id),
        INDEX ts (ts),
        INDEX ts_fix (ts_fix)
    ) COLLATE = utf8mb3_bin",

    'matched_orders' => "CREATE TABLE `{$bot_prefix}__matched_orders` (
        id INT(11) UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT DEFAULT 0 NOT NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL,
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) NOT NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL,
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX batch_id (batch_id),
        INDEX order_no (order_no),
        INDEX pair_id (pair_id),
        INDEX ts (ts)
    ) COLLATE = utf8mb3_bin",

    'mm_asks' => "CREATE TABLE `{$bot_prefix}__mm_asks` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT(10) DEFAULT 0 NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL COMMENT 'Time when status is fixed',
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) DEFAULT 0.00000000 NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no VARCHAR(40) NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX batch_id (batch_id),
        INDEX order_no (order_no),
        INDEX pair_id (pair_id),
        INDEX ts (ts),
        INDEX ts_fix (ts_fix)
    ) COLLATE = utf8mb3_bin",

    'mm_bids' => "CREATE TABLE `{$bot_prefix}__mm_bids` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT(10) DEFAULT 0 NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL COMMENT 'Time when status is fixed',
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) DEFAULT 0.00000000 NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no VARCHAR(40) NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX batch_id (batch_id),
        INDEX order_no (order_no),
        INDEX pair_id (pair_id),
        INDEX ts (ts),
        INDEX ts_fix (ts_fix)
    ) COLLATE = utf8mb3_bin",

    'mm_config' => "CREATE TABLE `{$bot_prefix}__mm_config` (
        pair_id INT NOT NULL,
        account_id INT NOT NULL,
        enabled TINYINT(1) DEFAULT 0 NOT NULL,
        delta FLOAT NOT NULL,
        step FLOAT NOT NULL,
        max_orders INT DEFAULT 4 NOT NULL,
        order_cost FLOAT DEFAULT 100 NOT NULL COMMENT 'MM default order cost',
        max_exec_cost FLOAT DEFAULT 5000 NOT NULL,
        PRIMARY KEY (pair_id, account_id)
    ) COLLATE = utf8mb4_general_ci",

    'mm_exec' => "CREATE TABLE `{$bot_prefix}__mm_exec` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT DEFAULT 0 NOT NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL,
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) NOT NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL,
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX pair_id (pair_id)
    ) COLLATE = utf8mb3_bin",

    'mm_limit' => "CREATE TABLE `{$bot_prefix}__mm_limit` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT DEFAULT 0 NOT NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL,
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) NOT NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL,
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX pair_id (pair_id)
    ) COLLATE = utf8mb3_bin",

    'other_orders' => "CREATE TABLE `{$bot_prefix}__other_orders` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT(10) DEFAULT 0 NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL COMMENT 'Time when status is fixed',
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) DEFAULT 0.00000000 NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no VARCHAR(40) NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL COMMENT 'After order updated',
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        UNIQUE KEY order_no (order_no),
        INDEX account_id (account_id),
        INDEX batch_id (batch_id),
        INDEX pair_id (pair_id),
        INDEX ts (ts),
        INDEX ts_fix (ts_fix)
    ) COLLATE = utf8mb3_bin",

    'pending_orders' => "CREATE TABLE `{$bot_prefix}__pending_orders` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        host_id INT UNSIGNED DEFAULT 0 NOT NULL,
        predecessor INT DEFAULT 0 NOT NULL,
        ts TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_fix TIMESTAMP NULL,
        account_id INT NOT NULL,
        pair_id INT NOT NULL,
        batch_id INT DEFAULT 0 NULL,
        signal_id INT(10) DEFAULT 0 NOT NULL,
        avg_price FLOAT DEFAULT 0 NOT NULL,
        init_price DOUBLE DEFAULT 0 NOT NULL,
        price DOUBLE(16,8) NOT NULL,
        amount DECIMAL(16,8) DEFAULT 0 NULL,
        buy TINYINT(1) NOT NULL,
        matched DECIMAL(16,8) NOT NULL,
        order_no BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        flags INT UNSIGNED NOT NULL,
        in_position DECIMAL(16,8) NOT NULL,
        out_position FLOAT DEFAULT 0 NOT NULL,
        comment VARCHAR(64) NOT NULL,
        updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        INDEX account_id (account_id),
        INDEX batch_id (batch_id),
        INDEX order_no (order_no),
        INDEX pair_id (pair_id),
        INDEX ts (ts)
    ) COLLATE = utf8mb3_bin",

    'position_history' => "CREATE TABLE `{$bot_prefix}__position_history` (
        ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP() NOT NULL,
        pair_id INT NOT NULL,
        account_id INT NOT NULL,
        value FLOAT NOT NULL,
        value_qty FLOAT NOT NULL,
        target FLOAT NULL,
        `offset` FLOAT DEFAULT 0 NOT NULL,
        PRIMARY KEY (ts, pair_id, account_id)
    ) COLLATE = utf8mb4_general_ci",

    'positions' => "CREATE TABLE `{$bot_prefix}__positions` (
        pair_id INT NOT NULL PRIMARY KEY,
        account_id INT NOT NULL,
        ts_target TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        ts_current TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL,
        target DECIMAL(20,8) NOT NULL,
        current DECIMAL(20,8) NOT NULL,
        `offset` DECIMAL(16,8) NOT NULL,
        rpnl FLOAT DEFAULT 0 NOT NULL,
        upnl FLOAT DEFAULT 0 NOT NULL,
        UNIQUE KEY pair_id (pair_id, account_id)
    ) COLLATE = utf8mb3_bin",

    'ticker_map' => "CREATE TABLE `{$bot_prefix}__ticker_map` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticker VARCHAR(16) NOT NULL,
        symbol VARCHAR(16) NOT NULL,
        pair_id INT NULL,
        UNIQUE KEY pair_id (pair_id),
        UNIQUE KEY symbol (symbol),
        UNIQUE KEY ticker (ticker)
    ) COLLATE = utf8mb3_bin",

    'tickers' => "CREATE TABLE `{$bot_prefix}__tickers` (
        pair_id INT NOT NULL PRIMARY KEY,
        symbol VARCHAR(20) NOT NULL,
        last_price FLOAT NOT NULL,
        lot_size INT NOT NULL,
        tick_size FLOAT NOT NULL,
        multiplier FLOAT DEFAULT 1 NOT NULL,
        flags INT NOT NULL,
        trade_coef FLOAT DEFAULT 1 NOT NULL,
        ts_updated TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) NOT NULL ON UPDATE CURRENT_TIMESTAMP(3)
    ) COLLATE = utf8mb4_general_ci"
];

$created_tables = [];
foreach ($tables_to_create as $table_suffix => $create_sql) {
    $result = $mysqli->try_query($create_sql);
    if ($result === false) {
        $mysqli->try_query("ROLLBACK");
        send_error("Failed to create table {$bot_prefix}__{$table_suffix}: " . $mysqli->error, 500);
        exit;
    }
    $created_tables[] = "{$bot_prefix}__{$table_suffix}";
}

$mysqli->try_query("COMMIT");

$created_config = $mysqli->select_map('param,value', $config_table);

send_response([
    'applicant' => $applicant,
    'account_id' => $account_id,
    'config' => $created_config
], 201);
