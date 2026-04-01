<?php

    /*
    * Signal data/business layer for the legacy editor/API.
    *
    * Responsibilities:
    * - parse incoming signal payloads or explicit field-based edits;
    * - load signal rows and derive accumulated positions;
    * - apply TP/SL logic and auxiliary pricing data (VWAP);
    * - keep rendering concerns out of the controller entrypoint.
    */

    function sql_quote($mysqli, ?string $value): string {
    return "'".$mysqli->real_escape_string((string)($value ?? ''))."'";
    }

    function resolve_vwap_endpoint(): string {
    $base = trim((string)(
        getenv('SIGNALS_VWAP_URL')
        ?: getenv('TRADEBOT_PHP_HOST')
        ?: getenv('SIGNALS_FEED_URL')
        ?: getenv('SIGNALS_API_URL')
        ?: ''
    ));

    if ($base === '') {
        return '';
    }

    if (stripos($base, 'get_vwap.php') !== false) {
        return $base;
    }

    return rtrim($base, '/').'/bot/get_vwap.php';
    }

    function ensure_ticker_prices_map_table($mysqli): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $query = "CREATE TABLE IF NOT EXISTS `ticker_prices_map` (\n"
        . " `pair_id` INT NOT NULL,\n"
        . " `symbol` VARCHAR(32) NOT NULL,\n"
        . " `base_symbol` VARCHAR(16) NOT NULL,\n"
        . " `last_price` DOUBLE NOT NULL DEFAULT 0,\n"
        . " `source` VARCHAR(32) NOT NULL DEFAULT 'coingecko',\n"
        . " `ts_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (`pair_id`),\n"
        . " KEY `idx_base_symbol` (`base_symbol`),\n"
        . " KEY `idx_ts_updated` (`ts_updated`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $mysqli->try_query($query);
    $ready = true;
    }

    function normalize_pair_base_symbol(string $pair): string {
    $pair = strtoupper(trim($pair));
    foreach (['USDT', 'USD', 'PERP', 'USDC'] as $suffix) {
        if (str_ends_with($pair, $suffix) && strlen($pair) > strlen($suffix)) {
            return substr($pair, 0, -strlen($suffix));
        }
    }
    return $pair;
    }

    function resolve_coingecko_endpoint(): string {
    $url = trim((string)(getenv('SIGNALS_PRICE_FEED_URL') ?: getenv('COINGECKO_PRICE_URL') ?: ''));
    if ($url !== '') {
        return $url;
    }
    return 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=250&page=1&sparkline=false';
    }

    function fetch_coingecko_price_map(): array {
    $endpoint = resolve_coingecko_endpoint();
    $json = curl_http_request($endpoint);
    $rows = json_decode($json, true);
    if (!is_array($rows)) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
        $price = floatval($row['current_price'] ?? 0);
        if ($sym === '' || $price <= 0) continue;
        $map[$sym] = $price;
    }
    return $map;
    }

    function maybe_refresh_ticker_prices_map($mysqli, $id_map, int $refresh_ttl = 180): void {
    static $refresh_checked = false;
    if ($refresh_checked) {
        return;
    }
    $refresh_checked = true;

    ensure_ticker_prices_map_table($mysqli);

    $lock_file = '/tmp/ticker_prices_map_refresh.ts';
    if (is_file($lock_file)) {
        $last = intval(@file_get_contents($lock_file));
        if ($last > 0 && (time() - $last) < $refresh_ttl) {
            return;
        }
    }

    $price_by_symbol = fetch_coingecko_price_map();
    if (count($price_by_symbol) === 0) {
        return;
    }

    $ts_now = date(SQL_TIMESTAMP);
    foreach ($id_map as $pair_id => $pair) {
        $pair_id = intval($pair_id);
        if ($pair_id <= 0) continue;

        $base_symbol = normalize_pair_base_symbol((string)$pair);
        if (!isset($price_by_symbol[$base_symbol])) continue;

        $price = floatval($price_by_symbol[$base_symbol]);
        if ($price <= 0) continue;

        $pair_sql = sql_quote($mysqli, (string)$pair);
        $base_sql = sql_quote($mysqli, $base_symbol);
        $query = "INSERT INTO `ticker_prices_map` (`pair_id`, `symbol`, `base_symbol`, `last_price`, `source`, `ts_updated`) VALUES "
            . "($pair_id, $pair_sql, $base_sql, $price, 'coingecko', '$ts_now') "
            . "ON DUPLICATE KEY UPDATE `symbol` = $pair_sql, `base_symbol` = $base_sql, `last_price` = $price, `source` = 'coingecko', `ts_updated` = '$ts_now'";
        $mysqli->try_query($query);
    }

    @file_put_contents($lock_file, strval(time()), LOCK_EX);
    }

    function load_cached_ticker_prices($mysqli, $id_map, int $max_age = 600): array {
    ensure_ticker_prices_map_table($mysqli);
    maybe_refresh_ticker_prices_map($mysqli, $id_map);

    $rows = $mysqli->select_rows('pair_id,last_price,ts_updated', 'ticker_prices_map', "WHERE ts_updated >= (UTC_TIMESTAMP() - INTERVAL $max_age SECOND)", MYSQLI_ASSOC);
    $prices = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $pair_id = intval($row['pair_id'] ?? 0);
            $price = floatval($row['last_price'] ?? 0);
            if ($pair_id > 0 && $price > 0) {
                $prices[$pair_id] = $price;
            }
        }
    }
    return $prices;
    }

    function resolve_reference_price_for_pair(int $pair_id, string $pair, array $cached_prices, array $vwap_prices, array $cm_symbols, int $btc_pair_id = 0, int $eth_pair_id = 0): float {
    if (isset($cached_prices[$pair_id]) && $cached_prices[$pair_id] > 0) {
        return floatval($cached_prices[$pair_id]);
    }

    if (isset($vwap_prices[$pair_id]) && $vwap_prices[$pair_id] > 0) {
        return floatval($vwap_prices[$pair_id]);
    }

    if (isset($cm_symbols[$pair_id]) && isset($cm_symbols[$pair_id]->last_price)) {
        return floatval($cm_symbols[$pair_id]->last_price);
    }

    if ('ETHBTC' === $pair) {
        $eth = floatval($cached_prices[$eth_pair_id] ?? 0);
        $btc = floatval($cached_prices[$btc_pair_id] ?? 0);
        if ($eth > 0 && $btc > 0) {
            return $eth / $btc;
        }

        if (isset($cm_symbols[$btc_pair_id]) && isset($cm_symbols[$eth_pair_id])) {
            $eth = floatval($cm_symbols[$eth_pair_id]->last_price ?? 0);
            $btc = floatval($cm_symbols[$btc_pair_id]->last_price ?? 0);
            if ($eth > 0 && $btc > 0) {
                return $eth / $btc;
            }
        }
    }

    return 0;
    }

    function sig_param_set($mysqli, $table_name, $setup, string $param, string $field, mixed $value, int $flag, &$touched) {
    $id = rqs_param($param, -1) * 1;
    if ($id <= 0) return false;

    $strict = "(setup = $setup)";
    $touched = $mysqli->select_value('pair_id', $table_name, "WHERE (id = $id) AND $strict");
    $value = $mysqli->format_value($value);
    $query = "UPDATE `$table_name` SET `$field` = $value, flags = (flags | $flag) WHERE (id = $id) AND $strict;";

    if ($mysqli->try_query($query) && $mysqli->affected_rows) {
        echo "#OK: $field = $value for #$touched\n";
        return true;
    } else {
        echo "#FAIL: [ $query ] result {$mysqli->error};";
        return false;
    }
    }

    function sig_toggle_flag($mysqli, $table_name, $setup, string $param, int $flag) {
    $id = rqs_param($param, -1) * 1;
    if ($id > 0) {
        $strict = "(setup = $setup)";
        $mysqli->try_query("UPDATE $table_name SET flags = flags ^ $flag WHERE (id = $id) AND $strict; ");
        return true;
    }
    return false;
    }

    function has_individual_signal_params() {
    return rqs_param('side', false) !== false &&
        rqs_param('pair', false) !== false &&
        rqs_param('signal_no', false) !== false;
    }

    function process_input_signal($mysqli, $table_name, $setup, $grid_flag, $input, $pairs_map, $cm_symbols, $user_id, $source, &$touched, &$id_added, &$signal, $output_format) {
    if (!$input && !has_individual_signal_params()) {
        return false;
    }

    try {
        // Р•СЃР»Рё РµСЃС‚СЊ РѕС‚РґРµР»СЊРЅС‹Рµ РїР°СЂР°РјРµС‚СЂС‹, РёСЃРїРѕР»СЊР·СѓРµРј РёС…
        if (has_individual_signal_params()) {
            return process_individual_signal_params($mysqli, $table_name, $setup, $grid_flag, $pairs_map, $cm_symbols, $user_id, $source, $touched, $id_added, $signal);
        }

        // РРЅР°С‡Рµ РѕР±СЂР°Р±Р°С‚С‹РІР°РµРј СЃС‚СЂРѕРєСѓ РєР°Рє СЂР°РЅСЊС€Рµ (С‚РѕР»СЊРєРѕ РµСЃР»Рё РµСЃС‚СЊ $input)
        if ($input) {
            return process_signal_string($mysqli, $table_name, $setup, $grid_flag, $input, $pairs_map, $cm_symbols, $user_id, $source, $touched, $id_added, $signal);
        }

        return false;
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $error_code = $e->getCode() ?: 500;
        $output = $output_format === 'json'
            ? render_json_error($error_message, $error_code)
            : "#ERROR: $error_message";

        // Р’С‹Р·С‹РІР°РµРј response РЅР°РїСЂСЏРјСѓСЋ СЃ РїСЂР°РІРёР»СЊРЅС‹Рј РєРѕРґРѕРј
        response($output_format, $output, $error_code);
    }
    }

    function process_individual_signal_params($mysqli, $table_name, $setup, $grid_flag, $pairs_map, $cm_symbols, $user_id, $source, &$touched, &$id_added, &$signal) {
    $side = strtoupper(trim(rqs_param('side', '')));
    $pair = strtoupper(trim(rqs_param('pair', '')));
    $signal_no = intval(rqs_param('signal_no', 0));
    $multiplier = floatval(rqs_param('multiplier', 1.0));

    // Р’Р°Р»РёРґР°С†РёСЏ РїР°СЂР°РјРµС‚СЂРѕРІ
    if (!in_array($side, ['BUY', 'SELL', 'SHORT', 'DEL'])) {
        throw new Exception("Invalid side parameter: $side. Must be BUY, SELL, SHORT or DEL", 400);
    }

    if (!isset($pairs_map[$pair])) {
        throw new Exception("Unknown pair: $pair", 400);
    }

    if ($signal_no <= 0 && $side !== 'DEL') {
        throw new Exception("Invalid signal number: $signal_no", 400);
    }

    if ($multiplier <= 0 && !in_array($side, ['DEL'])) {
        throw new Exception("Invalid multiplier: $multiplier", 400);
    }

    $pair_id = $pairs_map[$pair];
    $ts = date(SQL_TIMESTAMP);
    $enter = false;

    log_cmsg("#DBG: processing individual params - side: %s, pair: %s, signal_no: %d, mult: %.2f",
        $side, $pair, $signal_no, $multiplier);

    // РћР±СЂР°Р±РѕС‚РєР° СѓРґР°Р»РµРЅРёСЏ СЃРёРіРЅР°Р»Р°
    if ($side === 'DEL') {
        return delete_signal_by_params($mysqli, $table_name, $setup, $pair_id, $signal_no, $source);
    }

    // РђРІС‚РѕРіРµРЅРµСЂР°С†РёСЏ РЅРѕРјРµСЂР° СЃРёРіРЅР°Р»Р° РїСЂРё РЅРµРѕР±С…РѕРґРёРјРѕСЃС‚Рё
    if ($signal_no === 9999) {
        $signal_no = intval($mysqli->select_value('signal_no', $table_name, "WHERE (pair_id = $pair_id) AND (setup = $setup) ORDER BY signal_no DESC")) + 1;
    }

    // РџСЂРѕРІРµСЂРєР° РїСЂРµРґС‹РґСѓС‰РµРіРѕ СЃРёРіРЅР°Р»Р°
    $prev = $mysqli->select_row('ts, mult', 'signals', "WHERE (pair_id = $pair_id) AND (setup = $setup) AND (signal_no = $signal_no) AND (flags & $grid_flag = 0)");
    $signal = tss().' + individual params: '.$side.' '.$pair.' #'.$signal_no.' x'.$multiplier;

    $is_buy = ($side === 'BUY') ? '1' : '0';
    $trader_id = intval($user_id ?? 0);

    $source_sql = sql_quote($mysqli, $source);
    $query = "INSERT INTO $table_name (ts, pair_id, trader_id, setup, signal_no, buy, mult, source_ip)\n VALUES ";
    $query .= "('$ts', $pair_id, $trader_id, $setup, $signal_no, $is_buy, $multiplier, $source_sql)\n";
    $query .= "ON DUPLICATE KEY UPDATE ts='$ts', trader_id = $trader_id, mult=$multiplier, buy=$is_buy, source_ip=$source_sql;";

    log_cmsg("#QUERY: %s", $query);

    if ($mysqli->try_query($query)) {
        $touched = $pair;
        $ar = $mysqli->affected_rows;
        $id_added = $mysqli->select_value('id', 'signals', "WHERE (pair_id = $pair_id) AND (signal_no = $signal_no) AND (setup = $setup) AND (flags & $grid_flag = 0)");

        if (false === $prev) {
            echo "#OK: new record added via individual params.\n";
            file_add_contents(SIGNALS_LOG, tss()."#ADD_INDIVIDUAL: [$query], affected rows $ar, source = $source\n");
        } else {
            echo "#OK: record changed from previous ".json_encode($prev)." via individual params\n";
            file_add_contents(SIGNALS_LOG, tss()."#MODIFY_INDIVIDUAL: [$query], affected rows $ar, source = $source\n");
        }

        // РЈСЃС‚Р°РЅРѕРІРєР° SL Рё TP РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ
        if ($id_added) {
            $cached_prices = load_cached_ticker_prices($mysqli, array_flip($pairs_map));
            $last_price = floatval($cached_prices[$pair_id] ?? 0);
            if ($last_price <= 0 && isset($cm_symbols[$pair_id])) {
                $last_price = floatval($cm_symbols[$pair_id]->last_price ?? 0);
            }
            if ($last_price > 0) {
            $tp_target = $last_price * 10;
            $mysqli->try_query("UPDATE $table_name\n SET stop_loss = $last_price, take_profit = $tp_target\n WHERE (id = $id_added) AND (setup = $setup);");
            echo "#OK: assigned SL price = $last_price\n";
            }
        }

        return true;
    } else {
        throw new Exception("Failed to add signal via individual params: ".$mysqli->error, 500);
    }
    }

    function delete_signal_by_params($mysqli, $table_name, $setup, $pair_id, $signal_no, $source) {
    if ($signal_no <= 0) {
        throw new Exception("Signal number required for deletion", 400);
    }

    $query = "DELETE FROM $table_name WHERE (pair_id = $pair_id) AND (signal_no = $signal_no) AND (setup = $setup)";
    if ($mysqli->try_query($query)) {
        $ar = $mysqli->affected_rows;
        log_msg("#OK: signal #$signal_no for pair_id $pair_id removed from DB via individual params. Rows = $ar");
        file_add_contents(SIGNALS_LOG, tss()."#DELETE_INDIVIDUAL: signal_no $signal_no, pair_id $pair_id, affected rows $ar, source = $source\n");
        return true;
    } else {
        throw new Exception("Failed to delete signal: ".$mysqli->error, 500);
    }
    }

    function process_signal_string($mysqli, $table_name, $setup, $grid_flag, $input, $pairs_map, $cm_symbols, $user_id, $source, &$touched, &$id_added, &$signal) {
    $m = [];
    $enter = false;
    $input = str_replace('[now]', '['.date('r').']', $input);
    log_cmsg("#DBG: processing signal string: %s", $input);

    // Р Р°Р·Р»РёС‡РЅС‹Рµ С„РѕСЂРјР°С‚С‹ РїР°СЂСЃРёРЅРіР° СЃРёРіРЅР°Р»РѕРІ (СЃСѓС‰РµСЃС‚РІСѓСЋС‰Р°СЏ Р»РѕРіРёРєР°)
    if (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|SHORT).X([\d\.]*)#(\d*)/', $input, $m) && count($m) >= 6) {
        $enter = true;
    } elseif (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|SHORT).X(\d).(\d*).*(LONG|SHORT)/', $input, $m) && count($m) > 6) {
        $enter = true;
    } elseif (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|DEL)#(\d*)/', $input, $m) && count($m) >= 5) {
        // РћР±С‹С‡РЅС‹Р№ С„РѕСЂРјР°С‚
    } elseif (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|DEL).(\d*).*(LONG|SHORT)/', $input, $m) && count($m) > 5) {
        // Р¤РѕСЂРјР°С‚ СЃ LONG/SHORT
    } else {
        echo "#FAIL: can't parse $input\n";
        log_msg("#FAIL: can't parse $input");
        throw new Exception("Invalid signal format: cannot parse input string", 400);
    }

    print_r($m);
    $pair = $m[2];
    if (!isset($pairs_map[$pair])) {
        log_msg("#ERROR: not registered pair $pair\n");
        throw new Exception("Unknown pair: $pair", 400);
    }

    $t = strtotime($m[1]);
    $t = min(time(), $t);
    $ts = date(SQL_TIMESTAMP, $t);
    $pair_id = $pairs_map[$pair];

    $shift = $enter ? 1 : 0;
    $mult = $enter ? $m[4] : 0;
    $signal_no = intval($m[4 + $shift]);

    if (9999 == $signal_no) {
        $signal_no = intval($mysqli->select_value('signal_no', $table_name, "WHERE (pair_id = $pair_id) AND (setup = $setup) ORDER BY signal_no DESC")) + 1;
    }

    $side = '';
    if (count($m) > $shift + 5) {
        $side = $m[5 + $shift];
    }

    // РћР±СЂР°Р±РѕС‚РєР° СѓРґР°Р»РµРЅРёСЏ СЃРёРіРЅР°Р»Р°
    if ('DEL' == $m[3] && $signal_no > 0) {
        $query = "DELETE FROM $table_name WHERE (pair_id = $pair_id) AND (signal_no = $signal_no) AND (setup = $setup)";
        if ($mysqli->try_query($query)) {
            $ar = $mysqli->affected_rows;
            log_msg("#OK: signals #$signal_no for $pair removed from DB. Rows = $ar");
            file_add_contents(SIGNALS_LOG, tss()."#DELETE: $signal_no, affected rows $ar, source = $source\n");
            return true;
        } else {
            echo "#ERROR: failed $query\n";
            throw new Exception("Failed to delete signal: ".$mysqli->error, 500);
        }
    }

    log_msg("#PERF: load prev signal for setup %d", $setup);
    $prev = $mysqli->select_row('ts, mult', 'signals', "WHERE (pair_id = $pair_id) AND (setup = $setup) AND (signal_no = $signal_no) AND (flags & $grid_flag = 0)");
    $signal = tss().' + '.$m[0];

    $buy = ($enter && 'BUY' === $m[3]) ? '1' : '0';
    $trader_id = intval($user_id ?? 0);

    $source_sql = sql_quote($mysqli, $source);
    $query = "INSERT INTO $table_name (ts, pair_id, trader_id, setup, signal_no, buy, mult, source_ip)\n VALUES ";
    $query .= "('$ts', $pair_id, $trader_id, $setup, $signal_no, $buy, $mult, $source_sql)\n";
    $query .= "ON DUPLICATE KEY UPDATE ts='$ts', trader_id = $trader_id, mult=$mult, buy=$buy, source_ip=$source_sql;";
    log_cmsg("#QUERY: %s", $query);

    if ($mysqli->try_query($query)) {
        $touched = $pair;
        $ar = $mysqli->affected_rows;
        $id_added = $mysqli->select_value('id', 'signals', "WHERE (pair_id = $pair_id) AND (signal_no = $signal_no) AND (setup = $setup) AND (flags & $grid_flag = 0)");

        if (false === $prev) {
            echo "#OK: new record added.\n";
            file_add_contents(SIGNALS_LOG, tss()."#ADD: [$query], affected rows $ar, source = $source\n");
        } else {
            echo "#OK: record changed from previous ".json_encode($prev)."\n";
            file_add_contents(SIGNALS_LOG, tss()."#MODIFY: [$query], affected rows $ar, source = $source\n");
        }

        if ($id_added) {
            $cached_prices = load_cached_ticker_prices($mysqli, array_flip($pairs_map));
            $last_price = floatval($cached_prices[$pair_id] ?? 0);
            if ($last_price <= 0 && isset($cm_symbols[$pair_id])) {
                $last_price = floatval($cm_symbols[$pair_id]->last_price ?? 0);
            }
            if ($last_price > 0) {
            $tp_target = $last_price * 10;
            $mysqli->try_query("UPDATE $table_name\n SET stop_loss = $last_price, take_profit = $tp_target\n WHERE (id = $id_added) AND (setup = $setup);");
            echo "#OK: assigned SL price = $last_price\n";
            }
        }

        return true;
    } else {
        throw new Exception("Failed to add signal: ".$mysqli->error, 500);
    }
    }

    define('VWAP_CACHE_FILE', '/tmp/vwap_cache.json');

    function file_age($fname) {
    return time() - filemtime($fname) or 0;
    }

    function get_vwap(int $pair_id, $id_map, $mysqli, &$vwap_prices, &$vwap_cache, &$vwap_fails, &$cache_hits, $timeout) {
    if (!isset($id_map[$pair_id])) {
        echo "<!-- #ERROR: unknown pair_id #$pair_id -->\n";
        return;
    }
    $elps = 3600;
    $pair = strtolower($id_map[$pair_id]);
    if (isset($vwap_cache[$pair_id]))
        unset($vwap_cache[$pair_id]);

    if (isset($vwap_cache[$pair])) {
        $elps = time() - $vwap_cache[$pair]['ts'];
        if ($elps <= $timeout) {
            $cache_hits ++;
            $vwap_prices[$pair_id] = $vwap_cache[$pair]['vwap'];
            return;
        }
    }
    if (isset($vwap_fails[$pair_id])) return;

    $vwap_endpoint = resolve_vwap_endpoint();
    if ($vwap_endpoint === '') {
        printf("<!-- #WARN: VWAP endpoint is not configured for pair_id %d -->\n", $pair_id);
        $vwap_fails[$pair_id] = 1;
        return;
    }

    $vwap = curl_http_request("{$vwap_endpoint}?pair_id=$pair_id&limit=5");
    if (false === strpos($vwap, '#') && floatval($vwap) > 0) {
        $vwap_prices[$pair_id] = $vwap;
        $vwap_cache[$pair] = ['ts' => time(), 'vwap' => $vwap];
        printf("<!-- For %s vwap price loaded as %s, cache elps = $elps -->\n", $pair, $vwap);
    } else {
        printf("<!-- #WARN: For %s not loaded VWAP, response: $vwap -->\n", $pair);
        $vwap_fails[$pair_id] = 1;
    }
    }

    function load_vwap_prices($rows, $id_map, $mysqli, $action, &$vwap_prices, &$vwap_cache, &$vwap_fails, &$cache_hits) {
    $timeout = 120;
    if ('track' === $action) {
        $timeout = 30;
    }

    if (file_exists(VWAP_CACHE_FILE))
        $vwap_cache = file_load_json(VWAP_CACHE_FILE, null, true);

    $rqst = pr_time();
    foreach ($rows as $row) {
        $pair_id = intval($row['pair_id']);
        if (isset($vwap_prices[$pair_id]) || 0 == $pair_id) continue;
        get_vwap($pair_id, $id_map, $mysqli, $vwap_prices, $vwap_cache, $vwap_fails, $cache_hits, $timeout);
    }
    $elps = pr_time() - $rqst;
    file_save_json(VWAP_CACHE_FILE, $vwap_cache);

    if ($cache_hits > 0)
        printf("<!-- load VWAP time = %.1f sec, cache hits $cache_hits -->\n", $elps);
    else
        printf("<!-- load VWAP time = %.1f sec, cache data:\n%s -->\n", $elps, print_r($vwap_cache, true));

    $vwap_good = 0;
    foreach ($vwap_prices as $pair_id => $vwap)
        if ($vwap > 0) $vwap_good ++;

    return $vwap_good;
    }

    function check_tpsl($mysqli, $table_name, $setup, int $id, bool $long, float $price, float $tp, float $sl, int $flags, $pair) {
    $allowed = 'yep';
    $coef = 1.0;
    if (0 == $price) return $coef;
    $ts = date('Y-m-d H:i');
    $strict = "(id = $id) AND (setup = $setup)";

    $endless = boolval($flags & SIG_FLAG_SE);
    $thresh = $price * 0.2;  // РїРѕСЂРѕРі, РЅРёР¶Рµ РєРѕС‚РѕСЂРѕРіРѕ СЃС‚РѕРї РёР»Рё С‚РµР№Рє СЏРІР»СЏСЋС‚СЃСЏ РѕС‚РЅРѕСЃРёС‚РµР»СЊРЅС‹РјРё Рє С†РµРЅРµ РІС…РѕРґР°, РєРѕС‚РѕСЂР°СЏ РЅР° СЃРµСЂРІРµСЂРµ РЅРµРёР·РІРµСЃС‚РЅР°

    // take profit logic
    if ($tp > $thresh  && $flags & SIG_FLAG_TP) {
        if ($long  && $price > $tp)
            $allowed = "long.TP $price > $tp";
        if (!$long && $price < $tp)
            $allowed = "short.TP $price < $tp"; // not allow short
    }

    if ('yep' == $allowed)
        $mysqli->try_query("UPDATE $table_name SET ttl = 10 WHERE $strict; "); // possible check 10 times
    else {
        $mysqli->try_query("UPDATE $table_name SET ttl = ttl - 1 WHERE $strict; ");
        $coef = 0;
    }

    // stop loss logic
    if ($sl > $thresh && $flags & SIG_FLAG_SL) {
        if ($long  && $price < $sl)
            $allowed = "long.SL $price < $sl";
        if (!$long && $price > $sl)
            $allowed = "short.SL $price > $sl"; // not allow short

        if ('yep' == $allowed) { // stop not activated yet, or nearly to be activated
            $diff_pp = 100.0 * abs($price - $sl) / $price;  // difference in %
            $limit = 3.0;
            if ($price > 5000) $limit = 4.0; // extended range for BTC & ETH
            $coef = $endless ? round( min($limit, $diff_pp) / $limit, 1) : 1;  // LONG { if price - SL > limit% = full signal allow }
            $info = sprintf("id = $id, price = $price, SL = $sl, diff = %.2f%%, coef = %.1f, endless = %s", $diff_pp, $coef, $endless ? 'yes' : 'no');
            echo "<!-- $info -->\n";
            if ($coef < 1.0)
                file_add_contents('./logs/tpsl.log', "[$ts]. #SLOW: $info\n");
        } else {
            $coef = 0;
            if (!$endless) {
                $mysqli->try_query("DELETE FROM $table_name WHERE $strict AND (id = $id);");  // remove signal if SL reached
                log_cmsg("~C91#STOP_LOST:~C00 triggered for signal %d %s %s  at price %.2f", $id, $long ? 'LONG' : 'SHORT', $pair, $price);
            }
        }
    }
    if ($coef < 1)
        printf("<!-- TP/SL activated, coef = %.1f, price = $price -->\n", $coef);
    return $coef;
    }

    function cmp_pair($a, $b) {
    global $id_map;
    $pid_a = $a['pair_id'];
    $pid_b = $b['pair_id'];
    if ($pid_a == $pid_b)
        return intval($a['signal_no']) - intval($b['signal_no']);
    if (isset($id_map[$pid_a]) && isset($id_map[$pid_b]))
        return $id_map[$pid_a] < $id_map[$pid_b] ? -1 : 1;
    return $pid_a < $pid_b ? -1 : 1;
    }

    function load_signals($mysqli, $setup, $grid_flag, $filter_id) {
    $strict = "WHERE (setup = $setup) AND (flags & $grid_flag = 0) AND ";
    if ($filter_id > 0)
        $strict .=  "(pair_id = $filter_id )";
    else
        $strict .= '(pair_id > 0)';

    log_msg("#PERF: loading signals...");

    $rows = $mysqli->select_rows('id, ts, signal_no, buy, pair_id, mult, limit_price, take_profit, stop_loss, ttl, flags, comment', 'signals', $strict.' ORDER BY pair_id,signal_no', MYSQLI_ASSOC);
    if (is_null($rows))
        die("#ERROR: failed to fetch signals via query {$mysqli->last_query}:  {$mysqli->error}\n");

    usort($rows, 'cmp_pair');

    return $rows;
    }

    function process_signals_data($rows, $mysqli, $table_name, $setup, $id_map, $cm_symbols, $btc_pair_id, $eth_pair_id, $vwap_prices, $filter) {
    $accum_map = [];
    $ts_map = [];
    $buys = [];
    $shorts = [];
    $table_data = [];
    $sym_errs = 0;

    foreach ($id_map as $id => $sym)
        $accum_map[$id] = 0;

    if ($filter && count($rows) < 10) {
        printf("<!-- sorted rows:\n%s -->\n", print_r($rows, true));
    }

    if (0 == count($rows))
        printf("<!-- No signals yet for setup %d -->\n", $setup);

    echo "<!-- btc_id = $btc_pair_id, eth_id = $eth_pair_id -->\n";
    printf("<!--\n %s-->", print_r($GLOBALS['pairs_map'] ?? [], true));

    log_msg("#PERF: dumping signals");

    $cached_prices = load_cached_ticker_prices($mysqli, $id_map);

    foreach ($rows as $row) {
        list($id, $ts, $signal_no, $is_buy, $pair_id, $mult, $limit, $tp, $sl, $ttl, $flags, $comment) = array_values($row);
        $ts_map[$pair_id] = $ts;
        $pair = $id_map[$pair_id];
        $curr_ch = '$';
        if (strpos($pair, 'BTC', -3) !== false)
            $curr_ch = 'в‚ї';
        $is_buy = $is_buy > 0;
        $side = $is_buy ? 'BUY' : 'SELL';
        $key   = "$pair_id:$signal_no";
        $price = resolve_reference_price_for_pair($pair_id, $pair, $cached_prices, $vwap_prices, $cm_symbols, $btc_pair_id, $eth_pair_id);
        if ($price <= 0) {
            $sym_errs ++;
        }

        $coef = check_tpsl($mysqli, $table_name, $setup, $id, $is_buy, $price, $tp, $sl, $flags, $pair);
        $amount = round($mult * $coef);

        if ($is_buy) { // buying
            if (isset($shorts[$key])) { // this row closing exist SHORT signal
                $accum_map [$pair_id] += $shorts[$key];
            } elseif ($amount > 0) { // not existed signal = typical
                $accum_map [$pair_id] += $amount;
                $buys [$key] = $amount;
            }
        } else {  // selling
            if (isset($buys[$key])) { // this row closing exist LONG signal
                $accum_map [$pair_id] -= $buys[$key];
            } elseif ($amount > 0) { // not existed signal = typical
                $accum_map [$pair_id] -= $amount;
                $shorts [$key] = $amount;
                if ($accum_map [$pair_id] < 0)  $side = 'SHORT';
            }
        }

        printf("<!-- coef = $coef, amount = $amount, TTL = $ttl  --> \n");

        $row_data = [
            'id' => $id,
            'ts' => $ts,
            'signal_no' => $signal_no,
            'side' => $side,
            'pair' => $pair,
            'pair_id' => $pair_id,
            'mult' => $mult,
            'accum_pos' => $accum_map[$pair_id],
            'limit_price' => $limit,
            'take_profit' => $tp,
            'stop_loss' => $sl,
            'ttl' => $ttl,
            'flags' => $flags,
            'comment' => $comment,
            'curr_ch' => $curr_ch,
            'price' => $price,
            'coef' => $coef,
            'amount' => $amount,
            'is_buy' => $is_buy
        ];

        $table_data[] = $row_data;
    }

    return [
        'table_data' => $table_data,
        'accum_map' => $accum_map,
        'ts_map' => $ts_map,
        'buys' => $buys,
        'shorts' => $shorts,
        'sym_errs' => $sym_errs
    ];
    }

    function update_positions($mysqli, $setup, $accum_map, $source, $signal, $filter) {
    if ($filter) return; // РќРµ РѕР±РЅРѕРІР»СЏРµРј РїРѕР·РёС†РёРё РІ СЂРµР¶РёРјРµ С„РёР»СЊС‚СЂР°

    log_msg("#PERF: updating hype_last...");
    $ts_now = date(SQL_TIMESTAMP);
    $hype_map = $mysqli->select_map('pair_id,value', 'hype_last', "WHERE (account_id = 256) AND (setup = $setup)");
    if (is_null($hype_map))
        $hype_map = [];

    ksort($accum_map);
    echo "<!-- \n";

    // updating position as target
    foreach ($accum_map as $pair_id => $pos) {
        // TODO: add signal story log (change detection)
        $upd_ts = "ts = '$ts_now',";
        $updated = true;
        if (isset($hype_map[$pair_id]) && !is_null($hype_map[$pair_id]) && $pos == $hype_map[$pair_id]) {
            $upd_ts = '';
            $updated = false; // just checked
        }
        $source_sql = sql_quote($mysqli, $source);
        $query = "INSERT INTO `hype_last` (ts, ts_checked, account_id, setup, pair_id, `value`, value_change, `source`)\n VALUES ";
        $query .= "('$ts_now', '$ts_now', 256, $setup, $pair_id, $pos, 0, $source_sql)\n";
        $query .= "ON DUPLICATE KEY UPDATE $upd_ts ts_checked = '$ts_now', value=$pos, source=$source_sql;";

        if ($mysqli->try_query($query))
            printf(" position for pair_id #%3d %s = %7d ts = $ts_now\n", $pair_id, $updated ? 'updated' : 'checked', $pos);
        else
            printf("#FAIL: position for pair_id #%3d not saved\n", $pair_id);
    }
    echo "-->\n";

    if ($signal)
        file_add_contents(SIGNALS_LOG, $signal.' '.json_encode($accum_map)."\n");
    }

    function prepare_display_data($processed_data, $colors, $btc_pair_id, $eth_pair_id, $setup, $work_t) {
    return [
        'table_data' => $processed_data['table_data'],
        'accum_map' => $processed_data['accum_map'],
        'buys' => $processed_data['buys'],
        'shorts' => $processed_data['shorts'],
        'sym_errs' => $processed_data['sym_errs'],
        'colors' => $colors,
        'setup_info' => [
            'setup_id' => $setup,
            'btc_pair_id' => $btc_pair_id,
            'eth_pair_id' => $eth_pair_id,
            'processing_time' => $work_t
        ]
    ];
    }