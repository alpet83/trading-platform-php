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
$config = $_POST['config'] ?? null;

if (!$applicant) {
    send_error('Missing required field: applicant', 400);
    exit;
}

if (!$config || !is_array($config)) {
    send_error('Missing or invalid config field (must be array)', 400);
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

$account_id = $mysqli->select_value('account_id', $table_name);
if (!$account_id) {
    send_error("Account not found for bot: $applicant", 404);
    exit;
}

$current_config = $mysqli->select_map('param,value', $table_name, "WHERE account_id = $account_id");
if (!is_array($current_config)) {
    send_error('Failed to load current configuration', 500);
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

$final_config = array_merge($current_config, $config);

$missing = [];
foreach ($required_fields as $field) {
    if (!isset($final_config[$field]) || $final_config[$field] === '' || $final_config[$field] === null) {
        $missing[] = $field;
    }
}

if (!empty($missing)) {
    send_error('Cannot remove required config fields: ' . implode(', ', $missing), 400);
    exit;
}

$updated = [];
$failed = [];

foreach ($config as $param => $value) {
    if ($value === '' || $value === null) {
        $param_escaped = $mysqli->real_escape_string($param);
        $delete_query = "DELETE FROM `$table_name` WHERE account_id = $account_id AND param = '$param_escaped'";
        $result = $mysqli->try_query($delete_query);

        if ($result === false) {
            $failed[$param] = $mysqli->error;
        } else {
            $updated[$param] = null;
        }
        continue;
    }

    $param_escaped = $mysqli->real_escape_string($param);
    $value_escaped = $mysqli->real_escape_string($value);

    $exists = $mysqli->select_value('param', $table_name, "WHERE account_id = $account_id AND param = '$param_escaped'");

    if ($exists) {
        $query = "UPDATE `$table_name` SET `value` = '$value_escaped' WHERE account_id = $account_id AND param = '$param_escaped'";
    } else {
        $query = "INSERT INTO `$table_name` (account_id, param, value) VALUES ($account_id, '$param_escaped', '$value_escaped')";
    }

    $result = $mysqli->try_query($query);

    if ($result === false) {
        $failed[$param] = $mysqli->error;
    } else {
        $updated[$param] = $value;
    }
}

$updated_config = $mysqli->select_map('param,value', $table_name, "WHERE account_id = $account_id");

if (!empty($failed)) {
    send_response([
        'applicant' => $applicant,
        'account_id' => $account_id,
        'config' => $updated_config,
        'updated' => $updated,
        'failed' => $failed
    ], 207);
} else {
    send_response([
        'applicant' => $applicant,
        'account_id' => $account_id,
        'config' => $updated_config
    ]);
}
