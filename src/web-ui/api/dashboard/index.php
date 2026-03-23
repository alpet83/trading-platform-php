<?php

chdir('../../');

require_once('api_helper.php');

require_once('lib/esctext.php');
require_once('lib/mini_core.php');

$user_rights = get_user_rights();

if (!str_in($user_rights, 'view')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('DB inaccessible');
    exit;
}

$bots = $mysqli->select_map('applicant,table_name', 'config__table_map');
mysqli_report(MYSQLI_REPORT_OFF);

$impl_name = $_SESSION['bot'] ?? rqs_param('bot', false);
$account_id = $_SESSION['account_id'] ?? rqs_param('account', 0);
$bot = rqs_param('bot', $impl_name);

$missing_params = [];
if (!$impl_name) $missing_params[] = 'bot';
if (!$account_id) $missing_params[] = 'account';
if (!empty($missing_params)) {
    $params = implode(', ', $missing_params);
    send_error("Parameters missing: $params", 400);
    exit;
}

$core = new MiniCore($mysqli, $bot);
$bots = $core->bots;
$cfg_table = 'config__test';

if (!$impl_name && $account_id > 0) {
    foreach ($bots as $app => $table) {
        $acc_id = $mysqli->select_value('account_id', $table);
        if ($acc_id != $account_id) continue;
        $impl_name = $app;
        $cfg_table = $table;
        break;
    }
}

if (!is_array($core->config)) {
    send_error("Bot config damaged in DB. Bot: {$bot}");
    exit;
}

$_SESSION['bot'] = $bot;
$exch = strtolower($core->config['exchange'] ?? false);
if (!is_string($exch)) {
    send_error("No exchange defined for bot. Bot: {$bot}");
    exit;
}

$config = $core->config;
if (!is_array($config)) {
    send_error("No config for bot. Bot: {$bot}");
    exit;
}

$account_id = $core->trade_engine->account_id;
if (0 == $account_id) {
    $account_id = $mysqli->select_value('account_id', $cfg_table);
}

$_SESSION['account_id'] = $account_id;

$engine = $core->trade_engine;
$exchange = rqs_param('exchange', $config['exchange'] ?? 'NyMEX');

$is_admin  = str_in($user_rights, 'admin');
$is_trader = str_in($user_rights, 'trade');

$p_table = $engine->TableName('pending_orders');
$m_table = $engine->TableName('mm_exec');
$params = "UNION SELECT * FROM $p_table\n";
$params .= "WHERE (account_id = $account_id) ORDER BY pair_id ASC,updated DESC";
$pairs_map = &$core->pairs_map;

$orders = $mysqli->select_rows('*', $m_table, $params, MYSQLI_OBJECT);

function format_orders($orders, $pairs_map) {
    if (!is_array($orders) || 0 == count($orders)) return [];

    $result = [];
    foreach ($orders as $order) {
        $pair = $pairs_map[$order->pair_id] ?? '#'.$order->pair_id;
        $amount = $order->amount * ($order->buy ? 1 : -1);
        $matched = $order->matched * ($order->buy ? 1 : -1);

        $result[] = [
            'id' => $order->id,
            'ts' => $order->ts,
            'host_id' => $order->host_id,
            'batch_id' => $order->batch_id,
            'signal_id' => $order->signal_id,
            'pair' => $pair,
            'pair_id' => $order->pair_id,
            'price' => $order->price,
            'amount' => $amount,
            'matched' => $matched,
            'buy' => (bool)$order->buy,
            'comment' => $order->comment
        ];
    }
    return $result;
}

$active_orders = [];
if (is_array($orders) && count($orders) > 0) {
    $active_orders = format_orders($orders, $pairs_map);
}

$table = $engine->TableName('mm_limit');
$limit_orders_raw = $mysqli->select_rows('*', $table, 'ORDER BY pair_id ASC, updated DESC', MYSQLI_OBJECT);
$limit_orders = format_orders($limit_orders_raw, $pairs_map);

$positions_table = $exchange.'__positions';
$pos_params = "LEFT JOIN `$exchange"."__tickers` AS T ON T.pair_id = P.pair_id\n";
$pos_params .= "LEFT JOIN `$exchange"."__ticker_map` AS TM ON TM.pair_id = P.pair_id\n";
$pos_params .= "WHERE account_id = $account_id\n";
$pos_params .= "ORDER BY pair";

$positions_raw = $mysqli->select_rows('P.*,T.last_price,COALESCE(T.symbol, TM.symbol) as pair', "`$positions_table` as P", $pos_params, MYSQLI_ASSOC);

$positions = [];
$bot_dir = strtolower("/tmp/{$bot}");
$json_cache = [];

if (is_array($positions_raw)) {
    foreach ($positions_raw as $row) {
        $pair_id = $row['pair_id'];
        $pair = $row['pair'];
        if (is_null($pair)) $pair = "#$pair_id";
        if (is_array($pair)) $pair = $pair[0];

        $price_precision = null;
        $qty_precision = null;
        if (!isset($json_cache[$pair])) {
            $ticker_file = $bot_dir."/$pair.json";
            if (file_exists($ticker_file)) {
                $json = file_get_contents($ticker_file);
                $ti = json_decode($json);
                if (is_object($ti)) {
                    $json_cache[$pair] = $ti;
                } else {
                    $json_cache[$pair] = null;
                }
            } else {
                $json_cache[$pair] = null;
            }
        }
        if ($json_cache[$pair]) {
            $price_precision = $json_cache[$pair]->price_precision ?? null;
            $qty_precision = $json_cache[$pair]->qty_precision ?? null;
        }

        $positions[] = [
            'ts' => $row['ts_current'],
            'pair_id' => $pair_id,
            'pair' => $pair,
            'current' => $row['current'],
            'target' => $row['target'],
            'last_price' => $row['last_price'] ?? 0,
            'rpnl' => $row['rpnl'] ?? 0,
            'upnl' => $row['upnl'] ?? 0,
            'offset' => $row['offset'],
            'price_precision' => $price_precision,
            'qty_precision' => $qty_precision
        ];
    }
}

$response = [
    'bot' => $bot,
    'account_id' => $account_id,
    'exchange' => $exchange,
    'is_admin' => $is_admin,
    'is_trader' => $is_trader,
    'active_orders' => $active_orders,
    'limit_orders' => $limit_orders,
    'positions' => $positions
];

send_response($response);