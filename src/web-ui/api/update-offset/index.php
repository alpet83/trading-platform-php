<?php

chdir('../../');

require_once('api_helper.php');

$user_rights = get_user_rights();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Only POST method allowed', 405);
    exit;
}

if (!str_in($user_rights, 'trade')) {
    send_error('Insufficient rights', 403);
    exit;
}

$exch = $_POST['exchange'] ?? null;
$acc_id = $_POST['account'] ?? null;
$pair_id = $_POST['pair_id'] ?? null;
$offset = $_POST['offset'] ?? null;

if (!$exch || !$acc_id || !$pair_id || is_null($offset)) {
    send_error('Missing required fields: exchange, account, pair_id, offset', 400);
    exit;
}

$acc_id = intval($acc_id);
$pair_id = intval($pair_id);
$offset = doubleval($offset);

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('Database connection failed', 500);
    exit;
}

$bot = $exch . '_bot';
$cfg_table = $mysqli->select_value('table_name', 'config__table_map', "WHERE applicant = '$bot'");
if (!$cfg_table) {
    send_error("Bot configuration not found for $bot", 404);
    exit;
}

$table = $exch . '__positions';

$existing = $mysqli->select_value('COUNT(*)', $table, "WHERE (account_id = $acc_id) AND (pair_id = $pair_id)");
if (!$existing) {
    send_error('Position not found for specified account and pair_id', 404);
    exit;
}

$query = "UPDATE `$table` SET `offset` = $offset WHERE (account_id = $acc_id) AND (pair_id = $pair_id);";
$result = $mysqli->try_query($query);

if ($result === false) {
    send_error('Update query failed: ' . $mysqli->error, 500);
    exit;
}

send_response([
    'account_id' => $acc_id,
    'pair_id' => $pair_id,
    'offset' => $offset,
    'exchange' => $exch
]);
