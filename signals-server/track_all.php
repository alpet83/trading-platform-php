<?php
    require_once('lib/common.php');
    require_once('lib/db_tools.php');
    require_once('lib/ip_check.php');
    include_once('/usr/local/etc/php/db_config.php');

    header('Content-Type: application/json; charset=utf-8');

    $token_env = trim((string)(getenv('SIGNALS_TRACK_TOKEN') ?: ''));
    $token_req = trim((string)rqs_param('token', ''));

    if ($token_env !== '' && !hash_equals($token_env, $token_req)) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'unauthorized token',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!ip_check('Not allowed for %IP', false)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden ip',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('sigsys');
    if (!$mysqli) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'db connect failed',
            'details' => $db_error ?? 'n/a',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    set_time_limit(55);

    function local_endpoint_cli_call(string $script_name, array $params = []): array {
        @mkdir('/app/signals-server/logs', 0777, true);

        $query = http_build_query($params);
        $query_export = var_export($query, true);
        $script_export = var_export('/app/signals-server/' . ltrim($script_name, '/'), true);

        $php_code = "chdir('/app/src'); "
            . "parse_str($query_export, \$_GET); "
            . "\$_REQUEST = \$_GET; "
            . "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; "
            . "\$_SERVER['REQUEST_METHOD'] = 'GET'; "
            . "\$_SERVER['DOCUMENT_ROOT'] = '/app/signals-server'; "
            . "\$_SERVER['SCRIPT_NAME'] = '/" . ltrim($script_name, '/') . "'; "
            . "include $script_export;";

        $cmd = 'php -d display_errors=1 -r ' . escapeshellarg($php_code) . ' 2>&1';
        $t0 = microtime(true);
        $output = shell_exec($cmd);
        $elapsed_ms = intval(round((microtime(true) - $t0) * 1000));
        $body = is_string($output) ? $output : '';
        $ok = !str_contains($body, 'Fatal error') && !str_contains($body, 'Parse error');

        return [
            'script' => $script_name,
            'query' => $query,
            'elapsed_ms' => $elapsed_ms,
            'body_head' => substr($body, 0, 320),
            'ok' => $ok,
        ];
    }

    $rows = $mysqli->select_rows('DISTINCT setup', 'signals', 'ORDER BY setup', MYSQLI_ASSOC);
    $setups = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $setup = intval($row['setup'] ?? 0);
            if ($setup >= 0) {
                $setups[$setup] = 1;
            }
        }
    }
    if (!isset($setups[0])) {
        $setups[0] = 1;
    }
    ksort($setups);
    $setups = array_keys($setups);

    $only_setup = rqs_param('setup', null);
    if ($only_setup !== null && $only_setup !== '') {
        $forced = intval($only_setup);
        $setups = [$forced];
    }

    $run_level_track = !boolval(rqs_param('skip_level_track', false));

    $result = [
        'ok' => true,
        'ts' => gmdate('Y-m-d H:i:s'),
        'host' => php_uname('n'),
        'setups' => $setups,
        'calls' => [],
    ];

    foreach ($setups as $setup) {
        $call = local_endpoint_cli_call('sig_edit.php', [
            'view' => 'quick',
            'setup' => intval($setup),
        ]);
        $call['name'] = 'sig_edit';
        $call['setup'] = intval($setup);
        $result['calls'][] = $call;
    }

    if ($run_level_track) {
        $call = local_endpoint_cli_call('level_track.php', [
            'action' => 'track',
        ]);
        $call['name'] = 'level_track';
        $call['setup'] = null;
        $result['calls'][] = $call;
    }

    $result['ok'] = true;
    foreach ($result['calls'] as $c) {
        if (!$c['ok']) {
            $result['ok'] = false;
            break;
        }
    }

    if (!$result['ok']) {
        http_response_code(207);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
