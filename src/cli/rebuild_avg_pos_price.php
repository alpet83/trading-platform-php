<?php
    /**
     * Rebuild avg_pos_price from historical order data.
     *
     * Usage:
        *   php src/cli/rebuild_avg_pos_price.php [--exchange=deribit,bitmex] [--dry-run] [--smoke-test]
     */
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "CLI only\n");
        exit(1);
    }

    include_once(__DIR__ . '/../lib/common.php');
    include_once(__DIR__ . '/../lib/db_tools.php');
    include_once(__DIR__ . '/../lib/db_config.php');
    include_once(__DIR__ . '/../position_math.php');

    function rap_out(string $text = ''): void {
        fwrite(STDOUT, $text . "\n");
    }

    function rap_fail(string $text, int $code = 1): never {
        fwrite(STDERR, "#ERROR: $text\n");
        exit($code);
    }

    function rap_parse_args(array $argv): array {
        $opts = ['exchange' => '', 'dry_run' => false, 'smoke_test' => false];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--exchange=')) {
                $opts['exchange'] = trim(substr($arg, strlen('--exchange=')));
            }
            if ($arg === '--dry-run') {
                $opts['dry_run'] = true;
            }
            if ($arg === '--smoke-test') {
                $opts['smoke_test'] = true;
            }
        }
        return $opts;
    }

    function rap_existing_order_tables(mysqli_ex $db, string $prefix): array {
        $names = [
            'archive_orders', 'lost_orders', 'matched_orders', 'pending_orders',
            'other_orders', 'mm_asks', 'mm_bids', 'mm_exec', 'mm_limit',
        ];

        $result = [];
        foreach ($names as $name) {
            $table = strtolower($prefix . '__' . $name);
            if ($db->table_exists($table) && rap_column_exists($db, $table, 'avg_pos_price')) {
                $result[] = $table;
            }
        }
        return $result;
    }

    function rap_column_exists(mysqli_ex $db, string $table, string $column): bool {
        $db_name = $db->active_db();
        $table_name = $table;
        if (strpos($table, '.') !== false) {
            [$db_name, $table_name] = explode('.', $table, 2);
        }

        $table_esc = $db->real_escape_string($table_name);
        $column_esc = $db->real_escape_string($column);
        $db_esc = $db->real_escape_string($db_name);
        $count = $db->select_value(
            'COUNT(*)',
            'information_schema.columns',
            "WHERE table_schema = '$db_esc' AND table_name = '$table_esc' AND column_name = '$column_esc'"
        );
        return intval($count) > 0;
    }

    function rap_floor_minute(string $ts): string {
        $base = substr($ts, 0, 19);
        $unix = strtotime($base);
        if ($unix === false)
            return $ts;
        return date('Y-m-d H:i:00', $unix);
    }

    function rap_ceil_minute(string $ts): string {
        $base = substr($ts, 0, 19);
        $unix = strtotime($base);
        if ($unix === false)
            return $ts;

        $sec = intval(date('s', $unix));
        if ($sec > 0)
            $unix += (60 - $sec);

        return date('Y-m-d H:i:00', $unix);
    }

    function rap_pair_ticker(mysqli_ex $db, string $prefix, int $pair_id): string {
        $tables = [
            strtolower($prefix . '__ticker_map'),
            strtolower($prefix . '.ticker_map'),
        ];

        foreach ($tables as $table) {
            if (!$db->table_exists($table))
                continue;

            $ticker = trim((string)$db->select_value('ticker', $table, "WHERE pair_id = $pair_id LIMIT 1"));
            if ($ticker === '')
                $ticker = trim((string)$db->select_value('symbol', $table, "WHERE pair_id = $pair_id LIMIT 1"));

            if ($ticker !== '')
                return strtolower($ticker);
        }

        $pairs_map_table = strtolower($prefix . '__pairs_map');
        if ($db->table_exists($pairs_map_table)) {
            $pair_name = trim((string)$db->select_value('pair', $pairs_map_table, "WHERE pair_id = $pair_id LIMIT 1"));
            $ticker = rap_symbol_to_ticker($pair_name);
            if ($ticker !== '')
                return $ticker;
        }

        return '';
    }

    function rap_symbol_to_ticker(string $symbol): string {
        $src = strtolower(trim($symbol));
        if ($src === '')
            return '';

        if (str_ends_with($src, '-perpetual')) {
            $base = substr($src, 0, -strlen('-perpetual'));
            if ($base !== '')
                return preg_replace('/[^a-z0-9]+/', '', $base) . 'usd';
        }

        $src = preg_replace('/[^a-z0-9]/', '', $src);
        if ($src === '')
            return '';

        if (preg_match('/^[a-z0-9]+(usd|usdt|usdc|btc|eth)$/', $src))
            return $src;

        return '';
    }

    function rap_discover_fallback_candle_tables(mysqli_ex $db, string $ticker): array {
        static $cache = [];

        $ticker = strtolower(trim($ticker));
        if ($ticker === '')
            return [];

        if (isset($cache[$ticker]))
            return $cache[$ticker];

        $ticker_esc = $db->real_escape_string($ticker);
        $like_tail = $db->real_escape_string('__candles__' . $ticker);

        $rows = $db->select_rows(
            'table_schema, table_name',
            'information_schema.tables',
            "WHERE (table_schema NOT IN ('information_schema','mysql','performance_schema','sys'))"
            . " AND ((table_name = 'candles__$ticker_esc') OR (table_name LIKE '%$like_tail'))"
            . " AND (table_name NOT LIKE '%__1d')"
            . " ORDER BY table_schema, table_name",
            MYSQLI_ASSOC
        );

        $result = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $schema = strtolower(trim(strval($row['table_schema'] ?? '')));
                $table = strtolower(trim(strval($row['table_name'] ?? '')));
                if ($schema === '' || $table === '')
                    continue;

                $result[] = $schema . '.' . $table;
            }
        }

        $cache[$ticker] = array_values(array_unique($result));
        return $cache[$ticker];
    }

    function rap_candle_extrema(mysqli_ex $db, string $prefix, string $ticker, string $ts_from, string $ts_to): ?array {
        if ($ticker === '')
            return null;

        $from = rap_floor_minute($ts_from);
        $to = rap_ceil_minute($ts_to);
        if ($from > $to) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }

        $tables = [
            strtolower($prefix . '.candles__' . $ticker),
            strtolower('datafeed.' . $prefix . '__candles__' . $ticker),
            strtolower('datafeed.candles__' . $ticker),
        ];

        $fallback_tables = rap_discover_fallback_candle_tables($db, $ticker);
        foreach ($fallback_tables as $tb)
            $tables[] = strtolower($tb);

        $tables = array_values(array_unique($tables));

        foreach ($tables as $table) {
            if (!$db->table_exists($table))
                continue;

            $has_hl = rap_column_exists($db, $table, 'high') && rap_column_exists($db, $table, 'low');
            $has_oc = rap_column_exists($db, $table, 'open') && rap_column_exists($db, $table, 'close');
            if (!$has_hl && !$has_oc)
                continue;

            $fields = $has_hl
                ? 'MIN(`low`) AS p_min, MAX(`high`) AS p_max'
                : 'MIN(LEAST(`open`, `close`)) AS p_min, MAX(GREATEST(`open`, `close`)) AS p_max';

            $from_sql = $db->format_value($from);
            $to_sql = $db->format_value($to);
            $rows = $db->select_rows($fields, $table, "WHERE (`ts` >= $from_sql) AND (`ts` <= $to_sql)", MYSQLI_ASSOC);
            if (!is_array($rows) || count($rows) === 0)
                continue;

            $row = $rows[0];
            if (!isset($row['p_min']) || !isset($row['p_max']) || is_null($row['p_min']) || is_null($row['p_max']))
                continue;

            return [floatval($row['p_min']), floatval($row['p_max'])];
        }

        return null;
    }

    function rap_build_union_query(array $tables, int $pair_id): string {
        $parts = [];
        foreach ($tables as $table) {
            $parts[] = "SELECT '$table' AS src_table, id, pair_id, in_position, out_position, avg_price, price, matched, updated, avg_pos_price\n"
                     . "FROM `$table`\n"
                     . "WHERE (pair_id = $pair_id) AND (matched > 0)";
        }

        $query = implode("\nUNION ALL\n", $parts);
        $query .= "\nORDER BY updated ASC, id ASC, src_table ASC";
        return $query;
    }

    function rap_rebuild_pair(mysqli_ex $db, string $prefix, int $pair_id, array $tables, bool $dry_run, bool $smoke_test): array {
        $rows = $db->select_rows('*', '(' . rap_build_union_query($tables, $pair_id) . ') AS Q', '', MYSQLI_ASSOC);
        if (!is_array($rows) || count($rows) === 0) {
            return [0, 0, 0, 0.0, ['checks' => 0, 'passed' => 0, 'failed' => 0, 'missing' => 0]];
        }

        $updates_orders = 0;
        $updates_history = 0;
        $state_pos = 0.0;
        $state_avg = 0.0;
        $segment_start_ts = null;
        $timeline = [];
        $smoke = ['checks' => 0, 'passed' => 0, 'failed' => 0, 'missing' => 0];
        $pair_ticker = $smoke_test ? rap_pair_ticker($db, $prefix, $pair_id) : '';

        foreach ($rows as $row) {
            $pos_before = floatval($row['in_position']);
            $pos_after = floatval($row['out_position']);
            $fill_price = max(floatval($row['avg_price']), floatval($row['price']));

            // Fallback to state continuity when row has incomplete position metadata.
            if (abs($pos_before) < 1e-14 && abs($state_pos) > 1e-14) {
                $pos_before = $state_pos;
            }

            $avg_after = PositionMath::next_avg_pos_price($pos_before, $pos_after, $state_avg, $fill_price);

            $ts_now = strval($row['updated']);
            if (abs($pos_before) < 1e-14 && abs($pos_after) > 1e-14)
                $segment_start_ts = $ts_now;
            if (($pos_before * $pos_after) < 0)
                $segment_start_ts = $ts_now;
            if (is_null($segment_start_ts) && abs($pos_after) > 1e-14)
                $segment_start_ts = $ts_now;

            if ($smoke_test && $pair_ticker !== '' && !is_null($segment_start_ts) && abs($pos_after) > abs($pos_before) && abs($avg_after) > 1e-12) {
                $smoke['checks']++;
                $extrema = rap_candle_extrema($db, $prefix, $pair_ticker, $segment_start_ts, $ts_now);
                if (!is_array($extrema))
                    $smoke['missing']++;
                else {
                    [$p_min, $p_max] = $extrema;
                    if ($avg_after >= $p_min - 1e-9 && $avg_after <= $p_max + 1e-9)
                        $smoke['passed']++;
                    else
                        $smoke['failed']++;
                }
            }

            if (abs($avg_after - floatval($row['avg_pos_price'])) > 1e-10) {
                $updates_orders++;
                if (!$dry_run) {
                    $src_table = $row['src_table'];
                    $id = intval($row['id']);
                    $avg_sql = $db->format_value($avg_after);
                    $db->try_query("UPDATE `$src_table` SET avg_pos_price = $avg_sql WHERE id = $id LIMIT 1");
                }
            }

            $state_pos = $pos_after;
            $state_avg = $avg_after;
            $timeline[] = [
                'ts' => strval($row['updated']),
                'avg' => $state_avg,
            ];

            if (abs($pos_after) < 1e-14)
                $segment_start_ts = null;
        }

        $positions_table = strtolower($prefix . '__positions');
        if ($db->table_exists($positions_table) && rap_column_exists($db, $positions_table, 'avg_pos_price')) {
            $avg_sql = $db->format_value($state_avg);
            if (!$dry_run) {
                $db->try_query("UPDATE `$positions_table` SET avg_pos_price = $avg_sql WHERE pair_id = $pair_id");
            }
        }

        $history_table = strtolower($prefix . '__position_history');
        if ($db->table_exists($history_table) && rap_column_exists($db, $history_table, 'avg_pos_price')) {
            $hist = $db->select_rows('ts, avg_pos_price', $history_table, "WHERE pair_id = $pair_id ORDER BY ts ASC", MYSQLI_ASSOC);
            if (is_array($hist) && count($hist) > 0) {
                $idx = 0;
                $cur_avg = 0.0;
                $timeline_count = count($timeline);
                foreach ($hist as $hrow) {
                    $ts = strval($hrow['ts']);
                    while ($idx < $timeline_count && $timeline[$idx]['ts'] <= $ts) {
                        $cur_avg = floatval($timeline[$idx]['avg']);
                        $idx++;
                    }

                    if (abs($cur_avg - floatval($hrow['avg_pos_price'])) > 1e-10) {
                        $updates_history++;
                        if (!$dry_run) {
                            $avg_sql = $db->format_value($cur_avg);
                            $ts_sql = $db->format_value($ts);
                            $db->try_query("UPDATE `$history_table` SET avg_pos_price = $avg_sql WHERE pair_id = $pair_id AND ts = $ts_sql");
                        }
                    }
                }
            }
        }

        return [count($rows), $updates_orders, $updates_history, $state_avg, $smoke];
    }

    $opts = rap_parse_args(array_slice($argv, 1));
    $dry_run = boolval($opts['dry_run']);
    $smoke_test = boolval($opts['smoke_test']);

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = init_remote_db('trading');
    if (!$db) {
        rap_fail('Failed to connect to trading DB');
    }

    $prefixes = [];
    if (strlen($opts['exchange']) > 0) {
        foreach (explode(',', $opts['exchange']) as $e) {
            $e = strtolower(trim($e));
            if ($e !== '') {
                $prefixes[] = $e;
            }
        }
    } else {
        $tables = $db->select_col('table_name', 'information_schema.tables', "WHERE table_schema = 'trading' AND table_name LIKE '%__positions'", MYSQLI_NUM);
        if (is_array($tables)) {
            foreach ($tables as $table_name) {
                $prefixes[] = str_replace('__positions', '', strtolower($table_name));
            }
        }
    }

    $prefixes = array_values(array_unique($prefixes));
    sort($prefixes);

    if (count($prefixes) == 0) {
        rap_fail('No exchange prefixes detected');
    }

    rap_out('#REBUILD avg_pos_price started');
    rap_out('#MODE: ' . ($dry_run ? 'dry-run' : 'apply'));
    rap_out('#SMOKE_TEST: ' . ($smoke_test ? 'on' : 'off'));
    rap_out('#EXCHANGES: ' . implode(',', $prefixes));

    $total_pairs = 0;
    $total_rows = 0;
    $total_updates_orders = 0;
    $total_updates_history = 0;
    $total_smoke_checks = 0;
    $total_smoke_passed = 0;
    $total_smoke_failed = 0;
    $total_smoke_missing = 0;

    foreach ($prefixes as $prefix) {
        $tables = rap_existing_order_tables($db, $prefix);
        if (count($tables) == 0) {
            rap_out("#WARN: $prefix no order tables with avg_pos_price");
            continue;
        }

        $positions_table = strtolower($prefix . '__positions');
        if (!$db->table_exists($positions_table)) {
            rap_out("#WARN: $prefix positions table not found");
            continue;
        }

        $pairs = $db->select_col('pair_id', $positions_table, 'ORDER BY pair_id', MYSQLI_NUM);
        if (!is_array($pairs)) {
            rap_out("#WARN: $prefix no pairs in positions");
            continue;
        }

        $ex_rows = 0;
        $ex_ord_upd = 0;
        $ex_hist_upd = 0;
        $ex_pairs = 0;
        $ex_smoke_checks = 0;
        $ex_smoke_passed = 0;
        $ex_smoke_failed = 0;
        $ex_smoke_missing = 0;

        foreach ($pairs as $pair_id) {
            $pair_id = intval($pair_id);
            if ($pair_id <= 0) {
                continue;
            }

            [$rows_cnt, $upd_orders, $upd_hist, $last_avg, $smoke] = rap_rebuild_pair($db, $prefix, $pair_id, $tables, $dry_run, $smoke_test);
            if ($rows_cnt <= 0) {
                continue;
            }

            $ex_pairs++;
            $ex_rows += $rows_cnt;
            $ex_ord_upd += $upd_orders;
            $ex_hist_upd += $upd_hist;
            $ex_smoke_checks += intval($smoke['checks']);
            $ex_smoke_passed += intval($smoke['passed']);
            $ex_smoke_failed += intval($smoke['failed']);
            $ex_smoke_missing += intval($smoke['missing']);
        }

        $total_pairs += $ex_pairs;
        $total_rows += $ex_rows;
        $total_updates_orders += $ex_ord_upd;
        $total_updates_history += $ex_hist_upd;
        $total_smoke_checks += $ex_smoke_checks;
        $total_smoke_passed += $ex_smoke_passed;
        $total_smoke_failed += $ex_smoke_failed;
        $total_smoke_missing += $ex_smoke_missing;

        rap_out(sprintf(
            '#%s pairs=%d rows=%d order_updates=%d history_updates=%d smoke_checks=%d smoke_pass=%d smoke_fail=%d smoke_missing=%d',
            $prefix,
            $ex_pairs,
            $ex_rows,
            $ex_ord_upd,
            $ex_hist_upd,
            $ex_smoke_checks,
            $ex_smoke_passed,
            $ex_smoke_failed,
            $ex_smoke_missing
        ));
    }

    rap_out(sprintf(
        '#DONE pairs=%d rows=%d order_updates=%d history_updates=%d smoke_checks=%d smoke_pass=%d smoke_fail=%d smoke_missing=%d mode=%s smoke=%s',
        $total_pairs,
        $total_rows,
        $total_updates_orders,
        $total_updates_history,
        $total_smoke_checks,
        $total_smoke_passed,
        $total_smoke_failed,
        $total_smoke_missing,
        $dry_run ? 'dry-run' : 'apply',
        $smoke_test ? 'on' : 'off'
    ));
