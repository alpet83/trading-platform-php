<?php
    include_once('../lib/common.php');
    include_once('../lib/esctext.php');
    include_once('../lib/db_tools.php');
    require_once('./trading_core.php');
    require_once('./rest_api_common.php');

    $utc = new DateTimeZone('UTC');

    define('SATOSHI_MULT', 0.00000001);
    define ('USD_MULT',      0.000001);
    define ('MIN_BTC_PRICE', 3200);
    define ('CL_ORDER_REGEX', '/fb-(-*\d+)@(\d+)/'); // short version, not includes signal_id !  

    function sort_by(array $rows, string $field, bool $asc = true): array {    
        $result = [];
        $keys = [];
        $key = '0';
        foreach ($rows as $i => $row)  {
            if (is_object($row))
                $key = $row->$field;
            else
                $key = $row[$field];
            
            $key = "$key-$i"; // possible equal keys such as timestamp
            $keys [] = $key;
            $result [$key] = $row;    
        }      

        if ($asc)    
            ksort($result);    
        else 
            krsort($result);

        array_multisort($rows, $asc ? SORT_ASC : SORT_DESC, $keys);  
        // $rows = array_values($result); 
        return $rows;
    }
    

    function error_test($obj, string $text) {
        if (is_object($obj) && isset($obj->error))
            return  isset($obj->error->message) && false !== strpos($obj->error->message, $text);
        return false;
    }

    function filter_fixed(array $list): array {    
        $result = [];
        foreach ($list as $id => $info)
        if ( ($info->leaves > 0 || !$info->IsFixed()) && 'lost' !== $info->status)
            $result[$id] = $info;  

        return $result;
    }


    final class BitMEXEngine extends RestAPIEngine {

        private   $last_good_rqs = [];
        private   $open_orders = [];
        private   $loaded_data = [];
        private   $api_params = [];
        public    $stats_map = [];    
        public    $fails_map = [];

        private   $load_orders = 0;
        private   $load_pending = [];

        private   $last_trades_load = 0;

        private   $recursion = 0;    
        private   $recur_max = 0;

        private   $trades_buff = [];



        public function __construct(object $core) {            
            parent::__construct($core);                       
            $this->exchange = 'BitMEX';
            $env = getenv();
            if (isset($env['BMX_API_KEY'])) {
                $this->apiKey = trim($env['BMX_API_KEY']);
                $this->LogMsg("#ENV: Used API Key {$this->apiKey}");
                $this->secretKey = explode("\n", trim($env['BMX_API_SECRET']));
                // echo $env['BMX_API_SECRET']." vs \n";
                // echo file_get_contents('.bitmex.key');         
            } else {
                $key = file_get_contents('.bitmex.api_key');     
                $this->apiKey = trim($key);
                $this->secretKey = file('.bitmex.key');
                if (!$this->secretKey)
                    throw new Exception("#FATAL: can't load private key!\n");
            }
            $this->public_api = 'https://www.bitmex.com/';
            $this->private_api = 'https://www.bitmex.com/';
        }

        public function  Initialize() {
            parent::Initialize();
            // TODO: check account code valid
        }

        public function Finalize(bool $eod)     {
            $core = $this->TradeCore();
            $ts = date(SQL_TIMESTAMP);
            $history = [];
            $json = 'nope';
            $dbg = $eod ? '' : '-dbg';
            $dts = date('Y-m-d');
            foreach ($core->pairs_map as $pair_id => $pair) {
                $json = $this->RequestPrivateAPI('api/v1/user/executionHistory', ['symbol' => $pair, 'timestamp' => $ts], 'GET');
                if (false === $json || strlen($json) <= 2) continue;

                file_put_contents($core->tmp_dir."/trades-$pair@{$this->account_id}.raw", $json);
                $res = json_decode($json, true);   
                if (is_array($res) && count($res) > 0)
                $history [$pair_id]= $res;
                if ($core->aborted) break;  
            }  
            
            if (count($history) > 0) {                  
                $fname = sprintf("%s/trades_%d-%s$dbg.csv", $this->hist_dir, $this->account_id, $dts);
                $this->LogMsg("~C93#FINALIZE(BitMEX):~C00 saving execution history to %s, for %d pairs", $fname, count($history));         
                file_put_contents($fname, FormatCSV($history, ';', 1));
                if (file_exists($fname))
                    exec("bzip2 -f -9 $fname");
                else 
                    $core->LogError("~C91#ERROR:~C00 failed save execution history to %s", $fname);
            } else  {
                $core->LogError("~C91#ERROR(Finalize):~C00 PrivateAPI failed, returned %s...", substr($json, 0, 64));         
            }   
            
            $json = $this->RequestPrivateAPI('api/v1/user/walletHistory', ['currency' => 'all', 'count' => '10000', 'reverse' => 'true', 'start' => 0], 'GET'); 
            $res = json_decode($json, true);  
            if (is_array($res) && count($res) > 0) {
                $res = array_reverse($res);
                $fname = "data/history/wallet-{$this->account_id}-$dts$dbg.csv";
                file_put_contents($fname, FormatCSV($res));
                if (file_exists($fname))
                exec("bzip2 -f -9 $fname");
                else 
                $core->LogError("~C91#ERROR:~C00 failed save wallet history to %s", $fname);
            }


            parent::Finalize($eod);
        }


        protected function ImportTicker(stdClass $obj, string $mark = '') {
            $sym = $obj->symbol;
            $pmap = $this->pairs_map_rev;
            if (!isset($pmap[$sym]))            
                return null;
            
            $pair_id = $pmap[$sym];
            $tinfo = $this->TickerInfo($pair_id);
            // print_r($obj);
            $tinfo->enabled    = true;
            $tinfo->bid_price  = $obj->bidPrice;
            $tinfo->ask_price  = $obj->askPrice;
            $tinfo->last_price = $obj->lastPrice;
            $tinfo->fair_price = $obj->fairPrice ?? 0;
            $tinfo->lot_size   = $obj->lotSize;
            $tinfo->tick_size  = $obj->tickSize;
            $tinfo->price_precision = $tinfo->CalcPP($obj->tickSize);
            $tinfo->multiplier = $obj->multiplier;
            $tinfo->is_inverse = $obj->isInverse ? true : false;
            $tinfo->is_quanto  = $obj->isQuanto ? true : false;            
            $tinfo->pos_mult   = $obj->underlyingToPositionMultiplier ?? 0; // for ETHBTC like futures
            $tinfo->q2s_mult   = $obj->quoteToSettleMultiplier ?? 0;

            $tinfo->quote_currency = $obj->quoteCurrency;
            $tinfo->settl_currency = $obj->settlCurrency; // margin calculated in

            if ('Settled' == $obj->state || isset($obj->expiry) && strtotime_ms($obj->expiry) < time_ms()) {
                if (!$tinfo->expired)
                    $this->LogMsg("~C91#WARN_EXPIRED:~C00 instrument %s now is expired, state = %s, expiry = %s", $tinfo->pair, $obj->state, $obj->expiry ?? 'no time!');
                $tinfo->expired = true;                        
            }
            else  
                $tinfo->expired = false;

            $tinfo->OnUpdate ($obj->timestamp);
            // $ts = date_ms(SQL_TIMESTAMP, $tinfo->updated);

            if ('XBTUSD' === $sym)
                $this->set_btc_price($tinfo->last_price);
            $tinfo->is_btc_pair = (false !== strpos($sym, 'BTC', -3));

            if (false !== strpos($tinfo->quote_currency, 'XBT') || 
                false !== strpos($tinfo->quote_currency, 'BTC'))
                $tinfo->is_btc_pair = true;    
            // if ($tinfo->is_quanto && $tinfo->multiplier < 5000)  $tinfo->multiplier *= 1000; // TODO: this is dirty hack, need real method for detection        
            
            $host_id = $this->host_id;
            $info_file = "data/$sym$mark-$host_id.info";
            if (!file_exists($info_file) || filemtime($info_file) * 1000 < $tinfo->updated)
                file_put_contents($info_file, print_r($obj, true));      
            $json_file = "data/$sym$mark-$host_id.json";                  
            if (!file_exists($json_file) || filemtime($json_file) * 1000 < $tinfo->updated)
                file_put_contents($json_file, $tinfo->SaveToJSON());      

            return $tinfo;
        }

        public function LoadTickers() {
            global $curl_resp_header;
            parent::LoadTickers();

            $core = $this->TradeCore();

            $this->LogMsg(" trying acquire active instrument list from public API...");

            $json = $this->RequestPublicAPI('api/v1/instrument/active', array('any' => 1));        
            $list = json_decode($json);

            $updated = 0;
            $ignored = [];

            $pmap = $this->pairs_map_rev;
            if (!is_array($pmap) || 0 == count($pmap)) {
                $core->LogError("~C91#ERROR:~C00 pairs_map_rev not initialized, LoadTickers can't work");
                return false;
            }
            

            $work_map = [];
            foreach ($pmap as $pair => $pair_id) {
                $tinfo = $this->TickerInfo($pair_id);  
                if (!$tinfo) continue;            
                $tinfo->enabled = false;
                $work_map[$pair] = $tinfo;
            }          

            //  $this->LogMsg(" acceptable symbols: %s ", implode(',', array_keys($pmap)));
            $loaded = [];
            file_put_contents($core->tmp_dir.'/pairs_map.json', json_encode($core->pairs_map)); // для построения отчетов        

            if ($list && is_array($list))
                foreach ($list as $obj) {                
                    $tinfo = $this->ImportTicker($obj);
                    if ($tinfo) {
                        $updated ++;                        
                        unset($work_map[$tinfo->pair]);
                    }    
                    else 
                        $ignored []= $obj->symbol;                
                    // $json = $this->RequestPublicAPI('quote', array('symbol' => $pair, 'count' => 1));
                } // foreach
            else {
                $core->LogError("~C91#FAILED:~C00 public API instrument/active returned '%s', response headers:\n %s", $json, $curl_resp_header);
                if ($core->ErrorsCount() > 30)
                    throw new Exception("Instruments list retrieve failed to many times"); 
            }  
            if ($updated)
                file_put_contents($core->tmp_dir.'/active_instr.json', $json);

            sort($ignored);        
            
            foreach ($work_map as $pair => $tinfo)  {       
                $tinfo->elapsed = (time_ms() - $tinfo->updated) / 1000;  

                if ($tinfo->elapsed < 120) continue;            
                $params = ['symbol' => $pair];
                
                $json = $this->RequestPublicAPI('api/v1/instrument', $params);
                $data = json_decode($json);

                if (is_array($data) && is_object($data[0])) 
                    $data = $data[0];

                if (is_object($data) && isset($data->symbol)) {
                    $tinfo = $this->ImportTicker($data, '.expired');
                    if ($tinfo)
                        $tinfo->json = $json;                
                }
                else
                    $this->LogMsg("~C91#WARN:~C00 failed to load expired instrument %s, response: %s", $pair, $json);
            }

            $exp_list = array_keys($work_map);                 
            $this->LogMsg(" ignored symbols: %s, expired/skipped:  %s", json_encode($ignored), json_encode($exp_list));

            // afterload calculations
            if ($this->btc_price > 0)
                foreach ($loaded as $tinfo) {
                    if (null === $tinfo || !$tinfo instanceof TickerInfo) continue;
                    if ($tinfo->is_quanto && $tinfo->multiplier > 0)
                        $tinfo->min_cost = $tinfo->last_price * 1.05; // 5% is just protection
                    elseif ($tinfo->is_inverse)
                        $tinfo->min_cost = $tinfo->lot_size; // 1USD for XBTUSD
                    else
                        $tinfo->min_cost = 100;
                }

            return $updated;
        } // LoadTicker

        public function AmountToQty(int $pair_id, float $price, float $btc_price, float $value, mixed $ctx = false) { // from contracts to real quantity of base coins
            if (0 == $value || 0 == $price)
                return $value;

            $this->SetLastError('');
            $tinfo = $this->TickerInfo($pair_id);

            if (is_null($tinfo)) {
                $this->SetLastError("#ERROR(AmountToQty): no ticker info for $pair_id retrieved by engine");
                $this->LogMsg("~C91#ERROR(AmountToQty):~C00 no ticker info for %d, context %s, query from: %s", $pair_id, $ctx ?? 'default', format_backtrace());
                return 0;
            }

            if (!$tinfo->enabled) return 0;

            $core = $this->TradeCore();
            $rpos = false;
            if (isset($core->current_pos[$pair_id]))
                $rpos = $core->current_pos[$pair_id];

            if ($btc_price < 0)
                $btc_price = $core->BitcoinPrice();
            elseif (0 == $btc_price) {
                $btc_price = $this->get_btc_price();      
                if ('XBTUSD' === $tinfo->pair && $tinfo->native_price > 0)
                    $btc_price = $tinfo->native_price;
                elseif ($rpos && $rpos->btc_price > 0)
                    $btc_price = $rpos->btc_price;  // changes once position modified
            }   

            if ($tinfo->is_quanto && $tinfo->multiplier > 0 && $btc_price > 0) {
                $btc_coef = SATOSHI_MULT * $tinfo->multiplier;   // this value not fluctuated, typically 0.0001
                $btc_cost = $price * $btc_coef * $value;
                $usd_cost = $btc_cost * $btc_price;  // this value may be variable
                $res = $usd_cost / $price;
                // $this->LogMsg("#C2A: position = %d USDT, btc_coef = %.5f, btc_cost = %.5f, usd_cost = %8.2f, ref_price = %10f, result = %10f", $value, $btc_coef, $btc_cost, $usd_cost, $price, $res);
                return $res;                 // real position in base coins
            }
            if ($tinfo->is_inverse) {
                return $value / $price;  // may only XBTUSD and descedants
            }

            if (0 == $btc_price) {
                $this->SetLastError(" btc_price == 0, pair_id = $pair_id");
                return 0;
            }
            else
                $this->set_btc_price($btc_price);

            if (false === $tinfo->is_quanto && $tinfo->pos_mult > 0)
                return $value / $tinfo->pos_mult;
            $this->SetLastError(sprintf('not available method for %s, is_quato = %d, is_inverse = %d ', $tinfo->pair, $tinfo->is_quanto, $tinfo->is_inverse));
            return 0;
        }
        public function QtyToAmount(int $pair_id, float $price, float $btc_price, float $value, mixed $ctx = false) { // from qty to contracts
            $tinfo = $this->TickerInfo($pair_id);      
            if (is_null($tinfo)) {
                $this->SetLastError("ERROR(QtyToAmount): no ticker info for $pair_id retrieved by engine");
                return 0;
            }

            if (!$tinfo->enabled) return 0;

            $core = $this->TradeCore();

            if ($btc_price < 0)
                $btc_price = $core->BitcoinPrice();

            if ($btc_price < MIN_BTC_PRICE) {
                $btc_price = $this->get_btc_price();
                // $rpos = $core->current_pos[$pair_id];      
                if ('XBTUSD' === $tinfo->pair && $tinfo->native_price > 0)
                    $btc_price = $tinfo->native_price;  // most updated              
            }  

            if (0 == $this->btc_price)  return 0;

            if ($btc_price < MIN_BTC_PRICE)
                throw new Exception("QtyToAmount: btc_price = $btc_price, with ".strval($tinfo));


            if ($tinfo->is_quanto && $tinfo->multiplier > 0 && $price > 0) {
                $btc_coef = SATOSHI_MULT *  $tinfo->multiplier;
                $usd_cost = $price * $value;
                $btc_cost = $usd_cost / $btc_price;
                $res = $btc_cost / ($btc_coef * $price);
                if ($usd_cost > $core->total_funds * 0.5 && $core->total_funds > 0 && $ctx)
                    $this->LogMsg("~C31 #Q2A_WARN:~C00 %8s qty = %10f, usd_cost = %8.2f, btc_cost = %.5f, btc_price = %.0f, price = %10f, context %s, result amount = %10f", 
                                $tinfo->pair, $value, $usd_cost, $btc_cost, $btc_price, $price, $ctx, $res);

                if (abs($res) >= 1000000 && $ctx)  {
                    $core->LogError("~C91#ERROR:~C00 Extreme high value calculated $res for %s, context %s, called from: %s", strval ($tinfo), strval($ctx), format_backtrace());
                    $res = 0;
                }
                return floor($res * $tinfo->lot_size) / $tinfo->lot_size;
            } elseif ($tinfo->is_inverse) {
                return floor($value * $price);  // may only XBTUSD and descedants
            } elseif (false === $tinfo->is_quanto)
                return $value * $tinfo->pos_mult;

            $this->SetLastError(sprintf('not available method for %s, is_quato = %d, is_inverse = %d ', $tinfo->pair, $tinfo->is_quanto, $tinfo->is_inverse));
            return 0;
        }

        public function  LimitAmountByCost(int $pair_id, float $amount, float $max_cost, bool $notify = true) {
            $tinfo = $this->TickerInfo($pair_id);
            $core = $this->TradeCore();
            if (!$tinfo || 0 == $tinfo->last_price) {
                $pair = $core->FindPair($pair_id);
                $this->SetLastError("#FATAL: no ticker for pair_id $pair_id [$pair]");
                $this->LogMsg("~C91#WARN(LimitQtyByCost):~C00 Failed get info for pair %d %s", $pair_id, $pair);
                return 0;
            }

            $price = $tinfo->last_price;
            $qty = $this->AmountToQty($pair_id, $price, -1, $amount);
            $cost = $qty * $price;

            if ($cost > $max_cost) {
                $qty = $max_cost / $price;
                if (is_infinite($qty))
                    throw new Exception("LimitQtyByCost failed calculate amount from $max_cost / $price");

                $amount = $this->QtyToAmount($pair_id, $price, -1, $qty);
                $amount = $tinfo->FormatAmount($amount);
                if ($notify)
                    $this->LogMsg ("~C91#WARN:~C00 for %s due cost %.1f$ > max limit %d, price = %10f, amount reduced = %s from qty = %.5f", $tinfo->pair, $cost, $max_cost, $price, $amount, $qty);
            }
            return $amount;
        }

        public function NativeAmount(int $pair_id, float $qty, float $ref_price, float $btc_price = 0, mixed $ctx = false) {
            return $this->QtyToAmount($pair_id, $ref_price, $btc_price, $qty, $ctx); // used values fixed after position feed changes      
        }

        private function DecodeOrderStatus(string $st): string {
            $st = strtolower($st);
            $st = str_ireplace('partiallyfilled', 'partially_filled', $st);
            return $st;
        }


        public function RecoveryOrder(int $id, int $pair_id, \stdClass $order): ?OrderInfo {
            if (0 == $pair_id) return null; 
            if ($order->orderQty <= 0) return null;

            $tinfo = $this->TickerInfo($pair_id);
            $info = $this->CreateOrder($id, $pair_id);
            $info->amount = max($order->cumQty, $order->orderQty);           

            if (0 == $info->amount) 
                return null;
            $info->price = $order->price ?? $tinfo->last_price;
            $info->pair = $tinfo->pair;    
            $info->comment = $order->text ?? 'recovered';  
            $info->flags |= OFLAG_RESTORED;
            if ($id > 0)
                file_add_contents("data/orders-trace-{$this->account_id}.log", tss()." #RECOVERY: ".strval($info)."\n");  
            return $this->UpdateOrder($info, $order); // recursion here expected
        }

        public function UpdateOrder(mixed $target, \stdClass $order, bool $force_fixed = false, string $op = 'load'): ?OrderInfo {
            $core = $this->TradeCore();
            if (!is_object($order) || !isset($order->symbol)) {
                $core->LogError("~C91#ERROR(UpdateOrder):~C00 invalid order source data %s", var_export($order, true));
                return null;
            }  

            if (is_null($target))
                throw new Exception("FATAL: UpdateOrder(Target == NULL)!");

            if (is_string($target) && false !== strpos($target, '@'))   { // clOrdID formatted
                preg_match('/@(\d+)/', $order->clOrdID, $m);
                if (count($m) < 2) {
                    $core->LogError("~C91#ERROR(UpdateOrder):~C00 invalid 'target' parameter used %s, can't decode clOrdID", $target);
                    return null;        
                } 
                $target = intval($m[1]);        
            }

            $info = $target;
            $pair_id = $this->pairs_map_rev[$order->symbol] ?? 0;
            if (0 == $pair_id)  {
                $this->LogMsg("#WARN(UpdateOrder): pair_id not found for symbol %s", $order->symbol);
                return null; 
            }

            if (!is_object($target) && is_numeric ($target) ) {
                $info = null;
                if (isset($this->pending_orders[$target]))
                    $info = $this->pending_orders[$target];
                else            
                    $info = $this->FindOrder($target);

                if ($target > 0 && is_null($info)) 
                    return $this->RecoveryOrder($target, $pair_id, $order);            
            }        

            if (!is_object($info)) {
                $core->LogError("~C91#ERROR(UpdateOrder):~C00 invalid 'target' parameter used %s (%s)", var_export($info, true), var_export($target, true));
                /// var_dump($info);
                return null;
            }
            
            $info->source_raw = $order;
            $this->updated_orders[$info->id] = $info;

            $was_fixed = $info->IsFixed();
            if (!isset($order->orderID)) {
                $this->LogMsg("~C91#WARN:~C00 order source %s without orderID, at operation %s", json_encode($order), $op);
                $order->orderID = $info->order_no;        
            }   

            if (strlen($info->order_no) < 40)
                $info->order_no = $order->orderID;      
            elseif ($info->order_no != $order->orderID)  { 
                $list = $this->FindOrders('order_no', $order->orderID);
                // ошибка появление второй заявки с тем-же ID, вероятная при проблема с синхронизацией БД
                if (0 == count($list)) {           
                    $core->LogError("~C91#ERROR(UpdateOrder):~C00 order %s @%s order_no not equal source data %s, may id was reused/splitted! Creating additional instance", 
                                strval($info), $info->account_id, json_encode($order));        
                    $id = $info->id;                  
                    $info = $this->RecoveryOrder(0, $pair_id, $order);
                    $info->fork = true;
                    $info->flag |= OFLAG_CONFLICT;
                    $info->comment = $order->text ?? "forked from #$id";           
                    return $info;
                } 
                $info = $list[array_key_first($list)];
            }      
            
            if (isset($order->price)) {
                assert($order->price > 0, new Exception("Invalid price value"));
                $info->price = $order->price;
                $info->avg_price = $order->price;
            }  
            if (isset($order->avgPx))
                $info->avg_price = floatval($order->avgPx); 
            if ($order->orderQty > 0)   // for canceled is 0
                $order->amount = $order->orderQty;      
            
            if (isset($order->cumQty))
                $info->matched = doubleval($order->cumQty);


            $tt = $order->transactTime;          
            $st = $this->DecodeOrderStatus($order->ordStatus);   

            assert(!is_null($info->price) && $info->price > 0, new Exception("Invalid price value, source order data: ".json_encode($order)));
            try {        
                if ('filled' == $st && 0 == $info->matched)  {
                    $core->LogOrder("~C91#WARN(UpdateOrder):~C00 order %s new status filled, but matched amount is 0, source %s", strval($info), json_encode($order));
                    $info->matched = $info->amount;
                }           

                if (!isset($order->leavesQty))
                    $order->leavesQty = 0; // seems order filled/canceled

                if ($st == 'canceled' && $info->matched > 0)  // адаптация статуса
                    $st = $info->leaves > 0 ? 'partially_filled' : 'filled'; 
            
                $info->leaves = $order->leavesQty;  // referense rest amount;
                $res = $info->set_status($st, true); 
                if (!$res)
                $core->LogOrder("~C91#DBG:~C00 using source %s", json_encode($order));                  

                $ts = strtotime_ms($order->timestamp);
                $ts = date_ms(SQL_TIMESTAMP3, $ts);
                $info->ts = $ts; // exchange time set
                if ($ts < $info->created) {
                    $core->LogOrder("~C94#DBG(UpdateOrder):~C00 for order %s time from past %s relative instance %s", strval($info), $ts, $info->created);
                    $info->created = $ts;
                }

                $utm = strtotime_ms($tt);
                $uts = date_ms(SQL_TIMESTAMP3, $utm);
                $info->updated = $uts;

                if ($info->IsFixed() && !$was_fixed) 
                    $info->ts_fix = $uts;        

                // elseif ($uts < $info->updated)  $core->LogOrder("~C93 #DBG(UpdateOrder):~C00 order %s time not updated by late, %s < %s", strval($info), $uts, $info->updated);
                $this->DispatchOrder($info);
                $info->OnUpdate($op);
            } catch (Exception $E) {
                $core->LogError("~C91#EXCEPTION(UpdateOrder):~C00 %s, callstack:\n %s ", $E->getMessage(), $E->getTraceAsString());
            } 
            return $info;
        }

        public function  LoadOrders(bool $force_all = false) {
            $core = $this->TradeCore();      
            $this->load_orders ++;                

            // NOTE: вся жуткая и навороченная логика в дальнейшем, только издержки оптимизации и багоборства. Гораздо все проще должно быть через WebSocket
            // boolval($this->load_orders % 1 > 0);  
            $min_t = time() - 3600;
            
            $using_limit = false;
            $pending =  $this->load_pending;

            if (0 == $this->recursion) {
                $this->recur_max = 0;
                $pending = $this->PendingOrdersAll();          
                $lost = $this->MixOrderList('lost:5', 0, true);

                if (0 == count($pending) && 0 == count($lost))
                    $this->LogMsg("~C91#PERF/WARN:~C00 no pending/lost orders found, may be all fixed?");
                else  
                    $this->LogMsg("~C94#PERF(LoadOrders):~C00 trying update %d pending and recovery %d lost orders", count($pending), count($lost));

                $pending = array_replace($pending, $lost);          
            }     
            
                
            $total = count($pending);
            $load_open = (0 == $this->recursion  && $total > 0); // чаще всего нужно проверять активные нетронутые заявки      
            $load_filled = (0 == $this->recursion && 0 == $total || 1 == $this->recursion);
            $load_killed = (!$load_open && !$load_filled || 2 == $this->recursion);

            $max_t = 0;
            $pairs = [];
            

            foreach ($pending as $id => $oinfo) {
                $t = strtotime($oinfo->created) - 300;
                $u = strtotime($oinfo->updated);
                $min_t = min($min_t, $t);
                $max_t = max($max_t, $u);
                if (strlen($oinfo->pair) > 4)
                    $pairs[$oinfo->pair] = true;
                $using_limit |= $oinfo->IsLimit();
                $l = strlen($oinfo->order_no); 
                if ($l < 20) {
                    $this->LogMsg("~C91#WARN:~C00 order %s has invalid order_no, length %d", strval($oinfo), $l);
                    unset($pending[$id]);        
                }    
            }            
            $min_ts = gmdate('Y-m-d H:i:00', $min_t);    
            $max_ts = gmdate('Y-m-d H:i:59', $max_t);    
            $cnt = 200;
            
            $minute = date('i');
            $params = ['count' => $cnt, 'reverse' => 'true'];    
            if (0 == $minute % 5 || $force_all) {
                $cnt = 500;        
            }  
            if ($load_open) 
                $params['startTime'] = gmdate(SQL_TIMESTAMP, time() - 3600 * 24 * 7); // week ago
            else 
                $params['start'] = 0;        

            if ($this->recursion >= 2) {
                $cnt = 500;         
                $params['start'] = $cnt * ($this->recursion - 2);
                if ($this->recursion >= 4)             
                    $params['endTime'] = $max_ts;
            }   


            $load_fails = $this->fails_map['load_orders'] ?? 0;
            $flt = new \stdClass();
            $final_attempt = false;
            if ($total < 10)  {
                $orders = [];
                $params = []; // reset
                foreach ($pending as $oinfo)
                $orders []= $oinfo->order_no; // номер обычно длинный, но его не нужно специально форматировать...
                $flt->orderID = $orders;
                $final_attempt = true;
            }  
            else {
                if ($load_open && $load_fails < 3)         
                $flt->ordStatus = ['New', 'PartiallyFilled']; 
                elseif ($load_filled && $load_fails < 3) 
                $flt->ordStatus = 'Filled';             
                elseif ($load_killed && $load_fails < 3) 
                $flt->ordStatus = 'Canceled';                          

                if (1 == count($pairs)) {  // можно получить все заявки для одной пары, вместо фильтрации по статусу для многих
                    $cnt = 500;
                    $pair = array_keys($pairs)[0];
                    $params['symbol'] = $pair; // для одной пары можно уточнить запрос
                }    
            }

            if (isset($flt->ordStatus) || isset($flt->orderID))
                $params['filter'] = json_encode($flt); // for GET parameters used http_build_query,so need strignify  

            $json = $this->RequestPrivateAPI("api/v1/order", $params, 'GET');
            $orders = json_decode($json);
            file_put_contents(sprintf('data/orders-%s-%d.json', $this->account_id, $this->recursion), $json);     
            
            $pmap = $this->pairs_map_rev;      
            $loaded = 0;
            
            $skipped = [0, 0, 0, 0, 0, 0];
            $manual = 0;
            
            $first_t = time();     
            $unknown = [];
            $t_hard_past = $min_t - 3600 * 24 * 13; // 13 days ago

            if (is_array($orders)) {        
                $orders = array_reverse($orders);
                $orders = sort_by($orders, 'transactTime', true); // по возрастанию времени 
                $this->fails_map['load_orders'] = 0;
                $this->open_orders = [];

                foreach ($orders as $order)
                if (is_object($order)) {           
                    $pair = $order->symbol;
                    if (!isset($pmap[$pair])) {
                        $unknown [$pair] = ($unknown [$pair] ?? 0) + $order->orderQty; // absolute
                        $skipped  [0]++;
                        continue;
                    }   
                    $pair_id = $pmap[$pair];
                    if ($order->account != $this->account_id) {
                    $skipped  [0]++;
                    continue;
                    }   
                    $m = [];
                    // fb-s\d+
                    if (!isset($order->clOrdID)) 
                        $order->clOrdID = 'external';

                    $tupd = strtotime_ms($order->transactTime) / 1000;
                    if ($tupd < $t_hard_past && !$load_open) {
                        $skipped [1] ++;
                        continue; // не нужно в память забивать столько старых заявок
                    }    

                    preg_match(CL_ORDER_REGEX, $order->clOrdID, $m);
                    $info = null;
                    $cid = 0;
                    $outer = false;

                    if (count($m) < 2) {           
                        $list = $this->FindOrders('order_no', $order->orderID, 'matched,archive,other,lost,db');              
                        $mixed = $this->TableName('mixed_orders');
                        $mysqli = $this->sqli();

                        if(count($list) > 0)
                            $info = array_values($list)[0];              
                        elseif ($id = $mysqli->select_value('id', $mixed,"WHERE order_no = '$order->orderID'")) 
                            $info = $this->FindOrder($id, $pair_id, true); // load from DB
                        else             
                        {
                            $info = $this->CreateOrder();
                            $info->pair_id = $pair_id;       
                            $info->pair = $pair;         
                            $info->amount = $order->orderQty;
                            $info->batch_id = EXT_BATCH_ID;
                            if (isset($order->text))
                                $info->comment = $order->text;
                            else
                                $info->comment = 'outer '.$order->clOrdID;
                            $outer = true;                
                        }                               
                        $manual ++;   
                    } 
                    else {  // 0 - full matched, 1 - batch id, 2 - order id, [3 - signal id]
                        $cid = $m[2];
                        if ($cid <= 0) {
                            $skipped [2] ++;
                            continue;                     
                        }    
                        if (isset($pending[$cid]))  // fast scan
                            $info = $pending[$cid];
                        else
                            $info = $this->FindOrder($cid, $pair_id, true); // full scan            

                        if (is_null($info))
                            $info = $this->RecoveryOrder($cid, $pair_id, $order); // recovery lost in DB
                    }  

                    if (!is_object($info)) { // means not found, null 
                        $skipped [3] ++;                
                        $unknown [$pair] = ($unknown [$pair] ?? 0) + $order->orderQty; // absolute
                        continue; 
                    }
                    $loaded ++;
                    $t = strtotime($info->created);
                    $first_t = min($first_t, $t);
                    if ('lost' == $info->status) 
                        $core->LogOrder("~C92#RECOVERY:~C00 for order %s retrieved data %s", strval($info), json_encode($order));

                    $oinfo = $this->UpdateOrder($info, $order, $this->recursion >= 2);
                    if (!is_object($oinfo)) {
                        $core->LogOrder("~C91#WANR:~C00 order %s not updated, source %s", strval($info), json_encode($order));
                        continue;
                    }
                    if ($outer)
                        $core->LogOrder("~C91#WARN~C00: registered new outer/manual order %s, from %s, placed in %s", strval($info), json_encode($order), strval($info->GetList()));
                    
                    if (!$oinfo->IsFixed())              
                        $this->open_orders []= $oinfo->id;          

                    if ('lost' !== $oinfo->status)
                        unset($pending[$oinfo->id]); // never use again    
                }
            }
            elseif ('[]' == $json) {        
                $this->fails_map['load_orders'] = 0;        
                $this->pending_orders = $pending; // сохранять надо в любом случае, перед выходом из функции        
            }
            elseif (!is_array($orders)) {       
                $load_fails ++;
                $core->LogError("~C91#FAILED_LOAD_ORDERS( $load_fails):~C00 private API order returned '%s' for params %s = %s", $json, json_encode($params), var_export($orders, true));        
                $this->fails_map['load_orders'] = $load_fails;
                $loaded = -1;
            } 

            if ($this->recursion >= 3)
                $pending = filter_fixed($pending);      

            $untouched = count($pending);   
            if ($loaded > 0)
                $this->LogMsg("~C96#PERF(%d):~C00 %d/%d orders (except %d), updated while LoadOrders from %d records, %s skipped, oldest = %s, outer/manual = %d ", 
                                $this->recursion, $loaded, $total, $untouched, count($orders), json_encode($skipped), date(SQL_TIMESTAMP, $first_t), $manual);

            $outated = 120; // 2 minutes



            if (!$load_open && 0 == $untouched) {        
                $this->load_pending = $pending; // сохранять надо в любом случае, перед выходом из функции
                return $loaded; 
            }   

            if (count($unknown) > 0)
                $core->LogOrder("~C94 #UNKNOWN_ORDERS:~C00 summary amount %s", json_encode($unknown));
            
            if ($this->recursion >= 3 || $final_attempt)   // пройден этап загрузки всех заявок, остался хардкор по заметанию
                foreach ($pending as $id => $info)  {     
                    if ( $info->Elapsed('checked') < $outated * 1000 ) continue;
                    $this->LogMsg("~C91#WARN(LoadOrders):~C00 order %s was fixed or not updated some time, seems it lost", strval($info));                                   
                    $info->Unregister(null, "API not retrieved");
                    $this->CancelOrder($info); // orphan order?                                   
                    unset($pending[$id]);              
                    $untouched = count($pending);
                }   

            $last_loaded = -2;  

            if ($untouched > 0 || 0 == $loaded || $force_all)  // дозагрузка возможна до 3 уровня рекурсии, если хоть-что возвращает API         
                try {
                    $this->load_pending = $pending;  // подрезанный список, для следующего уровня рекурсии
                    if ($untouched > 0)
                        $this->LogMsg("~C93#PERF({$this->recursion}):~C00 %d/%d pending orders not updated while LoadOrders, oldest time = %s, params %s, going deeper...", 
                                    count($pending), $total, $min_ts, json_encode($params));      
                    $this->recursion ++;
                    $this->recur_max = max($this->recursion, $this->recur_max);                  

                    if ($this->recursion <= 3) {
                        $last_loaded = $this->LoadOrders($force_all); // после загрузки активных заявок, рекурсивно подгрузить частично исполненные  
                        if ($last_loaded > 0)
                            $loaded += $last_loaded;
                        $pending = filter_fixed($this->load_pending);  // upgraded
                        $untouched = count($pending);   
                    }

                } finally {  
                    $this->recursion --;
                }    

            if ($untouched > 0) {
                $pmap = [];
                foreach ($pending as $id => $oinfo)
                    $pmap [$id] = sprintf('%s=%f:0x%x', $oinfo->status, $oinfo->leaves, $oinfo->flags);
                $core->LogError("~C91#WARN(LoadOrders):~C00 recursion  %d / %d; for orders %s was no updates, last loaded = %d", 
                        $this->recursion, $this->recur_max, json_encode($pmap), $last_loaded);
            }    
            return $loaded;  
        }

        public function  LoadPositions() {
            global $utc, $curl_resp_header;  
            parent::LoadPositions();

            $core = $this->TradeCore();

            $file_prefix = $this->account_id;

            $params = ['currency' => 'all'];
            $total = 0;   

            $json = $this->RequestPrivateAPI('api/v1/user/margin', $params, 'GET');
            $obj = json_decode($json);      
            $map = [];
            $upnl = [];

            // marginBalance = walletBalance + unrealisedPnl if uPNL < 0
            // totalBalance = walletBalance + unrealisedPnl + locked

            if ($obj && is_array($obj)) {
                $core->total_funds = 0;
                $core->used_funds = 0;
        
                file_put_contents('data/'.$file_prefix."_margin.json", $json);
                foreach ($obj as $rec) {          
                    if (!isset($rec->currency) || strlen($rec->currency) > 6) continue;
                    $ti = null;
                    $sym = strtoupper($rec->currency);        
                    $balance = max($rec->walletBalance + $rec->unrealisedPnl, $rec->marginBalance) + $rec->maintMargin;  

                    $upnl [$sym] = SATOSHI_MULT * $rec->unrealisedPnl;
                    if (strpos($sym, 'XBT') === 0) {
                        
                        $core->total_btc = SATOSHI_MULT * $balance;              
                        $upnl [$sym] *= $this->btc_price;

                        $margin_btc = $core->total_btc;
                        $usd = $margin_btc * $this->btc_price;                            
                        $total += $usd;
                        if (0 == $core->used_funds)
                            $core->used_funds = $rec->marginLeverage * 100;
                        $map[$sym] = $usd;                              
                        $this->account_id = $rec->account;              
                        continue;
                    }            
                    elseif ( strpos($sym, 'USD') !== false ) {           
                        $usd = $balance * USD_MULT;
                        $upnl [$sym] = $rec->unrealisedPnl * USD_MULT;
                        if ('MAMUSD' == $sym) { // this works in most high leverage configuration 
                            $core->total_funds = $usd;
                            $core->used_funds = $rec->marginLeverage * 100;
                        }  
                        else {          
                            $map[$sym] = $usd;
                            $total += $usd;
                        } 
                        continue;
                    }               
                    $ti = $this->FindTicker($sym);
                    if (is_null($ti)) {
                        // $this->LogMsg ("~C91#WARN:~C00 unknown currency %s in margin record", $rec->currency);
                        continue;
                    }

                    $coef = strpos($sym, 'GWEI') ? 0.000000001 : SATOSHI_MULT;          
                    $usd =  $balance * $coef * $ti->last_price;
                    $map[$sym] = $usd;
                    $total += $usd;
                } // foreach obj

                if (0 == $core->total_funds)      
                    $core->total_funds = $total;
                // $this->total_funds = $total;
                $this->LogMsg("~C96#INFO:~C00 saldo total funds = %.2f USD vs %.2f margin from %s, upnl %s",
                        $total, $core->total_funds, json_encode($map), json_encode($upnl));

            } // if obj
            else
                $core->LogError("~C91#FAILED:~C00 private API user/margin returned '%s', headers:\n %s", $json, $curl_resp_header);


            $columns = 'symbol,timestamp,currentQty,avgEntryPrice,realisedPnl,unrealisedPnl';    
            $json = $this->RequestPrivateAPI('api/v1/position', ['columns' => $columns], 'GET');
            $obj = json_decode($json);        
            
            $pmap = $this->pairs_map_rev;
            $now = time_ms();

            if ($obj && is_array($obj)) {
                file_put_contents('data/'.$file_prefix."_positions.json", $json);
                $result = [];
                $skipped = [0, 0, 0];
                foreach ($obj as $pos) {
                    if (!is_object($pos)) {
                        $skipped [0] ++;
                        continue;
                    }
                    $pair = $pos->symbol;
                    if (!isset($pmap[$pair])) {
                        $skipped [1] ++;
                        continue;
                    }    

                    $pair_id = $pmap[$pair];
                    $tinfo = $this->TickerInfo($pair_id);           

                    $pos_t = strtotime_ms($pos->timestamp);
                    $elps = ($now - $pos_t) / 10000;

                    if ($elps >= 600 && $pos->currentQty != 0)  {
                        $core->LogError("~C91#WARN:~C00 for %s position returned by API have age %d seconds, qty = %d", $pair, $elps, $pos->currentQty);
                        $skipped [2] ++;
                        continue;
                    }  

                    $rpos = $core->CurrentPos($pair_id);            
                    $result[$pair_id] = $rpos;
                    $rpnl = $pos->rebalancedPnl; // means RPL for current position, applied to wallet
                    $upnl = $pos->unrealisedPnl; // yet not applied... but effective for margin limitations            

                    if ('USD' == $tinfo->settl_currency) {
                        $rpos->unrealized_pnl = $upnl * USD_MULT;
                        $rpos->realized_pnl = $rpnl * USD_MULT;  
                        $pnl = sprintf('%.2f$', $rpos->unrealized_pnl);
                    }   
                    else {
                        $rpos->unrealized_pnl = $upnl * SATOSHI_MULT;
                        $rpos->realized_pnl = $rpnl * SATOSHI_MULT;
                        $pnl = sprintf('%.4f₿', $rpos->unrealized_pnl); // from SAT to BTC
                    }         
                    
                    $rpos->time_chk = $pos_t;
                    if ($rpos->amount == $pos->currentQty) {
                        $rpos->SaveToDB();  // save PnL fields if updated
                        continue;
                    }     
                    $diff = $rpos->amount - $pos->currentQty;

                    if (abs($diff) > abs($rpos->amount) * 0.03)
                        $this->LogMsg("~C94#UPDATE_POS:~C00 Position record $pair %f => %f, inremental %f, elps = %d sec", $rpos->amount, $pos->currentQty, $rpos->incremental, $elps);          

                    $rpos->avg_price = isset($pos->avgEntryPrice) ? $pos->avgEntryPrice : 0;
                    $rpos->set_amount($pos->currentQty, $rpos->avg_price,  $this->btc_price, $pos->timestamp);         /// <<<<<<<<<<<<<<<<<<<<<<<==============================          
                        $this->pnl_map[$pair_id] = $pnl;                
                    
                } // foreach
                
                if (count($result) > 0)
                    $core->ImportPositions($result);
                elseif (count($obj) > 0)
                    $core->LogMsg("~C94#CALM:~C00 no positions was updated from %d records", count($obj));
                
                file_put_contents("data/{$file_prefix}_posmap.json", json_encode($result));
                file_put_contents("data/{$file_prefix}_pnl_map.info", print_r($this->pnl_map, true));
                return count ($result);
            }
            else {
                $core->LogError("~C91#FALED:~C00 private API positions returned %s, headers:\n %s", $json, $curl_resp_header);
                return -1;
            }
        }


        public function ImportTrades(array $data): int {
            $rows = [];
            $acc_id = $this->account_id;

            $data = array_replace($data, $this->trades_buff); // undetected trades 

            foreach ($data as $rec) {
                $pair_id = $this->pairs_map_rev[$rec->symbol] ?? 0;
                if (0 == $pair_id) continue;

                $commiss = $rec->commission ?? 0;
                $exec_t = $rec->execType;
                $buy = ($rec->side ?? 'Buy') == 'Buy';
                $mt = strtotime_ms($rec->transactTime);
                $mts = date_ms(SQL_TIMESTAMP3, $mt);        

                $row = ['ts' => $mts, 'pair_id' => $pair_id, 'account_id' => $acc_id, 'amount' => $rec->lastQty, 'price' => $rec->lastPx, 'position'=> $rec->currentQty, 'comission' => $commiss, 'trade_no' => $rec->execID, 'buy' => $buy];       
                $flags = 0;

                $rpnl = 0;
                if (isset($rec->realisedPnl))
                    $rpnl = $rec->realisedPnl * SATOSHI_MULT; // TODO: check currency of pair (may spot trade)

                if ($exec_t == 'Trade') {
                $flags = 1;
                $row['rpnl'] = 1 * $rpnl;
                }  
                if ($exec_t == 'Funding') {
                $flags = 2;
                $row['rpnl'] = null;
                }   
                $row['flags'] = $flags;
                $row['order_id'] = null; 

                if (isset($rec->clOrdID) && false !== preg_match(CL_ORDER_REGEX, $rec->clOrdID, $m) && count($m) > 2) {
                    $row['order_id'] = $m[2];           
                    $oinfo = $this->FindOrder($m[2], $pair_id, true);
                    if ($oinfo) {            
                    $oinfo->out_position = $row['position']; // upgrade value                            
                    }            
                    unset($this->trades_buff[$rec->orderID]);
                }    
                elseif (isset($rec->orderID) && strlen($rec->orderID) > 5) {          
                $orders = $this->FindOrders('order_no', $rec->orderID, 'matched,archive,pending,lost,market_maker,db');
                if (count($orders) > 0) {
                    $oinfo = array_shift($orders);
                    $row['order_id'] = $oinfo->id;                       
                    $oinfo->out_position = $row['position']; // upgrade value             
                    unset($this->trades_buff[$rec->orderID]);
                }                          
                }    
                if (1 == $flags && is_null($row['order_id'])) {
                $this->trades_buff[$rec->orderID] = $rec;
                continue; // 
                }

                $rows []= $row;
            }   // foreach data     
            
            return parent::ImportTrades($rows);       
        } // ImportTrades

        public function LoadTrades(): int {
        
            $period = time_ms() - $this->last_trades_load;
            // Выбор пар, по которым прошли заявки за период
            $pairs = [];

            $tmax = 0;
            $matched = $this->GetOrdersList('matched');
            foreach ($matched as $oinfo) {
                $elps = $oinfo->Elapsed('updated');
                if ($elps > $period || !isset($this->pairs_map_rev[$oinfo->pair])) continue;
                $pairs [$oinfo->pair] = 1;
                $tmax = max($tmax, strtotime($oinfo->updated));
            }
            if (0 == count($pairs) || 0 == $tmax) return 0; // ничего обновлять пока не нужно

            $ts = date(SQL_TIMESTAMP, $tmax);
            $loaded = 0;
            foreach ($pairs as $pair => $load) {
                $params =  ['symbol' => $pair, 'timestamp' => $ts];
                $json = $this->RequestPrivateAPI('api/v1/user/executionHistory', $params, 'GET'); // must sort by timestamp
                if ('[]' == $json) continue;
                $data = json_decode($json);
                if (!is_array($data)) {
                $this->LogMsg("~C91#WARN:~C00 private API executionHistory with params %s returned %s", json_encode($params), $json);
                continue;
                }
                $loaded += $this->ImportTrades($data);               
            }  

            $this->last_trades_load = time_ms();

            return $loaded;

        }

        public function  PlatformStatus() {
            return 1; // TODO: detect by responce of public API
        }

        protected function EncodeParams(&$params, &$headers, $method) {
            if ('POST' === $method) {
                if (is_array($params))
                    $params = http_build_query($params, '', '&');
                if (!is_string($params)) {
                    $params = json_encode($params);
                    $headers []= 'Content-Type: application/json';
                } 
                $params = str_replace('."', '"', $params);
                $params = trim($params);
            }
            elseif (is_array($params))
                $params = http_build_query($params, '', '&');
            return $params;
        }

        public static function ClientOrderID(OrderInfo $info, int $host_id): string {
            return "fb-{$info->batch_id}@{$info->id}:{$info->signal_id}h{$host_id}";
        }

        public function  NewOrder(TickerInfo $tinfo, array $params): ?OrderInfo {
            $core = $this->TradeCore();
            $proto = $params;
            $proto['pair_id'] = $tinfo->pair_id;

            if ($tinfo->expired) {
                $this->SetLastError("Pair {$tinfo->pair} is expired");
                return null; // не нужно беспокоить биржу по мертвым парам
            }    

            if (0 == $proto['amount'])
                throw new Exception("Attempt create order with amount == 0");       

            if ($proto['amount'] > 1e6 && $tinfo->quote_currency != 'XBT') 
                    throw new Exception("#CRITICAL: used amount too big for new order: ".json_encode($params));

            $price = $proto['price'];     
            assert($price > 0, "Invalid price value");

            $shift = abs($price - $tinfo->last_price);     
            $max_dist = 0.30;
            if ($tinfo->last_price > 0 && $shift > $price * $max_dist && $proto['batch_id'] > 0) {
                $err = format_color("NewOrder: price change too big for new order --> %f relative last %f for %s, shift = %.2f", 
                                $price, $tinfo->last_price, $tinfo->pair, $shift);
                                
                if ($shift > $price * $max_dist * 2)                           
                    throw new Exception($err);  
                else
                    $core->LogError("~C91#ERROR:~C00 $err");
            }  
            $info = $this->CreateOrder(0, $tinfo->pair_id, 'NewOrder');  // OrderInfo instance simple
            $info->Import($proto);
            if (!$this->DispatchOrder($info)) {
                $core->LogError("#FAILED: order was not registered");
                $core->LogObj($proto, '  ', 'proto', 'error_log');
                return null;
            }

            $amount = floor($info->amount / $tinfo->lot_size) * $tinfo->lot_size;
            if ($amount < $info->amount) {
                $this->LogMsg("~C91#WARN:~C00 amount %s not multiple of lot_size %s, reduced to %s", $info->amount, $tinfo->lot_size, $amount);
                $info->amount = $amount;
            }          

            

            $info->ticker_info = $tinfo;       
            $side = $info->buy ? 'Buy' : 'Sell';
            $symbol = $core->pairs_map[$tinfo->pair_id];
            $hidden = isset($params['hidden']) ? $params['hidden'] : false;
            if ($info->IsGrid() && $info->signal_id <= 0)
                throw new Exception("NewOrder: grid order without signal_id"); 

            // $ttl = gmdate('Y-m-d H:i:s', time() + 300);  // for debug purpose
            // $acc_id = $this->account_id;
            $params = array('clOrdID' => self::ClientOrderID($info, $this->host_id), 'ordType' => 'Limit', 'symbol' => $symbol,
                            'price' => strval($info->price), 'orderQty' => $info->amount, 'side' => $side); // 'text' => 'Sig#'.$info->batch_id

            if (false !== $hidden) {
                if (is_int($hidden))
                    $params['displayQty'] = $hidden;
                else 
                    $params['displayQty'] = min(rand(0, 5) * $tinfo->lot_size, $info->amount); // TODO: range must be configurable
            }
            if (strlen($info->comment) > 0)
                $params['text'] = $info->comment;

            // $core->LogObj($params, '   ', '~C93 order submit params:~C97');
            $this->SetLastError('');

            $json = $this->RequestPrivateAPI('api/v1/order', $params);
            $res = json_decode($json);
            if (is_object($res) && isset($res->orderID) && !isset($res->error)) {
                $obj = $this->UpdateOrder($info, $res, false, 'post');         
                if ($obj && is_object($obj))
                    return $obj;
                else
                    $this->SetLastError('UpdateOrder returned '.var_export($obj, true));
                return $info;
            }
            else {        
                $core->LogOrder("~C91#FAILED(NewOrder)~C00: API returned %s", $json);
                if (isset($res->error) && isset($res->error->message)) {
                    $emsg = $res->error->message;
                    $this->SetLastError("symbol = $symbol, qty = {$info->amount}, API fails with result {$res->error->name}: {$emsg}");
                    if ('Instrument expired' == $emsg)
                        $tinfo->expired = true;
                }  
                else
                    $this->SetLastError("API fails with result $json");
                $info->status = 'rejected';
                $info->OnError('submit');
                $info->Unregister(null, 'failed');
                $this->DispatchOrder($info);         
                return null;
            }
        }

        public function  CancelOrder(OrderInfo $info): ?OrderInfo {
            $core = $this->TradeCore();
            $params = [];

            if ($info->IsFixed() && 'lost' != $info->status) {
                $this->LogMsg("~C91#WARN:~C00 trying cancel fixed order %s", strval($info));
                return null;
            }
            if (0 == $info->flags & OFLAG_ACTIVE) 
                $this->LogMsg("~C91#WARN:~C00 trying cancel inactive order %s", strval($info));

            $api_valid = strlen($info->order_no) > 24;      
            if ($api_valid)
                $params['orderID'] = $info->order_no;
            else
                $params['clOrdID'] = self::ClientOrderID($info, $this->host_id);

            $json = $this->RequestPrivateAPI('api/v1/order', $params, 'DELETE');
            $res = json_decode($json);
            if (is_array($res)) {
                foreach ($res as $rec)              
                    if (isset($rec->error)) { // типично, заявка уже была отменена извне либо истек TTL              
                        if ($api_valid)  
                            $info->SetFixedAuto();
                        else
                            $info->status = OST_REJECTED;
                        $info->OnError('cancel');  
                        // $rec->error == "Unable to cancel order"
                    }  
                    elseif (is_object($rec) && isset($rec->clOrdID)) {
                        $info = $this->UpdateOrder($rec->clOrdID, $rec, true, 'cancel'); // в случае пакетной отмены, вернет последний успешно отменененный              
                    }   
                    else
                        $core->LogOrder("~C91#FAILED(CancelOrder):~C00 record response: %s", json_encode($rec));        
            } 
            else {
                $core->LogOrder("~C91#FAILED:~C00 API order/DELETE returned %s:\n %s", $json, var_export($res, true));
                $this->SetLastError("API fails with result $json");
                $info->OnError('cancel');
                return null;
            }      
            return $info;
        }

        public function  CancelOrders(mixed $list) {
            $core = $this->TradeCore();      
            if (is_object($list) && method_exists($list, 'RawList'))
            $list = $list->RawList();

            $ids = [];
            foreach ($list as $info)
            if (is_object($info)) {
                if ($info->IsFixed() && 'lost' != $info->status) {
                $this->LogMsg("~C91#WARN:~C00 trying cancel fixed order %s", strval($info));
                continue;
                }
                if (0 == $info->flags & OFLAG_ACTIVE) 
                $this->LogMsg("~C91#WARN:~C00 trying cancel inactive order %s", strval($info));

                if (strlen($info->order_no) > 24)
                $ids []= $info->order_no;
                else 
                $this->CancelOrder($info);        
            }  
            if (0 == count($ids)) return;

            $params = ['orderID' => json_encode($ids)];      
            $json = $this->RequestPrivateAPI('api/v1/order', $params, 'DELETE');
            $res = json_decode($json);
            $ok = 0;
            if (is_array($res)) {
                foreach ($res as $i => $rec)  
                    if (is_object($rec) && isset($rec->clOrdID)) {
                    $info = $this->UpdateOrder($rec->clOrdID, $rec, true, 'cancel'); // в случае пакетной отмены, вернет последний успешно отменененный
                    $ok ++;
                    if ($info) unset($list[$info->id]);
                    }  
                    else 
                    $core->LogOrder("~C91#FAILED(CancelOrders#$i):~C00 record response: %s", json_encode($rec));        
            } 
            else {
                $core->LogOrder("~C91#FAILED:~C00 API order/DELETE returned %s:\n %s", $json, var_export($res, true));
                $this->SetLastError("API fails with result $json");        
                return null;
            }     
            if ( $ok < count($ids))     
                parent::CancelOrders($list);
        }

        public function  MoveOrder(OrderInfo $info, float $price, float $amount = 0): ?OrderInfo {
            $core = $this->TradeCore();
            $tinfo = $this->TickerInfo($info->pair_id);
            if (!$tinfo) {
                $core->LogOrder("~C91#FAILED(MoveOrder)~C00: ticker info not exists for pair %d", $info->pair_id);
                return null;
            }
            if ($info->IsFixed()) {
                $this->LogMsg("~C91#WARN:~C00 trying move fixed order %s", strval($info));
                return $info;
            }
            if (0 == $info->flags & OFLAG_ACTIVE) 
                $this->LogMsg("~C91#WARN:~C00 trying move inactive order %s", strval($info));

            if ('0' == $info->order_no) {
                $this->LogMsg("~C91#WARN:~C00 trying move order without order_no %s", strval($info));
                $info->status = OST_INVALID;
                return $info;
            }

            $shift = abs($price - $tinfo->last_price);
            $tresh = $tinfo->last_price * 0.15;

            if ($price <= $tinfo->last_price * 0.5)
                throw new Exception("MoveOrder: price = $price");

            if ($tinfo->last_price > 0 && $shift > $tresh  && $info->batch_id > 0) {
                $err = sprintf("MoveOrder: price change too big for order %s --> $price relative last price %s %f, shift = %f > %f, source will be canceled", 
                            strval($info), $tinfo->pair, $tinfo->last_price, $shift, $tresh);
                $this->CancelOrder($info);
                throw new Exception( $err );
            }    

            if ($shift < $tinfo->tick_size) {
                $this->LogMsg("~C91#WARN:~C00 price shift %f < tick_size %f for order %s, skipping", $shift, $tinfo->tick_size, strval($info));
                return $info;
            }

            $leaves = floor($info->amount - $info->matched);  
            $leaves = $this->AmountToQty($info->pair_id, $price, $this->btc_price, $leaves);    
            if ($tinfo->ticks_size < 1) {              
                $ticks = $price / $tinfo->tick_size;
                $rp = floor($ticks);        
                if ($rp > 0 && $rp < $ticks) {            
                    $this->LogMsg("~C91#WARN(MoveOrder):~C00 price %s not multiple of tick_size %.10f: %d < %.3f ticks", $tinfo->FormatPrice($price), $tinfo->tick_size, $tinfo->FormatPrice($rp), $rp, $ticks);            
                    $rp *= $tinfo->tick_size;
                    $price = $tinfo->FormatPrice($rp);
                }    
            }
            $params = ['price' => $price]; 
            if ($amount > 0 && $amount != $info->amount) {
                $delta = $amount - $info->amount;
                $info->amount = $amount;
                $params['leavesQty'] = $leaves - $delta; // исполненная часть учитывается, но тут может быть проблема рассинхронизации (полное исполнение остатка заявки до выполнения move)
            }

            
            if (strlen($info->order_no) > 24)
                $params['orderID'] = $info->order_no;
            else
                $params['origClOrdID'] = self::ClientOrderID($info, $this->host_id);
        
            $info->exec_attempts ++; 
            $json = $this->RequestPrivateAPI('api/v1/order', $params, 'PUT');
            $res = json_decode($json);

            if (is_object($res) && isset($res->clOrdID)) {
                $info->was_moved ++;
                return $this->UpdateOrder($info, $res, false, 'move');
            }    
            else {                            
                if ($info->exec_attempts > 5 || isset($res->error) && isset($res->error->message) && 'Invalid orderID' == $res->error->message) {
                    $emsg = $res->error->message;

                    $core->LogError("~C91#WARN(MoveOrder)~C00: API returned %s, for order %s, params: %s", 
                                        $json, strval($info), json_encode($params));


                    if ("Invalid orderID" == $emsg)
                        $info->SetFixedAuto();
                    elseif ('0' != $info->order_no)
                        $info->status = OST_LOST;           
                    else
                        $info->status = OST_REJECTED;                       
                    $info->OnError('move');
                    $info->Unregister(null, 'failed');    
                    $this->DispatchOrder($info);
                    return null;
                }         
                // $info->status = 'lost';         
                $core->LogOrder("~C91#WARN(MoveOrder)~C00: API returned %s, for order %s => %s, leaves = %f, trying cancel", $json, strval($info), json_encode($params), $leaves);                     
                if (str_contains($json,"unchanged"))
                    $core->LogOrder("~C91#ERROR:~C00 Invalid Move from  %s ", format_backtrace());
                $this->CancelOrder($info);
                $info->updated = date_ms(SQL_TIMESTAMP3);
                if ('orderID or origClOrdID must me sent.' == $res->error->message)  
                    throw new Exception("Trying move abnormal order");

                return null;
        }
        }


        private function GetTraceroute(string $server) {
            $server = str_replace('https://', '', $server);
            $server = str_replace('/', '', $server);  
            $out = []; 
            exec("traceroute $server", $out);
            $out = array_slice($out, 0, 5);
            $out = implode("\t\n", $out);
            return "traceroute:\n~C97 $out~C00";
        }

        private function SignRequest(string $rqs): string {
            $secret = implode('', $this->secretKey); // recovery from mem-array
            $secret = trim($secret);
            if (strlen($secret) < 10)
                throw new Exception("Secret key proto to small: ".strlen($secret));
            return hash_hmac('sha256', $rqs, base64_decode($secret));
        }

        public function RequestPrivateAPI($path, $params, $method = 'POST', $sign = true) {
            global $curl_resp_header;
            $core = $this->TradeCore();
            $this->api_params = $params; // save for debug

            $expire_ts = time() + 25;

            $headers = ["api-expires: $expire_ts", "api-key: {$this->apiKey}"];
            $this->EncodeParams($params, $headers, $method);     
            $signed_msg = '';      

            if ($sign) {        
                if ('POST' === $method)
                $signed_msg = sprintf('%s/%s%lu%s', $method, $path, $expire_ts, $params);
                else
                $signed_msg = sprintf('%s/%s?%s%lu', $method, $path,  $params, $expire_ts);
                $signed_msg = trim($signed_msg);
                $this->LogMsg(" signaturing request [%s]", $signed_msg);        
                $headers []= 'api-signature: '.$this->SignRequest($signed_msg);
            }

            $full_path = "$method:$path";

            if (!isset($this->stats_map[$full_path]))
                $this->stats_map[$full_path] = array(1, 0);
            else    
                $this->stats_map[$full_path][0] ++;  // posted requests

            $pdump = $params;  
            $result = $this->RequestPublicAPI($path, $params, $method, $headers, $this->private_api); // using separated API endpoint
            if ($result) {
                $res = json_decode($result);
                $sig_fail = error_test($res,"Signature not valid");
                $ip_strict = error_test($res, 'This IP address is not allowed');
                if ($sig_fail || $ip_strict)  {            
                    $trace = '';
                    if ($ip_strict) 
                    $trace = $this->GetTraceroute($this->public_api);            

                    if ($sig_fail) { 
                        $this->sign_fails ++;
                        $this->stats_map[$full_path][1] ++;
                        $pdump = $signed_msg; // show whole string in error log
                    }    
                    
                    $msg = "~C91#FATAL({$this->sign_fails}):~C00 PrivateAPI returns~C93 $result~C00,\n\tparams~C93 $pdump~C00, API key~C94 {$this->apiKey}~C00, $trace\n\theaders: ~C92".json_encode($headers).'~C00';
                    $msg .= "\n\tstats_map: ~C95 ".print_r($this->stats_map, true).'~C00';
                    $last_good = print_r($this->last_good_rqs, true);
                    $msg .= "last good: ~C93 $last_good~C00";
                    if ($this->sign_fails >= 3 || $ip_strict) {
                    $core->Shutdown("Private API signature fails >= 3");               
                    if ($this->sign_fails >= 10) {
                        $core->LogError("$msg\n to much private API errors, exception will be generated");
                        throw new Exception (format_uncolor('%s', $msg)); 
                    }  
                    else
                        $core->LogError("#FATAL: script will be breaked due high private API fails");
                    }   
                    else  { 
                    $core->LogError($msg);
                    // $this->LogMsg("~C91 #FAILS_MAP:~C97 ".print_r($this->fails_map, true));
                    }   
                }
                else { 
                    $this->sign_fails = 0;
                    $this->last_good_rqs[$full_path] = $pdump;
                    file_put_contents(__DIR__."/data/last_good_rqs@{$this->account_id}.json", json_encode($this->last_good_rqs));
                }  
            }  
            return $result;
        }
    }

    $bot_impl_class = 'BitMEXBOT';
    
    final class BitMEXBOT extends TradingCore {

        public function  __construct() {
            global $mysqli, $impl_name, $g_bot;
            $this->impl_name = $impl_name;
            parent::__construct();
            assert (!is_null($g_bot));
            assert (!is_null($this));
            $this->trade_engine = new BitMEXEngine($this);      
            $this->Initialize($mysqli); 
        }

    };



?>