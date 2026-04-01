<?php
$docroot = __DIR__;
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($docroot . $uri);

if (is_string($file) && str_starts_with($file, $docroot) && is_file($file)) {
    return false;
}

if ($uri === '/' || $uri === '') {
    require $docroot . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;
