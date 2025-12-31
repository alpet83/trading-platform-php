<?php
    include_once('../lib/common.php');
    include_once('../lib/db_tools.php');
    require_once('trading_core.php');
    require_once('rest_api_common.php');

    define('SATOSHI_MULT', 0.00000001);
    define('BITCOIN_TICKER', 'BTC-PERPETUAL');
    define('ETHER_TICKER',   'ETH-PERPETUAL');

    final class DeribitEngine extends RestAPIEngine {

        private   $open_orders = array();
        private   $loaded_data = array();
        private   $clientId =  '';
        private   $api_params = array();
        private   $oauth_token = false;
        private   $oauth_refresh_token = false;
        private   $oauth_expires = 0;
        private   $instruments = array();

        private   $history_by_instr = []; // map by pair
        private   $open_by_instr = [];  // map by pair    

        public    $eth_price = 0;
        public    $platform_version = 'n/a';
        public    $server_time  =  0;
        public    $diver_time   = 0; // server to local time divergence

        public function __construct($core) {
            parent::__construct($core);
            $this->exchange = 'Deribit';
            $env = getenv();
            if (isset($env['DBT_API_KEY'])) {
            $this->clientId = trim($env['DBT_API_KEY']);
            $this->LogMsg("#ENV: Used clientId {$this->clientId}");         ;
            $this->secretKey = explode("\n", trim($env['DBT_API_SECRET']));
            
            } else {
            $clid = file_get_contents('.deribit.clid');
            $this->clientId = trim($clid);
            $this->secretKey = file('.deribit.key');        
            if (!$this->secretKey)
                throw new Exception("#FATAL: can't load private key!\n");
            } 
            $this->public_api = 'https://www.deribit.com';
            $this->private_api = 'https://www.deribit.com';
            $this->last_nonce = time_ms() * 11;
            $this->oauth_refresh_token = file_get_contents('.oauth_refresh_token');
        }

        public function Cleanup() {
            if ($this->oauth_token)
                $this->RequestPrivateAPI('/api/v2/private/logout', '');
            parent::Cleanup();
        }

        private function ProcessError($context, $json, $res): bool  {
            $core = $this->TradeCore();
            if (is_object($res) && isset($res->error)) {
            $this->last_error = $res->error->message;
            $this->last_error_code = $res->error->code;

            if (13009 == $res->error->code && isset($res->error->data) && isset($res->error->data->reason) && $res->error->data->reason == 'invalid_token') {
                $core->LogError("~C91#OAUTH_BREAKS:~C00 token %s is'nt valid now", $this->oauth_token);
                $this->oauth_token = false;
            }
            return true;
            } else {
            $this->last_error = sprintf($context, $json);
            $this->last_error_code = -324;
            return false;
            }
        }

        function LoadInstruments ($currency = false, $kind = 'future') {
            $core = $this->TradeCore();
            $this->last_error = '';
            $params ['kind'] = $kind;
            if ($currency)
                $params['currency'] = $currency;

            $json = $this->RequestPublicAPI('/api/v2/public/get_instruments', $params); // TODO: for all currencies
            $res = json_decode($json);


            $ignored = array();

            $pmap = $this->pairs_map_rev;
            if (!is_array($pmap) || 0 == count($pmap)) {
                $core->LogError("~C91#ERROR:~C00 pairs_map_rev not initialized, LoadTickers can't work");
                $this->last_error = "pairs_map_rev not init.";
                return false;
            }

            $this->LogMsg(" acceptable instruments: %s ", implode(',', array_keys($pmap)));

            $list = array();
            if (is_object($res) && is_array($res->result))
                $list = $res->result;
            else {
                $core->LogError("~C91#FAILED:~C00 public API instruments/active returned %s,\n list: %s", $json, var_export($res));
                $this->ProcessError("instrument/active returned %s", $json, $list);
                return false;
            }

            $loaded = 0;
            foreach ($list as $obj) {
                $sym = $obj->instrument_name;
                    if (!isset($pmap[$sym])) {
                    $ignored []= $sym;
                    continue;
                }
                $pair_id = $pmap[$sym];
                $this->instruments[$pair_id] = $obj; // save full info
                $tinfo = $this->TickerInfo($pair_id);
                

                $tinfo->lot_size   = $obj->min_trade_amount;
                $tinfo->tick_size  = $obj->tick_size;
                $tinfo->base_currency = $obj->base_currency;
                $pp = -log10($tinfo->tick_size);
                if ($pp - floor($pp) > 0) 
                    $pp = floor($pp) + 1; // round up always

                $tinfo->price_precision = $tinfo->tick_size < 1 ? $pp : 0;
                $tinfo->contract_size = $obj->contract_size;        
                if (isset($obj->future_type)) {
                    $tinfo->is_inverse = ('reversed' == $obj->future_type);
                    $tinfo->is_linear = ('linear' == $obj->future_type);  
                }   

                file_put_contents("data/$sym.info", print_r($obj, true));                

                $loaded ++;
            } // foreach

            $this->LogMsg(" ignored instruments: %s ", implode(',', $ignored));
        }

        public function LoadTickers() {
            parent::LoadTickers();
            if (0 == $this->PlatformStatus()) return 0;

            $core = $this->TradeCore();

            $this->LogMsg(" trying acquire active instrument list from public API...");
            if (0 == count($this->instruments)) {
                $this->LoadInstruments();         
            }
            $updated = 0;

            // Loading real-time data
            foreach ($this->instruments as $pair_id => $instr_info) {
                $instr_name = $instr_info->instrument_name;
                $json = $this->RequestPublicAPI('/api/v2/public/ticker', "instrument_name=$instr_name");
                $obj = json_decode($json);
                if (!is_object($obj) || !isset($obj->result)) {
                    $core->LogError("API request `ticker`, failed decode response %s ", $json);
                    continue;
                }
                file_put_contents("data/$instr_name.last", print_r($obj, true));
                $obj = $obj->result;
                $tinfo = $this->TickerInfo($pair_id);

                $tinfo->bid_price  = $obj->best_bid_price;
                $tinfo->ask_price  = $obj->best_ask_price;
                $tinfo->last_price = $obj->last_price;        
                $tinfo->fair_price = $tinfo->index_price = $obj->index_price;        

                if (isset($obj->quote_currency))
                    $tinfo->quote_currency = $obj->quote_currency;

                if (isset($obj->settlement_currency))  
                    $tinfo->settl_currency = $obj->settlement_currency;

                if (isset($obj->settlement_price))
                    $tinfo->settl_price = $obj->settlement_price;
                if (isset($obj->stats) && isset($obj->stats->volume_usd))
                    $tinfo->daily_vol = $obj->stats->volume_usd;

                $tinfo->OnUpdate($obj->timestamp);        
                if (BITCOIN_TICKER === $instr_name)
                    $this->set_btc_price($obj->last_price);
                if (ETHER_TICKER == $instr_name)
                    $this->eth_price = $obj->last_price;

                $updated ++;
            }

            return $updated;
        } // LoadTicker

        public function AmountToQty(int $pair_id, float $price, float $btc_price, float $value) { // from Amount to real ammount
            if (0 == $value || 0 == $price)
                return $value;

            $this->last_error = '';
            $tinfo = $this->TickerInfo($pair_id);

            if (!$tinfo) {
                $this->last_error = "no ticker info for $pair_id";
                return 0;
            }
            if ($tinfo->is_linear)
                return $value;

            $core = $this->TradeCore();      
            if ($tinfo->is_inverse && $price > 0)
                return $value / $price;  // 1900 / 1900 = 1

            if (0 == $price) {
                $this->last_error = " price == 0, pair_id = $pair_id";
                return 0;
            }
            $this->last_error = sprintf('not available method for %s ', $tinfo->pair);
            return 0.000000001;
        }
        public function QtyToAmount(int $pair_id, float $price, float $btc_price, float $value) { // from real amount to Amount
            $tinfo = $this->TickerInfo($pair_id);
            if (!$tinfo)
                return 0;

            if ($tinfo->is_linear)
                return $value;

            if ($tinfo->is_inverse)
                return floor($value * $price);
            return $value;  
        }

        public function  LimitAmountByCost($pair_id, $amount, $max_cost) {
            $tinfo = $this->TickerInfo($pair_id);
            $core = $this->TradeCore();
            if (!$tinfo || 0 == $tinfo->last_price) {
                $pair = $core->FindPair($pair_id);
                $this->last_error = "#FATAL: no ticker for pair_id $pair_id [$pair]";
                $this->LogMsg("~C91#WARN(LimitAmountByCost):~C00 Failed get info for pair %d %s", $pair_id, $pair);
                return 0;
            }

            $price = $tinfo->last_price;
            $qty = $this->AmountToQty($pair_id, $price, $this->get_btc_price(), $amount);
            $cost = $qty * $price;
            $result = $amount;

            if ($cost > $max_cost) {
                $limited = $max_cost / $price;
                if (is_infinite($limited))
                throw new Exception("LimitAmountByCost failed calculate amount from $max_cost / $price");

                $result = $this->QtyToAmount($pair_id, $price, $this->get_btc_price(), $limited);
                $result = $tinfo->FormatAmount($result);
                $this->LogMsg ("~C91#WARN:~C00 for %s due cost %.1f$ > max limit %d, quantity reduced from %s to %s, price = %10f, amount = %.5f, limited = %.5f", $tinfo->pair, $cost, $max_cost, $qty, $result, $price, $amount, $limited);
            }
            return $result;
        }

        public function NativeAmount(int $pair_id, float $amount, float $ref_price, float $btc_price = 0) {
            return $this->QtyToAmount($pair_id, $ref_price, $btc_price, $amount); // used values fixed after position feed changes
        }

        private function DecodeOrderStatus($st) {
            $st = strtolower($st);
            $st = str_ireplace('partiallyfilled', 'partially_filled', $st);
            $st = str_ireplace('open', 'active', $st);
            return $st;
        }

        private function FormatOrderNo(string $order_no, string $base_currency) {
            if ('-' == $order_no[0] || is_numeric($order_no) ||
                !str_contains($order_no, $base_currency)) {
                $order_no = ltrim($order_no, '-'); // remove excess -
                return "{$base_currency}-$order_no";            
            }
            return $order_no;
        }
        
        private function UpdateOrder($target, $order = false) {
            $core = $this->TradeCore();
            if (!$target)
            throw new Exception("FATAL: UpdateOrder(Target == NULL)!");

            $info = $target;
            $pending = $this->MixOrderList('pending,market_maker');
            if (is_numeric($target)) {
                if (isset($pending[$target]))
                    $info = $pending[$target];
                else 
                    $info = $this->FindOrder($target, 0, true);

                if (is_null($info)) {
                    $this->LogMsg("~C91#WARN(UpdateOrder):~C00 order not found for #%d", $target);
                    return null;
                }
            }   


            if (!is_object($info)) {
                $core->LogError("~C91#ERROR(UpdateOrder):~C00 invalid 'target' parameter used %s", var_export($target));
                var_dump($info);
                return null;
            }


            if (!is_object($order)) {
                $order = $this->LoadSingleOrder($info);
                if (!is_object($order) || !property_exists($order, 'order_id')) return null;
            } // if false == order

            // filling dynamic fields here
            $info->price = $order->price;
            $info->avg_price = $order->average_price;      
            $ti = $info->ticker_info;
            $info->order_no = $this->FormatOrderNo($order->order_id, $ti->base_currency);                 
            
            $st = $this->DecodeOrderStatus($order->order_state);  
            $mlast = ('filled' == $st) ? $order->amount : $order->filled_amount; // may filled_amount can be incorrect?
            $info->matched = max($info->matched, $mlast); // prevent set status as partally_filled
            $info->status = $st;     
            $info->checked = date_ms(SQL_TIMESTAMP3); 
            $utm = $order->last_update_timestamp;
            $uts = date_ms(SQL_TIMESTAMP3, $utm);
            if ($uts > $info->updated) {
                $info->updated = $uts;
                $this->updated_orders[$info->id] = $info;
                if ($info->batch_id > 0)
                    $core->LogOrder("~C93#DBG(UpdateOrder):~C00 order %s updated time %s from %s", strval($info), $uts, json_encode($order));
            }
            // $core->LogOrder("~C95#DBG(UpdateOrder):~C00 order %s time not updated, %s <= %s", strval($info), $uts, $info->updated);
            $this->DispatchOrder($info);
            $info->OnUpdate();
            return $info;
        } // UpdateOrder

        private function LoadSingleOrder(OrderInfo $info): mixed {
            $core = $this->TradeCore();
            $json = '';  
            if (!isset($core->pairs_map[$info->pair_id])) {
                $core->LogError("#FAILED(LoadSingleOrder): not entry in pairs_map for %s", strval($info));
                return null;
            }
            $pair = $core->pairs_map[$info->pair_id];
            $tinfo = $this->TickerInfo($info->pair_id);
            $label = $this->OrderLabel($info);
            $params = [];
            if (strlen($info->order_no) >= 5) {
                if (is_numeric($info->order_no))
                    $params['order_id'] = "{$info->quote_currency}-{$info->order_no}";
                else  
                    $params['order_id'] = $info->order_no;
                $json = $this->RequestPrivateAPI('/api/v2/private/get_order_state', $params, 'GET');
            } else {
                $params['label'] = $label;
                $params['currency'] = $tinfo->quote_currency;
                $json = $this->RequestPrivateAPI('/api/v2/private/get_order_state_by_label', $params, 'GET');
            }
            $res = json_decode($json);

            if (!is_object($res) || !property_exists($res, 'result')) {        
                $params = ['instrument_name' => $pair, 'count' => 100];
                $eps = ['get_open_orders' => 'open_by_instr', 'get_order_history' => 'history_by_instr'];        
                $this->LogMsg("~C91#PERF_WARN:~C00 trying scan for %s, currency %s in history by instrument", strval($info), $tinfo->quote_currency); 
                foreach ($eps as $ep => $map_name)  {          
                    $json = '';           
                    if (!isset($this->$map_name[$pair])) { // lookup cache
                        $json = $this->RequestPrivateAPI("/api/v2/private/{$ep}_by_instrument", $params, 'GET');            
                        $res = json_decode($json);
                        $this->$map_name[$pair] = $res;
                    }  
                    else 
                        $res = $this->$map_name[$pair];

                    if (is_object($res) && property_exists($res, 'result') && is_array($res->result)) {
                        foreach ($res->result as $rec)
                            if ($rec->label == $label) {
                                $this->LogMsg(" ~C94 #FOUND:~C00 source for order in %s: %s", $map_name, json_encode($rec));
                                return $rec;
                            }   
                        $this->LogMsg("  ~C94 #NOT_FOUND:~C00 by API retrieved %d orders for $ep  %s ", count($res->result), $pair);    
                    } elseif (property_exists($res, 'error') && strlen($json) > 0)
                        $this->ProcessError('LoadSingleOrder', $json, $res);
                }                       
                if ('lost' == $info->status) {           
                    if ($info->OnError('load') > 3) {
                        $info->status = 'rejected';
                        $this->DispatchOrder($info);
                    }   
                    return null;
                }   
                if (property_exists($res, 'error'))
                    $this->ProcessError('LoadSingleOrder', $json, $res);
                $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 get_order_history[by_instrument] returned %s, request %s", $json, $this->last_request);                  
                $info->status = 'lost'; 
                $info->Register($this->archive_orders);          
                return null;
            }

            if (!is_object($res) || !property_exists($res, 'result')) {  
                if ('lost' == $info->status) return null;
                $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 get_order_state[by_label] returned %s, request %s", $json, $this->last_request);          
                if (property_exists($res, 'error'))
                    $this->ProcessError('LoadSingleOrder', $json, $res);
                elseif (is_array($res->result) && 0 == count($res->result)) {           
                    $info->status = 'lost'; 
                    $info->Register($this->archive_orders);          
                }   
                return null;
            }
            $order = $res->result;
            if (is_array($res->result) && count($res->result) > 0)
                $order = $res->result[0];

            if (is_array($order)) {
                $sp = json_encode($params);
                if (count($order) > 0)
                    $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 Returned for $sp: $json"); 
                else {  
                    $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 order lost $sp, result: $json"); 
                    $info->Register($this->archive_orders);
                }            
                return null;  
            }  
            if (!property_exists($order, 'order_state')) {
                $core->LogError("~C91#FAILED(LoadSingleOrder):~C00 invalid order record %s", $json);
                return null;
            }
            return $order;
        }


        private function LoadOpenOrders(mixed $currency = false, string $kind = 'future') {
            $json = '?';
            $params = ['kind' => $kind];
            $path = '/api/v2/private/get_open_orders';
            if ($currency) {
                $path = '/api/v2/private/get_open_orders_by_currency';
                $params['currency'] = $currency;
            }       
            $json = $this->RequestPrivateAPI($path, $params , 'GET');

            $res = json_decode($json);
            if (is_object($res) && isset($res->result) && is_array($res->result))
            $res = $res->result;
            else {
                $this->last_error = 'API private/orders returned $json';
                return -1;
            }

            if (false === $currency)
                $currency = 'all';

            file_put_contents("data/$kind-open_orders-$currency.json", $json);

            $active = $this->MixOrderList('pending,market_maker');
            $loaded = 0;
            $pmap = $this->pairs_map_rev;

            foreach ($res as $order)
            if (is_object($order)) {
                $instr_name = $order->instrument_name;
                if (!isset($pmap[$instr_name])) continue;
                $m = array();
                list($account, $id) = sscanf($order->label, "%d:%d");
                if ($account == $this->account_id && $id > 0 && isset($active[$id])) {
                    $loaded ++;
                    $this->open_orders [$id] = $this->UpdateOrder($id, $order);
                }
            }
            return $loaded;
        }

        private function OrderLabel($info) {
            return "{$this->account_id}:{$info->id}:{$info->batch_id}";
        }

        public function  LoadOrders(bool $force_all = false) {
            $this->open_orders = [];
            $loaded = 0;
            $this->history_by_instr = [];
            $this->open_by_instr = [];
            $active = $this->MixOrderList('pending,market_maker,lost');

            $this->LoadOpenOrders('BTC');
            $this->LoadOpenOrders('ETH');
            // TODO: processing completed orders
            $core = $this->TradeCore();      

            $lost_count = 0;
            foreach ($active as $oinfo) {
            if (isset($this->open_orders[$oinfo->id])) continue; // OK, is updated
            if ($oinfo->error_map['load'] >= 10) continue;

            if ($oinfo->status == 'lost' && strlen($oinfo->order_no) < 5) {
                $lost_count ++;
                if ($lost_count >= 5) continue; // не пытаться все потеряшки обновить за раз
            }

            if ($this->UpdateOrder($oinfo, false)) // update with request
                $loaded ++;                   
            else {
                $elps = time_ms() - strtotime_ms($oinfo->checked);
                $elps /= 1000;           
                if (!$oinfo->IsFixed() && 'lost' !== $oinfo->status) {
                    $core->LogOrder("~C91#ORDER_LOST:~C00 %s was not checked %.1f seconds, not retrieved from API. Marked as lost", strval($oinfo), $elps);
                    $oinfo->status = 'lost';        
                }   
                $this->DispatchOrder($oinfo);
            }    
            }

            return $loaded;
        }

        private function GetPositions($currency, $kind = 'future') {
            $core = $this->TradeCore();
            $json = $this->RequestPrivateAPI('/api/v2/private/get_positions', array('currency' => $currency, 'kind' => $kind), 'GET');
            $obj = json_decode($json);
            $pmap = $this->pairs_map_rev;

            $file_prefix = "{$this->exchange}@{$this->account_id} $currency $kind ";

            if (is_object($obj) && isset($obj->result) && is_array($obj->result)) {
            $dump = $obj->result;
            if (1 == count($dump)) $dump = $dump[0]; // single is typical
            file_put_contents('data/'.$file_prefix."positions.json", json_encode($dump));
            $result = $core->current_pos;

            foreach ($obj->result as $pos)
            if (is_object($pos) && isset($pos->instrument_name)) {
                $iname = $pos->instrument_name;
                if (!isset($pmap[$iname])) continue;
                $pair_id = $pmap[$iname];
                //
                $rpos = $result[$pair_id];
                if ($rpos->amount == $pos->size) continue;
                $rpos->avg_price = $pos->average_price;
                $rpos->set_amount($pos->size, $rpos->avg_price, $this->btc_price);
                $rpos->ref_qty = $pos->size_currency;
                $this->LogMsg ("~C97#POS_CHANGED:~C00 for $iname %s", strval($rpos));
                file_add_contents("data/$file_prefix-$iname-pos.log", strval($rpos)."\n");          
                // $result[$pair_id] = $rpos;
            }        
            $core->current_pos = $result;
            return count ($result);
            }
            else {
            $core->LogError("~C91#FALED:~C00 private API positions returned %s", $json);
            return -1;
            }
        }

        private function GetAccountSummary($currency, &$map) {
            $this->last_error = '';
            $core = $this->TradeCore();
            $file_prefix = "{$this->exchange}@{$this->account_id} $currency";
            $json = $this->RequestPrivateAPI('/api/v2/private/get_account_summary', array('currency' => $currency), 'GET');
            $obj = json_decode($json);
            $out = array();

            if ($obj && isset($obj->result) && isset($obj->result->balance) ) {
                file_put_contents('data/'.$file_prefix."_margin.json", $json);
                $out['balance'] = $obj->result->margin_balance;
                $out['avail_funds']  = $obj->result->available_funds;
                $out['used_balance'] = $obj->result->balance;
                $out['init_margin'] = $obj->result->initial_margin;
                $out['session_rpl'] = $obj->result->session_rpl;
            }
            else {
            $this->ProcessError("#FAILED: get_account_summary %s", $json, $obj);
            $core->LogError("~C91#FAILED:~C00 private API %s returned %s", $this->last_request, $json);
            }
            $map[$currency] = $out;
        }

        protected function ProcessBalance(array $map, string $coin) {
            $key = strtoupper($coin);
            $total_key = strtolower("total_$coin");
            $price_key = strtolower("{$coin}_price");
            $core = $this->TradeCore();
            if (!isset($map[$key])) {
                $core->LogError("~C91 #FAILED:~C00 ProcessBalance no info for %s:  %s", $key, json_encode($map));
                return 0;
            }

            $price = $this->$price_key;
            $map = $map[$key];
            if (is_array($map) && isset($map['balance'])) {
                $core->$total_key = $map['balance'];        
                $core->total_funds = $core->$total_key * $price;
                return $map['init_margin'] * $price;        
            } 
            else  
                $core->LogError("~C91 #FAILED:~C00 ProcessBalance no info for %s balances: %s", $key, var_export($map, true));       

            return 0;  
        }

        public function  LoadPositions() {
            parent::LoadPositions();

            $core = $this->TradeCore();
            $map = [];
            $this->GetAccountSummary('BTC', $map);
            $this->GetAccountSummary('ETH', $map);
            $btc_usage = $this->ProcessBalance($map, 'BTC');
            $eth_usage = $this->ProcessBalance($map, 'ETH');     

            $funds_locked = $btc_usage + $eth_usage;
            if ($core->total_funds > 0)  
                $core->used_funds = 100 * $funds_locked  / $core->total_funds;
            $this->GetPositions('BTC');
            $this->GetPositions('ETH');
        }

        public function  PlatformStatus() {
            $json = $this->RequestPublicAPI('/api/v2/public/test', array());
            $core = $this->TradeCore();
            $res = json_decode($json);
            if (is_object($res) && isset($res->result) && isset($res->result->version)) {
                $this->platform_version = $res->result->version;
            }
            else {
                $this->LogMsg("~C91#WARN:~C00 {$this->last_request} failed: $json");
                return 0;
            }
            $json = $this->RequestPublicAPI('/api/v2/public/get_time', array());
            $res = json_decode($json);
            if (is_object($res) && isset($res->result)) {
                $this->server_time = $res->result;
                $this->diver_time = time_ms() - $this->server_time;
                if (abs($this->diver_time) > 100)
                    $this->LogMsg("#SERVER_TIME: diff = {$this->diver_time} ms");
            }
            else {
                $this->LogMsg("~C91#WARN:~C00 {$this->last_request}  method failed: $json");
                return 0;
            }

            return 1;
        }

        protected function EncodeParams(&$params, &$headers, $method) {
            if ('POST' === $method) {
                if (!is_string($params))
                $params = json_encode($params);
                $headers []= 'Content-Type: application/json';
            }
            elseif (is_array($params))
            $params = http_build_query($params, '', '&');
            return $params;
        }


        public function  NewOrder(TickerInfo $tinfo, array $proto): ?OrderInfo {
            $this->last_error = '';
            $core = $this->TradeCore();       
            $pair_id = $tinfo->pair_id;   
            $proto['pair_id'] = $pair_id;


            if (0 == $proto['amount'])
                throw new Exception("Attempt create order with amount == 0");

            $active = $this->MixOrderList('pending,market_maker');
            if (count($active) >= 40) {
                $core->LogError("~C91#FAILED(NewOrder):~C00 too many active orders %d", count($active));
                return null;
            }

            $info = $this->CreateOrder();  // OrderInfo instance simple
            $info->Import($proto);
            if (!$this->DispatchOrder($info)) {
                $core->LogError("#FAILED: order was not registered");
                $core->LogObj($proto, '  ', 'proto', 'error_logger');
                return null;
            }
            $info->ticker_info = $tinfo;
            $side = $info->buy ? 'Buy' : 'Sell';
            $instr_name = $core->pairs_map[$pair_id];
            $hidden = array_value($proto, 'hidden', false);
            // $ttl = gmdate('Y-m-d H:i:s', time() + 300);  // for debug purpose
            // $acc_id = $this->account_id;
            $label = "{$this->account_id}:{$info->id}:{$info->batch_id}";
            $params = array('label' => $label, 'type' => 'limit', 'instrument_name' => $instr_name,
                            'price' => strval($info->price), 'amount' => $info->amount);

            if ($hidden && is_bool($hidden)) {
                $params['max_show'] = min(rand(0, 5) * $tinfo->lot_size, $info->amount); // TODO: range must be configurable
            }
            elseif ($hidden)
                $params['max_show'] = $hidden; 

            $core->LogObj($params, '   ', '~C93 order submit params:~C97');
            $json = '';
            if ($info->buy)
                $json = $this->RequestPrivateAPI('/api/v2/private/buy', $params, 'GET');
            else
                $json = $this->RequestPrivateAPI('/api/v2/private/sell', $params, 'GET');

            $res = json_decode($json);
            if (is_object($res) && isset($res->result) && isset($res->result->order) ) {         
                $order = $res->result->order;
                $this->LogMsg("~C97 #NEW_ORDER_SUCCESS:~C00 result %s ", json_encode($order));
                $obj = $this->UpdateOrder($info, $order);
                $this->last_error = '~'.gettype($obj);
                if ($obj && is_object($obj))
                    return $obj;
                else
                    $this->last_error = 'UpdateOrder returned '.var_export($obj);
                return $info;
            }
            else {
                $core->LogOrder("~C91#FAILED(NewOrder)~C00: API returned %s", $json);
                $this->ProcessError("API fails with result %s", $json, $res);
                $info->status = 'rejected';
                $info->OnError('submit');
                $info->Register($this->archive_orders);
                return null;
            }
        }

        public function  CancelOrder(OrderInfo $info): ?OrderInfo {
            $this->last_error = '';
            $core = $this->TradeCore();
            $params = array();
            $json = '';
            $tinfo = $this->TickerInfo($info->pair_id);
            if (strlen($info->order_no) >= 5) {
                $params['order_id'] = $info->order_no;
                $json = $this->RequestPrivateAPI('/api/v2/private/cancel', $params, 'GET');
            }
            else {
                $params['label'] = $this->OrderLabel($info);
                $params['currency'] = $tinfo->quote_currency;
                $json = $this->RequestPrivateAPI('/api/v2/private/cancel_by_label', $params, 'GET');
            }

            $fixed_st = $info->matched > 0 ? 'filled' : 'canceled';
            if ($info->matched > 0 && $info->matched < $info->amount) 
                $fixed_st = 'partially_filled';      

            if ($info->error_map['cancel'] > 1) {
                $info->status = $fixed_st;
                $info->flags |= OFLAG_FIXED;
                return $info;
            }   
            $res = json_decode($json);
            if (is_object($res) && isset($res->result)) {
                return $this->UpdateOrder($info);
            }
            else {
                if (is_object($res) && isset($res->error) && $res->error->code == 11044) {
                    $info->status = $fixed_st;
                    $info->flags |= OFLAG_FIXED;
                    $this->UpdateOrder($info);
                }
                else {  
                    $core->LogOrder("~C91#FAILED:~C00 API cancel/cancel_by_label returned %s", $json);
                    $this->ProcessError("API cancel/cancel_by label fails with result %s", $json, $res);
                }            
                $info->OnError('cancel');
                return null;
            }
        }

        public function  MoveOrder(OrderInfo $info, float $price, float $amount = 0): ?OrderInfo {
            $this->last_error = '';
            $core = $this->TradeCore();
            $tinfo = $this->TickerInfo($info->pair_id);
            if (!$tinfo) {
                $core->LogOrder("~C91#FAILED(MoveOrder)~C00: ticker info not exists for pair %d", $info->pair_id);
                return null;
            }
            $rest = $info->amount - $info->matched;
            if ($rest <= 0) {
                $core->LogOrder("~C91#WARN(MoveOrder)~C00: order %s already filled", strval($info));
                $this->UpdateOrder($info);
                return $info;
            }
            $fails = $info->ErrCount('move');
            if ($fails >= 3) {
                $core->LogOrder("~C91#WARN(MoveOrder)~C00: order %s may be lost/filled, have %d move fails", strval($info), $fails);
                $this->UpdateOrder($info);        
                return $info;
            }
            if ($amount > 0)
                $info->amount = $tinfo->FormatAmount($amount) * 1;

            $params = ['price' => $price, 'amount' => $tinfo->FormatAmount($info->amount), 'instrument_name' => $tinfo->pair];

            $json = '';
            if (strlen($info->order_no) >= 5) {                    
                $params['order_id'] = $this->FormatOrderNo($info->order_no, $tinfo->base_currency);;
                $json = $this->RequestPrivateAPI('/api/v2/private/edit', $params, 'GET');
            }
            else {
                $params['label'] = $this->OrderLabel($info);
                $json = $this->RequestPrivateAPI('/api/v2/private/edit_by_label', $params, 'GET');
            }

            $res = json_decode($json);
            $info->comment = str_replace(', mv, mv', ', mv', $info->comment . ', mv'); 
            $info->was_moved = 0;
            if (is_object($res) && isset($res->result) && isset($res->result->order)) {                 
                $info->was_moved++; 
                return $this->UpdateOrder($info->id, $res->result->order);
            }  
            else {         
                $fails = $info->OnError('move');
                $core->LogOrder("~C91#FAILED(MoveOrder)~C00: API returned %s, for order %s, request = %s, fails = %d", $json, strval($info), $this->last_request, $fails);
                $this->ProcessError("API edit/edit_by_label fails with result %s", $json, $res);
                $info->status = 'lost';         
                $info->updated = date_ms(SQL_TIMESTAMP3);
                $this->UpdateOrder($info);
                return null;
            }
        }

        private function AuthCheck($obj, $context = 'auth') {
            $core = $this->TradeCore();
            if (is_object($obj) && isset($obj->result) && isset($obj->result->access_token)) {
                $this->last_error = '';
                $this->last_error_code = 0;
                $this->oauth_token = $obj->result->access_token;
                $this->oauth_refresh_token = $obj->result->refresh_token;
                file_put_contents('.oauth_refresh_token', $this->oauth_refresh_token);
                chmod('.oauth_refresh_token', 0640);
                $exp_sec = $obj->result->expires_in;
                $this->oauth_expires = time() + $exp_sec;
                $token = substr($this->oauth_token, 0, 10).'...';
                $this->LogMsg("~C92#SUCCES:~C00 new $context token [%s], expires at %s (since %d sec) ", $token, date(SQL_TIMESTAMP, $this->oauth_expires), $exp_sec);
                return true;
            } else
                return false;
        }

        private function AuthRefresh() {
            $headers = array('Content-Type: application/json');
            $core = $this->TradeCore();
            $json = $this->RequestPublicAPI("/api/v2/public/auth", "grant_type=refresh_token&refresh_token={$this->oauth_refresh_token}", 'GET', $headers);
            $obj = json_decode($json);
            if ($this->AuthCheck($obj, 'auth/refresh'))
                return true;
            else  {
                $this->ProcessError("#FAILED(AuthRefresh): %s", $json, $obj);
                $core->LogError("~C91#FAILED(AuthRefresh):~C00 API returned %s,\n\t request %s", $json, $this->last_request);
                $this->oauth_token = false;
                $this->oauth_refresh_token = false;
            }

        }

        private function AuthSession() {
            $nonce = (++$this->last_nonce);
            $nonce = substr($nonce, 0, 8);
            $this->last_error = '';
            if (0 == $this->PlatformStatus())
            throw new Exception("Attempt auth, while platform is down");

            $mts = $this->server_time;

            // $headers = array('Content-Type: application/json');
            $headers = array('Content-Type: application/x-www-form-urlencoded');

            $core = $this->TradeCore();
            $secret = implode('', $this->secretKey);
            if (strlen($secret) < 10)
                throw new Exception("Secret key proto to small");

            $clid = $this->clientId;
            $path = "/api/v2/public/auth";
            $body = '';
            $sign_msg = "$mts\n$nonce\n$body\n";
            $this->LogMsg(" signaturing request [%s]", $sign_msg);
            $secret = str_replace("\n", '', $secret);
            $secret = base64_decode($secret);
            $secret = trim($secret);
            $signature = hash_hmac('sha256', $sign_msg, $secret);
            $params = array('client_id' => $clid, 'grant_type' => 'client_signature', 'timestamp' => $mts, 'nonce' => $nonce, 'signature' => $signature);

            $headers []= "Authorization: deri-hmac-sha256 id=$clid,ts=$mts,nonce=$nonce,sig=$signature";
            $json = $this->RequestPublicAPI($path, $params, 'GET', $headers, $this->public_api); //
            $obj = json_decode($json);

            if ($this->AuthCheck($obj))
                return true;
            else {
                $this->ProcessError("Auth fails %s", $json, $obj);
                $core->LogError("~C91#ERROR:~C00 client_signature auth rejected by %s,\n\t request %s ", $this->last_error, $this->last_request);
            }
            // TODO: little unsecured auth method
            $params = "client_id=$clid&client_secret=$secret&grant_type=client_credentials&";
            $json = $this->RequestPublicAPI($path, $params, 'GET', $headers, $this->public_api); //
            $obj = json_decode($json);
            if ($this->AuthCheck($obj))
                return true;
            else {
                $this->ProcessError("Auth fails %s", $json, $obj);
                $core->LogError("#FATAL: auth rejected by %s, params: %s ", $this->last_error, $params);
                $core->auth_errors []= $json;
                throw new Exception ("Authorization $clid with credentials failed, response: $json");
            }
        }

        private function RequestPrivateAPI($path, $params, $method = 'POST', $sign = true) {
            // $core = $this->TradeCore();
            $this->api_params = $params; // save for debug
            $this->EncodeParams($params, $headers, $method);
            $soon = time() + 60;
            if ($this->oauth_refresh_token && $soon >= $this->oauth_expires)
                $this->AuthRefresh();

            if (!$this->oauth_token)
                $this->AuthSession();

            if ($sign) {
                $headers []= "Authorization: Bearer ".$this->oauth_token;
            }

            $result = $this->RequestPublicAPI($path, $params, $method, $headers, $this->private_api); // using separated API endpoint
            // if (error_test($result, '')
            return $result;
        }
    }

    $bot_impl_class = 'DeribitBOT';

    final class DeribitBOT extends TradingCore {

        public function  __construct() {
            global $mysqli, $impl_name;
            $this->impl_name = $impl_name;
            parent::__construct();      
            $this->trade_engine = new DeribitEngine($this);
            $this->trade_engine->account_id = 0;
            $this->Initialize($mysqli);
            $this->configuration->max_order_cost = 3000;  // to big Amount...
        }

    };



?>