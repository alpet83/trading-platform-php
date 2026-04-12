<?php

chdir('../../');

require_once('api_helper.php');

require_once('lib/esctext.php');

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
$bot = rqs_param('bot', '');
if (!$bot) {
    send_error('bot param is required', 400);
    exit;
}
if (!isset($bots[$bot])) {
    send_error("bot $bot not exists in DB", 404);
    exit;
}

$config = $mysqli->select_map('param,value', $bots[$bot]);
if (is_null($config)) {
    send_error("bot $bot config damaged in DB", 500);
    exit;
}

$exch = strtolower($config['exchange'] ?? 'NYMEX');
$acc_id = $mysqli->select_value('account_id', 'config__table_map', "WHERE table_name = '{$bots[$bot]}' LIMIT 1");
$acc_id = rqs_param('account',  $acc_id);

$detailed = rqs_param('ts', false);
$params = "WHERE (account_id = $acc_id)";
$params .= $detailed ? " AND ts ='$detailed'" : '';
$params .= ' ORDER BY ts DESC LIMIT 500';

$errors_raw = $mysqli->select_rows('*', $exch.'__last_errors', $params, MYSQLI_ASSOC);
$errors = array_map(function ($error_raw) {
    $error['time'] = $error_raw['ts'];
    $error['host'] = $error_raw['host_id'];
    $error['code'] = $error_raw['code'];
    $error['message_raw'] = $error_raw['message'];
    $error['message'] = preg_replace('/~C\d\d/', '', $error_raw['message']);
    $error['source'] = $error_raw['source'];
    return $error;
}, $errors_raw);

$response = [
    'bot' => $bot,
    'account_id' => $acc_id,
    'errors' => $errors
];

send_response($response);
