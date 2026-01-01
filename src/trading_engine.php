<?php

  // форматирование отчетов 
  function FormatCSV(array $data, string $sep = ';', int $recursion = 0) {
    if (0 == count($data)) return '';
    $result = '';
    $dbg = count($data) > 9000;

    if ($recursion > 0) {
      foreach ($data as $sub)
         $result .= FormatCSV($sub, $sep, $recursion - 1);
      return $result;
    }
    ksort($data);    
    $ref = []; // выборка первой записи
    $core = active_bot();
    if ($dbg) $core->LogMsg("~C96#PERF~C00: FormatCSV: processing %d records", count($data));

    $valid = [];
    foreach ($data as $row) {
      if (is_string($row))
          continue;
      $valid []= $row;   
      $ref = array_replace($ref, $row);  // заполнение всех ключей      
    }  

    $keys = array_keys($ref);   

    if (0 == count($valid) || 0 == count($keys)) 
        return "ERROR: empty/invalid source data";

    if ($dbg) $core->LogMsg("~C96#PERF~C00: FormatCSV: keys: %s", json_encode($keys));
    
    $fkey = array_key_first($data);
    assert(count($keys) < 50, 
            new Exception("To much keys count, source row".print_r($data[$fkey], true)) );
    
    $result = '' ;
    if (!is_numeric($keys[0]))  // если ключи - строки, то добавляем заголовок
        $result = implode($sep, $keys)."\n";

    foreach ($data as $row) {
      $vals = [];
      foreach ($keys as $key) {
        $s = $row[$key] ?? 'null';
        if (is_bool($s))
          $s = intval($s);
        else
          $s = trim($s );        
        $s = str_replace("\n", '\n', $s);
        $s = str_replace("\a", '\a', $s);        
        $vals []= $s;        
      }  
      $result .= implode($sep, $vals)."\n";
    } 
    return $result;
  }  

// TradingEngine - базовый класс торгового движка, реализующий API с конкретной биржей по протоколу REST.  
class TradingEngine {
    public      $debug = []; // отладочные переменные, данные расчетов функций
    public      $last_error = '';
    public      $last_error_code = 0;
    public      $exchange   = 'test';
    public      $account_id = 0;
    public      $ne_assets = [];

    public      $active_sig = []; // map batch [pair_id], for execution progress
    public      $batch_map = [];    // batch map []
    public      $pnl_map = [];    // [pair_id] == $ or coin PNL
    public      $sign_fails = 0;

    public     $last_scale = ''; // отладочная инфа

    public     $host_id = 0;

    protected   $archive_orders = null;  // canceled and outdated orders

    protected   $lost_orders = null;
    protected   $pending_orders = null;
    protected   $matched_orders = null;
    protected   $other_orders = null;  // external/temporary/undectected orders

    protected   $updated_orders = [];  // map of updated orders, used for incremental and out_position recalculations
    public      $pairs_map_rev  = [];  // symbol dictionary ['BTCUSDT'] => 1
    protected   $pairs_info     = [];  // map[pair_id] = info, ticker data

    public      string $history_db; // default database for candles and ticker history

    private     ?TradingCore $trade_core  = null;

    protected   $hist_dir = 'data/history';

    private     $track_loop = 0;
    private     $last_order_id = -1;

    private    $market_makers = []; // array of MarketMaker instance, key = pair_id



    public function  __construct(object $core) {
        assert ($core instanceof TradingCore);
        $this->trade_core = $core;
    }

    public function get_btc_price(string $ts = 'now'): float {
        throw new Exception('TradingEngine.get_btc_price not implemented');
    }

    public function sqli(string $kind = ''): ?mysqli_ex {
        $mysqli = $this->TradeCore()->CheckDBConnection($kind);
        if (is_null($mysqli) && '' == $kind)
            throw new Exception("TradingEngine.sqli(): no main DB connection");
        return $mysqli;    
    }
    public function Cleanup() {
        $this->SetLastError('');
    }

    public function MarketMaker(int $pair_id, bool $create_not_exists = false): ?MarketMaker {
      $mms = $this->market_makers;
      $tinfo = $this->TickerInfo($pair_id);
      if (isset($mms[$pair_id])) 
         return $mms[$pair_id];
      elseif ($create_not_exists && $tinfo)  {                
        $this->market_makers[$pair_id] = new MarketMaker($tinfo, $this);
        return $this->market_makers[$pair_id];
      } elseif ($create_not_exists)
        $this->TradeCore()->LogError("~C91#ERROR:~C00 TickerInfo not found for pair_id %d, can't make MM", $pair_id);
      return null;
    }

    public function ConfigureMM() {
      $core = $this->TradeCore();
      $mysqli = $core->mysqli;
      $prefix = strtolower($this->exchange).'__';
      $config = $mysqli->select_rows('*', $prefix.'mm_config', "WHERE account_id = {$this->account_id}", MYSQLI_OBJECT);
      

      foreach ($config as $rec) 
        if (is_object($rec)) {
          $enabled = $rec->enabled > 0;
          $tinfo = $this->TickerInfo($rec->pair_id);
          if (!$tinfo) {
             $keys = array_keys($core->pairs_map);
             $core->LogError("~C91#ERROR:~C00 TickerInfo not found for pair_id %d, can't make MM, allowed pairs: %s", $rec->pair_id, json_encode($keys)); 
             continue;
          }
          $mm = $this->MarketMaker($tinfo->pair_id, $enabled); 
          if (!$mm) continue;

          $mm->enabled = $enabled;
          $mm->mm_delta = $rec->delta;
          $mm->grid_interval = $rec->step;
          $mm->max_orders = max(1, $rec->max_orders);
          $mm->max_mm_cost = $rec->order_cost;
          $mm->max_exec_cost = $rec->max_exec_cost; // value per order in USD
        }   
    }
    protected function CountAttempts($batch_id) {
       $killed = $this->archive_orders->FindByField('batch_id', $batch_id);
       return count($killed);
    }

    public function CheckAccount(string $config_table) {                
      $accounts = sqli()->select_col('account_id', $config_table, "WHERE param = 'exchange' ");  // any default
      foreach ($accounts as $acc_id)
        if ($acc_id == $this->account_id) return;

      if (is_array($accounts) && count($accounts) > 0) { 
          $this->account_id = $accounts[0];
          $this->LogMsg("#DBG: assigned default account_id %d", $this->account_id);
      }    
      else    
          throw new Exception("In table '$config_table' not found exchange parameter.");
          
    }


    public function DispatchOrder(OrderInfo $oinfo, string $ctx = 'DispatchOrder') {
        $st = $oinfo->status;      
        $batch_id = $oinfo->batch_id;      
        $fixed = $oinfo->IsFixed(); 
        $ff = $oinfo->flags & OFLAG_FIXED;
        $partial = (OrderStatusCode::TOUCHED == $oinfo->status_code) || (false !== strpos($st, 'part')); // may be partially filled and cancelled
        $mm = $this->MarketMaker($oinfo->pair_id, false);
        $mm_order = ($batch_id == MM_BATCH_ID);
        $core = $this->TradeCore();

        if (!$fixed && $batch_id > 0)
            $this->GetOrdersBatch($batch_id, 3); // force load batch, if not exists      

        if ('lost' == $st)
            return $oinfo->Register($this->lost_orders);

        $feed = $core->SignalFeed();
        if ($feed && !$oinfo->IsOutbound())   
            if ($feed->DispatchOrder($oinfo, $ctx))
                return true; // нужно передать заявку, если она создана в процессе перемещения через отмену

        if ($oinfo->Cost() < 1000) {
            $n_target = 0;
            if (0 == $oinfo->matched)
                $n_target = 1;
            else
                $n_target = $oinfo->IsFixed() ? 3 : 2;

            if (!$oinfo->IsCanceled() && $oinfo->notified < $n_target) 
                $core->NotifySmallOrderReg($oinfo);             
        }                

        if ($fixed) {
            $partial = false;        
            if ($oinfo->matched > 0) {
            $core->ProcessFilled($oinfo);  
            return $oinfo->Register($this->matched_orders);
            }    
            if (false !== array_search($st, ARCHIVED_STATUSES)) 
                return $oinfo->Register($this->archive_orders);
            $this->TradeCore()->LogError("~C91 #WARN:~C00 can't dispatch order [%s] detected as fixed", strval($oinfo));  
            if ($oinfo->OnError('dispatch') > 5)  
                $oinfo->status = 'lost';
        }  
        elseif (EXT_BATCH_ID == $batch_id) 
            return $oinfo->Register($this->other_orders);

        if ( array_search($st, PENDING_STATUSES) !== false || $partial || $mm) {        
            // Активные сигналы могут обрабатываться и маркет-мейкером (в проекте - всегда) 
            if ($mm) {                             
                if($mm->MakerFor($batch_id) || $mm_order) 
                    return $mm->Register($oinfo);
                else
                    $this->LogMsg("~C91#WARN($ctx):~C00 order %s is not related for MM %s, acceptable batches: %s %s",
                        strval($oinfo), strval($mm), array_keys_dump($mm->batches), array_keys_dump($mm->limit_batches));
            }                 
            elseif ($batch_id == MM_BATCH_ID && !$mm)
                throw new Exception("DispatchOrder: MM order detected, but no exist MM object: ".$oinfo);

            if ($batch_id >= 0)
                return $oinfo->Register($this->pending_orders);
            else
                $this->LogMsg("~C91#WARN_ORPHAN($ctx):~C00 order %s have bad batch", strval($oinfo));
        }    
        $mm_pair = $mm ? strval($mm) : 'none'; // list name
        $fixed = $fixed ? 'fixed' : 'pending';
        $core->LogError("~C91 #WARN($ctx):~C00 can't dispatch %s order [%s], partial = %d, MM = $mm_pair ^ %d, ff = 0x%02x - temparary reg in others ", $fixed, strval($oinfo), $partial, $mm_order, $ff);
        $core->LogMsg("~C91#TRACEBACK:~C00 %s", format_backtrace());      
        if ($oinfo->OnError('dispatch') < 5)
            $oinfo->Register($this->other_orders);
        else {
            $oinfo->status = 'lost';
            $oinfo->Register($this->lost_orders);
        }  
        return false;
    }

    public function FindOrder(int $id, int $pair_id = 0, bool $load = false, float $min_matched = 0): ?OrderInfo {
        $lists = [$this->pending_orders, $this->matched_orders, $this->lost_orders, $this->archive_orders, $this->other_orders];
        if ($id <= 0) return null;
        $result = null;
        foreach ($lists as $list) {
            $result = $list[$id];
            if ($result) break;
        }              

        if (!$result) {
            if ($pair_id > 0 && isset($this->market_makers[$pair_id])) // not need create additional MM here!
            $result = $this->MarketMaker($pair_id)->FindOrder($id);
            else // long scan if unknown         
            foreach ($this->market_makers as $mm) {
                $result = $mm->FindOrder($id);
                if ($result) break;
            }             
        }
        if ($result || !$load)
            return $result;
        return $this->LoadOrder($id, $min_matched);  
    }


    public function FindOrders(string $field, $value, string $source = 'matched,pending,lost,archive,market_maker', int $pair_id = 0): array {
        $result = [];
        $orders = $this->MixOrderList($source, $pair_id);
        foreach ($orders as $id => $info) {
            if (isset($info->$field) && $info->$field == $value)
                $result[$id] = $info;
        }
        // для поиска архивных заявок в БД
        $source = explode(',', $source);      
        if (false !== array_search('db', $source)) {
            $mysqli = $this->sqli();
            $table = $this->TableName('mixed_orders');
            $ids = $mysqli->select_col('id', $table, "WHERE $field = '$value'");
            if (is_array($ids))
            foreach ($ids as $id) 
                $result[$id] = $this->LoadOrder($id);                  
        }      
        return $result;
    }

    
    public function Finalize(bool $eod) {
        $count = 0;
        foreach ($this->market_makers as $mm) {        
            if (0 == $count) 
                $mm->CleanupDB();
            $mm->Finalize();
            $count++;
        }

        $strict_all = "WHERE account_id = {$this->account_id}";// all pending orders for self 
        if ('master' ==  $this->TradeCore()->active_role)
            $this->pending_orders->SaveToDB($strict_all); // replace all exists

        foreach (['archive', 'lost', 'matched', 'pending', 'other'] as $name)
            $this->GetOrdersList($name)->Finalize($eod);      
    }

    public function  Initialize() {
        global $hostname;
        $cname = get_class($this);
        $core  = $this->TradeCore();
        if (!is_object($core))
            throw new Exception('trade_core is unset!');

        if (!is_array($core->pairs_map))
            throw new Exception('trade_core->pairs_map is invalid value!');

        $this->hist_dir = __DIR__.'/data/history';
        check_mkdir($this->hist_dir);           


        $mysqli = $this->sqli();          
        if (!is_object($mysqli)) 
            throw new Exception("Initialize: no DB connection");
           

        $this->host_id = $mysqli->select_value('id', 'config__hosts', "WHERE host = '{$hostname}'");

        $prefix = strtolower("{$this->exchange}__");
        $table = $prefix.'batches';
        if (!$mysqli->table_exists($table))
            $mysqli->try_query("ALTER TABLE {$prefix}signals RENAME TO $table;");

        $tmap_table = $prefix.'ticker_map';
        $ticker_map = $mysqli->select_map('symbol,ticker', $tmap_table); // ticker is global pair code for all bots, like: btcusd, ethusd
        if (0 == count($core->pairs_map)) {
            $core->Shutdown("CRITICAL ERROR");
            throw new Exception("FATAL: empty pairs_map while init");
        }  
        // initialize info map
        foreach ($core->pairs_map as $id => $sym) {
            $tinfo = new TickerInfo($id, $sym, $this);
            $tinfo->trade_coef = $core->pairs_coef[$id];
            $tinfo->cost_limit = $core->ConfigValue('max_order_cost', 1000); // base limit
            if ($ticker_map && isset($ticker_map[$sym])) {
                $tinfo->ticker = $ticker_map[$sym];
                $mysqli->try_query("UPDATE `$tmap_table` SET pair_id = $id WHERE ticker = '{$tinfo->ticker}'");
            }

            $this->pairs_info[$id] = $tinfo;
            $this->pairs_map_rev[$sym] = $id;         

            $price = $mysqli->select_value('price', $table, "WHERE pair_id = $id ORDER BY id DESC");
            if ($price && $price > 0) {
            $tinfo->native_price = $price;
            // $this->native_prices[$pair_id] = $price;
            $this->LogMsg("~C96#INIT:~C00 Assigned native price %10f for %s, from last batch", $price, $sym);
            }
        }

        while (0 == $this->PlatformStatus()) {
            set_time_limit(50);
            $this->LogMsg("~C91#WARN:~C00 Platform is not ready, waiting...");
            sleep(5);
        }  

        // selecting historical DB
        $mysqli_df = $this->sqli('datafeed');       

        $test_table =  strtolower("{$this->exchange}.ticker_map");        
        $exch = strtolower($this->exchange);        
        $kind = 'common';
        if ($mysqli_df->table_exists($test_table)) {        
            $kind = 'dedicated';
            $mysqli_df->select_db($exch);            
        }        

        $this->history_db = $active_db = $mysqli_df->active_db();
        log_cmsg("~C04~C97#INFO:~C00 for historical data assigned $kind exchange DB %s", $active_db);

        if (!$this->LoadTickers()) {
            $this->SetLastError("CRITICAL: LoadTickers failed on engine init");
            $core->Shutdown("FATAL ERROR");
            throw new Exception("LoadTickers failed on engine init"); // first time, required for price update
        }    
        
        check_mkdir(__DIR__.'/'.$this->account_id);         
        $this->LogMsg("~C96#PERF:~C00 Loading orders from DB...");      
        // $this->trade_core->LogMsg("Processing $cname -> Initialize for exchange {$this->exchange}...");
        $this->archive_orders = new OrderList($this, 'archive_orders', true); // canceled without matching
        $this->lost_orders    = new OrderList($this, 'lost_orders');
        $this->matched_orders = new OrderList($this, 'matched_orders', true);
        $this->pending_orders = new OrderList($this, 'pending_orders');
        $this->other_orders   = new OrderList($this, 'other_orders');  // this trash need sometime checking, move to archive

        $strict_all = "WHERE account_id = {$this->account_id}";// load all pending orders for self 
        $this->archive_orders->LoadFromDB();      
        $this->matched_orders->LoadFromDB();
        $this->other_orders->LoadFromDB();

        $this->lost_orders->LoadFromDB($strict_all);
        $this->pending_orders->LoadFromDB($strict_all); 
        

        $pending = $this->pending_orders;
        foreach ($pending as $info)
            $info->exec_attempts = $this->CountAttempts($info->batch_id);

        // make view for common orders tables  
        $lists = [$this->archive_orders, $this->matched_orders, $this->pending_orders, $this->lost_orders, $this->other_orders];
        $this->LogMsg("~C96#PERF:~C00 Upgrading mixed_orders view..."); 
        $query = "CREATE OR REPLACE VIEW `{$prefix}mixed_orders` AS\n";
        foreach ($lists as $i => $list) {
            if ($i > 0) $query .= "\t UNION \n";
            $query .= "\tSELECT id, host_id, ts, account_id, pair_id, batch_id, signal_id, avg_price, price, amount, buy, matched, (amount - matched) as rest, order_no, status, flags, in_position, comment, updated FROM {$list->TableName()}\n";
        }
        $query .= " ORDER BY id DESC;";
        if (!$mysqli->try_query($query)) {         
            throw new Exception("Failed create/replace view {$prefix}__mixed_orders: ".$mysqli->error);
        }
        $this->ConfigureMM(); 

        foreach ($this->batch_map as $batch_id => $batch) {        
            if ($batch->Progress() >= 100) continue;
            $mm = $this->MarketMaker($batch->pair_id, false);
            if ($mm)
                $mm->AddBatch($batch);
        }         

        

    }
    public function IsAmountSmall(int $pair_id, float $amount, float $price = 0, float $min_cost = 0): bool {
        $core = $this->TradeCore();
        $tinfo = $this->TickerInfo($pair_id);
        if (0 == $price) $price = $tinfo->last_price;
        $this->last_scale = 'none';
        $this->last_error = '';
        $qty = $this->AmountToQty($pair_id, $price, $this->get_btc_price(), $amount);

        if (0 == $min_cost) { // no external value set
            $min_cost = $core->ConfigValue('min_order_cost', 10) ;
            if ($tinfo->min_cost > 0)
                $min_cost = min($min_cost, $tinfo->min_cost);
            
            if ($tinfo->min_notional > 0 && $tinfo->lot_size <= 1)
                $min_cost = min($min_cost, $tinfo->min_notional * $tinfo->lot_size * $price);  
        }  

        $cost = abs($qty) * $price;
        if ($tinfo->is_btc_pair)
            $cost *= $this->get_btc_price();

        $qty = $tinfo->FormatQty($qty);
        $info = '';
        $err = strlen($this->last_error) > 0 ? "~C91error:~C97 {$this->last_error}~C00" : '';
        if ($qty <= 0) 
            $info = format_color("~C04~C91qty <= ~C950~C00, min_cost = $%.8f, lot_size = %.8f, price = %s; last_scale: {$this->last_scale} $err",        
                                $min_cost, $tinfo->lot_size, $tinfo->FormatPrice($price));      
        else   
            $info = format_color("qty =~C96$qty~C00, min_cost = $%.8f, cost = $%.8f, mn = %f, lot_size = %.8f, price = %s", 
                                $min_cost, $cost, $tinfo->min_notional, $tinfo->lot_size, $tinfo->FormatPrice($price));

        $this->debug['is_amount_small'] = $info;
        return ($cost < $min_cost);          
    }

    
    public function LoadOrder(int $id, float $min_matched = 0): ?OrderInfo   { // предполагается использовать для подгрузки заявок внешних сигналов
        $result = null;
        $mysqli = $this->sqli();        
        if (is_null($mysqli))
            throw new Exception("LoadOrder: no DB connection");
        $table = $this->TableName('mixed_orders');
        $o_table = $this->TableName('other_orders');
        $strict = "WHERE (id = $id) AND (matched >= $min_matched)";
        $row = $mysqli->select_row('*', $table, $strict, MYSQLI_ASSOC);  
        if (is_null($row))
            $row = $mysqli->select_row('*', $o_table, $strict, MYSQLI_ASSOC);  

        if ($row) {
            $result = $this->CreateOrder($id);
            $fields =  $result->Import($row);
            $tinfo = $this->TickerInfo($result->pair_id);
            if (!$tinfo) return null; // not supported
            $result->pair = $tinfo->pair;                  
            if ('proto' == $result->status)
                $this->LogMsg("~C91#WARN(FindOrder):~C00 proto order %s loaded from mixed_orders", strval($result));

            if ($result->status && $fields > 10)
                $this->DispatchOrder($result, 'DispatchOrder/LoadOrder'); // register in list
            else {
                $info = print_r($result->__debugInfo(), true);
                $this->TradeCore()->LogError("~C91#WARN(FindOrder):~C00 invalid order source: %s, import result ($fields): %s", json_encode($row), $info);
                throw new Exception("FindOrder: invalid order data for $id");            
            }   
        }
        return $result;
    }

    public function MixOrderList(string $select, int $pair_id = 0, bool $include_fixed = true): array {
        $result = [];
        $select = strtolower($select);
        $select = explode(',', $select);
        $source = ['pending' => $this->pending_orders, 
                    'matched' => $this->matched_orders, 
                    'lost'    => $this->lost_orders,
                    'archive' => $this->archive_orders, 
                    'other' => $this->other_orders];


        foreach ($select as $src) {        
            $list = trim($src);          
            $limit = 0;
            if (false !== strpos($src, ':'))
                list($list, $limit) = explode(':', $list);

            $part = [];
            
            if (isset($source[$list]))
                $part = $source[$list]->RawList();
            elseif ($list == 'market_maker') {
                if ($pair_id > 0 && isset($this->market_makers[$pair_id]))
                $part = $this->MarketMaker($pair_id)->GetAllOrders();
                elseif ($pair_id == 0)
                foreach ($this->market_makers as $mm) 
                    $part = array_replace($part, $mm->GetAllOrders());                
            }    
            elseif ($list != 'db') 
                $this->TradeCore()->LogError("~C91#WARN(MixOrderList):~C00 unknown order list '%s' ", $list);

            if ($limit > 0 && count($part) > $limit)    
                $part = array_slice($part, 0, $limit);

            $result = array_replace($part, $result);
            }
        // filtering list 
        $rmap = [];       
        foreach ($result as $id => $oinfo) { 
            $pending = (!$oinfo->IsFixed() && $oinfo->order_no != '0'); 
            if ((0 == $pair_id || $oinfo->pair_id == $pair_id) && ($pending || $include_fixed))
                $rmap[$id] = $oinfo;               
            }     
        return $rmap; 
    }

    public function  GenOrderID (mixed $ts_from = false, string $ctx = ''): int { // generating new unique order id
        global $hostname;
        $mysqli = $this->sqli();
        $core = $this->TradeCore();
        $impl_name = $core->impl_name;            
        $base = time() - strtotime('2025-01-01 00:00:00'); // 1/4 of century start with my counter
        $base = floor($base / 86400); // convert to days
        $base *= $core->ConfigValue('max_orders_per_day', 10000);  // expected limit orders per day,
        $table = 'bot__orders_ids';     
                
        $hosts = $mysqli->select_value('COUNT(*)', 'config__hosts');

        $last_id = 0;   
        $mixed_table = $this->TableName('mixed_orders');


        $strict = "(account_id = {$this->account_id}) AND (busy = 0)";
        $date = date('Y-m-d');
        if ($mysqli->try_query("LOCK TABLES `$table` WRITE;")) 
        try {
            if ($this->last_order_id <= 0) { // once per call               
                if ($mysqli->try_query("LOCK TABLES `$table` WRITE,`$mixed_table` READ;")) {
                $query = "INSERT INTO `$table`(order_id, ts, account_id)\n";
                $query .= "\tSELECT id, ts, account_id FROM  $mixed_table as MT WHERE (id >  0) AND (account_id = {$this->account_id})\n";
                $query .= "\tON DUPLICATE KEY UPDATE busy = 1"; 
                $mysqli->try_query($query);  // recovery missed               
                }  

                $core->LogOrder("~C96#STARTUP:~C00 GenOrderID: checking for last unused order");
                $res = $mysqli->query("INSERT IGNORE INTO `$table` (order_id, account_id, busy) VALUES ($base, {$this->account_id}, 0)");                    

                if ($res && $mysqli->affected_rows > 0)
                    $last_id = $mysqli->insert_id;  // застобил круглый id первым 
                else  
                    $last_id = $mysqli->select_value('order_id', $table,"WHERE $strict AND (DATE(ts) = '$date') AND (order_id > 0) ORDER BY order_id DESC");
            }                   

            $attempt = 0;
            $failed_id = 0;
            if (is_string($ts_from))
                while ($attempt++ < 20) { // аллокация номера из прошлого, если не был задействован ещё. Это может быть медленно!
                $last_id = $mysqli->select_value('order_id', $table, "WHERE $strict AND (ts >= '$ts_from') AND (order_id > $failed_id) ORDER BY order_id");
                $exist = $mysqli->select_value('COUNT(*)', $mixed_table, "WHERE order_id = $last_id");
                if (is_null($exist)) break;
                $failed_id = $last_id;
                $last_id = 0;
                }    
            elseif (is_numeric($ts_from) && $ts_from <= 0) 
                return $last_id - $ts_from; // return last generated id without new generation  
            
            if (0 == $last_id) 
                while ($attempt++ < 10) {        
                if($mysqli->query("INSERT INTO `$table` (account_id, busy) VALUES ({$this->account_id}, 0) -- generate new id")) {
                    $last_id = $mysqli->insert_id;       
                    $this->last_order_id = $last_id;  
                }
                else
                    throw new Exception("GenOrderID: failed to insert row, for generate new order id: ".$mysqli->error);          

                if ($last_id > 0 && ($last_id % $hosts) == $this->host_id) 
                    break; // my turn    
                }            
        } finally {
            $mysqli->try_query("UNLOCK TABLES");
        }      

        if ($last_id <= 0)
            throw new Exception("GenOrderID: failed to generate order id after $attempt attemps");
                
        $this->last_order_id = $last_id;     
        // default id >= 0
        $strict_a = "(account_id = {$this->account_id}) AND (applicant = '$impl_name')";
        $mysqli->try_query("UPDATE bot__activity SET last_order = $last_id WHERE $strict_a");
        $mysqli->try_query("UPDATE $table SET busy = 1 WHERE order_id = $last_id");
        $this->TradeCore()->LogOrder("~C94#INFO:~C00 GenOrderID result = %d %s", $last_id, $ctx);
        return $last_id;
    }

    public function  PlatformStatus() {
        return 0;
    }


    public function  CreateOrder(int $id = 0, int $pair_id = 1, string $comment = '') {   // just instance create, can be overrided
        $id = $id > 0 ? $id : $this->GenOrderID(false, format_color("~C37from CreateOrder(#%d, '%s')~C00", $pair_id, $comment));
        if ($id <= 0) 
            throw new Exception("CreateOrder: failed to generate order id");
        $info = new OrderInfo();
        $info->id = $id;
        $info->host_id = $this->host_id;
        $info->pair_id = $pair_id;
        $info->account_id = $this->account_id;
        $info->comment = $comment;      
        return $info;
    }

    public function  GetOrdersList(string $list): ?OrderList {
        $key = $list.'_orders';
        if (isset($this->$key))
            return $this->$key;
        return null;
    }


    public function LogMsg() {
        $core = $this->TradeCore();
        if ($core instanceof TradingCore)
            $core->LogEngine(...func_get_args());
    }

    public function  LoadCMCPrices() {
        $json = curl_http_request('http://cmc-source.vpn/bot/cm_symbols.php?max_rank=400');
        $core = $this->TradeCore();
        if (strpos($json, '#ERROR') !== false) {
            $core->LogError("~C0C#WARN(LoadCMCPrice):~C00 request failed:\n\t$json");
            return 0; 
        } 
        $cmc = json_decode($json);

        $assigned = 0;
        $outdated = [];
        $ts_min = gmdate(SQL_TIMESTAMP, time() - 60);  // popular coins must update every minute
        if (!is_array($cmc)) {
            $core->LogError("~C91#WARN:~C00 LoadCMCPrices - invalid JSON data from CMC: %s", substr($json, 0, 250));
            return 0;
        }
        foreach ($this->pairs_map_rev as $pair => $pair_id) {
            $pair = strtoupper($pair);
            $tinfo = $this->TickerInfo($pair_id);
            if (false === strpos($pair, 'USD')) continue;
                foreach ($cmc as $rec)
                if (is_object($rec) && strlen($rec->symbol) >= 3 &&
                            strpos($pair, $rec->symbol) !== false)  {
                if ($rec->last_price <= 0) break;

                if ($rec->ts_updated > $ts_min) {
                    $tinfo->cmc_price = $rec->last_price;
                    $assigned ++;
                }
                else {
                    $outdated[$rec->symbol] = $rec->ts_updated;
                    $tinfo->cmc_price = 0;
                }   
                break;
                } // foreach cmc if
        } // foreach pairs   
        $this->LogMsg("~C93 #PERF:~C00 from CMC data assigned %d prices from %d records, outdated (< $ts_min) %s  ", $assigned, count($cmc), print_r($outdated, true));          
        return $assigned;
    }

    public function  LoadOrders(bool $force_all) { // by default load only pending orders if exchange method available
        $this->updated_orders = [];
        return -1;
    }

    public function  LoadTickers() { // fill pairs_info
        return false;
    }

    public function LoadTrades(): int {  // if implemented load latest trades for all pairs
        return 0;
    }

    public function ImportTrades(array $rows): int {
        if (0 == count($rows)) return 0;
        $mysqli = $this->sqli();

        $imported = 0;
        $row = $rows[0];
        $f_fields = 'ts,account_id,pair_id,trade_no,buy,price,amount,flags,comission';                
        $t_fields = 'ts,account_id,pair_id,trade_no,buy,price,amount,order_id,position,rpnl,flags';

        $trades = [];
        $funding = [];

        $t_table = $this->TableName('trades');        
        $f_table = $this->TableName('funding');

            
        $s = '';
        foreach ($rows as $row) {  
            $is_trade = (1 == $row['flags']);
            if ($is_trade) {
                if (!isset($row['rpnl']))
                    $this->LogMsg("~C31 #WARN(ImportTrades):~C00 rpnl field not set for record %s", json_encode($row));                        
                $s = $mysqli->pack_values($t_fields, $row); 
                $trades []= "($s)";     
            }   
            elseif (2 == $row['flags']) {
                $s = $mysqli->pack_values($f_fields, $row); 
                $funding []= "($s)";
            }    
        }

        if (count($trades) > 0) 
            $mysqli->insert_rows($t_table, $t_fields, $trades);
        if (count($funding) > 0) 
            $mysqli->insert_rows($f_table, $f_fields, $funding);      

        return $imported;
    }

    public function  SaveTickersToDB() { // historical info save
        $table = $this->TableName('ticker_history');
        $query = "INSERT IGNORE INTO $table (ts, `pair_id`, `ask`, `bid`, `last`, `fair_price`, `daily_vol`) VALUES\n";
        $lines = [];
        $ts = 'nope';
        $core = $this->TradeCore();
        $is_history = false !== strpos($table, $this->history_db);
        $mysqli = $is_history ? $core->CheckDBConnection('datafeed') : $this->sqli();      
        
        if (is_null($mysqli)) {
            $core->LogError("~C91#ERROR:~C00 SaveTickersToDB failed, no DB connection for %s ", $table);
            return;
        }
        $tinfo = new TickerInfo(1, 'BTCUSD', $this);
        

        foreach ($this->pairs_info as $pair_id => $tinfo) {     
            if (!is_int($tinfo->updated)) 
                $tinfo->updated = strtotime_ms($tinfo->updated); // convert to ms
            if ($tinfo->updated == 0 || !$tinfo->enabled) continue; // never updated

            $tinfo->SaveToDB(); // update runtime rows in __tickers table                                  
            $ts = date_ms(SQL_TIMESTAMP3, $tinfo->updated);
            $pp = $tinfo->price_precision;
            $lines []= sprintf("(\"%s\", %d, %.{$pp}f, %.{$pp}f, %.{$pp}f, %.{$pp}f,  %.2f)", 
                $ts, $pair_id, $tinfo->ask_price, $tinfo->bid_price,  $tinfo->last_price, $tinfo->fair_price, $tinfo->daily_vol);

            $json_file = "{$core->tmp_dir}/{$tinfo->pair}.json"; // for external use / web updates
            if (!file_exists($json_file) || filemtime($json_file) * 1000 < $tinfo->updated)    
                file_put_contents($json_file, $tinfo->SaveToJSON());
        }
        $query .= implode(",\n", $lines).";";

        if (!$mysqli->try_query($query))
            $core->LogError("~C91#ERROR:~C00 SaveTickersToDB failed, last ts = %s, err = %s ", $ts, $mysqli->error);
        
            
        if ('master' != $core->active_role) return;
        if (!$is_history) return; // trading synced via replication
        $remote = $this->sqli('remote');
        if ($remote && $remote->try_query($query) && $remote->affected_rows > 0)
            $this->LogMsg("~C93#INFO:~C00 SaveTickersToDB affected too remote DB %s, last ts = %s ", $table, $ts);
    }

    public function CalcPositions() {
        $core = $this->TradeCore();
        if (0 == count($this->updated_orders)) return;
        $upd_tt = [];
        $diff_t = [];
        foreach ($this->updated_orders as $info)
            $upd_tt []= strtotime_ms($info->updated);

        array_multisort($upd_tt, SORT_ASC, $this->updated_orders); // sort by updated time

        $pcount = 0;
        $mmap = [];
        $mcount = 0;

        // в этой функции всегда должен происходить пересчет инкрементальной позиции, с раздачей результата участвующим заявкам
        // WARN: при отсутствии пересчета, имеют место быть проблемы, отчего out_position вероятно имеет ошибочное значение
        foreach ($this->updated_orders as $info) {        
            $cpos = $this->CurrentPos($info->pair_id, false);                    
            if (0 == $info->matched)
                $info->in_position = $cpos->amount; // sync with current

            if (0 == $info->matched || !isset($info->matched_prev)) continue;
            $mcount ++;
            
            $t_upd = strtotime_ms($info->updated);                  
            $t_inc = $cpos->inc_chg;    // ms time  
            if ($t_inc <= 0) continue;  // never update by API LoadPositions 

            $mmap [$info->id] = date_ms(SQL_TIMESTAMP, $t_upd)."<=".date_ms(SQL_TIMESTAMP, $t_inc);
            $elps = $t_upd - $t_inc; // сколько мс прошло от последнего обновления инкрементальной позиции до изменений в заявке

            if ($elps > 0) {
                $delta = $info->matched - $info->matched_prev;         
                $delta *= $info->TradeSign(); // long or short?           
                $mmap [$info->id] = "{$info->matched_prev}=>{$info->matched}";
                if (0 == $delta ) continue;                             
                $tinfo = $info->ticker_info ?? $this->TickerInfo($info->pair_id);
                $info->matched_prev = $info->matched; 
                if ($info->batch_id > 0) {
                    $batch = $this->GetOrdersBatch($info->batch_id, 3);
                    $batch->need_update = 'matched_orders';
                }    

                $pcount ++;          
                $t_pos = $cpos->time_chk;          

                $outd = 'OUTDATED';
                // OUTDATED means inc position was calcuted before this order changed
                // OUTDATED_BOTH means also current position was updated before this order changed
                if ($t_inc < $t_pos && $t_pos < $t_upd) { // если по каким-то причинам последняя синхронизация инк. позиции была раньше синхронизации позиции с API, устарели оба значения!              
                    $cpos->incremental = $cpos->amount; // reset incremental position
                    $outd = 'OUTDATED_BOTH';
                }        

                $cpos->incremental += $delta;                   
                $cpos->inc_chg = $t_upd;
                $tdiff = ($t_pos -  $t_upd) / 1000; // seconds
                $actual = ($tdiff >= 0);
                $tdiff = abs($tdiff);

                if ($tdiff > 3600)
                    $tdiff = format_color("%.3f hours", $tdiff / 3600);
                elseif ($tdiff > 60)
                    $tdiff = format_color("%.3f minutes", $tdiff / 60);
                else
                    $tdiff = format_color("%.3f seconds", $tdiff);
                $descr = $actual ? '~C97 actual ~C00' : format_color("~C04~C93 $outd~C00 for $tdiff, %s vs %s",
                                            date_ms(SQL_TIMESTAMP3, $cpos->time_chg), $info->updated);         
                $delta = $tinfo->FormatAmount($delta, Y, Y);
                $inc_p = $tinfo->FormatAmount($cpos->incremental, Y, Y);
                $out_p = $tinfo->FormatAmount($info->out_position, Y, Y);
                $curr = $tinfo->FormatAmount($cpos->amount, Y, Y);
                $core->LogOrder("~C92#MATCHED_CHG:~C00 for order %s delta = %3s, inc. pos = %3s, order out = %3s, curr. pos = %s ($descr)", 
                                                        strval($info), $delta, $inc_p, $out_p, $curr);
                $info->out_position = $cpos->incremental; // точнее, чем по запрашиваемым раз в минуту, теоретически
                $info->OnUpdate(); // req: save updates to DB
            }  
            else
                $diff_t []= $elps; // debug info  
        } // foreach
        if ($pcount > 0)
            $this->LogMsg("~C96#CALC_POSITIONS:~C00 processed %d / %d updated orders ", $pcount, count($this->updated_orders));
        elseif ($mcount > 0)  
            $this->LogMsg("~C94#CHECK_POSITIONS:~C00 no touched orders with upd time < pos inc time. Checked %d. Matched map: %s, diff list: %s", 
                            $mcount, json_encode($mmap), json_encode($diff_t));
        $this->updated_orders = [];
    }

    public function  LoadPositions() { // request current positions from exchange account/sub-account
        $core = $this->TradeCore();
        // HERE: just reseting all positions, and load offsets configuration
        foreach ($this->pairs_map_rev as $pair => $pair_id)
        if (!isset($core->current_pos[$pair_id]))
            $core->current_pos[$pair_id] = new ComplexPosition($this, $pair_id);
        $mysqli = $core->mysqli;
        $table = strtolower($this->exchange.'__positions');
        $offset = $mysqli->select_map('`pair_id`,`offset`', $table, "WHERE `account_id` = {$this->account_id}");
        if (is_array($offset))
            $core->offset_pos = $offset;

        return -1;
    }
   
    public function CurrentPos(int $pair_id, bool $value_only = true) {
        $core = $this->TradeCore();
        $result = $core->CurrentPos($pair_id) ?? 0;
        if (is_object($result) && $value_only) 
            $result = $result->amount;            
        return $result;    
    }

    public function  CancelOrder(OrderInfo $info): ?OrderInfo {
        $info->updated = time_ms();
        return null;
    }
    public function CancelOrders(mixed $list) { // batch processor, can be implemented as single transaction
        if (is_object($list) && method_exists($list, 'RawList'))
            $list = $list->RawList();

        foreach ($list as $oinfo)
        if (is_object($oinfo))
            $this->CancelOrder($oinfo);
    }

    public function  MoveOrder(OrderInfo $info, float $price, float $amount = 0): ?OrderInfo { // default scheme, if not implemented in API

        $comment = $info->comment;
        if (false === strpos($comment, 'mv')) // never moved
            $comment .= ', mv'; 
        else     
            $comment = str_replace(', mv', '*', $comment). ', mv';  // moved action now is last

        $comment = str_replace('**', '*', $comment); // remove double dots

        $params = array('price' => $price, 'buy' => $info->buy, 'rising' => $info->rising_pos, 'flags' => $info->flags,
                        'comment' => $comment, 'batch_id' => $info->batch_id);

        if (!$info->IsFixed()) {        
            $info = $this->CancelOrder($info);        
            if ($info)
                $info->OnUpdate();
            else  
                return null;  
        }     

        $core = $this->TradeCore();            
        $rest_amount = $info->amount - $info->matched;
        $rest_qty    = $this->AmountToQty($info->pair_id, $price, $this->get_btc_price(), $rest_amount);
        $tinfo = $info->ticker_info;      
        $out_pos = $info->out_position;
        $config = $core->configuration;

        if ($tinfo) {          
            $vol = $info->price * $rest_amount;
            $cost = $price * $rest_qty;
            if ($rest_amount < $tinfo->min_size || $vol < $tinfo->min_cost || $cost < $config->min_order_cost ) {
                $core->LogOrder("~C91 #WARN:~C00 to small rest of order for replace, amount = %s, qty = %s, cost = %.2f", 
                                $tinfo->FormatAmount($rest_amount, Y, Y), $tinfo->FormatQty($rest_qty, Y), $cost);
                return null;
            }    

            $rest_qty = $tinfo->FormatQty($rest_qty);
            $out_pos = $tinfo->FormatAmount($out_pos);
        }
        $bias = $price - $info->price;
        $amount = $tinfo->FormatAmount($amount > 0 ? $amount : $rest_amount);
        $core->LogOrder("~C03~C96#MOVE:~C00 Order #%d rest_qty = $rest_qty, target price %s, price bias %s, target amount = %s,  position = %s", 
                        $info->id, $tinfo->FormatPrice($price),  $tinfo->FormatPrice($bias), $amount, $tinfo->FormatAmount($out_pos, Y));

        $params['amount'] = $amount * 1;
        $params['in_position'] = $out_pos;            
        $params['predecessor'] = $info->id;
        $params['signal_id'] = $info->signal_id;
        $params['batch_id'] = $info->batch_id;  
        $params['init_price']  = $info->init_price;
        $mig_flags = OFLAG_GRID | OFLAG_LIMIT | OFLAG_DIRECT | OFLAG_ACTIVE | OFLAG_RISING;        
        $params['flags'] = ($info->flags & $mig_flags); // informational

        $res = $this->NewOrder($info->ticker_info, $params );      
        if (is_object($res)) {        
            $res->init_price = $info->init_price;
            $res->was_moved = $info->was_moved + 1;
            $res->comment = str_replace('rp:rp:', 'rp:', 'rp:'.$info->comment);
            $info->comment .= ',rp;'; // replaced
            if (0 == $info->matched)
                $info->avg_price = $info->price; // успел выставиться без исполнения
        }
        else 
            $core->LogError("~C91#ERROR:~C00 MoveOrder/replace failed for %s: ", $info, $this->last_error);
        // $this->DispatchOrder($res, 'DispatchOrder/MoveOrder');  
        return $res;
    }

    public function  LimitAmountByCost(int $pair_id, float $amount, float $max_cost, bool $notify = true) { // стоимость принимается как в долларах, так и в битках
        $tinfo = $this->TickerInfo($pair_id);
        if (!$tinfo)
            return 0;
        $cost = abs($amount) * $tinfo->last_price;
        $result = $amount;
        if ($cost > $max_cost) {
            $result = $max_cost / $tinfo->last_price;
            if ($notify)
                $this->TradeCore()->LogMsg ("~C91#WARN:~C00 due cost %7.5f > max cost limit %7.5f, quantity reduced from %s, to %s", 
                       $cost, $max_cost, $tinfo->FormatQty($amount, Y), $tinfo->FormatQty($result, Y));
        }
        return $result;
    }    

    public function QtyToAmount(int $pair_id, float $price, float $btc_price, float $value) {
        return $value;
    }

    public function AmountToQty(int $pair_id, float $price, float $btc_price, float $value) { // from contracts to real ammount
        return $value;
    }

    public function  NativeAmount(int $pair_id, float $amount, float $ref_price, float $btc_price = 0) {  // calculate in contracts for futures, or another units in descendant
        return $amount;
    }   

    public function ScaleQty($pair_id, $qty) { // полноценный пересчет целевой позиции с сервера, в локальную позицию (не нативную!)
        $tinfo = $this->TickerInfo($pair_id);
        $core = $this->TradeCore();

        if ($tinfo && $qty != 0)  {
            $coef = $core->configuration->position_coef * $tinfo->trade_coef;
            $mult = sprintf("%.5f * %.5f", $core->configuration->position_coef, $tinfo->trade_coef);

            if ($qty < 0) {
                $coef *= $core->configuration->shorts_mult;
                $mult .= sprintf(' * %.f', $core->configuration->shorts_mult);
            }  

            $scaled = $qty * $coef; // debug verification calc         
            $fmt = "pair %s, using coef %.7f (%s) for qty %.3f = ~C04 %f ";
            $this->last_scale = format_color($fmt, $tinfo->pair, $coef, $mult, $qty, $scaled); 
            $this->debug['scale_qty'] = $this->last_scale;
            if (0 == date('i')) 
                $this->LogMsg("#DBG(ScaleQty): {$this->last_scale}");
            return $scaled;
        }
        else {
            if (is_null($tinfo))
                $this->SetLastError("#ERROR(ScaleQty): No TickerInfo for pair_id $pair_id retrieved by engine. Available: ".count($this->pairs_info));
            return 0; // TODO: error/warn
        }   
    }
    

    public function  NewOrder(TickerInfo $tinfo, array $params): ?OrderInfo {
       return null;
    }

    public function  ActiveBatch($pair_id, bool $include_mm = true) {
        $batch = null;      
        if (isset($this->active_sig[$pair_id]) && is_object($this->active_sig[$pair_id])) 
            $batch = $this->active_sig[$pair_id];

        $mm = $this->MarketMaker($pair_id);
        if ($mm && !$batch && $include_mm)
            $batch = $mm->ActiveBatch();

        if ($batch && $batch->active) {
            $hang = $batch->IsHang() || $batch->IsTimedout();
            if (!$hang || $batch->lock > 0) 
                return $batch->id;            
            else         
                $batch->Close($hang ? 'due timeouted/hang' : 'not locked');            
        }
        return false; // int or false
    }


     
    public function  NewBatch(array $proto, int $parent_id = 0): ?int {
        $core = $this->TradeCore();
        $mysqli = $core->mysqli;
        $result = -1;
        
        $table = $this->TableName('batches');

        $prev = $this->ReuseOrdersBatch($proto, $parent_id);
        if (false !== $prev)
            return $prev;


        // TODO: перепаковку надо упростить    
        $pair_id   = $proto['pair_id'];      
        $curr_pos  = $proto['curr_pos'];
        $position  = $proto['target'];
        $pos_ts    = $proto['ts_pos'];
        $price     = $proto['price'];
        $btc_price = $proto['btc_price'];     
        $flags      = $proto['flags'];


        $ts = date(SQL_TIMESTAMP); // new batch - current time

        $vals = array ('account_id' => $this->account_id, 'pair_id' => $pair_id, 'ts' => $ts, 'source_pos' => $proto['src_pos'], 'exec_amount' => 0, 'exec_qty' => 0,
                        'start_pos' => $curr_pos, 'target_pos' => $position, 'price' => $price, 'btc_price' => $btc_price, 'last_order' => 0, 'parent' => $parent_id, 'flags' => $flags);

        $fl = array_keys($vals);
        $columns = implode(',', $fl);
        $query = "INSERT INTO `$table`($columns)\n VALUES(\n";
        $query .= $mysqli->pack_values($fl, $vals).")";
        $qres = $mysqli->try_query($query); // изначально сигнал создается в БД, потом генерируется его объект
        if ($qres) {
            $result = $mysqli->select_value('id', $table, "ORDER BY id DESC");
            $batch = $this->GetOrdersBatch($result);
            $batch->pos_ts = $pos_ts;      
            $batch->max_expand = 5; // 5% for new batch
            $this->active_sig[$pair_id] = $batch;

            $owner = 'engine';
            $mm = $this->MarketMaker($pair_id);
            if ($mm && $mm->enabled) {
            $mm->AddBatch($batch, false); // регистрация, чтобы дочерние заявки могли быть переданы ММ           
            $owner = strval($mm);
            }    
            $this->LogMsg("~C97#NEW_BATCH:~C00 producted~C07 >>>> %s owner: %s <<<<", strval($batch), $owner);        
        }
        else {
            $this->LogMsg("~C91#FAILED:~C00 insert NewBatch {$mysqli->error} via query: $query");
            sleep(15); // pause console
            throw new Exception('NewBatch failed');
        }

        return $result;
    }

    protected function ReuseOrdersBatch(array $proto, int $parent_id = 0) {
        $table = $this->TableName('batches'); 
        $core = $this->TradeCore();
        $mysqli = $core->mysqli;
        $bad_f = BATCH_CLOSED | BATCH_EXPIRED;

        $ts_from = date(SQL_TIMESTAMP, time() - 900); // 15m limit

        $pair_id   = $proto['pair_id'];      
        $curr_pos  = $proto['curr_pos'];
        $position  = $proto['target'];
        $flags      = $proto['flags'];

        $ff = BATCH_LIMIT | BATCH_PASSIVE;  // для таких пакетов, всегда нужен новый экземпляр!
        if ($flags & $ff) return false;
  
  
        $strict = "WHERE (account_id = {$this->account_id}) AND (pair_id = $pair_id) AND (parent = $parent_id) AND (flags & $bad_f = 0) AND (ts > '$ts_from') ORDER BY `id` DESC";
        $row = $mysqli->select_row('id,ts,target_pos', $table, $strict, MYSQLI_ASSOC);
        $last_tgt = false;
        $last_sid = -1;
        $last_sig = false;
        // $this->active_sig[$pair_id]->active
        if (isset($this->active_sig[$pair_id]) )
            $last_sig = $this->active_sig[$pair_id];
        
        $elps = 0;
        if ($row && isset($row['target_pos'])) {
           $last_sid = $row['id'];
           $last_tgt = $row['target_pos'];
           $elps = time() - strtotime($row['ts']);
           if (isset ($this->batch_map[$last_sid]))
               $last_sig = $this->batch_map[$last_sid];  // object ref
        }
  
        // TODO: дублирование математики оптимизации... надо избавляться
        $coef = 0.0001;
        if ($elps < 300)
            $coef = 0.05;
  
        $epsilon = max( abs($position), abs($last_tgt), abs($curr_pos) ) * $coef; // minimal changing value, TODO: detect by minimal trading step / USD cost      
  
        if (false !== $last_tgt && abs($position - $last_tgt) <= $epsilon && $last_sig && !$last_sig->IsTimedout() && $parent_id == $last_sig->parent) {
          $this->LogMsg("~C91#WARN:~C00 attempt to register new batch, but position average same previous %s = %f. Reusing", strval($last_sig),  $position);
          return $last_sid;
        }
        // batch edit: если новая целевая позиция находится между текущей и указанной в последнем сигнале, он подвергается корректировке
        // обязательное условие - сигнал не должен быть исполнен полностью
        if ( $last_sig && $last_sig->active && same_sign($curr_pos, $position, $last_tgt) &&
                abs($curr_pos - $last_tgt) <= $epsilon &&  
                !$last_sig->IsTimedout() && $parent_id == $last_sig->parent ) {
  
          if ( value_between($position, $curr_pos, $last_tgt) || value_between($position, $last_tgt, $curr_pos) ) {
             $this->LogMsg("~C91#WARN:~C00 batch edit condition matched: current_pos = %f, prev_target = %f, new_target = %f, epsilon = %f ", $curr_pos, $last_tgt, $position, $epsilon);
             if ($last_sig->EditTarget($position, true))
                 return $last_sid;
             else
               $core->LogError("Edit target failed, will be generated new batch.");
          }
  
        }
        return false;
    }


    public function GetOrdersBatch(int $batch_id, mixed $force_load = true, bool $check_exist = false): ?OrdersBatch {
            
        $core = $this->TradeCore();
        if (is_bool($force_load))
            $force_load = intval($force_load);

        if (isset($this->batch_map[$batch_id]))
            return $this->batch_map[$batch_id];
        else {
            if ($batch_id <= 0 || !$force_load) return null;        
            $exch = strtolower($this->exchange);
            $table = $exch.'__batches';       
            $mysqli = $this->sqli();
            $exist = $mysqli->select_row('id,ts,flags', $table, "WHERE id = $batch_id", MYSQLI_ASSOC);       
            if ($check_exist && !is_array($exist)) 
                return null;

            if (!is_array($exist) || $exist['flags'] & BATCH_CLOSED && $force_load < 2) 
                return null;
            
            $elps = is_array($exist) ?  time() - strtotime($exist['ts']) : 0;    
            if ($elps > 3600 * 24 * 7 && $force_load < 3)  // 1 week age 
                return null;
                
            try {     
                $this->LogMsg("~C94#DBG(GetOrdersBatch):~C00 creating instance for batch_id = %d ", $batch_id);
                $batch = new OrdersBatch($this, $table, $batch_id); // create instance and reload props from DB         
            }
            catch (Exception $E) {
                $this->TradeCore()->LogException($E, "~C91#FAILED:~C00 GetOrdersBatch(%d), exist = %s", $batch_id, json_encode($exist));  
                $batch = null;
            }  
            return $batch;
        }
    }

    public function OnSetMasterRole()  {
        // синхронизация оперативных данных с репликой, которая надеюсь все успела сохранить в БД 
        $lists = [$this->matched_orders, $this->archive_orders, $this->other_orders, ];
        foreach ($lists as $list) 
            $list->LoadFromDB(); // грузить только свеженькое

        $strict_all = "WHERE account_id = {$this->account_id}";// грузить все что есть        
        $this->pending_orders->LoadFromDB($strict_all);
        $this->lost_orders->LoadFromDB($strict_all);
    }
    public function ProcessMM() {
        $this->TradeCore()->LogMM("~C93#PROCESS_MM:~C00 count configured %d", count($this->market_makers));
        foreach ($this->market_makers as $mm)
            $mm->Process();
      }
    public function ProcessRescuer() {
        $lists = [$this->pending_orders, $this->matched_orders, $this->archive_orders ];
        foreach ($lists as $list) 
            $list->LoadFromDB();
    }  
  
    public function PendingOrders (int $pair_id) {
        $orders = $this->pending_orders->FindByPair($pair_id);
        $mm = $this->MarketMaker($pair_id, false);
        if ($mm)
            $orders = array_replace( $mm->GetExec()->RawList(), $orders); 
        return $orders;
    }

    public function PendingOrdersAll(string $include = 'pending,other,market_maker'): array {
        $result = $this->MixOrderList($include, 0, false); 
        return $result;
    }

    public function  PendingAmount(int $pair_id, bool $sign = true) {
        $result = 0;
        $orders = $this->PendingOrders($pair_id);
        foreach ($orders as $order) {
            $amount = ($order->amount - $order->matched); 
            $result += $amount * ($sign ? $order->TradeSign() : 1);
        }        
        return $result;
    }

    public function RecoveryBatches(int $batch_id  = 0) {
        $core = $this->TradeCore();
        $mysqli = $core->mysqli;
        $exch = strtolower($this->exchange);
        $table = $exch.'__batches';
        $o_table = $exch.'__mixed_orders';
        // $mysqli->try_query("LOCK TABLES `$table` as S WRITE, `$o_table` as MO READ;");
        try {
            // restore last_order
            $query = "UPDATE `$table` S\n";
            $query .= " LEFT OUTER JOIN `$o_table` MO ON MO.batch_id = S.id \n";
            $query .= " SET S.last_order = GREATEST(S.last_order, MO.id)\n";
            $query .= " WHERE (S.last_order = 0) AND (MO.id > 0);";
            $mysqli->try_query($query);

            // restore exec_amount
            $query = "UPDATE `$table` AS OB \n";
            $query .= " INNER JOIN ( SELECT batch_id, SUM(matched) as batch_matched FROM `$o_table` WHERE batch_id > 0 GROUP BY batch_id ) MO ON MO.batch_id = OB.id\n";
            $query .= " SET OB.exec_amount = ABS(MO.batch_matched)\n";            
            $query .= " WHERE (OB.exec_amount <= 0);";
            $mysqli->try_query($query);
            // restore exec_price
            $query = "UPDATE `$table` AS OB \n";
            $query .= " INNER JOIN (  SELECT batch_id, SUM(matched * avg_price) as sig_cost, SUM(matched) as batch_matched FROM `$o_table` WHERE batch_id > 0 GROUP BY batch_id ) MO ON MO.batch_id = OB.id \n";
            $query .= " SET OB.exec_price = ABS(MO.sig_cost / MO.batch_matched), OB.slippage = 0\n";  // needs reset slippage due error
            $query .= " WHERE (OB.exec_price = 0) AND (MO.batch_matched != 0);";

            if (!$mysqli->try_query($query))
                $core->LogError("~C91#FAILED:~C00 query %s result: ".$mysqli->error, $query);

            $rows = $mysqli->select_rows('id,pair_id,exec_price,exec_amount,exec_qty',  "`$table` AS S",
                                        "WHERE (exec_qty = 0) AND (exec_amount > 0)", MYSQLI_ASSOC);
            if (is_array($rows))
            foreach ($rows as $row) {
                $batch_id = $row['id'];
                $amount = $row['exec_amount'];
                $batch = $this->GetOrdersBatch($batch_id);
                if ($batch && !$batch->invalid) {
                    $qty = $row['exec_qty'];
                    $batch->UpdateExecStats();
                    if ($batch->exec_qty != $qty)
                        $this->LogMsg(" for batch %d re-calculated exec_qty = %9f, from exec_amount = %9f; replacing %f ", $batch_id, $batch->exec_qty, $amount, $qty);              
                    elseif ($amount > 0) {                
                        $btcp = $this->get_btc_price();
                        if ($batch->btc_price)  
                        $btcp = $batch->btc_price;
                        $batch->exec_qty = $this->AmountToQty($batch->pair_id, $batch->exec_price, $btcp, $amount);                
                        $this->LogMsg("~C91 #WARN:~C00 exec_qty not calculated for batch %d, exec_amount = %f, forced result = %f", $batch_id, $amount, $batch->exec_qty);
                    }
                    $batch->Save();

                }          
            }
            // TODO: this is uglu hack
            $query = "UPDATE `$table`\n";
            $query .= " SET slippage = exec_price - price\n";
            $query .= " WHERE (target_pos > start_pos) AND (exec_price > 0);";
            $mysqli->try_query($query);
            $query = "UPDATE `$table`\n";
            $query .= " SET slippage = price - exec_price\n";
            $query .= " WHERE (target_pos < start_pos) AND (exec_price > 0);";
            $mysqli->try_query($query);
            $mysqli->try_query("COMMIT;\n");
        }
        catch (Exception $e) {
            $core->LogException($e, "~C91#FAILS:~C00 RecoveryBatches");
        }
        finally {
            // $mysqli->try_query("UNLOCK TABLES;", MYSQLI_USE_RESULT, true);
        }


        // TODO: restore deleted batches with exists orders


    }

    public function SetLastError(string $err, int $code = 0) {
        $mysqli = $this->sqli();
        $this->last_error = $err;        
        $this->last_error_code = $code;
        $core = $this->TradeCore();        
        $strict = "WHERE account_id = {$this->account_id} AND applicant = '{$core->impl_name}'";
        $err = $mysqli->real_escape_string($err);
        $err_256 = substr($err, 0, 255);        
        $mysqli->query("UPDATE `bot__activity` SET `last_error` = '$err_256' $strict");        
        if ('' == $err) return; // not neeed add empty error to DB log
        $class = get_class($this);
        $core->SaveLastError($err, "{$class}->SetLastError", $code );
    }


    public function TrackOrders() { // checking and forcing timeouted orders

        $now = time_ms();
        $core = $this->TradeCore();            

        $timeout = $core->order_timeout * 1000; // in ms
        $this->LoadOrders(false);      
        $plist = $this->MixOrderList('pending'); // ответственность только за локальные заявки
        $checked = 0;

        $this->track_loop ++;
        $verbose = (0 == $this->track_loop % 100);
        $count = count($plist);
        if (0 == $count ) {
            if ($verbose) 
                $this->LogMsg("~C93#OK(TrackOrders): no pending orders now~C00");
            return 0;
        }
        else 
            if ($verbose)
                $this->LogMsg("~C94#PERF(TrackOrders):~C00 pending orders = %d", $count);

        $fskip = BATCH_MM | BATCH_PASSIVE;    

        foreach ($this->batch_map as $batch)
            if ($batch->flags & $fskip)
                continue;
            else
                $batch->idle ++; 

        $overripe = [];

        foreach ($plist as $id => $info) {
            if ($info->IsFixed()) {                       
                $this->DispatchOrder($info, 'DispatchOrder/TrackOrders');          
                $this->LogMsg("~C91#WARN(TrackOrders):~C00 Order [%s] is already fixed, owner = %s", $id, $info->GetList());
                continue;
            }               
            if (!$info->updated) {
                $this->LogMsg("~C91#ERROR:~C00 invalid OrderInfo structure: %s", var_export($info, true));                
                continue;
            }        

            $mm = $this->MarketMaker($info->pair_id);
            if ($mm && $mm->MakerFor($info->batch_id)) {                            
                $mm->Register($info);
                $this->LogMsg("~C91#WARN(TrackOrders):~C00 Order [%s] is market maker order, owner = %s", $id, $info->GetList());
                continue;
            }                  

            if ($info->IsDirect() && $mm) {
                $this->LogMsg("~C91#WARN:~C00 unexpected direct order %s in pending list. MM [%s] failed?", strval($info), strval($mm));  
                continue;
            }

            if ($info->IsLimit() && $mm) {
                $this->LogMsg("~C91#WARN:~C00 unexpected limit order %s in pending list. MM [%s] failed?", strval($info), strval($mm));
                continue;
            }

            $utime = strtotime_ms($info->updated);
            // if ($utime <= 1000) continue;
            $elps = $now - $utime;
            if ($verbose || $elps < 0)
                $this->LogMsg("~C93#DBG(TrackOrders):~C00 Order [%s] pending time~C95 %.1f~C00 sec", strval($info), $elps / 1000.0);

            $checked ++;
            if ($elps >= $timeout)
                $overripe [$id] = 1;
        }

        if (count($plist) > 0)
            $this->LogMsg("~C94 #INFO(TrackOrders):~C00 checked %d / %d pending orders, overripe count %d", $checked, count($plist), count($overripe));

        if (0 == count($overripe))
            return $checked; // nothing for update
        
        $plist = $this->pending_orders->RawList(); // reload clone
        $info = new OrderInfo();

        foreach ($plist as $id => $info)
            if (isset($overripe[$id])) {
            $utime = strtotime_ms($info->updated);
            $elps = $now - $utime;

            if ($info->IsFixed()) {             
                $this->DispatchOrder($info, 'DispatchOrder/TrackOrders-2');
                $core->LogError("~C91#ERROR(TrackOrders-2):~C00 Order [%s] is already fixed, but registered as pending, current owner = %s", $id, $elps / 1000.0,  $info->GetList());
                continue;
            }   

            if ($info->batch_id <= 0)             
                continue;        

            $batch = $this->GetOrdersBatch($info->batch_id, 3);          
            $tinfo = $this->pairs_info[$info->pair_id];
            if (!$tinfo || !$batch) {
                $sdesc = '#object';
                $tdesc = '#object';
                if (!is_object($tinfo))
                    $tdesc = var_export($tinfo, true);
                if (!is_object($batch)) 
                    $sdesc = var_export($batch, true); 
                $core->LogError("#WARN: for pending order %s tinfo = %s, batch = %s ", strval($info), $tdesc, $sdesc);
                continue;
            }

            $price = ($info->buy ? $tinfo->bid_price : $tinfo->ask_price); // above wait_exec below wait_exec * 2

            // TODO: use actual target_pos!
            $elps /= 1000;
            $opt_rec = $core->exec_opt->Process($batch, $batch->target_pos, $elps);
            $batch_elps = $batch->Elapsed();          
            $batch->idle = 0;

            if (is_array($opt_rec)) {            
                $batch_desc = "elapsed $batch_elps sec";
                if ($batch_elps > 1000)
                $batch_desc = '~C94'. strval($batch).'~C00';            
                else 
                $price = $opt_rec['price']; // price may be not accessible on market, or error in optimizer, so using if new batch only

                $core->LogOrder("~C97#MOVE_ORDER(TrackOrders):~C00 Order %s pending time %.1f sec, was updated %s (%d), execute attempts %d, batch $batch_desc, opt result: %s", 
                                strval($info),  $elps, $info->updated, $utime, $info->exec_attempts, json_encode($opt_rec));
            }

            $price_shift = abs($price - $info->price);
            if ( $price_shift <= $tinfo->tick_size )  {
                $core->LogOrder("~C93#WARN:~C00 price not changed = %10f vs %10f, leaving order as is", $price, $info->price);
                $info->updated = date_ms(SQL_TIMESTAMP3);
            }
            else {
                $descr = strval($info);                
                $res = false;
                if ($info->ErrCount('move') < 5)  {
                    $res = $this->MoveOrder($info, $price);
                    $core->LogOrder("#DBG: price_shift = %f, prev = %s, new = %s, trying move [%s]", $price_shift, $info->price, $price, $descr);
                }    

                if (!$res && !$info->IsFixed()) {
                    $this->CancelOrder($info);
                    $core->LogError("~C91 #FAILED_MOVE:~C00 order canceled");              
                } elseif (!$res) {
                    $core->LogError("~C91 #FAILED_MOVE:~C00 order may still penging");                           
                }   
            }
        }

        return $checked;
    }

    public function  TradeCore(): TradingCore {
        assert(!is_null($this->trade_core), "FATAL: TradeCore is null in TradingEngine");
        return $this->trade_core;
    }

    public function  TickerInfo($pair): ?TickerInfo {
        $pair_id = $pair;
        if (is_string($pair) && isset($this->pairs_map_rev[$pair]))
            $pair_id = $this->pairs_map_rev[$pair];

        if (isset($this->pairs_info[$pair_id]))
            return $this->pairs_info[$pair_id];

        foreach ($this->pairs_info as $tinfo)
            if (isset($tinfo->source_pair_id) && $tinfo->source_pair_id == $pair_id)  
                return $tinfo;
        return null;
    }

    public function FindTicker(string $base): ?TickerInfo {
        foreach ($this->pairs_info as $pair_id => $tinfo) {
            $pair = $tinfo->pair;
            if ($base === "#$pair_id")
            return $tinfo;
            $pos = strpos($pair, $base);
            if ($pos < 1 && $pos !== false)
            return $tinfo;
        }
        return null;  
    }



    public function SyncDB() {
        $this->archive_orders->SaveToDB();
        $this->matched_orders->SaveToDB();
        $this->pending_orders->SaveToDB();
        $this->other_orders->SaveToDB();
    }

    public function TableName(string $suffix, bool $check_exists = true, mysqli_ex $mysqli = null): string {
        $table = $suffix;
        if ($mysqli === null)
            $mysqli = $this->sqli();
        if ($mysqli === null || !$check_exists)  return $table;
        if ('ticker_map' == $suffix && xdebug_connect_to_client())
            xdebug_break();
        
        $table_qfn = $suffix;
        if (!str_in($suffix, $this->exchange))        
            $table_qfn = strtolower($this->exchange.'__'.$suffix); 

        if ( $mysqli->table_exists($table_qfn) && 'trading' == $mysqli->active_db() )  // fully qualified name typically in trading DB
            return $table_qfn;
        
        if ($this->history_db == $mysqli->active_db())  // historical DB in last implementation is dedicated, so not need exchange prefix
            $table = str_replace( "{$this->history_db}__", '', $table);

        if ($mysqli->table_exists($table))
            return $table;

        $hist_table= "{$this->history_db}.$table";  
        $core = $this->TradeCore();
        $mysqli = $core->mysqli_datafeed;
        if (!$mysqli)
            throw new Exception("FATAL: no established datafeed connection" );
        if ($mysqli->table_exists($hist_table))
            return $hist_table;  

        $hist_table= strtolower("{$this->history_db}.{$this->exchange}__$table");  
        if ($mysqli->table_exists($hist_table))
            return $hist_table;  

        return "#ERROR: not_exists $table and $hist_table";  
    }

    public function Update() {
        foreach ($this->batch_map as $batch)
            $batch->Update();
    }

  }