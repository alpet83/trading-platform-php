<?php

chdir('../../');

require_once('../vendor/autoload.php');

require_once('api_helper.php');

$user_rights = get_user_rights();

if (!str_in($user_rights, 'view')) {
    send_error("Rights restricted to $user_rights", 403);
    exit;
}

$exchange = $_GET['exchange'] ?? 'bitmex';
$account_id = intval($_GET['account_id'] ?? 0);

$from_ts = '2017-01-01 00:00:00';

$mysqli = init_remote_db('trading');
if (!$mysqli) {
    send_error('DB inaccessible');
    exit;
}

// TODO: раскомментировать на проде и удалить захардкоженную дату
//$start_from = time() - 86400 * 10;
$start_from = strtotime('2025-08-30');
$start_from = max($start_from, strtotime($from_ts));
$from_ts = date('Y-m-d H:i', $start_from);

$table = strtolower("{$exchange}__funds_history");
$strict = "(ts >= '$from_ts') AND (account_id = $account_id) ORDER BY `ts` DESC";

$rows = [];
try {
    $res = $mysqli->select_from('ts, value, value_btc', $table, "WHERE $strict");
    while ($res && $row = $res->fetch_array(MYSQLI_NUM)) {
        $rows[] = $row;
    }
} catch (Exception $e) {
    send_error('Failed to fetch balance data');
    exit;
}

if (count($rows) == 0) {
    $mysqli->close();
    send_error('No data found');
    exit;
}

$rows = array_reverse($rows);
$rcnt = count($rows);

$table_dep = strtolower($exchange . '__deposit_history');
$deposits = [];
try {
    $res = $mysqli->select_rows('ts,withdrawal,value_usd,value_btc', $table_dep,
        "WHERE (account_id = $account_id) ORDER BY `ts`");
    if ($res) {
        $deposits = $res;
    }
} catch (Exception $e) {
    // Продолжаем без данных о депозитах
}

$btc_prices = [];
$btc_price = 80000;
$src_table = strtolower("$exchange.candles_btcusd");

if ($mysqli->table_exists($src_table)) {
    $btc_prices = $mysqli->select_map(
        "DATE_FORMAT(ts, '%Y-%m-%d %H') as rts, close",
        $src_table,
        "WHERE (ts >= '$from_ts') AND (MINUTE(ts) = 0) ORDER BY `ts`",
        MYSQLI_NUM
    );
} else {
    $src_table = strtolower("$exchange.ticker_history");
    if ($mysqli->table_exists($src_table)) {
        $btc_prices = $mysqli->select_map(
            "DATE_FORMAT(ts, '%Y-%m-%d %H') as rts, last",
            $src_table,
            "WHERE (pair_id = 1) AND (ts >= '$from_ts') AND (MINUTE(ts) = 0) ORDER BY `ts`",
            MYSQLI_NUM
        );
    }
}

if (count($btc_prices) > 0) {
    $start = array_key_first($btc_prices);
    $btc_price = $btc_prices[$start][0];
}

$mysqli->close();

$accum_usd = 0;
$accum_btc = 0;
$idx = 0;

if (count($deposits) > 0) {
    $deposits[] = [date('Y-m-d H:i:s'), 0, 0, 0]; // ending marker

    foreach ($deposits as $dep_row) {
        $ts = $dep_row[0];

        while ($idx < $rcnt && $rows[$idx][0] <= $ts) {
            $tp = $rows[$idx][0];
            $rts = substr($tp, 0, 13);
            $actual = $btc_prices[$rts] ?? [0];
            $actual = $actual[0];

            if (is_numeric($actual) && $actual > 0) {
                $btc_price = $actual;
            }

            $rows[$idx][1] -= $accum_usd + $accum_btc * $btc_price;
            $rows[$idx][2] -= $accum_btc + $accum_usd / $btc_price;
            $idx++;
        }

        $sign = $dep_row[1] ? -1 : +1; // withdrawal or deposit
        $accum_usd += $dep_row[2] * $sign;
        $accum_btc += $dep_row[3] * $sign;
    }
}

$chart_data = [];
$min_value = PHP_INT_MAX;
$max_value = PHP_INT_MIN;

$usd_col = 1;
$start_funds = count($rows) > 0 ? $rows[0][$usd_col] : 0;

foreach ($rows as $row) {
    $timestamp = strtotime($row[0]);
    $value = floatval($row[$usd_col]);

    $chart_data[] = [
        'timestamp' => $timestamp * 1000, // JavaScript timestamp
        'value' => round($value, 4)
    ];

    $min_value = min($min_value, $value);
    $max_value = max($max_value, $value);
}

$end_funds = count($rows) > 0 ? $rows[count($rows) - 1][$usd_col] : 0;

$gain = 0;
if (abs($start_funds) > 0) {
    $gain = ($end_funds - $start_funds) / $start_funds * 100;
} elseif ($end_funds > 0) {
    $gain = 100;
}
if ($start_funds > $end_funds) {
    $gain = -abs($gain);
}

$delta = abs($max_value - $min_value);
$max_value += $delta * 0.05;
$min_value -= $delta * 0.05;

$response = [
    'success' => true,
    'data' => $chart_data,
    'metadata' => [
        'exchange' => $exchange,
        'account_id' => $account_id,
        'from_date' => $from_ts,
        'to_date' => count($rows) > 0 ? $rows[count($rows) - 1][0] : null,
        'points_count' => count($chart_data),
        'currency' => 'USD'
    ],
    'statistics' => [
        'start_value' => round($start_funds, 4),
        'end_value' => round($end_funds, 4),
        'min_value' => round($min_value, 4),
        'max_value' => round($max_value, 4),
        'gain_percent' => round($gain, 2),
    ]
];

send_response($response);
