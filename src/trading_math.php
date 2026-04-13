<?php

    trait TradingMath {
        protected array $contract_anchor_cache = [];

        /** returns session or real BTC price */
        public function BitcoinPrice() {
            $sp = $this->session_btc_price ?? 0;
            return $sp > 9000 ? $sp : $this->Engine()->get_btc_price();
        }

        /**
         * function  CalcVWAP uses 1m candles to calculate VWAP, if is not available for current exchange using default data exchange
         * @param int $pair_id
         * @param string $ts_before
         * @param int $count
         * @return void
         */
        public function CalcVWAP(int $pair_id, string $ts_before, int $count = 24 * 60): float {
            $engine = $this->Engine();
            $ti = $engine->TickerInfo($pair_id);
            if (!is_object($ti)) {
                throw new Exception("TickerInfo not found for pair_id $pair_id");
            }
            if ($ts_before < '2010-01-01 00:00:00') {
                throw new Exception("Invalid timestamp $ts_before for pair_id $pair_id");
            }


            $datafeed = $this->mysqli_datafeed;
            $default = $ti->vwap ?? $ti->last_price; // TODO: use tickers
            if (!is_object($datafeed)) {
                $this->LogError('~C31 #WARN:~C00 datafeed DB not available, using current data for pair #%d', $pair_id);
                return $default;
            }

            $dedic = strtolower($engine->exchange);
            $dbs = [$datafeed->active_db(), $engine->history_db, $dedic, 'bitfinex', 'binance'];
            $candles = [];
            $reject = [];

            foreach ($dbs as $db_name) {
                $s_table = $engine->TableName("$db_name.ticker_map", true, $datafeed); // ОПАСНОСТЬ: в текущем дизайне таблицы сильно отличаются для БД datafeed и trading
                if (!$datafeed->table_exists($s_table)) {
                    $reject[$db_name] = "!ticker_map";
                    continue;
                }

                $ticker = $datafeed->select_value('ticker', $s_table, "WHERE pair_id = $pair_id");
                if ($ticker === null) {
                    $reject[$db_name] = "!ticker #$pair_id";
                    continue;
                }
                $table = strtolower("$db_name.candles__{$ticker}"); // ожидается что в этой таблице есть АКТУАЛЬНЫЕ минутные свечи
                if (!$datafeed->table_exists($table)) {
                    $reject[$db_name] = "!$table";
                    continue;
                }

                $strict = "WHERE (ts <= '$ts_before') AND (volume > 0) ORDER BY ts DESC LIMIT $count";
                $candles = $datafeed->select_rows('*', $table, $strict, MYSQLI_OBJECT);
                if (is_array($candles) && count($candles) >= $count) {
                    break;
                }
            }

            if (!is_array($candles) || 0 == count($candles)) {
                $t_table = $engine->TableName('ticker_history');
                // запасный вариант: грубая оценка по истории тикера
                $rows = [];
                $db_name = $datafeed->active_db();
                if ($datafeed->table_exists($t_table)) {
                    $rows = $datafeed->select_rows('*', $t_table, "WHERE (pair_id = $pair_id) AND (ts < '$ts_before') ORDER BY ts DESC LIMIT $count", MYSQLI_OBJECT);
                } else {
                    $reject[$db_name] = "!$t_table";
                }

                if (is_array($rows) && count($rows) == $count) {
                    $rows = array_reverse($rows);
                    $v_prev = 0;
                    $volume = 0;
                    $qty = 0;
                    foreach ($rows as $row) {
                        if (0 == $v_prev) {
                            $v_prev = $row->daily_vol;
                            continue;
                        }
                        $vchange = $row->daily_vol - $v_prev;
                        $qty += $vchange;
                        $volume += $vchange * $row->last;
                    }
                    if ($volume > 0) {
                        return $ti->RoundPrice($volume / $qty);
                    }

                }
                $this->LogMsg("~C31#WARN(CalcVWAP):~C00 can't load candles for pair_id %d %s, using default price %s", $pair_id, json_encode($reject), $default);
                return $default;
            }

            $volume = 0;
            $qty = 0;
            foreach ($candles as $cd) {
                $qty += $cd->volume;
                $volume += $cd->volume * ($cd->open + $cd->close) * 0.5; // avg median
            }
            if ($qty > 0) {
                return $ti->RoundPrice($volume / $qty);
            } else {
                return $default;
            }
        }

        protected function resolvePositionAnchorPrices(int $pair_id, TickerInfo $tinfo, float $market_price, float $market_btc_price): array {
            $batch_price = $market_price;
            $batch_btc_price = $market_btc_price;
            $source = 'market';

            if (!($tinfo->is_quanto || $tinfo->is_inverse)) {
                return [$batch_price, $batch_btc_price, $source];
            }

            $avg_price = $this->last_batch_price($pair_id);
            if (is_float($avg_price) && $avg_price > 0) {
                $batch_price = $avg_price;
                $source = 'last_batch';
            } else {
                $session_price = $this->lastSessionAnchorPrice($pair_id);
                if ($session_price > 0) {
                    $batch_price = $session_price;
                    $source = 'prev_session';
                    $this->notifyPrevSessionFallback($pair_id, $tinfo->pair, $batch_price, 'price');
                }
            }

            if ($tinfo->is_quanto) {
                $last_btc_price = $this->last_batch_btc_price($pair_id);
                if ($last_btc_price > 0) {
                    $batch_btc_price = $last_btc_price;
                } else {
                    $session_btc_price = $this->lastSessionAnchorBtcPrice($pair_id);
                    if ($session_btc_price > 0) {
                        $batch_btc_price = $session_btc_price;
                        $this->notifyPrevSessionFallback($pair_id, $tinfo->pair, $batch_btc_price, 'btc_price');
                    }
                }
            }

            return [$batch_price, $batch_btc_price, $source];
        }

        private function lastSessionAnchorPrice(int $pair_id): float {
            $cache_key = "price:$pair_id";
            if (isset($this->contract_anchor_cache[$cache_key])) {
                return $this->contract_anchor_cache[$cache_key];
            }

            $engine = $this->Engine();
            $table = strtolower($engine->exchange . '__batches');
            if (!$this->mysqli->table_exists($table)) {
                $this->contract_anchor_cache[$cache_key] = 0.0;
                return 0.0;
            }

            $session_start = $this->mysqli->real_escape_string($this->session_start ?? gmdate('Y-m-d 00:00'));
            $value = $this->mysqli->select_value('exec_price', $table,
                "WHERE (pair_id = $pair_id) AND (exec_price > 0) AND (ts < '$session_start') ORDER BY id DESC");

            $result = max(0.0, floatval($value));
            $this->contract_anchor_cache[$cache_key] = $result;
            return $result;
        }

        private function lastSessionAnchorBtcPrice(int $pair_id): float {
            $cache_key = "btc:$pair_id";
            if (isset($this->contract_anchor_cache[$cache_key])) {
                return $this->contract_anchor_cache[$cache_key];
            }

            $engine = $this->Engine();
            $table = strtolower($engine->exchange . '__batches');
            if (!$this->mysqli->table_exists($table)) {
                $this->contract_anchor_cache[$cache_key] = 0.0;
                return 0.0;
            }

            $session_start = $this->mysqli->real_escape_string($this->session_start ?? gmdate('Y-m-d 00:00'));
            $value = $this->mysqli->select_value('btc_price', $table,
                "WHERE (pair_id = $pair_id) AND (btc_price > 0) AND (ts < '$session_start') ORDER BY id DESC");

            $result = max(0.0, floatval($value));
            $this->contract_anchor_cache[$cache_key] = $result;
            return $result;
        }

        private function notifyPrevSessionFallback(int $pair_id, string $pair, float $value, string $kind): void {
            $flag_key = "warn:$kind:$pair_id";
            if (isset($this->contract_anchor_cache[$flag_key])) {
                return;
            }

            $this->contract_anchor_cache[$flag_key] = 1;
            $this->LogMsg("~C91 #WARN:~C00 %s anchor fallback to prev session %s = %.8f", $pair, $kind, $value);
        }

        private function last_batch_price(int $pair_id) {
            $engine = $this->Engine();
            $exch = $engine->exchange;
            // logic: используются все сигналы последней направленности (покупки или продажи), для высчета средней цены входа
            $price = 0;
            $qty   = 0;
            $rows =  sqli()->select_rows('exec_price,start_pos,target_pos,exec_qty', strtolower($exch.'__batches'), "WHERE (pair_id = $pair_id) AND (exec_price > 0) AND (exec_qty > 0) ORDER BY id DESC LIMIT 50");
            $prv_dir = 0;
            $lines = [];
            foreach ($rows as $row) {
                $pos_dir = signval($row[2] - $row[1]);
                if ($prv_dir != 0 && $pos_dir != $prv_dir) {
                    break;
                }
                $price += $row[0] * $row[3];
                $qty   += $row[3];
                $prv_dir = $pos_dir;
                $lines [] = sprintf("+ %f x %f = %f ", $row[0], $row[3], $price);
            }
            if ($qty > 0) {
                $price =  $price / $qty;
            } // average it
            if (tp_debug_mode_enabled()) {
                file_put_contents("data/last_batch_price_$pair_id.dbg", " result = $price \n");
            }
            return $price;
        }

        private function last_batch_btc_price(int $pair_id) {
            $engine = $this->Engine();
            $exch = $engine->exchange;
            $table = strtolower($exch.'__batches');
            $mysqli = $this->mysqli;
            $last = $mysqli->select_value('ts', $table, "WHERE (pair_id = $pair_id) ORDER by id DESC");
            $result = $mysqli->select_value('btc_price', $table, "WHERE (pair_id = $pair_id) AND (btc_price > 0) ORDER BY id DESC");
            if ($result && $result > 0) {
                return $result;
            }

            $result = $engine->get_btc_price();

            if ($last) {
                $res  = $mysqli->select_row('price', "{$exch}__mixed_orders", "WHERE (pair_id = 1) AND (price > 0) AND (ts >= '$last') ORDER BY id DESC LIMIT 1");
                if (is_array($res)) {
                    $result = $res[0];
                    $mysqli->try_query("UPDATE `$table` SET btc_price = $result WHERE (pair_id = $pair_id) AND (ts >= '$last')"); // patch
                }
                $this->LogMsg("~C91 #WARN:~C00 cannot retrieve last btc_price from~C92 %s~C00 for #%d, last batch %s, detected = %g ", $table, $pair_id, $last, $result); // typicaly never trade for this pair
            }
            return $result;
        }
    }
