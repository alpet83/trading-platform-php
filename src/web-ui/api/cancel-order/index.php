<?php

chdir('../../');

require_once('api_helper.php');

require_once('lib/esctext.php');
require_once('lib/mini_core.php');

$user_rights = get_user_rights();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Only POST method allowed', 405);
    exit;
}

if (!str_in($user_rights, 'trade')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('DB inaccessible', 500);
    exit;
}

$bot = $_POST['bot'] ?? null;
$order_id = $_POST['order_id'] ?? null;

if (!$bot || !$order_id) {
    send_error('Missing required fields: bot, order_id', 400);
    exit;
}

$order_id = intval($order_id);
if ($order_id <= 1000) {
    send_error('Invalid order_id: must be greater than 1000', 400);
    exit;
}

$core = new MiniCore($mysqli, $bot);
if (!is_array($core->config)) {
    send_error("Bot config damaged in DB. Bot: $bot", 500);
    exit;
}

$engine = $core->trade_engine;
$p_table = $engine->TableName('pending_orders');
$m_table = $engine->TableName('mm_exec');

$exists = $mysqli->select_value('order_no', $p_table, "WHERE id = $order_id") ??
    $mysqli->select_value('order_no', $m_table, "WHERE id = $order_id");

if (!$exists) {
    send_error("Order $order_id not found", 404);
    exit;
}

$account_id = $engine->account_id;
$table = $engine->TableName('tasks');
$query = "INSERT IGNORE INTO `$table` (`account_id`, `action`, `param`) VALUES ($account_id, 'CANCEL_ORDER', '$order_id');";
$res = $mysqli->try_query($query);

if (!$res) {
    send_error('Failed to schedule cancel task', 500);
    exit;
}

send_response([
    'bot' => $bot,
    'order_id' => $order_id,
    'tasks_scheduled' => $mysqli->affected_rows
]);
