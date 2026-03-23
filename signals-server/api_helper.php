<?php

require_once('lib/common.php');
require_once('lib/db_tools.php');
require_once('/usr/local/etc/php/db_config.php');

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

check_auth();

set_exception_handler(function (Throwable $E) {
    $loc = $E->getFile().':'.$E->getLine();
    log_msg("#EXCEPTION: %s from %s:\n %s", $E->getMessage(), $loc, $E->getTraceAsString());
    error_log("#EXCEPTION: {$E->getMessage()} from $loc: {$E->getTraceAsString()}");
            });

function check_auth() {
    global $log_file;
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $token = '?';
    $log_fn = '/tmp/api-debug.log';
    $log_file = fopen($log_fn, 'w');
    if (is_resource($log_file)) {
      log_msg("#DBG(headers): \n%s", print_r($headers, true));
      log_msg("#DBG(SERVER): \n%s", print_r($_SERVER, true));
    }
    else
      send_error("Failed open $log_fn for writing", 500);

    if (array_key_exists('authorization', $headers)) 
    try {
        $token = FRONTEND_TOKEN;
        if ($headers['authorization'] === "Bearer {$token}") {
            return;
        }
        $token .= ':' . $headers['authorization'];
    }
    catch (Throwable $E) { 
        log_msg("#EXCEPTION in check_auth: %s from %s:\n %s", $E->getMessage(), $E->getFile().':'.$E->getLine(), $E->getTraceAsString());        
    }
    send_error(['m' => "Unauthorized by [$token]/Token not specified/Wrong IP", 'h' => $headers], 401);
    exit;
}

function get_user_rights() {
    $default_rights = 'none';

    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $telegram_id = $headers['x-user-id'] ?? null;

    if ($telegram_id !== null) {
        $telegram_id = intval($telegram_id);

        if ($telegram_id <= 0) {
            send_error('Unauthorized: Invalid user ID', 401);
            exit;
        }

        // Host of the PHP trading server. In dev use the docker service name;
        // in production set TRADEBOT_PHP_HOST env var to the internal address.
        $host = getenv('ENVIRONMENT') === 'dev' ? 'http://tradebot-new-php' : (getenv('TRADEBOT_PHP_HOST') ?: 'http://localhost');
        $rights = curl_http_request("{$host}/get_user_rights.php?id=$telegram_id");

        return $rights;
    }

    return $default_rights;
}

function send_response($data, $http_code = 200) {
    ob_clean();
    http_response_code($http_code);
    echo json_encode($data, JSON_PRETTY_PRINT);
}

function send_error($message, $http_code = 500) {
    ob_clean();
    http_response_code($http_code);
    $trace = debug_backtrace(0);
    array_shift($trace);
    log_msg("#HTTP_SERVER_ERROR: %s from:\n %s", print_r($message, true), format_backtrace(0)); 
    if (500 == $http_code)
       error_log("#HTTP_SERVER_ERROR: ".print_r($message, true)." from ".print_r($trace, true));
    echo json_encode(['error' => $message, 'backtrace' => $trace], JSON_PRETTY_PRINT);
}
