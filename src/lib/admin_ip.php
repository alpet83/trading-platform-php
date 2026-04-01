<?php

function is_admin_ip(string $remote): bool {
    $default = ['localhost', '127.0.0.1', '::1'];
    $raw = trim((string)(getenv('ADMIN_IP_ALLOWLIST') ?: ''));

    if ($raw === '') {
    $list = $default;
    } else {
    $parts = array_map('trim', explode(',', $raw));
    $list = array_values(array_filter($parts, static fn($ip) => $ip !== ''));
    if (count($list) === 0) {
      $list = $default;
    }
    }

    return in_array($remote, $list, true);
}