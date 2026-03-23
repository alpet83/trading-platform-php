<?php

chdir('../../');

require_once('api_helper.php');

$user_rights = get_user_rights();

if (!str_in($user_rights, 'admin')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('Database connection failed', 500);
    exit;
}

$bots = $mysqli->select_map('applicant,table_name', 'config__table_map', 'ORDER BY applicant');
if (!is_array($bots)) {
    send_error('Failed to fetch bots list', 500);
    exit;
}

$bots_list = [];

foreach ($bots as $applicant => $table_name) {
    $config = $mysqli->select_map('param,value', $table_name);

    if (!is_array($config)) {
        $bots_list[] = [
            'applicant' => $applicant,
            'error' => 'Failed to load config'
        ];
        continue;
    }

    $account_id = $mysqli->select_value('account_id', $table_name);

    $bots_list[] = [
        'applicant' => $applicant,
        'account_id' => $account_id,
        'config' => $config
    ];
}

send_response(['bots' => $bots_list]);
