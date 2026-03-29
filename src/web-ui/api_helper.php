<?php

// Keep this helper minimal and operationally stable for API endpoints.
// Auth orchestration for signals-system.ts is handled outside this web-ui layer.

require_once(__DIR__ . '/../lib/common.php');
require_once(__DIR__ . '/../lib/db_tools.php');

if (file_exists('/usr/local/etc/php/db_config.php')) {
    require_once('/usr/local/etc/php/db_config.php');
} else {
    require_once(__DIR__ . '/../lib/db_config.php');
}

require_once(__DIR__ . '/../lib/auth_check.php');

if (!function_exists('send_response')) {
    function send_response($data, int $http_code = 200): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code($http_code);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('send_error')) {
    function send_error($message, int $http_code = 500): void {
        send_response(['error' => $message], $http_code);
    }
}

