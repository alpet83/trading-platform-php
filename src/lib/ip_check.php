<?php

if (!function_exists('tp_hive_host_candidates')) {
  function tp_hive_host_candidates(): array {
    $hosts = [];

    $raw = trim((string)(getenv('BOT_SERVER_HOSTS') ?: ''));
    if ($raw !== '') {
      foreach (explode(',', $raw) as $host) {
        $host = trim($host);
        if ($host !== '') {
          $hosts[$host] = true;
        }
      }
    }

    foreach (['BOT_SERVER_HOST', 'BOTS_HIVE_HOST'] as $envName) {
      $host = trim((string)(getenv($envName) ?: ''));
      if ($host !== '') {
        $hosts[$host] = true;
      }
    }

    foreach (['bots-hive', 'trd-bots-hive', 'bot'] as $fallbackHost) {
      $hosts[$fallbackHost] = true;
    }

    return array_keys($hosts);
  }
}

if (!function_exists('tp_resolve_host_ips')) {
  function tp_resolve_host_ips(string $host): array {
    $host = trim($host);
    if ($host === '') {
      return [];
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
      return [$host];
    }

    $ips = [];
    $resolved = @gethostbynamel($host);
    if (is_array($resolved)) {
      foreach ($resolved as $ip) {
        $ip = trim((string)$ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
          $ips[$ip] = true;
        }
      }
    }

    $single = @gethostbyname($host);
    if (is_string($single) && $single !== '' && $single !== $host && filter_var($single, FILTER_VALIDATE_IP)) {
      $ips[$single] = true;
    }

    return array_keys($ips);
  }
}

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

  if (!function_exists('tp_register_hive_ip_if_needed')) {
    function tp_register_hive_ip_if_needed(string $remote, array &$allowMap): bool {
      if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) {
        return false;
      }

      foreach (tp_hive_host_candidates() as $host) {
        $ips = tp_resolve_host_ips($host);
        if (!in_array($remote, $ips, true)) {
          continue;
        }

        $allowMap[$remote] = true;

        $whiteListFile = __DIR__ . '/.allowed_ip.lst';
        $line = $remote . "\n";
        if (!is_file($whiteListFile)) {
          @file_put_contents($whiteListFile, $line, LOCK_EX);
          return true;
        }

        $existing = @file($whiteListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($existing) && in_array($remote, $existing, true)) {
          return true;
        }

        @file_put_contents($whiteListFile, $line, FILE_APPEND | LOCK_EX);
        return true;
      }

      return false;
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

    $allowMap = array_fill_keys(tp_load_allowed_ips(), true);
    if (!isset($allowMap[$remote])) {
      tp_register_hive_ip_if_needed($remote, $allowMap);
    }

    if (!isset($allowMap[$remote])) {
    http_response_code(403);
    $msg = str_replace('%IP', $remote, $msg);
    if ($die) {
      die($msg);
    }
    return false;
    }

    return true;
}

