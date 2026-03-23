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
$enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

if (!$bot || is_null($enabled)) {
    send_error('Missing required fields: bot, enabled', 400);
    exit;
}

if (!is_bool($enabled)) {
    send_error('Invalid enabled value: must be boolean', 400);
    exit;
}

$enabled_value = $enabled ? 1 : 0;

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

$account_id = $mysqli->select_value('account_id', $cfg_table);
if (!$account_id) {
    send_error("Account not found for bot $bot", 404);
    exit;
}

$query = "UPDATE `$cfg_table` SET `value` = '$enabled_value' WHERE (`param` = 'trade_enabled');";
$result = $mysqli->try_query($query);

if ($result === false) {
    send_error('Update query failed: ' . $mysqli->error, 500);
    exit;
}

send_response([
    'bot' => $bot,
    'account_id' => $account_id,
    'trade_enabled' => $enabled
]);
