<?php
  include_once('../lib/common.php');
  include_once('../lib/db_tools.php');

  define ('Y', true);
  define ('N', false);  

  define ( 'OST_NEW',       'new');
  define ( 'OST_ACTIVE',    'active');  
  define ( 'OST_CANCELED',  'canceled');
  define ( 'OST_EXPIRED',   'expired');
  define ( 'OST_FILLED',    'filled');
  define ( 'OST_INVALID',   'invalid');
  define ( 'OST_LOST',      'lost');  
  define ( 'OST_PROTO',     'proto');
  define ( 'OST_REJECTED',  'rejected');
  define ( 'OST_TOUCHED',   'partially_filled');


  define('ARCHIVED_STATUSES', [OST_LOST, OST_EXPIRED, 'expired_in_match', OST_CANCELED, OST_REJECTED, OST_INVALID]);
  define('FILLED_STATUSES', [OST_FILLED, 'matched']);
  define('PENDING_STATUSES', [OST_NEW, OST_ACTIVE, OST_PROTO, 'pending', OST_TOUCHED]);


  define ('OFLAG_RISING',   0x001);  // позиция открывается этой заявкой, по крайней мере на момент выставления
  define ('OFLAG_ACTIVE',   0x002);  // заявка активна
  define ('OFLAG_LIMIT',    0x010);  // такие заявки ММ не должен двигать никогда, их цена фиксируется
  define ('OFLAG_OUTBOUND', 0x020);
  define ('OFLAG_DIRECT',   0x040);
  define ('OFLAG_SHARED',   0x080);
  define ('OFLAG_FIXED',    0x100); // уже не находится в списке открытых, значит ёк
  define ('OFLAG_GRID',     0x200); // для сеточных ботов 
  define ('OFLAG_RESTORED', 0x400); // вытащен из API, при восстановлении БД, вероятно старый
  define ('OFLAG_CONFLICT', 0x800); // id заявки заменен на новый, из-за конфликта с другой
  define ('COMMENT_MAX_LEN', 64);
  

  enum OrderStatusCode: int {
    case PROTO = 0;
    case ACTIVE = 1;
    case FILLED = 2;
    case TOUCHED = 3;
    case LOST = 4;
    case EXPIRED = 5;
    case CANCELED = 6;
    case REJECTED = 7;
    case INVALID = 8;   
    
    case UNKNOW = -1;
  }

  function StatusCode(string $st) {
    if (stripos($st, OST_EXPIRED) !== false)
        return OrderStatusCode::EXPIRED;
        
    switch ($st) {
       case OST_PROTO: return OrderStatusCode::PROTO;
         case OST_NEW: return OrderStatusCode::ACTIVE;
      case OST_ACTIVE: return OrderStatusCode::ACTIVE;
      case OST_FILLED: return OrderStatusCode::FILLED;
     case OST_TOUCHED: return OrderStatusCode::TOUCHED;
        case OST_LOST: return OrderStatusCode::LOST;
     case OST_EXPIRED: return OrderStatusCode::EXPIRED;
    case OST_CANCELED: return OrderStatusCode::CANCELED;
    case OST_REJECTED: return OrderStatusCode::REJECTED;
     case OST_INVALID: return OrderStatusCode::INVALID;      
      default: return OrderStatusCode::UNKNOW;
    }
  }

  function trim_comment(string $s) {
    return substr($s, 0,   COMMENT_MAX_LEN);  
  }


  class OrderInfo {
    protected $raw_set = null;
    protected $owner = null;  // OrderList
  

    public    $exec_attempts = 1;
    public    $registered = false; // database status
    public    $rising_pos = false;
    public    $error_log = []; // failed ops history
    public    $error_map = ['cancel' => 0, 'load' => 0, 'move' => 0, 'post' => 0, 'dispatch' => 0]; // failed ops count map, for preventing may repeats
    public    $pair       = '';  // also symbol, may be renamed in refactoring
    public    $ticker_info = null;
    public    $created = ''; // time first time post
    public    $checked = ''; // last time checked as active

    public    $status_code = 0;
    public    $source_raw = null; // исходные данные от биржевого API, для отладки проблем
    public    $signal = null; // ExtSignal - объект породившего сигнала, при наличии

    public    $btc_price = 0; // цена битка на момент создания заявки, для фьючерсных обсчетов    

    public $was_moved = 0; // сколько раз была перемещена заявка

    public $fork = false;

    public  $level = 0;  // уровень вложенности заявки, для сеточных ботов  

    public  $runtime = []; // map[key] = value, вспомогательная информация на время существования объекта (не сохраняется в БД)

    public  $notified = 0; // код последнего уведомления об этой заявке: 1 - активная, 2 - частично исполнена, 3 - фиксированный статуc 
    

    public function __construct() {
      $this->raw_set = array('id' => -1, 'host_id' => 0, 'predecessor' => 0, 'ts' => 0, 'ts_fix' => 0, 'account_id' => 0, 'pair_id' => 0, 'batch_id' => 0, 'signal_id' => 0,
                             'avg_price' => 0, 'init_price' => 0, 'price' => 0, 'amount' => 0, 'buy' => true, 'matched' => 0,
                             'order_no' => 0, 'status' => 'proto', 'flags' => 0, 'in_position' => 0, 'out_position' => 0, 'comment' => '_new', 'updated' => 0);
      $this->ts = date_ms(SQL_TIMESTAMP);
      $this->created = $this->ts;
      $this->updated = $this->ts;        
      // if ($this->amount > 10e9) throw new Exception("#ERROR: used amount too big for order: ".strval($this));
    }
    public function __destruct() {
       $core = active_bot();
       if ($this->order_no > 0 && $core) {
         if (is_null($this->owner) && !$this->IsFixed())
            $core->LogError("~C91#WARN:~C00 active order instance %s destructed without owner", strval($this));         
       }
    }

    public function __get ( $key ) {
      if (array_key_exists($key, $this->raw_set))
          return $this->raw_set[$key];
      if ('volume' == $key)  
           return $this->amount * $this->price;          
      if (method_exists($this, $key))  
           return $this->$key();    
      if (array_key_exists($key, $this->runtime))
          return $this->runtime[$key];
      return null;
    }
    public function __isset ( $key ) {
      return array_key_exists($key, $this->raw_set) || method_exists($this, $key) || array_key_exists($key, $this->runtime);
    }

    public function __set ($key, $value) {
      if (is_null($value)) {
        $core = active_bot();
        $core->LogError("Attempt set value $key to null in order ".strval($this));
        return;
      }

      if ('status' === $key &&  $this->raw_set[$key] !== $value && $this->owner)      
          $this->set_status($value, false);

      if (method_exists($this, 'set_'.$key)) {
        $this->{'set_'.$key}($value);
        return;
      }           

      $nzp = ['id', 'pair_id', 'price', 'amount'];      
      if (false !== array_search($key, $nzp) && $value <= 0) {
        $core = active_bot();
        $core->LogError("~C91#WARN:~C00 attempt set zero or negative value for %s in order %s from %s", $key, strval($this), format_backtrace());
        return;
      }

      if ('in_position' == $key && 0 == $this->matched) 
          $this->out_position = $value; // синхронизация, пока нет исполнения 

      if ('matched' == $key) {                      
          $this->out_position += ($value - $this->matched) * $this->TradeSign(); // delta-change
      }    
      if ('comment' == $key && strlen($value) > COMMENT_MAX_LEN) 
           throw new Exception("ERROR: new comment too long for order: ".strval($this));

      if (isset($this->raw_set[$key]))
          $this->raw_set[$key] = $value;  // в этот набор сохранять только legacy параметры, они сохранятся в БД
      else
          $this->runtime[$key] = $value;      
    }

    public function __debugInfo() { 
      $owner = $this->owner ? $this->owner->Name() : 'none';
      $res = ['info' => $this->__toString(), 'owner' => $owner, 'raw' => $this->raw_set];
      return $res;
    }


    public function Cost() {
      return $this->price * $this->qty;        
    }
    public function  GetList (): ?OrderList {
      if ($this->owner instanceof OrderList)
          return $this->owner;
      return null;  
    }
    public function GetBlock(): ?OrdersBlock {
      if ($this->owner instanceof OrdersBlock)
          return $this->owner;
      return null;  
    }

    public function __toString() {
      if (0 == strlen($this->pair)) {
        $this->pair = '#'.$this->pair_id;
        if ($this->owner) {
           $core = $this->owner->TradeCore();
           $pmap = $core->pairs_map;
           $pid = $this->pair_id;
           if (isset($pmap[$pid]))
               $this->pair = $pmap[$pid];
           else 
               $core->LogMsg("~C91 #WARN:~C00 no entry in pairs_map for #$pid, may be external order?");
        }    
      }
      $rest = $this->amount - $this->matched;

      $ti = $this->ticker_info;
      $amount = $ti ? $ti->FormatAmount($this->amount, Y, Y) : sprintf('%.5G', $this->amount);
      $matched = $ti ? $ti->FormatAmount($this->matched, Y, Y) : sprintf('%.5G', $this->matched);      
      $rest = $ti ? $ti->FormatAmount($rest, Y, Y) : sprintf('%.5f', $rest);
      $res = sprintf('id#%d %s %s %s @ %5G st=%s, batch=%d, m=%s, r=%s, f:0x%03x, №%s', $this->id, $this->Side(), $this->pair,
                       $amount, $this->price, $this->status, $this->batch_id, trim($matched), trim($rest), $this->flags, $this->order_no);
      if ($this->owner)
          $res .= ', owner:'.$this->owner->Name();                      

      if ($this->signal_id > 0)
         $res .= ", sig={$this->signal_id}";                      
      if ($this->fork) 
         $res .= ', fork';                      

      return $res;                       
    }


    public function Elapsed(string $k = 'created') { // return elapsed ms from select timestamp
      $ts = $this->$k;
      if (is_string($ts))  
         return time_ms()- strtotime_ms($ts);
      if (is_numeric($ts))  
         return time_ms() - $ts;
      return false;  
    }

    public function Export(): array {
      return $this->raw_set;
    }

    public function IsFixed() {
       // partially_filled or partial_filled is not fixed!             
      if (false !== array_search($this->status, ARCHIVED_STATUSES)) return true;
      $ff = $this->flags & OFLAG_FIXED;      
      if ($ff) return true;
      if (false !== array_search($this->status, PENDING_STATUSES)) return false;
      if ($this->IsFilled()) return true;
      // TODO: тут злостные костыли
      $rest = $this->amount - $this->matched;
      $epsilon = $this->amount * 0.0001;
      if ($this->was_moved > 0)   
          $epsilon = $rest;
      $this->status_code = StatusCode($this->status);  
      $code = $this->status_code;
      $pending = $rest - $epsilon;
      if (OrderStatusCode::FILLED == $code && $pending <= 0) return true; // TODO: epsilon from config      

      if (OrderStatusCode::TOUCHED == $code && $pending > 0) 
          return $this->onwer ? $this->owner->is_fixed : false;  // недобитки попадают в matched, из-за отмены например или смещения

      if ($this->owner)
          $this->owner->TradeCore()->LogError("~C91#WARN:~C00 unknown status for order %s %s, epsilon = %f, pending = %f, ff = 0x%x ", 
                  strval($this), $this->comment, $epsilon, $pending, $ff);

      if (OrderStatusCode::FILLED == $code && $rest > 0 && 0 == $this->was_moved)
          $this->status = 'partially_filled';  
      return false;
    }

    public function SetFixedAuto() {
        if ($this->matched > 0) 
            $this->status = $this->Pending() > 0 ? OST_TOUCHED : OST_FILLED;
        else 
            $this->status = OST_CANCELED;
        $this->flags |= OFLAG_FIXED;
    }

    public function  Side() {
      return $this->buy ? 'buy' : 'sell';
    }

    public function  SetList($order_list) {
      if (!$this->registered) {
        echo "#WARN: SetList called for order, with registered === false\n";
        return false;
      }
      $this->owner = $order_list;
      $order_list->Include($this);
      return true;
    }


    public function  Import(array $record): int  {
      if (isset($record['id']) && $record['id'] <= 0)  // proto value
          unset($record['id']);

      if (isset($record['flags'])) {
         $flags = $record['flags'];
      	 $this->rising_pos = ($flags & OFLAG_RISING > 0);
      }
      if (isset($record['rising']))
         $this->rising_pos = $record['rising'];

      if (isset($record['ext_signal']))
         $this->signal_id = $record['ext_signal']; 

      if (isset($record['status']))         
          if (!$this->VerifyStatus($record['status'])) 
             $record['status'] = 'invalid';

      $count = 0;   
      foreach ($this->raw_set as $key => $value) // import only legacy params
       if (isset($record[$key])) {
           if ('status' == $key)
              $this->set_status($record[$key], true);
           else 
              $this->$key = $record[$key]; // с прохождением проверок  
           $count ++;
       } // foreach if   

       $core = $this->owner ? $this->owner->trade_core : active_bot();       
       $this->pair = $core->pairs_map[$this->pair_id] ?? "#$this->pair_id";

       $this->status_code = StatusCode($this->status);
       if ($this->status_code == OrderStatusCode::ACTIVE && $this->flags & OFLAG_FIXED) {                  
          $core->LogMsg("~C91#WARN:~C00 for order %s flags combination are wrong", strval($this));
          $this->flags &= ~OFLAG_FIXED;           
       }  
       if (!isset($this->matched_prev))
            $this->matched_prev = $this->matched; // for tracking changes start       
       
       return $count;
    }


    public function IsCanceled(bool $check_matched = true) {
      if ($check_matched && 0 == $this->matched && $this->IsFixed())
          return true;        
      return $this->status_code == OrderStatusCode::CANCELED;  
    }

    public function IsDirect(): bool {
      return ($this->flags & OFLAG_DIRECT) != 0;
    }
    public function IsFilled(): bool {
      $res = ($this->matched == $this->amount) || ($this->status == 'filled');
      if ($res)
          $this->flags |= OFLAG_FIXED;
      return $res;
    }
    public function IsGrid(): bool {
      return ($this->flags & OFLAG_GRID) != 0;
    }
    public function IsLimit(): bool {
      return ($this->flags & OFLAG_LIMIT) != 0;
    }
    public function IsOutbound(): bool {
      return ($this->flags & OFLAG_OUTBOUND) != 0;
    }

    public function StatusCheck() {
      $args = func_get_args();
      foreach ($args as $st)
        if ($this->status === $st)
           return true;
      return false;
    }

    public function Register(OrderList $order_list):bool {
      $core = $order_list->TradeCore();
      $mysqli = $core->mysqli;
      $table = $order_list->TableName();
      $prev_owner = 'none';
      $new_owner = $order_list->Name();

      if ($this->id <= 0) 
         throw new Exception("#FATAL: try register order with wrong id: ".strval($this));

      if (!$this->IsFixed() && $this->batch_id == MM_BATCH_ID && !$order_list->mm)
         throw new Exception(sprintf("#FATAL: try register active MM order %s in non MM-list: $new_owner", strval($this)));

      if ($this->IsFixed() && false !== strpos($new_owner, 'pending')) {
         log_cmsg("~C97#FATAL:~C00 try register fixed order in $new_owner: ".strval($this));
         return false;
      }  
      
      if (0 == $this->price || 0 == $this->amount)
         throw new Exception("#FATAL: try register order with zero price or zero amount: ".strval($this).' '.$this->comment);

       if ($this->owner) 
          $prev_owner = $this->owner->Name();
       
       $i_active = boolval($this->flags & OFLAG_ACTIVE);
       $i_fixed = $this->IsFixed();    
       $was_fixed = ( false !== strpos($prev_owner, 'archive') ||  false !== strpos($prev_owner, 'matched') ) && $i_fixed && !$i_active;  
       $new_fixed =   false !== strpos( $new_owner, 'archive') ||   false !== strpos($new_owner, 'matched');  
    
       if ($was_fixed && $this->status != 'lost' && !$new_fixed ) {          
          $fst = $i_fixed ? '~C31FIXED~C00' : '~C97PENDING~C00';                    
          $core->LogError("~C91#WARN:~C00 Ignored attempt move %s order [%s] from fixed list %s => %s",  $fst, strval($this), $prev_owner, $new_owner);  
          return false;
       }     

      if ($this->owner) {
         if ($order_list != $this->owner)
             $this->Unregister($this->owner, "List migration");
         else 
            return true; // already registered
      }  
      $info = $this->raw_set;
      $clist  = array_keys($info);
      $columns = implode(',', $clist);

      $info['flags'] |= $this->rising_pos ? OFLAG_RISING : 0;
      $info['comment'] = trim_comment($this->comment); 

      $query = "INSERT IGNORE INTO `$table` ($columns)\n VALUES(\n";
      $query .= $mysqli->pack_values($clist, $info).");";

      if (!$mysqli->try_query($query)) {
        $core->LogError("~C91#FAILED:~C00 cannot register order in database! Error: %s", $mysqli->error);
        return false;
      }
      else {
        if ('none' != $prev_owner) {
            $line = sprintf(tss()." #REGISTERED: in %-15s from %-15s #%d %s %s \n", 
                                    $new_owner, $prev_owner, $this->id, $this->order_no, $this->comment);
            file_add_contents("data/orders-trace-{$this->account_id}.log", $line);   
        }    
        $order_list->total += $mysqli->affected_rows;
        $this->registered = true;
        $this->SetList($order_list);        
        $core->LogOrder('~C93#DBG:~C00 order #%d:%d was registered in table %s, prev-list was %s, affected rows %d, total orders = %d', $this->id, $this->batch_id, $table, $prev_owner, $mysqli->affected_rows, $order_list->total);
      }
      return true;
    }

    public function SaveToDB() {      
        $list = $this->GetList();
        if (!$list) return false;  
        $core = $list->TradeCore();
        $engine = $list->Engine();
        $mysqli = $core->mysqli;
        $table = $list->TableName();
        

        if ($list->is_fixed && $this->flags & OFLAG_FIXED == 0)   {
            $core->LogError("~C91#WARN:~C00 save non fixed formally order %s in fixed list %s", strval($this), $list->Name());
            $this->flags |= OFLAG_FIXED;
        }

        if (0 == $this->account_id) {
            $core->LogOrder("~C91#WARN:~C00 account_id not set for order %s, trying set from engine", $this->id);
            $this->account_id = $core->Engine()->account_id;
        }   

        $cl = array_keys($this->raw_set);
        unset($cl['id']); // not need update;
        $query = "UPDATE `$table` SET\n ";
        $lines = array();
        $void = array();      
        // $str_keys = ['created', 'updated', 'comment', 'order_no', 'status'];
        $this->comment = trim_comment($this->comment); // limit length       
        
        $ref = $mysqli->select_value('order_no', $table, "WHERE (id = {$this->id})");
        
        if ('0' !== strval($this->order_no)) 
            $alt_id = $mysqli->select_value('id', $table, "WHERE (order_no = '{$this->order_no}')");

        if (is_string($ref) && $ref != '0' && $ref != $this->order_no) {
            $ref_ts = $mysqli->select_value('ts', $table, "WHERE (order_no = '$ref')");        
            if ($this->ts > $ref_ts) {
            $core->LogError("~C91#WARN:~C00 for %d in DB already exists older order %s with create time %s, saving as is declined!", strval($this), $ref_ts, $ref);
            $info = $list->ResolveConflict($this, $ref);
            if (!$info) 
                    return false;
            }   
        }             
        
        
        foreach ($cl as $column) {
            $value = $this->$column;         
            if (is_null($value)) 
                $void []= $column;          
            if (is_numeric($value) && is_nan($value)) {
                $core->LogError("~C91#WARN:~C00 NaN value for field %s in order %s", $column, $this->id);
                $value = 0;
            }
            $lines []= "`$column` = ".$mysqli->format_value($value);
        } // foreach      

        if (is_null($ref)) {
            $columns = implode(',', $cl);
            $query = "INSERT INTO `$table` ($columns)\n VALUES(\n";
            $query .= $mysqli->pack_values($cl, $this->raw_set).")\n";
            $query .= "ON DUPLICATE KEY UPDATE id = VALUES(id)"; // replace duplicate id if wrong 
        }
        else {  
            $query .= implode(",\n", $lines);
            $query .= " WHERE id = {$this->id};";
        }  
        // $core->LogMsg("UpdateOrder query: $query");
        if (!$mysqli->try_query($query)) {
            $core->LogError("#FAILED: update order via query $query: %s %d", $mysqli->error, $mysqli->errno);
        }  
        if (count($void) > 0) 
            $core->LogError("#WARN: update order have null fields: ".json_encode($void));
    }

    public function Unregister(?OrderList $order_list = null, $context = false) {
      $id = $this->id;
      if (!$order_list)
          $order_list = $this->GetList();
      if (!$order_list) 
          return false;

      $core = $order_list->TradeCore();
      $mysqli = $core->mysqli;
      
      $in_list = isset($order_list[$id]);

      if ($this->owner === $order_list) {
          $this->registered = false;
          $this->owner = null; // prevent recurion in Exclude
      }     
      elseif ($this->owner) 
          $core->LogMsg("~C91#WARN:~C00 order #%d has owner %s, but trying unregister from %s, in_list = %d ", $id, $this->owner->Name(), $order_list->Name(), $in_list);


      $ctx = $context ? $context : 'child->Unregister';    
      if ($in_list)
          $order_list->Exclude($this, $ctx);


      $table = $order_list->TableName();    
      $acc_id = intval($this->account_id);
      $res = $mysqli->try_query("DELETE FROM `$table` WHERE (id = $id) AND (account_id = $acc_id); -- Unregister: $ctx"); //  
      if ($context)
      	 $core->LogOrder("Unregister order #%d result: %s, in_list = %d, context %s", $this->id, $res, $in_list, $context);
      return $res;
    }


    public function Pending(): float {
      if (!$this->IsFixed())       
          return $this->amount - $this->matched;
      return 0;  
    }

    public function qty(): float {   // if not assigned raw_set['qty']   
       $e = active_bot()->Engine();
       return $e->AmountToQty($this->pair_id, $this->price, $this->btc_price, $this->amount);
    }
    
    protected function set_qty(float $qty ) {
      $e = active_bot()->Engine();
      $this->amount =  $e->QtyToAmount ($this->pair_id, $this->price, $this->btc_price, $qty);      
    }

    protected function set_flags(int $ff) {
      $pend = ['new', 'proto', 'active'];
      if ($ff & OFLAG_FIXED && false !== array_search($this->status, $pend))  {
         active_bot()->LogError("~C91#ERROR:~C00 try set fixed flag for pending order: %s, source %s, from %s", 
                                  strval($this), json_encode($this->source_raw),  format_backtrace());
         return;
      }  
      $this->flags = $ff;  
    }

    public function set_status(string $value, bool $force = false) {
      $result = true;
      try {        
        $value = trim($value);
        $prev = $this->raw_set['status'];
        if ($value == $prev) return true;
        $core = active_bot();
        if (!$this->VerifyStatus($value)) return false;
        $fixed = $this->IsFixed();
        $flags = $this->flags;

        $can_mod = array_search($prev, ['active', 'new', 'proto', 'lost', 'rejected', 'invalid']);

        $code = StatusCode($value);          
        if (OrderStatusCode::ACTIVE   == $code) {
            $flags |= OFLAG_ACTIVE;
            $flags &= ~OFLAG_FIXED;
        }    

        if (OrderStatusCode::FILLED   == $code ||
            OrderStatusCOde::CANCELED == $code ||
            OrderStatusCOde::EXPIRED  == $code) {
               $flags |= OFLAG_FIXED; // гарантированно fixed
               $flags &= ~OFLAG_ACTIVE;
            }   

        if (0 == $this->matched && ( OrderStatusCode::FILLED == $code || OrderStatusCode::TOUCHED == $code)) {
            $core->LogError("~C91#WARN:~C00 try set ~C97'$value'~C00 status for order %s with matched = 0, from:\n %s", 
                            strval($this), format_backtrace());
            return false;
        }

        if ($this->matched > 0 && OrderStatusCOde::CANCELED == $code) {
            $core->LogError("~C91#WARN:~C00 try set ~C97'$value'~C00 status for order %s with matched > 0, from:\n %s", 
                          strval($this), format_backtrace());
            if ($force) {
                $value = $this->Pending() > 0 ? 'partially_filled' : 'filled';
                $code = StatusCode($value);
            }
            else                                         
                return false; 
        }    
      
        if ($fixed && false === $can_mod && 
             !str_in($prev, '_filled') && 
             !str_in($prev, 'lost')) {  // lost is only changeable status                    
          if ($force) {
            $core->LogError("~C91#WARN:~C00 forced change status to %s (%s) for fixed order %s, source %s, from:\n %s", 
                            $value, var_export($code, true), strval($this), json_encode($this->source_raw), format_backtrace());                            
            $result = true;                            
            $this->flags &= ~OFLAG_FIXED; // force change
            $this->Unregister($this->owner, "force change status");
          }                  
          elseif (!str_in($this->status, $value))  
            throw new Exception('Trouble, force-change');  
        }   
        elseif($core && $this->owner) // not need message while initial import
           $core->LogOrder(" order [%s] changed status to %s", strval($this), $value);

        $this->status_code = $code;  
        $this->raw_set['status'] = $value; // need change before flags  
        $this->set_flags($flags);   
      } 
      catch (Exception $e) {
        $this->owner->TradeCore()->LogError("~C91#EXCEPTION:~C00 set status '%s' for %s, error %s from\n~C97".$e->getTraceAsString(), 
                                            $value, strval($this), $e->getMessage());
      }    
      return $result;
    }

    public function TradeSign() {
      return $this->buy ? 1 : -1;
    }

    public function ErrCount(string $op): int {
      return isset($this->error_map[$op]) ? $this->error_map[$op] : 0;
    }

    public function OnError (string $op): int{
      if ('move' == $op) $this->flags &= ~OFLAG_ACTIVE; // deactivate
      $fails = $this->ErrCount($op) + 1;
      $this->error_map[$op] = $fails;
      return $fails;
    }
    

    public function OnUpdate(string $op = 'load'): bool {
        $list = $this->GetList();
        if (is_null($list)) return false;

        $core = $list->TradeCore();
        $engine = $list->Engine();

        if ('cancel' == $op) {
            $this->flags &= ~OFLAG_ACTIVE; // эта операция гарантирует, что заявка больше не активна
            if (!$this->IsFixed()) {
                $mstatus = $this->matched < $this->amount ? 'partially_filled' : 'filled';
                $this->status = $this->matched > 0 ? $mstatus : 'canceled'; 
            }    
        }    
        if (false !== array_search($op, ['submit', 'post', 'move']))
            $this->flags |= OFLAG_ACTIVE;  // успешно выполненные операции выставления или перемещения заявки, дают знать что она активна  

        if (!$this->IsFixed())
            $this->checked = date_ms(SQL_TIMESTAMP3);  // метка времени начнет устаревать, после отмены или исполнения заявки           


        if ($this->amount <= 0) {
          $core->LogError("~C91#ERROR:~C00 amount not set for order %s, can't save", strval($this));        
          return false;
        }
        if ($this->price <= 0) {
          $core->LogError ("~C91#ERROR:~C00 price %f too small for order %s for saving, refusing", $this->price, strval($this)); // TODO: use config for negative prices        
          return false;
      }

        if ($this->amount >= 10e9) {
          $core->LogError ("~C91#WARN:~C00 amount %10f too big for order %s for saving, limiting ", $this->amount, $this->id);         
          $this->amount = 10e9 - 1;
        }

        if (abs($this->in_position) >= 10e9) {
          $core->LogError ("~C91#WARN:~C00 in_position %10f too big for order %s for saving, limiting ", $this->in_position, $this->id);
          $this->in_position = (10e9 - 1) * signval($this->in_position);
      }

      $t = strtotime($this->updated);

      if ($this->batch_id > 0) {
        $batch = $engine->GetOrdersBatch($this->batch_id, false);
        if ($batch)
            $batch->last_active = max($batch->last_active, $t);
      }    

      if ($core->TradingAllowed())
          $this->SaveToDB();    
      return true;

    } // OnUpdate

    public function UpdateStats() {
      $list = $this->GetList();
      if (!$list) return false;

      $core = $list->TradeCore();
      $mysqli = $core->mysqli;
      $table = $list->TableName();

      if (0 == $this->matched)
        return;


      $engine = $list->Engine();
      $exch = $engine->exchange;
      $sig_table = strtolower($exch).'__batches';

      $sid = $this->batch_id;
      $row = $mysqli->select_row('start_pos, target_pos', $sig_table, "WHERE id = $sid");
      if (false === $row) {
         // TODO: signal remake
         $query = "";
         $core->LogMsg("~C91#WARN:~C00 no signal %d registered in $sig_table ", $sid);
         return false;
      }

      if ($sid > 0) {
        $batch = $engine->GetOrdersBatch($sid, false);      
        if (is_null($batch)) return true;
        $batch->last_order = $this->id;
        $batch->need_update = $table;
      }  
      return true;
    } // UpdateStats


    
    public function VerifyStatus($st) {
        if (false !== strpos($st, '.') || is_numeric($st) )
            return false;
        $result = false;
        foreach (PENDING_STATUSES as $pst)
            if (false !== strpos($st, $pst))
                $result = true;
        foreach (ARCHIVED_STATUSES as $pst)
        if (false !== strpos($st, $pst))
                $result = true;
        foreach (FILLED_STATUSES as $pst)
                if (false !== strpos($st, $pst))
                    $result = true;
        
            
        return $result;  
    }
  };

  /* класс OrderList реализует массив заявок, с возможностью фильтрации, сохранения и загрузки в/из БД.
     Индексом подразумевается id из базы данных, а вовсе не порядковый номер!

  */

  class OrderList implements ArrayAccess, Countable, Iterator {
    protected $orders = [];
    protected $trade_engine = null;
    protected $trade_core   = null;    // cached var!
    protected $list_name = 'default';
    protected $init_stage = 0;
    protected $imp_fields    = 'id, predecessor, ts, account_id, pair_id, batch_id, avg_price, init_price, price, amount, buy, matched, order_no, flags, in_position, status, comment, updated';
    public    $load_limit = 20000;

    public    $is_fixed = false;
    public    $total = 0;      // all orders in DB, after lates op
    public    $mm = false;     // used for market maker distanced orders
    public    $verbosity = 1;
    private   $__index = 0;
    protected $loading = false;

    public function __construct(TradingEngine $engine, string $name, bool $fixed = false) {
        $this->trade_engine = $engine;
        $this->trade_core   = $core = $engine->TradeCore();
        $this->list_name    = $name;
        $this->is_fixed     = $fixed;      
        $this->imp_fields = $this->trade_core->ConfigValue('order_fields', $this->imp_fields);
        $proto_name = strtolower($engine->exchange.'__pending_orders');
        $table_name = $this->TableName();
        $mysqli = $this->trade_core->mysqli;     

        if (false === strpos($name, 'pending') && !$mysqli->table_exists($table_name)) {
            $res = $mysqli->try_query("CREATE TABLE `$table_name` LIKE $proto_name;", MYSQLI_STORE_RESULT, true); // дополнительные таблицы пусть автоматом создает
            if ($res)
                $this->trade_core->LogOrder("~C92#SUCCESS:~C00 created table $table_name");
            else 
                throw new Exception("#FATAL: cannot create table $table_name");              
        }    

        
        $this->AddField('predecessor', 'INT(10) DEFAULT 0', 'id');
        $this->AddField('host_id', "INT UNSIGNED NOT NULL DEFAULT '0'", 'id');      
        $this->AddField('init_price', 'DOUBLE NOT NULL DEFAULT 0', 'avg_price'); // for better slippage calculation      
        $this->AddField('out_position', "FLOAT NOT NULL DEFAULT '0' COMMENT 'After order updated'", 'in_position');
        $this->AddField('signal_id', 'INT(10) NOT NULL DEFAULT 0', 'batch_id');
        $code = $mysqli->show_create_table($table_name);


        // ALTER TABLE `bitmex__matched_orders` ADD `signal_id` INT NOT NULL DEFAULT '0' COMMENT 'External signal id' AFTER `batch_id`; 
        
        $this->total = $mysqli->select_value('COUNT(*)', $table_name, "-- count on {$this->list_name} construct"); // average slow
        
        if (strlen($code) > 10) {
            $need = sprintf('`comment` varchar(%d)', COMMENT_MAX_LEN);
            $query =  sprintf("ALTER TABLE `$table_name` CHANGE `comment` `comment` VARCHAR(%d) CHARACTER SET utf8mb3 COLLATE utf8mb3_bin NOT NULL", COMMENT_MAX_LEN);
            if (!str_in($code, $need)) {
                log_cmsg("~C91#WARN:~C00 trying to increase comment length field in table %s", $table_name);
                $mysqli->try_query($query); // extend for long comments
            }
            
            if (strpos($code, 'ts_fix') === false) {          
                $this->AddField('ts_fix', "TIMESTAMP NULL DEFAULT NULL COMMENT 'Time when status is fixed'", 'ts');        
                $mysqli->try_query("ALTER TABLE `$table_name` ADD INDEX(`ts_fix`)");
            }                

            $size = $this->total;
            $limit = 500000;
            $p_max = ceil($size / $limit) * $limit;
            // разбивка таблиц на партиции

            if (false == strpos($code, "PARTITION p$p_max") && $size > $limit)  try {           
            $parts = [];
            $id = $limit;
            while ($id <= $p_max) {                
                $parts []= "PARTITION p$id VALUES LESS THAN ($id) ENGINE = InnoDB";
                $id += $limit;
            }  
            $parts []= "PARTITION p_newest VALUES LESS THAN (MAXVALUE) ENGINE = InnoDB";
            $count = count($parts);           
            $core->LogMsg("~C96#TABLE_SPLIT:~C00 size = %d, parts = %d", $size, $count);
            $query = "ALTER TABLE `$table_name`  PARTITION BY RANGE (id) PARTITIONS $count\n (";
            $query .= implode(",\n  ", $parts).");\n"; 
            $core->LogMsg("~C91#WARN:~C00 trying parititioning table with query:\n %s", $query); 
            $mysqli->try_query($query);
            // 
            } catch (Exception $E) {
            $core->LogError("~C91#WARN:~C00 failed to partition table $table_name: %s", $E->getMessage());  
            }      
            
        }  
        else
            $this->trade_core->LogError("~C91#WARN:~C00 can't retrieve CREATE TABLE for $table_name: %s", $mysqli->error);
        // */  
    }

    protected function AddField(string $field, string $params, string $after) {
        $core = $this->trade_core;
        $engine = $this->trade_engine;
        $mysqli = $engine->sqli();

        $table_name = $this->TableName();     
        $res = $mysqli->try_query("SHOW CREATE TABLE `$table_name`;");
        if (!$res) {
            $core->LogError("~C91#WARN:~C00 can't retrieve CREATE TABLE for $table_name: %s", $mysqli->error);
            return;
        }   

        $line = $res->fetch_array(MYSQLI_NUM)[1];                
        if (strpos($line, $field) === false) {         
            $query = "ALTER TABLE `$table_name` ADD `$field` $params AFTER `$after`;";
            if ($mysqli->try_query($query))  return ; // TODO: remove after tables upgrade                      

            $core->LogMsg("~C97 #SHOW_CREATE_TABLE:~C00 %s, attempt to upgrade failed: %s", $line, $mysqli->error);
            throw new Exception("#FATAL: can't upgrade table $table_name ");             
        }        
   }



    public function __debugInfo() {
      $res = ['name' => $this->list_name, 'orders' => $this->Count(), '__index' => $this->__index, 'load_limit' => $this->load_limit];      
      return $res;
    }

     public function __toString() {
      return  sprintf("%s@%s count:%d", $this->list_name, get_class($this), $this->count());
    }
    
    public function current(): mixed {      
      return $this[$this->key()];
    }
    
    public function key(): mixed {
      return $this->ids()[$this->__index];
    }
    public function next(): void {
      $this->__index ++;
    }
    public function rewind(): void {
      $this->__index = 0;
    }
    public function valid(): bool {
      return isset($this->ids()[$this->__index]);
    }

    public function count(): int {
      return count($this->orders);
    }

    public function CountByField($field, $value) {
      return count($this->FindByField($field, $value));
    }


    public function Finalize(bool $eod) {
      $this->init_stage = -1;
      $this->SaveToDB();
      $this->orders = [];
    }

    public function FindByPair($pair_id): mixed {
      return $this->FindByField('pair_id', $pair_id);
    }

    public function ids(): array {
      return array_keys($this->orders);
    }

    public function FindByField($field, $value, $all = true, $last = false): mixed {
      $result = $all ? [] : null;
      $keys = array_keys($this->orders);
      sort($keys);
      if ($last)
          $keys = array_reverse($keys);

      foreach ($keys as $id)  {
       $info = $this->orders [$id];
       if ($info->$field == $value) {
          if(!$all) return $info;
          $result [$id]= $info;
       }
      } // foreach keys

      return $result;
    }

    public function GetHistory(int $pair_id, int $period, string $k = 'id') { // period in  seconds      
      $map = [];
      $period *= 1000;
      foreach ($this->orders as $info)
       if ($info->pair_id == $pair_id && $info->Elapsed('created') < $period ) {
          $val = '?';
          if (isset($info->$k)) $val = $info->$k;
          elseif ($k == 's_matched')  $val = $info->matched * $info->TradeSign();
          elseif ($k == 's_amount')   $val = $info->amount * $info->TradeSign();
          $map[$info->id] = $val;
       }   

      return $map;  
      // krsort($map);
      // return array_values($map);
    }


    public function offsetSet(mixed $key, mixed $info): void {            
      assert (is_int($key));     
      // Sometime after is_numeric() variable shows as undefed and market as error. 

      if ($key < 0)
        throw new Exception('Attempt to insert object with wrong key');


      if (is_null($info))  {
        unset($this->orders[$key]);
        return;        
      }

      if (!is_object($info))
        throw new Exception('Attempt to insert non object into order list');

      if ($info->GetList() !== $this)
        $info->SetList($this);
      else {
        $info->id = $key;
        $this->Include($info);
      }
    }

    public function offsetExists(mixed $offset): bool {
      return isset($this->orders[$offset]);
    }

    public function offsetUnset(mixed $offset): void {
      if ($this->verbosity > 1) 
          $this->trade_core->LogOrder("~C92#DBG:~C00 Unset order #%d from list %s", $offset, $this->Name());
      unset($this->orders[$offset]);
    }

    public function offsetGet(mixed $offset): mixed {
      if (isset($this->orders[$offset]))
        return $this->orders[$offset];
      else
        return null;
    }

    public function LoadFromDB(string $sql_params = '') {
        $core = $this->TradeCore();
        $engine = $this->trade_engine;
        $mysqli = $core->mysqli;      
        $host_id = $engine->host_id;

        $table = $this->TableName();
        $start_ts = date(SQL_TIMESTAMP, time() - 86400 * 2);
        if ($sql_params == '')
            $sql_params = "WHERE (updated > '$start_ts') AND (account_id = {$engine->account_id})";      

        $core->LogOrder("~C96#PERF(LoadFromDB):~C00 Trying load orders from DB %s %s...", $table, $sql_params);

        $rcnt = 0;
        $batches = [];
        $rows = $mysqli->select_rows('*', $table, "$sql_params ORDER BY `updated`,`id` DESC LIMIT {$this->load_limit}", MYSQLI_ASSOC);
        $skipped = 0;
        $count_prev = $this->Count();
        $this->loading = true;
        $strayed = [];

        if(is_array($rows)) 
        try {
            foreach ($rows as $row) {
                if (isset($row['id'])) {
                    $rcnt ++;
                    $id = $row['id'];
                    if ($id <= 0) continue;
                    if (isset($this->orders[$id])) {
                        $skipped ++;
                        continue;             
                    }  

                    $load_st = $row['status'];         
                    if (!in_array($load_st, [OST_ACTIVE, OST_PROTO, OST_NEW]) && $this->is_fixed) 
                        $row['flags'] |= OFLAG_FIXED; // force fixed flag for non-pending orders
                    

                    $info = $engine->CreateOrder($id, $row['pair_id'], 'loaded from DB');           
                    if ($info->Import($row) < 10) {
                        $skipped ++;
                        continue;
                    }             
        
                    $info->registered = true;
                    $info->ticker_info = $engine->TickerInfo($info->pair_id);
                    if ($info->batch_id > 0)
                        $batches [$info->batch_id] = 1;
                    $this[$id] = $info;           
                    if ($load_st != $info->status)
                        $info->OnUpdate(); // correction applied

                    if ($this->is_fixed && $info->status_code == OrderStatusCode::ACTIVE) {
                        $core->LogError("~C91#ERROR:~C00 loaded active order %s in fixed list %s", strval($info), $this->list_name);
                        $info->Unregister($this, 'fail: load active in fixed list');
                        $strayed []= $info;
                    }

                    if (!$this->is_fixed && $info->IsFixed()) {
                        $core->LogError("~C91#ERROR:~C00 loaded fixed order %s in active list %s", strval($info), $this->list_name);
                        $info->Unregister($this, 'fail: load fixed in active list');
                        $strayed []= $info;
                    }
                }
                else
                    $core->LogOrder("~C91#ERROR:~C00 loaded invalid row from $table: ".print_r($row, true));
            }
        } 
        finally {
            $this->loading = false;
        }  
        else {
            if (false === strpos($table, '_mm_'))
                $core->LogOrder("~C91#WARN:~C00 no orders loaded, probably not exists/empty table %s in DB", $table);
            return 0;
        }
        ksort($rows);
        ksort($batches);
        $ids = array_keys($this->orders);

        // назначение цены битка для заявок. TODO: добавить альтернативный скан по истории тикеров или свечей
        if (count($batches) > 0 && isset($ids[0]))    {
            $start = intval($ids[0]);
            $stable = $engine->TableName('batches');

            $map = $mysqli->select_map('id,btc_price', $stable, "WHERE (id >= $start) AND (btc_price > 0)");
            $last_btc_price = $engine->get_btc_price();
            foreach ($this as $oinfo) {
            $sid = $oinfo->batch_id;
            if (isset($map[$sid]))
                $oinfo->btc_price = $map[$sid]; 
            else 
                $oinfo->btc_price = $last_btc_price;   
            }         
        }

        if ($this->Count() < $rcnt)
            $core->LogOrder("~C91 #WARN:~C00 from %d records loaded %d orders in %s", $rcnt, $this->Count(), $this->Name());
        elseif ($count_prev != $this->Count())
            $core->LogOrder("~C96#PERF(LoadFromDB):~C00 from %s loaded %d / %d orders in list %s", $table, $this->Count(), $rcnt, $this->Name());

        $this->init_stage = 1;
        foreach ($strayed as $info)
            $engine->DispatchOrder($info, 'strayed');

        return $this->Count();
    }


    public function SaveToDB(string $sql_params = '') {
      $core = $this->TradeCore();
      $acc_id = $this->trade_engine->account_id;

      $mysqli = $core->mysqli;
      $table = $this->TableName();      
      // TODO: need using UPDATE TABLE for efficiency
      // if (strlen($sql_params) > 5)
      $ts_first = date(SQL_TIMESTAMP);


      // Выбор по времени
      foreach ($this->orders as $info) 
        if ($info->updated < $ts_first)
             $ts_first = $info->updated;
                

      if ('' == $sql_params && !$this->is_fixed) {
        $sql_params = "WHERE (account_id = $acc_id) AND (updated >= '$ts_first')";        
        $core->LogMsg("~C91#WARN(SaveToDB):~C00 for cleanup table %s before refill with %d records, using strict params %s ", $table, $this->Count(), $sql_params);
      }
      set_time_limit(30);   

      if (0 == count($this->orders)) return 0;
      $inserted = 0;
      $rows = [];
      $mysqli->try_query("LOCK TABLES `$table` WRITE;"); // если параллельно ещё бот работает, надо блокировать таблицу
      try {
        $before = $mysqli->select_value('COUNT(*)', $table, "WHERE (account_id = $acc_id) -- {$this->list_name}.SaveToDB");     

        if (!$this->is_fixed)         
            $mysqli->try_query("DELETE FROM `$table` $sql_params; -- {$this->list_name}->SaveToDB"); // TODO: this cleanup can destroy old orders

          
        // $fl = str_replace(' ', '', $this->fields); // only stricted fields for save?
        $ref = new OrderInfo();
        $cl = array_keys($ref->Export());        
        
        // enum all orders in list
        foreach ($this->orders as $id => $order) {
            // $fl = array_keys($order);           
            if ($this->is_fixed && !$order->IsFixed()) { 
                $core->LogError("~C91#ERROR:~C00 attempt to save active order %s in fixed list %s", strval($order), $this->list_name);
                continue;              
            }    
            if (!$this->is_fixed && $order->IsFixed()) {
                $core->LogError("~C91#ERROR:~C00 attempt to save fixed order %s in active list %s", strval($order), $this->list_name);
                continue;              
            }          
            $order->comment = trim_comment($order->comment); // cut long comments
            $rows []= '('.$mysqli->pack_values($cl, $order).')';        
        }
        $columns = implode(',', $cl);
        $query = "INSERT INTO `$table`({$columns})\n VALUES\n";
        $query .= implode(",\n", $rows);
        
        $volatile = 'status,batch_id,signal_id,matched,avg_price,ts_fix,updated,flags,in_position,out_position';
        $volatile = explode(',', $volatile);       
        $query .= "ON DUPLICATE KEY UPDATE ";
        $set = [];
        foreach ($volatile as $col) 
             $set []= "`$col` = VALUES(`$col`)";
        $query .= implode(",\n", $set).";";            
        
        if ($mysqli->try_query($query)) {
           $inserted = $mysqli->affected_rows;
           $core->LogOrder("~C96#PERF(SaveToDB):~C00 for table %s affected %d / %d rows", $table, $inserted, count($rows));           
        }   
        else {
           $core->LogError("~C91#ERROR(SaveToDB):~C00 for table %s try_query failed, used fields %s", $table, $columns);
           file_put_contents('data/failed_save_orders.txt', print_r($this->orders, true));
        }   

        $after = $mysqli->select_value('COUNT(*)', $table, "WHERE account_id = $acc_id -- {$this->list_name}.SaveToDB");

        $core->LogOrder("~C96#PERF({$this->list_name}.SaveToDB):~C00 in list %d orders, total count changed from %d to %d ", 
                        $this->count(), $before, $after);
        if ($this->is_fixed)
            file_add_contents("data/history/{$this->list_name}_{$acc_id}_stats.txt", tss().": size $after\n");

        if ($this->is_fixed && $before > $after) {
            $lost = $before - $after;
            if ($inserted < count($rows))
                throw new Exception("CRITICAL: $lost orders lost after SaveToDB in fixed list  {$this->list_name}, inserted $inserted / ".count($rows));                        
            else
                $core->LogError("~C91#WARN:~C00 after save fixed list %s lost %d orders [%d => %d], inserted %d / %d", 
                                  $this->list_name, $lost, $before, $after, $inserted, count($rows));  
        }    
      } finally {
        $mysqli->try_query('UNLOCK TABLES;');
      }   
    }

    public function Name(): string {
       return $this->list_name;
    }

    public function PendingAmount(): float {  // сумма всех неисполненных остатков по заявкам
      $result = 0;
      foreach ($this->orders as $info)    
        if (!$info->IsFixed())   
             $result += $info->Pending();
      return $result;  
    }
    public function TableName() {
      $exch = $this->trade_engine->exchange;
      return strtolower($exch).'__'.$this->Name();
    }

    public function TradeCore(): ?TradingCore {
      return $this->trade_core;
    }

    public function Engine(): ?TradingEngine {
      return $this->trade_engine;
    }

    public function RawList (): array {
      return $this->orders;
    }

    public function ResolveConflict(OrderInfo $newest, string $order_no) {      
      $alter = $this->FindByField('order_no', $newest->order_no);
      if (is_object($alter)) return  $alter;
      $id = $newest->id;
      // $oldest = $this->FindByField('order_no', $order_no, false);
      $engine = $this->Engine();
      $new_id = $engine->GenOrderID($newest->ts);
      $newest->id = $new_id;
      
      $cf_list = file_load_json('data/conflict_orders.json');
      if (!is_array($cf_list))
          $cf_list = [];
      if (!is_array($cf_list[$id]))
          $cf_list[$id] = [];
      if (false === in_array($id, $cf_list[$id]))
           $cf_list [$id][]= "$id=>$new_id";
      file_save_json('data/conflict_orders.ids.json', $cf_list);      
      return $newest;
    }

    public function Walk($cb_func) {
      foreach ($this->orders as $info)
         $cb_func($info);
    }

    public function Include (OrderInfo $info, string $ctx = '') {      
      if ($this->is_fixed && !$this->loading) {
          if (!$info->IsFixed()) {
             $this->trade_core->LogError("~C91#ERROR:~C00 attempt to include active order %s in fixed list %s %s from: %s", strval($info), $this->Name(), $ctx, format_backtrace());
             return false;
          }      
      }    

      if (isset($info->id)) {
          if (isset($this->orders[$info->id])) return true;          
          $this->orders[$info->id] = $info;
      }   
      else  
         throw new Exception("Attempt to register non-object in order list {$this->Name()} $ctx: ".var_export($info, true));

      if ($this->init_stage > 0) {          
          if ($info->OnUpdate())  // all data correct for registration in this list
             $info->UpdateStats();
          else {
             $info->Unregister($this, 'OnUpdate failed');   
             return false;
          }   
      }    
      return true;
    }
    public function Exclude(OrderInfo $info, string $ctx = '') {
      if ($this->is_fixed && $info->IsFixed())
          $this->trade_core->LogError("~C91#WARN:~C00 attempt to exclude fixed order %s from list %s %s", strval($info), $this->Name(), $ctx);

      if ($info->GetList() === $this)
         $info->Unregister($this);
      else 
         unset($this[$info->id]);
    }

  };

  class OrdersBlock extends OrderList {

    public $buy_side = false;
    public $interval = 0.1; // in percent   


    public function __construct(TradingEngine $engine, string $name, bool $buy_side) {       
      parent::__construct($engine, $name,false);
      $this->buy_side = $buy_side;      
      $this->mm = true;
    }
      
    public function AverageExecPrice(): float {
      return 0;
    }

  }

?>