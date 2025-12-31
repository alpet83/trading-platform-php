<?php

    include_once "lib/common.php";
    include_once 'orders_lib.php';
    include_once 'trading_core.php';

    /*   Концепция внешнего сигнала: элемент изменяющий целевую позицию по торговой паре. По сути это программируемая дельта позиции, которая может быть в активном или ожидающем состоянии.
    При выставлении в таком сигнале лимитной цены, сигнал подразумевается полуактивным, и заявка его исполняющая должна быть соответственно активной (выполняться под управлением ММ) если цена торгов достаточно близка. Аналогичную 
    заявку механизм ММ должен выставлять и удерживать при приближении к цене тейка.  
    Сигнал может быть активным безусловно, просто по факту присутствия в списке сигналов. 
    * Более сложной механикой является бесконечный стоп-триггер, по которому сигнал начинает частично открываться начиная с определенного уровня цены. Такой триггер может служить источником неограниченного распила - заявки будут порождаться
    * всякий раз при заметном изменении коэффициента открытия сигнала. 

    Условием закрытия сигнала может быть достижение целевой (тейк) цены, или удаление его на сервере (инвалидация), после чего его дельта-позиции считается нулевой. 

    Старая схема предполагала рассчет полной целевой позиции в скрипте sig_edit.php, с выполнением раз в минуту. В цикле обрабатывались все сигналы и происходило простое сальдирование базовой позиции. Боты получали позиции уже сведенные в таблицу hype_last, посредством скрипта lastpos.php

    ИДЕИ:
        * связывать сигналы внешние и внутренние, через поле parent в таблице внутренних сигналов. Это позволит каждому внешнему сигналу привязать так-же заявки, которые для него создавались
    */

    define('SIG_FLAG_TP', 0x001);
    define('SIG_FLAG_SL', 0x002);
    define('SIG_FLAG_LP', 0x004);
    define('SIG_FLAG_SE', 0x010);   // eternal stop
    define('SIG_FLAG_GRID', 0x100);

    define('MAX_OFFSET_SIG', 222);  // limit to pairs

    function pair_base(string $pair) {
        $pos = strpos($pair, 'USD') or strpos($pair, 'BTC');
        if ($pos !== false && $pos > 0) 
            return substr($pair, 0, $pos);
        return '??';
    }

    function find_by_matched(array &$orders, float $m): ?OrderInfo {
        foreach ($orders as $i => $oinfo) {
            if ($oinfo->matched * $oinfo->TradeSign() == $m) {
                array_splice($orders,  $i,1);
                return $oinfo;
            }
        }
        return null;
    }

    /** 
     * Подбор группы заявок с общим нулевым сальдо, если таковые имеются
     * @param array $orders - список ордеров, объектов класса OrderInfo 
     * @return ?array
     * */
    function scan_zero_saldo(array &$orders): ?array {
        $core = active_bot();
        $engine = $core->Engine();
        $result = [];        
        if (count($orders) < 2) return null;
        // сортировка по убыванию значения matched
        usort($orders, function($a, $b) { 
            $am = is_object($a) ? $a->matched : 0;
            $bm = is_object($b) ? $b->matched : 0;
            if ($am == $bm) return 0;
            return ($am > $bm) ? -1 : 1;
        });

        $i = random_int(0, count($orders) / 2);   // крупные заявки в начале, не всегда найдется комплект закрывающих
        $first = $orders[$i];
        $saldo = $first->matched * $first->TradeSign();
        $result = [$first];
        $rest = [];
        $vml = [$saldo];
        array_splice($orders, $i,1); // удалить первый элемент из общего списка
        $min_cost = $core->ConfigValue('min_order_cost', 100);

        // задача - собрать группу с общим нулевым сальдо
        // В процессе сборки пытаться найти закрывающие сальдо заявки, аналогично максимального размера.
        // Статистически сальдо может разворачиваться много раз
        foreach ($orders as $oinfo) {
            $sign = $oinfo->TradeSign();
            if (signval($saldo) == $sign) {
                $rest []= $oinfo;
                $oinfo = find_by_matched($orders, -$saldo);
                if (!$oinfo) continue;
            }
            $vm = $oinfo->matched * $sign;
            $vml []= $vm;
            $saldo += $vm;
            $result []= $oinfo;
            $qty = $engine->AmountToQty($oinfo->pair_id, $oinfo->price, $engine->get_btc_price($oinfo->ts), abs($saldo));
            $cost = $qty * $oinfo->price;
            if ($cost < $min_cost) { 
                $orders = $rest;  // успех нужно закрепить, пропущенные заявки оставить
                return $result;                
            }
        }
        $core->LogMsg("~C95#CLEANUP:~C00 for %s vector has saldo %.3f, matched values %s, rest %d orders",
                                strval($oinfo), $saldo, implode(',', $vml), count($orders));
        return null;
    }

    function dbg_array_push( &$dest, $value)  {
        if (is_null($value)) return;
        $count = count($dest);
        if (is_array($dest))
        $dest []= $value;
        else
        $dest[$count] = $value;

        $upd = count($dest);
        if ($upd < $count + 1) 
            active_bot()->LogError("~C91 #ERROR:~C00 dbg_array_push %s failed increase result count $count => %d", 
                                        var_export($value, true), $upd);
    }

    class ExternalSignal {
        // свойство mult по сути является базовым количеством внешнего сигнала, и не может быть меньше единицы 
        protected $raw = ['id' => 0, 'setup' => 0, 'account_id' => -1, 'buy' => 1,  'pair_id' => 0, 'ts' => '', 'ts_checked' => '2025-01-01 00:00', 
                          'limit_price' => 0, 'recalc_price' => 0, 'stop_loss' => 0, 'take_profit' => 0, 'take_order' => -1, 'limit_order' => -1,
                          'amount' => 0, 'mult' => 1, 'ttl' => 10, 'flags' => 0, 'open_coef' => 1, 'qty' => 0, 
                          'active' => 1, 'closed' => 0, 'exec_prio' => 0, 'comment' => ''];

        protected $engine = null;
        protected $core = null;

        protected $ticker_info = null;

        protected $owner = null;

        protected $min_clean = 10; // минимальное количество заявок, при котором разрешается уборка

        protected $clean_amount = 0; // минимальное количество дельты, позволяющее коллапс заявок разной направленности

        // recalc_price цена, на момент изменения целевой позиции (для оценки проскальзывания)

        public  $gen_orders = null; // список ID заполняется ММ, при выполнении алгоритма достижения позиции
        public  $gen_batches = null; // список ID внутренних сигналов, которые были созданы по этому внешнему
        public  $tp_order_info = null; // for take profit
        public  $limit_info = null; // limit order 

        public  $last_source = 'new';
        public  $last_cdp = 0;  // native value
        public  $last_matched = null;  // последняя исполненная
    

        public  $min_bias = 0; // минимальное отклонение от целевой позиции, при котором сигнал требует выполнения

        public  $postpone = 0; // счетчик, до обнуления которого сигнал будет откладываться от исполнения


        protected $req_flags = OFLAG_DIRECT; // требуемые флаги для заявок
        protected $last_changed = 0;
        public    $last_error = '';

        protected $base_price = 0;  // цена для расчетов, обычно последняя по инструменту
        protected $btc_price = 0; // цена для расчетов контрактов

        protected $updates = 0;
        

        public function __construct(SignalFeed $owner) {       
            $this->owner = $owner;
            $this->core = $owner->core;
            $this->engine = $owner->engine;        
            $this->account_id = $this->engine->account_id;
            $this->gen_orders = []; // new ArrayTracer();
            $this->gen_batches = []; // new ArrayTracer();
            $this->ts = date(SQL_TIMESTAMP);
        }

        public function __destruct() {       
            $count = count($this->gen_orders); 
            $cl = get_class($this);
            if ($count > 50)
                log_cmsg("~C91#WARN($cl.Destruct):~C00 signal %s has %d gen_orders", strval($this), $count);
        }

        public function __get ( $key ) {
            if (array_key_exists($key, $this->raw)) {
                if (in_array($key, ['take_profit', 'stop_loss'])) 
                    return $this->CalcRelative($key);
                return $this->raw[$key];
            }    

            if (!property_exists($this, $key))
                $this->core->LogError("~C91#ERROR:~C00 for signal %d trying get unknown property %s, but available %s", 
                                    $this->id, $key, json_encode(array_keys($this->raw)));                                          
            return null;
        }

        public function __isset ( $key ) {
            return isset ($this->raw[$key]);
        }
  
        public function __set ($key, $value) {
            /*
            $order_field = false !== strpos($key, '_order');      
            $price_field = 'limit_price' == $key || 'stop_loss' == $key || 'take_profit' == $key;
            if (($order_field || $price_field)  && $this->raw[$key] != $value) {
                $cl = get_class($this);
                $this->core->LogMsg("~C92#DBG($cl.Set):~C00 signal %s->$key changed from %f to %f, trace:~C00", 
                    strval($this), $this->raw[$key], $value);
                $this->core->LogMsg(format_backtrace());
            } // */
            if (is_numeric($value )) $value *= 1;
            $this->raw[$key] = $value;        
        }

        public function __toString() {
            $side = $this->buy ? 'BUY ' : 'SELL';
            if (!$this->engine) return sprintf("ID:%d, pair_id=%d, $side uninitialized now", $this->id, $this->pair_id);
            $this->ticker_info = $ti = $this->engine->TickerInfo($this->pair_id);        
            $res = '';
            if ($ti) 
                $res = sprintf("ID:%d @%s, active:%d, %s %s Δ amount:%s, coef:%.5f", $this->id, $this->account_id, intval($this->active), $side, $ti->pair, 
                                $ti->FormatAmount($this->amount, Y, Y), $this->open_coef);                                               
            else
                $res = sprintf("ID:%d @%s, %s %s Δ amount = %10G", $this->id, $side, $this->account_id, $this->pair_id, $this->amount);        
            if ($this->flags & SIG_FLAG_SL && $this->stop_loss > 0) $res .= ", stop_loss:{$this->stop_loss}";
            if ($this->flags & SIG_FLAG_LP && $this->limit_order > 0) $res .= ", limit_order: {$this->limit_order}";
            if ($this->flags & SIG_FLAG_TP && $this->take_order > 0) $res .= ", take_order: {$this->take_order}";
            if ($this->closed)
                $res .= ',~C31 CLOSED!~C00';
            return $res;   
        }    

        public function ActualOrders(bool $ret_count = true) { // подразумеваются исполненные и ожидающие исполнения        
            $res = [];                

            foreach ($this->gen_orders as $id)  {
                $oinfo = $this->owner->FindOrder($id, $this->pair_id, true);                       
                if (is_null($oinfo) || $oinfo->IsCanceled() || $oinfo->IsOutbound())            
                    $this->ExcludeOrder($id, is_null($oinfo) ? 'lost/invalid-id' : strval($oinfo)); 
                else
                    $res[$id] = $oinfo;             
                }  
            if ($ret_count) 
                return count($res);
            return $res;       
        }

        public function AddBatch(int $id, string $ctx = '') { // добавление пачки заявок
            if ('construct' == $ctx) return; // преодоление рекурсии, при отработки конструктора пачки

            if (array_search($id, $this->gen_batches) === false) {       
                $batch = $this->engine->GetOrdersBatch($id, true, true);
                if ($batch === null || $batch->IsClosed()) return false;    
                dbg_array_push($this->gen_batches, $id);
                $cl = get_class($this);
                $this->core->LogMsg("~C94#PERF($cl.AddBatch):~C00 batch %s added to signal %s, total = %d %s", strval($batch), strval($this), count($this->gen_batches), $ctx);
                return true;
            }   
            return true; // already exists
        }
        
        public function AdjustCloseAmount(bool $buy, float $amount): float {
            $prev = 0;
            $orders = $this->ActualOrders(false);
            foreach ($orders as $oinfo) {
                if ($oinfo->buy == $buy) continue; // нужны только встречные заявки, которые открывали смещение
                $m = $oinfo->matched;
                if ($prev + $m > $amount) continue; // перебор
                $prev += $m; // добор четко закрывающего объема
            }            
            return $prev > $amount * 0.5 ? $prev : $amount; // слишком большое сокращение объема не нужно
        }

        public function AddOrder(mixed $order, string $ctx = ''): bool {                
            if (is_object($order)) {
                if ($order->IsFixed() && 0 == $order->matched) return false; // не добавлять отмененные
                if ($order->flags & OFLAG_RESTORED) return false;
                $elps = $order->Elapsed('updated') / 1000;
                $very_aged = 3600 * 24 * 30; // через месяц заявки будут заменяться, постепенно. Это в том числе нужно, чтобы из БД подгружался свежачок
                if ($order->IsOutbound() || $elps > $very_aged) return false; 

                if ($order->signal_id > 0 && $order->signal_id != $this->id) { // разрешать только адаптацию, чужие сигналы не вписывать
                    $this->core->LogError("~C91#ERROR(AddOrder):~C00 order %s signal_id %d mismatch for signal %s, stack:\n %s", 
                                    strval($order), $order->signal_id, strval($this), format_backtrace());                        
                    return false;
                }

                if (strpos($ctx, 'Trade') !== false) {
                    $bias = $this->Bias();
                    $side_need = $bias >= 0 ? 'buy' : 'sell';                    
                    if ($side_need != $order->Side()) { 
                        $this->core->LogError("~C91#WARN:~C00 trading algo attempt register order with wrong side %s for %s, due bias %f, context: %s", 
                                                $order->Side(), strval($this), $bias, $ctx);
                        // throw new Exception("Invalid order side for external signal");
                    }
                }   
                if ($this->take_order == $order->id) 
                    $this->tp_order_info = $order;                 
                if ($this->limit_order == $order->id)   
                    $this->limit_info = $order;
                $order = $order->id;           
            }  else
            if (is_numeric($order)) {           
                $oinfo = $this->owner->FindOrder($order, $this->pair_id, true);
                if (is_null($oinfo)) {             
                    $this->core->LogMsg("~C91#WARN:~C00 attempt to add invalid order %d to signal %s %s from:\n %s", $order, strval($this), $ctx, format_backtrace());
                    return false;
                }
            }          

            if ($order <= 0) return false;
            if (array_search($order, $this->gen_orders) === false) {
                dbg_array_push($this->gen_orders, $order);   
                if ($this->core->uptime > 30000)       
                    $this->core->LogMsg("~C94#PERF(ExternalSignal.AddOrder):~C00 order #%d added to extr signal %s, total = %d %s", $order, strval($this), count($this->gen_orders), $ctx);
                return true;
            }   
            return false;
        }   
        

        public function AverageEntryPrice() {
            $orders = $this->ActualOrders(false);
            $engine = $this->engine;
            $saldo_qty = 0;
            $total_vol = 0;
            foreach ($orders as $oinfo)
                if (is_object($oinfo)) {
                    // TODO: нужны тесты с разным набором заявок, в том числе с закрывающими позицию 
                    if (0 == $oinfo->matched || $this->buy != $oinfo->buy) continue;
                    $btc_price = $engine->get_btc_price($oinfo->ts);
                    $qty = $engine->AmountToQty($this->pair_id, $oinfo->avg_price, $btc_price,  $oinfo->matched);
                    $total_vol += $qty * $oinfo->avg_price;
                    $saldo_qty += $qty;
                }
            if ($saldo_qty > 0) 
                return $total_vol / $saldo_qty;
            return 0;
        }  

        public function Bias(bool $native = true) {
            return $this->TargetDeltaPos($native) - $this->CurrentDeltaPos($native);
        }

        public function CalcRelative(string $key): float {
            if (!isset($this->raw[$key]) || !is_object($this->ticker_info)) return 0;
            $ti = $this->ticker_info;
            $base = $this->raw[$key];
            if ($base > $ti->last_price * 0.2) return $base; // non-relative
            $entry = $this->AverageEntryPrice();
            if (0 == $entry)
                $entry = $ti->last_price; // пока не было открывающих сделок, разрешить вход указав стоп ниже текущей (для лонга).

            $sign = $this->buy ? 1 : -1;
            if (str_in($key, 'stop')) 
                return $entry - $base * $sign;
            else
                return $entry + $base * $sign; // take profit
        }


        public function Cleanup (bool $force = false){
            // уборка заявок не влияющих на позицию
            $engine = $this->engine;
            $core = $this->core;
            $owner = $this->owner;
            $ti = $this->ticker_info;
            $match_map = [];
            $total = count($this->gen_orders);
            $force |= $total > 50;
            if (!$core->TradingAllowed()) 
                return "orders can't be changed, trading disabled";
            if ($this->PendingAmount() > 0 && !$force) return 'have pending';                    
            if ($total < $this->min_clean) return 'not enough orders';
            $cdp_start = $this->CurrentDeltaPos(N); 
            $bias = $cdp_start - $this->TargetDeltaPos(N);
            $bias_cost = abs($bias) * $ti->last_price;        
            if ($bias_cost > $core->ConfigValue('min_order_cost', 100) && !$force) return 'have delta';  // при активном расхождении, не трогать

            $cdp_list = $this->CurrentDeltaPos(Y, N);  // для сохранения истории         
            if (count($cdp_list) < $this->min_clean) return 'not enough orders 2';

            $this->CDP_Log(tss() ." Cleanup started with {$total} orders ...\n");
            $force_before = -1;        
            $check_list = [];        
            
            $min_qty = $ti->min_cost / $ti->last_price;      
            $min_amount = $engine->QtyToAmount($this->pair_id, $ti->last_price, $this->btc_price, $min_qty);
            $min_amount = max($min_amount, $ti->lot_size * max(1, $ti->min_notional));
            $min_amount = max($min_amount, $this->clean_amount); 
            
            $min_idx = 0;
            $min_pos = $cdp_list [array_key_first($cdp_list)];

            if (is_object($this->last_matched))
                unset($cdp_list[$this->last_matched->id]); // последняя исполненная заявка не участвует в очистке, она важна для грид-ботов

            foreach ($cdp_list as $oid => $pos) {                               
                $n = count($check_list);
                $ap = abs($pos);
                if (0 != $cdp_start) {
                    if ($oid == $this->limit_order) continue; // не трогать заявку открытия, для очистки
                }

                if ($ap < $min_pos) {
                    $min_pos = $ap;
                    $min_idx = $n;
                }          
                if ($ap <= $min_amount) // абсолютного нуля на практике можно не дождаться, издержки округлений и комиссионных съемов         
                    $force_before = $n;  // до этого индекса - все под нож
                $check_list []= $oid;   
            } 

            if (0 == count($check_list)) return 'no enough matched'; // все заявки нужные
            sort($check_list);
            $removed = [];    

            if ($force_before < $total && $force_before > 50)
                $core->LogMsg("~97#CLEANUP_BATCH:~C00 estimate %d orders can'be forgotten for %d", $force_before, $this->id);

            $dump_map = [];
            $all_orders = [];
            
            foreach ($check_list as $n => $oid) {          
                $oinfo = $owner->FindOrder($oid, $this->pair_id, true);
                $remove = true;          
                
                if ($oinfo && $remove) {  // дальше можно собирать пары, которые можно анигилировать
                    $remove = $oinfo->IsFixed() && $oinfo->matched == 0; // удалить только отмененные
                    if ($remove) {
                        $core->LogMsg("~C95#CLEANUP_CANCELLED:~C00 order %s will be detached from ext signal %s and intermediate ", strval($oinfo), strval($this));
                        continue;
                    }

                    $k = sprintf('M%.6f', $oinfo->matched);
                    // добавление заявки в карту исполнений, независимо от направления
                    if (isset($match_map[$k])) 
                        $match_map[$k] []= $oinfo;
                    else   {
                        $match_map[$k] = [$oinfo];               
                        $dump_map[$k] = [0, 0];
                    }
                    $buy = $oinfo->buy;
                    $dump_map[$k][0] += $buy ? 1 : 0;
                    $dump_map[$k][1] += $buy ? 0 : 1;
                    $all_orders []= $oinfo;
                        
                } 
                // удалять так-же безобъектные заявки
                if ($remove || $n <= $force_before) {
                    if ($oinfo) {               
                        $oinfo->flags = OFLAG_OUTBOUND; // пометить как исключенный. Больше загружаться не будет, кроме как для статистики
                        $oinfo->OnUpdate();
                        $removed [$oinfo->id] = 1;
                    }                            
                }             
            }
            if ($total >= 50) {
                file_put_contents("{$this->account_id}/sig_{$this->id}.cln", "$total = ".json_encode($dump_map, JSON_PRETTY_PRINT));
            }

            if (0 == count($match_map)) return 'no pairs';
            ksort($match_map);

            $forget = [];
            // $core->LogMsg("~C94 #SMART_CLEANUP:~C00 matching map %s ", json_encode(array_keys($match_map)));
            // дальше можно сканировать список исполненных заявок до получения нулевого сальдо, и помечать анигилировавшие. Слишком долгая жизнь сигнала приведет к сотням заявок в списке
            foreach ($match_map as $list) {
                if (count($list) < 2) continue;                    
                $buys = []; 
                $sells = [];
                foreach ($list as $oinfo) 
                    if ($oinfo->buy) $buys []= $oinfo; else $sells []= $oinfo;
                if (0 == count($buys) || 0 == count($sells)) continue; // анигилировать нечему
                $core->LogMsg("~C95#CLEANUP:~C00 for %s found orders with same matched amount %.6f, %d buys, %d sells",
                                strval($this),  $list[0]->matched, count($buys), count($sells));
                $max_count = min(count($buys), count($sells));
                // пометить заявки, чтобы они в следующий раз не участвовали в подсчете CurrentDeltaPos и вообще не загружались
                for ($i = 0; $i < $max_count; $i ++) { 
                    $forget = array_merge($forget, [$buys[$i], $sells[$i]]);
                }
            }

            if (0 == count($forget)) {
                // похоже списки заявок имеют разный объем, потому анигилировать проблематично
                while ($list = scan_zero_saldo($all_orders)) { // попытка итеративной сборки группы с общим нулевым сальдо
                    $forget = array_merge($forget, $list);
                    $vl = [];
                    foreach ($list as $oinfo) 
                        $vl []= $oinfo->matched * $oinfo->TradeSign();
                    $core->LogMsg("~C95#CLEANUP:~C00 for %s found group of %d orders with small saldo %f, matched values %s",
                                    strval($this), count($list), array_sum($vl), implode(',', $vl));                    
                    break;
                }
            }

            foreach ($forget as $oinfo) {            
                if (!is_object($oinfo)) continue;
                $core->LogMsg("~C95#CLEANUP:~C00 order %s will be detached from ext signal %s and intermediate ", strval($oinfo), strval($this));
                $oinfo->flags |= OFLAG_OUTBOUND;                        
                $oinfo->OnUpdate();
                $removed [$oinfo->id] = 1;
            }

            $this->CurrentDeltaPos(true); 
            $cdp_end = $this->CurrentDeltaPos(false);
            $diff = abs($cdp_start - $cdp_end);        

            if (1 == date('i') % 10 && $total > 25) 
                $core->LogMsg("~C94#CLEANUP_DBG:~C00 signal %s, total %d,  actual %d orders. Min pos = %f at %d, trigger level %5f ",  strval($this), $total, count($cdp_list), $min_pos, $min_idx, $min_amount);

            if ($diff > max(abs($cdp_start), abs($cdp_end)) * 0.01) 
                $core->LogMsg("~C91#CLEANUP_WARN:~C00 signal %s total forgot %d / %d orders (until %d),  currrent delat pos changed %.6f => %.6f",
                    strval($this), count($removed), $total, $force_before, $cdp_start, $cdp_end);

            if (count($removed) > 0)  {
                $line = tss(). " CLEANUP ".implode(',' , array_keys($removed))."\n";           
                $this->CDP_Log($line);
            }  
            return "#OK: removed ".count($removed);
        }
      

        public function EnabledSL(): bool {
            return ($this->flags & SIG_FLAG_SL) > 0;
        }
        public function EnabledTP(): bool {
            return ($this->flags & SIG_FLAG_TP) > 0;
        }
        public function ExcludeOrder(int $oid, string $ctx = '') {
            if (($i = array_search($oid, $this->gen_orders)) !== false) {          
                unset ($this->gen_orders[$i]);
                $this->core->LogMsg("~C95#DBG(ExcludeOrder):~C00 #$i from %s removed order %d, rest %d orders %s", strval($this), $oid, count($this->gen_orders), $ctx);
            } 
        }

        public function Finalize() {
            // before destructions any actions
        }

        public function FindOrder(int $id){
            return array_search($id, $this->gen_orders);
        }

        public function GenBatch(int $sig_id): ?OrdersBatch {        
            $engine = $this->engine;
            $i = array_search($sig_id, $this->gen_batches);
            if ($i === false) return null;
            return $engine->GetOrdersBatch($sig_id);        
        }

        public function NearestGenBatch(float $price):? OrdersBatch {        
            $best = null;
            $best_diff = $price;
            foreach ($this->gen_batches as $batch_id) {
            $batch = $this->GenBatch($batch_id);
            if (null == $batch) continue;          
            if (null == $best) 
                $best = $batch;
            else {
                $diff = abs($price - $batch->price);
                if ($diff < $best_diff) {
                    $best = $batch;
                    $best_diff = $diff;
                }   
            }
            }
            return $best;
        }


        protected function CDP_Log(string $content) {
            $cdp_file = "{$this->account_id}/sig_{$this->id}.cdp";                   
            $lines = file_exists($cdp_file) ? file($cdp_file) : [];                      
            $lines = array_slice($lines, -1000); // keep last 1000 lines before
            $lines []= $content;            
            file_put_contents($cdp_file,  implode("", $lines));            
        }

        public function CurrentDeltaPos(bool $native = true, bool $ret_saldo = true)  {
            $saldo = 0;
            $saldo_qty = 0;
            $oinfo = new OrderInfo();
            $engine = $this->engine;
            $core = $this->core;        
            $list = [];
            $ti = $this->ticker_info;
            if (is_null($ti)) 
                throw new Exception("#FATAL: Ticker info not set for  ".strval($this));            

            if ($this->flags & SIG_FLAG_LP) {          
                if ( $this->limit_order <= 0 || !$this->limit_info) {
                    $this->active = false; 
                    return $ret_saldo ? 0 : $list; // не инициализирована заявка открытия
                } 
                $info = $this->limit_info;
                if (false === array_search($info->id, $this->gen_orders)) {
                    $m = $native ? $info->matched : $engine->AmountToQty($this->pair_id, $oinfo->price, $oinfo->btc_price, $info->matched); 
                    $saldo = $m * $info->TradeSign(); // Всегда учитывается, хотя в список генеративных попасть должна по идее
                }    
            }

            $ids = array_unique($this->gen_orders);
            sort($ids);
            $owner = $this->owner;        
            $oinfo = null;
            $story = [];
            $actual = [];
            $bmap = [];            
            
            
            foreach ($ids as $n => $id) {
                $sfx = ( $n % 10 < 9) ? '' : "\n\t\t";          
                $oinfo = $owner->FindOrder($id, $this->pair_id, true); // TODO: use epsilon
                
                if (!$oinfo) {
                    $core->LogMsg("~C91#WARN(CurrentDeltaPos):~C00 order %d not found in cache - removing as rejected", $id);                          
                    $this->ExcludeOrder($id, 'lost/rejected');
                    continue;
                }                  
                
                $bs = $oinfo->buy ? 'B' : 'S';  
                if ($oinfo->IsCanceled() || $oinfo->IsOutbound() || $oinfo->flags & OFLAG_RESTORED) {
                    $story []= format_color("~C07~C09$bs%d:%-6s;$sfx", $id, '0'); // trash
                    continue;         
                }   

                if (0 == $oinfo->signal_id)  {
                    $oinfo->signal_id = $id;
                    $oinfo->flags |= OFLAG_RESTORED;
                }

                if ($oinfo->signal_id != $this->id) {
                    $core->LogError("~C91#ERROR(CurrentDeltaPos):~C00 order %s signal_id %d mismatch for signal %s - removing as lost", 
                                    strval($oinfo), $oinfo->signal_id, strval($this));                          
                    $this->ExcludeOrder($id, 'lost/signal-mismatch');
                    continue;
                }

                $fprice = $ti->FormatPrice($oinfo->price);

                $actual []= $id;
                if (0 == $oinfo->matched) {
                    $story []= format_color("~C07~C04$bs%s@%s:%-6s;$sfx", $id, $fprice, 'A'); // not-matched
                    continue;         
                }   
                $bmap [$oinfo->batch_id] = $bmap [$oinfo->batch_id] ?? 0 + 1;  
                $sm = $oinfo->matched * $oinfo->TradeSign();          
                $saldo += $sm;               
                $tag = ' ';               
                if ($this->limit_order == $id) $tag = '~C07L';
                if ($this->take_order == $id) $tag = '~C07T';          
                $story []= format_color("$tag$bs%d@%s:%8s=%-6s;$sfx", $id, $fprice,  
                                            $ti->FormatAmount($sm, Y, Y), $ti->FormatAmount($saldo, Y, Y));  
                $saldo_qty += $engine->AmountToQty($this->pair_id, $oinfo->price, $oinfo->btc_price, $sm);
                $list [$id]= $saldo; 
                
            }
            $used = count($list);

            $saldo_qty = $ti->FormatQty($saldo_qty);
            $saldo_qty = $ti->LimitMin($saldo_qty, false, true);

            if ($native && $saldo != $this->last_cdp) {          
                $this->last_cdp = $saldo;
                $this->gen_orders = $actual;
                $tdp = $this->TargetDeltaPos();  
                $text_saldo = sprintf("%8s:%-8s ~C93 %f", $saldo ,$saldo_qty, $this->open_coef);
                $append = format_color(tss()."~C07~C96 $text_saldo ~C00 = SUM %d orders(", $used);
                $append .= implode(' ', $story).") TDP = $tdp, limit = {$this->limit_order}, take = {$this->take_order}";                     
                $append .= ', batches = '.json_encode(array_keys($bmap))."\n";
                $this->CDP_Log($append);
                if ($used > 30) 
                    $core->LogError("~C91#WARN(CurrentDeltaPos):~C00 signal %s has too many (%d) non-compensated orders, with CDP = %f, TDP = %f", strval($this), $used, $saldo, $tdp);
            }

            if (!$native)
                $saldo = $ti->RoundQty($saldo_qty);
            return $ret_saldo ? $saldo : $list;
        }

        public function TargetDeltaPos(bool $native = true, bool $raw = false): float { // возвращает дельту позиции в нативном значении, при полной или частичной активации
            $res = 0;
            $engine = $this->engine;
            if ($this->limit_order > 0) {
                if (!$this->limit_info) return 0; // не инициализирована заявка открытия                   
                $info = $this->limit_info;
                $matched = $info->matched;
                $qty = $engine->AmountToQty($this->pair_id, $info->price, $this->btc_price,  $matched);
                $matched = $native ? $matched : $qty;
                $rest = $info->Pending();
                if (!$native)
                    $rest = $engine->AmountToQty($this->pair_id, $info->price, $this->btc_price, $rest);
                $res -= $rest * $info->TradeSign(); // блокировать закрытие дельты позиции, пока заявка висит не полностью исполненной  
                if (!$this->active) 
                    return $matched;  // если заявка не исполнена в достаточной степени, то это и есть целевая позиция              
            }

            if (!$this->active || $this->closed) return 0;            

            $res += $this->LocalAmount($native, $raw, true) * ($this->buy ? 1 : -1);       

            
            $oinfo = $this->tp_order_info;
            if (is_object($oinfo)) {
                $res += $oinfo->matched * $oinfo->TradeSign(); // closing position at limit price with profit
            }                   

            $ti = $this->ticker_info;

            return $ti->LimitMin($res, true, true); 
        }

        public function TryAdoption() {
            $engine = $this->engine;
            $core = $this->core;
            $ml = $engine->MixOrderList('matched', $this->pair_id);
            $ex_flags = OFLAG_OUTBOUND | OFLAG_DIRECT | OFLAG_LIMIT;
            $this->Update();
            $tdp = $this->TargetDeltaPos();
            
            krsort($ml); // начинать нужно с заявок помоложе
            $after = strtotime($this->ts);
            $aged = time() - 3600 * 24;
            $ti = $this->ticker_info;        
            foreach ($ml as $oinfo) {
            $flags = $oinfo->flags & $ex_flags;
            if (!$oinfo->IsFixed() || $flags != 0 || $oinfo->matched == 0)  continue;
            if (false !== $this->FindOrder($oinfo->id)) {
                $core->LogMsg("~C91#WARN:~C00 found orphan order %s in gen_orders", strval($oinfo));
                $oinfo->flags |= OFLAG_DIRECT;
                continue;
            }
            $t = strtotime($oinfo->created);
            if ($t < $after || $t < $aged) continue;          
            $pdiff = abs($oinfo->price - $ti->last_price);
            if ($pdiff > $oinfo->price * 0.02) continue; // экстремально отличается
            $batch_id = $oinfo->batch_id;
            $batch = null;
            if ($batch_id > 0) {
                $batch = $engine->GetOrdersBatch($batch_id, 3);
                if (is_object($batch) && $batch->parent > 0) continue; // такие сигналы нельзя трогать
            }  

            $msigned = $oinfo->matched * $oinfo->TradeSign();
            $cdp = $this->CurrentDeltaPos();
            $diff = $tdp - $cdp;

            // test: need compensate -1000, order have -100, after adoption rest changed to -900
            if (signval($diff) == signval($msigned) && abs($msigned) <= abs($diff))  {                        
                if ($this->AddOrder($oinfo)) {
                $diff -= $msigned;       

                if ($batch && 0 == $batch->parent) {
                    $this->AddBatch($batch_id, 'from TryAdoption');    // присвоение сигнала ради одной заявки... сомнительное решение
                    $batch->parent = $this->id;                   
                }                  
                elseif (!$this->GenBatch($batch_id)) {                
                    // после того как заявка будет украдена у другого сигнала, его данные станут не валидными (exec_amount) и вероятно пересчитаются
                    // покамест считается, что сигналы у которых можно забрать заявки, не слишком важны для статистики (дельта-добивка)
                    $batch = $this->NearestGenBatch($oinfo->price);
                    if ($batch) {
                        $oinfo->batch_id = $batch->id; // довольно жесткая замена, нужно в журнале отметить?                     
                    }    
                }
                
                $core->LogMsg("~C92#SIGNAL_ADOPT:~C00 signal %s adopted order %s, price diff = %s, prev batch_id = %d, rest = %.6f", strval($this), strval($oinfo), $ti->FormatPrice($pdiff), $batch_id, $diff);
                $oinfo->flags |= OFLAG_DIRECT | OFLAG_SHARED;
                $oinfo->OnUpdate();               
                }    
                else  
                $core->LogMsg("~C91#SIGNAL_ADOPT:~C00  signal %s failed adopt order %s, rest = %.6f, flags = 0x%02x", strval($this), strval($oinfo), $diff, $flags);
            }
            if (0 == $diff) break;
            }
        }

        public function LastBatch() {
            sort($this->gen_batches);
            $last = count($this->gen_batches) - 1;
            if ($last >= 0)
                return $this->gen_batches[$last]; // предполагается, что ранние сигналы просто протухли
            return false; 
        }
        public function LocalAmount(bool $native = true, bool $raw = false, bool $apply_coef = false): float {
            $engine = $this->engine;    
            $base = $this->mult;
            $price = $this->base_price;
            $ti = $this->ticker_info;
            
            if (0 == $price)  
            $price = $ti->last_price;

            if ($apply_coef)
                $base *= $this->open_coef; // лучше его применить до округления в нативные единицы
            $scaled = $engine->ScaleQty($this->pair_id, $base); // применение всех коэффициентов из настроек.            
            $qty = $raw ? $base : $scaled; // без скалирования получается обычно большее число       
            
            // TODO: need use signal prices, if available
            if ($ti && $native) {       
                $amount = $engine->NativeAmount ($this->pair_id, $qty, $price, $this->btc_price);             
                $amount = $ti->RoundAmount($amount); // округление до шага
                if ($amount != $this->amount || 0 == $this->recalc_price)  {              
                    $this->recalc_price = floatval($ti->last_price);  
                    $this->last_changed = time();
                }
                if (0 == $amount) 
                    $this->last_error = format_color("~C93#DBG(LocalAmount):~C00 qty = %f, base = %f, scaled = %f, last_scale %s ", $qty, $base, $scaled, $engine->last_scale);
                return $this->amount = $amount;
            }
            
            return $qty;    
        }

      

        public function PendingAmount (bool $native = true): float {
            $saldo = 0;
            $engine = $this->engine;
            $map = $this->PendingOrders();
            foreach ($map as $oid => $oinfo) {            
                $amount = $oinfo->Pending();
                $saldo += $native ? $amount : $engine->AmountToQty($this->pair_id, $oinfo->price, $oinfo->btc_price, $amount);
            }
            return $saldo;
        }

        public function PendingOrders(bool $skip_lp = true, bool $skip_tp = true): array {
            $res = [];
            $oinfo = null;
            $owner = $this->owner;
            if (!$this->active) return $res;

            foreach ($this->gen_orders as $oid) {
                $oinfo = $owner->FindOrder($oid, $this->pair_id, true);              
                if (!$oinfo || $oinfo->IsFixed()) continue;             
                if ($oinfo->IsLimit() && $oinfo->id == $this->limit_order && $skip_lp)  continue;
                if ($oinfo->IsLimit() && $oinfo->id == $this->take_order && $skip_tp)  continue;
                $res [$oid] = $oinfo;         
            }
            return $res;
        }

        public function Import(mixed $src): bool {
            if (is_string($src))
            $src = json_decode($src, true);
            $core = $this->core;
            $engine = $core->Engine();                  
            $acc_id = $engine->account_id;  

            if ($src['pair_id'] <= 0) {
            $this->last_error = 'Invalid pair_id in '.json_encode($src);
            return false;
            }

            $keys = array_keys($this->raw);
            foreach ($keys as $key) 
            if (is_numeric($key)) {
                $core->LogMsg("~C91#WARN:~C00 wrong field name %d for external signal", $key);
                unset($raw[$key]); 
            }    

            if (is_array($src)) {
            unset($src['pair']);
            if (!isset($src['account_id']))
                    $src['account_id'] = $acc_id;  // remote loaded signals have no account binding

            foreach ($src as $key => $value)           
                $this->$key = $value;          

            if ($this->flags & SIG_FLAG_LP) 
                $core->LogMsg("~C93#DBG:~C00 signal %s import check, source %s", strval($this), json_encode($src));
            }   
            else {
                $this->last_error = 'Invalid source data '.var_export($src, true);
                return false; 
            }           

            $this->ticker_info = $engine->TickerInfo($this->pair_id);
            if (!$this->ticker_info) {
                $core->LogError("~C91#ERROR:~C00 signal %s import failed, no ticker info", strval($this));
                $this->last_error = 'Not found ticker for '.$this->pair_id;
                return false;
            }
                
            if ($this->take_profit <= $this->stop_loss && $this->stop_loss > 0 && 
                    $this->flags & SIG_FLAG_TP && $this->flags & SIG_FLAG_SL) {
                $core->LogError("~C91#ERROR:~C00 invalid TPSL parameters for %s", strval($this));           
                $this->take_profit = 0;
            }

            

            if ($this->take_order > 0 && $oinfo = $engine->FindOrder($this->take_order, $this->pair_id, true)) {            
                $this->AddOrder($oinfo, 'Import Take Profit');            
            } 
            else
                $this->take_order = 0;  // init value -1 override

            if ($this->limit_order > 0 && $oinfo = $engine->FindOrder($this->limit_order, $this->pair_id, true)) {
                $this->AddOrder($oinfo, 'Import Limit Open');  
            } 
            else  
                $this->limit_order = 0;   

            $this->Update();
            return true; // supported    
        }


        protected function  LoadFromTable (string $orders_table) {
            $core = $this->core;
            $engine = $this->engine;
            $mysqli = $core->mysqli;                
            $acc_id = $this->engine->account_id;
            $flags_mx = OFLAG_OUTBOUND | OFLAG_DIRECT | $this->req_flags;                                    
            $flags_rq = $this->req_flags; // нужны прямые заявки, без исключенных        
            $min_ts = date(SQL_TIMESTAMP, time() - BATCH_OUTDATE); 
            $rows = $mysqli->select_rows('id,batch_id', $orders_table, "WHERE (signal_id={$this->id}) AND (account_id = $acc_id) AND (ts > '$min_ts') AND (batch_id > 0) AND (flags & $flags_mx = $flags_rq)", MYSQLI_ASSOC);              
            if (is_array($rows)) {
            $prev = count($this->gen_orders);                                             
            foreach ($rows as $row) { 
                $id = $row['id'];
                $this->AddBatch($row['batch_id'], 'from LoadFromTable');                        
                $oinfo =  $this->owner->FindOrder($id, $this->pair_id, true); 
                $this->AddOrder($oinfo ? $oinfo : $row['id'], ' via LoadFromDB');
            }   
            
            $now = count($this->gen_orders);
            if ($now > $prev)
                $core->LogMsg("~C94#PERF(ExternalSignal.LoadFromDB):~C00 for %s loading orders from table count += %d = %d", strval($this),$now - $prev, $now);                   
            }
            else {
                $core->LogMsg("~C91#WARN:~C00 no actual orders in %s for signal %s", $orders_table,  strval($this));              
            }   
        }
        public function LoadFromDB(int $id): bool {        
            $this->last_error = '';
            if ($id <= 0) {
                $this->last_error = '#ERROR: invalid id';
                return false;
            }   
            
            $engine = $this->engine;                
            $table = $engine->TableName('ext_signals');
            $core = $this->core;
            $mysqli = $core->mysqli;        
            $row = $mysqli->select_row('*', $table, "WHERE (id=$id) AND (account_id={$this->account_id})", MYSQLI_ASSOC);       
            if (is_null($row)) {
                $this->last_error = '#WARN: not exists in DB';
                return false;
            }
            if ($this->Import($row)) {
                // seems goood
                $this->last_source = sprintf('load_from_db at %s from\n %s', tss(), json_encode($row));            

            }
            else {
                $this->last_error = '#ERROR: import rejected from '.json_encode($row);
                return false;        
            }   
            if (!is_object($this->ticker_info)) {
                $this->last_error = '#ERROR: No ticker info for #'.$this->pair_id;
                return false;
            }   
        
            $this->gen_batches = [];                          
            // перебор пакетов
            $this->LoadFromTable($engine->TableName('matched_orders') ); 
            $this->LoadFromTable($engine->TableName('mm_limit') ); 

            $this->last_changed = strtotime($this->ts);
            $m_table = $engine->TableName('matched_orders');
            $prev = null;
            $info = $this->owner->signal_info[$this->id] ?? null;
            if (is_object($info) && $info->count > 0) // поиск реального изменения, которое дало исполнение заявки                
                $this->last_changed = strtotime($info->last_order_ts); //  $mysqli->select_value('ts', $m_table, "WHERE (signal_id = $id) AND (account_id = {$this->account_id}) AND (ts < '{$this->ts}') ORDER BY ts DESC");  // latest matched order
            
            $this->Update(); // выполнить пересчеты после полной перезагрузки
            return true;
        } // LoadFromDB

        public function UpdateBTCPrice() {
            $core = $this->core;            
            $changed_day = $this->last_changed > 0 ? gmdate('Y-m-d 00:00:00', $this->last_changed) : $core->session_start;
            $bp = 0;

            if ($this->last_changed > 0 && $changed_day < $core->session_start)
                $bp = $core->CalcVWAP(BITCOIN_PAIR_ID, $changed_day, 24 * 60); // using 24H avg from day before
            
            if ($bp < 15000)
                $bp = $core->BitcoinPrice();
            if ($bp != $this->btc_price) {
                $class = get_class($this);
                $core->LogMsg("~C94#DBG:~C00 for $class %s btc_price updated to $%.2f, change day %s vs session start %s ", 
                                strval($this), $bp, $changed_day, $core->session_start);
                $this->btc_price = $bp;
            } 
        }
      
        public function Update() {        
            $engine = $this->engine;
            $core = $this->core;
            $this->ticker_info = $engine->TickerInfo($this->pair_id);
            $ti = $this->ticker_info; 
            if (0 == $this->id) return;       
            if (!$ti || $this->closed) { 
                $this->SaveToDB();
                return;
            }        
            $this->base_price = $ti->mid_spread;        
            $this->UpdateBTCPrice(); // need for futures amount<->qty conversions
        
            $this->updates ++;

            $this->tp_order_info = $this->take_order > 0 ? $engine->FindOrder($this->take_order) : null;
            //  $this->lp_order_info = $this->limit_order > 0 ? $engine->FindOrder($this->limit_order) : null;        
            $prices = $this->owner->trig_prices;
            $last = isset($prices[$this->pair_id]) ? $prices[$this->pair_id] :  $ti->last_price;

            $this->active = true; // by default

            if ($this->flags & SIG_FLAG_LP) {
            $info = $this->limit_info;
            if ($this->limit_order <= 0 || !$info) 
                $this->active = false; // not ready   
            else
                $this->active = ($info->matched > $info->amount * 0.5); // хоть немножко, половиночку
            }

            // -------------------------------------------------------------------------------
            if ($this->active && $this->take_profit > 0 && $this->tp_order_info) {            
                $oinfo = $this->tp_order_info;
                if ($oinfo->matched > 0) 
                    $this->stop_loss = 0; // stop price is not actual anymore, fixing open_coef             
                if (0 == $this->limit_price)  
                    $this->closed |= $oinfo->IsFixed() && ($oinfo->matched > 0); // сигнал можно только один раз закрыть, если он без перезагрузки
            }
            // пересчет вводных для целевой дельты позиции
            $sl = $this->stop_loss;

            if ($this->active && $sl > 0 && $this->EnabledSL()) {
            $this->active = $this->buy ? ($last > $sl) : ($last < $sl);             
            $endless = boolval($this->flags & SIG_FLAG_SE);

            if ($this->active) {
                $diff_pp = 100.0 * abs($last - $sl) / $last;  // difference in %
                $limit = 3.0;
                if ($last > 5000) $limit = 4.0; // extended range for BTC & ETH             
                $prev = $this->open_coef;
                // LONG { if price - SL > limit% = full signal allow } 
                $this->open_coef = $endless ? round( min($limit, $diff_pp) / $limit, 1) : 1;  // одноразовые стопы открываются полностью, и закрываются единожды
                
                if ($this->open_coef < 1 && $this->open_coef != $prev)
                    $core->LogMsg("~C94#CALC_OPEN_COEF:~C00 pair [%10s], price = %9s, SL = %9s, diff = %9s (%.1f%%), result = %.1f", $ti->pair, $ti->FormatPrice($last), $ti->FormatPrice($sl), $ti->FormatPrice($last - $sl), $diff_pp, $this->open_coef);
            }
            else { 
                $this->open_coef = 0;
                if (!$endless) 
                    $this->closed = true; // stop loss reached, no way back
            }    
            } 
            else            
                $this->open_coef = 1;

            $this->TargetDeltaPos(); // update amount
            $count_orders = count($this->gen_orders);
            if (5 == ($this->updates % 10) || $count_orders > 40) {                
                $res = $this->Cleanup();  // не слишком часто               
                if ($count_orders >= 50)
                    $core->LogMsg("~C94#PERF(ExternalSignal.Cleanup):~C00 for %s returned [%s], rest %d", strval($this), $res, count($this->gen_orders)); // */
            }
                
            if (count($this->gen_orders) == 0 && $this->active)
                $core->LogMsg("~C94#PERF(ExternalSignal.Update:%d):~C00 updated signal %s, yet no orders", $this->updates, strval($this)); // */

            $this->SaveToDB();
        }
      
      public function SaveToDB() {
        $core = $this->core;         
        if ($this->limit_order < 0 || $this->take_order < 0 || $this->account_id <= 0) {
           $core->LogMsg("~C91#WARN:~C00 trying save not initialized signal %s", strval($this));
           return; // не сохранять неинициализированные сигналы
        }   
        // сохранение в БД измененных данных               
        $clone = $this->raw;                 
        unset($clone['pair']);
        $fields = implode(', ', array_keys($clone));
        $engine = $this->engine;
        $acc_id = $this->account_id;        
        
        $table = $engine->TableName("ext_signals");
        $mysqli = $core->mysqli;        
        $vals = $mysqli->pack_values($fields, $clone);
        $query = "INSERT IGNORE INTO `$table` ($fields)\n VALUES\n ($vals)\n";
        $active = $this->active ? 'true' : 'false';
        $closed = $this->closed ? 'true' : 'false';       
        if (!$mysqli->try_query($query)) {
           $core->LogError("~C91#FAILED(SaveToDB-1):~C00 %s source: %s", $query, json_encode($clone)); 
           return; 
        }          

        $upd_columns = 'ts_checked, amount, mult, active, closed, recalc_price, open_coef, limit_price, take_profit, stop_loss, limit_order, take_order, ttl, qty, flags, comment';
        $upd_columns = str_replace(' ', '', $upd_columns);
        $upd_columns = explode(',', $upd_columns);

        $upd_pairs = [];
        foreach ($upd_columns as $col)
            $upd_pairs []= sprintf(" %s=%s", $col, $mysqli->format_value($this->$col));
        
        $query = "UPDATE `$table` SET\n".implode(",\n", $upd_pairs);        
        
        $query .= "\n WHERE (id={$this->id}) AND (account_id = $acc_id) AND (setup = {$this->setup});";
        if (!$mysqli->try_query($query)) {
            $core->LogError("~C91#FAILED(SaveToDB-2):~C00 %s source: %s", $query, json_encode($clone));
            return;
        }    

        /*$ar = $mysqli->affected_rows;
        if ($core->GetDebugPair() == $this->pair_id && $ar > 0)
            $core->LogMsg("#DBG: using query [%s] for ext signal %s => record in DB updated", $query, strval($this)); /*/

        if ($this->limit_price > 1e6 )
           file_add_contents("{$this->account_id}/sig_{$this->id}.change.log", sprintf("%s %s %s\n", tss(), $query, $this->last_source)); 
      }  

      public function SetLastMatched(mixed $order) {
         if (is_object($order) && $order->matched > 0 || is_null($order)) {
            $this->last_matched = $order;
            return true;
         }   
         return false;
      }
 } // class ExternalSignal


 class GridSignal extends ExternalSignal {

    // активные заявки сетки, участвуют в gen_orders
    public  $grid = []; // map [id] => OrderInfo    
    public  $grid_levels = []; // map [-qty..+qty] = price
    
    public  $spread_price = 0;  // цена от которой строилась сетка в последний раз
    public  $center_price = 0;  // цена равновесия сетки, когда поровну бидов и асков
  
    public  $last_state = '';

    public  $take_level = '';  // справочный уровень,  разрешающий выставить заявку сетки, даже если цена близко


    public  $step = 0; // рассчетный шаг сетки в пунктах

    public  $mhistory = []; // история исполнившихся заявок последовательно за сессию (текстовый формат)



    public function __toString() {      
      if (!$this->engine) return sprintf("ID:%d, pair_id=%d, uninitialized now", $this->id, $this->pair_id);
      $this->ticker_info = $ti = $this->engine->TickerInfo($this->pair_id);        
      $res = '';
      if ($ti) 
         $res = sprintf("ID:%d @%s, active:%d, %s Δ mult:%f, amount:%s, levels: %d, L:%s, H:%s, cp: %s", $this->id, $this->account_id, 
                             intval($this->active), $ti->pair, $this->mult,
                             $ti->FormatAmount($this->amount, Y, Y), $this->qty, 
                             $ti->FormatPrice($this->stop_loss),
                             $ti->FormatPrice($this->take_profit),
                             $ti->FormatPrice($this->center_price));                                               
      else
         $res = sprintf("ID:%d @%s, #%s Δ amount = %10G, qty: %d", $this->id, $this->account_id, $this->pair_id, $this->amount, $this->qty);        
      return $res;   
    }    

    public function AddOrder(mixed $order, string $ctx = ''): bool {
      if (is_object($order)) {
          $order->flags |= OFLAG_GRID;
          $best = is_object($this->last_matched) ? $this->last_matched->updated : $order->created;
          if ($order->IsFixed() && $order->matched > 0 && $order->updated > $best) {              
              $this->SetLastMatched($order); // последняя исполненная
          }    
      }       

      return parent::AddOrder($order, $ctx);
    }
    public function Finalize() {
      $list = $this->grid;
      $core = $this->core;      
      $engine = $this->engine;
      $mm = $engine->MarketMaker($this->pair_id, false);      
      if (is_null($mm)) {
          $core->LogError("~C91#GRID_FINALIZE_BLOCK:~C00 no market maker for %s", strval($this));
          return;
       }            
      foreach ($list as $name => $map) 
        if (is_array($map) && count($map) > 20) { // слишком много заявок оставлять не нужно
          $core->LogMM("~C95#GRID_CLEANUP:~C00 for %s->%s cancelling up to %d orders", strval($this), $name, count($map));
          $this->engine->CancelOrders($map);
        }   
      
      $this->Report('~C95#GRID_FINALIZE:'); // отобразить только исполненные задействованные заявки
    }

    public function LoadFromDB(int $id): bool {       
       $this->req_flags = OFLAG_GRID | OFLAG_LIMIT;
       $this->mhistory = [];
       $this->last_error = 'no error';
       $res = parent::LoadFromDB($id);
       $this->active = $res;       
       if ($res && is_null($this->last_matched)) {
          $engine = $this->engine;
          $acc_id = $engine->account_id;          
          $table = $engine->TableName('matched_orders');          
          $mysqli = $engine->sqli();
          $ts_min = date(SQL_TIMESTAMP, time() - 15 * 24 * 3600); // 15 days for renew orders
          $live_t = time() - strtotime($this->ts);
          $strict = "(signal_id = {$this->id}) AND (ts > '$ts_min') AND (account_id = $acc_id) AND (flags & {$this->req_flags})";
          $id = null;          
          if ($mysqli->select_count($table, "WHERE $strict") > 0) // следующий запрос тупит, если в БД не найдется ответа
              $id = $mysqli->select_value('id', $table, "WHERE $strict ORDER BY `updated` DESC -- get last matched id"); 

          if (!is_null($id)) 
             $this->SetLastMatched($engine->FindOrder($id, $this->pair_id, true));
          elseif ($live_t > 3600 * 5)
             $this->core->LogMM("~C31#GRID_WARN:~C00 last matched order for %s not loaded with query %s, result is NULL. May no matched orders yet. Grid lived %.3f hours.",
                                  strval($this), $mysqli->last_query, $live_t / 3600.0);
       } 
       if ($res) {          
          $grid = [];
          for ($i = 0; $i < $this->qty; $i++)  
              $grid[-$i] = $grid[$i] = null; // bi-directional filling       

          $mapped = 0;              

          foreach ($this->gen_orders as $oid) {
            $oinfo = $this->owner->FindOrder($oid, $this->pair_id, true);
            if ($oinfo->IsFixed()) continue; // нельзя в список активных пихать исполненные
            $m = [];
            preg_match('/GBOT [AB]:(-*\d*)/', $oinfo->comment, $m);
            if (count($m) > 1) {                              
                $level = $m[1];                
                $grid[$level] = $oinfo; 
                $oinfo->checked = time_ms();  
                $mapped ++;  
            }
          }
          $this->grid = $grid;

          $this->Report("~C95#GRID_LOAD:~C00 mapped ~C95{$mapped}~C00 orders");
       }  
       else
          $this->core->LogMM("~C91#WARN_GRID_LOAD:~C00 failed for #$id %s: %s", strval($this), $this->last_error); // may is new

       return $res;
    }

    public function SetLastMatched(mixed $order) {
       if (is_null($order)) {
          $this->last_matched = null;
          return true;
       }
       $core = $this->core;
       $engine = $this->engine;       
       if (is_numeric($order))
           $order = $engine->FindOrder($order, $this->pair_id, true);
       if (!is_object($order)) return false;   
       if (0 == $order->matched) {
           $core->LogMM("~C91#ERROR:~C00 order %s not matched, can't set as last. Call from: %s", strval($order), format_backtrace());
           return false;
       }
       $this->core->LogMM("~C93#DBG:~C00 gridbot %s assigned last matched %s ", strval($this), strval($order)); 
       $this->last_matched = $order;
       return true;
    }

    public function Report(string $prefix) {
      if (!is_object($this->ticker_info))  return;
      $core = $this->core;
      $cdp = $this->CurrentDeltaPos();      
      $cdp = $this->ticker_info->FormatAmount($cdp, Y, Y);
      $actual = $this->ActualOrders(false);
      $ti = $this->ticker_info;
      $map = [];
      foreach ($actual as $id => $oinfo) 
        if (is_object($oinfo)) {
           if ($oinfo->matched > 0)
               $map[$id] = ($oinfo->buy ? 'B' : 'S').$ti->FormatAmount($oinfo->matched, Y, Y);
           else
               $map[$id] = ($oinfo->buy ? 'b' : 's').$ti->FormatAmount($oinfo->amount, Y, Y);    
        }    
      $core->LogMM("$prefix~C00 for %s CDP = %s, last matched [%s], actual orders %s", 
              strval($this), $cdp, strval($this->last_matched), json_encode($map));
    }
    
    public function TargetDeltaPos(bool $native = true, bool $raw = false): float {
      // фактические расчеты нужны, исходя из текущей позиции цены/спреда в сетке. Так есть возможность избежать переисполнения
      $core = $this->core;

      $pos = $this->CurrentDeltaPos($native, true);
      if (!$raw)
          return $pos;
      $ti = $this->ticker_info;

      $coef = $this->engine->ScaleQty($this->pair_id, 10000) / 10000.0;
      if ($coef > 0)      
         return $pos / $coef;
      else  
         $core->LogMM("~C91 #WARN:~C00 for grid-bot %s scale factor = %f", strval($this), $coef);
      return 0;
    }
    public function Update() {
      $engine = $this->engine;      
      $this->open_coef = 1;
      $this->min_clean = 2; // TODO: move to constructor
      $this->base_price = $this->center_price;
      $this->UpdateBTCPrice();

      if ($this->base_price > 0) {
          $this->amount = $this->LocalAmount();
          if (0 == $this->amount) 
              $this->core->LogMM($this->last_error);
      }    

      $this->clean_amount = $this->amount / 2; // убирать не полностью исполненное 
      $this->Cleanup(true);
      $this->SaveToDB();
    }
 }


 class OffsetSignal extends ExternalSignal {
    
    public function __toString() {      
        return sprintf("ID:%d, last:%.5f", $this->id, $this->amount);
    }

    public function LocalAmount(bool $native = true, bool $raw = false, bool $apply_coef = false): float{
        $core = $this->core;        
        $res = $core->offset_pos[$this->pair_id] ?? 0;        
        $this->ts_checked = date_ms(SQL_TIMESTAMP3);
        if ($res != $this->amount) {
            $this->amount = $res;
            $this->last_changed = time();
            $this->ts = date(SQL_TIMESTAMP);
        }
        if ($native || 0 == $res) return $res;
        $engine = $this->engine;
        $res = $engine->AmountToQty($this->pair_id, $this->base_price, $this->btc_price, $res);
        if (!$raw) return $res;

        $coef = $this->engine->ScaleQty($this->pair_id, 10000) / 10000.0;
        if ($coef > 0)      
            return $res / $coef;
        $core->LogError("~C91#WARN:~C00 from offset %.5f for %s scale factor = %.7f, can't calc result pos", 
                        $res, strval($this), $coef);           
        return 0;                        
    }
    public function TargetDeltaPos(bool $native = true, bool $raw = false): float {    
        return $this->LocalAmount($native, $raw);
    }
    public function Update() {        
        $this->active = true; // by default
        $this->comment = "Offset signal (local/internal)";
        $this->take_order = 0;
        $this->limit_order = 0;
        $this->open_coef = 1;     
        $this->flags = 0;   
        $engine = $this->engine;
        assert($this->id == $this->pair_id);
        if (is_null($this->ticker_info))
            $this->ticker_info = $engine->TickerInfo($this->pair_id);

        $this->closed = !is_object($this->ticker_info);
        if ($this->closed) return;

        $this->UpdateBTCPrice();
        $this->base_price = $this->ticker_info->last_price;
        if (BITCOIN_PAIR_ID == $this->pair_id) 
            $this->base_price = $this->btc_price;

        $this->TargetDeltaPos(); // update amount
        if (5 == ($this->updates % 10))
            $this->Cleanup();  // не слишком часто
        $this->SaveToDB();
    }
 }

 class SignalFeed implements ArrayAccess, Countable {
    protected $signals = [];    
    public    $core = null; // class TradingCore
    public    $engine = null; // class TradingEngine
    protected $feed_server = 'http://vps.vpn'; // must be specified in /etc/hosts

    protected $load_fails = 0;
    public   $orders_cache = [];
    public   $orders_map  = []; // for fast access OrderInfo
    
    public   $signal_info = [];
    public   $trig_prices = []; // for stop triggers

    public   $pos_cache = [];


    public   $unfilled_map = [];
    public   $common_symbol = false; // одна позиция на инструмент, независимо от валюты котировки. Например ETHUSD & ETHBTC сводятся к позиции в ETH

    public function __construct(TradingCore $owner) {
        $this->core = $owner;
        $this->engine = $owner->Engine();
        if (!$this->engine) throw new Exception("Engine not initialized");
    }
    public function Finalize(bool $eod) {
      foreach ($this->signals as $sig) {
        $sig->last_source = 'finalize';
        $sig->SaveToDB();  // перед завершением сохранить все изменения
        $sig->Finalize();
      }    
      $this->signals = [];      

      $mysqli = $this->core->mysqli;      
      $table = $this->engine->TableName('ext_signals');
      $dump = $mysqli->select_rows('*', $table, 'WHERE id = 742', MYSQLI_ASSOC);
      if (is_array($dump)) 
          file_put_contents("{$this->engine->account_id}/ext_signals_dump-exit.json", json_encode($dump)."\n");
    }

    public function offsetExists(mixed $offset): bool {
        return isset($this->signals[$offset]);
    }

    public function offsetGet(mixed $offset): mixed {        
        return $this->GetSignal($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        $this->signals[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->signals[$offset]);
    }

    public function count(): int {
        return count($this->signals);
    }

    public function CalcPosition(int $pair_id, bool $target = true, bool $native = true, bool $raw = false) {
        $saldo = 0;
        $cnt = 0;                
        $ti = $this->engine->TickerInfo($pair_id);
        $core = $this->core;

        // if ($ti) $this->core->LogMsg("~C94#PERF_CALC_POS:~C00 pair %s, avail signals %d", $ti->pair, count($this->signals));               

        $dbg_pair = $core->GetDebugPair();

        $prev = isset($this->pos_cache[$pair_id]) ? $this->pos_cache[$pair_id] : 0;
        $tt = $target ? 'target' : 'current';
        if ($raw)  $tt .= ':raw';

        
        foreach ($this->signals as $sig) {
            if ($sig && $sig->pair_id != $pair_id) continue;
            if ($sig->closed) continue;                        
            $cnt ++;
            if (!$sig->active) continue; // только активные сигналы сальдировать для текущей позиции            
            $delta = $target ? $sig->TargetDeltaPos($native, $raw) : $sig->CurrentDeltaPos($native);
            
            $saldo += $delta;
            $dtxt = $native ? $ti->FormatAmount($delta, Y, Y) : $ti->FormatQty($delta, Y);
            $stxt = $native ? $ti->FormatAmount($saldo, Y, Y) : $ti->FormatQty($saldo, Y);

            $type = get_class($sig); // $sig->flags & SIG_FLAG_GRID ? 'grid' : 'signal';

            if ($pair_id == $dbg_pair)
                $core->LogMsg(" ~C94#CALC_POS_ADD($tt):~C00 $type %s, + %s = %s", 
                      strval($sig), $dtxt, $stxt);
            
        }        
        
        if ($ti && $native && $cnt && $saldo != $prev) {        
            $this->core->LogMsg("~C96#CALC_POS_CHG:~C00 processed %2d / %3d active signals for pair %10s $tt position result %s => %s", 
                                 $cnt, count($this->signals), $ti->pair, 
                                  $ti->FormatAmount($prev, Y, Y), 
                                  $ti->FormatAmount($saldo, Y, Y));
            if ($target) $this->pos_cache[$pair_id] = $saldo;            
        }                     
        return $saldo;
    }  

    public function CalcPrices() { // рассчет цен для триггеров
        $engine = $this->engine;
        $core = $this->core;
        $used_pairs = [];
        foreach ($this->signals as $sig)            
           $used_pairs[$sig->pair_id] = $engine->TickerInfo($sig->pair_id);  
           
        ksort($used_pairs);
        $table = $engine->TableName('ticker_history'); // possible located in datafeed DB
        if (strpos($table, '#ERROR'))
           throw new Exception("Ticker history table not found: $table");
        $mysqli = $engine->sqli();
        $ts_from = date(SQL_TIMESTAMP, time() - 320); // ~5 minutes ago
        $period_slow = 13;
        $period_fast = 2;           

        foreach ($used_pairs as $pair_id => $tinfo) {
           if (is_null($tinfo)) continue;
           $rows = $mysqli->select_rows('ask,bid,last,ROUND(UNIX_TIMESTAMP(ts)/60) as m', $table, 
                                        "WHERE (ts >= '$ts_from') AND (pair_id = $pair_id) GROUP BY m ORDER BY ts DESC LIMIT $period_slow");

           if (!is_array($rows) || 0 == count($rows)) {
              $core->LogMsg("~C91#WARN:~C00 no recent history for pair %d", $pair_id);
              continue;
           }                                      
           
           $vwap = curl_http_request("http://db-local.lan/bot/get_vwap.php?pair_id=$pair_id&limit=5");                           
           $vwap_good = (false === strpos($vwap, '#') && is_numeric($vwap));

           if (!is_array($rows) || 0 == count($rows) || !$tinfo) {
             if ($vwap_good)
                 $this->trig_prices[$pair_id] = floatval($vwap);
             else   
                 $this->trig_prices[$pair_id] = $tinfo->last_price;
             continue;
           }   
           
           $rows = array_reverse($rows);
           $slow = 0;
           $fast = 0;
           $highest = 0;
           $lowest  = 0;
           $c_slow = 1 / $period_slow;
           $c_fast = 1 / $period_fast;
           $prices = [];
           foreach ($rows as $i => $row) {             
             // если в текущую выборку цена последней сделки уже устарела, спред сдвинулся и даст ориентир
             list($ask, $bid, $last) = $row;
             // $ask = floatval($ask);
             // $bid = floatval($bid);
             // $last = floatval($last);                         
             $price = min($ask, $last); // min ask or last
             $price = max( $bid, $price); // max bid or last             
             $prices []= floatval($price);
             if (0 == $i) {
               $slow = $price;
               $fast = $price;               
               $highest = max($price, $ask);
               $lowest = min($price, $bid);
             }
             else {
               $slow = $c_slow * $price + (1 - $c_slow) * $slow;
               $fast = $c_fast * $price + (1 - $c_fast) * $fast; 
               $highest = max($highest, $price, $ask);
               $lowest  = min($lowest, $price, $bid);
             }              
           }
           
           $price = $tinfo->last_price;           
           $mix = $highest + $lowest - $fast;
           $mix = min($mix, $price * 1.01); // 1% above last is filtering bound 1
           $mix = max($mix, $price * 0.99); // 1% below last is filtering bound 2
           $mix = $tinfo->FormatPrice($mix);
           

           if ($vwap_good) {              
              $this->trig_prices[$pair_id] = floatval($vwap) * 0.1 + floatval($mix) * 0.9; // 10% vwap + 90% EMA reversal mix
              $core->LogMsg("~C94#AVG_LAST:~C00 pair %9s, ll = %10s, mix %10s, hh = %10s, vwap = %10s, periods = %d / %d, used prices %s",
                           $tinfo->pair, $tinfo->FormatPrice($lowest), $mix, $tinfo->FormatPrice($highest), $tinfo->FormatPrice($vwap), 
                           $period_slow, $period_fast, json_encode($prices));              
           }   
           else 
              $this->trig_prices[$pair_id] = $mix;          
        }                             
    }

    public function CollectSignals(string $select, int $pair_id, bool $only_active = true): array {
      $result = [];
      $select = explode(',', $select);
      $add_limit = array_search('limit', $select) !== false;
      $add_any = array_search('any', $select) !== false; 
      $add_grid = array_search('grid', $select) !== false;
      $limit_flags = SIG_FLAG_LP | SIG_FLAG_TP;            

      foreach ($this->signals as $sig) {
        if ($only_active && !$sig->active) continue;
        if ($sig->closed || $sig->pair_id != $pair_id && $pair_id > 0) continue;    
        // выборка лимитных заявок предполагает наличие триггерных цен, или номеров активных заявок  
        if ($add_grid && $sig->flags & SIG_FLAG_GRID)
           $result []= $sig;
        elseif ($add_limit && $sig->flags & $limit_flags && $sig->flags < SIG_FLAG_GRID)
           $result []= $sig;
        elseif ($add_any) 
           $result []= $sig; 
      }
      return $result;
    }


    public function DispatchOrder(OrderInfo $oinfo, string $ctx = 'DispatchOrder'){
      $pair_id = $oinfo->pair_id;
      if ($oinfo->batch_id <= 0 || $oinfo->IsOutbound()) return false;
      if ($oinfo->signal_id > 0 && isset($this[$oinfo->signal_id])) {
        $sig = $this[$oinfo->signal_id];  
        if ($sig->id == $oinfo->signal_id)         
            return $sig->AddOrder($oinfo, "fast via $ctx");                
        $this->core->LogMsg("~C91 #WARN:~C00 order %s is yet not related to ext signal %s", strval($oinfo), strval($sig));                      
      }
      // not so fast
      foreach ($this->signals as $sig) {       
        if ($pair_id == $sig->pair_id && $sig->GenBatch($oinfo->batch_id))         
          return $sig->AddOrder($oinfo, "slow via $ctx");                
      }        
      return false;
    }


    public function FindOrder(int $oid, int $pair_id, bool $load):? OrderInfo {
      $oinfo = $this->orders_map[$oid] ?? null; // fastest way
      if (is_null($oinfo))
          $oinfo = $this->engine->FindOrder($oid, $pair_id, $load);
       if (is_object($oinfo)) 
          $this->orders_map[$oid] = $oinfo; // to cache 

       return $oinfo;            }

    public function GetSignal(int $id, int $flags = 0, bool $ret_new = false): ?ExternalSignal {
        if (isset($this->signals[$id]))
            return $this->signals[$id];            
        $make_grid = $flags & SIG_FLAG_GRID;      
        $sig = null;

        if ($id < MAX_OFFSET_SIG)
            $sig = new OffsetSignal($this); // 
        else
            $sig = $make_grid ? new GridSignal( $this) :  new ExternalSignal( $this);                            

        $load = $sig->LoadFromDB($id);
        if ($load || $ret_new) {
            $this->signals[$id] = $sig;        
            return $sig;
        } 
        return null;
    }


    public function GetUnfilled(int $pair_id, bool $prefered_buy, float $min_cost = 100): ?ExternalSignal { // получение сигнала для пары, который не полностью исполнен
        $core = $this->core;
        $ti = $this->engine->TickerInfo($pair_id);
        if (!is_object($ti)) {
          $core->LogError("~C91#FAIL(GetUnfilled):~C00 ticker info for pair %d not available", $pair_id);  
          return null;  
        } 

        
        $dbg_pair = $core->GetDebugPair();
        $dbg = ($pair_id == $dbg_pair);
        if ($dbg)
           $core->LogMsg("~C94#DBG(GetUnfilled):~C00 search for unfilled signals for pair %d, min cost = %f in %s signals", $pair_id, $min_cost, count($this->signals));

        $results = [];
        ksort($this->signals);
        foreach ($this->signals as $sig) {            
            if ($sig->pair_id != $pair_id || $sig->closed || $sig->flags & SIG_FLAG_GRID) continue;            
            $sig->TryAdoption(); // попробовать захватить дельта-заявки в персональный список
            $sig->Update();                        
            $this->engine->last_scale = 'none';    

            if ($sig->postpone > 0) {
                $sig->postpone --;
                continue;
            }

            $tp = $sig->TargetDeltaPos(false);
            $cp = $sig->CurrentDeltaPos(false);
            $delta = $tp - $cp;
            $cost =  $ti->QtyCost(abs($delta));            
            $ready = $cost > $min_cost;            
            $cnt = $sig->ActualOrders();
            $tag = $ready ? '~C04~C97' : '~C94';            
            if ($dbg) 
                $core->LogMsg("$tag#DBG(GetUnfilled):~C00 signal %s, delta cost %9.2f, orders %2d, cdp = %10f, tdp = %10f, last scale = %s", 
                                      strval($sig), $cost, $cnt, $cp, $tp, $this->engine->last_scale); //*/
            if ( $ready ) {
                $key = round($cost + $sig->exec_prio * 10000, 1);
                $results [$key]= $sig;
            }    
        }

        $this->unfilled_map[$pair_id] = count($results);
        $filtered = [];
        // из множества можно выбирать тот у которого в наличии активные заявки, для предотвращения гонки
        // + отфильтровывать по желаемому направлению заявки
        foreach ($results as $key => $sig) {
            if ($sig->PendingAmount() > 0) 
                return $sig;
            $buy_sig = $sig->Bias() > 0;
            if ($prefered_buy == $buy_sig)                
               $filtered[$key] = $sig;
        }
        if (count($filtered) > 0) {            
            if ($dbg)
                $core->LogMsg("~C94#DBG(GetUnfilled):~C00 after direction filter %d / %d signals remain", count($filtered), count($results));
            $results = $filtered;  // есть подходящие по направлению        
        }

        if (count($results) > 0) {          
            ksort($results); // самые тяжелые сигналы надо вернуть первыми
            return array_pop($results);
        }        
        return null;

    }

    
    public function ImportSignal(array $src, string $ctx = 'remote'): bool {        
        $id = $src['id'];
        if ($src['pair_id'] <= 0) return false;      
        try {
          $sig = $this->GetSignal($id, $src['flags'] ?? 0, true); 
          $src['ts_checked'] = date_ms(SQL_TIMESTAMP3);
          if ($sig && $sig->Import($src)) {           
            $sig->last_source = "import_signal  $ctx".tss();           
            return true;
          }   
        }  catch (Exception $E) {
           $this->core->LogException($E, "~C91#SERIOUS:~C00 failed to import signal %s",  json_encode($src));
        }
        // $core->LogError("~C91#ERROR:~C00 failed to import signal %s", json_encode($src));
        return false;
    }

    public function LoadFromDB () {
        // при недоступности сервера сигналов, загрузить последние 
        $core = $this->core;
        $engine = $core->Engine();        
        $table =  $engine->TableName ('ext_signals');        
        $sig_table = $engine->TableName('batches');
        $ord_table = $engine->TableName('matched_orders');
        $mysqli = $this->core->mysqli;        
        $mysqli->options(MYSQLI_OPT_READ_TIMEOUT, 30);    
        $acc_id = $engine->account_id;
        $list = $mysqli->select_map('id, flags', $table, "WHERE (closed=0) AND (id > 0) AND (account_id=$acc_id) AND (pair_id > 0)");
        $start = $mysqli->select_value('MIN(id)', $table, 'WHERE (closed=0) and (id > 0)'); 
        $this->signal_info = $mysqli->select_map('signal_id, COUNT(ts) as count, MIN(ts) as first_order_ts, MAX(ts) as last_order_ts, MIN(id) as first_order, MAX(id) as last_order', 
                                $ord_table, "WHERE (account_id = $acc_id) AND (signal_id > 0) GROUP BY signal_id", MYSQLI_OBJECT);
        file_save_json("{$engine->account_id}/ext_signals_meta.json", $this->signal_info);                                

        $need_set = OFLAG_DIRECT;
        $skip_set = OFLAG_OUTBOUND | OFLAG_RESTORED;
        if ($start > 0) {
          $sig_first = $mysqli->select_value('MIN(id)', $sig_table, "WHERE parent >= $start");  
          $cache = [];                    
          if ($sig_first)
             $cache = $mysqli->select_map('id,signal_id', $ord_table, "WHERE (pair_id > 0) AND (batch_id >= $sig_first) AND (account_id = $acc_id) AND (status != 'canceled') AND (flags & $need_set > 0) AND (flags & $skip_set = 0)  ORDER BY id");          
          if (is_array($cache)) 
              $this->orders_cache = $cache;                    
          $core->LogMsg("~C94#PERF(SignalFeed.LoadFromDB):~C00 signal feed orders cache size = %d", count($this->orders_cache));
          foreach ($this->orders_cache as $id => $sig_id) 
              $this->orders_map[$id]  = $engine->FindOrder($id, 0, true); // for fast access 
        }
            
        if (is_array($list)) {            
            $ext = 0;
            $grids = 0;
            $core->LogMsg("~C96#PERF:~C00 loading %d signals from DB", count($list));
            foreach ($list as $sig_id => $flags) {
                $t_start = pr_time();
                $sig = $this->GetSignal($sig_id, $flags);                          
                if ($sig) {  
                    $elps = pr_time() - $t_start;
                    if ($elps > 3) 
                        $core->LogMsg("~C31#PERF_WARN:~C00 load signal %s, orders %d, batches %d, took %.2f seconds", strval($sig), count($sig->gen_orders), count($sig->gen_batches),  $elps);
                    if ($flags & SIG_FLAG_GRID)  
                        $grids ++;
                    else 
                        $ext ++;
                }   
                else
                    $core->LogError("#ERROR: failed load/init signal %d from DB", $sig_id);                 
            }   
            $core->LogMsg("~C93#DBG(LoadFromDB):~C00 loaded %d signals, %d grids from %d:%s", $ext, $grids, count($list), $table);
        } else 
           $core->LogError("~C91#WARN(LoadFromDB):~C00 no active signals loaded from %s: %s", $table, var_export($list, true));
    }

    public function FindByOrder(OrderInfo $oinfo): ?ExternalSignal {      ;
      if (is_object($oinfo))
      foreach ($this->signals as $sig) {
        $ti = $sig->ticker_info;
        if ($sig->closed || !is_object($ti)) continue;
        if ($ti->pair_id != $oinfo->pair_id) continue; // optimization
        if (false !== $sig->FindOrder($oinfo->id)) return $sig;
      }
      return null;
    }


    public function LoadSignals(): int {
        $engine = $this->engine;
        $core = $this->core;      
        $setup = $core->ConfigValue('signals_setup', -1);
        if ($setup < 0)
            throw new Exception("Invalid signals setup value (not set in config table)");
        $url = $this->feed_server."/get_signals.php?setup=$setup";
        $json = curl_http_request($url);
        if (!$json) {
            $this->load_fails ++;
            if ($this->load_fails >= 5) 
                throw new Exception("Failed to load signals from $url");
            $core->LogError("~C91#FAIL(LoadSignals):~C00 from %s returned %s", $url, var_export($json, true));         
            return  0; 
        } 
        if (strpos($json, '#ERROR') !== false)  {  
            $core->LogError("~C91#FAIL:~C00 load signals from %s returned %s", $url, $json);
            return 0;        
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) return 0;              

        $this->load_fails = 0;
        $imported = 0;        
        $acceptable = 0;
        $failed = 0;
        $used_pairs = [];      
        foreach ($this->signals as $sig_id => $sig) {
            if ($sig_id >= MAX_OFFSET_SIG)
                $sig->closed = true; // mark all as closed, before overwrite
            else
                $loaded[$sig->id] = true; // prevent from closing
            $used_pairs[$sig->pair_id] = 1;  
        }   
        ksort($used_pairs);
        

        $this->CalcPrices(); // перекрыть рассчетами более актуальными через историю тикеров
        $skipped = [];
        $loaded = [];
        $dbg_pair = $core->GetDebugPair();

        foreach ($rows as $row) {
            $sig_id = $row['id'];
            $pair_id = $row['pair_id'];

            if ($sig_id <= 0 || 0 == $pair_id) {
                $failed ++;  
                continue;
            }   

            $ti = $engine->TickerInfo($pair_id);
            if (!$ti) {             
                $pair = "#$pair_id";
                if ($this->common_symbol && isset($row['pair'])) {
                    $pair = $row['pair'];
                    $base = pair_base($pair);
                    $ti = $engine->FindTicker($base);
                }    
                if (!$ti) {
                    $skipped[$pair] = 1;
                    continue;
                }  
                $ti->source_pair_id = $pair_id; // save source  
                $row['pair_id'] = $ti->pair_id; // replacement
            }   
            $row['active'] = true;          
            $row['closed'] = false;
            $row['account_id'] = $engine->account_id;
            $res = $this->ImportSignal($row, 'LoadSignals:'.json_encode($row));
            if ($pair_id == $dbg_pair)        
                $core->LogMsg("~C94#PERF:~C00 signal %s import from server, pair %s; result: %s", json_encode($row), $ti->pair, var_export($res, true));           
            $acceptable ++;
            if ($res)             
                $loaded [$sig_id] = 1;            
            else {
                $core->LogMsg("~C91#WARN:~C00 signal from %s was not imported", json_encode($row)); 
                $failed++;  
            }    
        }  
        

        $this->Update();        
        $ccnt = 0;
        
        foreach ($this->signals as $sig_id => $sig) {           
            if ($sig_id < MAX_OFFSET_SIG) continue; // skip offset signals
            if (!isset($loaded[$sig_id]) && !$sig->closed) {
                $class = get_class($sig);
                $core->LogMsg("~C91#WARN:~C00 $class %s was not imported, marking as closed", strval($sig));
                $sig->closed = true;
            }   
            if ($sig->closed) {
                $ccnt ++;
                if (0 == date('i')) 
                    unset($this->signals[$sig->id]); // cleanup          
            }                               
        }       
        // foreach ($used_pairs as $pair_id => $v) $this->CalcPosition($pair_id);            
        $imported = count($loaded);
        $this->core->LogMsg("~C93#PERF(LoadSignals):~C00 imported %d / %d acceptable signals from %s, total = %d, closed = %d, used_pairs = %d, skipped = %s, failed = %d", 
                                $imported, $acceptable, $url, count($this->signals), $ccnt, count($used_pairs), json_encode(array_keys($skipped)), $failed);
        return $imported;
    }     
  
    public function Update() {
        $core = $this->core;
        $engine = $this->engine;
        foreach ($core->offset_pos as $pair_id => $offset) {
            if ($pair_id > 0 && $pair_id < MAX_OFFSET_SIG) {                
                $sig = $this->GetSignal($pair_id, 0, true); // load or create                                
                $sig->pair_id = $pair_id;                       
                $sig->account_id = $engine->account_id;                
                $sig->id = $pair_id;                                
            }
        }
        foreach ($this->signals as $id => $sig)  {
            $sig->Update();
        }
    }
}