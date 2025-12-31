<?php
    include_once('../lib/common.php');
    include_once('../lib/db_tools.php');
    require_once('trading_core.php');
    require_once('rest_api_common.php');


    define('DEFAULT_STABLE_COIN', 'USDC');

    function floor_to(float $value, float $divisor) {
        if ($divisor > 0)
          return floor($value / $divisor) * $divisor;
        return $divisor;
    }

    final class BinanceEngine extends RestAPIEngine {

        private   $open_orders = array(); // simple map [id] = true
        private   $exchange_info = '';
        private   $loaded_data = array();
        protected $time_bias = 0;
        protected $external_orders = [];

        protected $last_load = ['orders' => 0, 'tickers' => 0, 'positions' => 0];

        // protected $exchange = 'Binance';    
        public function  __construct($core) {
          parent::__construct($core);
          $this->exchange = 'Binance';

          $key = file_get_contents('.binance.api_key');
          $this->apiKey = trim ($key);
          $this->secretKey = file('.binance.key');
          $this->public_api = 'https://api.binance.com/';
          $this->private_api = 'https://api2.binance.com/';
          
        }

        public function  Initialize() {
            parent::Initialize();
            

            $filename = 'data/exchange_info.json';
            $this->exchange_info = '';

            if (file_exists($filename)) {
                $age = time() - filemtime($filename);
                if ($age < 24 * 3600) {
                    $this->exchange_info = file_get_contents($filename);
                    $this->LOgMsg("~C96#PERF:~C00 exchangeInfo loaded from cache with age %.3f hours", $age / 3600);
                }  
                else
                $this->TradeCore()->LogMsg("$filename is outdated, age = $age seconds");
            }

            if (strlen($this->exchange_info) < 10) {
                $this->TradeCore()->LogMsg("Requesting exchangeInfo update...");
                $update = $this->RequestPublicAPI('api/v3/exchangeInfo', '');
                if ($update && strlen($update) > 100000) {
                    $this->exchange_info = $update;           
                    file_put_contents($filename, $update);
                } else {
                    $this->TradeCore()->LogMsg(" exchangeInfo update may invalid {$this->curl_last_error}: $update");
                }
                
            }
            if (strlen($this->exchange_info) > 10)        
                $this->exchange_info = json_decode($this->exchange_info);                
        }

        private function IsRequestFailed($obj) {
          if (is_array($obj)) return false;
          return (null == $obj) || (isset($obj->code)) && ($obj->code < 0 || $obj->code > 50000);
        }

        protected function SetLastErrorEx ($obj, $text, $code = -1) {
            if ($obj && isset($obj->code)) {        
                if (isset($obj->msg))          
                    $this->SetLastError($obj->msg." $text", $obj->code); 
                else
                    $this->SetLastError($text, $obj->code);        
            }
            else {
                $this->SetLastError($text, $code);        
            }
        }

        protected function SwitchAPIServer(string $current) {
            $core = $this->TradeCore();
            $servers = array('https://api.binance.com/', 'https://api1.binance.com/', 'https://api2.binance.com/', 'https://api3.binance.com/', 'https://api4.binance.com/', 'https://api5.binance.com/');

            $curr = array_search($current, $servers);
            $switch_to = $servers[0];
            if ($curr !== false) {
                $i = ($curr + 1) % count($servers);
                $switch_to = $servers[$i];
            }
            $this->LogMsg("~C91#WARN:~C00 SwitchAPIServer server %s have problems, in list position %s", $current, $curr);

            $eps = array('public_api', 'private_api');
            foreach ($eps as $key) {
                $this->LogMsg("~C91#WARN:~C00 switching~C91 $key~C00 from %s to %s", $this->$key, $switch_to);
                $this->$key = $switch_to;
            }
            return $switch_to;
        }

        private function RequestPrivateAPI(string $rqs, $params, string $method = 'GET', $sign = true) {
            $core = $this->TradeCore();

            if (is_array($params))
                $params = http_build_query($params);

            if (strpos($params, 'timestamp=') === false)
                $params .= sprintf('&timestamp=%lu', time_ms() + $this->time_bias); // must be before signing
            if (strpos($params, 'recvWindow=') === false)
                $params .= '&recvWindow=30000';


            if ($sign)
                $params .= '&signature='.$this->SignRequest($params);

            $headers = ["X-MBX-APIKEY: {$this->apiKey}"];
            $result = $this->RequestPublicAPI($rqs, $params, $method, $headers, $this->private_api);
            if (str_in($result, '"code":-1021') && str_in($result, "ahead of the server's time."))
                $this->time_bias -= 1000;

            // if ($this->curl_response)$this->LogMsg(" response header: {$this->curl_response['headers']}");

            return $result;
        }

        public function LoadTickers() {
            $core = $this->TradeCore();
            parent::LoadTickers();

            $symlist = [];
            foreach ($this->pairs_info as $tinfo) 
                $symlist []= $tinfo->symbol;
            $total_updated = 0;

            if (0 == strlen($core->tmp_dir)) {
                $core->LogError("~C91#ERROR:~C97 tmp_dir not initialized~C00");         
                $core->tmp_dir = '/tmp/binance_bot';
                mkdir($core->tmp_dir, 0775, true);
            }

            file_put_contents($core->tmp_dir.'/pairs_map.json', json_encode($core->pairs_map)); // для построения отчетов

            $symlist = json_encode($symlist);
            $json = $this->RequestPublicAPI('/api/v3/ticker/tradingDay', "symbols=$symlist&type=MINI");
            if (!$json) {
                $core->LogError("~C91#ERROR:~C97 public API request failed: price, %s, %s", $this->curl_last_error, json_encode($this->curl_response));
                $core->LogMsg("\t\t\t as filter used symbol list %s", $symlist);
                return;
            }
            $prices = json_decode($json);
            // $this->LogMsg("prices loaded: $json");
            // filling last_price
            if (is_array($prices))
            foreach ($prices as $rec) {
                $symbol = $rec->symbol;
                if (!isset($this->pairs_map_rev[$symbol])) continue;
                $pair_id = $this->pairs_map_rev[$symbol];
                $tinfo = &$this->pairs_info[$pair_id];
                $tinfo->last_price = $rec->lastPrice;
                $tinfo->daily_vol = $rec->quoteVolume; // in USD or BTC
                $tinfo->OnUpdate();

                if (!isset($tinfo->iceberg_parts)) 
                    $tinfo->iceberg_parts = 10;
                $total_updated ++;
                if (false !== strpos($symbol, 'BTCUSD')) // TODO: use more preferable pair
                    $this->set_btc_price($rec->lastPrice);
            }


            $json = $this->RequestPublicAPI('api/v3/ticker/bookTicker', "symbols=$symlist");

            if (!$json) {
                $this->LogMsg("~C91#ERROR:~C97 public API request failed: bookTicker, %s, %s ~C00", $this->curl_last_error, json_encode($this->curl_response));
                return false;
            }
            $this->last_load['tickers'] = time();
            // $this->LogMsg("bookTicker loaded: $json");

            $book_ticker = json_decode($json);
            // filling results
            if (is_array($book_ticker))
                foreach ($book_ticker as $rec) {
                    $symbol = $rec->symbol;
                    if (!isset($this->pairs_map_rev[$symbol])) continue;
                    $pair_id = $this->pairs_map_rev[$symbol];        
                    $tinfo = &$this->pairs_info[$pair_id];
                    $tinfo->ask_price = $rec->askPrice;
                    $tinfo->bid_price = $rec->bidPrice;
                    if ($tinfo->bid_price > 0 && $tinfo->ask_price > $tinfo->bid_price)
                        $tinfo->mid_spread = ($tinfo->ask_price + $tinfo->bid_price) / 2;
                    else 
                        $tinfo->mid_spread = 0;   
                    $tinfo->OnUpdate();
                    $total_updated ++;        
                }
            else 
                $this->LogMsg("~C91WARN:~C00 unexpected json_decode for: %s, used params: %s", $json, $symlist);

            // синхронизация данных фильтров проводится единожды за сессию
            if (!isset($this->loaded_data['symbol_filters'])) 
            try {        
                if (is_string($this->exchange_info))
                    $this->exchange_info = json_decode($this->exchange_info);

                $einfo = $this->exchange_info;       

                if (is_object($einfo) && isset($einfo->symbols) && is_array($einfo->symbols))
                    $this->LogMsg("~C93#LOAD_TICKERS:~C00 Processing exchange_info data from %d symbols...", count($einfo->symbols));
                else
                    return $total_updated;

                $applied = 0;
                foreach ($einfo->symbols as $obj) {
                    $symbol = $obj->symbol;
                    if (!isset($this->pairs_map_rev[$symbol])) continue;
                    $pair_id = $this->pairs_map_rev[$symbol];
                    $tinfo = &$this->pairs_info[$pair_id];            
                    $tinfo->details = $obj;
                    $tinfo->qty_precision = max($obj->baseAssetPrecision, $obj->quoteAssetPrecision);
                    $found_fltrs = [];

                    $f = array_find_object ($obj->filters, 'filterType', 'PRICE_FILTER');
                    if ($f) {
                        $found_fltrs []= 'PF';
                        $tinfo->tick_size = doubleval($f->tickSize);
                        $tinfo->min_price = doubleval($f->minPrice);
                        $tinfo->max_price = doubleval($f->maxPrice);
                        $tinfo->price_precision = 0;
                        $base = $tinfo->tick_size; // max($tinfo->tick_size, $tinfo->min_price);
                        if ($base < 1)
                            $tinfo->price_precision = TickerInfo::CalcPP($base); // ceil(-log10( $base));                    
                    }

                    $f = array_find_object ($obj->filters, 'filterType', 'LOT_SIZE');
                    if ($f) {
                        $tinfo->lot_size = doubleval($f->minQty);
                        $tinfo->step_size = doubleval($f->stepSize);
                        if ($tinfo->step_size > 0 && $tinfo->step_size < 1)
                            $tinfo->qty_precision = TickerInfo::CalcPP($tinfo->step_size);
                        else
                            $tinfo->qty_precision = TickerInfo::CalcPP($tinfo->lot_size);
                        $found_fltrs []= 'LS';
                    }
                    $f = array_find_object ($obj->filters, 'filterType', 'MIN_NOTIONAL');
                    if ($f && isset($f->minNotional)) {
                        $tinfo->min_notional = $f->minNotional;
                        $tinfo->min_cost = doubleval(max(25, $f->minNotional));
                        $found_fltrs []= 'MN';
                    }  
                    $f = array_find_object ($obj->filters, 'filterType', 'NOTIONAL'); 
                    if ($f && isset($f->minNotional)) {
                        $tinfo->notional = $f->minNotional;
                        $found_fltrs []= 'NL';
                    }    

                    $tinfo->iceberg_parts = 10;

                    $f = array_find_object ($obj->filters, 'filterType', 'ICEBERG_PARTS');  
                    if ($f && isset($f->limit)) {
                        $tinfo->iceberg_parts = $f->limit;
                        $found_fltrs []= 'IP';
                    }    
                    $tinfo->OnUpdate();  
                    $this->LogMsg("~C97#LOT_SIZE~C00 for %10s = %.9f, tick_size = ~C04%.9f, price precision = %d, qty precision = %d, filters = %s", 
                                            $symbol, $tinfo->lot_size, $tinfo->tick_size, $tinfo->price_precision, $tinfo->qty_precision, json_encode($found_fltrs));

                    $applied ++;
                } // foreach
                if ($applied > 0) {
                    $this->LogMsg("~C94#INFO:~C00 applied filters from %d records ", $applied);
                    $this->loaded_data['symbol_filters'] = 1; 
                }    
            } catch (Exception $E) {
                $core->LogError("~C91#ERROR:~C00 unexpected exception %s from:\n %s", $E->getMessage(), $E->getTraceAsString());
            }

            return $total_updated;
        } /// LoadTickers

        public function CancelOrder($info): ?OrderInfo {
            $core = $this->TradeCore();
            if (!isset($info->pair_id)) {
                $core->LogError("~C97#ERROR: invalid OrderInfo object:~C00");         
                throw new Exception("#FATAL: structure mistmatch");
            }

            $this->UpdateSingleOrder($info); // recheck status
            if ($info->IsFixed()) {
                $core->LogOrder('~C91#ERROR:~C00 attempt to cancel [%s] order #%d', $info->status, $info->id);
                $info->Unregister(null, 'cancel-blocked');
                $this->DispatchOrder($info, 'DispatchOrder/cancel-fail');
                return $info;         
            }
            $code = sprintf('Account_%d-IID_%d', $this->account_id, $info->id);
            $pair = $info->pair;
            if (strlen($pair) <= 3)
                $pair = $core->pairs_map[$info->pair_id];

            $params = array('symbol' => $pair);

            // if ($info->order_no > 0) $params['orderId'] = $info->order_no;      else
            $params['origClientOrderId'] = $code;
            $core->LogOrder("~C93#DBG:~C07 trying cancel order  %s ", strval($info)); 
            $this->SetLastErrorEx(false, '', 0);
            $json = $this->RequestPrivateAPI('sapi/v1/margin/order', $params, 'DELETE'); 
            $obj = json_decode($json);
            if ($this->IsRequestFailed($obj)) {
                $core->LogError("~C91#ERROR: SAPI/v1 request order %s DELETE failed:~C97 $json~C00", strval($info));
                $core->LogObj($params, '   ', 'method params: ');
                $this->SetLastErrorEx($obj, $json, -1);
                $info->OnError('cancel');

                $info->error_log []= "SAPI order DELETE failed: $json";
                if ($this->last_error_code == -2013)
                    $info->status = 'lost'; // not existed order
                else
                    $this->UpdateSingleOrder($info); // try assign actual order list

                $this->DispatchOrder($info, 'DispatchOrder/cancel-ok');
                $info->comment .= ', ce';
                if ($obj->code != -2011 || count($info->error_log) > 3)  // lost order
                    return null;
            }
            else {
                // $core->LogOrder("~C93#DBG: cancelOrder result:~C00");
                // $core->LogObj($obj, ' ', 'response: ');
                $info->matched = $obj->executedQty;
                $info->comment .= ', cl';
                $info->status = $info->matched > 0 ? 'partially_filled' : 'canceled';
                $info->Unregister(null, 'cancel-success');
            }

            $info->updated = date_ms(SQL_TIMESTAMP_MS, time_ms());
            $this->UpdateSingleOrder($info);

            if ($info->matched > 0) {
                if ($info->matched == $info->amount)
                    $info->status =  'filled';
                $info->Register($this->matched_orders);
            }
            else {
                $info->status = 'canceled'; // TODO: move to orders archive
                $info->Register($this->archive_orders);
            }

            return $info;
        }

        public function  NewOrder(TickerInfo $ti, array $proto): ?OrderInfo {
            // $proto = $params;

            $pair_id = $ti->pair_id;
            $proto['pair_id'] = $pair_id;

            if (0 == $proto['amount'])
                throw new Exception("Attempt create order with amount == 0");


            $core = $this->TradeCore();
            $info = $this->CreateOrder();  // OrderInfo instance simple
            $info->Import($proto);
            if (!$this->DispatchOrder($info, 'DispatchOrder/new')) {
                $core->LogError("~C91#FAILED:~C00 order was not registered");
                $core->LogObj($proto, '  ', 'proto');
                return null;
            }
            if (0 == $info->id)
                throw new Exception("OrderInfo->id not initialized");

            $info->ticker_info = $ti;

            $symbol = $core->pairs_map[$pair_id];
            $side = $proto['buy'] ? 'BUY' : 'SELL';
            $code = sprintf('Account_%d-IID_%d', $this->account_id, $info->id);

            // adjusting keys in params - recreating
            $amount = $ti->FormatAmount($proto['amount']);
            $min_qty = floatval($amount) / 2;

            $qp = ceil ( 1 + log10($ti->iceberg_parts) - log10($ti->lot_size)); // amount = 1, precision = 5, 10 = 4... etc
            $qp = max($qp, $ti->qty_precision);              

            if ($ti->iceberg_parts > 0) {                    
                $min_qty = floor_to ($amount / $ti->iceberg_parts, $ti->lot_size) ;   
                $min_qty = max($min_qty, $ti->lot_size * $ti->notional);
                $min_qty = $ti->FormatAmount($min_qty);
            }    

            if (0 == $min_qty) {
                $core->LogError("~C91#WARN:~C00 min_qty calculation failed, amount = %f, iceberg_parts = %d, lot_size = %f, precision = %d", $amount, $ti->iceberg_parts, $ti->lot_size, $qp);
                $min_qty = $ti->FormatAmount($amount / 2);            
                $min_qty = max($min_qty, $ti->min_notional);
                
            }    

            $min_qty = floor_to($min_qty, $ti->lot_size);
            $ttl = 3600;
            if (isset($proto['ttl']))
                $ttl = $proto['ttl'];

            $price = $proto['price'];   
            $diff = 100 * ($price - $ti->last_price) / max($price, $ti->last_price);
            $diff = round($diff, 1);
            if ($diff >= 5)
                $this->LogMsg("~C91#WARN:~C00 Price deviation is too high: $diff %");       

            $cost  = $info->Cost();
            $params = array('symbol' => $symbol, 'price' => $ti->FormatPrice($price), 'side' => $side, 'type' => 'LIMIT', 'quantity' => $amount, 'newClientOrderId' => $code, 'timeInForce' => 'GTC');
            $params['sideEffectType'] = 'AUTO_BORROW_REPAY'; // ($side == 'BUY' || $proto['rising']) ? 'MARGIN_BUY' : 'AUTO_REPAY';       
            $params['autoRepayAtCancel'] = 1;
            // $params['goodTillDate'] = (time() + $ttl) * 1000; // will not work with margin API
            $hidden = isset($proto['hidden']) ? $proto['hidden'] : false;
            $parts = 0;
            $large_order = $info->Cost() >= 500;                
            if ($large_order && is_numeric($hidden) && $ti->iceberg_parts > 1 && $hidden < $amount && $min_qty < $amount) {      
                $parts =  ($hidden > $min_qty && $hidden > 0) ? $amount / $hidden : $ti->iceberg_parts;
                $parts = min($parts, $ti->iceberg_parts - 1); 
                $parts = floor($parts);            
                if ($parts > 0)
                    $hidden = floor_to($amount / $parts, $ti->lot_size); // must be multiple of lot  
                else 
                    $hidden = 0;

                if ($hidden < $amount) 
                    $params['icebergQty'] = $hidden;
            }
            elseif ($hidden && $ti->iceberg_parts > 1) {         
                $params['icebergQty'] = 0;
            }       

            $this->SetLastErrorEx(false, '', 0);

            $json = $this->RequestPrivateAPI('sapi/v1/margin/order', $params, 'POST');
            $result = json_decode($json);
            $info->exec_attempts = $this->CountAttempts($info->batch_id);
            if ($this->IsRequestFailed($result)) {
                $curl_err = $this->curl_last_error == 'OK' ? '' : "CURL failed: $this->curl_last_error";                           
                $this->LogMsg("~C91#FAILED:~C00 SAPI margin new order: $json, min_qty = %f, qp = $qp, %s params:~C04 %s", $min_qty, $curl_err, json_encode($params));
                if (-1013 == $result->code) {                
                    $this->LogMsg("~C91\t\t#FAIL_INFO:~C00  ticker notional = %5G, min_notional = %8G, lot size = %8G, used / max iceberg parts = %.3f / %f, cost = %.5f ", 
                                  $ti->notional, $ti->min_notional, $ti->lot_size, $parts, $ti->iceberg_parts, $cost);
                }                          
                $text = sprintf("for %s %s; %s", strval($info), $info->comment, $curl_err);   
                $this->SetLastErrorEx($result, $text , -404);
                $info->Unregister(null, 'failed-post-new');
                $info->error_log []= "SAPI failed post new order: $json";
                $temporary_errors = array(-3045 => 1, -3006 => 1, -2010 => 1);
                if (isset($result->code) && isset($temporary_errors[$result->code])) {
                    $info->status = 'rejected';
                    $info->OnError('submit');
                    $info->Register($this->archive_orders);
                    $core->LogOrder("~C91#WARN:~C00 postpone timer activated for pair $symbol");
                    $core->postpone_orders[$pair_id] = 10;
                    if (-3045 == $result->code) {
                        $this->ne_assets = true;
                        $core->postpone_orders[$pair_id] = 30;
                    }

                    return $info;  // bypass failed result, for prevent exceptions
                }

                return null;
            }

            $core->LogOrder("#RAW_ORDER: ~C93%20s~C00", $json);              
            $info->updated = date_ms(SQL_TIMESTAMP_MS, $result->transactTime - $this->time_bias);
            $info->order_no = $result->orderId;
            $info->matched = $result->executedQty;
            if ($info->matched > 0)
                $info->avg_price = $result->cummulativeQuoteQty / $info->matched; 

            $info->status = strtolower($result->status); // new as active
            if ('filled' === $info->status) {
                $core->LogOrder("~C93#OK: Order fully executed immediately~C00");          
                $this->DispatchOrder($info, 'DispatchOrder/new-filled');
            }
            else {
                $info->OnUpdate();
                $list = $this->MixOrderList('pending,market_maker');
                $core->LogOrder("#POST: order active with status %s, total pending orders %d ", $info->status, count(array_keys($list)));
            }

            sleep(2);
            return $info;
        }

        private function UpdateOrder(\stdClass $order, bool $force_active = false) {
            $core = $this->TradeCore();
            if (!is_object($order)) {
                $core->LogError("~C97#ERROR: UpdateOrder param must be object~C00");  // TODO: cancel lost order
                return false;
            }
            $symbol = $order->symbol;
            if (!isset($this->pairs_map_rev[$symbol])) return false; // not supported in DB config
            $pair_id = $this->pairs_map_rev[$symbol];
            $order_id = $order->clientOrderId;
            $m = array();
            $acc_id = -1;
            if (preg_match('/Account_(\d*)-IID_(\d*)/', $order_id, $m)) {
                $acc_id   = $m[1];
                $order_id = $m[2];
            }
            if ($acc_id != $this->account_id) return false; // another bot order             

            $info = $this->FindOrder($order_id, $pair_id);       

            if (!$info) {        
                if (!isset($this->external_orders[$order_id]))
                    $this->external_orders[$order_id] = 1;
                else  
                    $this->external_orders[$order_id] ++;

                if (2 == $this->external_orders[$order_id])
                    $core->LogOrder("~C91#WARN:~C00 order #%d is not present in lists, Update rejected. Market as external", $order_id);  // TODO: cancel lost order
                return false;
            }
            $was_fixed = $info->IsFixed();
            $this->open_orders[$order_id] = true;
            unset($this->external_orders[$order_id]);

            $cur_status = strtolower($order->status);
            if (strpos($cur_status, 'expired_in_match' !== false))
                $cur_status = 'rejected'; // TODO: need re-explain. Order was canceled by exchange to prevent self trading
            
            $utime = $order->updateTime - $this->time_bias;
            
            if ($force_active && $info->IsFixed()) {
                $core->LogOrder("~C91#WARN:~C00 wrong fixed order %s restored to active state", $info);
                $info->Unregister(null, 'fixed-to-active');
                $info->flags &= ~OFLAG_FIXED;
                $info->status = 'active';          
                $info->ts_fix = null;
            }

            $is_changed = (strtotime_ms($info->updated) < $utime);
            $is_changed |= ($info->matched !== $order->executedQty);
            $is_changed |= ($info->status !== $cur_status);
            // updating order info
            $info->amount = $order->origQty;
            $info->matched = $order->executedQty;
            $info->order_no = $order->orderId;
            $this->updated_orders[$info->id] = $info;

            if ($info->matched)
                $info->avg_price = $order->cummulativeQuoteQty / $info->matched;

            if ($is_changed ) {
                if (is_numeric($info->updated))
                    $info->updated = date_ms(SQL_TIMESTAMP_MS, max($utime, $info->updated));
                else  
                    $info->updated = date_ms(SQL_TIMESTAMP_MS, $utime);    
            }    

            $info->set_status($cur_status, true);  // это реальный статус, можно обходить проверки
            if (!$this->DispatchOrder($info, 'DispatchOrder/update'))
                    $core->LogError("~C91#FAILED_DISPATCH:~C00 order %s, source %s  ", $info, json_encode($order));
            if ($info->IsFixed()&& !$was_fixed)
                $info->ts_fix = $info->updated;

            if ('filled' != $info->status) 
                $info->OnUpdate();
            return $is_changed;
            }

            private function UpdateSingleOrder(OrderInfo $info) { // request actual info via API
            $core = $this->TradeCore();
            $pair = $core->FindPair($info->pair_id);
            $params = array('symbol' => $pair);
            $code   = sprintf('Account_%d-IID_%d', $this->account_id, $info->id);
            if ($info->order_no > 0)
                $params ['orderId'] = $info->order_no; // exchange id
            else
                $params ['origClientOrderId'] = $code;

            if ($info->IsFixed())  
                return true; // fixed orders not need update

            $core->LogOrder(" checking pending order [%s] ", strval($info));
            $this->SetLastErrorEx(false, '', 0);
            while(true) {
                $json = $this->RequestPrivateAPI('sapi/v1/margin/order', $params);
                $order = json_decode($json);
                if ($this->IsRequestFailed($order)) {          
                $core->LogError("~C91#ERROR:~C00 SAPI request [GET margin/order] failed: ~C97$json~C00, params = ".dump_r($params));
                $this->SetLastErrorEx($order, $json, -1);

                if (-2013 == $order->code && isset($params ['orderId'])) {
                    $core->LogOrder("#DBG: trying update order via client-id %s", $code);
                    unset($params['orderId']);
                    $params ['origClientOrderId'] = $code;
                    continue;
                }
                $info->error_log []= "SAPI order GET failed: $json";
                if (count($info->error_log) < 5)
                    break; // schedule next attempt

                if ($info->matched > 0)
                    $info->status = OST_TOUCHED;
                else
                    $info->status = OST_LOST;

                $info->Unregister(null, 'not-found/lost, errors = '.count($info->error_log));
                if (0 == $info->matched)
                    $info->Register($this->archive_orders);
                else
                    $info->Register($this->matched_orders);
                return false;
                }
                else
                return $this->UpdateOrder($order);       
            }

        }

        private function ProcessOrders($json, $rqs) {
            $core = $this->TradeCore();
            $updated = 0;      
            if ($json)  {
                $list = json_decode($json);
                if (is_object($list) && $this->IsRequestFailed($list)) {
                $this->SetLastErrorEx($list, $json, -1);                      
                $core->LogError("~C91#ERROR:~C00 SAPI request [$rqs] failed:~C97 $json~C00, %s ", dump_r($list));
                if (-2015 == $list->code)
                    $this->SwitchAPIServer($this->private_api);
                return false;
                }

                if (is_array($list)) {
                $open_cnt = count($list);
                foreach ($list as $order)
                    if ($this->UpdateOrder($order, strpos($rqs, 'openOrders') !== false))
                        $updated ++;

                $ids = array_keys($this->open_orders);
                $this->LogMsg("~C93#DBG:~C00 total open margin orders = $open_cnt, updated = $updated, mapped = %s", json_encode($ids));
                return $list;
                }
                else
                $this->LogMsg("~C91WARN:~C00 invalid json_decode for: $json");
            }
            else 
                $core->LogError("~C91#FAILED:~C00 processOrders rqs = '$rqs', %s, %s", $this->curl_last_error, json_encode($this->curl_response));
            return false;
        }

        public function  LoadOrders(bool $force_all) {
            $core = $this->TradeCore();
            $this->LogMsg("loading orders from SAPI...");
            $this->open_orders = []; // reset before get open orders
            $this->SetLastErrorEx(false, '', 0);
            
            $params = ''; 
            $json = ''; 
            $plist = $this->MixOrderList('pending,market_maker');
            
            $week_ago = floor(time()  / 3600) * 3600  - 7 * 24 * 3600;
            $week_ago *= 1000;

            $elps = time() - $this->last_load['orders'];
            
            if ($force_all) {
                $ep = 'margin/allOrders';        
                $need_pairs = [];
                foreach ($plist as $info) {         
                if (!$info->ticker_info) continue;
                $pair = $info->ticker_info->pair;
                $need_pairs[$pair] = 1;
                }    

                foreach ($need_pairs as $pair => $v) {
                $this->LogMsg("~C93#LOAD_ORDERS:~C00 request all for pair ~C92 $pair~C00...");
                $params = array('symbol' => $pair );  // TODO: actualize many symbols!
                $json = $this->RequestPrivateAPI('sapi/v1/'.$ep, $params);   
                $this->ProcessOrders($json, $ep); 
                usleep(50000); // 50 ms
                } 

            } else { // request only active     
                $trace = [];
                if ($elps < 10) {
                    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                    foreach ($trace as $i => $rec)
                        $trace[$i] = "\n\t\t{$rec['file']}:{$rec['line']} in  {$rec['function']}";
                    $this->LogMsg("~C93#LOAD_ORDERS:~C00 request all open orders %s ...", implode("", $trace));                       
                }            
                $ep = 'margin/openOrders'; 
                $json = $this->RequestPrivateAPI('sapi/v1/'.$ep, '');
                $this->ProcessOrders($json, $ep);
            }  

            $this->last_load['orders'] = time();
            $updated = 0;    

            // TODO: scan pending vs open, due full match or kill possibilty
            $plist = $this->MixOrderList('pending,market_maker');            
            $count = count(array_keys($plist));
            if ($count)
                $this->LogMsg(" checking registered orders, size = %d, retrieved open = %d", $count, count($this->open_orders));

            foreach ($plist as $info)
                if (!isset($this->open_orders[$info->id])) {

                if ($info->id < 0) {
                    $core->LogError("~C97#ERROR:~C00 Lost order in pending_orders, id = {$info->id}");
                    $info->Unregister(null, 'lost');
                    $this->DispatchOrder($info, 'DispatchOrder/load');
                    continue;
                }

                // detected lost order, getting addtional info
                if ($this->UpdateSingleOrder($info))
                    $updated ++;
            }
            return true;
          // if ($updated) $this->pending_orders->SaveToDB();
        }

        protected function DetectPair(string $asset, string &$symbol): int {      
            $symbol = $asset.DEFAULT_STABLE_COIN;
            if (isset($this->pairs_map_rev[$symbol]))       
                return $this->pairs_map_rev[$symbol];        
            $symbol = $asset.'BTC';
            if (isset($this->pairs_map_rev[$symbol]))       
                return $this->pairs_map_rev[$symbol];        
            $symbol = 'unknown?';      
            return -1;      
        }

        public function  LoadPositions() { // request from exchange account/sub-account
            parent::LoadPositions();
            $core = $this->TradeCore();
            $this->LogMsg("~C97loading account info from SAPI...~C00");
            $this->LogMsg(" current local/server time_bias = %.3f seconds ", $this->time_bias / 1000.0);
            $json = $this->RequestPrivateAPI('sapi/v1/margin/account', '');
            if (!$json) {
                $core->LogError("~C91#FAILED:~C00 request account info for exchange %s: %s, %s", $this->exchange, $this->curl_last_error, json_encode($this->curl_response));
                return false;
            }
            $this->last_load['positions'] = time();
            file_put_contents('data/margin_account.json', $json);
            $info = json_decode($json);
            $borrowed = array();

            $btc_price = $this->get_btc_price();
            // parsing assets
            if ($info && isset($info->userAssets)) {
                $current_pos = array();
                $core->total_btc = $info->totalNetAssetOfBtc;
                $core->total_funds = $btc_price * $core->total_btc;
                $core->used_funds = max(0, $info->marginLevel);
                $symbol = '';
                $applied = 0;
                
                foreach ($info->userAssets as $rec)
                if ($rec->netAsset != 0) {            
                    $pair_id = $this->DetectPair($rec->asset, $symbol);
                    if ($pair_id <= 0) {
                    // $this->LogMsg("~C91#WARN:~C00 LoadPosition skip %s as unregistered", $rec->asset);
                    continue;
                    } 
                    $applied ++;
                    $current_pos[$pair_id] = $rec->netAsset;
                    if (isset($core->postpone_orders[$pair_id]) && $core->postpone_orders[$pair_id] > 0)
                    $this->LogMsg("#DBG: LoadPosition for %s = %.5f", $symbol, $rec->netAsset);

                    if ($rec->borrowed != 0)
                    $borrowed[$symbol] = $rec->borrowed;
                } // for if
                $this->LogMsg("~C95 #LOAD_POS:~C00 processing %d updating records, applied %d ", count($info->userAssets), $applied);
                $this->LogMsg('~C94 #INFO:~C00 borrowed now:\n %s~C97', print_r($borrowed, true));
                $core->ImportPositions($current_pos); // just overwrite
            } else
                $this->LogMsg("~C91#WARN:~C00 no userAssets in account info: %s", $json);
            return true;
        } // LoadPosition

        public function PlatformStatus() {
            $this->time_bias = 0;
            $core = $this->TradeCore();
            $json = $this->RequestPublicAPI('/api/v3/time', '');
            $obj = json_decode($json);
            $day_start = strtotime(date('Y-m-d 0:00:00'));
            if ($obj && isset($obj->serverTime) && $obj->serverTime >= $day_start * 1000)
                $this->time_bias = time_ms() - $obj->serverTime; // positive if server time late to local
            else
                $core->LogObj($obj, ' ' , "~C91#ERROR:~C00 API time returned:~C97 ");

            $json = $this->RequestPublicAPI('/sapi/v1/system/status', ''); // wapi/v3/systemStatus.html
            $obj = json_decode($json);
            if ($obj && isset($obj->status))
                return (1 - $obj->status);
            else
                $core->LogObj($obj, ' ' , "~C91#ERROR:~C00 System status returned:~C97 ");

            return 0;
        }

        private function SignRequest(string $rqs) {
            $secret = implode('', $this->secretKey);
            $secret = base64_decode($secret.'==');
            return hash_hmac('sha256', $rqs, $secret);
        }


    }; // BinanceEngine


    $bot_impl_class = 'BinanceBOT';


    final class BinanceBOT extends TradingCore {
        public function  __construct() {
            global $mysqli;
            $this->impl_name = 'binance_bot';
            parent::__construct();      
            $this->trade_engine = new BinanceEngine($this);
            $this->Initialize($mysqli);
        }
    };

    
?>