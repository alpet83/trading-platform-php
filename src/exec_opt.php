<?php
  /*
  Уровень бота: политика исполнения как класс с возможностью наследования/переопределения.
  + Входящие параметры алгоритма: активный сигнал и общая целевая позиция (сигнал может быть старым!).
  + Результат выполнения алгоритма: оптимизированная цена для текущей заявки и дедлайн выполнения.


  Промежуточный рассчет - получение показателя спешки/торможения, исходя из которого цена будет отклоняться от середины действующего спреда.
  Эта функция может переопределяться в более эффективных версиях класса.
  // */
  include_once('../lib/db_tools.php');

  class  ExecutionOptimizer {
    public     $price_indent_pp = 50;
    protected  $exec_time = 0;
    protected  $exec_time_limit = 900;
    protected  $trade_core;
    protected  $time_shift;


    function __construct(TradingCore $core) {
        $this->trade_core = $core;
    }

    protected function CalcExecSpeed(OrdersBatch $batch, $target_pos, array &$exec_params) { // must return from 1 to 10, lazy to hurry
       $exec_time = $batch->Elapsed() + $this->time_shift;
       $this->exec_time = $exec_time;
       $exec_params['exec_time'] = round($exec_time);

       $exec_limit = $exec_params['exec_time_limit'] / 60;
       $result = 1 + $exec_time / $exec_limit; // !!! YES there seconds / minutes

       $curr_pos = $batch->CurrentPos();
       $mp = max(abs($target_pos), abs($batch->target_pos), abs($curr_pos));
       $diff = abs($batch->target_pos - $curr_pos);
       
       if ($diff < $mp * 0.02) // слишком маленькое изменение, сигнал стоит протолкнуть с безразличеим к проскальзыванию
         $result += 5;  

       if (abs($target_pos) > abs($batch->target_pos)) // next in queue
          $result += 1;

       if (abs($batch->start_pos) < abs($batch->TargetPos()))  // if position closing, need hurry
          $result += 1;

       return min (10, $result); // maximum hurry after 12 minute
    }

    public function Process(OrdersBatch $batch, $target_pos, $time_shift = 0) {
        $this->time_shift = $time_shift;
        $core = $this->trade_core;
        $engine = $core->Engine();
        $tinfo = $engine->TickerInfo($batch->pair_id);
        $buy = ( $batch->target_pos > $batch->start_pos );
        $ttl = $core->order_timeout;

        $exec_params = ['price' => 0, 'order_ttl' => $ttl, 'exec_time' => 0, 'max_cost_coef' => 1, 'exec_time_limit' => $this->exec_time_limit];

        $speed = $this->CalcExecSpeed($batch, $target_pos, $exec_params);
        $speed += $batch->urgency;        
        $speed = round($speed, 1);
        $exec_params['speed'] = $speed;        
        
        $min_price = $tinfo->bid_price; 
        $max_price = $tinfo->ask_price;
        $price = max($min_price, $tinfo->last_price);
        $price = min($max_price, $price);        


        if ($tinfo->fair_price > 0) {
            $min_price = max($min_price, $tinfo->fair_price * 0.995);
            $max_price = min($max_price, $tinfo->fair_price * 1.005);
        }
        if (isset($exec_params['max_price']))
            $max_price = $exec_params['max_price'];
        if (isset($exec_params['min_price']))
            $max_price = $exec_params['min_price'];

        $dist = $max_price * 0.001; // 0.1%

        $spread_range = ($tinfo->ask_price - $tinfo->bid_price) / $dist;
        $spread_range = round($spread_range, 2);

        // особенно сложно предлагать цену заявки, когда спред расширен (нет ММ в стакане). Проскальзывание может быть разгромным. Возможно эффективно будет заполнять спред мелкими заявками, чтобы привлечь ММ по мере выставления сделок.
        $deep_sell = $tinfo->ask_price - ($speed / 1000) * $price;  // предельная низкая цена продажи, на дистанции от лучшей цены
        $exp_buy   = $tinfo->bid_price + ($speed / 1000) * $price;    // предельная высокая цена покупки, на дистанции от лучшей цены

        if ($speed >= 10) {                    
            $expensive = min($exp_buy, $tinfo->ask_price);
            $cheap = max($deep_sell, $tinfo->bid_price);
            $price = ($buy ?  $expensive : $cheap); // near market execution
            $exec_params['ps'] = 'market';
            $exec_params['order_ttl'] = 60;
        }          
        elseif ($speed >= 5) {
            $shift = round($speed - 4, 1);
            $price = $buy ? $tinfo->bid_price : $tinfo->ask_price; // in spread
            $price += $buy ? $dist * $shift : -$dist * $shift;   // с каждой минутой дистанция дальше от лучшей цены
            $exec_params['ps'] = "inside -$shift";          
            $exec_params['shift'] = $dist * $shift;
            $exec_params['order_ttl'] = min($ttl, 300);
        } 
        elseif ($speed >= 3 && $spread_range < 10) {
            $price = $tinfo->mid_spread;
            $exec_params['ps'] = 'mid_spread';
        }
        else {                    
            $dist = floor( $dist / $tinfo->tick_size) * $tinfo->tick_size; // TODO:
            $price = ($buy ? $min_price - $dist : $max_price + $dist); // try better whan best price
            $exec_params['max_cost_coef'] = 0.3;
            $exec_params['ps'] = 'far';
        }        

        if ($speed <= 5) {
            $exec_params['min'] = $tinfo->min_price;
            $exec_params['max'] = $tinfo->max_price;            
            $price = min($price, $max_price);
            $price = max($price, $min_price);
        }    

        $exec_params['raw_p'] = $price;
        $exec_params['price'] = $tinfo->FormatPrice($price, false);
        return $exec_params;
    }


  }

  class SmartExecOpt extends ExecutionOptimizer {
    public  $source_exchange = 'bitfinex';
    private $market_track = array();
    private TickerInfo $tinfo;


    protected function RSI_speed(OrdersBatch $batch, $target_pos, array &$exec_params) {
      $buy = ( $batch->target_pos > $batch->start_pos );
      $extreme_map = array('1m' => 0.2, '5m' =>  0.5, '15m' => 0.7, '1H' => 1, '4H' => 1, '1D' => 1, '3D' => 1.1);  // values for increasing / decreasing speed
      $mt = &$this->market_track;
      $result = 0;

      if (!isset($mt['indicator']['RSI'])) return $result;
      $rsi_data = &$mt['indicator']['RSI'];
      file_put_contents('last_rsi_data.map', print_r($rsi_data, true));

      $rsi_ovb = 70;
      $rsi_ovs = 30;

      // TODO: apply max_exec_time

      foreach ($rsi_data as $period => $rsi)
      if (isset($extreme_map[$period])) {
        $dta = 0;
        if ($rsi >= $rsi_ovb) {
          $power = ($rsi - 60) / 10;
          $dta = $extreme_map[$period] * $power;
          if ($buy) $dta = -$dta; // slowdown buys in OVB
        }
        if ($rsi <= $rsi_ovs) {
          $power = (40 - $rsi) / 10;
          $dta = $extreme_map[$period] * $power;
          if (!$buy) $dta = -$dta; // slowdown sells in OVS
        }

        if (0 == $dta) continue;
        $result += $dta;
        $this->trade_core->LogMsg("#RSI_EXEC_OPT: applied delta %.3f for RSI period %s, value = %.1f, result = %.1f", $dta, $period, $rsi, $result);
        if ('1H' == $period && $dta < 0) {
            $exec_params['max_cost_coef'] *= 0.75;
            $exec_params['exec_time_limit'] = 3600;
        }
        if ('4H' == $period && $dta < 0) {
          $exec_params['max_cost_coef'] *= 0.6;
          $exec_params['exec_time_limit'] = 3600 * 4;
        }
      } // foreach if
      return $result;
    }

    protected function RangeBreak_speed(OrdersBatch $batch, $target_pos)  {
      $result = 0;
      $mt = &$this->market_track;
      $core = $this->trade_core;
      $engine = $core->Engine();

      if (!isset($mt['candle'])) {
         $core->LogError("#ERROR: not exist field 'candle' in market track");
         return $result;
      }
      $buy = ( $batch->target_pos > $batch->start_pos );


      $cdata = &$mt['candle'];
      $per_check = array('30m', '1H', '4H', '1D', '3D', '1W');
      $weights = array('30m' => 0.5, '1H' => 0.7, '4H' => 0.9, '1D' => 1, '3D' => 1, '1W' => 1);

      $lc = array();
      $used = 0;

      $c_close = &$cdata['close'];


      $ticker = $this->tinfo->ticker;


      $close_prv = -1; // lowest TF close
      
      file_put_contents("{$ticker}_candle.map", print_r($cdata, true));
      $mysqli = sqli();

      foreach ($per_check as $period) {
        if (!isset($c_close[$period])) continue;
        $close = $c_close[$period];
        $dta = 0;
        // break test
        $weight = $weights[$period];
        $table_name = $engine->TableName("candles__{$ticker}__$period", true, $mysqli);
        if (!$mysqli->table_exists($table_name)) continue;
        $last_rows = sqli()->select_rows('ts,high,low', $table_name, "ORDER BY ts DESC LIMIT 2");
        $fixed = array();

        if (is_array($last_rows) && 2 == count($last_rows)) {
          $used ++;
          $fixed = $last_rows[1]; // is closed candle, ready for break check
          if ($close_prv > $fixed[1]) // up breakout
              $dta = ( $buy ? 1 : -1 ) * $weight;
          if ($close_prv > 0 && $close_prv < $fixed[2]) // down breakout
              $dta = ( $buy ? -1 : 1 ) * $weight;
        } else
          $this->trade_core->LogError("#WARN: for table %s returned %s", $table_name, var_export($last_rows));

        if ($dta != 0) {
            $result += $dta;
            $this->trade_core->LogMsg("#RANGE_OPT($period): dta = %f, result = %f, close_prev = %f, bounds = %f..%f", $dta, $result, $close_prv, $fixed[2], $fixed[1]);
        }
        $close_prv = $close;
      }

      $this->trade_core->LogMsg("#RANGE_OPT: processed $used periods ");


      return $result;
    }

    protected function CalcExecSpeed(OrdersBatch $batch, $target_pos, array &$exec_params) { // must return from 1 to 10, lazy to hurry
        $engine = $this->trade_core->Engine();
        $tinfo = $engine->TickerInfo($batch->pair_id);
        $this->tinfo = $tinfo;
        if (!$tinfo)
            throw new Exception("#FATAL: no ticker info for batch ".var_export($batch, true));

        $result = parent::CalcExecSpeed($batch, $target_pos, $exec_params); // base speed select

        $mysqli = sqli();  
        $table_name = $engine->TableName("market_track", true, $mysqli);        
        if (!$mysqli->table_exists($table_name)) 
            return $result;

        $rows = $mysqli->select_rows('ts,period,value_type,value_name,`value`', $table_name, "WHERE ticker = '{$tinfo->ticker}' ");
        if (!$rows) {
            return $result;
        }

        $mt = &$this->market_track;

        foreach ($rows as $row) {
            $ts = $row[0]; // TODO: check time
            $period = $row[1];
            $vtype = $row[2];
            $vname = $row[3];

            if (!isset($mt[$vtype])) $mt[$vtype] = array();
            $data = &$mt[$vtype];
            if (!isset($data[$vname])) $data[$vname] = array();
            $named_data = &$data[$vname];
            $named_data[$period] = $row[4]; // value

            if (isset($named_data['ts'])) //
                $named_data['ts'] = max($named_data['ts'], $ts);
            else
                $named_data['ts'] = $ts;
        }
        /*
            1. checking market track date is actual
        //*/
        $buy = ( $batch->target_pos > $batch->start_pos );
        $result += $this->RSI_speed($batch, $target_pos, $exec_params); // apply deviations from overbought/oversell info
        $result += $this->RangeBreak_speed($batch, $target_pos);
        $gain = 0;

        if ($exec_params['price'] < $tinfo->bid_price && $buy) $gain = $tinfo->bid_price - $exec_params['price'];
        if ($exec_params['price'] > $tinfo->ask_price && !$buy) $gain = $exec_params['price'] - $tinfo->ask_price;
        $exec_params['gain'] = $gain;
        $this->trade_core->LogMsg("~C43~C90 #SMART_EXEC_OPT~C00: processing target_pos = %f, sig_info->target_pos = %f, speed = %f, gain = %g", $target_pos, $batch->TargetPos(), $result, $gain);
        return $result;
    }

}


?>