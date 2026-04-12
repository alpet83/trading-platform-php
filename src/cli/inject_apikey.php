<?php
    /**
     * CLI: interactive API-key injection into MariaDB bot config.
     *
     * Usage:
     *   php src/cli/inject_apikey.php [bot_name]
     *
     * Optional env overrides:
     *   BOT_DB_API_KEY_PARAM    (default: api_key)
     *   BOT_DB_API_SECRET_PARAM (default: api_secret)
     *   BOT_MANAGER_SECRET_KEY  | BOT_MANAGER_SECRET_KEY_FILE  (for encryption)
     */
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "CLI only\n");
        exit(1);
    }

    include_once(__DIR__ . '/../lib/common.php');
    include_once(__DIR__ . '/../lib/db_tools.php');
    include_once(__DIR__ . '/../lib/db_config.php');

    // ── Output helpers ────────────────────────────────────────────────────────

    function ik_out(string $text = ''): void {
        fwrite(STDOUT, $text . "\n");
    }

    function ik_err(string $text): void {
        fwrite(STDERR, $text . "\n");
    }

    function ik_fail(string $msg, int $code = 1): never {
        ik_err("#ERROR: $msg");
        exit($code);
    }

    function ik_prompt(string $label, string $default = ''): string {
        $hint = $default !== '' ? " [$default]" : '';
        fwrite(STDOUT, "$label$hint: ");
        fflush(STDOUT);
        $val = fgets(STDIN);
        $val = is_string($val) ? trim($val) : '';
        return $val !== '' ? $val : $default;
    }

    function ik_prompt_secret(string $label): string {
        fwrite(STDOUT, "$label: ");
        fflush(STDOUT);
        $val = fgets(STDIN);
        return is_string($val) ? trim($val) : '';
    }

    function ik_pick(array $items, string $title, int $default = 1): int {
        ik_out('');
        ik_out($title . ':');
        foreach ($items as $idx => $label)
            ik_out(sprintf('  %d) %s', $idx + 1, $label));

        while (true) {
            $hint = $default > 0 ? " [$default]" : '';
            fwrite(STDOUT, "Select number (0 to exit)$hint: ");
            $raw = fgets(STDIN);
            $raw = is_string($raw) ? trim($raw) : '';
            if ($raw === '' && $default > 0)
                $raw = (string)$default;
            if ($raw === '0')
                return -1;
            if (ctype_digit($raw)) {
                $i = intval($raw) - 1;
                if ($i >= 0 && $i < count($items))
                    return $i;
            }
            ik_err('Invalid selection, try again.');
        }
    }

    // ── Encryption (identical to encrypt_key.php / inject-api-keys.sh) ───────

    function ik_resolve_master_key(): string {
        $key = trim((string)(getenv('BOT_MANAGER_SECRET_KEY') ?: ''));
        if (strlen($key))
            return $key;

        $key_file = trim((string)(getenv('BOT_MANAGER_SECRET_KEY_FILE') ?: '/run/secrets/bot_manager_key'));
        if (strlen($key_file) && is_file($key_file)) {
            $k = trim((string)@file_get_contents($key_file));
            if (strlen($k))
                return $k;
        }

        foreach (['/run/secrets/bot_manager_secret_key', '/run/secrets/bot_manager_master_key'] as $f)
            if (is_file($f)) {
                $k = trim((string)@file_get_contents($f));
                if (strlen($k))
                    return $k;
            }

        return '';
    }

    function ik_encrypt(string $plain, string $master): string {
        if (!function_exists('openssl_encrypt'))
            ik_fail('openssl extension is not available');

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $master, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || strlen($tag) !== 16)
            ik_fail('openssl_encrypt failed');

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    /** @return array{string, string, string} [s0, sep, s1] */
    function ik_split_secret(string $secret): array {
        $len = strlen($secret);
        if ($len < 3)
            ik_fail('API secret too short to split (min 3 chars)');

        $pos = intdiv($len, 2);
        $s0  = substr($secret, 0, $pos);
        $sep = substr($secret, $pos, 1);
        $s1  = substr($secret, $pos + 1);

        if (!strlen($s0) || !strlen($sep) || !strlen($s1))
            ik_fail('secret split produced empty part');

        return [$s0, $sep, $s1];
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    function ik_upsert(object $db, string $table, string $param, string $value): void {
        $p = $db->real_escape_string($param);
        $v = $db->real_escape_string($value);
        $exists = $db->select_value('param', $table, "WHERE param = '$p'");
        if ($exists)
            $db->try_query("UPDATE `$table` SET value = '$v' WHERE param = '$p'");
        else
            $db->try_query("INSERT INTO `$table` (param,value) VALUES ('$p','$v')");
    }

    // ── Main ──────────────────────────────────────────────────────────────────

    if (!function_exists('init_remote_db'))
        ik_fail('init_remote_db() unavailable — check include path / runtime libs');

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = init_remote_db('trading');
    if (!$db)
        ik_fail('failed to connect to trading DB');

    $param_key    = trim((string)(getenv('BOT_DB_API_KEY_PARAM')    ?: 'api_key'));
    $param_secret = trim((string)(getenv('BOT_DB_API_SECRET_PARAM') ?: 'api_secret'));

    // ── Step 1: choose bot ────────────────────────────────────────────────────

    $bot_arg = trim((string)($argv[1] ?? ''));

    $bot_rows = $db->select_rows('applicant, table_name', 'config__table_map', 'ORDER BY applicant', MYSQLI_ASSOC);
    if (!is_array($bot_rows) || count($bot_rows) === 0)
        ik_fail('no bots found in config__table_map');

    $applicant = '';
    $cfg_table = '';

    if ($bot_arg !== '') {
        $cand = strtolower($bot_arg);
        if (!str_ends_with($cand, '_bot'))
            $cand .= '_bot';
        foreach ($bot_rows as $r) {
            if (strtolower((string)($r['applicant'] ?? '')) === $cand) {
                $applicant = (string)$r['applicant'];
                $cfg_table = (string)$r['table_name'];
                break;
            }
        }
        if ($applicant === '')
            ik_fail("bot '$cand' not found in config__table_map");
    } else {
        $labels = array_map(fn($r) => sprintf('%-24s  (table: %s)', $r['applicant'], $r['table_name']), $bot_rows);
        $idx = ik_pick($labels, 'Available bots');
        if ($idx < 0)
            exit(0);
        $applicant = (string)$bot_rows[$idx]['applicant'];
        $cfg_table = (string)$bot_rows[$idx]['table_name'];
    }

    ik_out("#INFO: bot=$applicant  table=$cfg_table");

    // ── Step 2: read account_id from config__table_map ────────────────────────

    $account_id = intval($db->select_value('account_id', 'config__table_map', "WHERE table_name = '$cfg_table' LIMIT 1") ?? 0);
    if ($account_id <= 0)
        ik_fail('account_id not found in config__table_map for table: ' . $cfg_table);

    ik_out("#INFO: account_id=$account_id");

    // ── Step 3: read credentials ──────────────────────────────────────────────

    ik_out('');
    $api_key = ik_prompt_secret('API key');
    if (!strlen($api_key))
        ik_fail('API key is empty');

    $api_secret = ik_prompt_secret('API secret');
    if (!strlen($api_secret))
        ik_fail('API secret is empty');

    // ── Step 4: encryption choice ─────────────────────────────────────────────

    $master_key = ik_resolve_master_key();
    $has_master = strlen($master_key) > 0;

    $do_encrypt = false;
    if ($has_master) {
        $enc_choice = ik_prompt('Encrypt secret with bot_manager key? (y/n)', 'y');
        $do_encrypt = (strtolower($enc_choice) !== 'n');
    } else {
        ik_out('#WARN: BOT_MANAGER_SECRET_KEY not found — secret stored split, unencrypted');
    }

    // ── Step 5: write to DB ───────────────────────────────────────────────────

    ik_out('');
    ik_upsert($db, $cfg_table, $param_key, $api_key);
    ik_out("#INFO: wrote $param_key");

    if ($do_encrypt) {
        $packed = ik_encrypt($api_secret, $master_key);
        [$s0, $sep, $s1] = ik_split_secret($packed);
        ik_upsert($db, $cfg_table, $param_secret,        '');
        ik_upsert($db, $cfg_table, $param_secret . '_s0', $s0);
        ik_upsert($db, $cfg_table, $param_secret . '_sep', $sep);
        ik_upsert($db, $cfg_table, $param_secret . '_s1', $s1);
        ik_upsert($db, $cfg_table, 'secret_key_encrypted', '1');
        ik_out("#INFO: wrote $param_secret (AES-256-GCM encrypted, split len=" . strlen($packed) . ')');
    } else {
        [$s0, $sep, $s1] = ik_split_secret($api_secret);
        ik_upsert($db, $cfg_table, $param_secret,        '');
        ik_upsert($db, $cfg_table, $param_secret . '_s0', $s0);
        ik_upsert($db, $cfg_table, $param_secret . '_sep', $sep);
        ik_upsert($db, $cfg_table, $param_secret . '_s1', $s1);
        ik_upsert($db, $cfg_table, 'secret_key_encrypted', '0');
        ik_out("#INFO: wrote $param_secret (split at pos=" . strlen($s0) . ", sep='$sep')");
    }

    // ── Step 6: verify readback ───────────────────────────────────────────────

    ik_out('');
    $stored_key = (string)$db->select_value('value', $cfg_table, "WHERE param = '" . $db->real_escape_string($param_key) . "'");
    $stored_enc = (string)$db->select_value('value', $cfg_table, "WHERE param = 'secret_key_encrypted'");

    if (trim($stored_key) !== trim($api_key))
        ik_fail('readback mismatch: stored api_key differs from input');

    ik_out("#OK: readback verified — $param_key matches, secret_key_encrypted=$stored_enc");
    ik_out("#SUCCESS: credentials injected for bot='$applicant', account_id=$account_id");
    exit(0);
