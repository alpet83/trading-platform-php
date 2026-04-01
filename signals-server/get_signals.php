<?php
    require_once('lib/common.php');
    require_once('lib/db_tools.php');
    require_once('lib/ip_check.php');
    include_once('/usr/local/etc/php/db_config.php');

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('trading');

    if (!$mysqli) {
        error_log("#DB-SERVER: $db_alt_server");
        if (is_array($db_profile)) {
                $db_user = $db_profile[0];
        }
        error_log('#DB_PROFILE: '.json_encode($db_profile));
        die("FATAL: user '$db_user' can't connect to servers ".json_encode($db_servers).", $db_error\n");
    }

    function extract_client_ip(): string {
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if ($ip !== '') {
            return $ip;
        }
    }
    if (isset($_SERVER['REMOTE_ADDR'])) {
        return (string)$_SERVER['REMOTE_ADDR'];
    }
    return '';
    }

    function resolve_client_host(string $ip): string {
    if ($ip === '') {
        return '';
    }

    if (isset($_SERVER['REMOTE_HOST']) && $_SERVER['REMOTE_HOST'] !== '') {
        return (string)$_SERVER['REMOTE_HOST'];
    }

    // Reverse DNS can be very slow and unstable on production networks.
    // Keep hostname empty unless web server provided REMOTE_HOST.
    return '';
    }

    function ensure_signals_stats_table(mysqli_ex $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS `signals_stats` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpoint` VARCHAR(64) NOT NULL,
    `remote_ip` VARCHAR(64) NOT NULL,
    `remote_host` VARCHAR(255) NOT NULL DEFAULT '',
    `src_account` INT NOT NULL DEFAULT 0,
    `setup_raw` VARCHAR(255) NOT NULL DEFAULT '',
    `view_name` VARCHAR(32) NOT NULL DEFAULT 'json',
    `out_format` VARCHAR(32) NOT NULL DEFAULT 'json',
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_endpoint_ip` (`endpoint`,`remote_ip`),
    KEY `idx_endpoint_last_seen` (`endpoint`,`last_seen`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->query($sql);
    }

    function track_get_signals_request(mysqli_ex $db, string $ip, int $accountId, string $setupRaw, string $viewName, string $outFormat): void {
    if ($ip === '') {
        return;
    }

    $endpoint = 'get_signals.php';
    $host = resolve_client_host($ip);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';

    $setupRaw = substr($setupRaw, 0, 255);
    $viewName = substr($viewName, 0, 32);
    $outFormat = substr($outFormat, 0, 32);
    $ua = substr($ua, 0, 255);

    $sql = "INSERT INTO `signals_stats`
    (`endpoint`, `remote_ip`, `remote_host`, `src_account`, `setup_raw`, `view_name`, `out_format`, `user_agent`)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      `remote_host` = ?,
      `src_account` = ?,
      `setup_raw` = ?,
      `view_name` = ?,
      `out_format` = ?,
      `user_agent` = ?,
      `hits` = `hits` + 1,
      `last_seen` = CURRENT_TIMESTAMP";

    $maxAttempts = 2;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return;
        }

        $stmt->bind_param(
            'sssisssssissss',
            $endpoint,
            $ip,
            $host,
            $accountId,
            $setupRaw,
            $viewName,
            $outFormat,
            $ua,
            $host,
            $accountId,
            $setupRaw,
            $viewName,
            $outFormat,
            $ua
        );

        if ($stmt->execute()) {
            $stmt->close();
            return;
        }

        $errno = $stmt->errno;
        $stmt->close();

        // Table missing: create once and retry insert.
        if ($errno === 1146 && $attempt === 0) {
            ensure_signals_stats_table($db);
            continue;
        }
        return;
    }
    }


    $account_id = 0;
    $setup_raw = '0';
    $view = 'dump';

    $remote = false;
    $remote_ip = '';
    $out = 'json';
    $sapi = php_sapi_name();

    if ($_SERVER && $sapi !== 'cli' && isset($_SERVER['REMOTE_ADDR'])) {
    $remote = true;
    $remote_ip = extract_client_ip();
    }

    if ($remote)  {
    $account_id = intval(rqs_param('src_account', 256));
    $view = rqs_param('view', 'json');
    $setup_raw = isset($_GET['setup']) ? (string)$_GET['setup'] : (string)rqs_param('setup', '0');
    $out = rqs_param('format', 'json');
    track_get_signals_request($mysqli, $remote_ip, $account_id, $setup_raw, $view, $out);
    ip_check();
    }

    function parse_setup_list(string $raw): array {
    $raw = trim($raw);
    if ('' === $raw) return [];

    // Fast path for a single setup id.
    if (preg_match('/^\d+$/', $raw)) {
        $v = intval($raw);
        return ($v > 0) ? [$v] : [];
    }

    $ids = [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        if (isset($decoded['setup'])) {
            $v = intval($decoded['setup']);
            if ($v > 0) $ids[$v] = 1;
        } else {
            foreach ($decoded as $row) {
                if (is_array($row) && isset($row['setup'])) {
                    $v = intval($row['setup']);
                    if ($v > 0) $ids[$v] = 1;
                }
            }
        }
    }

    // Fallback for lightly sanitized payloads like [{ВЁsetupВЁ:1,ВЁqtyВЁ:1}].
    if (!count($ids)) {
        if (preg_match_all('/setup[^0-9-]*([0-9]+)/i', $raw, $m) && isset($m[1])) {
            foreach ($m[1] as $num) {
                $v = intval($num);
                if ($v > 0) $ids[$v] = 1;
            }
        }
    }

    ksort($ids);
    return array_keys($ids);
    }

    $setups = parse_setup_list($setup_raw);
    if (!count($setups)) {
    echo "#ERROR: invalid setup param: " . substr($setup_raw, 0, 200);
    exit(0);
    }

    $setup_filter = implode(',', array_map('intval', $setups));

    $pairs_map = $mysqli->select_map('id,symbol', 'pairs_map', '');
    $rows = $mysqli->select_rows('id, buy, pair_id, ts, mult, limit_price, take_profit, stop_loss, ttl, qty, flags', 'signals', "WHERE setup IN ($setup_filter)", MYSQLI_ASSOC);
    if (is_array($rows)) {
        $res = [];
        foreach ($rows as $row) {
                foreach ($row as $key => $val) {
                        if (is_numeric($val)) {
                                $row[$key] = floatval($val); // for removing "
                        }
                }
                $row['closed'] = 0; // is working signal

                $pair_id = $row['pair_id'];
                if (isset($pairs_map[$pair_id])) {
                        $row['pair'] = $pairs_map[$pair_id];
                }
                $res []= $row;
        }
        if ($out == 'json') {
                echo json_encode($res);
        }
        if ($out == 'dump') {
                echo "<pre>\n";
                $dump = [];
                foreach ($res as $rec) {
                        $id = $rec['id'];
                        unset($rec['id']);
                        $dump[$id] = $rec;
                }

                print_r($dump);
        }
    }
    else {
        echo "#ERROR: failed to fetch signals: {$mysqli->error}\n, response = ".var_export($rows, true);
    }