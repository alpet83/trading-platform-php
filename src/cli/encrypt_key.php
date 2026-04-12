<?php
    if (PHP_SAPI !== 'cli') {
        echo "CLI only\n";
        exit(1);
    }

    include_once(__DIR__.'/../lib/common.php');
    include_once(__DIR__.'/../lib/db_tools.php');
    include_once(__DIR__.'/../lib/db_config.php');

    function cli_fail(string $msg, int $code = 1): void {
        fwrite(STDERR, "#ERROR: $msg\n");
        exit($code);
    }

    function cli_info(string $msg): void {
        fwrite(STDOUT, "$msg\n");
    }

    function resolve_manager_secret_key(): string {
        $key = trim((string)(getenv('BOT_MANAGER_SECRET_KEY') ?: ''));
        if (strlen($key))
            return $key;

        $key_file = trim((string)(getenv('BOT_MANAGER_SECRET_KEY_FILE') ?: '/run/secrets/bot_manager_key'));
        if (strlen($key_file) && is_file($key_file)) {
            $key = trim((string)@file_get_contents($key_file));
            if (strlen($key))
                return $key;
        }

        $fallbacks = ['/run/secrets/bot_manager_secret_key', '/run/secrets/bot_manager_master_key'];
        foreach ($fallbacks as $file)
            if (is_file($file)) {
                $key = trim((string)@file_get_contents($file));
                if (strlen($key))
                    return $key;
            }

        return '';
    }

    function encrypt_secret_v1(string $plain, string $master): string {
        if (!function_exists('openssl_encrypt'))
            return '';

        $plain = trim($plain);
        if (!strlen($plain) || !strlen($master))
            return '';

        try {
            $iv = random_bytes(12);
        } catch (Throwable $E) {
            return '';
        }

        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $master, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || strlen($tag) !== 16)
            return '';

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    function split_secret(string $secret): array {
        $len = strlen($secret);
        if ($len < 3)
            return ['', '', ''];

        $pos = intdiv($len, 2);
        if ($pos < 1 || $pos >= $len)
            return ['', '', ''];

        $s0 = substr($secret, 0, $pos);
        $sep = substr($secret, $pos, 1);
        $s1 = substr($secret, $pos + 1);
        if (!strlen($s0) || !strlen($sep) || !strlen($s1))
            return ['', '', ''];

        return [$s0, $sep, $s1];
    }

    function db_upsert($mysqli, string $table, int $account_id, string $param, string $value): void {
        $p = $mysqli->real_escape_string($param);
        $v = $mysqli->real_escape_string($value);
        $exists = $mysqli->select_value('param', $table, "WHERE account_id = $account_id AND param = '$p'");
        if ($exists)
            $mysqli->try_query("UPDATE `$table` SET value = '$v' WHERE account_id = $account_id AND param = '$p'");
        else
            $mysqli->try_query("INSERT INTO `$table` (account_id,param,value) VALUES ($account_id,'$p','$v')");
    }

    function load_secret_raw($mysqli, string $table, int $account_id, string $secret_param): string {
        $secret = trim((string)$mysqli->select_value('value', $table, "WHERE account_id = $account_id AND param = '$secret_param'"));
        if (strlen($secret))
            return $secret;

        $s0 = trim((string)$mysqli->select_value('value', $table, "WHERE account_id = $account_id AND param = '{$secret_param}_s0'"));
        $s1 = trim((string)$mysqli->select_value('value', $table, "WHERE account_id = $account_id AND param = '{$secret_param}_s1'"));
        if (!strlen($s0) || !strlen($s1))
            return '';

        $sep = trim((string)$mysqli->select_value('value', $table, "WHERE account_id = $account_id AND param = '{$secret_param}_sep'"));
        if (!strlen($sep))
            $sep = '-';

        return $s0 . $sep . $s1;
    }

    $bot_arg = trim((string)($argv[1] ?? ''));
    if (!strlen($bot_arg))
        cli_fail('usage: php src/cli/encrypt_key.php <bot|bot_bot>');

    if (!function_exists('init_remote_db'))
        cli_fail('init_remote_db() is unavailable; check include path/runtime libs');

    $master_key = resolve_manager_secret_key();
    if (!strlen($master_key))
        cli_fail('BOT_MANAGER_SECRET_KEY is empty (or key file is missing)');

    $applicant = strtolower($bot_arg);
    if (!str_ends_with($applicant, '_bot'))
        $applicant .= '_bot';

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('trading');
    if (!$mysqli)
        cli_fail('failed connect to trading DB');

    $appEsc = $mysqli->real_escape_string($applicant);
    $table = $mysqli->select_value('table_name', 'config__table_map', "WHERE applicant = '$appEsc'");
    if (!$table)
        cli_fail("bot '$applicant' not found in config__table_map");

    $secret_param = trim((string)(getenv('BOT_DB_API_SECRET_PARAM') ?: 'api_secret'));
    $account_rows = $mysqli->select_rows('DISTINCT account_id AS aid', $table, 'WHERE account_id > 0 ORDER BY account_id', MYSQLI_ASSOC);
    if (!is_array($account_rows) || count($account_rows) === 0)
        cli_fail("no account_id rows in $table");

    $updated = 0;
    $skipped = 0;

    foreach ($account_rows as $row) {
        $aid = intval($row['aid'] ?? 0);
        if ($aid <= 0)
            continue;

        $flag = intval($mysqli->select_value('value', $table, "WHERE account_id = $aid AND param = 'secret_key_encrypted'"));
        if ($flag === 1) {
            cli_info("#INFO: account_id=$aid already has secret_key_encrypted=1, skip");
            $skipped++;
            continue;
        }

        $param_candidates = [$secret_param, $secret_param . '_ro'];
        $encrypted_any = false;

        foreach ($param_candidates as $param) {
            $plain = load_secret_raw($mysqli, $table, $aid, $param);
            if (!strlen($plain))
                continue;

            if (strpos($plain, 'v1:') === 0) {
                cli_info("#INFO: account_id=$aid param=$param already looks encrypted");
                $encrypted_any = true;
                continue;
            }

            $packed = encrypt_secret_v1($plain, $master_key);
            if (!strlen($packed))
                cli_fail("failed encrypt secret for account_id=$aid, param=$param");

            [$s0, $sep, $s1] = split_secret($packed);
            if (!strlen($s0) || !strlen($sep) || !strlen($s1))
                cli_fail("failed split encrypted payload for account_id=$aid, param=$param");

            db_upsert($mysqli, $table, $aid, $param, '');
            db_upsert($mysqli, $table, $aid, $param . '_s0', $s0);
            db_upsert($mysqli, $table, $aid, $param . '_s1', $s1);
            db_upsert($mysqli, $table, $aid, $param . '_sep', $sep);
            $encrypted_any = true;

            cli_info("#OK: account_id=$aid param=$param encrypted (len=" . strlen($packed) . ")");
        }

        if ($encrypted_any) {
            db_upsert($mysqli, $table, $aid, 'secret_key_encrypted', '1');
            $updated++;
        } else {
            cli_info("#WARN: account_id=$aid has no secret params to encrypt");
        }
    }

    if ($updated === 0) {
        if ($skipped > 0)
            cli_info('#INFO: all matched accounts already encrypted, nothing to do');
        else
            cli_info('#WARN: no accounts were updated');
        exit(0);
    }

    cli_info("#DONE: bot=$applicant, updated_accounts=$updated, skipped=$skipped");
    exit(0);
?>
