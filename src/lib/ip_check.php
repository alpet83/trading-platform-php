<?php

if (!function_exists('tp_load_allowed_ips')) {
  function tp_load_allowed_ips(): array {
    $allow = [
      'localhost' => true,
      '127.0.0.1' => true,
      '::1' => true,
    ];

    $raw = trim((string)(getenv('ADMIN_IP_ALLOWLIST') ?: ''));
    if ($raw !== '') {
      foreach (explode(',', $raw) as $ip) {
        $ip = trim($ip);
        if ($ip !== '') {
          $allow[$ip] = true;
        }
      }
    }

    $white_list_file = __DIR__ . '/.allowed_ip.lst';
    if (is_file($white_list_file)) {
      $white_list = file($white_list_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (is_array($white_list)) {
        foreach ($white_list as $ip) {
          $ip = trim((string)$ip);
          if ($ip === '' || $ip[0] === '#') {
            continue;
          }
          $allow[$ip] = true;
        }
      }
    }

    return array_keys($allow);
  }
}

function ip_check(string $msg = 'Not allowed for %IP', bool $die = true): bool {
  if (!isset($_SERVER) || !isset($_SERVER['REMOTE_ADDR'])) {
    return true;
  }

  $remote = trim((string)$_SERVER['REMOTE_ADDR']);
  if ($remote === '') {
    return true;
  }

  $allow_ip = tp_load_allowed_ips();
  if (!in_array($remote, $allow_ip, true)) {
    http_response_code(403);
    $msg = str_replace('%IP', $remote, $msg);
    if ($die) {
      die($msg);
    }
    return false;
  }

  return true;
}

