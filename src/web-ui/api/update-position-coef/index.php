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

$bot = $_POST['bot'] ?? null;
$position_coef = $_POST['position_coef'] ?? null;

if (!$bot || is_null($position_coef)) {
    send_error('Missing required fields: bot, position_coef', 400);
    exit;
}

$position_coef = doubleval($position_coef);

if ($position_coef < 0 || $position_coef > 1) {
    send_error('Invalid position_coef: must be between 0 and 1', 400);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('Database connection failed', 500);
    exit;
}

$cfg_table = $mysqli->select_value('table_name', 'config__table_map', "WHERE applicant = '$bot'");
if (!$cfg_table) {
    send_error("Bot configuration not found for $bot", 404);
    exit;
}

$account_id = $mysqli->select_value('account_id', 'config__table_map', "WHERE table_name = '$cfg_table' LIMIT 1");
if (!$account_id) {
    send_error("Account not found for bot $bot", 404);
    exit;
}

$query = "UPDATE `$cfg_table` SET `value` = '$position_coef' WHERE (`param` = 'position_coef');";
$result = $mysqli->try_query($query);

if ($result === false) {
    send_error('Update query failed: ' . $mysqli->error, 500);
    exit;
}

send_response([
    'bot' => $bot,
    'account_id' => $account_id,
    'position_coef' => $position_coef
]);
