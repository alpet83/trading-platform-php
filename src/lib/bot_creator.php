<?php

/**
    * bot_creator.php — shared bot initialization logic.
    * Used by the REST API and basic-admin bootstrap form.
    */

if (!function_exists('bot_create')) {
    /**
     * Create a new bot: config table, table_map entry, and all runtime tables.
     *
     * @param object $mysqli  An open mysqli-compatible connection with select_value/try_query helpers.
     * @param string $bot_name
     * @param int    $account_id
     * @param array  $config   Associative array of config params.
     * @return array ['ok' => bool, 'error' => string, 'code' => int, 'applicant' => string]
     */
    function bot_create(object $mysqli, string $bot_name, int $account_id, array $config): array
    {
        $required_fields = [
            'exchange', 'trade_enabled', 'position_coef', 'monitor_enabled',
            'min_order_cost', 'max_order_cost', 'max_limit_distance',
            'signals_setup', 'report_color', 'debug_pair',
        ];

        $missing = [];
        foreach ($required_fields as $field) {
            if (!isset($config[$field]) || $config[$field] === '') {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            return ['ok' => false, 'code' => 400, 'error' => 'Missing required config fields: ' . implode(', ', $missing)];
        }

        if (!preg_match('/^[a-z0-9_]+$/i', $bot_name)) {
            return ['ok' => false, 'code' => 400, 'error' => 'Invalid bot_name. Use only alphanumeric and underscore'];
        }

        if ($account_id <= 0) {
            return ['ok' => false, 'code' => 400, 'error' => 'Invalid account_id. Must be positive integer'];
        }

        $applicant    = strtolower($bot_name) . '_bot';
        $config_table = 'config__' . strtolower($bot_name);
        $bot_prefix   = strtolower($bot_name);

        $existing = $mysqli->select_value('applicant', 'config__table_map', "WHERE applicant = '$applicant'");
        if ($existing) {
            return ['ok' => false, 'code' => 409, 'error' => "Bot already exists: $applicant"];
        }

        $table_exists = $mysqli->select_value('TABLE_NAME', 'INFORMATION_SCHEMA.TABLES',
            "WHERE TABLE_SCHEMA = 'trading' AND TABLE_NAME = '$config_table'");
        if ($table_exists) {
            return ['ok' => false, 'code' => 409, 'error' => "Config table already exists: $config_table"];
        }

        $mysqli->try_query('START TRANSACTION');

        // --- config table ---
        $ok = $mysqli->try_query("CREATE TABLE `$config_table` (
            param VARCHAR(32) NOT NULL,
            value VARCHAR(64) NOT NULL,
            PRIMARY KEY (param)
        ) COLLATE = utf8mb3_bin");
        if ($ok === false) {
            $mysqli->try_query('ROLLBACK');
            return ['ok' => false, 'code' => 500, 'error' => 'Failed to create config table: ' . $mysqli->error];
        }

        // --- table_map registration ---
        $ok = $mysqli->try_query("INSERT INTO config__table_map (table_name, account_id, applicant)
            VALUES ('$config_table', $account_id, '$applicant')");
        if ($ok === false) {
            $mysqli->try_query('ROLLBACK');
            return ['ok' => false, 'code' => 500, 'error' => 'Failed to register bot in table_map: ' . $mysqli->error];
        }

        // --- config params ---
        foreach ($config as $param => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pe = $mysqli->real_escape_string($param);
            $ve = $mysqli->real_escape_string($value);
            $ok = $mysqli->try_query("INSERT INTO `$config_table` (param, value)
                VALUES ('$pe', '$ve')");
            if ($ok === false) {
                $mysqli->try_query('ROLLBACK');
                return ['ok' => false, 'code' => 500, 'error' => "Failed to insert config param '$param': " . $mysqli->error];
            }
        }

        // --- runtime tables — all DDL lives in templates/bot_tables.sql ---
        $tpl_path = __DIR__ . '/../../templates/bot_tables.sql';
        if (!file_exists($tpl_path)) {
            $mysqli->try_query('ROLLBACK');
            return ['ok' => false, 'code' => 500, 'error' => 'bot_tables.sql template not found at: ' . $tpl_path];
        }

        $sql_raw = (string)file_get_contents($tpl_path);
        $sql_all = str_replace('#exchange', $bot_prefix, $sql_raw);
        foreach (explode(';', $sql_all) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            if ($mysqli->try_query($stmt) === false) {
                $mysqli->try_query('ROLLBACK');
                return ['ok' => false, 'code' => 500, 'error' => "Failed (bot_tables.sql): " . $mysqli->error];
            }
        }

        $mysqli->try_query('COMMIT');

        return ['ok' => true, 'code' => 201, 'applicant' => $applicant, 'account_id' => $account_id];
    }
}
