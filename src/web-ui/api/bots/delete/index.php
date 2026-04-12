<?php

chdir('../../../');

require_once('api_helper.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Only POST method allowed', 405);
    exit;
}

$user_rights = get_user_rights();

if (!str_in($user_rights, 'admin')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

$applicant = $_POST['applicant'] ?? null;

if (!$applicant) {
    send_error('Missing required field: applicant', 400);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('Database connection failed', 500);
    exit;
}

$table_name = $mysqli->select_value('table_name', 'config__table_map', "WHERE applicant = '$applicant'");
if (!$table_name) {
    send_error("Bot not found: $applicant", 404);
    exit;
}

$account_id = $mysqli->select_value('account_id', 'config__table_map', "WHERE table_name = '$table_name' LIMIT 1");

$bot_prefix = str_replace('config__', '', $table_name);

$mysqli->try_query("START TRANSACTION");

$delete_map = "DELETE FROM config__table_map WHERE applicant = '$applicant'";
$result = $mysqli->try_query($delete_map);
if ($result === false) {
    $mysqli->try_query("ROLLBACK");
    send_error('Failed to delete bot from table map: ' . $mysqli->error, 500);
    exit;
}

$mysqli->try_query("DROP TABLE IF EXISTS `$table_name`");

$tables_to_drop = [
    'archive_orders',
    'batches',
    'deposit_history',
    'events',
    'ext_signals',
    'funds_history',
    'last_errors',
    'lost_orders',
    'matched_orders',
    'mm_asks',
    'mm_bids',
    'mm_config',
    'mm_exec',
    'mm_limit',
    'other_orders',
    'pending_orders',
    'position_history',
    'positions',
    'ticker_map',
    'tickers'
];

foreach ($tables_to_drop as $table_suffix) {
    $mysqli->try_query("DROP TABLE IF EXISTS `{$bot_prefix}__{$table_suffix}`");
}

$delete_activity = "DELETE FROM bot__activity WHERE applicant = '$applicant' AND account_id = $account_id";
$mysqli->try_query($delete_activity);

$mysqli->try_query("COMMIT");

send_response([
    'applicant' => $applicant,
    'message' => 'Bot deleted successfully'
]);
