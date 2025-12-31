<?php

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function send_response($data, $http_code = 200) {
    ob_clean();

    http_response_code($http_code);
    echo json_encode($data, JSON_PRETTY_PRINT);
}

function send_error($message, $http_code = 500) {
    ob_clean();

    http_response_code($http_code);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT);
}
