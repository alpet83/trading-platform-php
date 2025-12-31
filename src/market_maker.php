<?php
  require_once "lib/common.php";
  require_once "orders_lib.php";
  require_once "trading_core.php";

  

  function get_nearest(array &$list, float $price, float $intv): ?OrderInfo {
    $result = null;
    $min_diff = $intv;
    foreach ($list as $oinfo)
      if ($oinfo) {
        $diff = abs($price - $oinfo->price);
        if ($diff < $min_diff) {
            $min_diff = $diff;
            $result = $oinfo;
        }
      }
    if ($result)
        unset($list[$result->id]); // extraction
    return $result;
  }

  function count_keys(array $list): int {
    $keys = array_keys($list);
    return count($keys);
  }

  function claim_deeper(array &$list, int $level, bool $buy): ?OrderInfo {
    $kl = min(array_keys($list));
    $ku = max(array_keys($list));    
    $from = $buy ? $kl : $ku;  // покупки отбирать снизу, продажи сверху
    $step = $buy ? 1 : -1;  // из минусовой зоны вверх, и наоборот         
    $count = count($list);
    while ( $from != $level && $count -- > 0) {
      $oinfo = $list[$from] ?? null;
      if (is_null($oinfo)) { 
         $from += $step;
         continue;     
      } 
      $list[$from] = null;
      $oinfo->level = $level;
      return $oinfo;
    }
    return null;
  }

  function dump_orders_info(array $orders, array $levels) {
    $res = [];
    foreach ($orders as $i => $oinfo) {        
        $lp = isset($levels[$i]) ? $levels[$i] : '-';
        $s = sprintf("N@%s", $lp); // absent any order
        if ($oinfo) {
          $price = $oinfo->price;
          $amount = $oinfo->matched;
          if ($oinfo->ticker_info) {
              $price = $oinfo->ticker_info->FormatPrice($price);   
              $amount = $oinfo->ticker_info->FormatAmount($amount, Y, Y);
          }     
          $bs = $oinfo->buy ? 'B': 'S';
          if (!$oinfo->matched) {
              $bs = strtolower($bs);
              $amount = $oinfo->amount; 
          }     
          $s = sprintf('%s@%s=%s:%s', $bs, $price, $amount, substr($oinfo->status, 0, 1));
        }  
        $res []= $s;            
    }
    return implode(', ', $res);
  }

  class MarketMaker {

    protected $asks = null; // OrdersBlock 
    protected $bids = null; // OrdersBlock    
    protected $exec = null; // OrdersBlock for out of MM orders (bias correction)
    protected $limit = null; // for take profit and may be grid orders
    
    public $grid_interval = 0.5; // in percent
    public $exec_interval = 0.1; // in percent

    public $mm_delta = 1; // first order delta in percent
    
    public $max_orders = 4; // for each orders block

    public $max_mm_cost = 100; // USD cost for each order in MM block

    public $max_exec_cost = 5000; // USD cost for each order in exec block

    protected $tinfo = null;

    protected $engine = null;
    protected $added = false; // only if new orders added

    protected $movs = []; // stats for orders movements

    protected $opt_rec = []; // execution optimization record    

    public $batches = []; // batch map, for bias orders
    public $limit_batches = []; // batches map for limit orders

    protected $batch_cache = []; // also for limit orders, with keys like 'bids@sig_id'

    public  $active_batch = 0;

    public $mm_errors = 0;
    public $enabled   = false; // allow post or move orders

    private $bp_source = 'nope';
    private $base_price = 0;

    
    private $mm_cycles = 0;
    
    protected $indent = '';


    public function __construct(TickerInfo $ti, TradingEngine $engine) {
        $this->tinfo = $ti;
        $this->engine = $engine;
        $this->asks = new OrdersBlock($engine, 'mm_asks', false);
        $this->bids = new OrdersBlock($engine, 'mm_bids', true);
        $this->exec = new OrdersBlock($engine, 'mm_exec', true);
        $this->limit = new OrdersBlock($engine, 'mm_limit', true);
        $this->exec->mm = false;
        $this->limit->mm = false;
        $this->asks->verbosity = $this->bids->verbosity = 3;

        $this->LoadFromDB();                
        foreach ($this->exec as $oinfo) {
            $batch = $engine->GetOrdersBatch($oinfo->batch_id);
            if ($batch && !$batch->IsClosed()) $this->AddBatch($batch);
        }          
        $this->LogMsg("~C92#INIT_MM~C00(%s): active bids, asks, exec = %d, %d, %d ", $ti->pair, $this->bids->Count(), $this->asks->Count(), $this->exec->Count());        
    }

    protected function LogMsg() {
        $args = func_get_args();
        $args [0] = $this->indent.$args[0];
        $this->engine->TradeCore()->LogMM(...$args);
    }

    private function price_delta(): string {
        $ti = $this->tinfo;
        $res = $this->base_price / 100 * $this->mm_delta;
        return $ti->FormatPrice($res);
    }
    private function price_intv(bool $mm = true) {
        $ti = $this->tinfo;
        $intv = $mm ? $this->grid_interval : $this->exec_interval;        
        $res = $this->base_price / 100 * $intv;
        $rv = $ti->FormatPrice($res, false, 0);
        if (0 == $rv)
            $this->LogMsg("~C91 #WARN:~C00 price interval rounded to zero from %f, base price = %f", $res,  $this->base_price);
        return $rv;
    }

    public function ActiveBatch(bool $select = false): ?OrdersBatch {
        
        $purge = [];
        ksort($this->batches);
        $ignore_flags = BATCH_LIMIT | BATCH_PASSIVE | BATCH_CLOSED;

        if ($select && $this->exec->Count() > 0) { // при исполнении обычно активная пачка заявок владеет самой ближней к последней цене заявкой           
           $ref = $this->tinfo->last_price;
           $best = $ref;
           $nearest = null;
           foreach ($this->exec as $oinfo)  {
             $dist = abs($oinfo->price - $ref);
             if (is_null($nearest) || $dist < $best) {
                $nearest = $oinfo;
                $best = $dist;
             }   
           }
           if ($this->active_batch != $nearest->batch_id) 
              $this->LogMsg("~C94#ACTIVE_BATCH:~C00  for select used orders %s, nearest to last price = %s",
                             strval($nearest), $this->tinfo->FormatPrice($nearest->price));
           $this->active_batch = $nearest->batch_id;
        }   

        // проверка пакетов заявок на их устаревание/заполнение
        foreach ($this->batches as $bat_id => $batch) {
           if ($batch->long_lived || $batch->flags & $ignore_flags)  continue; // этот сигнал пассивный )           
           $orders = $this->exec->FindByField('batch_id', $bat_id);           
           if (count($orders) > 0) continue;  // пока не трогать

           $batch->UpdateExecStats(); // all native tables
           $batch->UpdateExecStats($this->exec->TableName()); // add local table

           $sp = $batch->Progress();
           $left = $batch->TargetLeft();            
           $left_cost = $this->tinfo->last_price * abs($left);           
           if ($batch->IsClosed()) {
              $purge [$bat_id] = format_color("~C94BATCH_PURGE:~C00 closed [%s] at %.1f%%, left cost = %.2f, removing from active list", strval($batch), $sp, $left_cost);
              continue;           
           }
           

           if (($batch->IsHang() || $left_cost < 15)) {              
              $purge [$bat_id] = format_color("~C94BATCH_PURGE:~C00 looks completed/hang [%s] at %.1f%%, left cost = %.2f, removing from active list", strval($batch), $sp, $left_cost);
              continue;
           }
           if ($batch->IsTimedout() && $left_cost < 100) {
            $batch->flags |= BATCH_EXPIRED;
            $purge [$bat_id] = format_color("~C94#BATCH_PURGE:~C00 signifcant lag [%s] at %.1f%%, left cost = %.2f, removing from active list", strval($batch), $sp, $left_cost);            
            continue;
           }       
           $batch->idle ++;           
        }

        if ($this->active_batch && isset($purge[$this->active_batch])) 
            $this->active_batch = 0;                 

        if (date('i') == 0)
        foreach ($purge as $batch_id => $msg) {
           $this->LogMsg($msg);
           $this->batches[$batch_id]->Close();  
           unset($this->batches[$batch_id]);
        }   

        if (isset($purge[$this->active_batch])) {
            $this->active_batch = 0;
            return null;
        }
        return array_value($this->batches, $this->active_batch, null);
    }

    public function AddBatch(OrdersBatch $batch, bool $open_orders = false, bool $set_active = false) {              
       if (is_null($batch) || $batch->IsClosed()) return false;

       $exists = false;
       if ($batch->flags & BATCH_LIMIT)  {
         $exists = isset($this->limit_batches[$batch->id]); // already added for limit
         $this->limit_batches[$batch->id] = $batch;
       } else {        
          $exists = isset($this->batches[$batch->id]); // already added for limit
          $this->CloseBatches($this->batches); // close previous MM exec batches, for prevent concurent MM orders
          $this->batches[$batch->id] = $batch;
       }

       $batch->flags |= BATCH_MM; // mark as MM batch

       if ($set_active)
          $this->active_batch = $batch->id;
       if (!$exists) 
          $this->LogMsg("~C94#BATCH_ADD:~C00 %s, total %d", strval($batch), count($this->batches));        
       if ($open_orders)
          $this->OpenOrders($this->exec, $this->max_orders - $this->exec->Count()); 
       return true;  
    }    

    protected function Adoption() {
        $ti = $this->tinfo;
        $engine = $this->engine;
        $pending = $engine->MixOrderList('pending', $ti->pair_id);
        foreach ($pending as $oinfo)
          if ($ti->pair_id == $oinfo->pair_id && $this->Register($oinfo))
              $this->LogMsg("~C94#ADOPTED_MM:~C00 %s", strval($oinfo));
    }
    public function BatchesCount(): int {
        return count($this->batches);
    }
    protected function AdjustBlock(OrdersBlock $block) {
        /* здесь должна происходить корректировка (сдвиг) одной заявки, если блок оказался достаточно далеко от целевой сетки. Восстанавливает целевое количество
          функция блокируется, если есть активные сигналы с той-же направленностью, т.к. заявки блока могут быть задействованы для сетки исполнение
        */
        $ti = $this->tinfo;
        $name = $block->Name();        
        $mm = $block->mm;
        $fast = !$mm;        
        $this->LogMsg("~C93 #TICKER_INFO:~C00 tick_size = %f, price_precision = %f, last_price = %10f  ", $ti->tick_size, $ti->price_precision, $ti->last_price);    
        
        $block->interval = $mm ? $this->grid_interval : $this->exec_interval;

        $batch = $this->ActiveBatch($fast);            
        $delta = $fast ? 0 : $this->price_delta();
        $dir = $block->buy_side ? -1 : 1; // buys all under bid, sell all over ask
        $intv = $this->price_intv($mm) * $dir; // 0.1% or 0.5% for MM, may be configured in DB
        
        $need_more = $mm || $batch != null;
        

        if ($block->Count() < $this->max_orders && $this->enabled && $need_more) {          
            $max = $fast ? $this->max_orders - $block->Count() : 1; // all / single
            $this->OpenOrders($block, $max); // открыть дополнительные заявки в сетке      
        }

        if (0 == $block->Count()) return; // no active orders
        // все ордера активные: проверка необходимости сдвига
        // в режиме маркет-мейкера заявки передвигаются, если выходят за границы сетки
        // в режиме исполнения заявок как-правило меньше, чем размеры сетки 
        // в любом случае, дальние границы сужены до количества активных ордеров в блоке.
        $max_depth = $mm ? 0.5 : 3;
        $grid = $this->CalcGrid($block, $max_depth);

        $base_price = $this->base_price; // updated by CalcGrid
        $start_price = $block->buy_side ? $base_price - $delta : $base_price + $delta;                
        

        $near_bound = $start_price - $intv;                
        $far_bound  = $start_price + $intv * ($block->Count() + 1);        
        $inbound = [];
        $outbound = [];        
        
        $count = 0;
        $list = $block->RawList();
        $nearest = null;

        foreach ($list as $id => $oinfo) {
            $shift = false;
            $owner = $oinfo->GetBlock();
            if ($oinfo->IsFixed() || $oinfo->IsLimit()) continue;
            if ($oinfo->Elapsed('created') < 60) continue; // not need to move fresh orders

            if ($name !== $owner->Name()) {
                $this->LogMsg("~C91#ERROR:~C00 magically order %s with owner %s processing in block %s", $oinfo, $owner->Name(), $name);
                $oinfo->Unregister($block, "Invalid location");
                continue;
            }
            if ($owner !== $block)
               throw new Exception(sprintf("#ERROR: Order %s owned by block %s", $oinfo, $block->Name()));
               
            $count ++;           
            $price = $oinfo->price; // $ti->FormatPrice()
            $dist = abs($price - $ti->last_price);
            if (!$nearest || abs($price - $nearest->price) > $dist)
                $nearest = $oinfo;            
            
            if ($block->buy_side) 
               $shift = ($price > $near_bound || $price < $far_bound); // заявки на покупку должны быть ниже ближней границы, и выше дальней границе            
            else
               $shift = ($price > $far_bound || $price < $near_bound); // заявки на продажу должны быть выше ближней границы, и ниже дальней границе


            if ($shift) {
               $outbound []= sprintf('%5d:%s:%s', $id, $oinfo->status, $price);
               $this->AdjustOrder($oinfo, $grid); // выбрать лучшее место в сетке               
            }   
            else {
                $sp = $ti->FormatAmount($oinfo->Pending(), Y, Y);
                $inbound []= sprintf('%5d:%s@%5G', $id, trim($sp), $price);                    
            }   
        }        

        if (count($inbound) + count($outbound ) == 0) return; // no report about nothing

        // $ids = array_keys($list);
        $ninfo = "none";
        if ($nearest)
            $ninfo = sprintf("%s@%s:%s", $ti->FormatAmount($nearest->amount, Y, Y), 
                                     $ti->FormatPrice($nearest->price), $ti->FormatPrice($nearest->price - $ti->last_price)); // id:price:delta

        $pending = $block->PendingAmount();
        $bs = $block->buy_side ? '~C42~C97 BUY ~C00' : '~C41~C97 SELL ~C00';
        $bp = $ti->FormatPrice($base_price).':'.$this->bp_source;
        if ( count($outbound)  > 0)
           $this->LogMsg("~C96#TRACKING:~C00 $bs %s@%s range %s..%s inbound = %s, outbound = %s, base = %s, delta = %s, interval = %s, active count = %d, pending total = %s, nearest = %s", 
                                $name, $ti->pair, $ti->FormatPrice($near_bound), $ti->FormatPrice($far_bound), 
                                json_encode($inbound), json_encode($outbound), $bp, 
                                $delta, $intv, $count, $ti->FormatAmount($pending, Y, Y), $ninfo); 
        else                              
          $this->LogMsg("~C94#TRACKING_PASS:~C00 $bs %s@%s range %s..%s inbound = %s, base = %s, delta = %s, interval = %s, active count = %d, pending total = %s, nearest = %s", 
                            $name, $ti->pair, $ti->FormatPrice($near_bound), $ti->FormatPrice($far_bound), 
                            json_encode($inbound), $bp, $delta, $intv, $count, $ti->FormatAmount($pending, Y, Y), $ninfo); 

    } 

    protected function AdjustOrder(OrderInfo $oinfo, array $grid) {
        $engine = $this->engine;   
        $core = $engine->TradeCore();
        $block = $oinfo->GetBlock();
        $name = $block->Name();
        $ti = $this->tinfo;
        $mm = $block->mm;

        if ($mm && $this->mm_errors > 5) return false;

        if ($oinfo->batch_id > 0 && !$this->ActiveBatch()) {
           $batch = $engine->GetOrdersBatch($oinfo->batch_id, 3);
           if ($batch)
              $this->LogMsg("~C91#WARN:~C00 no active batch, while adjust order %s ", strval($oinfo));
           else  
              throw new Exception(sprintf("For order %s can't retrive batch #%d object", strval($oinfo), $oinfo->batch_id));
           $this->batches [$oinfo->batch_id]= $batch; 
        }   
        
        $intv = $this->price_intv($mm);
        $last_id = $oinfo->id;        
        $mapped = $this->MapOrders($block,$grid, $intv);
        $move = $this->enabled;        
        $ext_sig  = $core->SignalFeed()->FindByOrder($oinfo);      
        // по стратегии нужно стремиться заполнять наиболее ближние уровни сетки
        foreach ($mapped as $i => $id) {
            if ($id > 0 && $id != $last_id) continue; // заменить можно только заявку которую двигать надо
            $price = $ti->FormatPrice($grid[$i]);
            $bp = $ti->FormatPrice($this->base_price);
            $diff = $ti->FormatPrice($price - $ti->last_price);            
            $shift = $ti->FormatPrice($price - $oinfo->price);
            $this->movs []= sprintf("%s: %s %s => %s ", tss(), $name, $oinfo->price, $price);
            if (0 == $shift) continue; // no need to 
            
            if ($move) {                              
                if ($oinfo->batch_id > 0)
                    $this->active_batch = $oinfo->batch_id;
                $this->LogMsg("~C97 #MOVE_ORDER(%s@%s):~C00 grid[%d] => %s, order %s, interval = %s, diff = %s, shift = %s, batch_id = %d, prev_moves = %d, base_price = %s, bp_source = %s", 
                                    $name, $ti->pair, $i, $price, strval($oinfo), $intv, $diff, $shift, $oinfo->batch_id, $oinfo->was_moved, $bp, $this->bp_source);
                $res = $engine->MoveOrder($oinfo, $price);     
                if (is_object($res)) {
                    $res->flags |= $oinfo->flags; // keep flags
                    if ($ext_sig)
                        $ext_sig->AddOrder($oinfo, 'AfterMove');
                }   
            }  
            else {
               $this->LogMsg("~C91 #KILL_ORDER(%s@%s):~C00 order %s, due MM was disabled", $name, $ti->pair, strval($oinfo));  
               $res = $engine->CancelOrder($oinfo);
            }  

            if (is_object($res) && $last_id != $res->id) {
                $oinfo->Unregister($block, "Replaced by MoveOrder");
                $res->Register($block);
            }              
            return $res;
        }   

        $this->LogMsg("~C91#WARN($name):~C00 order %d not moved, no free space in grid %s", $oinfo->id, json_encode($mapped));
        return false;
    }  

    protected function BasePrice() {
        $ti = $this->tinfo;
        $res = 0;

        if ($ti->fair_price > 0)  {
           $this->bp_source = 'fair_price'; 
           $res = $ti->fair_price; 
        } else {            
           $res =  $ti->last_price;         
           $this->bp_source = 'last_price';
           if ($ti->mid_spread > 0) {
             $res = $ti->mid_spread;
             $this->bp_source = 'mid_spread';
           }
        }              
        if ($ti->bid_price > $res) {
           $res = $ti->bid_price;
           $this->bp_source = 'bid_price';
        }       

        if ($ti->ask_price < $res) {
            $res = $ti->ask_price;
            $this->bp_source = 'ask_price';
        }                       
        return $res;
    }

    protected function CalcGrid(OrdersBlock $block, float $max_depth = 1): array {
        $mm = $block->mm;
        $ti = $this->tinfo;
        $buy_side = $block->buy_side;
        if (0 == $ti->last_price) return [];
        /*   Функция создает целевую сетку, от которой можно выравнивать существующие активные заявки, либо добавлять (постепенно) при незаполненном блоке. 
             Если результат требуется не для маркет-мейкера, а для исполнения, то используется малый интервал с поправкой на время исполнения сигнала.
             
        TODO: здесь должна развернуться обработка оптимизации исполнения сигнала, с коррекцией дельты вплоть до отрицательной!
        
        */
        $core = $this->engine->TradeCore();
        $base_price = $this->BasePrice();
        $this->base_price = $base_price;
        $delta = $this->price_delta();
        $delta *= $mm ? 1 : 0.01;
        $this->opt_rec = [];        
        
        $gain = $base_price - $ti->last_price;
        if (abs($gain) > $ti->last_price * 15)  {
          $this->LogMsg("~C91#ERROR:~C00 gain for %s is too large = %f, using last price %s ", $ti->pair, $gain, $ti->last_price);  
          $base_price = $ti->last_price;
        }  

        $intv = $this->price_intv($mm);   
        if ($ti->bid > 0 && $ti->ask_price > $ti->bid_price) {
            $market = $buy_side ? $ti->bid_price - $base_price : $ti->base_price - $ti->ask_price;  // глубина выхода из спреда для базовой цены
            $depth = $intv + $delta;
            if ($market > $depth * $max_depth) {
                $this->LogMsg("~C91#ERROR:~C00 for %s base_price %f may extreme, expected slippage up to %f > %f ", $ti->pair, $base_price, $market, $depth);              
                $base_price = ($ti->ask_price + $ti->bid_price) / 2;
            }
        }

        $min_price = $base_price * 0.5;

        if (!$mm && $this->ProcessOpt() && array_key_exists('price',$this->opt_rec )) {
            $delta = 0;  
            $base_price = $this->opt_rec['price'];                         
            $this->bp_source = 'exec_opt';  // TODO: delta need also correction to negative, if batch elapse to large
        } elseif (!$mm)
            $this->LogMsg("~C91#WARN_CALC_GRID(%s):~C00 base price not optimized for faster execution, active batch = [%s] ", $block->name(), strval($this->ActiveBatch()));

        if ($base_price <= 0) {
            if ($ti->mid_spread > 0) {
                $base_price = $ti->mid_spread;
                $this->bp_source = 'mid_spread';
            }    
            elseif ($ti->last_price > 0) {
                $base_price = $ti->last_price;
                $this->bp_source = 'last_price';
            }    
            else {
                $this->LogMsg("~C91#ERROR:~C00 base price for [%s] returned from %s is invalid = %f, can't calculate grid", strval($ti), $this->bp_source, $base_price);
                return [];
            }    
        }
        

        return $this->CalcGridEx($buy_side, $base_price, $delta, $intv, $this->max_orders, $block->Name());
    }
    protected function CalcGridEx (bool $buy_side, float $base_price, float $delta, float $intv, int $count, string $ctx): array {
        $ti = $this->tinfo;

        if (0 == $base_price) {
            $this->LogMsg("~C91#ERROR_GRID_CALC:~C00 for %s %s base price is zero", $ti->pair, $ctx);
            return [];
        }
        $dir = $buy_side ? -1 : 1; // buys all under bid, sell all over ask                            

        $intv *= $dir;
        $this->base_price = $base_price; // save last used
        $start_price = $buy_side ? $base_price - $delta : $base_price + $delta;
        if ($start_price <= 0) 
            throw new Exception("For grid start price $start_price is invalid, base price = $base_price, delta = $delta, last price = ".$ti->last_price);

        $result = [];               
        $prev = 0;
        $pp  = 8;
        if ($ti->price_precision > 1)
            $pp = $ti->price_precision;
        if (0 == $intv) {
          $this->LogMsg("~C91#ERROR_GRID_CALC:~C00 for %s %s rounded interval is zero, params grid %f, exec %f, price %s, can't calculate grid",
                                     $ti->pair, $ctx, $this->grid_interval, $this->exec_interval, $this->base_price);   
          return [];
        }              

        for ($i = 0; $i < $count; $i++) {
          $quote = $start_price + ($i * $intv);                    
          $quote = round($quote, $pp);
          if ($quote == $prev)
             throw new Exception("For grid price $quote is equal to previous $prev at step $i; price precision = $pp, interval = $intv");
          if ($ti->tick_size > 0)
             $quote = floor($quote / $ti->tick_size) * $ti->tick_size; 
          
          $result []=  $ti->RoundPrice($quote);
          $prev = $quote;  
        }  
        if (false === strpos($ctx, 'mm_')) {
           $bat_count = count($this->batches);
           $this->LogMsg("~C95#CALC_GRID_EXEC(%s):~C00 %s  base_price = %10f from %s, delta = %s, interval = %s, batches = %d, opt: %s, result: %s ", $ctx,
                        $ti->pair, $base_price, $this->bp_source, $delta, $intv, $bat_count, json_encode($this->opt_rec), json_encode($result));
        }   
        return $result;
    }

    protected function CheckOrders(OrdersBlock $block) {
        global $fixed_order_st;                 
        $name = $block->Name();
        $engine = $this->engine;
        $core = $engine->TradeCore();
        $purge = [];
        $list = $block->RawList();        
        $ids = array_keys($list);
        $mm = $block->mm;
        // $this->LogMsg("~C94#CHECK_ORDERS(%s):~C00 in list orders = %s", $name, json_encode($ids));
        $oinfo  = $this->FindOrder(0); 

        $ids = array_keys($this->GetAllOrders());

        $left = 0;
        $pending = 0;
        $batch = $this->ActiveBatch(!$mm);
        $ext = false;
        $check = false;
        if ($batch && !$mm) {           
            $ext = $batch->parent > 0;            
            $left = abs($batch->TargetLeft());            
        }    

        foreach ($list as $oinfo)
          if (is_object($oinfo)) {
             if ($oinfo->IsFixed() || $oinfo->GetBlock() !== $block) {
               $purge []= $oinfo;
               continue;
             }     
             if ($oinfo->IsLimit()) continue; // не учитывать в подсчете, что тут он делает вообще?

             $ext_sig  = $core->SignalFeed()->FindByOrder($oinfo);     
             if ($ext_sig) {
                $bias = $ext_sig->TargetDeltaPos() - $ext_sig->CurrentDeltaPos();
                $small = abs($bias) <= $ext_sig->min_bias;
                $wrong = signval($bias) == -$oinfo->TradeSign() && abs($bias) >= $ext_sig->min_bias;
                if ($small || $wrong) {                
                    $this->LogMsg("~C97#MM_OPT_KILL:~C00 bias signal %s < %6f or direction opposite batch %s, cancelling excessive order %s", 
                                            strval($ext_sig), $ext_sig->min_bias, strval($batch), strval($oinfo)); 
                    $engine->CancelOrder($oinfo);                                        
                    if ($wrong && $batch) 
                        $batch->Close();
                }                   
             }

             $ref = $this->FindOrder($oinfo->id);
             if (!$ref) 
                $this->LogMsg("~C91#ERROR:~C00 can't find order %s in %s", strval($oinfo), json_encode($ids) );

             

             if ($oinfo->Elapsed('updated') > 500000 && !$mm) {
                 $this->LogMsg("~C91#WARN:~C00 order %s elapsed %.1f minutes, seems exec hang - killing", strval($oinfo), $oinfo->Elapsed() / 60000);

                 $engine->CancelOrder($oinfo);                 
             }                   
             $pending += $oinfo->Pending();            
             $check = $batch && $batch->id == $oinfo->batch_id && !$ext;              
             if (!$mm && $pending > $left && $check && !$oinfo->IsDirect()) {
                $this->LogMsg("~C91#WARN:~C00 pending amount %8G > left %8G, cancelling delta-order %s", $pending, $left, strval($oinfo));
                $engine->CancelOrder($oinfo);    
             }             
          }   

        foreach ($purge as $oinfo) {
            $this->LogMsg("~C93#MM_ORDER_PURGE($name):~C00 order id #%d detected status %s", $oinfo->id, $oinfo->status);
            $oinfo->Unregister($block);
            $this->engine->DispatchOrder($oinfo);
        }
    }
    public function ClaimOrders(bool $buy_side, float $total_amount): array  {
        /*
          извлечение самых близких к спреду заявок, пока не будет заполнен объем в массив. Решение для переноски из MM блока в Exec
         */
        $result = [];
        return $result;
    }

    protected function CloseBatches($list) {
       foreach ($list as $batch)
          $batch->Close();
    }

    public function GetAsks(): OrdersBlock {
        return $this->asks;
    }
    public function GetBids(): OrdersBlock {
        return $this->bids;
    }
    public function GetExec(): OrdersBlock {
        return $this->exec;
    }

    public function GetAllOrders(): array {                
        $result = [];
        $blocks = [$this->asks, $this->bids, $this->exec, $this->limit];
        foreach ($blocks as $block)
          $result = array_replace($result, $block->RawList());
        return $result;
    }
    
    public function FindBatch(ExternalSignal $sig, string $lists = 'limit,exec,matched'): int {        
        $res = 0;
        $lists = explode(',', $lists);
        $engine = $this->engine;        
        $map = ['limit' => $this->limit, 'exec' => $this->exec, 'matched' => $engine->GetOrdersList('matched')];
        // исполненные заявки используются гридами для подсчета CDP, поэтому их пакеты лучше наследовать
        foreach ($lists as $lname) {
            $list = $map[$lname] ?? [];
            foreach ($list as $oinfo)
            if ($oinfo->buy == $sig->buy && $oinfo->signal_id == $sig->id)         
                $res = max($res, $oinfo->batch_id); // нужна самая свежая
        }         
        return $res;
    }
    public function FindOrder(int $id): ?OrderInfo {
        $orders = $this->GetAllOrders();
        return isset($orders[$id]) ? $orders[$id] : null;
    }

    public function Finalize() {
        $engine = $this->engine;
        // убрать заявки из MM блоков
        $engine->CancelOrders($this->asks);
        $engine->CancelOrders($this->bids);        
        $this->SaveToDB();
    }

    public function MakerFor(int $batch_id) { // check for local batches
        if (isset($this->batches[$batch_id]) || isset($this->limit_batches[$batch_id])) return true;

        $engine = $this->engine;
        $mysqli = $engine->sqli();
        if (!$mysqli) 
             throw new Exception("Not active SQL connection ");

        $table = $engine->TableName('batches');
        $flags = $mysqli->select_value('flags', $table, "WHERE id = $batch_id");       

        if (is_numeric($flags) && $flags & BATCH_MM) {
            $batch = $engine->GetOrdersBatch($batch_id); 
            if (is_null($batch)) return false; // closed or lost
            $this->LogMsg("~C96#SIGNAL_REG_MM:~C00 %s late registration in %s", strval($batch), strval($this));
            $this->AddBatch($batch, false);
            return true;
        }    
        return false;
    }
    public function MapOrders(OrdersBlock $block, $grid, $intv) {
        $orders = [];
        $mapped = [];
        foreach ($block as $info)
           $orders [$info->id]= $info;

        foreach ($grid as $i => $price) {
           // разметка блоков попадающих в сетку  
           $mapped[$i] = 0;
           $info = get_nearest($orders, $price,  $intv); // извлечение ближайшей по цене заявки 
           if (is_object($info))              
             $mapped[$i] = intval($info->id);
        }           
        return $mapped;
    }

    public function __toString() {
        return sprintf("MM@%s; asks = %d, bids = %d, exec = %d", $this->tinfo->pair, 
                                  $this->asks->Count(), $this->bids->Count(), $this->exec->Count());
    }
    protected function OpenOrders(OrdersBlock $block, $count = 1): int {        
        $name = $block->Name();
        $engine = $this->engine;
        $core = $engine->TradeCore();        
        $btc_price = $core->BitcoinPrice();        
        $ti = $this->tinfo; 
        $pair_id = $ti->pair_id;

        $mm = $block->mm;   
        $mm_asks = strpos($name, 'asks') !== false;
        $mm_bids = strpos($name, 'bids') !== false;

        if ($this->max_mm_cost < 100 && $mm) 
            return 0; // no need to open orders in MM mode, due low limit

        if ($block->Count() >= $this->max_orders) {
           $trace = format_backtrace(2, 'basic', 4);
           $this->LogMsg("~C91#WARN:~C00 max orders limit reached for %s, skip. Trace: %s ", $name, $trace); 
           return 0;
        }     

        
        $batch = $this->ActiveBatch(); // получение самого старого сигнала, который на очереди исполнения
        if (!$batch && !$mm) return 0;

        
        
        $full_amount = $amount = $rest_amount = 0; // default by MM settings
        $rest_qty = 0;

        if ($batch && !$mm) {
            $price = $ti->last_price;       
            $left = $batch->TargetLeft();
            $pcnt = $block->Count();
            $block->buy_side = $left > 0;           
            $rest = $this->max_orders - $pcnt;
            $pending = $block->PendingAmount();
            $rest_amount = abs($left) - $pending; // native = contracts may be
            
            $rest_qty = $engine->AmountToQty($pair_id, $price, $btc_price, $rest_amount);  
            $rest_qty  = round($rest_qty, 5); // for 0.00001BTC, cost = $1 at price 100k, TODO: use config roundings
            $rest_cost = round($price * $rest_qty, 2); // default in USD
            $price_corr = 1;
            if ($ti->is_btc_pair)  {
                $rest_cost = round($rest_cost * $btc_price, 2); // 
                $price_corr = $btc_price;
            }    
            
            $cc = 1;
            if ($this->opt_rec) $cc = $this->opt_rec['max_cost_coef'];

            $cost_limit = ($this->max_exec_cost / $price_corr);                             
            $full_limit = $limit = $engine->LimitAmountByCost($pair_id, $rest_amount, $cost_limit);          

            if (0 == $pending)
                $limit = $ti->FormatAmount($limit / 5); // поскольку нет заявок на исполнение, первый уровень свободен, и лучше туда ставить ограниченную заявку  

            if ($rest_amount > $limit && $limit > 0) {                                             
                $full_amount = $full_limit;  // для дальних заявок тоже может быть ограничение                              
                $far_amount = $amount - $limit;  // остаток на выполнение уровнями "дальше"
                $max_count = 1 +  ceil($far_amount / $full_amount); // общее количество заявок на выставление, всегда больше 1 из-за обрезки.
                $amount = $limit;                // сокращение объема до выбраного лимита               
                $count = min($count, $max_count);               
            }    
            else {
                $full_amount = $amount = $rest_amount;  // все одинаково             
                $count  = 1;
            }      
                
            $tag = '~C94#MM_EXEC~C00';
            $is_small = $engine->IsAmountSmall($pair_id, $rest_amount, $this->BasePrice()); 

            if ($is_small) { 
                $tag = '~C93#MM_EXEC_IGNORE~C00';                 
                $batch->hang ++;
                $batch->idle ++;
                $count = 0;             
            }  
            
            $this->LogMsg("~C03~C93$tag~C00: left amount = %s, rest = %d, pending = %s:$pcnt, rest_amount = %s ($rest_qty), rest_cost = %.1f$, cost_coef = %.1f, planned order amount = %10f, planned orders count = %d", 
                        $ti->FormatAmount($left), $rest, $ti->FormatAmount($pending), $ti->FormatAmount($rest_amount), $rest_cost, $cc, $ti->FormatAmount($amount), $count);
           
        }

        if (0 == $count || 0 == $amount) return 0;

        $this->LogMsg("~C92#MM_OPEN_ORDERS~C00(%s): pair = %s, count = %d/%d ", $name, $ti->pair, $count, $this->max_orders);
        $core->SetIndent("  ");
        $grid = $this->CalcGrid($block);        
        $intv = $this->price_intv($mm);        
        $mapped = $this->MapOrders($block, $grid, $intv);
        $opened = 0;

        $buy = $mm ? $mm_bids : $block->buy_side;
        $best = $ti->fair_price ? $ti->fair_price : $ti->last_price;
        $base = $this->BasePrice();

        if ($ti->bid_price > 0 && $ti->bid_price < $ti->ask_price)
               $best = $buy ? $ti->bid_price : $ti->ask_price;        

        foreach ($mapped as $i => $id) 
          if (0 == $id) {
            $price = $grid[$i];            
            $total = $block->Count();               
            
            $offset = $buy ? $best - $price : $price - $best; // хорошие покупке под бидом, хорошие продажи над аском                        
            $dist = abs($offset);
            if ($dist < $intv && $mm) {
                $this->LogMsg("~C91#WARN:~C00 grid[$i] price %s too close to best %s, skip", $price, $best);
                continue; // wrong 
            }  

            if ($price < $base && $mm_asks) {
                $this->LogMsg("~C91#ERROR:~C00 ask grid[$i] price %s cheap relative base %s, attempt to market execution", $price, $base);
                continue; 
            }  

            if ($price > $base && $mm_bids) {
                $this->LogMsg("~C91#ERROR:~C00 bid grid[$i] price %s expensive relative base %s, attempt to market execution", $price, $base);
                continue; 
            }  
            $count --;                                      
            $this->LogMsg("~C97 #NEW_MM_ORDER(%d:%s):~C00 %9s grid[%d] = %8s @ %7s, interval = %f, offset to best_price %s = %f ", 
                                $total + 1, $name, $ti->pair, $i, $amount, $price, $intv, $ti->FormatPrice($best), $offset);
            $core->SetIndent("\t");
            $qty = $engine->AmountToQty($pair_id, $price, $btc_price, $amount); // минимальный размер лота
            $rq = $ti->FormatQty($qty, Y) . ' / '. $ti->FormatQty($rest_qty, Y);
            if ($amount <= 0 || $qty <= 0) {
                $core->LogError("~C91#ERROR_MM(OpenOrders):~C00 grid[%d] amount  %.10f or qty %.10f is small, can't place order for %s", $i, $amount, $qty, $ti->pair);
                break;
            }

            if ($this->PlaceOrder($block, $price, $amount, "#$i $rq" )) {
                $mapped [$i] = 'new';            
                $opened ++;            
            }
            else 
                break;     
            if (0 == $count) break;            
            $rest_amount -= $amount;
            $this->LogMsg("~C94 #NEXT_MM_ORDER:~C00 rest_amount = %s", $ti->FormatAmount($rest_amount));
            $amount = min($full_amount, $rest_amount);
            if ($amount <= 0) break;            
          }

        if ($count > 0)
              $this->LogMsg("~C91#WARN:~C00 not all orders placed, rest = %d, grid size = %d, mapped = %s", $count, count($grid), json_encode($mapped));
        $core->SetIndent("");
        return $opened;
    }

    protected function PlaceOrder(OrdersBlock $block, float $price, float $amount, string $desc = '') {
        $tinfo = $this->tinfo;        
        $engine = $this->engine;
        $core = $engine->TradeCore();
        $btc_price = $core->BitcoinPrice();
        $lot_size = $tinfo->lot_size;                
        $pair_id = $tinfo->pair_id;
        $name = $block->Name();
        //------------------------------------------------------//
        $qty = $this->max_mm_cost / $price; // minimal order size for MM        
        if (0 == $amount && $block->mm) { // for MM 
            if ($tinfo->is_btc_pair)  
                $qty /= $btc_price; // in BTC
            $amount = $engine->QtyToAmount($pair_id, $price, $btc_price, $qty);
        }    
        else
           $qty = $engine->AmountToQty($pair_id, $price, $btc_price, $amount);  

        if (0 == $qty) {
            $this->LogMsg("~C91#ERROR_MM(PlaceOrder):~C00 qty = 0 for MM->%s, amount = %s, lot_size = %3f, skip",  $name, $amount, $lot_size);
            return false;
        }

        $amount = floor($amount / $lot_size) * $lot_size; // need round to minimal value              
        $cost = $qty * $price;   
        if ($tinfo->is_btc_pair)
            $cost *= $btc_price; // in USD  
        $min_cost = $core->ConfigValue('min_order_cost', 10); // minimal cost for order
        if ($engine->IsAmountSmall($tinfo->pair_id, $amount, $price) || abs($cost) < $min_cost)  {
            $this->LogMsg("~C91#WARN_MM(PlaceOrder):~C00 amount = %8f < lot_size = %8f, cost %.2f relative min %.2f, skip", $amount, $lot_size, $cost, $min_cost);
            return false;        
        }              
        
        $raw_amount = $amount;
        $amount   = $tinfo->FormatAmount($amount);        
        
        if (0 == $amount) {
            $this->LogMsg("~C91#WARN_MM(PlaceOrder):~C00 for MM->%s, max_cost = %.2f, qty = %s, raw amount = %.6f formated = 0, skip", $name, $this->max_mm_cost, $tinfo->FormatQty($qty), $raw_amount);
            return false;
        }

        $batch_id = MM_BATCH_ID;

        if ($block->mm && $this->mm_errors > 5) return false;

        $ext_sig = null;

        if (!$block->mm) {
            $batch = $this->ActiveBatch(true);
            if (!$batch) {
               $this->LogMsg("~C91#ERROR_MM:~C00 no active batch, can't place additional order");
               return false;
            } 
            $batch->idle = 0;            
            $batch_id = $batch->id;
            $feed = $core->SignalFeed();            
            if ($batch->parent > 0 && $feed) 
                $ext_sig = $feed[$batch->parent];
        }                   

        $price = $tinfo->FormatPrice($price);

        if (!$block->mm && isset($engine->ne_assets[$pair_id])) 
            $amount = $tinfo->Format($amount * 0.1); // 10% of total

        
        $params = array ('buy' => $block->buy_side, 'account_id' => $engine->account_id, 'batch_id' => $batch_id, 'price' => $price, 'amount' => $amount, 'qty' => $qty, 'rising' => 1);
        $params['in_position'] = $engine->CurrentPos($pair_id);
        $params['comment'] = "$name$desc";
        if (!$block->mm && abs($cost) > 500)
             $params['hidden'] = $tinfo->lot_size; // show minimal amount

        $pair = $tinfo->pair;
        if ($block->mm)
            $params['ttl'] = random_int(900, 1800); // 15-30 m for replacing        

        if ($ext_sig) {
            if ($ext_sig->pair_id != $pair_id) 
               throw new Exception("External signal pair_id mismatch for $pair:$pair_id = {$ext_sig->pair_id}");                  
            $params['signal_id'] = $ext_sig->id;
            $params['init_price'] = $ext_sig->recalc_price;
            $params['comment'] = "MM ext_sig#{$ext_sig->id} coef = {$ext_sig->open_coef}";
            $params['flags'] = OFLAG_DIRECT;
        }
 
        $info = $engine->NewOrder($tinfo, $params); 
        if (!is_object($info)) {          
          $core = $engine->TradeCore();
          $this->mm_errors ++;          
          $core->LogError('~C91#REJECTED:~C00 MM order with params %s, last_error %s', json_encode($params), $engine->last_error);
          $emsg = sprintf("MM %s order post failed, NewOrder(%s) returned [%s], pair = %s, cost = %.5f, ~C04%s\n error = %s", 
                     $name, json_encode($params), var_export($info, true), $pair, $cost, $desc, $engine->last_error);
          $core->send_event('ALERT', $emsg, $engine->last_error_code);          
          return false;
        }
        $info->pair = $tinfo->pair;
        $info->created = $info->updated;        

        if ($ext_sig) 
            $info->flags |= OFLAG_DIRECT;

        if (!$info->IsFixed())
             $info->Register($block);  // move from global list to local
        if ($info->Cost() > REPORTABLE_ORDER_COST) 
            $core->NotifyOrderPosted($info, 'by 🅜🅜'); 
        $this->added = true;
        $this->LogMsg("~C01~C97#POSTED:~C00 order info [%s %s]", strval($info), $info->comment);
    }

    public function Register(OrderInfo $info): bool {
        $list = $this->exec;
        if ($info->batch_id == MM_BATCH_ID) 
            $list = $info->buy ? $this->bids : $this->asks;        
        if ($info->IsLimit() || $info->IsGrid()) 
            $list = $this->limit;    

        if ($info->IsFixed()) return false;

        if (false === stripos($info->comment, 'MM'))
            $info->comment .= ", MM-reg";

        $prev = $info->GetBlock();
        $name = $prev ? $prev->Name() : 'none';
        if (!$list->mm && $list !== $info->GetBlock())        
             $this->LogMsg("~C97#EXEC_REG:~C00 order %s, target %s from %s ", $info, $list->Name(), $name);

        return $info->Register($list);
    }

    public function CleanupDB() { // зачистка всех таблиц, от потенциальных засоров. Выполняется перед финализацией как правило
        $blocks = [$this->asks, $this->bids, $this->exec, $this->limit]; 
        $engine = $this->engine;
        $acc_id = $engine->account_id;
        $mysqli = $engine->sqli();
        foreach ($blocks as $block) {
            $table = $block->TableName();
            if (0 == $block->Count())
                $mysqli->try_query("DELETE FROM `$table` WHERE (account_id = $acc_id); -- MarketMaker->CleanupDB");
        }
    }

    public function LoadFromDB() {
        $pair_id = $this->tinfo->pair_id; 
        $acc_id = $this->engine->account_id;        
        // using common tables for all mm orders
        $strict = "WHERE (pair_id = $pair_id) AND (account_id = $acc_id)";
        $blocks = [$this->asks, $this->bids, $this->exec, $this->limit];
        $orders_log = [];
        foreach ($blocks as $block) {
           $block->LoadFromDB($strict);
           $orders_log []= format_color("%s:%d", $block->Name(), $block->Count());
        }   

        $lists = [$this->exec, $this->limit]; // not MM select
        foreach ($lists as $list)
          $this->LoadBatches($list);                
        $orders_log = implode(', ', $orders_log);
        $this->LogMsg("~C96#PERF(MarketMaker.LoadFromDB):~C00 completed for %s, orders [$orders_log], batches %d", strval($this), count($this->batches)); 
    }
    protected function LoadBatches(OrdersBlock $block) {
        $loaded = 0;
        foreach ($block as $oinfo) {
           $batch_id = $oinfo->batch_id;
           if ( isset($this->batches[$batch_id] )  || isset($this->limit_batches[$batch_id] ) )  continue;           
           $force = $oinfo->IsLimit() ? 3 : 2; // для лимиток пакеты должны жить больше недели
           $batch = $this->engine->GetOrdersBatch($batch_id, $force); 
           if (!$batch) {
             // предполагается заявки оставленные без пакетов не будут обрабатываться, и со временем отменятся
             $this->LogMsg("~C91 #ERROR(LoadBatches):~C00 can't load batch info for order %s", strval($oinfo));        
             continue;
           }  
           $loaded ++;
           if ($oinfo->IsLimit()) {             
             $this->RegLimitBatch($batch);
           }  
           else
             $this->batches[$batch_id] = $batch;           
        }

        $keys = array_keys($this->batch_cache);
        if ($loaded > 0)
           $this->LogMsg("~C95#LOAD_BATCHES(%s):~C00 for %s loaded %d batches  as active, batch cache = %s", 
                        $block->Name(), $this->tinfo->pair, $loaded, json_encode($keys));
    }

    protected function RegLimitBatch(mixed $batch) {
      if (is_null($batch)) return null;      
      assert(is_object($batch), new Exception("Invalid batch object"));  
      $batch->long_lived = true;
      $this->limit_batches[$batch->id] = $batch;
      $bs = $batch->target_pos > 0 ? 'B@' : 'S@';
      $cache_key = $bs.$batch->parent;
      $this->batch_cache[$cache_key] = $batch;
      return $cache_key;
    }  
    protected function KeyLimitBatch(ExternalSignal $sig) {
        $bs = $sig->buy > 0 ? 'B@' : 'S@';
        return  $bs.$sig->id;  
    }
    
    public function SaveToDB() {
        // сохранение только для одной пары 
        $pair_id = $this->tinfo->pair_id; 
        $acc_id = $this->engine->account_id;
        $strict = "WHERE (pair_id = $pair_id) AND (account_id = $acc_id)";
        $blocks = [$this->asks, $this->bids, $this->exec, $this->limit];
        foreach ($blocks as $block)
           $block->SaveToDB($strict);
    }

 
    public function PendingAmount(): float {
       $res  = 0.0;
       $ti = $this->tinfo;
       $engine = $this->engine;        
       $core = $engine->TradeCore();
       $min_cost = $engine->TradeCore()->ConfigValue('min_order_cost', 50); // in USD
       foreach ($this->exec as $oinfo)
          $res += $oinfo->Pending();  

       $btc_price = $core->BitcoinPrice();  

       $price = $ti->last_price;

       foreach ($this->batches as $batch)
         if (!$batch->IsTimedout() && !$batch->IsClosed()) {
           $pa = abs($batch->TargetLeft()); 
           $elps = time() - $batch->last_active;
           if (0 == $pa || $elps > 100 || $batch->idle > 3) continue;
           $qty = $engine->AmountToQty($ti->pair_id, $price, $btc_price, $pa); 
           $cost = $qty * $price;
           if ($ti->is_btc_pair)
               $cost *= $btc_price;
           if ($cost < $min_cost) continue;
           $res += $pa;             
         }    

       return $res;   
    }

    public function OrdersBlock(string $name): ?OrdersBlock {
        $blocks = [$this->asks, $this->bids, $this->exec];
        foreach ($blocks as $block)  
          if ($block->Name() === $name) return $block;
        return null;
    }


    protected function CheckPriceDistance(float $price) {
        $ti = $this->tinfo;
        $core = $this->engine->TradeCore();
        $max_dist = $core->ConfigValue('max_limit_distance', 5); // in %
        // на неликвиде цена может устареть легко.
        $ref = $this->BasePrice();        
        $dist = abs($price - $ref);

        // следующая фильтрация сократит число ложников на неликвидном инструменте, который нужно маркетить
        if ($price > $ti->ask_price) 
            $dist = abs($price - $ti->ask_price);  

        if ($price < $ti->bid_price) 
            $dist = abs($ti->bid_price - $price);         

        return ($dist <= $ref * $max_dist / 100);               
    }
    public function PlaceLimitOrder(float $price, float $amount, ExternalSignal $bot, string $ctx, bool $reverse = false): ?OrderInfo {
        $engine = $this->engine; 
        $core = $engine->TradeCore();
        $ti = $this->tinfo;
        $pair_id = $ti->pair_id;
        if ($bot->pair_id != $pair_id) {
            $core->LogError("~C91#ERROR:~C00 Signal pair_id {$bot->pair_id} mismatch MM $pair_id for limit order");
            throw new Exception('Unexpected signal pair_id for limit order');
        }           
        $minute = date('i');

        assert($bot->id > 0, "Invalid grid bot ".strval($bot));
        
        if (!$this->CheckPriceDistance($price)) {
          if (5 == $minute % 10) $core->LogMsg("~C94 #SKIP_LIMIT_ORDER:~C00 price %s too far from last price %s, skip", $price, $ti->last_price);
          return  null;
        }
        $buy = $bot->buy ^ $reverse; // reverse for TP or other closing orders        
        if ($engine->IsAmountSmall($pair_id, $amount, $price))  {
            $core->LogError("~C91#ERROR:~C00 small amount %s for limit order, price = %f, signal = %s, dbg %s; ~C97$ctx~C00",
                                $amount, $price, strval($bot), $engine->debug['is_amount_small']);
            return null;
        }

        $cost_limit = $core->ConfigValue('max_order_cost', 5000); // in USD

        $amount = $engine->LimitAmountByCost($pair_id, $amount, $cost_limit);

        $qty = $engine->AmountToQty($pair_id, $price, $core->BitcoinPrice(), $amount);
        $cost = abs($qty * $price);

        if ($cost > $this->max_exec_cost) {
            $core->LogError("~C91#ERROR:~C00 cost %.2f too big for limit order, max_exec_cost = %.2f, price = %f, amount = %f, signal = %s, ~C97$ctx~C00",
                                $cost, $this->max_exec_cost, $price, $amount, strval($bot));                                        
        }
        

        $sign = $buy ? 1 : -1;
        $proto = ['pair_id' => $bot->pair_id, 'curr_pos' => 0, 'src_pos' => 0, 'target' => $amount * $sign, 'price' => $price, 'ts_pos' => date('now'), 'btc_price' => $core->BitcoinPrice()];
        $proto ['ts_pos'] = date('now');        
        $proto ['flags'] = BATCH_LIMIT | BATCH_PASSIVE | BATCH_MM;        
        
        $cache_key = $this->KeyLimitBatch($bot);
        $cache = $this->batch_cache;
        $batch_id = false;

        if (isset($cache[$cache_key]))
            $batch_id = $this->batch_cache[$cache_key]->id;
        elseif ($batch_id = $this->FindBatch ($bot))  {
            $core->LogMsg("~C94#CACHE_MISS:~C00 for key %s, but found in orders ", $cache_key);
        }            
        else {
            $keys = array_keys($this->batch_cache);
            $core->LogMsg("~C94#CACHE_MISS:~C00 for key %s no candidates found in %s ", $cache_key, json_encode($keys));
            $batch_id = $engine->NewBatch($proto, $bot->id);        
        }   

        if (false === $batch_id || 0 == $batch_id)  {
            $core->LogError("~C91#ERROR:~C00 can't create batch for limit order, price = %f, amount = %f", $price, $amount); 
            return null;
        }        
        $batch = $engine->GetOrdersBatch($batch_id, 3);        
        $this->RegLimitBatch($batch);
        $this->batch_cache[$cache_key] = $batch;
        $batch->idle = 0;
        $batch->last_active = time();
        $ttl =  $core->ConfigValue('limit_base_ttl', 7200) + rand(0, 55) * 30;
        //$ttl = 300;
        $params = ['price' => $price, 'amount' => $amount, 'buy' => $buy, 'batch_id' => $batch->id, 'in_position' => 0, 'ttl' => $ttl];                      
        $params ['init_price'] = $price;
        $params ['flags'] = OFLAG_LIMIT;
        $params ['signal_id'] = $bot->id;
        $params ['hidden'] = 0;  // тест - не отпугивать другого ММ
        $params ['comment'] = "limit @{$bot->id} $ctx";        
        
        $bs = $buy ? '~C42~C90 BUY ~C00' : '~C41~C97 SELL ~C00';
        $core->LogOrder("~C97#NEW_LIMIT_ORDER({$bot->id}):~C07 $bs @ price %s, amount %s, batch %d (%s), ttl = %.1f hours $ctx",  $price, $amount, $batch_id, $cache_key, $ttl / 3600);
        $info = $engine->NewOrder($ti, $params);
        if ( ($buy && $price > $ti->ask_price) || 
             (!$buy && $price < $ti->bid_price) ) {
           $core->LogError("~C91#WARN_MM:~C00 limit order price %s means market execution, will be corrected to base %f ", $price, $this->BasePrice());     
           $price = $this->BasePrice();
        }        

        if (is_object($info) ) {
           $info->flags |= OFLAG_LIMIT;
           $info->pair = $ti->pair;
           if (0 == $info->signal_id) {   
              $core->LogError("~C91#WARN:~C00 signal_id not set for limit order %s, set to %d", strval($info), $bot->id);       
              $info->signal_id = $bot->id;
           }   
           $info->batch_id = $batch_id;
           if (!$info->IsFixed())           
                $info->Register($this->limit);
           if (strpos($ctx, 'AO') !== false || strpos($ctx, 'GBOT') === false)
              $core->NotifyOrderPosted($info, "<b>🅻🅸🅼🅸🆃 $ctx</b> for signal ".strval($bot));
           else 
              $core->NotifySmallOrderReg($info);
           $bot->AddOrder($info, 'new_limit');
           $info->OnUpdate();
        } 
        else  
           $core->LogError("~C91#FAILED_NEW_LIMIT:~C00 NewOrder returned %s", var_export($info, true));
        return $info;   
    }

    protected function CheckLimitOrder(int $order_id, mixed &$info, float $price, float $amount, bool $buy, bool $allow, $mark = '~'): int { // модифицировать свойства объектов через ссылки можно лишь объявленные!
        $engine = $this->engine;
        $core = $engine->TradeCore();
        $cache = $engine->MixOrderList('matched,lost');
        $cache = array_replace($cache, $this->limit->RawList());
        // костыли проверок и перепроверок
        if ($order_id > 0) {
            if (is_null($info)) {                
               if (isset($cache[$order_id]))  
                  $info = $cache[$order_id];
               if (null == $info)
                   $info = $engine->FindOrder($order_id, $this->tinfo->pair_id, true);                
               if ($info) {
                  $this->LogMsg("~C92#LIMIT_ORDER_ASSIGN($mark):~C00 order_id = %d, info = [%s] ", $order_id, strval($info));                  
               }   
               else {
                  $this->LogMsg("~C91#LIMIT_ORDER_GONE($mark):~C00  %d not found in cache/lists", $order_id);
                  file_put_contents('data/orders_cache.json', json_encode(array_keys($cache)));
                  return 0;
               }   
            }   

            if (!isset($this->limit[$order_id]) && is_object($info) && !$info->IsFixed()) {
               $this->LogMsg("~C92#LIMIT_ORDER_REGISTER($mark):~C00 order_id = %d, info = [%s] ", $order_id, strval($info));  
               $info->Register($this->limit);
            }              
        }

        if (0 == $order_id || is_null($info) || 0 == $info->amount) return 0;

        
        $ti = $this->tinfo;
        $bs = $info->buy ? 'B' : 'S';

        $info->checked_was = $info->checked; // runtime var, for marking orphans, using string ts
        $info->checked_by = getmypid();
        $info->flags |= OFLAG_LIMIT;
        $otext = sprintf("%s/~C32%s", strval($info), $info->comment);


        if ($info->IsFixed()) {                        
            $local = isset($this->limit[$order_id]);                     
            $text = 'outside';
            if ($local) { 
               $engine->DispatchOrder($info);   // фактически заявка может быть исполненна частично, что тогда?         
               $text = 'routed';
            }                 
            $filled = $info->matched > 0;               
            if ($filled) {                                 
               if ($local) {              
                   $this->LogMsg("~C92#LIMIT_ORDER_FIX($mark):~C00 order %s, location routed", $otext);                
                   $sig = $core->SignalFeed()->FindByOrder($info);
                   if ($sig)
                       $sig->SetLastMatched($info);                       
               }    

               if ('lost' == $info->status) 
                   $engine->CancelOrder($info); // контрольный выстрел

               if ($info->flags & OFLAG_GRID) {  // для ботов исполненные заявки сразу в утиль                                                                           
                   $sig = $info->signal;
                   $hst = '';                  
                   if (is_object($sig) && is_array($sig->mhistory)) {                    
                      $s = sprintf('%s%s@%s', $bs, $ti->FormatAmount($info->matched, Y, Y), $ti->FormatPrice($info->price));
                      $sig->mhistory []= $s;
                      $hst = 'history: '.json_encode( $sig->mhistory);
                   }  
                   $sig->comment .= ' CDP:'.$ti->FormatAmount($sig->CurrentDeltaPos(), Y, Y);
                   $this->LogMsg("~C04~C92#GRID_ORDER_FIX($mark):~C00 order %s~C32 %s", $otext, $hst);                                   
                   $info = null;
                   return 0; 
               }

               if (!$allow)  { // чет много таких проверок
                  $info = null;  
                  return 0;
               }

               return  $order_id;               
            }
            $elps = $info->Elapsed('created') / 60000;
            $progress = 100 * $info->matched / $info->amount;
            $this->LogMsg("~C93#LIMIT_ORDER_LOST($mark):~C00 orders %s fixed as incomplete (%.0f%%), elapsed %.1f minutes, location $text", 
                                                 $otext, $progress, $elps);             
            $info = null;
            return 0;
        }       
        
        if (0 == $amount && $info) {
            $this->LogMsg("~C31#WARN(CheckLimitOrder $mark):~C00 order %s, target amount = 0, skip control; stack: %s", strval($info), format_backtrace(0));
            return $order_id;
        }

        $reason = false;
        if (!$allow || $amount <= 0) {
            $elps = $info->Elapsed('created') / 1000;
            if (!$info->IsFixed() && $elps > 10) 
                $reason = format_color("not allowed or due target amount = %s, elapsed = %.1f seconds ", $otext, $amount, $elps);                        
        } elseif ($info->buy ? ($price >= $ti->ask_price) : ($price <= $ti->bid_price))
            $reason = format_color("changed price %s now is market", $price);
        elseif ($info->buy != $buy)
            $reason = format_color("changed side to %s", $buy ? '~C102~C97 BUY ~C00' : '~C101~C97 SELL ~C00');

        if ($reason) {
            $this->LogMsg("~C31#LIMIT_ORDER_DELETE($mark):~C00 order %s will be canceled, due $reason", strval($info));            
            $engine->CancelOrder($info);
            $info = null;
            return 0;
        }       

        $shift = floatval($price - $info->price);        
        $min_shift = $core->ConfigValue('mm_min_shift', 1) * $ti->tick_size;
        $unshifted = abs($shift) < $min_shift;
        if ($unshifted && $amount == $info->amount) { 
          $shift = $shift ? $ti->FormatPrice($shift) : 'no';             
          $this->LogMsg("~C97#LIMIT_ORDER_STABLE($mark):~C00 order ~C04[%s] have actual price vs target %s, shift = %s; elapsed = %.3f hours, rtm-data %s", 
                            $otext, $price, $shift, $info->Elapsed() / 3600000, json_encode($info->runtime));      
          return $order_id;
        }               

        if ($unshifted)
           $this->LogMsg("~C100~C93#LIMIT_RESIZE_ORDER($mark):~C00 orders %s target amount changed to %s", 
                    strval($info),  $ti->FormatAmount($amount, Y, Y));
        else
           $this->LogMsg("~C93#LIMIT_MOVE_ORDER($mark):~C00 orders %s target changed to %s, shift = %s", 
                                 strval($info), $price, $ti->FormatPrice($shift));

        if ($price > 0 && $this->CheckPriceDistance($price)) {            
            
            $info = $engine->MoveOrder($info, $price, $amount);                
            if (is_object($info)) {
                if ($info->IsGrid())
                    $info->comment = "GBOT $mark";
                if (!$info->IsFixed())                     
                   $info->Register($this->limit);                
                $info->flags |= OFLAG_LIMIT;
                $info->OnUpdate();
                return $order_id;
            }       
            $info = null;     
            return 0;
        }    
        else {              
            $this->engine->CancelOrder($info);            
            $info = null;
            return 0;
        }
    }

    protected function ProcessLimit() {
        $engine = $this->engine; 
        $core = $engine->TradeCore();
        $feed = $core->SignalFeed();        
        $pair_id = $this->tinfo->pair_id;
        $ti = $engine->TickerInfo($pair_id);         
        $list = $feed->CollectSignals('limit', $pair_id, false);
        // $core->LogMsg("~C04~C94#PROCESS_LIMIT:~C00 found %d ext signals with limit req", count($list));
        foreach ($list as $sig) {          
            if ($sig->flags & SIG_FLAG_GRID) continue; 
            $delta = $sig->CurrentDeltaPos();            
            $target = $sig->TargetDeltaPos();
            if (abs($delta) > abs($target)) {
                $this->LogMsg("~C91#WARN_SIGNAL_OVERFILLED:~C00 signal %s have delta %s over target %s", 
                             strval($sig), $ti->FormatAmount($delta, Y, Y), $ti->FormatAmount($target, Y, Y));
                if (signval($delta) == signval($target)) 
                    $delta = $target; // ограничить объем тейка
                else
                    continue; // пропускаем только при совпадающих знаках
            }
            $info = null;          
            $prev = strval($sig);         
            if ($sig->take_profit > 0 || $sig->limit_price > 0) 
                $this->LogMsg("~C95#PS_LIMIT:~C00 checking ext signal %s, delta = %7s, limit = %9s (%7d), take = %9s (%7d), flags = 0x%x", 
                                strval($sig), $ti->FormatAmount($delta, Y, Y), 
                                    $ti->FormatPrice($sig->limit_price), $sig->limit_order, 
                                    $ti->FormatPrice($sig->take_profit), 
                                    $sig->take_order, $sig->flags);
            /// Выставление отсутствующих
            $allow_limit = ($sig->limit_price > 0) && ($sig->flags & SIG_FLAG_LP);
            $full_amount = $sig->LocalAmount(Y, N, N); // количество выдается с учетом коэффициента отрытия позиции на текущей цене!

            if (0 == $sig->limit_order && is_null($sig->limit_info) && $allow_limit) {
                $price = $ti->FormatPrice($sig->limit_price);
                
                $raw_amount = $sig->LocalAmount(N, Y, N);  // количество в монетах пары 
                // создать сигнал и специальную заявку, зарегать в список                           
                $info = $this->PlaceLimitOrder($price, $full_amount, $sig, "OPEN ".$ti->FormatQty($raw_amount, Y));               
                if ($info) {
                    $sig->limit_order = $info->id; 
                    $sig->limit_info = $info;
                    continue;
                }    
                $this->LogMsg("~C91#WARN(ProcessLimit):~C00 signal %s limit order placement failed", strval($sig));
            }    
            $allow_take = ($sig->flags & SIG_FLAG_TP) && $sig->active && $sig->open_coef == 1 && $delta != 0 && $sig->take_profit > 0;
            $amount = $ti->LimitMin(abs($delta), true);            

            if ($sig->limit_info && $sig->limit_info->Pending()) {  // yet not full filled                
                $sig->limit_order = $this->CheckLimitOrder($sig->limit_order, $sig->limit_info, $sig->limit_price,  $full_amount,  $sig->buy, $allow_limit, 'LO');
                continue;  // сигнал ещё не открыт, поскольку лимитная заявка не исполнена
            }

            $amount = $ti->FormatAmount($amount);
            if (0 == $amount) 
                $this->LogMsg("~C31#WARN(ProcessLimit):~C00 signal %s have zero amount, with current delta %f ", strval($sig), $delta);
            

            if (0 == $sig->take_order && is_null($sig->tp_order_info) && $amount > 0 && $allow_take) {
                $price = $ti->FormatPrice($sig->take_profit);            
                // создать сигнал и специальную заявку, зарегать в список                           
                $info = $this->PlaceLimitOrder($price, $amount, $sig, 'TP ', true);               
                if ($info) {
                    $sig->take_order = $info->id;                             
                    $sig->tp_order_info = $info;
                } 
            }     
            // проверка изменения цены
            
            $sig->take_order  = $this->CheckLimitOrder($sig->take_order, $sig->tp_order_info, $sig->take_profit, $amount, !$sig->buy, $allow_take, 'TP');
            if ($sig->tp_order_info && $sig->tp_order_info->IsFilled() && $sig->limit_price > 0) {
                $core->LogMsg("~C04~C97#LIMIT_RESTART:~C00 as take profit filled, restart limit signal %s", strval($sig));
                $sig->take_order = $sig->limit_order = 0;
                $sig->tp_order_info = $sig->limit_info = null;
            }
            $sig->last_source = sprintf('mm_process_limit prev = %s ', $prev);
            $sig->SaveToDB(); // сохранение возможных изменений
            // уборка заявок по внешнему отключению.     
         
       } // foreach

    }


    protected function CheckGridBot( GridSignal $sig, array $levels){
      $ti = $this->tinfo;
      $engine = $this->engine;
      $core = $engine->TradeCore();      
      // выбор активного списка, биды или аски. Однако там и там могут быть заявки на покупку и продажу     
      $lst = $sig->grid;       
      ksort($lst, SORT_NUMERIC); // from lower to upper level
      ksort( $levels, SORT_NUMERIC); // 

      $ref_price = $this->BasePrice();  
      $max_level  = +$sig->qty;
      $min_level  = -$max_level;

      $max_orders = $max_level * 2 + 1;

      $max_bid_level = $min_level;
      $min_ask_level = $max_level;

      foreach ($levels as $level => $level_price) {        
        if (!isset($lst[$level])) $lst [$level]= null;
        if ($ref_price < $level_price) 
            $min_ask_level = min($min_ask_level, $level);            
        elseif ($ref_price > $level_price) 
            $max_bid_level = max($max_bid_level, $level);            
      }    

      // оценка лимита активных заявок
      // bids: - 5 - 4  = 9; -5 - 5 = 0
      $max_bids = max(1, $max_bid_level - $min_level); // в случае когда спред выше сетки, максимальное число заявок на покупку, и ноль когда ниже
      $max_asks = max(1, $max_level - $min_ask_level); // в случае когда спред ниже сетки, максимальное число заявок на продажу, и ноль когда выше      

      $last = 0;    

      $step = $sig->step;      
      $sig->Update();

      $amount = $sig->LocalAmount();
      $amount = $ti->LimitMin($amount, true);
      $amount = $ti->FormatAmount($amount);
      if ($engine->IsAmountSmall($ti->pair_id, $amount, $ref_price)) { 
          $qty = $engine->AmountToQty($ti->pair_id, $ref_price, $core->BitcoinPrice(), $amount);
          $this->LogMsg("~C91#WARN:~C00 amount %s (%s) too small for grid bot %s, ignored; calc: ".$engine->debug['is_amount_small'], 
                            $amount, $ti->FormatQty($qty), strval($sig));  
          return;  
      }  

      $low_bound = $ref_price - $step * 0.5;
      $high_bound = $ref_price + $step * 0.5;

      $lm_level = -1; 
      $lm_buy = true;
      $lm_upd = '2020-01-01 00:00:00';
      $lm_price = 0;
      $lmo = $sig->last_matched;
      

      if ($lmo) {
          if (0 == $lmo->matched) {
              $this->LogMsg("~C91#GRID_WARN:~C00 last matched order %s have no matched, reset", strval($lmo));  
              $sig->SetLastMatched(null);              
              $lmo = null;
          }
          else {  
            $lm_level = $lmo->level;
            $lm_price = $lmo->price;
            $lm_buy = $lmo->buy;          
            $lm_upd = $lmo->updated;
          }   
      }   

      $cls = $sig->Cleanup(true);
      $cdp = $sig->CurrentDeltaPos();  // нужна направленность, для блокировки двойного исполнения               
      $orders = $sig->ActualOrders(false); // заявки участвующие в сетке для подсчета или ожидающие исполнения

      $sells = $buys = $bids = $asks = 0;
      // Подсчет всех заявок для контроля лимитов
      foreach ($orders as $oinfo) {
        if ($oinfo->IsFixed())  {
           if ($oinfo->buy) 
              $buys ++;
           else
              $sells ++;    
        } 
        else {
            if ($oinfo->buy) 
            $bids ++;
         else
            $asks ++;                
        }            
      }

      $max_sells = $max_orders - $buys; // сколько может быть проданно всего
      $max_buys = $max_orders - $sells;  // сколько может быть куплено всего

      if ($bids > $max_bids || $asks > $max_asks) 
          $this->LogMsg("~C91#GRID_WARN:~C00 grid bot %s have too much active orders, bids %d/%d, asks %d/%d, max bid level %d, min ask level %d", 
                            strval($sig), $bids, $max_bids, $asks, $max_asks, $max_bid_level, $min_ask_level);  
     
      if ($buys > $max_buys || $sells > $max_sells) 
          $this->LogMsg("~C91#GRID_WARN:~C00 grid bot %s have too much matched orders, buys %d/%d, sells %d/%d", 
                            strval($sig), $buys, $max_buys, $sells, $max_sells);                                            

      $batch_id = 0;
      $all = [];
      $k = $this->KeyLimitBatch($sig);
      if (isset($this->batch_cache[$k]))
         $batch_id = $this->batch_cache[$k]->id;

      if ($batch_id > 0) 
        $all = $this->limit->FindByField('batch_id', $batch_id);  // short list
      else 
        $all = $this->limit->FindByField('signal_id', $sig->id); // full list 
      
      $orders = array_replace($orders, $all);        
      $fmap = [];
      $void_cnt = 0;
      
      foreach ($lst as $i => $oinfo)        
      if ($oinfo) 
        foreach ($lst as $j => $ref) 
         if (abs($j) > abs($i)) {          
            if ($ref && $oinfo->id == $ref->id) {
                $this->LogMsg("~C91#GRID_WARN($j):~C00 order %s duplicated in map at %d, relative nearest level %d", strval($oinfo), $j, $i);   
                $lst[$j] = null;
            }           
        } //  if -> foreach


      // проверка всей сетки, с перевыставлением заявок
      $keys = array_keys($levels);
      foreach ($levels as $level => $level_price) {          
        $id = 0;        
        $level *= 1;               
        $is_top = ($max_level == $level);
        $is_bottom = ($min_level == $level);

        $last = $level_price;
        $oinfo = $lst[$level] ?? null;
        if (is_object($oinfo))
           $id = $lst[$level]->id;

        $allow = $level_price <= $low_bound || $level_price >= $high_bound; // типичное разрешение, если цена достаточно далеко от уровня       
        $fmap[$level] = $allow ? '~~~' : 'near';
        $sell = $level_price > $ref_price;  
        $buy = !$sell;      
        $ba   = $buy ? 'B' : 'A'; // bid/ask
        $mark = "$ba:$level";
        $bs = $buy ? '~C102~C30 BUY ~C00' : '~C101~C97 SELL ~C00'; // information colored msg
        

        $lm_opp = false; // LMO opposite to current level and hear?     
        $lm_delta = 0;
        if ($lm_price > 0) 
            $lm_delta = abs($level_price - $lm_price);   
            
            

        if ($lm_delta >= $step) {
            if ($level == $lm_level + 1 && $sell && $lm_buy) { // разрешить контр-заявку, если продается откупленная снизу
                $lm_opp = true;            
            }    

            if ($lm_level && $level - 1 && $buy && !$lm_buy) { // разрешить контр-заявку , если откупается проданная сверху
                $lm_opp = true;                    
            }            
            $allow |= $lm_opp;     
        }                  

        $active_count = 0;

        if (is_null($oinfo) && $allow)    
        foreach ($orders as $oid => $act) { // цикл проверки на конфликтующие заявки, что могут воспрепятствовать выставлению новой
            if (is_null($act)) continue;
            if (!$act->IsFixed()) $active_count ++;

            if ($act->price == $level_price ) { 
                if ($act->signal_id > 0 && $act->signal_id != $sig->id) continue; // вероятно используется параллельный грид с тем-же уровнем
                if ($act->buy != $buy) continue;
                // ранее использованые заявки не должны мешать. Допускается второе исполнение по той-же цене как исключение, если очистка не убирает центровую
                // фактически, всегда разрешать нужно оппозитную ближнюю к последней исполненной
                $replaceable = signval($cdp) !== $act->TradeSign() || $lm_opp;              
                if ($act->updated < $lm_upd && $replaceable) continue; 

                if (!$act->IsFixed()) {
                   $lst[$level] = $oinfo = $act;                     
                   $this->LogMsg("~C95#GRID_BOT_ASSIGN($mark):~C00 found alive order %s for price %s, with level %2d ", strval($act), $level_price, $act->level);
                   $oinfo->level = $level;
                   $oinfo->signal_id = $sig->id;
                   $orders[$oid] = null; // чтобы больше не сверяться                   
                }   
                else {
                  $hours =  $act->Elapsed() / 3600000;                  
                  $delta = $ti->FormatPrice($lm_delta);
                  $this->LogMsg("~C31#GRID_BOT_REJECT($mark):~C00 level already in use order %s L%d, price delta to LMO %s = %s, elps = %.3f H, double execution $bs at %s blocked ", 
                                                            strval($act), $act->level, $lm_price, $delta, $hours, $level_price);
                  $allow = false; // предотвращение двойного исполнения
                  $fmap[$level] = 'de!';
                }  
                break;
            }              
        }

        // по предыдущей цене выставлять нельзя, никогда. Защита работает до изменения настроек грида, возможно и перезагрузки
        if ($allow && ($level == $lm_level || $level_price == $lm_price)) { 
            $fmap[$level] = 'de!!'; 
            $allow = false;
        }            
        if ($allow && $buy && ($buys >= $max_buys || $bids >= $max_bids)) {
            $fmap[$level] = 'mx_buys!'; 
            $allow = false;
        }

        if ($allow && $sell && ($sells >= $max_sells || $asks >= $max_asks)) {
            $fmap[$level] = 'mx_sells!'; 
            $allow = false;
        }


        $total_count = count($orders);     
        if ($allow && ($total_count > $max_orders || $active_count == $max_orders) && is_null($oinfo)) {  // оптимизация не прошла? Двойное исполнение пролезло?            
            $oinfo = claim_deeper($lst, $level, $buy);  // забрать заявку уровнем дальше от спреда, её цена будет скорректирована
            if (is_null($oinfo)) {  // если выше ничего нет, значит заполненно все и достигнут предел количества
               $fmap[$level] = 'mx!';
               $allow = false;
            }   
            else {    
               $this->LogMsg("~C93#GRID_CLAIM($mark):~C00 for price %s, will used order %s level %d", $level_price, strval($oinfo), $oinfo->level);               
            }   
        }    

        if (!$this->CheckPriceDistance($level_price) && $allow)  {
            $pp = ($level_price - $ref_price) / $ref_price * 100;
            $fmap[$level] = sprintf('pd!=%.1f%%', $pp);
            $allow = false;
        }         

        // блокировка максимального количества набора позиции qty * 2, через проверку граничных уровней        
        if ($is_top && $buy && $allow) {
            $fmap[$level] = 'top!';
            $allow = false;              
        }

        if ($is_bottom && $sell && $allow) {
            $fmap[$level] = 'bottom!';
            $allow = false;  
        }

        if ($allow || $oinfo)
            $fmap[$level] = ($oinfo ? $oinfo->id: '++')."@$level_price";        

         

        if ($oinfo) {
           $id = $oinfo->id;
           $oinfo->mark = $mark;
           $oinfo->level = $level;
           $oinfo->signal = $sig;       
           $oinfo->comment = "GBOT $mark"; // for better mapping on reloading                      
           $this->CheckLimitOrder($id, $lst[$level], $level_price,  $amount,  $buy, true, $mark); // нужно убирать исполненные заявки из сетки, перемещать заклайменные
           $oinfo = $lst[$level]; // необходим null, чтобы произвести замену
        }   

        if (is_null($oinfo) && $allow) {                             
           $this->LogMsg("~C04~C97#GRID_BOT_NEW($mark):~C00 $bs %s price %s  not active, place new order, amount %s", 
                                $ti->pair, $level_price, $ti->FormatAmount($amount, Y)); 
           $oinfo =  $this->PlaceLimitOrder($level_price, $amount, $sig, "GBOT $mark", $sell);           
           if (!$oinfo) continue;  // заявка появляется если все пошло так
           $oinfo->level = $level;  // для контроля при подсчете TDP
           $sig->AddOrder($oinfo, "gbot#$level");
           $lst[$level] = $oinfo;             
           if ($oinfo->buy)
             $bids ++;
           else
             $asks ++;
        }    
        if (!$oinfo) $void_cnt ++;
      }

      // для случая, когда сетку сократили             
      $keys = array_keys($lst);  
      sort($keys);
      $lower = min($keys);
      $upper = max($keys);
      if ($max_orders < count($keys))  {     
        $bad = 0;   
        if ($lower < -$max_level) $bad = $lower; 
            elseif ($upper >  $max_level) $bad = $upper;
                else 
                    $this->LogMsg("~C91 #GRID_WARN_STRANGE:~C00 grid have too much keys %s, relative levels %s %d", json_encode($keys), json_encode(array_keys($levels)), $max_orders);           
                                     
        if (abs($bad) > $max_level && isset($lst[$bad]) && $oinfo = $lst[$bad])  {          
          $this->LogMsg("~C91 #GRID_WARN:~C00 grid have orders keys %s what more than price levels %d (max %d), trying remove excess #%d... ",
                                 json_encode($keys), count_keys($levels), $max_orders, $bad);
          $this->CheckLimitOrder($oinfo->id, $oinfo, $last,  0.0000001,  true, false, 'excess'); // убрать лишние 
        }
        else 
          unset($lst[$bad]);
      }      
      if ($void_cnt >= 3)
          $this->LogMsg("~C93#GRID_GAP:~C00 %s void levels count = %d, filter map %s, last matched [%s], cleanup result %s", strval($sig), $void_cnt, json_encode($fmap), '~C34~C47'.strval($lmo).'~C40', $cls);        
      // $orders = $sig->ActualOrders(false);

      $sig->grid = $lst;
    }

    protected function ProcessGridBots() {
       /* 1. Выбор сигналов с настройками сеточных ботов
          2. Для каждого сигнала считается сетка заявок
          3. По сетке отбираются существующие в mm_limit, либо создаются свежие, относительно спреда
          4. Сетка считается заблокированной, если в ней остались только биды или только аски

       TODO: Текущая реализация не подразумевает добавление закрывающих заявок в противоположные стороны от спреда.   
       */ 
      $ti = $this->tinfo;
      $engine = $this->engine;
      $core = $engine->TradeCore();
      $sig_feed = $core->SignalFeed();      
      $bots = $sig_feed->CollectSignals('grid', $ti->pair_id, false);
      $levels = [];  
      $nbot = 0;
      foreach ($bots as $sig) {        
        $nbot ++;

        if ($sig->stop_loss > 0 && $sig->take_profit > 0 && $sig->qty > 1 && isset($sig->center_price)) {
             
        } else {
          $this->LogMsg("~C91#GRID_WARN:~C00 misconfigured grid bot %s, skipped", strval($sig));    
          continue;      
        }
        

        $qty = $sig->qty;        
        $max_qty = $qty * 2 + 1; // сколько заявок на обе стороны. Нулевая заявка всегда подразумевается в центре, и она оценивается как дополнительная (не всегда вылезает)
        $low = $sig->stop_loss;
        $high = $sig->take_profit;
        $range = $high - $low;
        $half = $range / 2;
        $step = $ti->FormatPrice($half / $qty);    

        $mid_price = ($high + $low) / 2;
        $mid_price = $ti->FormatPrice($mid_price);
        $sig->center_price = $mid_price;
        $sig->spread_price = $ti->mid_spread;
        $sig->step = $step;
        $fqty = $sig->LocalAmount(N, N, Y); // real coins qty
        $amount = $sig->LocalAmount(Y, N, Y); // contracts amount or lots
        $amount = $ti->FormatAmount($amount, Y, Y);
        $cdp = $sig->CurrentDeltaPos();
        $cdp = $ti->FormatAmount($cdp, Y, Y);

        $actual = $sig->ActualOrders(false);
        $matched = [];
        foreach ($actual as $oid => $oinfo)
          if ($oinfo->matched > 0)
              $matched[$oid] = $oinfo;         
        
        $this->indent = '  ';
        $this->bp_source = 'grid_center';

        $lower_levels = $this->CalcGridEx(true, $mid_price, 0, $step, $qty + 1, "GBOT {$sig->id}");    // buy side, but possible sell orders on     
        $upper_levels = $this->CalcGridEx(false, $mid_price, 0, $step, $qty + 1, "GBOT {$sig->id}");   // sell side, but possible buy orders on     
        $levels = [$mid_price];  // zero level is middle always

        for ($level = 1; $level <= $qty; $level++) {        
           $levels[+$level] = $upper_levels[$level]; 
           $levels[-$level] = $lower_levels[$level]; // negative indexation
        }   

        $sig->grid_levels = $levels;
        $minfo = dump_orders_info($matched, $levels);
        $ginfo = dump_orders_info($sig->grid, $levels);

        $state = "[$ginfo]";

        if ($state != $sig->last_state) {
            $this->LogMsg("~C94#PS_GRID_BOT(%d/%d):~C00 %s max_qty %d, step = %5s, amount = %7s (%7s), cdp = %7s,  mid_price = %7s, spread = %s; grid = [%s], matched = [%s]", 
                           $nbot, count($bots), strval($sig), $max_qty, $step, $amount, $fqty, $cdp, $mid_price, $ti->mid_spread, $ginfo, $minfo);                            
            $sig->last_state = $state;                           
         }                 
 
        $this->CheckGridBot($sig,  $levels);               
        $this->indent = '';
      }

      foreach ($this->limit as $oinfo) {
        $tms = isset($oinfo->checked_was) ? $oinfo->checked_was : $oinfo->created;
        $tms = strtotime_ms ($tms);
        $ps = isset($oinfo->checked_by) ? 'from PID '.$oinfo->checked_by : '';
        $elps = (time_ms() - $tms) / 1000;    
        if ($elps > 240) {
           $this->LogMsg("~C91#LIMIT_ORPHAN?:~C00 order %s / %s was not checked for %.1f seconds %s", strval($oinfo), '~C32'.$oinfo->comment, $elps, $ps);
           if (!$oinfo->IsFixed()) $engine->CancelOrder($oinfo);
        }    
      }  
    }
    protected function ProcessOpt(OrdersBatch $batch = null ): bool {
        $block = $this->exec;
        if (is_null($batch))
            $batch =  $this->ActiveBatch(true); // update stats for signal included
        $this->opt_rec = [];        
        if ($batch) {
            $core = $this->engine->TradeCore();
            $now = time_ms();
            $elps = 0;
            //* find oldest order, check its time
            foreach ($block as $oinfo) {
                $utime = strtotime_ms($oinfo->updated);
                $elps = max($elps, $now - $utime);
            }  
            $elps /= 1000; // ms to sec
            $opt_rec = $core->exec_opt->Process($batch, $batch->target_pos, $elps);
            $this->opt_rec = $opt_rec;
            $tleft = $batch->TargetLeft(false);
            $cost = $opt_rec['price'] * $tleft;
            if (abs($cost) > 50)
                $this->LogMsg("~C96#EXEC_OPT_MM~C00(%s): batch %s, max order elps = %3.0f seconds, target left (%5f) cost %7.1f, opt_rec = %s ", 
                       $this->tinfo->pair, strval($batch), $elps, $tleft, $cost, json_encode($opt_rec));
            // TODO: delta need also correction to negative, if batch elapse to large
            return true; 
        }         
        return false;
    }
    public function Process() {
       /*
       - check fully executed orders (imbalance), remove from orders block
       - move nearest to spread orders 
       - recovery grid if no active signal: maximum orders on both sides
       ! place provocation micro-orders if spread to large (if have active batches)
       */ 
      $ti = $this->tinfo;
      $elps = time_ms() - max($ti->updated, $ti->checked);
      if ($elps > 60000 || 0 == $ti->last_price) {
        $this->LogMsg("~C91#WARN_SKIP:~C00 ticker %s data incorrect, was updated %s (+ %.1f seconds), can't process MM tasks", strval($ti), date_ms(SQL_TIMESTAMP3, $ti->checked), $elps / 1000);
        return;  // некорректные данные тикера, на таких сетки не строятся
      }        
       $minute = date('i');
       $core = $this->engine->TradeCore();
       if (!$core->TradingAllowed()) {
          $this->LogMsg("~C91#WARN_SKIP:~C00 trade not allowed, can't process MM tasks");
          return;  // запрет на торговлю
       }       
       $this->mm_cycles ++;
       if (0 == $minute)
          $this->mm_errors = 0;

       try {
            $this->Adoption();  // заполучить активные заявки для соотв. pair_id            
            $this->CheckOrders($this->limit); // проверить заявки, которые уже исполнились, убрать из списка активных
            $this->ProcessGridBots(); // обработка всех сеточных ботов для текущей пары
            $this->ProcessLimit();  // выставление лимитных заявок для внешних сигналов (тейки или открывашки)           
            

            $blocks = [$this->asks, $this->bids, $this->exec];
            if ($this->max_mm_cost < 100 && count($this->asks) + count($this->bids) == 0) 
                $blocks = [$this->exec]; // disabled MM due low limits
            
            foreach ($blocks as $block) {          
                $core->SetIndent('');
                $this->LogMsg("~C04~C94---------------------------- %s = %d/%d~C94~C04 ----------------------------~C00", $block->Name(), $block->Count(), $this->max_orders);
                $core->SetIndent('  ');
                $this->CheckOrders($block);               
                $this->AdjustBlock($block);                   
            }  

            foreach ($this->batches as $batch)
                $batch->Update(); // lock/unlock, recalc stats

           $this->LogMsg("~C09~C94----------------------------~C97 ========== ~C94----------------------------~C00");
       }
       finally  {
         $core->SetIndent('');
       }

       if (59 == $minute && count($this->movs) > 0) {
          $this->LogMsg("~C97#HOUR_REPORT:~C00 order movements:\n\t%s", implode("\n\t", $this->movs));
          $this->SaveToDB();
          $this->movs = [];
       } 
    } 

  }