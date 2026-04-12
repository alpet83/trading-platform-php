<?php

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/admin_ip.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('tp_parse_bool_env')) {
    function tp_parse_bool_env(string $name, bool $default = false): bool {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }
        $val = strtolower(trim((string)$raw));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('tp_auth_mode')) {
    function tp_auth_mode(): string {
        $mode = strtolower(trim((string)(getenv('AUTH_MODE') ?: 'basic')));
        if (!in_array($mode, ['basic', 'telegram'], true)) {
            return 'basic';
        }
        return $mode;
    }
}

if (!function_exists('tp_resolve_rights_host')) {
    function tp_resolve_rights_host(): string {
        $host = trim((string)(getenv('TRADEBOT_PHP_HOST') ?: ''));
        if ($host !== '') {
            return rtrim($host, '/');
        }
        return 'http://localhost';
    }
}

if (!function_exists('tp_is_private_network_ip')) {
    function tp_is_private_network_ip(string $remote): bool {
        $privateRanges = ['127.', '10.', '192.168.', '172.16.', '172.17.', '172.18.', '172.19.',
            '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.',
            '172.27.', '172.28.', '172.29.', '172.30.', '172.31.'];
        foreach ($privateRanges as $prefix) {
            if (strpos($remote, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('tp_fetch_user_rights_by_telegram')) {
    function tp_fetch_user_rights_by_telegram(int $telegram_id): ?string {
        if ($telegram_id <= 0) {
            return null;
        }

        $host = tp_resolve_rights_host();
        $raw = curl_http_request($host . '/get_user_rights.php?id=' . $telegram_id);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['rights']) && is_string($decoded['rights'])) {
            return trim($decoded['rights']);
        }

        $plain = trim((string)$raw);
        if ($plain !== '' && $plain[0] !== '{' && $plain[0] !== '[') {
            return $plain;
        }

        return null;
    }
}

if (!function_exists('tp_registered_users_count')) {
    function tp_registered_users_count(): ?int {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        global $db_configs;
        // Candidates in priority order: legacy 'signals_system' alias → new 'sigsys' name → 'trading' fallback.
        // Only DBs present in $db_configs are tried; passing a hostname as DB name is a bug and must not occur.
        $db_candidates = array_filter(
            ['signals_system', 'sigsys', 'trading'],
            static fn($db) => !isset($db_configs) || isset($db_configs[$db])
        );
        foreach ($db_candidates as $db_name) {
            $mysqli = init_remote_db($db_name);
            if (!$mysqli instanceof mysqli_ex) {
                continue;
            }

            if (!$mysqli->table_exists('chat_users')) {
                $mysqli->close();
                continue;
            }

            $count = intval($mysqli->select_value('COUNT(*)', 'chat_users'));
            $mysqli->close();
            $cached = max(0, $count);
            return $cached;
        }

        $cached = null;
        return $cached;
    }
}

if (!function_exists('tp_allow_local_bootstrap_admin')) {
    function tp_allow_local_bootstrap_admin(): bool {
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '' || !is_admin_ip($remote)) {
            return false;
        }

        $count = tp_registered_users_count();
        return $count !== null && $count === 0;
    }
}

if (!function_exists('get_user_rights')) {
    function get_user_rights(): string {
        if (isset($_SESSION['user_rights']) && is_string($_SESSION['user_rights'])) {
            return $_SESSION['user_rights'];
        }

        $mode = tp_auth_mode();
        if ($mode === 'basic') {
            $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (tp_allow_local_bootstrap_admin()) {
                return 'view,trade,admin';
            }
            if (is_admin_ip($remote)) {
                return 'view,trade,admin';
            }

            // Docker bridge and RFC-1918 private networks get read-only access in basic mode.
            $privateRanges = ['127.', '10.', '192.168.', '172.16.', '172.17.', '172.18.', '172.19.',
                '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.',
                '172.27.', '172.28.', '172.29.', '172.30.', '172.31.'];
            foreach ($privateRanges as $prefix) {
                if (strpos($remote, $prefix) === 0) {
                    return 'view';
                }
            }

            $allow_local_bypass = tp_parse_bool_env('ALLOW_LOCAL_ADMIN_BYPASS', false);
            if ($allow_local_bypass) {
                return 'view';
            }

            return 'none';
        }

        $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
        $telegram_id = intval($headers['x-user-id'] ?? 0);

        if ($telegram_id > 0) {
            $rights = tp_fetch_user_rights_by_telegram($telegram_id);
            if (is_string($rights) && $rights !== '') {
                $_SESSION['user_rights'] = $rights;
                return $rights;
            }
        }

        $allow_local_bypass = tp_parse_bool_env('ALLOW_LOCAL_ADMIN_BYPASS', false);
        if ($allow_local_bypass) {
            $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            if (is_admin_ip($remote)) {
                return 'view,trade,admin';
            }
        }

        if (tp_allow_local_bootstrap_admin()) {
            return 'view,trade,admin';
        }

        return 'none';
    }
}

$user_rights = get_user_rights();
