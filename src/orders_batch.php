<?php
/*  OrdersBatch - описание данных пакета заявок и краткосрочного таргета по позиции. Имеет обращение до завершения всех сделок,
     используется для управления скоростью исполнения.
  */
  define('BATCH_MM', 0x004);   // controlled by MM
  define('BATCH_LIMIT', 0x008);
  define('BATCH_PASSIVE', 0x010);  
  define('BATCH_FILLED',  0x040);
  define('BATCH_EXPIRED', 0x080);
  define('BATCH_CLOSED', 0x100);  

  define ('EXT_BATCH_ID', -2);
  define ('MM_BATCH_ID', -3);
  define ('BATCH_OUTDATE', 30 * 24 * 3600);
  

class OrdersBatch
{

    public $start_t = 0;
    public $pos_ts = ''; // time when position changed external (debug value)
    public $id = 0;
    public $invalid = false;

    public $urgency = 0; // смещение скорости исполнения
    public $position = null; // last current position 
    public $exec_target = 0; // reference/progress amount value yet
    public $active = true; // batch now executing
    public $idle = 0; // passed cycles without made new orders in MM 

    public $last_active = 0; // time with last updated order

    public $hang = 0; // timer for hanging

    public $lock = 0; // заблокирована, т.к. имеет заявки на выполнение

    public $long_lived = false; // сигналы для лимиток не подвержены "зависанию"

    public $max_expand = 5;

    public $orders_map = [];

    public $need_update = false;

    protected $exec_stats = [];
    protected $engine = null;
    protected $table = 'nope';
    protected $values = [];  // all saveable props here!
    protected $changes = 0;
    

    protected $prev_stats = '';

    public function __construct(TradingEngine $engine, string $table, int $id)
    {
        $this->id = $id;
        $this->engine = $engine;
        $this->table = $table;
        $this->start_t = time();
        $this->last_active = time();
        $core = $engine->TradeCore();
        // $fields = ['account_id', 'pair_id', 'ts', 'parent', 'source_pos', 'start_pos', 'target_pos', 'price', 'exec_price', 'btc_price', 'exec_amount', 'exec_qty', 'slippage', 'last_order', 'flags'];                             
        $vals = $core->mysqli->select_row('*', $table, "WHERE id = $id", MYSQLI_ASSOC);
        if ($vals) {
            $this->values = $vals;            
            $engine->batch_map [$id] = $this;                                    
            if ($this->IsClosed()) return; // not need add to any runtime agents            
            $feed = $core->SignalFeed(); // implements ArrayAccess
            $esid = $this->parent;
            $mm = $engine->MarketMaker($this->pair_id);
            if ($mm && $mm->enabled)
                $mm->AddBatch($this, false);
            else
                $mm = 'not active';

            $ts = $vals['ts'];
            $this->start_t = strtotime($ts);
            // $core->LogMsg("~C97#NEW_INSTANCE:~C00 Batch %d using start_t = %d (%s), now = %d, parent: %s, mm: %s", $id, $this->start_t, $ts, time(), $parent, strval($mm));
            $ext_sig = $esid > 0 && isset($feed[$esid]) ? $feed[$esid] : null;
            if ($ext_sig) {
                $ext_sig->AddBatch($this->id, 'construct');  // установление родительских отношений (дублирование)                
            }

        } else
            throw new Exception("OrdersBatch construction: cannot load from DB row with id = $id, error: " . $core->mysqli->error);
    }

    public function __destruct()
    {
        $this->Save();
    }

    public function __get($key)
    {
        if (array_key_exists($key, $this->values))
            return $this->values[$key];
        $this->engine->TradeCore()->LogError("~C91#ERROR:~C00 no such property %s in %s", $key, strval($this));
        return NAN;
    }
    public function __isset($key)
    {
        return isset($this->values[$key]);
    }

    public function __set($key, $value)
    {
        if (is_numeric($value) && abs($value - $this->values[$key]) == 0)
            return;
        elseif ($value === $this->values[$key])
            return;
        $this->values[$key] = $value;
        $this->changes++;
    }
    public function __toString()
    {
        $ti = $this->engine->TickerInfo($this->pair_id);
        $cp = $this->CurrentPos(N);
        if (null == $cp)
            $cp = 0;

        $pinfo = $this->parent > 0 ? ", parent:{$this->parent}" : '';
        if ($ti && $cp)
            return sprintf(
                "ID:%d, pair:%s, start_pos:%s, curr_pos:%s, target_pos:%s, exec_amount:%s, exec_target:%s$pinfo, flags:0x%02x, elps: %3d, idle: %d",
                $this->id,
                $ti->pair,
                $ti->FormatAmount($this->start_pos, Y, Y),
                $cp->Format(Y),
                $ti->FormatQty($this->target_pos, Y),
                $ti->FormatQty($this->exec_amount, true),
                $ti->FormatQty($this->exec_target, true),
                $this->flags,
                $this->Elapsed(),
                $this->idle
            );
        else
            return sprintf(
                "ID:%d, pair_id:%d, start_pos:%5G, curr_pos:%5G, target_pos:%5G, exec_amount:%5G, exec_target:%5G$pinfo, flags:0x%02x",
                $this->id,
                $this->pair_id,
                $this->start_pos,
                strval($cp),
                $this->target_pos,
                $this->exec_amount,
                $this->exec_target,
                $this->flags
            );
    }

    public function Bias(float $position = 0)
    {
        if (0 == $position)
            $position = $this->start_pos;
        return $this->TargetPos() - $position;
    }
    public function DistTarget($position)
    {
        return abs($this->Bias($position));
    }

    public function Close(string $reason = '')
    {
        if ($this->IsClosed())
            return;
        $this->flags |= BATCH_CLOSED;
        $this->Update();
        $this->Save();                
        $this->active = false;
        unset($this->engine->active_sig[$this->pair_id]);   
        if ($this->lock)
            $this->engine->TradeCore()->LogError("~C91#ERROR:~C00 closing locked for %d cycles, batch %s %s from %s", $this->lock, strval($this), $reason, format_backtrace());
        else     
            $this->engine->TradeCore()->LogOrder("~C94#BATCH_CLOSED:~C00 %s, %s real position %s", strval($this), $reason, $this->CurrentPos());
    }

    public function EditTarget(float $position, bool $force = false)
    {
        $engine = $this->engine;
        $core = $engine->TradeCore();

        if (0 != $position && 0 != $this->target_pos && signval($position) != signval($this->target_pos) && !$force) {
            $core->LogError("~C91#ERROR:~C00 can't change target position to different sign, current = %f, new = %f", $this->target_pos, $position);
            return;
        }
        if ($this->target_pos == $position)
            return true; // already set

        if ($this->Elapsed() >= 300)
            $this->max_expand = min($this->max_expand, 0.1); // пожилые сигналы расширять не допустимо

        $pos = $this->CurrentPos();
        $tresh = $this->max_expand * max(abs($this->target_pos), abs($pos), abs($position)) / 100;
        if ( abs($position) > abs($this->target_pos) + $tresh &&
            signval($pos) == signval($this->target_pos) && !$force ) {
            $bias = $pos - $this->target_pos;
            $bias_tgt = $position - $pos;
            $core->LogError(
                "~C91#ERROR:~C00 can't expand target position current = %f, new = %f, bias curr = %.6f, bias tgt = %.6f, tresh = %.6f",
                $this->target_pos,
                $position,
                $bias,
                $bias_tgt,
                $tresh
            );
            return;
        }

        $ti = $engine->TickerInfo($this->pair_id);
        if ($ti)
            $position = $ti->FormatAmount($position);

        $query = "UPDATE `{$this->table}` SET target_pos = $position WHERE id = {$this->id};";
        if ($core->mysqli->try_query($query)) {

            if ($ti)
                $core->LogMsg(
                    "~C97#EDIT_TARGET:~C00 batch %s to %s ",
                    strval($this),
                    $ti->FormatAmount($position, Y, Y)
                );
            $this->values['target_pos'] = $position;
            return true;
        } else
            return false;

    }

    /**
     * return seconds from batch creating time
     * @return int
     */
    public function Elapsed(): int 
    { 
        return time() - $this->start_t;
    }

    public function IsClosed(): bool {
        return $this->flags & BATCH_CLOSED;
    }

    public function IsHang(int $threshold = 3)
    {
        return ($this->hang > $threshold) && !$this->long_lived;
    }

    public function IsTimedout(int $threshold = 1800)
    {
        return ($this->Elapsed() > $threshold) && !$this->long_lived;
    }

    

    public function TargetLeft(bool $native_qty = true, bool $from_current = true): float
    { // сколько осталось до цели      
        $ref = $from_current ? $this->CurrentPos() : $this->start_pos;
        $res = $this->TargetPos() - $ref;
        if (!$native_qty)
            $res = $this->engine->AmountToQty($this->pair_id, $this->price, $this->engine->get_btc_price(), $res);
        return $res;
    }

    public function CurrentPos(bool $ret_amount = true)
    { // native value position for internal use
        if (!$this->position)
            $this->position = $this->engine->CurrentPos($this->pair_id, false);
        if ($ret_amount)
            return is_object($this->position) ? $this->position->amount : 0.000001;
        return $this->position;
    }
    public function TargetPos()
    {
        return floatval($this->values['target_pos']);
    }


    public function PendingAmount(int $skip_flags = OFLAG_LIMIT, string $sources = 'pending,market_maker'): float
    {
        $result = 0;        
        $orders = $this->PendingOrders($skip_flags, $sources);
        foreach ($orders as $oinfo)             
                $result += abs($oinfo->Pending());        
        return $result;
    }
    public function PendingOrders(int $skip_flags = OFLAG_LIMIT, string $sources = 'pending,market_maker'): array {
        $engine = $this->engine;
        $orders = $engine->FindOrders('batch_id', $this->id, $sources);
        $result = [];
        foreach ($orders as $oinfo) {
            $f = $oinfo->flags & $skip_flags;
            if (0 == $f)
                $result[] = $oinfo;
        }
        return $result;  
    }

    public function Progress($curr_pos = false)
    { // возвращает проценты достижения целевой позиции
        if (false === $this->start_pos)
            return -1;
        $curr_pos = $this->CurrentPos(); // native value position       
        $target_m = $this->TargetPos() - $this->start_pos;
        if (0 == $target_m)
            return -2;
        $progress_m = $curr_pos - $this->start_pos;
        if (signval($target_m) != signval($progress_m))
            return 0;  // different direction
        return 100 * abs($progress_m) / abs($target_m);
    }

    public function UpdateExecStats($o_table = false)
    {
        $engine = $this->engine;
        $core = $engine->TradeCore();
        $mysqli = $core->mysqli;

        if (!is_string($o_table)) {
            $this->exec_amount = 0; // reset before saldo
            $this->exec_target = 0;
            $this->exec_stats = [];
            $this->UpdateExecStats('archive_orders');
            $this->UpdateExecStats('pending_orders');
            $this->UpdateExecStats('matched_orders');
            return;
        }

        $o_table = $engine->TableName($o_table);

        $buy = $this->start_pos < $this->target_pos;
        $batch_id = $this->id;
        $params = 'ORDER BY id DESC LIMIT 1000';
        $strict = "WHERE (account_id = {$this->account_id}) AND (batch_id = $batch_id) AND (avg_price = 0) AND (matched > 0) $params";
        $bad = $core->mysqli->select_value('COUNT(id)', $o_table, $strict);
        if ($bad > 0)
            $core->mysqli->try_query("UPDATE `$o_table` SET avg_price = price $strict");


        $last_id = $mysqli->select_value('MAX(id)', $o_table, "WHERE (account_id = {$this->account_id}) AND (batch_id = $batch_id) $params");
        if ($last_id)
            $this->last_order = max($this->last_order, $last_id);

        // сальдо значимых переменных может быть положительным для таблиц с исполненных или активных заявок (MM, pending).
        // ( 5 * 10 + 5.2 * 11 - 5.2 * 1 ) / ( 10 + 11 - 1 ) = 102 / 20 = 5.1
        $params = "WHERE (account_id = {$this->account_id}) AND (batch_id = $batch_id) AND (matched > 0) $params"; //
        $fields = "SUM(o.avg_price * o.matched) AS exec_cost, SUM(o.matched) as exec_amount, SUM(o.amount) as exec_target, COUNT(id) as order_count";

        $row = $core->mysqli->select_row($fields, "`$o_table` AS `o`", $params);
        if (!is_array($row) || count($row) < 3) {
            if ($batch_id > 10000)
                $core->NotifyOnce($batch_id, "~C91#WARN:~C00 No orders for batch %d in table %s, or exec_amount = 0 ", $batch_id, $o_table);
            $this->invalid = true;
            return;
        }


        if (0 == $row[3])
            return; // no orders, not need recalc

        $this->exec_stats[$o_table] = $row;  // save      
        $exec_price = 0;
        $exec_amount = 0; // prevent divizion by zero
        $exec_target = 0;
        $tables = [];
        $tinfo = $engine->TickerInfo($this->pair_id);
        if (!$tinfo) {
            $core->LogMsg("~C91#UPDATE_STATS_WARN:~C00  ticker info not retrieved for %d, avaliable: %s", 
                                $this->pair_id, json_encode($engine->pairs_map_rev));
            return 0;
        }

        $ord_count = 0; // summary for all tables
        foreach ($this->exec_stats as $tbname => $rec) {
            $tables[] = $tbname;
            $cost = floatval($rec[0]); // exec_cost
            $amount = floatval($rec[1]); // exec_amount
            $target = floatval($rec[2]);
            if ($amount != 0) {
                $exec_amount += $amount;
                $exec_price += $cost;
                $exec_target += $target;
                $ord_count += $rec[3];
            }
        }

        $slippage = 0;
        $exec_price = abs($exec_price);

        if ($exec_amount != 0)
            $exec_price /= $exec_amount; // averaging between all
        else {
            if ($ord_count > 0)
                $core->NotifyOnce($batch_id, "~C91#WARN:~C00 %d orders for batch %d in tables [%s], have summary exec_amount = %f ", $ord_count, $batch_id, implode(',', $tables), $exec_amount);
            $this->invalid = true;
            return; // invalid inputs at now
        }

        if ($exec_price > 0 && $this->price > 0)
            $slippage = $exec_price - $this->price; // summary slippage


        $exec_amount = $tinfo->FormatAmount($exec_amount, false);
        $exec_target = $tinfo->FormatAmount($exec_target, false);
        $exec_price = $tinfo->RoundPrice($exec_price);
        $slippage = $tinfo->RoundPrice($slippage);
        $slippage *= $buy ? 1 : -1;

        $this->changes = 0;
        $this->exec_amount = $exec_amount;
        $this->exec_target = $exec_target;
        // TODO: use valid prices
        $price = $exec_price > 0 ? $exec_price : $this->price;
        $this->exec_qty = $engine->AmountToQty($this->pair_id, $price, $engine->get_btc_price(), $exec_amount);
        if (0 === $this->exec_qty && $exec_amount > 0) {
            $core->LogMsg("~C91#WARN_UPDATE_STATS:~C00 exec_qty was not resolved from %10f ", $exec_amount);
            $this->exec_qty = $exec_amount;
        }


        $this->exec_price = $exec_price;

        if ($this->flags & BATCH_LIMIT)
            $slippage = 0;  // за сигналом могут быть лимитки с разными ценами, проскальзывание лишено информативности 
        $this->slippage = $slippage;

        $ff = BATCH_LIMIT | BATCH_PASSIVE | BATCH_EXPIRED;
        if ($this->flags & $ff || 0 == $this->exec_amount)
            goto skip_log;  // WARN: jump over!

        $tt = str_pad("~C97$o_table~C95", 25);

        if ($this->exec_qty <= 0) {
            if ($batch_id > 10000)                
                $core->LogMsg(
                    "~C91#WARN_UPDATE_STATS($o_table)~C00: batch %s, rejected by exec_qty = %.f, last_error = '%s' ",
                    strval($this),                    
                    $this->exec_qty,
                    $engine->last_error
                );
            return;
        } elseif ($exec_price > 0) {            
            $text = format_color(
                "~C95#UPDATE_STATS(%s:%s):~C00 batch %7d, start_price = %9.4f, exec_price = %9f, slippage = %9f @ %s => %s, orders = %d",
                $tt,
                $this->changes,
                $this->id,
                $this->price,
                $exec_price,
                $slippage,
                $exec_amount,
                $exec_target,
                $this->exec_stats[$o_table][2]
            );

            if ($this->changes > 2 || $this->changes > 0 && $slippage > 100 && $this->prev_stats != $text)              
               $core->LogMsg($text);               
              
            $this->prev_stats = $text; 
        } elseif ($exec_amount > 0)
            $core->LogMsg("~C91#WARN_UPDATE_STATS(%s):~C00 batch %s exec_amount = %9.4f, exec_price = %9f, avg_price in DB not calc", $tt, strval($this), $exec_amount, $exec_price);

skip_log:            
        if (count($this->exec_stats) >= 3)
            $this->Save();

    }

    public function Update() {
        $orders = $this->PendingOrders();
        $pending = $this->PendingAmount();
        if ($pending > 0)
            $this->lock = count($orders) + 3;
        elseif ($this->lock > 0) {
            $this->lock --; // countdown like TTL
            if ($this->IsTimedout())
                $this->lock = 0;
        }    
        if (is_string($this->need_update))
            $this->UpdateExecStats($this->need_update);
        elseif (0 == $this->exec_amount)
            $this->UpdateExecStats('matched_orders');
    }

    public function Save()
    { // store to database
        $engine = $this->engine;
        $core = $engine->TradeCore();
        if (!$core->mysqli)
            return;

        if ($this->start_pos == $this->target_pos) {
            // return;
        }
        if ($this->exec_amount > 0 && 0 == $this->exec_qty) {
            $this->UpdateExecStats();
            $core->LogMsg("~C91 #WARN:~C00 exec_qty not resolved for batch %d, exec_amount = %f, after enforce = %f ", $this->id, $this->exec_amount, $this->exec_qty);
        }


        $acc_id = $engine->account_id;

        $query = "UPDATE `{$this->table}`\n SET exec_price = {$this->exec_price}, exec_amount = {$this->exec_amount}, exec_qty = {$this->exec_qty},";
        $query .= " parent = {$this->parent}, slippage = {$this->slippage}, last_order = {$this->last_order}, flags = {$this->flags}\n WHERE (id = {$this->id}) AND (account_id = $acc_id);";
        $mysqli = $core->mysqli;
        if ($mysqli->try_query($query)) { // sync exec stats
          if ($mysqli->affected_rows && !$this->IsClosed())
               $core->LogMsg("~C94#DBG:~C00 batch %s was saved/updated into table %s ", strval($this), $this->table);
        }   
        else
            $core->LogError("~C91#FAILED:~C00 update batches via query $query");
    }
}