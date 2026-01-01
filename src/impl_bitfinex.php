<?php
    include_once('../lib/common.php');
    include_once('../lib/db_tools.php');
    require_once('trading_core.php');
    require_once('rest_api_common.php');


    define('MAX_SAFE_INTEGER', 9007199254740991);
    define('MAX_ORDER_OFFSET', 300); // id offset for detection from last own orders 

    function error_test($res, $msg) {
      return (is_array($res) && count($res) >= 3 && 'error' == $res[0] && 
                  false !== strpos($res[2], $msg) );
    }

    final class BitfinexEngine extends RestAPIEngine {

        private   $open_orders = [];
        private   $outer_orders = [];  // external/manual/other bots orders
        private   $loaded_data = [];
        
        private   $last_imported = [];
        private   $api_params = [];
        
        private   $dbg_statuses = [];

        public    $api_key_fails = 0;

        public function  __construct(object $core) {
            parent::__construct($core);
            $this->exchange = 'Bitfinex';

            $env = getenv();
            if (isset($env['BFX_API_KEY'])) {
                $this->apiKey = trim($env['BFX_API_KEY']);
                $this->LogMsg("#ENV: Used API Key {$this->apiKey}");
                $ss = $env['BFX_API_SECRET'];
                $this->secretKey = explode("\n", trim($ss));         
            } else {
                $key = file_get_contents('.bitfinex.api_key');
                $this->apiKey = trim($key);
                $this->secretKey = file('.bitfinex.key');
                if (!$this->secretKey)
                throw new Exception("#FATAL: can't load private key!\n");
            }  
            $this->public_api = 'https://api-pub.bitfinex.com/';
            $this->private_api = 'https://api.bitfinex.com/';
        }


        public function Finalize(bool $eod) {       
            $core = $this->TradeCore();
            $start = $end = time() * 1000;       
            $full = [];
            $before = $end - 86400 * 1000;
            $loops = 0;
            while ($start > $before) {                
                $loops ++;
                $start = $end - 3600 * 1000;
                $params = ['start' => $start, 'end' => $end, 'limit' => 50];          
                $json = $this->RequestPrivateAPI('v2/auth/r/positions/hist', $params);
                $res = json_decode($json);
                $count = 0;
                if (is_array($res) ) {            
                    $full = array_merge($full, $res);
                    $count = count($res);
                    $this->LogMsg("~C94#FINALIZE:~C00 loading positions before %s, count = %d", date_ms(SQL_TIMESTAMP3, $end), $count);          
                }   

                if ($count < 50)
                    $end = $start - 1;
                elseif (is_array($res[0]) && count($res[0]) > 13) // using MTS_UPDATE field for next end
                    $end = round($res[0][13] / 1000) * 1000;    
                if ($loops > 60) sleep(1);
            }        
            if (count($full) > 0)
                file_put_contents($this->hist_dir.sprintf('/position_%s.csv', date('Y-m-d')), FormatCSV($full, ','));
            parent::Finalize($eod);      
        }
        public function  Initialize() {
            parent::Initialize();      
            $json = $this->RequestPrivateAPI('v2/auth/r/info/user', []);
            $res = json_decode($json);
            if (error_test($res, '')) return;
            if (is_array($res) && count($res) > 50) {
                $uid = $res[0];
                $master_id = $res[16];
                if ($uid != $this->account_id)  
                send_event('WARN', "Account ID in DB {$this->account_id}, but exchange returned $uid, master_id = $master_id");        
            } else {
                $this->TradeCore()->LogError("~C91#ERROR:~C00 PrivateAPI user/info returned %s ", $json);
            }         
        }
        private function DecodeOrder($info, $rec) {
            // 0:ID, 1:GID, 2:CID, 3:SYMBOL, 4:MTS_CREATE, 5:MTS_UPDATE, 6:AMOUNT, 7:AMOUNT_ORIG, 8:TYPE, 9:TYPE_PREV,
            // 10:MTS_TIF,
            $core = $this->TradeCore();
            if (!is_numeric($rec[0]) &&  is_array($rec[0])) {
                $count = count($rec);
                if ($count > 1) {
                $core->LogOrder("~C91#WARN:~C00 attempt use DecodeOrder with %d records for order %s, from %s", $count, strval($info), format_backtrace());        
                foreach ($rec as $r)
                    $core->LogOrder("\t\t %s", json_encode($r));
                }   
                $rec = $rec[0]; // inner array
            }   

            if (count ($rec) < 15)
                throw new Exception("Attempt to decode wrong order data: ".print_r($rec, true));
            
            $info->order_no = $rec[0];

            $was_fixed = $info->IsFixed();   
            $this->DecodeStatus($info, $rec[13]);      
            $info->avg_price = $rec[17];
            $rest = $rec[6];                        
        
            if (0 == $rest && !$info->IsFixed()) {
                $core->LogOrder("~C91#WARN:~C00 DecodeOrder zero rest for %s", strval($info));
                $info->status = 'filled';           
                $info->flags |= OFLAG_FIXED; 
                $info->matched = $info->amount;
            }  
            elseif ($rest > 0) {
                $m = $info->amount - abs($rest);
                if ($m != $info->matched)
                $core->LogOrder("~C93#DBG:~C00 DecodeOrder rest amount for %s = %f", strval($info), abs($rest));
                $info->matched = $m;        
            }  

            $tif = $rec[10];
            if ($tif > time() && !$info->IsFixed()) {
                $tif /= 1000; // rounding
                $timeout = $tif - time();
                $info->runtime['expiration_ts'] = gmdate(SQL_TIMESTAMP, $tif);         
                $elps = $info->Elapsed('created') / 3600000;
                if ($timeout < 120)
                    $core->LogOrder("~C91#WARN:~C00 order %s will expired by %d seconds, elapsed = %.3f hours, tif = %s UTC", 
                                    strval($info), $timeout, $elps, $info->runtime['expiration_ts']);
            }   
            
            $uts = date_ms(SQL_TIMESTAMP3, max($rec[4], $rec[5]));      
            $info->updated = $uts;
            $info->OnUpdate();   // touch ->checked to current time
            if ($info->IsFixed() && !$was_fixed) {
                $info->ts_fix = $info->updated;
            }
            }

        
            private function ProcessOrderResult(OrderInfo $info, string $json, string $op): ?OrderInfo {
            $core = $this->TradeCore();
            $result = json_decode($json);
            $curl_rcode = $this->curl_response['code'];
            $this->last_error = '';
            $ti = $info->ticker_info;

            if (200 == $curl_rcode && 
                    is_array($result) && count($result) >= 8 && is_array($result[4])) {
                $this->DecodeOrder($info, $result[4]);
                $this->DispatchOrder($info, 'DispatchOrder/bfx-process');
                if ('rejected' == $info->status)
                    $core->LogOrder("~C91#WARN:~C00 while %s order %s result: %s", $op, strval($info), $json);
            }
            else {
                $nonce = intval($this->last_nonce);
                $info->status = OST_REJECTED;

                $core->LogError("~C91#FAILED~C00: API %s returned %s, last_nonce = %15.3G, curl rcode = %d, error_map = %s", 
                                    $op, $json, $nonce / 1000.0, $curl_rcode, json_encode($info->error_map));
                $core->LogMsg ('~C93API params used: %s', json_encode($this->api_params));
                if (is_array($result) && 'error' == $result[0] && isset($result[2])) {            
                    $this->SetLastError($result [2], $result[1]);
                }              

                $info->error_map[$op] = $this->last_error;

                if (10001 == $this->last_error_code) {           
                    $new_order = $op === 'NewOrder'; 
                    if ($new_order && $info->rising && strpos($this->last_error, 'not enough tradable balance') !== false) {
                        $this->ne_assets[$info->pair_id] = true; // WARN: only if position opens attempt, and real insufficent funds!                    
                        $core->postpone_orders[$info->pair_id] = 30;   
                        $this->LogMsg("~C91#WARN:~C00 insufficient funds for %s, postponed", strval($info));                 
                        if (is_object($ti))  {
                          $ti->cost_limit /= 2; // reduce cost limit for next attempt
                          $core->LogOrder("~C91#WARN:~C00 reduced cost limit for %s to $%.2f", strval($ti), $ti->cost_limit);
                        }    
                        else  
                          $core->LogError("~C91#ERROR:~C00 unassigned ticker info for %s", strval($info));  

                    }
                    elseif (false !== stripos($this->last_error, 'order not found') || 
                            str_in($this->last_error, 'order: invalid'))   {                            
                                $info->flags |= OFLAG_FIXED;                                                             
                    }              
                }
            
                $res = $this->LookupOrder($info);           
                if ($res) 
                    return  $res;
                else {
                    $core->LogOrder("~C91#WARN:~C00 failed to lookup order %s", strval($info));                                                    
                    $info->status = 'rejected';
                }  
                    
                return null;
            }
            return $info;
        }

        private function DecodeStatus($info, $src) {

            $dict = ['ACTIVE' => 'active', 'CANCELED' => 'canceled', 'CANCELED_WAS' => 'canceled', 'EXECUTED' => 'filled', 'PARTIALLY FILLED' => 'partially_filled'];
            $core = $this->TradeCore();
            $this->dbg_statuses = ['error:'.$src];
            $stl = explode(', ', $src);
            $code = $src;
            $matched = 0;
            $avg_p = 0;
            $stack = [];
            $loop = 0;
            $flags = 0;     

            foreach ($stl as $st)
                while (strlen($st) > 1 && $loop < 20) {
                $loop ++;
                $m = [];
                $st = trim($st);
                // ready for serialized status as 'EXECUTED @ 1468.4(-0.00000667): was PARTIALLY FILLED @ 1468.4(-0.20999333)'
                //  exploding   (token) @ (price) \((amount)\)
                if (strlen($st) < 5) {
                    file_put_contents('logs/decode_status.bug', $st);
                    break;
                }

                if (preg_match('/([^@]*) @ ([\S]*)\(([\S]*)\)/', $st, $m)) {
                    $code = trim($m[1]);
                    if (false !== array_search($code, ['CANCELED', 'EXECUTED']))
                        $flags = OFLAG_FIXED;

                    if (isset($dict[$code]))
                        $code = $dict[$code];
                    else
                        $code = strtolower($code);

                    $code = str_replace(':', '', $code);
                    $code = str_replace('_was', '', $code);
                    $code = trim($code, '-: ');
                    if (strlen($code) >= 5)
                        $stack []= $code;
                    else
                        $this->LogMsg("~C91#WARN(DecodeStatus):~C00  code small length, source matches %s ", json_encode($m));

                    $avg_p += $m[2] * abs($m[3]);
                    // $info->avg_price =$m[2];
                    $matched += abs($m[3]);
                    $st = substr($st, strlen($m[0]));
                    $st = str_ireplace(': was ', '', $st);
                }
                elseif (strlen($st) >= 6) {
                $st = trim($st, '0123456789.()\t '); // TODO: remove trash via regEx
                if (isset($dict[$st]))
                    $stack[]= $dict[$st];
                else
                    $stack[]= strtolower($st);

                break;
                }
            } // foreach / while

            if ($loop > 20) {
                $core->LogError("~C91#ERROR:~C00 failed decoding [%s] rest [%s] ", $src, $st);
                file_put_contents('decode_status.bug', $st);
            }
            

            if ($matched != 0) {
                $info->matched = $matched;
                if (0 == $info->avg_price)
                    $info->avg_price = $avg_p; // TODO: remove after testing
            }

            $this->dbg_statuses = $stack;            
            $can_mod = ['active', 'partially_filled', 'lost', 'new', 'proto'];
            if (array_search($info->status, $can_mod) !== false)
                while (count($stack) > 0) {
                $line = array_shift($stack);
                $st = substr($line, -16);
                $st = str_replace(' ', '_', $st);         
                if (strlen($st) >= 5) {            
                    if ($st == 'canceled')                                     
                        $st = $info->SetFixedAuto(); // автодетект статуса, в зависимости от исполненной части
                    else
                        $info->status = $st;             
                    if ($info->IsFixed() || $st == 'filled')
                        break;                    
                }   
                elseif (strlen($st) > 0) 
                    $core->LogError("~C91 #WARN:~C00 invalid status [%s] from: [%s] %s", $st, $line, json_encode($stack));          
                }   
            $info->flags |= $flags;
        }

        public function CancelOrder($info): ?OrderInfo {
            $core = $this->TradeCore();
            if (!isset($info->pair_id)) {
                $core->LogError("~C97#ERROR: invalid OrderInfo object:~C00");
                throw new Exception("#FATAL: structure mistmatch");
            }
            //  for ($attempt = 0; $attempt < 2; $attempt ++)
            if ($info->IsFixed()) {
                $core->LogOrder('~C91#ERROR:~C00 attempt to cancel fixed order #%d', $info->id);
                $this->DispatchOrder($info, 'DispatchOrder/cancel-fixed');
                return null;
            }
            if ($info->error_map['cancel'] > 0)  return null;

            $params = array('id' => $info->order_no);
            $json = $this->RequestPrivateAPI('v2/auth/w/order/cancel', $params);
            $res = $this->ProcessOrderResult($info, $json, 'cancel');
            if ($res) 
                $info->OnUpdate('cancel');
            else  
                $info->OnError('cancel');
            return $res;
        }

        public function  MoveOrder(OrderInfo $info, float $price, float $amount = 0): ?OrderInfo {
            $core = $this->TradeCore();
            $mvt = time_ms();

            $rest = ($info->amount - $info->matched);
            if (0 == $rest) {
                $core->LogOrder("#WARN: MoveOrder cannot execute zero rest. Order probably is matched");
                $info->status = 'filled';
                $this->DispatchOrder($info, 'DispatchOrder/move-fast');
                return null;
            }

            if ($info->IsFixed()) {
                $core->LogOrder("#WARN: MoveOrder cannot change fixed order %s", strval($info));
                $info->status = $info->matched > 0 ? 'filled' : 'canceled';
                $this->DispatchOrder($info, 'DispatchOrder/move-fast');
                return null;
            }
            if ($info->error_map['move'] >= 3) 
                return null; // hanged wrong status

            if ($info->ticker_info)
                $rest = $info->ticker_info->FormatAmount($rest);        

                
            $ttl = gmdate('Y-m-d H:i:s', time() + 927);  // for crash scenario
            if (isset($info->ttl)) {
                $ttl = $info->ttl;
                if (is_numeric($ttl))
                    $ttl = gmdate('Y-m-d H:i:s', time() + $ttl);        
            }
            $id_origin = $this->account_id * 1000000;
            $amount = $amount > 0 ? $amount  :  $info->amount;      
            $amount *= $info->TradeSign();
            $rest = $rest * $info->TradeSign();  // need signed value

            $params = array('id' => $info->order_no, 'cid' => $info->id + $id_origin, 'tif' => $ttl, 
                            'price' => strval($price),'amount' => strval($amount)); // 
            $json = $this->RequestPrivateAPI('v2/auth/w/order/update', $params);
            $res = $this->ProcessOrderResult($info, $json, 'MoveOrder');
            if (!$res) { 
                $info->OnError('move');
                return null;
            }  

            $info->was_moved ++;
            if ('lost' === $res->status)
                $res = CancelOrder($res);
            else
                $info->price = $price; // price modified, since order changed

            if ('active' === $res->status || strpos($res->status, '_filled') !== false) {
                $utm = strtotime_ms($res->updated);
                if ($utm < $mvt) {
                $mvts = date_ms(SQL_TIMESTAMP3, $mvt);
                $core->LogOrder("#DBG: MoveOrder info->updated < %s = %s, amount = %10f, rest = %10f", $res->updated, $mvts, $amount, $rest);
                $info->updated = $mvts;
                }
            }
            return $res;
        }

        public function LoadTickers() {

            $core = $this->TradeCore();
            parent::LoadTickers();
            $pmap = $this->pairs_map_rev;
            if (is_null($pmap) || !is_array($pmap) || 0 == count($pmap)) {
                $core->LogError("~C91#ERROR:~C00 pairs_map_rev not initialized, LoadTickers can't work");
                return false;
            }
            $symlist = implode(',', array_keys($pmap));

            $json = $this->RequestPublicAPI('v2/tickers', array ('symbols' => $symlist));
            if (!$json) {
                $core->LogError("~C91#ERROR: public API request failed:~C97 tickers~C00 ");
                return;
            }
            file_put_contents('data/bfx_tickers.json', $json);
            $tickers = json_decode($json);
            if (!is_array($tickers)) {
                $core->LogError("~C91#ERROR: public API request failed:~C97 tickers~C00, invalid JSON returned:~C92 $json~C00");
                return 0;
            }
            $tmap = [];

            foreach ($tickers as $set) {
                $sym = $set[0];
                $pair_id = $pmap[$sym];
                $tinfo = $this->TickerInfo($pair_id);
                
                // 0:SYMBOL, 1:BID,  2:BID_SIZE, 3:ASK, 4:ASK_SIZE, 5:DAILY_CHANGE, 6:DAILY_CHANGE_RELATIVE, 7:LAST_PRICE, 8:VOLUME, 9:HIGH, 10:LOW
                $tinfo->bid_price = $set[1];
                $tinfo->ask_price = $set[3];        
                $tinfo->last_price = $set[7];        
                $tinfo->daily_vol = round($set[8] * $tinfo->last_price, 4);
                $tinfo->fair_pirce = $tinfo->cmc_price; // not implemented, due no futures
                $tinfo->min_cost = 10;
                $tinfo->OnUpdate();
                $tmap [$sym] = 1;

                $pb = strpos($sym, 'BTC');
                $pu = strpos($sym, 'USD');
                if (false !== $pb && false !== $pu && $pu > $pb)
                    $this->set_btc_price($tinfo->last_price);
                      
            }




            $info = [];
            $minute = date('i');

            if (!isset($this->loaded_data['tickers_config']) || 0 == $minute) {
                $json = $this->RequestPublicAPI('v2/conf/pub:info:pair', '');  // described in API 
                if (!$json) {
                    $core->LogError("~C91#ERROR: public API request failed:~C97 pub:info:pair~C00");
                    return count($tickers);
                }
                $info = json_decode($json); 
                $this->loaded_data['tickers_config'] = 1;
            }
            else 
                return count($tickers);

                
            if (is_null($info) || !is_array($info) || 0 == count($info)) {
                return count($tickers);
            }   
            

            foreach ($info[0] as $set) {
                $sym = $set[0];
                //  var_dump($sym);
                $pair = $sym;
                if ('t' != $pair[0])
                    $pair = 't'.$pair;
                if (!isset($pmap[$pair])) {          
                    continue;
                }
                $pair_id = $pmap[$pair];
                $tinfo = $this->TickerInfo($pair_id);
                $data = $set[1];
                $tinfo->lot_size = $data[3];
                $tinfo->max_size = $data[4];
                $tinfo->price_precision = $tinfo->CalcPP($tinfo->last_price);           
                $tinfo->DetectQuoteCurrency();          
                $this->LogMsg(" symbols %13s, lot_size = %f, last_price = %f ", $sym, $tinfo->lot_size, $tinfo->last_price);
                unset($tmap[$pair]);

            }

            if (count($tmap) > 0) {
                $tmap = array_keys($tmap);
                $core->LogError("~C91#WARN:~C00 not loaded info for symbols: ".implode(',', $tmap));
            }

            // $this->LogMsg(" ignored tickers %s", implode(',', $ignored));
            return count($tickers);
        }

        

        private function ImportOrders($list, $source = 'default', bool $opened = false) {
          
            $core = $this->TradeCore();
            $pmap = $this->pairs_map_rev;
            $id_origin = $this->account_id * 1000000; // WARN: this not compatible with HFT
            $loaded = 0;
            $imported = [];
            $max_order = $this->GenOrderID(0, 'ImportOrders/get-last') + MAX_ORDER_OFFSET; // ожидаемо все заявки должны быть ниже этого номера
            $info = false;
            $outrange = 0;
            $candidates = $this->MixOrderList('pending,market_maker,lost');
            

            foreach ($list as $rec)
            if (is_array($rec) && count($rec) > 15) {
                // 0:ID, 1:GID, 2:CID, 3:SYMBOL, 4:MTS_CREATE, 5:MTS_UPDATE, 6:AMOUNT, 7:AMOUNT_ORIG, 8:TYPE, 9:TYPE_PREV, 10:MTS_TIF, 11:FLAGS, 12:FLAGS, 13:STATUS,
                // 14: _PLACEHOLDER, 15:_PLACEHOLDER, 16:PRICE, 17:PRICE_AVG, 18:PRICE_TRAILING, 19:PRICE_AUX_LIMIT, 20:_PLACEHOLDER, 21:_PLACEHOLDER,  22:_PLACEHOLDER, 23:HOTIFY,
                // 24: HIDDEN, 25: PLACED_ID, 26: _PLACEHOLDER, 27:_PLACEHOLDER, 28:ROUTING, 29:_PLACEHOLDER, 30:_PLACEHOLDER, 31:META
                $sym = $rec[3];
                $cid = intval($rec[2]);
                $clid = $cid - $id_origin;

                $desc = "ENO={$rec[0]}/{$rec[25]}, {$rec[7]} @ {$rec[16]} $sym {$rec[13]}"; // debug log

                if ($max_order > MAX_ORDER_OFFSET && $clid > $max_order && !isset($this->outer_orders[$cid]))  {
                    $outrange ++;
                    $this->outer_orders[$cid] = 1;
                    if (strpos($source, '!') !== false && $cid != $rec[0]);
                        $core->LogOrder("~C91#WARN:~C00 outrange client order id %d (max expected %d) for %s, total %d, source [%s]: ~C97".json_encode($rec, true), 
                                                        $clid, $max_order, $desc, count($this->outer_orders), $source); // placed from terminal or other bot, must be ignored
                    continue;
                }
                unset($this->outer_orders[$cid]);
                //
                // $local = isset($pmap[$sym]);
                // if ($local && $clid < 100000) $core->LogOrder(" symbol %s, checking order #%d %s from %s", $sym, $clid, json_encode($rec), $source);
                $info = false;          
                $is_archive = isset($this->archive_orders[$clid]);
                $status = 'nope';
                if ($is_archive) {
                    $info = $this->archive_orders[$clid];
                    $status = $this->archive_orders[$clid]->status;
                }

                if (!$info && isset($candidates[$clid]) ) 
                    $info = $candidates[$clid]; 

                if ($info && false !== strpos($status, 'lost')) 
                    $core->LogOrder(' re-import lost order %s', strval($info));          
                else
                    $desc .= sprintf(" !lost = %s, is_archive:%d ", $status, $is_archive);

                $imported [$clid] = $desc;
                $pair_id = isset($pmap[$sym]) ? $pmap[$sym] : 0;
                if (!$info)
                    $info = $this->FindOrder($clid, $pair_id);
                if (!$info) continue; // non this bot made this order

                $this->updated_orders [$clid] = $info; // для обсчета позиций

                if ($opened)
                    $this->open_orders[$clid] = true;                    
                $this->DecodeOrder($info, $rec);      
                try {
                    if (!$opened && 'active' == $info->status) { 
                    $core->LogError("~C91#WARN:~C00 ImportOrders found active order %s after %s processing statuses: %s",
                                    strval($info), $source, json_encode($this->dbg_statuses));
                    if ($info->matched > 0)
                        $info->status = $info->Pending() > 0 ? 'partially_filled' : 'filled';
                    else
                        $info->status = 'canceled';                
                    }               
                    if (!$info->IsFixed() && $info->Elapsed('updated') < 60)
                        $core->LogOrder(' loaded/updated active order %s', strval($info));               

                    if (!$opened)  
                        $info->flags |= OFLAG_FIXED;
                    $this->DispatchOrder($info, 'DispatchOrder/import');
                    $loaded ++;
                } catch (Exception $e) {
                    $core->LogError("~C91#ERROR:~C00 ImportOrders failed to process order %s, %s, statuses: %s",
                                        strval($info), $e->getMessage(), json_encode($this->dbg_statuses));
                }
                
            }
            if (false !== strpos($source, '!'))
                $this->LogMsg("#DBG: ImportOrders, loaded = %d, outrange = %d", $loaded, $outrange);

            $this->last_imported = $imported;       
            return $loaded;
        }


        public function LookupOrder(OrderInfo $info):?OrderInfo {
            $url = "v2/auth/r/orders/hist";           
            $params = ['id' => [intval($info->order_no)]];
            if (isset($info->pair))
                $url = "v2/auth/r/orders/{$info->pair}/hist";           
            $core = $this->TradeCore();
            $json = $this->RequestPrivateAPI($url, $params, 'POST');
            $res = json_decode($json);
            if (is_array($res) && count($res) > 0) {
                $this->ImportOrders($res, 'API/lookup');
                return $info;
            }       
            else
                $this->LogMsg("~C91#WARN(LookupOrder):~C00 %s/?%s for %s returned %s", $url, json_encode($params),  strval($info), $json);      

            return null;
        }
        public function LoadOrders(bool $force_all) {
            $core = $this->TradeCore();
            $json = $this->RequestPrivateAPI('v2/auth/r/orders', '');
            $list = json_decode($json);
            if (!is_array($list)) {
                $core->LogError("~C91#FAILED: Request private API margin orders returned %s", $json);
                return false;
            }
            $this->api_key_fails = 0;
            $this->open_orders = [];
            $loaded = $this->ImportOrders($list, 'API/open', true);
            $part = array_slice( $this->last_imported, 0, 30);
            $active_cnt = $loaded;
            $active_lst = print_r($part, true);
            // post processing
            $lost_ids = [];      
            $lost_orders = [];

            $pending = $this->MixOrderList('pending,market_maker,lost');
            $core->LogOrder("#DBG: via API loaded orders %d, pending = %d", $loaded, count($pending));

            $oldest = time_ms();
            foreach ($pending as $info)
                if (is_object($info) && !isset($this->open_orders[$info->id])) {
                if ($info->order_no > 0)
                    $lost_ids []= intval($info->order_no);
                $lost_orders [$info->id]= $info;
                $t = strtotime_ms($info->created);
                if (!$info->IsFixed()) 
                    $info->status = 'lost';           
                $oldest = min($oldest, $t);
                }

            if (0 == count($lost_ids) && !$force_all)
                return $loaded; // active only present

            $sym_list = [];
            foreach ($lost_orders as $id => $info) {
                $sym_list []= $core->pairs_map[$info->pair_id];
            }
            $strict = '';

            $sym_list = array_unique($sym_list);
            if (1 == count($sym_list)) {
                $strict = '/'.$sym_list[0];
            }
            $url = "v2/auth/r/orders/hist";
            $params = [];
            $list = false;
            if (!$force_all) {
                $params = array('id' => $lost_ids);
                $json = $this->RequestPrivateAPI($url, $params, 'POST');
                $list = json_decode($json);
            }

            if (!$list) {
                if (!$force_all)  {           
                $core->LogError("~C91#WARN: Request private API margin orders history by id-list %s returned %s", json_encode($lost_ids), $json);
                $core->LogOrder(" used was request url %s, params %s ", $this->last_request, json_encode($params));           
                $url = "v2/auth/r/orders$strict/hist";
                }
                // back for X hours, no id filter
                $back_hours = 48;
                $start_mts = ( time() - $back_hours * 3600 ) * 1000;
                $start_mts = min($start_mts, $oldest);
                $params = array("start" => $start_mts, 'limit' => 2000);
                $this->LogMsg("~C93#PERF:~C00 Requesting orders history from %s url %s", date_ms(SQL_TIMESTAMP3, $start_mts), $url);         
                $json = $this->RequestPrivateAPI($url, $params, 'POST');
                $list = json_decode($json);
                if (!$list)
                    $core->LogError("~C91#WARN: Request private API margin orders history shrinked by time returned %s", $json);
                else {
                    $core->LogOrder("~C04~C93#DBG:~C00 margin orders history shrinked by time %ld returned %d records", $start_mts, count($list));            
                }
                // return false;
            }

            $hst = $this->ImportOrders($list, 'API/hist!');
            $loaded += $hst;
            $found = $this->archive_orders->CountByField('status', 'lost');
            if ($force_all && $found > 0) {
                $part_all = array_slice( $this->last_imported, -30);           
                $core->LogOrder("~C94#DBG:~C00 Dump last imported historical orders, last 30):\n ". print_r($part_all, true));
                $core->LogOrder(" in archive_orders found %d lost orders", $found);

            }
            if (0 == count($lost_orders)) return $loaded;
            $core->LogOrder("#DBG: checking %d lost orders (pairs: %s), updated from history %s request, imported $hst",
                                count($lost_ids), implode(',', $sym_list), json_encode($params));

            foreach ($lost_orders as $info) {
                if (false !== strpos($info->status,'lost')) {
                if (0 == $info->order_no)
                    $info->status = 'lost,n/e'; // not exists on exchange!
                $info->Unregister(null, 'LoadOrder/lost');
                $info->Register($this->archive_orders);          
                } 
                else
                unset($lost_orders[$info->id]);
            }
            $lost_cnt = count($lost_orders);
            if ($lost_cnt > 0) {
                $core->LogOrder("#WARN: total lost orders %d, imported active %d (displayed <= 30):\n %s ", $lost_cnt, $active_cnt, $active_lst);
                // print_r($this->last_imported);
                foreach ($lost_orders as $id => $info) {         
                $core->LogOrder('~C91#WARN(LoadOrders):~C00 order %s was not retrieved by API, marking temporary as lost', strval($info));  
                $info->status = 'lost';              
                }     
            }
            return $loaded;
        }

        public function LoadPositions() {
            parent::LoadPositions();
            $core = $this->TradeCore();
            $json = $this->RequestPrivateAPI('v2/auth/r/info/margin/base', '', 'POST');
            $obj = json_decode($json);
            if ($obj && is_array($obj)) {
                $rec = $obj [1];
                // 0:USER_PL, 1:USER_SWAPS, 2:MARGIN_BALANCE, 3:MARGIN_NET, 4:MARGIN_MIN
                if (is_array($rec) && count($rec) > 4) {
                $core->total_funds = floatval($rec[3]);
                $margin_min = floatval($rec[4]);
                $core->used_funds = 100 * $margin_min / $core->total_funds;

                if ($this->btc_price > 0)
                    $core->total_btc = round( 1000 * $core->total_funds / $this->btc_price ) * 0.001;
                }
            }

            $json = $this->RequestPrivateAPI('v2/auth/r/positions', '', 'POST');
            $obj = json_decode($json);
            if (!$obj) {
                $core->LogError("~C91#FAILED: Request private API margin positions returned %s", $json);
                return false;
            }

            $result = [];
            $ignored = [];
            $loaded  = 0;
            $pmap = $this->pairs_map_rev;
            
            if (is_array($obj))
            foreach ($obj as $rec)
            if (is_array($rec)) {
                $sym = $rec[0];
                if (!isset($pmap[$sym])) {
                $ignored []= $sym;
                // $this->LogMsg(" symbol %s not mapped", $sym);
                continue;
                }
                // SYMBOL, STATUS, 2:AMOUNT, 3:BASE_PRICE, 4:MARGIN_FUNDING, 5:MARGIN_FUNDING_TYPE, 6:PL, 7:PL_PERC, PRICE_LIQ, LEVERAGE, _PLACEHOLDER, POSITION_ID,  MTS_CREATE, MTS_UPDATE, _PLACEHOLDER, TYPE, _PLACEHOLDER, COLLATERAL, COLLATERAL_MIN, META...
                $pair_id = $pmap[$sym];
                $tinfo = $this->TickerInfo($pair_id);
                
                if (is_numeric($rec[6]) && $tinfo) {             
                    $pnl = floatval($rec[6]);
                    if ('BTC' === substr($sym, -3))
                    $pnl = sprintf('%.4f&#8383;', $pnl);
                    else  
                    $pnl = sprintf('$%.1f', $pnl); // USD
                    $this->pnl_map[$pair_id] = $pnl;   
                }   
                
                if (is_numeric($rec[2]))  {
                    $result[$pair_id] = $rec[2];
                    $loaded ++;            
                }   
                else   
                    $ignored []= $sym; 
            }

            foreach ($pmap as $pair => $pair_id)
            if (!isset($result[$pair_id]))
                $result[$pair_id] = 0; // default set
            
            if ($loaded > 0) {
                $this->LogMsg("loaded positions %d, ignored positions for %s", $loaded, implode(',', $ignored));
                $core->ImportPositions($result);
                return true;
            }   
            else {
                $core->LogError("~C91#WARN:~C00 private API retured void position list. Source: %s; pair_map_rev: %s", $json, print_r($pmap, true));
                return false;
            }         
        }

        public function NewOrder(TickerInfo $tinfo, array $proto): ?OrderInfo {
            $core = $this->TradeCore();
            
            $proto['pair_id'] = $tinfo->pair_id;

            if (0 == $proto['amount'])
                throw new Exception("Attempt create order with amount == 0");

            $comment = isset($proto['comment']) ? $proto['comment'] : 'NewOrder';    
            $info = $this->CreateOrder(0, $tinfo->pair_id,$comment);  // OrderInfo instance simple
            $info->Import($proto);      
            $info->ticker_info = $tinfo;
            $symbol = $core->pairs_map[$tinfo->pair_id];
            $amount = $info->amount;
            $amount = $tinfo->LimitMin($amount, true);

            $f_amount = $tinfo->FormatAmount($amount);  

            if ($this->IsAmountSmall($tinfo->pair_id, $f_amount)) {
                $core->LogOrder("~C91#WARN:~C00 order %s formated amount %s (%.15f) small by ticker %s limits, rejected", strval($info), $f_amount, $amount, strval($tinfo));
                $info->status = 'rejected';
                $this->DispatchOrder($info, 'DispatchOrder/small-amount');
                return $info;
            }

            $info->amount = $f_amount;
            

            if (!$this->DispatchOrder($info, 'DispatchOrder/new')) {
                $core->LogError("~C91#FAILED:~C00 order was not registered in any list");
                // $core->LogObj($proto, '  ', 'proto', 'error_logger');
                return null;
            }

            $ttl = rand(930, 1800); // for debug purpose, default max 15-30 minute alive, TODO: use config var
            if (isset($proto['ttl']) && is_numeric($proto['ttl']))
                $ttl = $proto['ttl'];

            $tif = gmdate(SQL_TIMESTAMP, time() + $ttl); // always UTC... but same as date in this solution  
            
            $id_origin = $this->account_id * 1000000;
            $amount = strval($info->buy ? $info->amount : -$info->amount);
            $params = array('cid' => $info->id + $id_origin, 'type' => 'LIMIT', 'symbol' => $symbol, 'price' => strval($info->price), 'lev' => 5,
                            'amount' => $amount, 'tif' => $tif, 'meta' => array('aff_code' => $info->comment));
            if (isset($proto['hidden']) && $proto['hidden'])
                $params['flags'] = 64;
            if ($ttl > 1000)  
                $core->LogOrder('~C04~C97#SUBMIT_ODER:~C00 order submit params with ttl %.1f: %s', $ttl, json_encode($params));
            $json = $this->RequestPrivateAPI('v2/auth/w/order/submit', $params);
            return $this->ProcessOrderResult($info, $json, 'NewOrder');
        }

            private function RequestPrivateAPI($path, $params, $method = 'POST', $sign = true) {
            $core = $this->TradeCore();
            $this->api_params = $params; // save for debug
            $path = str_replace('//', '/', $path);

            if (is_array($params))
                $params = json_encode($params);
            
            while (date('s') % 2 != $this->host_id) // wait for personal second
                    usleep(10000);   
                
            $nonce = sprintf("%lu", $this->MakeNonce());
            $this->LogMsg("Using nonce ~C95$nonce");
            $headers = array('Content-Type: application/json', "bfx-nonce: $nonce", "bfx-apikey: {$this->apiKey}");
            if ($sign) {
                $signed_msg = "/api/$path".$nonce.$params;
                $secret = implode('', $this->secretKey);
                if (strlen($secret) < 10)
                throw new Exception("Secret key proto to small");
                $signature = hash_hmac('sha384', $signed_msg, base64_decode($secret.'=='));
                $headers []= 'bfx-signature: '.$signature;
            }

            $result = $this->RequestPublicAPI($path, $params, $method, $headers, $this->private_api); // using separated API endpoint
            $res = json_decode($result);
            if (error_test($res, 'none: small')) { 
                $core->LogError("~C91 #ERROR:~C00 PrivateAPI returns ".$res[2].", used nonce = %s, previous = %s ", $nonce, $this->prev_nonce);

            } elseif (error_test($res, 'apikey: invalid')) {
                $core->LogError("~C91 #FATAL:~C00 PrivateAPI %s returns %s ", $path, $result);
                if ($this->api_key_fails ++ >= 10) {
                    $core->Shutdown("CRITICAL - API key was invalid more 10 times");         
                    $core->trade_enabled = false;      
                }  
                $core->auth_errors []= $result;
            } elseif (!error_test($res, ' '))
                $core->auth_errors = [];
            


            return $result;
        }

        public function PlatformStatus() {
            $json = $this->RequestPublicAPI('v2/platform/status', '');
            if ($json && $res = json_decode($json)) {
                if (is_object ($res))
                return $res->code;
                if (is_array($res))
                return $res[0];
            }

            return 0;
        }


    };

    $bot_impl_class = 'BitfinexBOT';
    final class BitfinexBOT extends TradingCore {

        public function  __construct() {
            global $mysqli;
            $this->impl_name = 'bitfinex_bot';
            parent::__construct();      
            $this->trade_engine = new BitfinexEngine($this);
            $this->Initialize($mysqli);
        }
    };


?>
