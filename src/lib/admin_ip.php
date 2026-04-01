<?php

if (!function_exists('tp_runtime_local_ips')) {
  function tp_runtime_local_ips(): array {
    static $cached = null;
    if (is_array($cached)) {
      return $cached;
    }

    $ips = [
      'localhost' => true,
      '127.0.0.1' => true,
      '::1' => true,
    ];

    $routeFile = '/proc/net/route';
    if (is_readable($routeFile)) {
      $lines = @file($routeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (is_array($lines)) {
        foreach ($lines as $index => $line) {
          if ($index === 0) {
            continue;
          }

          $parts = preg_split('/\s+/', trim((string)$line));
          if (!is_array($parts) || count($parts) < 3 || $parts[1] !== '00000000') {
            continue;
          }

          $gatewayHex = strtoupper(trim((string)$parts[2]));
          if (!preg_match('/^[0-9A-F]{8}$/', $gatewayHex)) {
            continue;
          }

          $chunks = array_reverse(str_split($gatewayHex, 2));
          $gatewayIp = implode('.', array_map('hexdec', $chunks));
          if (filter_var($gatewayIp, FILTER_VALIDATE_IP)) {
            $ips[$gatewayIp] = true;
          }
        }
      }
    }

    $cached = array_keys($ips);
    return $cached;
  }
}

function is_admin_ip(string $remote): bool {
  $default = array_fill_keys(tp_runtime_local_ips(), true);
  $raw = trim((string)(getenv('ADMIN_IP_ALLOWLIST') ?: ''));

  if ($raw !== '') {
    $parts = array_map('trim', explode(',', $raw));
    foreach ($parts as $ip) {
      if ($ip !== '') {
        $default[$ip] = true;
      }
    }
  }

  return isset($default[$remote]);
}