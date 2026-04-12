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

/**
 * Universal action reply with browser auto-detect.
 *
 * - Browser (HTTP_ACCEPT contains text/html): renders a dark mini-page with
 *   a status message and meta-refresh back to HTTP_REFERER after $delay seconds.
 * - CLI / API client: outputs a plain-text line.
 *
 * @param bool   $ok      Whether the action succeeded.
 * @param string $message Human-readable status (already HTML-safe caller's choice; will be escaped here).
 * @param string $title   <title> / page heading shown in browser mode.
 * @param int    $delay   Redirect delay in seconds (default 3).
 * @param string $back    Override redirect URL (default: HTTP_REFERER or 'index.php').
 */
if (!function_exists('http_reply')) {
    function http_reply(bool $ok, string $message, string $title = 'Action', int $delay = 3, string $back = ''): void {
        $from_browser = !empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'text/html')
                        && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest';

        if (!$from_browser) {
            echo ($ok ? 'OK: ' : 'ERROR: ') . $message . "\n";
            return;
        }

        $referer  = $back !== '' ? $back : (trim($_SERVER['HTTP_REFERER'] ?? '') ?: 'index.php');
        $safe_ref = htmlspecialchars($referer, ENT_QUOTES, 'UTF-8');
        $safe_msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safe_ttl = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $cls      = $ok ? 'ok' : 'err';

        if (!headers_sent())
            header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
        <!DOCTYPE html><html><head><meta charset="utf-8">
        <title>{$safe_ttl}</title>
        <meta http-equiv="refresh" content="{$delay};url={$safe_ref}">
        <style>body{font-family:monospace;padding:2em;background:#111;color:#ccc;}
        .ok{color:#6f6}  .err{color:#f66}  a{color:#8af}</style>
        </head><body>
        <h3>{$safe_ttl}</h3>
        <p class="{$cls}">{$safe_msg}</p>
        <p>Returning to <a href="{$safe_ref}">{$safe_ref}</a> in {$delay} seconds&hellip;</p>
        </body></html>
        HTML;
    }
}

