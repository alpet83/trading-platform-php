<?php
    /*
     * Legacy signal editor/controller.
     *
     * Responsibilities:
     * - authenticate UI/API access for signal viewing and editing;
     * - apply incoming signal mutations and position updates;
     * - orchestrate data loading from signal_data.php and rendering from signal_views.php.
     */
    $app_root = dirname(__DIR__);
    require_once($app_root.'/src/lib/common.php');
    require_once($app_root.'/src/lib/db_tools.php');
    require_once($app_root.'/src/lib/esctext.php');
    require_once($app_root.'/src/lib/ip_check.php');
    require_once('/usr/local/etc/php/db_config.php');
    require_once(__DIR__.'/signal_data.php');
    require_once(__DIR__.'/signal_views.php');

    $user_rights = 'view trade admin';
    $user_id = 0;

    $output_format = detect_output_format();
    if (php_sapi_name() != 'cli') {
        if ($output_format === 'json') {
            require_once('api_helper.php');
            $user_rights = get_user_rights();
        } else {
            require_once('lib/auth_check.php');
        }
    }

    define('SIGNALS_LOG', __DIR__.'/logs/signals.log');
    define('SIG_FLAG_TP', 0x001);
    define('SIG_FLAG_SL',0x002);
    define('SIG_FLAG_LP', 0x004);
    define('SIG_FLAG_SE',0x010);  // eternal/endless stop
    define('SIG_FLAG_GRID', 0x100);

    $grid_flag = SIG_FLAG_GRID;
    ob_implicit_flush();

    if (!str_in($user_rights, 'view')){
        error_exit("ERROR: user has no rights to view/edit signals\n");
    }

    $content_type_header = 'text/html';
    if ($output_format === 'json') {
        $content_type_header = "application/{$output_format}";
        ob_start();
    }

    header("Content-Type: {$content_type_header}; charset=utf-8");

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, PATCH, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    $id_added = 0;
    $minute = date('i') * 1;

    $script = $_SERVER['SCRIPT_NAME'] ?? __FILE__; // TODO: use auth token with cookies
    $setup = rqs_param('setup', 0);
    if ($setup == 0) {
        if (preg_match('/(\d+)/', $script, $m) && count($m) > 1)
            $setup = $m[1];
    }

    // Validate setup is within the user's allowed range (JSON API path, non-admin only)
    if ($output_format === 'json' && !str_in($user_rights, 'admin')) {
        $allowed_min = $user_base_setup ?? 0;
        $allowed_max = $allowed_min + 9;
        if ($setup < $allowed_min || $setup > $allowed_max) {
            send_error("Setup $setup out of allowed range [$allowed_min..$allowed_max]", 403);
            exit;
        }
    }

    $input = rqs_param('signal', false);
    $filter = rqs_param('filter', false);
    $action = rqs_param('action', '');
    $delete = rqs_param('delete', false);
    $setup_list = rqs_param('setup-list', false);
    $qview = 'quick' == rqs_param('view', 'html');

    $source = $_SERVER['REMOTE_ADDR'] ?? 'local';
    $touched = false;
    $signal = false;

    // Р СњР В°РЎРѓРЎвЂљРЎР‚Р С•Р в„–Р С”Р В° Р В»Р С•Р С–Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ
    $sfx = $input ? 'edit' : 'view';
    $sfx .=  "-$setup";
    $log_file = fopen(__DIR__."/logs/sig_$sfx.log", $input ? 'a' : 'w');
    if (!$log_file || php_sapi_name() == 'cli')
        $log_file = fopen("/tmp/sig_$sfx.log", 'w');
    if (is_resource($log_file))
        $color_scheme = 'none';

    $tstart = pr_time();
    log_msg("#START: connecting to DB...");

    // Р СџР С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘Р Вµ Р С” Р вЂР вЂќ
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = init_remote_db('trading');
    $table_name = 'signals';

    if (!$mysqli) {
        $error_msg = "FATAL: user '$db_user' can't connect to servers ".json_encode($db_servers);
        $code = 500;
        $output = $output_format === 'json'
            ? render_json_error($error_msg, $code)
            : "$error_msg\n";
        response($output_format, $output, $code);
    }

    flush();

    // Р вЂ”Р В°Р С–РЎР‚РЎС“Р В·Р С”Р В° Р В±Р В°Р В·Р С•Р Р†РЎвЂ№РЎвЂ¦ Р Т‘Р В°Р Р…Р Р…РЎвЂ№РЎвЂ¦
    $pairs_map = $mysqli->select_map('symbol,id', 'pairs_map', "WHERE id > 0 AND contract_ratio > 0");
    $id_map = array_flip($pairs_map);
    unset($pairs_map['null']);

    $init_t = pr_time() - $tstart;
    log_msg(sprintf("#PERF: %.2f sec, pairs_map:\n %s", $init_t, json_encode($pairs_map)));

    $btc_pair_id = $pairs_map['BTCUSD'];
    $eth_pair_id = $pairs_map['ETHUSD'];

    mysqli_report(MYSQLI_REPORT_OFF);
    include_once('load_sym.inc.php');
    log_cmsg("~C91#PERF:~C00 loaded cm_symbols =  %d, accum elps = %.1f, ",
        count($cm_symbols), pr_time() - $tstart);

    if ($setup_list !== false) {
        $result = $mysqli->try_query("SELECT DISTINCT setup FROM {$table_name} ORDER BY setup");
        $setups = array_map(
            fn ($row) => (int) $row['setup'],
            $result->fetch_all(MYSQLI_ASSOC)
        );

        if ($output_format === 'json') {
            $output = $setups
                ? render_json_success_with_data("Setup list", $setups)
                : render_json_error("Failed to get setup list");
            response($output_format, $output);
        }
    }

    // Р В¤Р С‘Р В»РЎРЉРЎвЂљРЎР‚
    $filter_id = 0;
    if ($filter) {
        if (isset($pairs_map[$filter])) {
            $filter_id = intval($pairs_map[$filter]);
        } else {
            $error_msg = "#ERROR: symbol $filter not found";
            $code = 404;
            $output = $output_format === 'json'
                ? render_json_error($error_msg, $code)
                : "<pre>$error_msg in ".print_r($pairs_map, true);
            response($output_format, $output, $code);
        }

        // Р С›Р В±РЎР‚Р В°Р В±Р С•РЎвЂљР С”Р В° РЎвЂ Р Р†Р ВµРЎвЂљР В° РЎвЂљР С•Р В»РЎРЉР С”Р С• Р ВµРЎРѓР В»Р С‘ Р С—Р В°РЎР‚Р В°Р СР ВµРЎвЂљРЎР‚ color РЎРЏР Р†Р Р…Р С• РЎС“Р С”Р В°Р В·Р В°Р Р… Р В Р Р…Р Вµ Р С—РЎС“РЎРѓРЎвЂљР С•Р в„–
        $color = rqs_param("color", null);
        if ($color !== null && $color !== '' && $color !== '-') {
            $lc = strlen($color);
            $output = '';
            if ($lc >= 3 && $filter_id > 0) {
                $mysqli->try_query("UPDATE `pairs_map` SET color = '#$color' WHERE id = $filter_id");
                $result_msg = "For $filter #$filter_id updated color = $color, result: {$mysqli->affected_rows}";
                $output = $output_format === 'json'
                    ? render_json_success($result_msg)
                    : "$result_msg";
            } else {
                $output = $output_format === 'json'
                    ? render_json_error("ERROR: color '$color' is invalid")
                    : "<!-- $color = $lc, $filter =  $filter_id -->\n";
            }

            response($output_format, $output);
        }
    }

    if (count(array_keys($_REQUEST)) > 0)
        ip_check();

    $colors = $mysqli->select_map('id,color', 'pairs_map');

    $editing_requested = $input || $delete ||
        isset($_REQUEST['sig_id']) ||
        isset($_REQUEST['edit_tp']) ||
        isset($_REQUEST['edit_sl']) ||
        isset($_REQUEST['edit_lp']) ||
        isset($_REQUEST['edit_comment']) ||
        isset($_REQUEST['toggle_tp']) ||
        isset($_REQUEST['toggle_sl']) ||
        isset($_REQUEST['toggle_lp']) ||
        isset($_REQUEST['toggle_se']) ||
        isset($_REQUEST['signal_no']);

    if ($editing_requested && !str_in($user_rights, 'trade') && $output_format === 'json') {
        $error_msg = "ERROR: user has no rights to edit signals";
        $code = 403;
        $output = render_json_error($error_msg, $code);
        response($output_format, $output, $code);
    }

    // РЎС“Р Т‘Р В°Р В»Р ВµР Р…Р С‘Р Вµ РЎРѓР С‘Р С–Р Р…Р В°Р В»Р В°
    if ($delete) {
        $delete = intval($delete);
        $result = $mysqli->try_query("DELETE FROM $table_name WHERE (id = $delete) AND (setup = $setup);");

        if ($output_format === 'json') {
            $output = $result
                ? render_json_success("Signal #$delete deleted")
                : render_json_error("Failed to delete signal #$delete");
            response($output_format, $output);
        }
    }

    // Р С•Р В±РЎР‚Р В°Р В±Р С•РЎвЂљР С”Р В° РЎРѓР С‘Р С–Р Р…Р В°Р В»Р С•Р Р†
    if (str_in($user_rights, 'trade')) {
        if (!$qview) echo "<!-- \n";

        $price = rqs_param('price', 0);
        $amount = rqs_param('amount', 1);
        $edit_comment = rqs_param('edit_comment', 0);
        $text = rqs_param('text', '');

        // Р С›Р В±Р Р…Р С•Р Р†Р В»Р ВµР Р…Р С‘Р Вµ Р С—Р В°РЎР‚Р В°Р СР ВµРЎвЂљРЎР‚Р С•Р Р† РЎРѓР С‘Р С–Р Р…Р В°Р В»Р С•Р Р†
        if ('null' !== $amount)
            sig_param_set($mysqli, $table_name, $setup, 'sig_id', 'mult', $amount, 0, $touched);

        if ('null' !== $price) {
            sig_param_set($mysqli, $table_name, $setup, 'edit_tp', 'take_profit', $price, SIG_FLAG_TP, $touched);
            sig_param_set($mysqli, $table_name, $setup, 'edit_sl', 'stop_loss', $price, SIG_FLAG_SL, $touched);
            sig_param_set($mysqli, $table_name, $setup, 'edit_lp', 'limit_price', $price, SIG_FLAG_LP, $touched);
        }

        if ($edit_comment)
            sig_param_set($mysqli, $table_name, $setup, 'edit_comment', 'comment', $text, 0, $touched);

        // Р СџР ВµРЎР‚Р ВµР С”Р В»РЎР‹РЎвЂЎР ВµР Р…Р С‘Р Вµ РЎвЂћР В»Р В°Р С–Р С•Р Р†
        sig_toggle_flag($mysqli, $table_name, $setup, 'toggle_tp', SIG_FLAG_TP);
        sig_toggle_flag($mysqli, $table_name, $setup, 'toggle_sl', SIG_FLAG_SL);
        sig_toggle_flag($mysqli, $table_name, $setup, 'toggle_lp', SIG_FLAG_LP);
        sig_toggle_flag($mysqli, $table_name, $setup, 'toggle_se', SIG_FLAG_SE);

        log_cmsg("#REQUEST: dump: %s\n", print_r($_REQUEST, true));

        // Р С›Р В±РЎР‚Р В°Р В±Р С•РЎвЂљР С”Р В° Р Р†РЎвЂ¦Р С•Р Т‘РЎРЏРЎвЂ°Р ВµР С–Р С• РЎРѓР С‘Р С–Р Р…Р В°Р В»Р В°
        if ($input || has_individual_signal_params()) {
            process_input_signal($mysqli, $table_name, $setup, $grid_flag, $input, $pairs_map, $cm_symbols, $user_id, $source, $touched, $id_added, $signal, $output_format);
        }

        if ($qview) {
            // Р СџРЎР‚Р ВµР Т‘РЎС“Р С—РЎР‚Р ВµР В¶Р Т‘Р ВµР Р…Р С‘Р Вµ РЎвЂљР С•Р В»РЎРЉР С”Р С• Р ВµРЎРѓР В»Р С‘ Р Р…Р ВµРЎвЂљ Р Р…Р С‘ signal, Р Р…Р С‘ Р С‘Р Р…Р Т‘Р С‘Р Р†Р С‘Р Т‘РЎС“Р В°Р В»РЎРЉР Р…РЎвЂ№РЎвЂ¦ Р С—Р В°РЎР‚Р В°Р СР ВµРЎвЂљРЎР‚Р С•Р Р†
            if (!$input && !has_individual_signal_params()) {
                echo "#WARN: data param not specified\n";
                print_r($_REQUEST);
            }
            ob_start();
        }
        echo "-->\n";
    } else {
        printf("<!-- Rights restricted to %s -->\n", $user_rights);
    }

    // Р вЂ”Р В°Р С–РЎР‚РЎС“Р В·Р С”Р В° РЎРѓР С‘Р С–Р Р…Р В°Р В»Р С•Р Р† Р С‘Р В· Р вЂР вЂќ
    $rows = load_signals($mysqli, $setup, $grid_flag, $filter_id);

    if (count($rows) == 0) {
        if ($output_format === 'json') {
            $output = render_json([], $colors, [], [], 0, $setup, [
                'setup_id' => $setup,
                'btc_pair_id' => $btc_pair_id,
                'eth_pair_id' => $eth_pair_id,
                'message' => 'No signals found'
            ]);
            response($output_format, $output);
        }
    }

    // Р ВР Р…Р С‘РЎвЂ Р С‘Р В°Р В»Р С‘Р В·Р В°РЎвЂ Р С‘РЎРЏ Р С—Р ВµРЎР‚Р ВµР СР ВµР Р…Р Р…РЎвЂ№РЎвЂ¦ Р Т‘Р В»РЎРЏ VWAP
    $vwap_prices = [];
    $vwap_fails = [];
    $vwap_cache = [];
    $cache_hits = 0;

    // Р вЂ”Р В°Р С–РЎР‚РЎС“Р В·Р С”Р В° VWAP РЎвЂ Р ВµР Р…
    if (!$filter) {
        $vwap_good = load_vwap_prices($rows, $id_map, $mysqli, $action, $vwap_prices, $vwap_cache, $vwap_fails, $cache_hits);

        if ($vwap_good < 10) {
            printf("#ERROR: VWAP calculated/loaded only for %d pairs\n<!-- %s -->", $vwap_good,
                print_r($vwap_prices, true));
        }
    }

    $processed_data = process_signals_data($rows, $mysqli, $table_name, $setup, $id_map, $cm_symbols, $btc_pair_id, $eth_pair_id, $vwap_prices, $filter);
    update_positions($mysqli, $setup, $processed_data['accum_map'], $source, $signal, $filter);

    if ($qview) ob_end_clean();

    if ($delete && !isset($processed_data['accum_map'][$delete]))
        $processed_data['accum_map'][$delete] = 0;

    $work_t = pr_time() - $tstart;
    log_msg(sprintf("#END: work_t = %.1f sec, CWD: %s, LOG_FILE %s", $work_t, getcwd(), SIGNALS_LOG));

    $display_data = prepare_display_data($processed_data, $colors, $btc_pair_id, $eth_pair_id, $setup, $work_t);

    // Р вЂ™РЎвЂ№Р Р†Р С•Р Т‘
    function response($output_format, $content, $code = 200) {
        if ($output_format === 'json') {
            ob_clean();
        }

        http_response_code($code);
        echo $content;

        if ($output_format === 'json') {
            ob_end_flush();
        }

        exit;
    }

    $output = render_output(
        $output_format,
        $display_data['table_data'],
        $display_data['colors'],
        $id_added,
        $mysqli,
        $display_data['buys'],
        $display_data['shorts'],
        $display_data['sym_errs'],
        $cm_symbols,
        $filter,
        $touched,
        $id_map,
        $setup,
        $script,
        $btc_pair_id,
        $eth_pair_id,
        $grid_flag,
        $display_data['setup_info']
    );

    response($output_format, $output);