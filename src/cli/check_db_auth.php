<?php
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "CLI only\n");
        exit(1);
    }

    mysqli_report(MYSQLI_REPORT_OFF);

    $ok_count = 0;
    $warn_count = 0;
    $fail_count = 0;

    function auth_print(string $level, string $message): void {
        fwrite(STDOUT, sprintf('[%s] %s', $level, $message) . PHP_EOL);
    }

    function auth_ok(string $message): void {
        global $ok_count;
        $ok_count++;
        auth_print('OK', $message);
    }

    function auth_warn(string $message): void {
        global $warn_count;
        $warn_count++;
        auth_print('WARN', $message);
    }

    function auth_fail(string $message): void {
        global $fail_count;
        $fail_count++;
        auth_print('FAIL', $message);
    }

    function auth_get_arg(string $name, string $default = ''): string {
        global $argv;
        foreach (array_slice($argv, 1) as $arg) {
            if (strpos($arg, "--$name=") === 0) {
                return (string)substr($arg, strlen($name) + 3);
            }
        }
        return $default;
    }

    function auth_first_existing_config(array $paths): ?string {
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            $size = @filesize($path);
            if (is_numeric($size) && intval($size) > 0) {
                return $path;
            }
        }
        return null;
    }

    function auth_load_db_config(string $path): array {
        $db_configs = [];
        $db_servers = [];
        $db_alt_server = null;
        include $path;

        $replica = defined('MYSQL_REPLICA') ? strval(MYSQL_REPLICA) : '';
        return [
            'path' => $path,
            'db_configs' => is_array($db_configs) ? $db_configs : [],
            'db_servers' => is_array($db_servers) ? $db_servers : [],
            'db_alt_server' => is_string($db_alt_server) ? $db_alt_server : '',
            'replica' => $replica,
        ];
    }

    function auth_resolve_host(array $cfg): string {
        $env_host = trim((string)(getenv('SIGNALS_LEGACY_DB_HOST') ?: ''));
        if ($env_host !== '') {
            return $env_host;
        }

        if (isset($cfg['replica']) && is_string($cfg['replica']) && trim($cfg['replica']) !== '') {
            return trim($cfg['replica']);
        }

        if (isset($cfg['db_servers'][0]) && is_string($cfg['db_servers'][0]) && trim($cfg['db_servers'][0]) !== '') {
            return trim($cfg['db_servers'][0]);
        }

        if (isset($cfg['db_alt_server']) && is_string($cfg['db_alt_server']) && trim($cfg['db_alt_server']) !== '') {
            return trim($cfg['db_alt_server']);
        }

        return 'mariadb';
    }

    function auth_connect_ok(string $host, string $user, string $pass, string &$error): bool {
        $error = '';
        $db = @new mysqli($host, $user, $pass, 'information_schema');
        if (!$db || $db->connect_errno) {
            $error = $db ? (string)$db->connect_error : 'mysqli allocation failed';
            return false;
        }
        $db->close();
        return true;
    }

    function auth_mask_secret(string $secret): string {
        $len = strlen($secret);
        $hash = substr(sha1($secret), 0, 10);
        return sprintf('len=%d sha1=%s', $len, $hash);
    }

    function auth_pair_matches(array $pair, string $user, string $pass): bool {
        $u = strval($pair[0] ?? '');
        $p = strval($pair[1] ?? '');
        return $u === $user && $p === $pass;
    }

    $context = auth_get_arg('context', trim((string)getenv('HOSTNAME')));
    if ($context === '') {
        $context = 'unknown';
    }

    auth_print('INFO', "context=$context");

    $config_path = auth_first_existing_config([
        '/usr/local/etc/php/db_config.php',
        '/app/src/lib/db_config.php',
        '/app/datafeed/lib/db_config.php',
    ]);

    if (!$config_path) {
        auth_fail('no non-empty db_config.php found in known runtime paths');
        auth_print('INFO', sprintf('summary: ok=%d warn=%d fail=%d', $ok_count, $warn_count, $fail_count));
        exit(1);
    }

    $cfg = auth_load_db_config($config_path);
    auth_ok("using db_config: $config_path");

    $db_configs = $cfg['db_configs'];
    if (!is_array($db_configs) || count($db_configs) === 0) {
        auth_fail("db_config has no db_configs map: $config_path");
        auth_print('INFO', sprintf('summary: ok=%d warn=%d fail=%d', $ok_count, $warn_count, $fail_count));
        exit(1);
    }

    $db_host = auth_resolve_host($cfg);
    auth_ok("db host candidate: $db_host");

    $preferred_keys = ['trading', 'datafeed', 'sigsys'];
    $tested = [];

    foreach ($preferred_keys as $db_key) {
        if (!isset($db_configs[$db_key]) || !is_array($db_configs[$db_key])) {
            continue;
        }

        $pair = $db_configs[$db_key];
        $db_user = strval($pair[0] ?? '');
        $db_pass = strval($pair[1] ?? '');

        if ($db_user === '') {
            auth_fail("db_config[$db_key] has empty user");
            continue;
        }

        $fingerprint = $db_user . "\0" . $db_pass;
        if (isset($tested[$fingerprint])) {
            auth_ok("db_config[$db_key] reuses credentials from db_config[{$tested[$fingerprint]}]");
            continue;
        }

        $tested[$fingerprint] = $db_key;
        $err = '';
        if (auth_connect_ok($db_host, $db_user, $db_pass, $err)) {
            auth_ok("db_config[$db_key] auth works for user '$db_user' (" . auth_mask_secret($db_pass) . ')');
        } else {
            auth_fail("db_config[$db_key] auth failed for user '$db_user': $err");
        }
    }

    $env_user = trim((string)(getenv('SIGNALS_LEGACY_DB_USER') ?: ''));
    $env_pass = strval(getenv('SIGNALS_LEGACY_DB_PASSWORD') ?: '');
    $env_name = trim((string)(getenv('SIGNALS_LEGACY_DB_NAME') ?: ''));

    if ($env_user !== '' || $env_name !== '') {
        if ($env_user === '') {
            auth_fail('SIGNALS_LEGACY_DB_NAME is set but SIGNALS_LEGACY_DB_USER is empty');
        } else {
            $err = '';
            if (auth_connect_ok($db_host, $env_user, $env_pass, $err)) {
                auth_ok("SIGNALS_LEGACY_DB_* env auth works for user '$env_user' (" . auth_mask_secret($env_pass) . ')');
            } else {
                auth_fail("SIGNALS_LEGACY_DB_* env auth failed for user '$env_user': $err");
            }

            $ref_pair = null;
            $ref_key = '';
            if (isset($db_configs['sigsys']) && is_array($db_configs['sigsys'])) {
                $ref_pair = $db_configs['sigsys'];
                $ref_key = 'sigsys';
            } elseif (isset($db_configs['trading']) && is_array($db_configs['trading'])) {
                $ref_pair = $db_configs['trading'];
                $ref_key = 'trading';
            }

            if ($ref_pair) {
                if (auth_pair_matches($ref_pair, $env_user, $env_pass)) {
                    auth_ok("env credentials match db_config[$ref_key]");
                } else {
                    auth_fail(
                        "credential conflict: env SIGNALS_LEGACY_DB_* differs from db_config[$ref_key] " .
                        "(env user='$env_user' " . auth_mask_secret($env_pass) .
                        ", cfg user='" . strval($ref_pair[0] ?? '') . "' " . auth_mask_secret(strval($ref_pair[1] ?? '')) . ')'
                    );
                }
            } else {
                auth_warn('env SIGNALS_LEGACY_DB_* is set, but db_config has no sigsys/trading pair to compare');
            }
        }
    } else {
        auth_warn('SIGNALS_LEGACY_DB_* env is not set in this container');
    }

    auth_print('INFO', sprintf('summary: ok=%d warn=%d fail=%d', $ok_count, $warn_count, $fail_count));
    exit($fail_count > 0 ? 1 : 0);
?>
