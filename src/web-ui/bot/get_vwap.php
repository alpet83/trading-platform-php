<?php

/*
    * Local VWAP endpoint for demo and containerized deployments.
    *
    * The legacy signals PHP server expects a simple HTTP endpoint returning a
    * numeric VWAP value. This variant is adapted from the datafeed server source
    * but uses the trading-platform shared libraries and local MariaDB datafeed
    * schemas available inside trd-web.
    */

$app_root = dirname(__DIR__, 2);

require_once($app_root . '/common.php');
require_once($app_root . '/lib/db_tools.php');
require_once($app_root . '/lib/db_config.php');

header('Content-Type: text/plain; charset=utf-8');

if (!function_exists('rqs_param')) {
    die("#FATAL: rqs_param not defined\n");
}

$inj_flt = 'PHP:SQL';
$pair_id = intval(rqs_param('pair_id', 1));
$limit = max(1, min(10000, intval(rqs_param('limit', 10))));
$hour_back = gmdate('Y-m-d H:i:00', time() - 3600);
$ts_from = rqs_param('ts_from', $hour_back, $inj_flt, REGEX_TIMESTAMP_FILTER);
$exch = strtolower((string)rqs_param('exchange', 'any', $inj_flt, '/(\w+)/'));

error_reporting(E_ERROR | E_WARNING | E_PARSE);
mysqli_report(MYSQLI_REPORT_ERROR);

$mysqli = init_remote_db('datafeed');
if (!$mysqli instanceof mysqli_ex) {
    die("#FATAL: can't connect to database datafeed\n");
}

function tp_format_vwap(float $avg): string {
    $precision = (int)max(1, 6 - log10(max($avg, 0.0000001)));
    $precision = min(10, $precision);
    return number_format($avg, $precision, '.', '');
}

function tp_print_vwap(float $avg, bool $exit = true): void {
    echo tp_format_vwap($avg);
    if ($exit) {
        exit(0);
    }
}

function tp_load_from_candles(mysqli_ex $mysqli, string $db_name, int $pair_id, string $ts_from, int $limit, float &$vwap): string {
    if (!$mysqli->select_db($db_name)) {
        return "#FAIL: cannot select db $db_name";
    }

    $id_map = $mysqli->select_map('pair_id,ticker', 'ticker_map');
    if (!$id_map) {
        return "#FAIL: ticker_map not found in $db_name";
    }
    if (!isset($id_map[$pair_id])) {
        return "#FAIL: pair_id #$pair_id not found in $db_name.ticker_map";
    }

    $pair = strtolower((string)$id_map[$pair_id]);
    $table = "candles__{$pair}";
    if (!$mysqli->table_exists($table)) {
        return "#FAIL: table $db_name.$table not found";
    }

    $rows = $mysqli->select_rows('*', $table, "WHERE ts >= '$ts_from' ORDER BY ts DESC LIMIT $limit", MYSQLI_ASSOC);
    if (!is_array($rows)) {
        return "#FAIL: error retrieving candles from $db_name.$table: {$mysqli->error}";
    }
    if (count($rows) < max(1, $limit - 5)) {
        return '#FAIL: too few candles loaded = ' . count($rows);
    }

    $rows = array_reverse($rows);
    $volume = 0.0;
    $weighted = 0.0;
    foreach ($rows as $row) {
        $close = floatval($row['close'] ?? 0);
        $row_volume = floatval($row['volume'] ?? 0);
        $weighted += $close * $row_volume;
        $volume += $row_volume;
    }

    if ($volume <= 0) {
        return '#FAIL: candle volume is zero';
    }

    $vwap = $weighted / $volume;
    return '#OK';
}

function tp_load_from_tickers(mysqli_ex $mysqli, string $db_name, int $pair_id, string $ts_from, int $limit, float &$avg): string {
    if (!$mysqli->select_db($db_name)) {
        return "#FAIL: cannot select db $db_name";
    }
    if (!$mysqli->table_exists('ticker_history')) {
        return "#FAIL: ticker_history not found in $db_name";
    }

    $rows = $mysqli->select_rows(
        'bid,ask,last,fair_price',
        'ticker_history',
        "WHERE (pair_id = $pair_id) AND (ts >= '$ts_from') ORDER BY ts DESC LIMIT $limit",
        MYSQLI_ASSOC,
    );
    if (!is_array($rows) || count($rows) < 3) {
        return "#FAIL: not enough ticker_history rows in $db_name";
    }

    $rows = array_reverse($rows);
    $avg = 0.0;
    foreach ($rows as $row) {
        $bid = floatval($row['bid'] ?? 0);
        $ask = floatval($row['ask'] ?? 0);
        $price = floatval($row['fair_price'] ?? 0);
        if ($price <= 0) {
            $price = floatval($row['last'] ?? 0);
        }
        $price = max($bid, $price);
        if ($ask > 0) {
            $price = min($ask, $price);
        }
        $avg = $avg == 0.0 ? $price : $avg * 0.8 + $price * 0.2;
    }

    return '#OK';
}

$sources = ['binance', 'bitfinex', 'bitmex', 'bybit'];
if ($exch !== 'any' && $exch !== 'all') {
    $sources = [$exch];
}

$accum = [];
foreach ($sources as $db_name) {
    $vwap = 0.0;
    $status = tp_load_from_candles($mysqli, $db_name, $pair_id, $ts_from, $limit, $vwap);
    if ($status !== '#OK') {
        $accum["$db_name-candles"] = $status;
        continue;
    }
    if ($exch === 'any' || $db_name === $exch) {
        tp_print_vwap($vwap);
    }
    $accum["$db_name-candles"] = tp_format_vwap($vwap);
}

foreach (['binance', 'bitfinex', 'bitmex'] as $db_name) {
    $avg = 0.0;
    $status = tp_load_from_tickers($mysqli, $db_name, $pair_id, $ts_from, $limit, $avg);
    if ($status !== '#OK') {
        continue;
    }
    if ($exch === 'any' || $db_name === $exch) {
        tp_print_vwap($avg);
    }
    $accum["$db_name-tickers"] = tp_format_vwap($avg);
}

if ($accum) {
    echo json_encode($accum, JSON_UNESCAPED_UNICODE);
    exit(0);
}

echo json_encode(['error' => 'VWAP not available'], JSON_UNESCAPED_UNICODE);