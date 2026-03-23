<?php

chdir('../');

require_once('api_helper.php');

require_once('lib/esctext.php');
require_once('lib/mini_core.php');

$user_rights = get_user_rights();

if (!str_in($user_rights, 'view')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

$is_admin  = str_in($user_rights, 'admin');
$is_trader = str_in($user_rights, 'trade');

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('DB inaccessible');
    exit;
}

$bots = $mysqli->select_map('applicant,table_name', 'config__table_map', "ORDER BY applicant");
$activity = $mysqli->select_map('account_id,ts,ts_start,applicant,funds_usage,last_error', 'bot__activity', 'ORDER BY applicant' );
$redundancy = $mysqli->select_map ('account_id,exchange,master_pid,uptime', 'bot__redudancy', '');

$day_start = gmdate('Y-m-d 00:00:00');

$acc_map = [];
$cfg_map = [];
$pairs_configs = [];
$btc_price = 80000;
$btc_time = date('Y-m-d 0:00');

$bots_data = [];

foreach ($activity as $acc_id => $row) {
    $app = $row['applicant'];
    $ts = $row['ts'];
    $started = $row['ts_start'];
    $rd_info = $redundancy[$acc_id] ?? null;

    $pid = '?';
    $uptime = -time();
    if ($rd_info) {
        $pid = $rd_info['master_pid'];
        $uptime = $rd_info['uptime'];
    }
    $cfg_table = $bots[$app] ?? 'none';
    $config = $mysqli->select_map('param,value', $cfg_table, "WHERE account_id = $acc_id");
    if (is_null($config)) {
        $bots_data[] = [
            'error' => "no config for $app => $cfg_table",
            'app' => $app
        ];
        continue;
    }

    $bot_dir = strtolower("/tmp/$app");
    $cfg_map[$app] = $config;
    $acc_map[$app] = $acc_id;

    $exch = strtolower($config['exchange'] ?? 'NYMEX');

    $ticker = $mysqli->select_row('last_price,ts_updated', "{$exch}__tickers", "WHERE pair_id = 1");
    if (is_array($ticker) && $ticker[1] > $btc_time) {
        $btc_price = $ticker[0];
        $btc_time = $ticker[1];
    }

    $pairs_table = "{$exch}__pairs_map";
    $bot_pairs = $mysqli->select_map('pair_id,pair', $pairs_table, 'ORDER BY pair');
    $pairs_configs[$app] = [];
    foreach ($bot_pairs as $pair_id => $pair) {
        if (is_file("{$bot_dir}/$pair.json")) {
            $pairs_configs[$app][$pair_id] = file_load_json("{$bot_dir}/$pair.json");
        }
    }

    $mo_table = "{$exch}__matched_orders";
    $err_table = "{$exch}__last_errors";
    $last_err = $row['last_error'];
    if ('' == $last_err)
        $last_err = $mysqli->select_value('CONCAT(TIME(ts), " ", message)', $err_table, "WHERE (account_id = $acc_id) ORDER BY ts DESC LIMIT 1") ?? '';

    $matched_count = $mysqli->select_value('COUNT(*)', $mo_table, "WHERE (account_id = $acc_id) AND (ts >= '$day_start')");
    $errors = $mysqli->select_value('COUNT(*)', $err_table, "WHERE (account_id = $acc_id)");

    $evt_table = "{$exch}__events";
    $events = $mysqli->select_map('event,COUNT(*)', $evt_table, "WHERE account_id = $acc_id AND ts >= '$day_start' GROUP BY event");
    $restarts = 0;
    $exceptions = 0;
    if (is_array($events)) {
        $restarts = $events['INITIALIZE'] ?? 0;
        $exceptions = $events['EXCEPTION'] ?? 0;
    }

    $color = $config['report_color'] ?? false;
    $bgc = null;
    if ($color && str_in($color, ','))
        $bgc = "rgb($color)";

    $pos_coef = $config['position_coef'] ?? 0.0;
    $enabled = $config['trade_enabled'];

    $bots_data[] = [
        'bot' => $app,
        'account' => $acc_id,
        'exchange' => $exch,
        'started' => $started,
        'last_alive' => $ts,
        'matched_orders' => $matched_count,
        'funds_usage' => $row['funds_usage'],
        'restarts' => $restarts,
        'exceptions' => $exceptions,
        'errors' => $errors,
        'last_error_raw' => $last_err,
        'last_error' => preg_replace('/~C\d\d/', '', $last_err),
        'pid' => $pid,
        'position_coef' => $pos_coef,
        'trade_enabled' => (bool)$enabled,
        'background_color' => $bgc
    ];
}

$symbol_map = json_decode(file_get_contents("cm_symbols.json"), true);

if (!is_array($symbol_map)) {
    send_error('wrong pairs_map');
    exit;
}

$pos_map = [];
foreach ($cfg_map as $bot => $config) {
    $acc_id = $acc_map[$bot];
    $exch = strtolower($config['exchange'] ?? 'NYMEX');
    $pos_map[$bot] = $mysqli->select_map('`pair_id`,`current`,`offset`', "{$exch}__positions", "WHERE account_id = $acc_id");
}

function is_light_color(string $color) {
    if (in_array(strtolower($color),[ 'white', 'lawngreen', 'khaki', 'gold', 'yellow', 'cyan']) || str_in($color, 'light'))
        return true;
    if (str_in($color, '#')) {
        $r = hexdec(substr($color, 1, 2));
        $g = hexdec(substr($color, 3, 2));
        $b = hexdec(substr($color, 5, 2));
        $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        return $lum > 160;
    }
    return false;
}

//dasdsad

$risk_mapping = [];
$short_volume = 0;
$long_volume = 0;

foreach ($symbol_map as $pair_id => $rec) {
    $positions = [];
    $sum = 0;
    $count = 0;
    $price = 1;
    $volume = 0;
    $bgc = null;
    $is_light = false;

    if (isset($rec['color'])) {
        $bgc = $rec['color'];
        $is_light = is_light_color($bgc);
    }

    foreach ($pos_map as $bot => $bot_positions) {
        $ti = $pairs_configs[$bot][$pair_id] ?? null;
        if (isset($bot_positions[$pair_id]) && is_object($ti)) {
            $pos = $bot_positions[$pair_id];
            if (isset($ti->last_price))
                $price = $ti->last_price;
            $qty = AmountToQty($ti, $price, $pos['current']);
            $sum += $qty;
            $vol = $price * $qty;
            if (in_array($ti->quote_currency, ['XBT', 'BTC']))
                $vol *= $btc_price;

            $volume += $vol;
            $positions[$bot] = format_qty($qty);
            $count++;
        } else {
            $positions[$bot] = null;
        }
    }

    if ($count > 0) {
        $risk_mapping[] = [
            'pair_id' => $pair_id,
            'symbol' => $rec['symbol'],
            'positions' => $positions,
            'summary' => format_qty($sum),
            'volume' => $volume,
            'background_color' => $bgc,
            'is_light_color' => $is_light
        ];
    }

    if ($volume > 0) $long_volume += $volume;
    if ($volume < 0) $short_volume -= $volume;
}

$result = [
    'bots' => $bots_data,
    'risk_mapping' => $risk_mapping,
    'volumes' => [
        'long' => $long_volume,
        'short' => $short_volume
    ],
    'is_admin' => $is_admin,
    'is_trader' => $is_trader,
];

send_response($result);
