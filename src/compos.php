<?php
 /* Класс комплексной позиции, представляет собой функциональную презентацию рыночной позы, 
 с двумя взаимосвязанными значениям: нативная (в биржевых контрактах) и классическая (в базовом активе).
 
 */
    class ComplexPosition {
        public       $pair_id = 0;
        protected       $value = 0.0;

        protected    $is_native = true;

        public       $chg_price = 0.0;   // price at change detection
        public       $avg_price = 0.0;   // average open price
        public       $btc_price = 0.0;   // BTC price at change detection
        public       $time_chg = 0;      // in ms   
        public       array $errors = [];        // trading/targeting errors 
        public       $inc_chg = 0;        // incremental changed in ms 
        public       $time_chk = 0;      // in ms

        public       $realized_pnl = 0;
        public       $unrealized_pnl = 0;
    
        public      $log = [];

        public       $incremental = 0;   // накопительное изменение, про обработке сделок
        protected    $meta = [];

        protected    $engine;
        protected    $tinfo;
    
        public function __construct(TradingEngine $engine, int $pair_id) {
            $this->engine = $engine;
            $this->pair_id = $pair_id;
            $this->tinfo = $engine->TickerInfo($pair_id);
            $this->set_amount(0);
            // no changes as no position loaded
            $this->inc_chg = 0; 
            $this->errors = [];
            $this->time_chg = 0;
        }
    
        public function __get ( $key ) {                  
            if (array_key_exists($key, $this->meta))
                return $this->meta[$key];
            if (method_exists($this, $key))  
                return $this->$key();
            
            return null; 
        }
        public function __isset ( $key ) {       
          return  isset ($this->meta[$key]) or method_exists($this, $key);
        }

        public function amount(): float {
            if ($this->is_native) return $this->value;
            return $this->engine->QtyToAmount( $this->pair_id, $this->chg_price, $this->btc_price, $this->value);
        }
        public function set_amount(float $amount, float $price = 0, float $btc_price = 0, $timestamp = false) {        
            $this->is_native = true; // по возможности позиции должны быть нативные, так реже пересчеты теоретически
            if ($this->value == $amount) {
              $this->time_chk = time_ms();   
              return;        
            } 
            $e = $this->engine; 
            $this->inc_chg = $this->time_chg;  // согласно изменениям последней позиции 
            $this->incremental = $this->value; // накопительное потом нужно дорастить до текущего, для сверки
            $this->value = $amount;
            $t_upd = time_ms();
            if (is_string($timestamp)) $t_upd = strtotime_ms($timestamp);
            if (is_integer($timestamp)) $t_upd = $timestamp;

            $this->time_chk = $this->time_chg = $t_upd;        
            $this->chg_price = $price > 0 ? $price : $this->tinfo->last_price;
            $this->btc_price = $btc_price > 0 ? $btc_price : $e->get_btc_price();        
            $this->log[tss()] = 'ac '.strval($this);
        }

        public function qty(): float  {
            if (!$this->is_native) return $this->value;
            return $this->engine->AmountToQty( $this->pair_id, $this->chg_price, $this->btc_price, $this->value);
        }

        public function set_qty(float $qty, float $price = 0, float $btc_price = 0) {                
            if ($this->qty == $qty) {
              $this->time_chk = time_ms();   
              return;        
            }  
            $e = $this->engine; 
            if ($this->is_native) 
              $this->value = $e->QtyToAmount( $this->pair_id, $this->chg_price, $this->btc_price, $this->value);
            else 
              $this->value = $qty;
            $this->time_chk = $this->time_chg = time_ms();        
            $this->chg_price = $price > 0 ? $price : $this->tinfo->last_price;
            $this->btc_price = $btc_price > 0 ? $btc_price : $e->get_btc_price();
            $this->log[tss()] = 'qc '.strval($this);
        }     

        public function __toString(): string {       
            $this->age = time_ms() - $this->time_chg; 
            return sprintf("age: %.1fm, amount:%s, qty:%s, volume: %.5f", 
                        $this->age / 60000, $this->Format(Y), $this->FormatQty( Y), $this->volume);
        }
    
        public function __set ($key, $upd) {            
            if (method_exists($this, 'set_'.$key)) {
                $this->{'set_'.$key}($upd);
                return;
            } 
            $this->meta[$key] = $upd;            
        }     
    
        public function Format(bool $scale = N) {
          return $this->tinfo->FormatAmount($this->amount(), Y, $scale);
        }

        public function FormatQty(bool $scale = N) {
          return $this->tinfo->FormatQty($this->qty(),  $scale);
        }

        public function SaveToDB(mixed $ts_pos = false) {
            if (0 == $this->time_chg)
                $this->time_chg = $this->time_chk;
                
            if (false == $ts_pos) 
                $ts_pos = date_ms('Y-m-d H:i:s', $this->time_chg);
            if (is_numeric($ts_pos) && $ts_pos > 0) 
                $ts_pos = date_ms('Y-m-d H:i:s', $ts_pos);

            $engine = $this->engine;
            $core = $engine->TradeCore();
            $mysqli = $engine->sqli();       
            $acc_id = $engine->account_id;
            $table_name = $engine->TableName('position_history');
            $pos = $this->amount;
            $target = 0;
            if (isset($core->target_pos[$this->pair_id])) 
                $target = $core->target_pos[$this->pair_id]['amount'] ?? 0;
            if (0 == $this->btc_price)
                $this->btc_price = $engine->get_btc_price();

            $offset = $core->offset_pos[$this->pair_id] ?? 0;   

            $pos_qty = $engine->AmountToQty($this->pair_id, $this->chg_price, $this->btc_price, $pos);    
            $query = "INSERT IGNORE INTO `$table_name` (ts, pair_id, account_id, `value`, `value_qty`, `target`, `offset`)\n";
            $query .= "VALUES ('$ts_pos', {$this->pair_id}, $acc_id, $pos, $pos_qty, $target, $offset)\n";
            if (!$mysqli->try_query($query)) {       }

            $table_name = $engine->TableName('positions');
            $query = "UPDATE `$table_name` SET `current` = $pos, `ts_current` = '$ts_pos', rpnl = {$this->realized_pnl}, upnl = {$this->unrealized_pnl}\n";
            $query .= "WHERE (pair_id = {$this->pair_id}) AND (account_id = $acc_id);";
            if (!$mysqli->try_query($query)) 
                $core->LogError("~C91#Failed:~C00 to update position in DB error %s, via query: %s", $mysqli->error, $query);      
            return $mysqli->affected_rows;
        }

        public function age(): float {
          return time() - $this->time_chg;
        }

        public function volume() {
          return $this->qty * $this->chg_price;
        }
        
    
    
      }
            
    // require_once 'trading_core.php';



    