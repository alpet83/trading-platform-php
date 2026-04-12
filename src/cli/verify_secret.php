<?php
    /**
     * CLI: verify DB secret round-trip for a given bot.
     * Usage: php src/cli/verify_secret.php <bot_name> [account_id]
     *
     * Reads api_secret_s0/sep/s1 from DB, reassembles the v1: payload,
     * decrypts it, and prints the result so it can be compared with the
     * original plaintext key.
     */
    if (PHP_SAPI !== 'cli') {
        echo "CLI only\n";
        exit(1);
    }

    include_once(__DIR__.'/../lib/common.php');
    include_once(__DIR__.'/../lib/db_tools.php');
    include_once(__DIR__.'/../lib/db_config.php');

    function verify_fail(string $msg, int $code = 1): void {
        fwrite(STDERR, "#ERROR: $msg\n");
        exit($code);
    }

    function verify_info(string $msg): void {
        fwrite(STDOUT, "$msg\n");
    }

    function resolve_manager_key(): string {
        $key = trim((string)(getenv('BOT_MANAGER_SECRET_KEY') ?: ''));
        if (strlen($key))
            return $key;

        $key_file = trim((string)(getenv('BOT_MANAGER_SECRET_KEY_FILE') ?: '/run/secrets/bot_manager_key'));
        if (strlen($key_file) && is_file($key_file)) {
            $v = trim((string)@file_get_contents($key_file));
            if (strlen($v))
                return $v;
        }

        foreach (['/run/secrets/bot_manager_secret_key', '/run/secrets/bot_manager_master_key'] as $f)
            if (is_file($f)) {
                $v = trim((string)@file_get_contents($f));
                if (strlen($v))
                    return $v;
            }

        return '';
    }

    function decrypt_v1(string $packed, string $master): string {
        $packed = trim($packed);
        if (strpos($packed, 'v1:') !== 0)
            return '';

        $raw = base64_decode(substr($packed, 3), true);
        if ($raw === false || strlen($raw) < 29)
            return '';

        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt(
            $cipher, 'aes-256-gcm',
            hash('sha256', $master, true),
            OPENSSL_RAW_DATA,
            $iv, $tag
        );
        return ($plain === false) ? '' : trim((string)$plain);
    }

    // ---- args ----
    $bot_arg = trim((string)($argv[1] ?? ''));
    if (!strlen($bot_arg))
        verify_fail('usage: php src/cli/verify_secret.php <bot_name> [account_id]');

    $filter_aid = isset($argv[2]) ? intval($argv[2]) : 0;

    if (!function_exists('init_remote_db'))
        verify_fail('init_remote_db() unavailable; check include path');

    $master_key = resolve_manager_key();
    if (!strlen($master_key))
        verify_fail('BOT_MANAGER_SECRET_KEY is empty (or key file missing)');

    $applicant = strtolower($bot_arg);
    if (!str_ends_with($applicant, '_bot'))
        $applicant .= '_bot';

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = init_remote_db('trading');
    if (!$db)
        verify_fail('failed connect to trading DB');

    $appEsc = $db->real_escape_string($applicant);
    $table = $db->select_value('table_name', 'config__table_map', "WHERE applicant='$appEsc'");
    if (!$table)
        verify_fail("bot '$applicant' not found in config__table_map");

    $secret_param = trim((string)(getenv('BOT_DB_API_SECRET_PARAM') ?: 'api_secret'));

    $where_aid = $filter_aid > 0 ? "WHERE account_id = $filter_aid AND account_id > 0" : 'WHERE account_id > 0';
    $rows = $db->select_rows('DISTINCT account_id AS aid', $table, "$where_aid ORDER BY account_id", MYSQLI_ASSOC);
    if (!is_array($rows) || count($rows) === 0)
        verify_fail("no account_id rows in $table" . ($filter_aid > 0 ? " for account_id=$filter_aid" : ''));

    $read = function(string $param) use ($db, $table): string {
        // helper: iterate all account_ids is caller's job; this reads a single param
        return '';
    };

    foreach ($rows as $row) {
        $aid = intval($row['aid'] ?? 0);
        if ($aid <= 0)
            continue;

        $esc = $db->real_escape_string($secret_param);
        $flag   = intval($db->select_value('value', $table, "WHERE account_id=$aid AND param='secret_key_encrypted'"));

        // --- show raw DB columns for this account ---
        $api_secret      = trim((string)$db->select_value('value', $table, "WHERE account_id=$aid AND param='$esc'"));
        $s0              = trim((string)$db->select_value('value', $table, "WHERE account_id=$aid AND param='{$esc}_s0'"));
        $s1              = trim((string)$db->select_value('value', $table, "WHERE account_id=$aid AND param='{$esc}_s1'"));
        $sep_stored      = $db->select_value('value', $table, "WHERE account_id=$aid AND param='{$esc}_sep'");
        $sep             = is_string($sep_stored) ? $sep_stored : '';

        verify_info(sprintf("--- account_id=%d (secret_key_encrypted=%d) ---", $aid, $flag));
        verify_info(sprintf("  api_secret        = '%s' (len=%d)", $api_secret, strlen($api_secret)));
        verify_info(sprintf("  api_secret_s0     = '%s' (len=%d)", $s0, strlen($s0)));
        verify_info(sprintf("  api_secret_sep    = '%s'", $sep));
        verify_info(sprintf("  api_secret_s1     = '%s' (len=%d)", $s1, strlen($s1)));

        // --- attempt reassembly ---
        if (strlen($api_secret)) {
            $packed = $api_secret;
            verify_info("  > assembled from api_secret directly");
        } elseif (strlen($s0) && strlen($s1) && strlen($sep)) {
            $packed = $s0 . $sep . $s1;
            verify_info(sprintf(
                "  > assembled: s0[%d] + sep('%s') + s1[%d] = packed[%d]",
                strlen($s0), $sep, strlen($s1), strlen($packed)
            ));
        } else {
            verify_info("  > SKIP: no secret data found");
            continue;
        }

        // --- detect: does it look encrypted? ---
        $looks_v1 = (strpos($packed, 'v1:') === 0);
        verify_info(sprintf("  > packed preview    = %s", substr($packed, 0, 80) . (strlen($packed) > 80 ? '...' : '')));
        verify_info(sprintf("  > looks_encrypted   = %s", $looks_v1 ? 'YES (v1: prefix present)' : 'NO'));

        if ($looks_v1) {
            $decrypted = decrypt_v1($packed, $master_key);
            if (strlen($decrypted)) {
                verify_info(sprintf("  > DECRYPTED secret  = '%s' (len=%d)", $decrypted, strlen($decrypted)));
            } else {
                verify_info("  > DECRYPT FAILED (wrong master key, corrupted payload, or OpenSSL error)");
            }
        } else {
            // plaintext: just show as-is
            verify_info(sprintf("  > Plaintext secret  = '%s' (len=%d)", $packed, strlen($packed)));
        }
        verify_info('');
    }

    verify_info("#DONE: check complete for bot=$applicant, table=$table");
    exit(0);
?>
