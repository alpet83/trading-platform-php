<?php
    function td2s(float|int $dir): string {
        if ($dir > 0) return 'BUY';
        if ($dir < 0) return 'SELL';
        return 'HOLD';
    }


   /** 
    * Трейт торгового цикла, подключаемый к торговому ядру (TradingCore).
    * Содержит критические методы, логики поддержания целевой позиции.
    */
   trait TradingLoop {

        public     $trade_enabled = false;
        protected  $trade_skip  = 10;


        public function ProcessFilled(OrderInfo $info) {
        if ($info->Cost() < REPORTABLE_ORDER_COST)
            $this->order_reports[$info->id] = $info;
        }
        public function ScanForTrade(): array {      
            $minute = date('i');
            $engine = $this->Engine();
            $ignored = &$this->ignored_ctr;
            $sig_feed = $this->SignalFeed();
            
            
                // целевую позицию нужно получить в базовом активе, а не в нативном значении (контракты), без масштабирования по настройкам пары
            $now = date_ms(SQL_TIMESTAMP3);
            if (is_object($sig_feed) && $sig_feed->count() > 0)  
                // теперь используется более гибкая схема, с расчетом через сигналы
                foreach ($this->pairs_map as $pair_id => $pair) {          
                    $tinfo = $engine->TickerInfo($pair_id);
                    if (!$tinfo) {
                        $ignored[IGNORE_NO_INFO] ++;
                        continue;                  
                    }
                    if ($tinfo->expired || $tinfo->last_price <= 0) {
                        $ignored[IGNORE_EXPIRED] ++;
                        continue;                  
                    }   
                    if (!isset($this->target_pos[$pair_id]))            
                        $this->target_pos[$pair_id] = ['value' => 1e-10, 'value_change' => 0, 'ts_checked' => $now, 'src_account' => 0, 'symbol' => $pair, 'scaled' => 0, 'mature' => 1];

                    $rec = $this->target_pos[$pair_id];    

                    $nat_pos = $sig_feed->CalcPosition ($pair_id, Y, Y, N);      
                    $raw_pos = $sig_feed->CalcPosition ($pair_id, Y, N, Y);      
                    
                    $rec['pair'] = $pair;
                    $curr = $rec['value'];                    
                    if ($raw_pos != $curr && $tinfo) {
                        $scaled = $engine->ScaleQty($pair_id, $raw_pos); 
                        $this->LogMsg("~C97#CALC_POS_QTY:~C00 %s upgrade raw pos from %s to %s, native = %s,  scaled = %s", 
                                $pair, $tinfo->FormatQty($curr, Y), $tinfo->FormatQty($raw_pos, Y),
                                        $tinfo->FormatAmount($nat_pos, Y),  $tinfo->FormatQty($scaled, Y));
                        $rec['value'] = $raw_pos;       
                        $rec['scaled'] = $scaled;
                        $rec['amount'] = $nat_pos;
                        $rec['ts'] = $now;
                        $offset = $this->offset_pos[$pair_id] ?? 0;
                        $table = $engine->TableName('position_history');            
                        $cpos = $engine->CurrentPos($pair_id);      
                        $cpos_qty = $engine->AmountToQty($pair_id, $tinfo->last_price, $engine->get_btc_price(), $cpos);
                        $prev = $this->mysqli->select_value('target', $table, "WHERE (pair_id = $pair_id) AND (account_id = {$engine->account_id}) ORDER BY ts DESC");      
                        if (is_null($prev) || $prev != $nat_pos)  {              
                            $query = "INSERT IGNORE INTO `$table` (pair_id, account_id, `value`, `value_qty`, `target`, `offset`)\n";
                            $query .= "VALUES ($pair_id, {$engine->account_id}, $cpos, $cpos_qty, $nat_pos, $offset); ";            
                            $this->mysqli->try_query($query);              
                        }
                    }              
                    $rec['ts_checked'] = $now;
                    if ($pair_id > 110)
                        $this->LogMsg("~C94#DBG:~C00 for $pair_id position record %s ", json_encode($rec));
                    $this->target_pos[$pair_id] = $rec;
                }

            file_put_contents("data/target_pos.dump",  print_r($this->target_pos, true));  

            $result = [];    
            foreach ($this->target_pos as $pair_id => $rec) {
                $pair = $this->FindPair($pair_id);
                if (!$pair) { 
                if ($minute <= 5)
                    $this->LogMsg("~C91#WARN:~C00 pair ~C94#$pair_id~C00 not configured, but position available");
                    continue; // not supported by exchange/configuration
                }  
                $tinfo = $engine->TickerInfo($pair_id);
                if (!$tinfo) {
                    $ignored[IGNORE_NO_INFO] ++;
                    continue; // TODO: error need handle
                }        
                if ($tinfo->expired) {
                    $ignored[IGNORE_EXPIRED] ++;
                    continue;                  
                }   

                if (!isset($this->current_pos[$pair_id])) {
                    $this->LogError("~C91#FATAL:~C00 current position not loaded for %s", $pair);
                    continue;
                }
                if (isset($this->postpone_orders[$pair_id]))  {
                    $this->LogMsg('#DBG: skipping trade %s, due postpone timer = %d', $pair, $this->postpone_orders[$pair_id]);
                    $this->postpone_orders[$pair_id] --;
                    if (0 == $this->postpone_orders[$pair_id])
                        unset($this->postpone_orders[$pair_id]);  
                    $ignored[IGNORE_BUSY_TRADE] ++;
                    continue;
                }      
                $result [$pair_id] = $rec;
            }

            ksort($result);      
            file_put_contents("data/trading_pos.dump",  print_r($result, true));
            return $result;  
        }

        protected function OnPositionOverflow(float $pos, float $limit, ValueConstraint $constraint) {
            $this->LogError("~C91#CRITICAL:~C00 position overflow: tried set %.6f over limit %.6f for constraint %s", $pos, $limit, $constraint->descr);
            return $limit;
        }

        protected function OnRiskAlert(int $pos, int $limit, ValueConstraint $constraint) {
            $msg = sprintf("~C91#ALERT:~C00 risk limit approaching: value %d over alert limit %d for constraint %s", $pos, $limit, $constraint->descr);
            throw new ErrorException($msg);
        }

        protected function SetupLimits(TradingContext $ctx, bool $zero_cross_allowed) {
            $engine = $this->Engine();
            $ti = $ctx->tinfo;
            $price = $ti->last_price;
            $pair_id = $ti->pair_id;
            $config = $this->configuration;
            $ctx->allow_zero_cross = $zero_cross_allowed;

            $ctx->price = $price = $ti->mid_spread > 0 ? $ti->mid_spread : $ti->last_price; // reference price is last
            assert ($price > 0, "Price is zero for pair $pair_id");

            $ctx->btc_price = $btc_price = $engine->get_btc_price();            
            $ctx->max_pos_cost = $this->ConfigValue('max_pos_cost', 100000); // loads config from DB            
            if ($ti->is_btc_pair)
                $ctx->max_pos_cost /= $ctx->btc_price; // TODO: need smart handling

            $ctx->max_pos_qty = $ctx->max_pos_cost / $price;
            $ctx->max_pos = $engine->QtyToAmount($ti->pair_id, $price, $btc_price, $ctx->max_pos_qty);

            $min_cost = $config->min_order_cost; // это минимальная стоимость ордера в базовой валюте
            $max_cost = $config->max_order_cost; // это максимальная стоимость ордера в базовой валюте
            if ($ti->is_quanto)
                $min_cost = max($min_cost, 50); // TODO: debug bias unexpected volatility

            $ctx->min_cost = $min_cost;
            $ctx->max_cost = $max_cost;
            $ctx->cost_limit = $max_cost; // будет вероятно ещё уменьшаться, в зависимости от агрессии алгоритма

            if ($ti->is_btc_pair) {
                $min_cost = round($min_cost / $ctx->btc_price, 7);     
                $max_cost = round($max_cost / $ctx->btc_price, 7);     
            }
            $ctx->min_qty = $ti->RoundQty($min_cost / $price);    
            $ctx->max_qty = $ti->RoundQty($max_cost / $price );
            $ctx->min_amount = $engine->QtyToAmount($pair_id, $price, $btc_price, $ctx->min_qty, 'calc:min_qty'); 
            $ctx->max_amount = $engine->QtyToAmount($pair_id, $price, $btc_price,  $ctx->max_qty, 'calc:max_qty');  

            $extreme_qty = $ti->RoundQty(ABNORMAL_HIGH_COST / $price); // стоимость такой величины приведет к зарегистрированному сбою
            $extreme_amount = $engine->QtyToAmount($pair_id, $price, $btc_price, $extreme_qty);

            // настройка умных переменных с заданием базовых лимитов. Все лимиты должны быть заданы до последнего изменения переменной
            
            // no changes as start
            $tp = $ctx->get_var('target_pos');
            // опциональный ограничитель: не пересекать ноль при смене позиции, необходим некоторым биржам для маржинальных позиций

            $tp->constrain('max_abs', 'Position extremal limit', $extreme_amount,  $this->OnRiskAlert(...))
               ->constrain('max_abs', 'Position configured limit', $ctx->max_pos, $this->OnPositionOverflow(...) );

            $ctx->get_var('amount')                   // всегда ≥ 0
                ->constrain('max_abs',  'Amount limit',  $ctx->max_amount(...));  

            // ограничитель для amount - предел стоимости заявки
            $cost_limit =  $this->ConfigValue('max_order_cost', 5000);            

            if (!isset($this->high_liq_pairs[$pair_id])) 
                $cost_limit = min($cost_limit, NON_LIQUID_ORDER_COST); 
            

            if (isset($engine->ne_assets[$pair_id]))   // ситуация когда API вернула ошибку о недостатке средств для торговли этим активом на бирже
                $cost_limit = $this->ConfigValue('max_lazy_order_cost', 500);

            $cost_limit = min ($cost_limit, $ti->cost_limit);

            $ctx->get_var('cost_limit')
                ->constrain('min', 'Ticker cost limit', $ti->cost_limit)
                ->set($cost_limit, 'initial');


        }

        public function CalcTargetPos(array $rec, TradingContext &$ctx, ValueTracer $stats): bool { // пересчет с коэффициентами и смещением
            $minute = date('i');
            $pair_id = $ctx->pair_id;
            $pair = $this->FindPair($ctx->pair_id);
            $ignored = &$this->ignored_ctr;      
            $config = $this->configuration;            
            $engine = $this->Engine();
            $btc_price = $engine->get_btc_price();                                     
            $ctx->reset();
            $ctx->ext_sig_id = 0;  

            $account_src = 0;
            if (isset($rec['src_account']))
                $account_src = $rec['src_account'];
            
            $close_coef = 1.0;  // if position better to terminate     
                    
            $ts_check = $rec['ts_checked'];        
            $age_sec = time() - strtotime_ms($ts_check) / 1000;        
            $stats->best_age = min($ctx->best_age, $age_sec);
            $stats->best_ts =  max($ctx->best_ts, $ts_check);      

            if ($age_sec > POSITION_HALF_DELAY) {
                $close_coef = 1 + floor(POSITION_HALF_DELAY / 3600) * 0.1;  // halving position in 10 hours
                $close_coef = min(1000, $close_coef);
            }
            if (0 == $minute % 10 && $age_sec > (POSITION_HALF_DELAY / 4) && isset($wsrc[$account_src])) {
                $this->LogMsg("~C91#WARN:~C00 detected outdate position record for pair $pair, checked = $ts_check, account = $account_src, age = %.0f sec", $age_sec);         
            }

            $raw_pos = floatval($rec['value']) / $close_coef; // рассчетная целевая позиция, полученная с мастер-сервера       
            $ctx->raw_pos = $raw_pos;
            $this->target_pos[$pair_id]['age_sec'] = $age_sec; // replace value

            $tinfo = $engine->TickerInfo($pair_id);        
            $price = $ctx->price;
            if (0 == $price)  return false;

            // TODO: here is very ugly code, need change to elegant. И вообще этот костыль лучше убрать, после отладки всех приводов
            $is_btc_pair = (false !== strpos($pair, 'BTC', -3)) || $tinfo->is_btc_pair;
            if (str_in($tinfo->quote_currency, 'XBT') || str_in($tinfo->quote_currency, 'BTC'))
                $is_btc_pair = true;

            if (!$tinfo->is_btc_pair && $is_btc_pair)   {
                $this->LogError("~C91#ERROR:~C00 ticker info %s signals is not BTC pair, but detected as BTC pair", strval($tinfo));
                $tinfo->is_btc_pair = true;
            }

            // $usd = $engine->AmountToQty($pair_id, 1, $btc_price, 1); // 1 USD in BTC
            // FILLUSD = 10.05 amount, round to 10USD?
            $engine->SetLastError('');
            $ctx->target_pos_qty = $engine->ScaleQty($pair_id, $raw_pos); // WARN: trading volume calculation!            
            $pcoef = $this->configuration->position_coef;    
            
            if ( abs($ctx->target_pos_qty) > abs($raw_pos) && $tinfo->last_price > 0.1)
                $this->LogMsg("~C91 #WARN:~C00 position positive rescaled for %s, raw_pos = %s, result = %s, pos coef = %.5f, ticker = %s", 
                                    $pair, $tinfo->FormatQty($raw_pos), $tinfo->FormatQty($ctx->target_pos_qty, Y), $pcoef, $tinfo); 

            
            if (abs($ctx->target_pos_qty) > $ctx->max_pos_qty) {
                $this->LogMsg("~C91 #WARN:~C00 target position for %s limited from %9f to %9f by max_pos_cost", $pair, $ctx->target_pos_qty, $ctx->max_pos_qty);
                $ctx->target_pos_qty = signval($ctx->target_pos_qty) * $ctx->max_pos_qty;
            }
            
            $target_qty = $ctx->target_pos_qty;     // qty
            
            if ($is_btc_pair) {
            }   
            else   
                $target_qty = floor_qty($ctx->target_pos_qty, $price, $ctx->min_cost);   // remove minor decimals

            $pcoef = '';
            $engine->SetLastError('');
            $batch_id = false;
            $batch = null;
            $sig_feed = $this->signal_feed;          
            
            $batch_price = $price; // or find last batch
            $batch_btc_price = $btc_price; // this value reqired for fixed c2a conversions
            $avg_price = false;

            if ($tinfo->is_quanto) {
                $avg_price = $this->last_batch_price($pair_id); // load averaged price                   
                if (is_float($avg_price) && $avg_price > 0) 
                    $batch_price = $avg_price;      

                $last_btc_price = $this->last_batch_btc_price($pair_id);
                if ($last_btc_price > 0) 
                    $batch_btc_price = $last_btc_price;
            }  

            $engine->SetLastError('');

            // WARN: here is possible magic! Conversion position to exchange quantity!
            $saldo_pos = $engine->NativeAmount($pair_id, $target_qty, $batch_price, $batch_btc_price);
            $ctx->update('target_pos', $saldo_pos, 'calc target pos');   

            // проверка сигналов требующих собственной коррекции
            $ext_sig = $sig_feed->GetUnfilled($pair_id, $ctx->trade_dir_sign() > 0,  $config->min_order_cost);
            $ctx->ext_sig = $ext_sig;
            if ($ext_sig) {        
                // not process ext_sig batches here, due target_pos can be virtual        
            }
            else 
                $batch_id = $engine->ActiveBatch($pair_id, true); // checking if already in work                
            

            if (strlen($engine->last_error) > 1) {
                $this->LogError("~C91#ERROR:~C00 for %s qty to amount conversion failed scaled_tgt = %f (%f), pos = %f, sig_price = %f, last_err: %s",  
                        $pair, $ctx->target_pos_qty, $target_qty, $ctx->target_pos, $batch_price, $engine->last_error);
                $ctx->update('target_pos', 0, 'failed pos convert');          
            }
            
            if (isset($tinfo->pos_mult)) {  // #TODO: remove bitmex debug info
                $pcoef = 'x'. $tinfo->pos_mult;
                if ($tinfo->pos_mult > 1)
                    $this->LogMsg("#DBG: scaling $raw_pos => {$ctx->target_pos_qty} => $target_qty => $ctx->target_pos, using $pcoef, sig_price = $batch_price, last_err: %s", $engine->last_error);
            }   
            
            if (abs($ctx->target_pos) > 0.1 && $close_coef < 1.0)
                $this->LogMsg("~C91 #WARN:~C00 target position divided by %.3f close_coef, due outdate", $close_coef);


            $ctx->src_pos = $ctx->target_pos;  // @non-adjusted    
            $offset = 0;
            if (isset($this->offset_pos[$pair_id])) {
                $offset = doubleval($this->offset_pos[$pair_id]);
                if (null == $sig_feed[$pair_id])
                    $ctx->update('target_pos', $ctx->target_pos + $offset, 'apply offset'); // alternate apply
            }
            
            $rounded = $tinfo->FormatAmount($ctx->target_pos, Y);
            $ctx->update('target_pos', $rounded, 'format target_pos');

            $batch = $engine->GetOrdersBatch($batch_id); 
            // обработка незаконченной пачки заявок с предыдущего цикла
            if ($batch && $batch->active && $batch->parent == 0) {  
                $batch->UpdateExecStats();
                $sig_cost = $tinfo->last_price * $batch->TargetLeft(false);
                $sig_cost = abs($sig_cost);
                $bypass = $batch->active && $sig_cost >= $ctx->min_cost;
                $elps = $batch->Elapsed();
                if ($batch->IsTimedout() || $batch->IsHang()) { // TODO: use config value, add price breaking
                    $this->LogMsg("~C91 #BATCH_LAG:~C00 %s; hang = %d, elapsed %.0f sec, can't edit/upgrade... ", strval($batch), $batch->hang++, $elps);                        
                    $bypass = false;                            
                }         
                // $dist = abs($tinfo->last_price - $batch->price);
                if (signval($batch->target_pos) == signval($ctx->target_pos) && $bypass)  {
                    if (abs($batch->target_pos) < abs($ctx->target_pos)) {
                        $pa = $batch->PendingAmount(); 
                        $this->LogMsg("~C94#BATCH_BLOCK:~C00 can't upgrade batch %s, target position < suggested %s (%s), pending amount %s, left cost = %2G",   
                                $batch, $tinfo->FormatAmount($ctx->target_pos, Y), 
                                $tinfo->FormatQty($ctx->target_qty, Y),
                                $tinfo->FormatAmount($pa, Y), $sig_cost);
                        return ($pa == 0); // если ничего не выполняется, можно добавлять заявки
                    } else {
                        $this->LogMsg("~C96#BATCH_SHRINK:~C00  update batch %s, target position %f => suggested %f, ", $batch, $batch->target_pos, $ctx->target_qty);
                        $batch->EditTarget($ctx->target_pos);
                    }
                }

                if ($bypass) {
                    $active_tgt = $batch->TargetPos();                    
                    if (abs($ctx->target_pos) > abs($active_tgt) && $sig_cost  >= $ctx->min_cost) {
                        $this->LogMsg("~C91 #WARN:~C00 target position for %s limited from %9f to %9f by active batch %s", $pair, $ctx->target_pos, $active_tgt, $batch);
                        $ctx->update('target_pos', $active_tgt, 'shrink by active batch');
                    }
                    $this->LogMsg("~C92#BATCH_PASS:~C00 %s", strval($batch));
                    $ctx->ext_sig_id = $batch->parent;
                }
                else
                {
                    $batch->Update();
                    if (0 == $batch->lock) {
                        $this->LogMsg("~C95 #BATCH_CLOSE(CalcTargetPos):~C00 removing active batch, due have no orders, sig_cost = %.1f, progress = %.0f%%  ", $sig_cost, $batch->Progress());
                        $curr_pos = $engine->CurrentPos($pair_id);
                        // if (abs($curr_pos) < abs($batch->target_pos))
                        if ($batch->exec_amount > 0)
                            $batch->EditTarget($curr_pos, true); // обрезать на том, что исполнилось                      
                        $batch->Close('due no orders');                                                          
                        $ctx->batch(null);
                    }                        
                    else 
                        return false; // пока заблочено
                }        

            } elseif($batch && !$batch->active) 
                $this->LogMsg("~C91 #WARN:~C00 batch %s is not active, but selected", $batch, $ctx->target_pos);               
            
            $ctx->is_btc_pair  = $is_btc_pair;
            $ctx->close_coef = $close_coef;            
            $ctx->sig_price  = $batch_price;      
            $ctx->pos_offset = $offset;        
            $ctx->target_qty = $target_qty;             
            $ctx->batch ($batch);               
            $ctx->age_sec    = $age_sec;                    
            return ($price > 0);
        }


        protected function ExtSigVerify(TradingContext &$ctx) {
            // приоритетом является заполнение всех внешних сигналов заявками нейтрализующими дельту позиции
            $ext_sig = $ctx->ext_sig;
            $curr_pos = $ctx->curr_pos;        
            $price = $ctx->price;
            $btc_price = $ctx->btc_price;
            $pair_id = $ctx->pair_id;
            $engine = $this->Engine();
            $minute = date('i');
            $tinfo = $engine->TickerInfo($pair_id);
            $pair = $tinfo->pair;
            $config = $this->configuration;
            $sig_feed = $this->signal_feed;            

            $offset = isset($sig_feed[$pair_id]) ? 0 : $ctx->pos_offset;  // смещение отключается, если в наличии OffsetSignal (используется фид)
            $limit_pos =  $ctx->max_pos + abs($offset); // offset = -0.2, current = -0.26, limit = 0.13
            $allow_rising = true;
            $bias = $ctx->bias; // initial saldo(!) abs(diff)
            $balanced = $bias <= $ctx->min_amount; // общая позиция сбалансирована, дельта нулевая или незначительная
            $trade_dir = $ctx->trade_dir_sign();

            $ctx->alt_bias = 0; // not assigned by default

            $sig_ctx = new TradingContext($tinfo, $ctx->curr_pos); // альтернативный контекст, по данным сигнала
            
            $this->SetupLimits($sig_ctx, true); // zero cross allowed for relative positions
            
            // obsolete: проверка выхода за общий лимит позиции
            if (0 != $minute % 5 && $trade_dir == signval($curr_pos) &&                   
                abs($curr_pos) > abs($limit_pos) && abs($limit_pos) > 0) {
                $this->LogMsg("~C91 #WARN:~C00 abs current position %s now is over limit %s, rising externals batches will be not processed", 
                        $tinfo->FormatAmount($curr_pos, Y, Y), $tinfo->FormatAmount($limit_pos, Y, Y));
                $allow_rising = false;       
            }  //*/          
            
            if (is_null($ext_sig) || !is_object($ext_sig)) {
                if ($ctx->verbosity > 2)
                    $this->LogMsg("~C93 #DBG:~C00 for pair %s not unfilled external signals with order cost <= %.2f", $pair, $config->min_order_cost);
            }   
            elseif ( $minute > 1 && $allow_rising && ($ctx->ext_sig_id == $ext_sig->id || !$ctx->batch))
            while (true) {
                if ($ctx->ext_sig_id != $ext_sig->id) { 
                    $ctx->batch (null);  // prevent reusing                               
                }
                $sig_ctx->name = 'sig#'.$ext_sig->id;
                $engine->last_scale = '';  
                
                $po = $ext_sig->PendingOrders();
                if (count($po) > 0)  {
                    $dump = [];
                    $pa = 0;
                    $ff = 0;              
                    foreach ($po as $oinfo) {
                        $dump []= strval($oinfo);
                        $pa += $oinfo->Pending();
                        $ff |= $oinfo->flags;
                    }   
                    $this->LogMsg("~C07~C94#SIGNAL_WAIT:~C00 %s have pending amount %s, flags 0x%02x in: %s", 
                            strval($ext_sig), $tinfo->FormatAmount($pa, Y, Y), $ff, json_encode($dump));
                    $ctx->update('target_pos', $curr_pos, 'signal have pending orders');                    
                    return false;
                }
                
                $cp_raw = $ext_sig->CurrentDeltaPos(false);
                $cp_cost = $tinfo->QtyCost($cp_raw);
                $cp = $ctx->cur_delta = $ext_sig->CurrentDeltaPos();   
                $tp = $ctx->tgt_delta = $ext_sig->TargetDeltaPos();                           

                $pa = $ext_sig->PendingAmount();
                $delta = $tp - $cp;                
                $sig_pos = $curr_pos + $delta;
                $sig_pos =  $sig_ctx->update('target_pos', $sig_pos, 'assign signal delta position');  // изменение позиции по дельте сигнала, с автоматическими ограничениями
                $sig_bias = $sig_ctx->bias;       // ограниченная дельта 
                $sig_dir  = $sig_ctx->trade_dir_sign();                
                $delta    = $sig_dir * $sig_bias;

                $bias_qty = $engine->AmountToQty($pair_id, $price, $btc_price, $sig_bias);           
                $bias_cost = $bias_qty * $price;

                if ($sig_bias < $tinfo->lot_size || $bias_cost < $ctx->min_cost) {
                    $this->LogMsg("~C94 #SIGNAL_SMALL:~C00 %s have small bias %f with cost %.6f, ignoring ...", strval($ext_sig), $sig_bias, $bias_cost);
                    $ctx->batch (null);
                    $ctx->ext_sig = null;  
                    break;
                }

                // TODO: нужно выводить предупреждения или даже ошибки, если лимитируется огромный объем/позиция. Эпизодически репортить, если позиция близка к предельной

                // потенциально редкий случай, когда сальдо-дельта достаточно большая для создания заявки, а сигнал направлен в другую сторону
                if ($trade_dir == -signval($delta) && $bias > $ctx->min_amount) {
                    $this->LogMsg("~C94 #SIGNAL_SKIP:~C00 %s have opposite to tradeable delta bias %s, small signal bias %s %f cost %.6f, ignoring...", 
                                    strval($ext_sig), td2s($trade_dir), td2s($delta), $bias, $bias_cost);
                    $orders = $ext_sig->PendingOrders();
                    $cnt  = count($orders);
                    if ($pa > abs($bias) && $cnt > 0) {
                        $this->LogMsg("~C91#WARN:~C00 %s have pending excess orders %d with amount %s, canceling...", strval($ext_sig), $cnt, $pa);
                        $engine->CancelOrders($orders);
                    }    
                    $ctx->batch(null);
                    $ctx->ext_sig = null;  
                    break;
                }           
                
                $ucount = $sig_feed->unfilled_map[$pair_id] - 1;
                if ($tinfo->is_btc_pair)
                    $bias_cost *= $btc_price;
                // WARN: hardcore target position upgrade, probable bugs
                $virt_pos = $sig_pos;                        
                $virt_qty = $engine->AmountToQty($pair_id, $price, $btc_price, $virt_pos - $offset);                    
                $pos_cost = abs($virt_qty * $price);
            
                if ($sig_bias > 0 && $bias_cost >= $ctx->min_cost) {
                        $this->LogMsg("~C04~C97#SIGNAL_SEL:~C00 in progress %s, %10s => %10s, pending %10s in %d orders, bias(A%s:Q%.5f) cost = %.2f, in queue %d", strval($ext_sig), 
                                    $tinfo->FormatAmount($cp, Y, Y), 
                                    $tinfo->FormatAmount($tp, Y, Y),
                                    $tinfo->FormatAmount($pa, Y, Y), 
                                    $ext_sig->ActualOrders(), 
                                    $tinfo->FormatAmount($sig_bias, Y, Y), $bias_qty, 
                                    $bias_cost, $ucount); 
                        $scale = $engine->last_scale;                            
                        $la_scal = $ext_sig->LocalAmount(true, false, true);             
                        $la_raw = $ext_sig->LocalAmount(true, true, true);    
                        $ext_sig->min_bias = $ctx->min_amount; // послужит для отмены избыточных заявок    
                        // $la_scal = $tinfo->RoundAmount($la_scal);              
                        $this->LogMsg("~C97#SIGNAL_DBG:~C00 target pos cost = %7.2f/%7.2f, real pos %10s => %10s, local amount = %10f:%10f, open_coef = %.6f, last_scale: $scale", 
                                    $pos_cost, $ctx->max_pos_cost, 
                                    $tinfo->FormatAmount($curr_pos, Y, Y), 
                                    $tinfo->FormatAmount($virt_pos, Y, Y),   
                                    $la_scal, $la_raw, $ext_sig->open_coef);
                    }                                   
                    
                    // all conditions OK, amount enough after limits
                    if ($sig_ctx->amount >= $sig_ctx->min_amount) {                        
                        $ctx->update('target_pos', $virt_pos, 'assigned from signal #'.$ext_sig->id);
                        $ctx->target_pos_qty = $ctx->target_qty = $virt_qty;     
                        $ctx->split_trade &= ($ctx->target_pos == 0);  // сброс флага, если позиция не закроется
                        $this->LogMsg("~C93 #BIAS_DBG:~C00 assumed from signal %s = %s, virtual pos = %s", strval($ext_sig), 
                                    $tinfo->FormatAmount($ctx->bias, Y, Y), $tinfo->FormatAmount($virt_pos, Y, Y));
                    }                    
                    
                    if ($pa > 0)  // исполнение в процессе - можно попробовать вернуть сигнал 
                        $ctx->batch ($ext_sig->LastBatch());
                    if (is_nan($ext_sig->recalc_price))   
                        throw new Exception("Invalid recalc_price for batch ".$ext_sig->id);
                    break;   
                }
            else 
                $ctx->ext_sig = null; 

            return true;
        }

        public function  Trade() {
            // make new orders for achieve target positions
            // $order_bias = [];

            $engine = $this->Engine();      
            $config = $this->configuration;
            // $exch = $engine->exchange;  
            if (!$this->TradingAllowed()) {
                $this->LogMsg("~C91 #WARN:~C00 trading not allowed ");
                return;
            }   
            if ($this->trade_skip > 0) {
                $this->LogMsg("~C91 #WARN:~C00 trading skip counter = %d, decreasing... ", $this->trade_skip);
                $this->trade_skip --;
                return;
            }      

            if (!is_array($this->target_pos)) {
                var_dump($this->target_pos);
                throw new Exception("Invalid this->target_pos, must be array!");
            }

            $btc_price = $this->BitcoinPrice();
            if ($btc_price < 10000) {
                $this->LogError("~C91#ERROR:~C00 invalid BTC price = %.2f, or unexpected bear  market. Check candles/ticker history! Skip trading", $btc_price);
                return;
            }       

            $new_orders = 0;
            $total_pairs = count(array_keys($this->pairs_map));        
            $have_pairs = 0;
            $total_pos  = 0;
            $this->ignored_ctr    = [0, 0, 0, 0, 0, 0, 0];
            $ignored = &$this->ignored_ctr;
            $ignored_map = [];
            

            $stats = new ValueTracer();
            $stats->best_age = 86400;
            $stats->close_coef = 1;        
            
            $minute = date('i') * 1;      
            if (0 == $minute % 10) 
                $this->auth_errors = [];

            $outdated = 0;
            // $pos_coef =  $this->configuration->position_coef;
            $uptime = ( time_ms() - $this->start_ts ) / 1000;

            $start_ts = date(SQL_TIMESTAMP, $this->start_ts / 1000);
            // $this->LogMsg("~C93#CONFIG:~C00 position coef = %5f", $pos_coef);

            $ts = date(SQL_TIMESTAMP);
            $query = "INSERT INTO `bot__activity`(ts,ts_start,applicant,account_id)\n VALUES";
            $query .= "('$ts', '$start_ts', '{$this->impl_name}', {$engine->account_id})\n";
            $query .= "ON DUPLICATE KEY UPDATE ts = '$ts', ts_start = '$start_ts', uptime = $uptime, funds_usage = {$this->used_funds};";
            $this->mysqli->try_query($query);
            
            $stats->best_ts = date('Y-m-d 00:00:00');      
            $trd_pos = $this->ScanForTrade();
            

            $this->LogMsg('~C04~C95 <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<< TRADING <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<< ~C00');
            $this->SetIndent("\t");      
            $debug_pair = $this->GetDebugPair();
            $ml = $engine->GetOrdersList('matched');

            $zero_cross_allowed = $this->ConfigValue('allow_zero_cross', false); // some exchanges need this

            // PROCESSING: testing all positions
            // TODO: extract single position trade to function -----------------------------------------------------------------------------------------------------------------
            foreach ($trd_pos as $pair_id => $rec)
            try {
                set_time_limit(60);
                $ex_pos = null;
                $tinfo = $engine->TickerInfo($pair_id);
                if ($tinfo->last_price <= 0) {
                    $this->LogMsg("~C31#WARN:~C00 not set ticker price for %s, skip trading", strval($tinfo));
                    continue;
                }

                $ex_pos = $this->current_pos[$pair_id];   // exist position          
                $used_api_pos = $ex_pos->time_chk >= $ex_pos->inc_chg;
                $curr_pos = $used_api_pos  ? $ex_pos->amount : $ex_pos->incremental; //                

                $ctx = new TradingContext($tinfo, $curr_pos);
                $ctx->verbosity = ($pair_id == $debug_pair) ? 3 : 1;                       
                $prefix = $ctx->verbosity > 1 ? "~C07~C93" : '~C04';
                if ($pair_id > 1 && false === strpos($this->logger->last_msg, '__________'))
                    $this->LogMsg("$prefix ___________________________________________________________________________________________________________________~C00"); // debugging only
                $warns = [];  
                $total_pos ++;
                $have_pairs ++;        
                xdebug_start_trace("/tmp/trd_trace_{$tinfo->pair}.xt", XDEBUG_TRACE_COMPUTERIZED);
                
                $lot_size = $tinfo->lot_size;
                $pair = $tinfo->pair;                            

                // контроль костыля препятствующего избыточным заявкам отсюда
                if (isset($this->last_pending[$pair_id])) {
                    $info = $this->last_pending[$pair_id];
                    $pending_map = $engine->PendingOrders($pair_id);
                    if ($info->IsFixed() || !isset($pending_map[$info->id]))
                        unset($this->last_pending[$pair_id]);
                }


                $this->SetupLimits($ctx, $zero_cross_allowed); // TODO:

                // Высчитывание сальдо позиции по всем сигналам, включая смещение администратора
                if (!$this->CalcTargetPos($rec, $ctx, $stats)) {
                    $this->LogMsg("~C94#SKIP_TARGET_POS:~C00 %s @ %s", $pair, $tinfo->FormatPrice());
                    continue;
                }   
                $price = $ctx->price;   

                $price_coef = $tinfo->is_btc_pair ? $btc_price : 1.0;  // ценовой коэфициент регулирует стоимость в основной валюте. Используется для совместимости с разными парами, в основном к битку
                if ($price <= 0)  {
                    $this->LogError("~C91#ERROR(CalcTargetPos):~C00 invalid price for %s, using context %s, skip trading", strval($tinfo), strval($ctx));
                    continue;
                }

                $cost = 0;
                $raw_price = $ref_price  = $price;        

                if (!is_array($ex_pos->errors)) {
                    $this->LogMsg("~C91#WARN:~C00 invalid ex_pos->errors for %s, must be array but now is %s, reset to empty. Class: %s", $pair, get_debug_type($ex_pos->errors), get_class($ex_pos));
                    $ex_pos->errors = [];        
                }

                if (count($ex_pos->errors ?? []) > 10 && $minute % 5 > 0) continue; // bugs or need trader attention

                
                if (!$this->ExtSigVerify($ctx)) // внешний сигнал первым должен модифицировать целевую позу, если ему не хватает для заполнения дельты
                    $ctx->ignore_trade(IGNORE_BUSY_TRADE, "external signal busy");  
                
                if ($ctx->verbosity > 1)
                    $this->LogMsg("~C93 #DBG_BIAS:~C00 active %s, amount = %s", 
                                    $tinfo->FormatAmount($ctx->bias, Y, Y),
                                    $tinfo->FormatAmount($ctx->amount, Y, Y));
                
                $ext_sig = $ctx->ext_sig;

                $pos = $ctx->target_pos;  // desired position
                $close_cost = abs($curr_pos) * $price;           
                $amount = $ctx->amount;
                # $amount = floor($amount / $lot_size) * $lot_size; // need round to minimal value                
                if ($ctx->trade_ready() && $this->TradingNow($pair_id, $ctx))  // проверка наличие активного исполнения
                    $ctx->ignore_trade(IGNORE_BUSY_TRADE, "Pair trading now in progress");      

                if ($ctx->verbosity > 1)
                    $ctx->dump_limits();

                $pending = $ctx->pending;                
                $price = $tinfo->FormatPrice($price);       
        
                $qty = $ctx->qty;
                if ($amount > 0 && 0.0 == $qty)
                    $this->LogMsg("~C31#WARN_FLOOR:~C00 calculated qty = 0 for amount = %.8f, price = %.8f, btc_price = %.2f, pair %s", 
                                $amount, $raw_price, $btc_price, $pair);

                $cost = $ctx->cost; // 1-st calc, may in BTC!
                $cost_limit = $ctx->cost_limit;                

                $rising = signval($curr_pos) == $ctx->trade_dir_sign() || ($curr_pos == 0) && ($ctx->bias > 0); // long rise long pos
                // $price = $ctx->bias > 0 ? $tinfo->ask_price : $tinfo->bid_price;

                if (abs($pos) > 1e10) {
                    $this->LogError("~C91#CRITICAL:~C00 position for $pair $pair_id is too huge, need manual check!");
                    continue;
                }
                
                if (0 == $price) {
                    $this->LogError("~C91#CRITICAL:~C00 price for $ctag $pair~C00 unavailable, may be ticker invalid!");
                    continue;
                }   

                $curr_char = '$';
                $ctag = '~C92'; // lime;
                if ($ctx->is_btc_pair) {
                    $curr_char = '₿';
                    $ctag = '~C96'; // cyan
                }  
            
                $tag = '~C97#TRADE_POS';        
                if (0.0 === $ctx->amount || $engine->IsAmountSmall($pair_id, $amount, $price) && !$ctx->split_trade) 
                    $ctx->ignore_trade(IGNORE_SMALL_BIAS, "small amount {$ctx->amount} for target {$ctx->target_pos}, split = ".strval($ctx->split_trade));

                // #SPLIT: prevent margin trade error, need two trades: close, open, TODO: remove after testing zero_cross limiter
                if (signval($pos) == -signval($curr_pos) && abs($curr_pos) > $tinfo->lot_size && $close_cost >= $ctx->min_cost) {
                    if ($ctx->verbosity > 1)
                        $this->LogMsg("~C93#SPLIT_TRADE:~C00 %s position change from %s to %s, need split trade into close and open", 
                                    $pair,
                                    $tinfo->FormatAmount($curr_pos, Y, Y),
                                    $tinfo->FormatAmount($pos, Y, Y));
                    $pos = 0;           
                    $amount = min($amount, abs($curr_pos)); // shrink
                    $rising = false;
                    $ctx->split_trade = true;
                    $tag = '~C94#CLOSE_POS~C00';
                    if ($ctx->close_coef > 1.01)
                        $tag = '~C91#CLOSE_POS_EMG~C00';
                }   
                // $amount = $tinfo->FormatAmount($amount);


                $s_pos = $tinfo->FormatAmount($pos, Y, Y);
                $s_cpos = $tinfo->FormatAmount($curr_pos, Y, Y);

                // not amount, but quantity expected (in real coins, not contracts)
                $s_fpos = $tinfo->FormatQty($ctx->target_pos_qty, Y);   
                $s_ffpos = $tinfo->FormatQty($ctx->target_qty, Y);
                
                $mm = $engine->MarketMaker($pair_id);             
                if ($mm) {            
                    $cost_limit = min($cost_limit, $mm->max_exec_cost / 5); // 20% of max_exec_cost for initial order
                    if ($cost_limit < 1)
                        $this->LogMM("~C91#WARN:~C00 MM %s probable have invalid max_exec_cost = %.2f,  for %s", strval($mm), $cost_limit, $pair);
                    $pending = $mm->GetExec()->Count();
                    $batch = $mm->ActiveBatch();            
                    if ($pending > 0 || $batch && $batch->lock) {
                        $ctx->ignore_trade(IGNORE_BUSY_TRADE, "external signal busy, have $pending active orders");
                        $accel = 1;
                        if ($cost > 1000)  $accel = 5;
                        if ($batch) 
                            $batch->urgency = max($batch->urgency, $accel);

                        $this->LogMsg("~C93#BLOCK_MM_EXEC:~C00 MM %s have active %d orders, skip trade for %s, current bias = %5G, cost = %.2f, accel = %d ", 
                                        strval($mm), $pending, $pair, $ctx->bias, $cost, $accel);  
                    }

                }  
                // TODO: проверить избыточность фильтра: Дополнительное ограничение по стоимости, на стадии начала исполнения. 
                $cost_limit /= $price_coef;
                $la = $engine->LimitAmountByCost($pair_id, $amount, $cost_limit, true);
                if ($la < $amount) {
                    $qty = $engine->AmountToQty($pair_id,$raw_price, $btc_price, $la);
                    $cost = $price * $qty;   // 2-nd calc                
                    $cost_usd = round($cost, 2);
                    $cost_info = format_color("cost = $%.2f", $cost_usd);
                    if ($price_coef != 1) {
                        $cost_usd = round($cost * $price_coef, 2);  // предыдущее значение скорее всего округлилось до нуля
                        $cost_info = format_color("cost = %f ($%.2f)", $cost, $cost_usd);
                    }   
                    else
                        $cost = $cost_usd;           

                    $this->LogMsg("  ~C94#COST_LIMIT:~C00 amount reduced from %s to %s, price_coef = %f, qty = %s $cost_info",
                                $tinfo->FormatAmount($amount, Y, Y), $tinfo->FormatAmount($la, Y, Y),  
                                $price_coef,  $tinfo->FormatQty($qty, Y));
                    $amount = $la;          
                }         
                else {
                    $cost = $ctx->cost;
                    $cost_usd = round($cost * $price_coef, 2);
                }  

                if ($cost < $ctx->min_cost && !$ctx->split_trade || 0 == $cost) {  // CHECK: cost and min_cost must be in same currency (USD, BTC)
                    $ctx->ignore_trade(IGNORE_SMALL_BIAS, "small amount {$ctx->amount} or qty {$qty} for target_pos {$ctx->target_pos}: cost = $cost < min_cost {$ctx->min_cost}");
                    if ($ctx->min_cost > 0.1 && $ctx->is_btc_pair) // very huge min cost!
                        $this->LogMsg("~C94#IGNORE_DBG:~C00 $ctag $pair~C00 order cost~C95 %.3f~C00 $curr_char < minimal~C95 {$ctx->min_cost} $curr_char ({$tinfo->min_cost}USD)~C00, order amount =~C95 {$amount}~C00, btc_price =~C95 $btc_price ~C00", $cost);
                }


                $batch = $ctx->batch ();
                if ($batch && $ctx->trade_ready()) {
                    if ($batch->pair_id != $pair_id) {
                        $this->LogError("~C91#CRITICAL:~C00 active batch %s have invalid pair_id, processing now %d", strval($batch),  $pair_id);              
                        continue;
                    }
                    $pending = $batch->PendingAmount();
                    $left = $batch->TargetLeft(); // negiative if short open or long close
                    if (($batch->lock || $pending > 0)) {
                        $pamount = $tinfo->FormatAmount($pending, Y, Y);
                        $this->LogMsg("~C94#NEW_ORDER_BLOCK:~C00 active batch %s have pending amount %s or incomplete lock %d", 
                                        strval($batch), $pamount, $batch->lock);
                        $ctx->ignore_trade(IGNORE_BUSY_TRADE, "batch lock, have $pending amount pending");
                    }  
                    
                    if (abs($left) > $tinfo->lot_size && abs($left) < $amount && $rising && $ctx->trade_ready())  {
                        $this->LogMsg("~C93#LIMIT_AMOUNT:~C00 active rising batch %s have left amount %s, reduce %s amount order to", 
                                strval($batch), $tinfo->FormatAmount($left, Y, Y), $tinfo->FormatAmount($amount, Y, Y));
                        $amount = abs($left);
                    }
                }

                $raw_amount = $amount;

                $fills_count = is_object($ext_sig) && $ext_sig->id > 0 ? $ext_sig->ActualOrders() : 0;

                if ($fills_count > 30) {
                    $amount = $ext_sig->AdjustCloseAmount($ctx->bias > 0, $amount); // может уменьшить заявку, чтобы закрывать входящие в сигнал контр-заявки
                    if ($amount < $raw_amount)
                        $this->LogMsg("~C93#EXT_SIG_ADJUST:~C00 external signal %s have high number of orders, so adjusted amount to %s from %s", 
                                    strval($ext_sig), $tinfo->FormatAmount($amount, Y, Y), $tinfo->FormatAmount($raw_amount, Y, Y));
                }
                    
                $amount = $tinfo->FormatAmount($amount);    
                // ограничение слишком малых заявок, кроме случая полного закрытия остатка
                if ($ctx->target_pos != 0 && $amount < $lot_size || 0 == $lot_size)
                    $ctx->ignore_trade(IGNORE_SMALL_BIAS, "small amount $amount after all limits, target_pos {$ctx->target_pos}, lot_size $lot_size");

                $ignore_var = -1;
                if (count($ctx->ignore_by) > 0) {
                    $ignore_var = $ctx->ignore_by[0][0];
                }

                // проверки в основном закончены, можно выводить информацию 
                if (0 == $ignore_var)
                    $tag = '~C96#WAIT_POS'; 
                elseif (1 == $ignore_var)  
                    $tag = '~C93#SYNC_POS';
                elseif ($ignore_var > 0)
                    $tag = '~C94#SKIP_POS';
                elseif ($tinfo->is_quanto)  
                    $this->LogMsg("~C96#OPT_COST($pair):~C00 sig_price =~C95 {$ctx->sig_price}~C00, order cost =~C95 $cost~C00 (amount), order amount =~C95 {$amount}~C00 (contracts), target =~C95 $s_ffpos~C00 ");

                $suffix = '';
                if ($ctx->close_coef > 1)
                    $suffix .= sprintf(', close_coef = %.2f ', $ctx->close_coef);
                // ============================= SYNC INFORMATION ROW ==========================


                $psrc = $used_api_pos ? '~C97API~C00' : '~C93INC~C00';
                $msg = false;
                if ($pos != $ctx->target_pos_qty) 
                    $msg = sprintf(": Pair $ctag %10s~C00 target pos =~C95 %10s~C00~C93 (%10s => %10s)~C00, from current $psrc =~C95 %10s~C00, offset = ~C95 %7.2f~C00, bias =~C95 %10s~C00, pending = ~C95 %5s~C00, min_cost = {$ctx->min_cost}, ignore_var = %d".$suffix, 
                                    $pair, $s_pos, $s_fpos, $s_ffpos, $s_cpos, $ctx->pos_offset, $tinfo->FormatAmount($ctx->bias, Y, Y), $tinfo->FormatAmount($pending, Y, Y), $ignore_var);          
                
                elseif ($pos != 0 || 0 == $minute % 10)  // closed positions show not every cycle
                    $msg = sprintf(": Pair $ctag %10s~C00 target pos =~C95 %10s~C00, current $psrc =~C95 %10s~C00, offset = ~C95 %7.2f~C00, bias =~C95 %10G~C00, min_cost = {$ctx->min_cost}, raw = %10s ignore_var = %d".$suffix, 
                                    $pair, $s_pos, $s_cpos, $ctx->pos_offset, $ctx->bias, $tinfo->FormatQty($ctx->raw_pos), $ignore_var);


                $tag .=  $ext_sig ? '@SIGNAL' : '@SALDO ';
                if ($msg)    
                    $this->LogMsg("~C01$tag~C00$msg");
                elseif ($ignore_var < 0)  
                    $this->LogMsg("~C91 #WARN:~C00 trading allowed, but description not generated ");

                if ($ctx->verbosity > 1)
                    $this->LogMsg("~C93 #DBG_BIAS_TRACE:~C00  %s", $ctx->dump_changes('bias')); 

                if ($ignore_var >= 0) {
                    $ignored[$ignore_var] ++;
                    $ignored_map[$pair] = $ignore_var;
                    if ($ctx->verbosity > 1)
                        $this->LogMsg("~C93 #DBG_IGNORE:~C00 reasons list: %s ", json_encode($ctx->ignore_by));
                    continue; // на этом этапе ясно, что позиция достигнута, или этому мешают другие обстоятельства
                }

                $target_cost = $tinfo->QtyCost(abs($ctx->target_qty));

                $this->SyncPositions($pair_id, $rec);
                if (!$this->TradingAllowed())  {
                    continue;
                }        

                $hidden = $cost_usd >= $this->ConfigValue('hidden_cost_threshold', 500); 
                $ts_pos = date_ms(SQL_TIMESTAMP);
                if (isset($rec['ts']))
                    $ts_pos = $rec['ts'];
        
                
                if (floatval($amount) < $lot_size) {
                    $this->LogError("~C91#ERROR:~C00 PostFilter check: pair %10s amount [%s:%f] < lot_size %8G, bias = %f, need manual check", $pair, $amount, $raw_amount, $lot_size, $ctx->bias);
                    continue;
                }        
                        // $price = $tinfo->FormatPrice($price); //  sprintf("%.{$tinfo->price_precision}f", $price);
                $new_batch = null;        
                if (null === $ctx->batch() || $batch && !$batch->active) {
                    $parent_id = $ext_sig ? $ext_sig->id : 0;
                    $proto = ['pair_id' => $pair_id, 'src_pos' => $ctx->src_pos, 'curr_pos' => $curr_pos, 'target' => $pos, 'ts_pos' => $ts_pos, 'price' => $ref_price, 'btc_price' => $btc_price, 'flags' => 0];
                    $batch = $engine->NewBatch($proto, $parent_id); // allocate in DB or extend last one        
                    $ctx->batch ($batch);
                    $new_batch = true;
                }    

                $batch = $ctx->batch();
                if (!$batch)
                    throw new ErrorException("Failed create/load batch object");

                foreach ($warns as $wmsg)    
                    $this->LogOrder("\t\t\t$wmsg");                
                
                $sig_elps = $batch->Elapsed();
                if ($batch->IsTimedout()) 
                    $this->LogError("~C91#ERROR:~C00 Batch #{$batch->id} %s alive more than 30 minutes = %.1fM, must close it!", strval($batch), $sig_elps / 60);
                
                $batch->active = true;                
                $batch->flags &= ~BATCH_CLOSED;  // TODO: reopen, but is not correct
                $batch->hang = 0;
                $batch->btc_price = $btc_price; //  required for quanto contracts <=> amount conversions
                $batch->EditTarget($pos);
                if ($new_batch && $ext_sig)
                    $batch->urgency += $ext_sig->exec_prio;

                $opt_rec = $this->exec_opt->Process ($batch, $pos, 0);
                $ctx->bias = signval($ctx->bias) * $amount;  // коррекция после потенциального пересчета
                if (is_array($opt_rec)) {
                    $price = $opt_rec['price'];
                    $this->LogMsg("~C96#EXEC_OPT(NewOrder):~C00 postion %f => %f, result: %s ", $curr_pos, $pos, json_encode($opt_rec));
                }
                if ($mm) {
                    $res = $mm->AddBatch($batch, false, true);
                    if (!$res)
                        $this->LogError("~C91#ERROR(NewOrder):~C00 MM %s failed add batch %s and set is active", strval($mm), strval($batch));
                }

                $curr_pos_qty = $engine->AmountToQty($pair_id, $price, $btc_price, $curr_pos);

                $curr_pos_qty = ($curr_pos_qty != $curr_pos) ? '(' . $tinfo->FormatQty($curr_pos_qty, Y) . ')' : '';

                $params = ['buy' => $ctx->is_buy(), 'account_id' => $engine->account_id, 'batch_id' => $batch->id, 'price' => $price,
                           'amount' => $amount, 'qty' => $tinfo->FormatQty($qty), 'rising' => $rising, 'hidden' => $hidden];
                $params['in_position'] = $tinfo->FormatAmount($curr_pos);
                $params['init_price'] = $tinfo->last_price; // default, not accurate!
                $params['ttl'] = $this->order_timeout; // за это время заявка должна быть сдвинута


                $params['comment'] = sprintf('corr. pos %s %s=> %s', 
                                        $tinfo->FormatAmount($curr_pos, Y, Y),  $curr_pos_qty, 
                                                $tinfo->FormatAmount($curr_pos + $ctx->bias, Y, Y));
                if ($ext_sig) {
                    $ext_sig->AddBatch($batch->id);
                    $batch->parent = $ext_sig->id;
                    $params['signal_id'] = $ext_sig->id;
                    $params['init_price'] = floatval($ext_sig->recalc_price);                
                    $tdp = $ext_sig->TargetDeltaPos();
                    $params['comment'] = "sig:{$ext_sig->id}, coef:{$ext_sig->open_coef}, TDP:".$tinfo->FormatAmount($tdp, Y, Y) ;  
                }
                $params['comment'] .= ", TSP:$s_pos"; // total saldo position
                $bs = $ctx->bias > 0 ? '~C42~C90 BUY ~C00' : '~C41~C97 SELL ~C00';

                $cost_info = format_color("cost = $%.2f", $cost_usd);
                if ($price_coef != 1)           
                    $cost_info = format_color("cost = %f ($%.2f)", $cost, $cost_usd);        

                $this->LogOrder("~C95#NEW_ORDER_DBG:~C00\t  $bs Using target for %d:%s context: %s, \n\t\t\t amount = [$amount], $cost_info, price_coef = %.2f, params = %s",
                                $pair_id, $pair, strval($ctx), $price_coef, json_encode($params));
            
                $hist = $ml->GetHistory($pair_id, 3600, 's_matched');
                $pos_hist = [];
                $pos_last = $curr_pos;         
                foreach ($hist as $matched) {
                    $pos_hist []= floatval($tinfo->FormatAmount($pos_last));
                    $pos_last -= $matched; 
                }
                xdebug_stop_trace();

                $pos_hist = array_slice($pos_hist, 0, 20);
                if ($ctx->verbosity > 2)
                    $this->LogMsg("~C93#DBG:~C00 %s position history %s", $pair,  json_encode($pos_hist));
                $info = $engine->NewOrder($tinfo, $params); // <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<< PLACE NEW ORDER <<<<<<<<<<<<<<<<<<<<<<<<<
                if (!is_object($info)) {
                    // throw new Exception('NewOrder failed create ObjectInfo instance');          
                    $emsg = sprintf("%s order post failed, NewOrder(%s) returned [%s], pair = $pair, min_cost = {$ctx->min_cost},\n error = %s", 
                            $this->impl_name, json_encode($params), var_export($info, true), $engine->last_error);
                    $this->send_event('ALERT', $emsg, $engine->last_error_code);
                    $this->LogError("~C91#TROUBLE:~C00 %s", '~C41'.$emsg);
                    $this->postpone_orders[$pair_id] = 5;
                    continue;
                }
                $info->pair = $pair;
                $info->created = $info->updated;
                $batch->last_order = $info->id;

                if (0 == $info->matched) {
                    $info->avg_price = $info->price;
                    if (0 == $info->init_price) {              
                        $ref = $ext_sig ? $tinfo->FormatPrice($ext_sig->recalc_price) : 'none';
                        $this->LogMsg("~C91#WARN:~C00 init_price not set for %s, source %s", strval($info), $ref);
                        $info->init_price = $price; // не назначено, но почему? 
                    }
                } 

                $real_pos = '';
                $pos_qty = $engine->AmountToQty($pair_id, $price, $batch->btc_price, $pos);
                if ($pos_qty != $pos) {
                    $real_pos = sprintf('%9f', $pos_qty); //
                    if (strpos($real_pos, '.') !== false)
                        $real_pos = rtrim($real_pos, '0');          
                    $real_pos = "($real_pos)";
                }    

                $descr = '';
                if ($ctx->close_coef > 1)
                    $descr .= sprintf(', CLOSE_COEF = %.1f!', $ctx->close_coef);
                elseif ($ctx->age_sec >= 960) 
                    $descr = ", age_pos={$ctx->age_sec}";

                $notify = "position $curr_pos => $pos $real_pos $descr";
                if ($ext_sig) {
                    $notify = sprintf("ext_sig {$ext_sig->id} %s => %s ", $ctx->cur_delta, $ctx->tgt_delta);           
                    $info->flags |= OFLAG_DIRECT; // прямо из сигнала, не дельта-позиции по инструменту в целом
                    $res = $ext_sig->AddOrder($info, '.Trade');          
                    $this->LogMsg("~C07#SIGNAL:~C00 batch %s, order %s, init_price = %s late registration = %s", 
                                strval($ext_sig), strval($info), $tinfo->FormatPrice($info->init_price),  $res ? 'Yep' : 'Nope');
                }   

                $this->last_pending[$pair_id] = $info;
                $engine->DispatchOrder($info, 'DispatchOrder/Trade-post');
                if ($mm && $mm->enabled) {          
                    $fixed = $info->IsFixed();
                    if (!$fixed) {
                        // исполненные заявки передавать бесполезно, не приживутся
                        $this->LogMsg("~C97#BYPASS_MM:~C00 active order %s", strval($info));  
                        $mm->Register($info); // передача опеки заявкой маркетмейкеру, он их может размножить в след. цикле
                    }  
                    $this->LogMsg("~C97#BYPASS_MM:~C00 batch %s", strval($batch));
                    $mm->AddBatch($batch, !$fixed, true); // выборка активного сигнала!                        
                }

                $batch->Save();  
                $info->OnUpdate();

                $good_status = ['new', 'active', 'filled', 'partially_filled', 'partiallyfilled'];
                $stp = array_search($info->status, $good_status);
                
                if (false !== $stp) {
                    if ($cost >= REPORTABLE_ORDER_COST)
                        $this->NotifyOrderPosted($info, $notify);        
                    else 
                        $this->NotifySmallOrderReg($info);
                }  
                else 
                    $this->send_event('ALERT', sprintf("#BAD_ST: %s order post failed %s, cost = $cost, status = %s, error = %s", 
                                    $this->impl_name, strval($info), $info->status, $engine->last_error));        

                $new_orders ++;
            } 
            catch (Throwable $E) {
                $this->LogException($E, "In loop trade position $pair_id failed");
                if (null !== $ex_pos)
                    $ex_pos->errors []= "#EXCEPTION: ".get_class($E).": ".$E->getMessage();
            } // foreach positions ============================================================================================================================================================================================================

            $this->SetIndent("");        
            $this->LogMsg("~C94==============================================================================================================================~C00");
            $this->LogMsg("~C34~C47 #TRADE(total): ~C00 new_orders = %d, have_pairs/positions/total = %d/%d/$total_pairs, ignored_map = %s", $new_orders, $have_pairs, $total_pos, 
                                json_encode($ignored_map));
            if ($minute % 10 == 0)
                $this->NotifySmallOrders();
            if ($new_orders > 0)  
                $engine->LoadPositions(); // некоторые заявки могли исполнится сходу

            if ($stats->best_age > POSITION_HALF_DELAY / 4)  {        
                if (0 == date('i') % 15) { 
                    send_event('WARN', "ALL positions from feed are outdated! Last ts_checked = $stats->best_ts ", $stats->best_age);
                    $pos_info = tss()." #DUMP: ";
                    $pos_info .= print_r($this->target_pos, true);
                    $this->LogMsg($pos_info);
                    file_add_contents(__DIR__.'/logs/outdated_pos.log', $pos_info);
                }
                else
                    $this->LogError("~C91 #WARN:~C00 best position age = %d seconds ", $stats->best_age);
            }  
            if ($outdated > 2) {
                $msg = "For {$this->impl_name} outdated positions $outdated / $total_pos, best age = ".gmdate('H:i:s', $stats->best_age);
                $this->send_event('WARN', $msg, $outdated);
            }
        } // function Trade


        public function TradingAllowed() {
            return $this->trade_enabled && $this->active && 'master' == $this->active_role;
        }

        protected function TradingNow(int $pair_id, ValueTracer $ctx): bool {
            // контроль не исполнившихся пока заявок:
            $ext_sig = $ctx->ext_sig;
            $engine = $this->trade_engine;      
            $tinfo = $engine->TickerInfo($pair_id);
            $pair = $tinfo->pair;

            $batch = $engine->ActiveBatch($pair_id, true);
            if (is_object($batch) && $batch->lock)
                return true;

            $pending = $engine->PendingAmount($pair_id, false); // костыль №25, проверка абсолютного объема на исполнение

            if ($ext_sig)
                $pending += $ext_sig->PendingAmount();
            
            $ctx->pending = $pending;  

            if ($pending > 0) {
                $this->LogMsg("~C94#NEW_ORDER_BLOCK:~C00 engine/signal have pending amount %s for %s", $tinfo->FormatAmount($pending, Y, Y), $pair);
                return true;
            }
            $mm = $engine->MarketMaker($pair_id);   
            if ($mm && $pending = $mm->PendingAmount()) {
                $this->LogMsg("~C94#NEW_ORDER_BLOCK:~C00 MM %s have pending amount %s, active batch %s",
                        strval($mm), $tinfo->FormatAmount($pending, Y, Y), strval ($mm->ActiveBatch()));
                return true; 
            }

            if (isset($this->last_pending[$pair_id]) && $this->last_pending[$pair_id] instanceof OrderInfo ) { // по хорошему, эта проверка уже проходить не должна...        
                $info = $this->last_pending[$pair_id];
                $list = $info->GetList();
                $orders = $engine->PendingOrders($pair_id);
                $count = count($orders);
                if ($count == 0 || !isset($orders[$info->id])) {
                    $this->LogMsg("~C31#WARN:~C00 delta/active have orphaned pending order %s, owner = %s ", strval($info), strval($list));                    
                    return false;
                }
                $this->LogMsg("~C91#LAST_ORDER_BLOCK:~C00 delta/active have pending order %s, count orders = %d", strval($info), $count);
                return true;
            }     
            return false;      
        }

    } // trait TradingLoop