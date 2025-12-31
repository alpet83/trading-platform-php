<?php
  ob_implicit_flush();
  /* 
    Формирование отчета по сделкам (исполненным заявкам) в табличный вид HTML. 
    По отдельным парам должно считаться на каждую заявку: изменение RPNL и изменение UPNL;
    В типичном случае подразумевается суточный отчет, начинающийся в 8 утра/вечера UTC.
  */
  include_once('lib/common.php');
  include_once('lib/db_tools.php');
  include_once('lib/db_config.php');

  require_once 'aggr_trade.php';

  define('SATOSHI_MULT', 0.00000001);
  define ('USD_MULT', 0.000001);

  date_default_timezone_set('UTC');

  $pair_id = rqs_param('pair_id', 1);
  $start  = rqs_param('start', date('Y-m-d  08:00:00', time() - 86400));
  $t_end = strtotime($start) + 86400;
  $end = date('Y-m-d  H:00:00', $t_end);
  $end    = rqs_param('end', $end);
  $exch   = rqs_param('exchange', 'BitMEX');
  $acc_id = rqs_param('account_id', 0);
  $show_table = rqs_param('show_table', 1);
  $show_report = rqs_param('show_stats', 1);
  $show_usd  = rqs_param('show_usd', true);
  $btc_price = 80000;
  $btc_pmap = [];

  $bot_dir = strtolower("/tmp/{$exch}_bot");
  mysqli_report(MYSQLI_REPORT_ERROR);  
  

  $btc_info = null;
  foreach(['XBTUSD', 'BTCUSD'] as $pair) {
    $fname = "$bot_dir./$pair.json";
    if (file_exists($fname))
        $btc_info = file_load_json($fname);
  }  

  if (is_object($btc_info) && isset($btc_info->last_price) && $btc_info->last_price > 0)
      $btc_price = $btc_info->last_price; 

?>
<!DOCTYPE html>
<HTML>
  <HEAD>
  <TITLE>Trades report</TITLE>   
  <style type='text/css'>
   td, th { padding-left: 4pt;
        padding-right: 4pt; } 
   table { 
        border-collapse: collapse;
   }     
   .red {
        color: red;        
   }  
   .green {
        color: green;        
   }
   .white {
        color: white;        
   }   
  </style>  
  </HEAD>  
  <BODY><PRE>
<?php  
  function format_PL(string $fmt, float $val) {
    $class = $val > 0 ? 'green' : 'red';
    return sprintf("<b class=$class>$fmt</b>", $val);
  }  


  class AccumPositionBase {
    public  $ticker_info = null;

    public  $buys_qty = 0;
    public  $sells_qty = 0;
    public  $buys_volume = 0; 
    public  $sells_volume = 0; 

    public  $buy_trades = 0;
    public  $sell_trades = 0;

    public $last_price = 0;
    public $med_price = 0;
    public $curr_pos = 0;

    public $closed = [];

    public $RPnL = 0;
    public $RPnL_accum = 0;

    public $UPnL = 0;

    public $id = 0;

    
    public function __construct(\stdClass $ti) {
        $this->ticker_info = $ti;
    }

    public function avg_buy_price() {
        if ($this->buys_qty > 0)
            return $this->buys_volume / $this->buys_qty;
        return 0; 
    }
    public function avg_sell_price() {
        if ($this->sells_qty > 0)
            return $this->sells_volume / $this->sells_qty;
        return 0; 
    }

    public function calc_results() {        
        global $btc_price, $show_usd;
        $ti = $this->ticker_info;
        $coef = 1;
        $is_btc_pair = $ti->is_btc_pair;
        
        if ($show_usd && $is_btc_pair)
            $coef = $btc_price;
        if (!$show_usd && $is_btc_pair)
            $coef = 1;
        if (!$show_usd && !$is_btc_pair)    
            $coef = 1 / $btc_price;

        $avg_buy = $this->avg_buy_price();
        $avg_sell = $this->avg_sell_price();         
        $cross = min($this->buys_qty, $this->sells_qty);      
        $this->RPnL = 0;
        if ($cross > 0) 
            $this->RPnL = $cross * ($avg_sell - $avg_buy) * $coef;               

        foreach ($this->closed as $closed_pos)
            if (is_object($closed_pos))      
                $this->RPnL += $closed_pos->RPnL;

        $saldo_qty = ($this->buys_qty + $this->sells_qty);

        $med_price = 0;
        if ($saldo_qty > 0)
            $med_price = ($avg_buy * $this->buys_qty + $avg_sell * $this->sells_qty) / $saldo_qty;
        else 
            $med_price = max($avg_buy, $avg_sell);        
        $this->UPnL = $this->curr_pos * ($this->last_price - $med_price) * $coef;
        $this->med_price = $med_price;    
    }

  }

  class AccumPosition extends AccumPositionBase {
    public $zero_reset = true;

    public $by_date = [];  // map[date] = [RPnL, UPnL]  

    

    public function apply(bool $buy, float $price, float $qty, float $curr_pos) {
        $prev_pos = $buy ? $curr_pos - $qty : $curr_pos + $qty;
        $this->curr_pos = $curr_pos; 
        $zero_cross = signval($curr_pos) != signval($prev_pos);    

        if ( (0 == $curr_pos || $zero_cross) && $this->zero_reset ) { // сделка закрыла прошлую позицию, теперь все сброшено на текущую
            if ($prev_pos != 0)
                $this->accum($prev_pos < 0, $price, abs($prev_pos)); // закрытие предыдущей позиции
            $this->close();   // новая позиция                   
            $this->accum($curr_pos > 0, $price, abs($curr_pos));            
        }          
        else 
            $this->accum($buy, $price, $qty);
        
    }

    protected function accum(bool $buy, float $price, float $qty) {
        if ($buy) {
            $this->buys_qty += $qty;  
            $this->buys_volume += $qty * $price;
            $this->buy_trades ++;
        } else {
            $this->sells_qty += $qty;
            $this->sells_volume += $qty * $price;
            $this->sell_trades ++;
        }
        $this->last_price = $price;      
    }

    public function close()    {                
        $closed = clone $this;        
        $closed->calc_results();        
        $this->closed []= $closed;                
        $closed->closed = []; // reduce links
        $this->reset();
        $this->id ++;
    }

    public function format_results(string $prefix, string $ts) {      
        global $btc_price, $show_usd; 
        $ti = $this->ticker_info;  
        $qcurr = $ti->quote_currency ?? 'USD';
        $is_btc_pair = ($qcurr == 'XBT' || $qcurr == 'BTC');      
        $pch = $is_btc_pair ? '₿' : '$';

        $result = sprintf("$prefix buy volume: $pch%.2f, sell volume: $pch%.2f\n", $this->buys_volume, $this->sells_volume);
        $avg_buy = $this->avg_buy_price();
        $avg_sell = $this->avg_sell_price();  

        $actual_price = NearestPrice($ts);
        if ($actual_price > 0) {
            $this->last_price = $actual_price;
            $result .= sprintf("$prefix actual price for %s:<b>$pch%.2f</b>\n", $ts, $actual_price);
        }
        $this->calc_results();
        $pp = $ti->price_precision ?? 2;      
        $curr_ch = $show_usd ? '$' : '₿';

        $result .= sprintf("$prefix average buy = $pch%.{$pp}f x %f in %d trades\n", $avg_buy, $this->buys_qty, $this->buy_trades);
        $result .= sprintf("$prefix average sell = $pch%.{$pp}f x %f in %d trades\n", $avg_sell, $this->sells_qty, $this->sell_trades);  

        $xp = (!$show_usd || $is_btc_pair) ? 5 : 2;
        $result .= sprintf("$prefix open median price = $pch%.{$pp}f, UPnL = %s\n",  $this->med_price, format_PL("$curr_ch%.{$xp}f",  $this->UPnL));       
        $cross = min($this->buys_qty, $this->sells_qty);      
        $result .= sprintf("$prefix cross qty = %.4f, ", $cross);
        
        $result .= sprintf("<u>RPL accum = %s, ", format_PL(" $curr_ch%.{$xp}f", $this->RPnL_accum) );
        $result .= sprintf("RPL summary = %s</u>\n", format_PL(" $curr_ch%.{$xp}f", $this->RPnL) );
        return $result;  
    }

    public function reset() {
        $this->buys_qty = 0;
        $this->sells_qty = 0;
        $this->buys_volume = 0;
        $this->sells_volume = 0;       
        $this->buy_trades = 0;
        $this->sell_trades = 0;
    }
  }

  $exch = strtolower($exch);
  
  $mysqli = init_remote_db('trading');
  if (!$mysqli) 
    die("#FATAL: DB `trading` inaccessible!\n");

  $rows = $mysqli->select_rows('ts,close', 'bitfinex.candles__btcusd__1H', "WHERE ts >= '$start' AND ts < '$end' ORDER BY ts ");
  foreach ($rows as $row) {
    $tkey = floor(strtotime($row[0]) / 3600); // hours
    $btc_pmap[$tkey] = $row[1];
  }      


  $pmap_file = "$bot_dir/pairs_map.json";   
  if (!file_exists($pmap_file))
    die("#FATAL: not found $pmap_file\n");

  $json = file_get_contents($pmap_file);  
  $pairs_map = json_decode($json, true);  

  $t_table = "$exch.ticker_map";  // previously was datafeed.{$exch}__ticker_map
  $ticker_map = [];
  if ($mysqli->table_exists($t_table))
       $ticker_map = $mysqli->select_map("pair_id, ticker", $t_table);


?>
<!DOCTYPE html>
<HTML>
  <HEAD>
  <style type='text/css'>
   td { padding-left: 4pt;
        padding-right: 4pt; } 
  </style>  
  </HEAD>  
  <BODY><PRE>

<?php
    $price_data = []; // ['YYYY-mm-dd HH:MM] => price, minute timeframe
    $scan_date = '';
    function NearestPrice(string $ts): float {
        global $price_data, $scan_date;
        if (0 == count($price_data))
              return 0;
        $tsm = substr($ts, 0, 16); 
        $scan_date = substr($ts, 0, 10); // YYYY-mm-dd
        if (isset($price_data[$tsm]))
            return $price_data[$tsm];
        $keys = array_keys($price_data);
        $flt  = array_filter($keys, function(string $val) {                
                global $scan_date;
                return str_contains($val, $scan_date);
            }); 
        if (!is_array($flt))
            $flt = $keys;
        foreach ($flt as $ts_ref) 
            if ($ts_ref >= $tsm) 
                return $price_data[$ts_ref];
            
        return $price_data[array_pop($flt)];
    }
  

    function AmountToQty(\stdClass $ti, float $price, float $value) {
        global $mysqli, $pair_id, $btc_price, $exch;    

        if ($ti->is_quanto && $ti->multiplier > 0 && $btc_price > 0) {
            $btc_coef = SATOSHI_MULT * $ti->multiplier;   // this value not fluctuated, typically 0.0001
            $btc_cost = $price * $btc_coef * $value;
            $usd_cost = $btc_cost * $btc_price;  // this value may be variable
            $res = $usd_cost / $price;      
            return $res;                 // real position in base coins
        }
        if ($ti->is_inverse) {
            return $value / $price;  // may only XBTUSD and descedants
        }

        if (false === $ti->is_quanto && isset($ti->pos_mult) && $ti->pos_mult > 0)
            return $value / $ti->pos_mult;
        log_cmsg("#WARN: AmountToQty: no conversion for %s, returns raw %f", $ti->pair, $value);
        return $value;
    }

    $table_name = 'trading.'.strtolower($exch).'__trades';  

    function FormatTrades(\stdClass $ti, array $list, \stdClass $stats) {
        global $btc_price, $btc_pmap, $show_usd, $show_table, $show_report, $start, $end;

        $table_text = "<table border=1 style='border-collapse: collapse;'>\n";
        $table_text .= " <thead><tr><th>Time<th>Price<th>Matched<th>In pos<th>Curr pos<th>Curr money<th>Real pos<th>Avg. buy<th>Avg. sell<th>RPnL accum<th>RPnL fix\n";


        $stats->period->close();
        $total_volume = 0;
        if (is_object($stats) && isset($stats->open_volume))
            $total_volume += $stats->open_volume;

        $price = 0;
        $max_pcost = 0;    
        $cpos   = 0; 
        $curr_pos = 0;
        $pos = $stats->pos;
        $period = $stats->period;
        $daily = new AccumPosition($ti);
        $pos_price = 0;
        
        $pos_trades = 0;
        $qpos = 0;

        if (is_object($stats) && isset($stats->last_pos))
            $curr_pos = $stats->last_pos;
    
        $prev_dt = '';
        $ppdt = '';
        $pp = $ti->price_precision ?? 3;
        $last_closed = 0;
        /**
         * При сканировании сделок позиция накапливается или фиксируется. В случае фиксакции позиции появляется реализованный результат (прибыль-убыток), объемно пропорциональный нереализованному. 
         * Статистика кошелька для BitMEX отмечает полную фиксацию позиции, т.е. баланс средств меняется когда позиция = 0. При этом торговый баланс постоянно корректируется на RPnL и UPnL.
         * Соответственно график баланса средств для статистики BitMEX довольно ступенчатый, и не меняется во время частичного прикрытия позиций. На каждую отчетную точку времени, имеются 
         * данные об открытой позиции, её аккумулированной реализованного и нереализованного PL и балансе средств клиентского кошелька, если позиция открыта. Для закрытой позиции имеется исключительно
         * баланс кошелька, в котором все сальдировалось. 
         * Итоговым результатом отчетного периода, после отработки скрипта, соответственно должны быть: итоговый баланс средств (общее сальдо) с дельтой и % изменения, аккумулированные RPnL и UPnL, вместе с дельтами изменения.
         * Нормальным будет тогда такое, когда накопленный реализованный убыток уменьшился (позиция закрылась полностью), вместе с почти нейтральным изменением общего баланса. 
         * Для расчетов реализованных результатов, и баланса кошелька вполне достаточно одних сделок. Тогда как для полного отчета потребуется знать цены на отчетную точку, соответственно это свечные данные, тики или история тикеров.
         */

        foreach ($list as $i => $row) {       // <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<
            // printf("<!-- %s -->\n", json_encode($row));      
            $pos_trades ++;
            $ts = $row['ts'];
            $ts = str_replace(' ', 'Z', $ts); // UTC conversion
            $tt = strtotime_ms($ts);
            $dt = date_ms('Y-m-d', $tt);
            $hours = round($tt / 3600000);
            if (isset($btc_pmap[$hours]))
                $btc_price = $btc_pmap[$hours];

            if ($prev_dt != $dt && $i > 0) {
                $daily->calc_results();                
                $day_res = [$daily->RPnL, $daily->RPnL_accum, $daily->UPnL];                
                $period->by_date[$prev_dt] = $day_res;        
                $daily->reset();
                $daily->$RPnL = $daily->RPnL_accum = 0;
            }

            $prev_dt = $dt;
            $price = $row['price'];
            $buy = $row['buy'];
            $amount = $row['amount'];
            $sign = $buy ? 1 : - 1;
            $cpos = $row['position'];                 
            $curr_pos = AmountToQty($ti,$price, $cpos);                       
            
            // $out_pos = $in_pos + $amount;      
            $qty = AmountToQty($ti,$price, $amount);
            $rpnl = 0;
            $prev_pos = $buy ? $curr_pos - $qty : $curr_pos + $qty;

            $pos->apply($buy, $price, $qty, $curr_pos);
            $daily->apply($buy, $price, $qty, $curr_pos);
            $period->apply($buy, $price, $qty, $curr_pos);

            $closed_pos = null;
            
            if (count($pos->closed) > $last_closed) {
                $closed_pos = $pos->closed[$last_closed];
                $last_closed = count($pos->closed);                
            }

            $amount *= $sign;
            $qty *= $sign;

            $style = 'background-color:'.(($buy) ? '#e0ffe0' : '#ffe0e0');

            $qpos -= $qty * $price;
            $in_pos = $curr_pos - $qty;          

            $avg_buy = $pos->avg_buy_price();
            $avg_sell = $pos->avg_sell_price();

            $rpnl = $rpnl_fix = 0;
            if (is_object($closed_pos)) { // закрытие позиции имеет приоритет
                $rpnl_fix = $closed_pos->RPnL;
                $avg_buy = $closed_pos->avg_buy_price();
                $avg_sell = $closed_pos->avg_sell_price();    
            }    
            elseif (abs($curr_pos) < $prev_pos) {
                if (0 == $avg_buy) $avg_buy = $price;
                if (0 == $avg_sell) $avg_sell = $price;
                $dir = -1; // if sell qty = -1, so need invert
                $rpnl = ($avg_sell - $avg_buy) * $qty * $dir;
                if ($show_usd && $ti->is_btc_pair)
                    $rpnl *= $btc_price;
                elseif (!$show_usd && !$ti->is_btc_pair)
                    $rpnl /= $btc_price; 

                $pos->RPnL_accum += $rpnl;
                $daily->RPnL_accum += $rpnl;
                $period->RPnL_accum += $rpnl; 
            }    


            $max_pcost = max($max_pcost, abs($curr_pos * $price));
            $table_text .= sprintf("\t<tr style='$style'><td>%s<td>%f<td>%.5f<td>%.4f", $ts, $price, $qty, $in_pos);
            $table_text .= sprintf("<td>%.4f<td>%.2f<td>%s<td>%.{$pp}f<td>%.{$pp}f<td>%.5f<td>%.4f</tr>\n", $curr_pos, $qpos, $cpos, $avg_buy, $avg_sell, $rpnl, $rpnl_fix);        
        }
        $table_text .= "</table>\n";    
        $result = '';
        if ($show_table) $result .= $table_text;
        $result .= sprintf("max position cost: $%.2f\n", $max_pcost);
        $report = $pos->format_results("Position", $start);  // позиция может накапливаться несколько дней, перекрыв период. А может быть только что открыта
        $report .= $period->format_results("Period", $end); // результат периода строго включает данные между граничными метками времени
        if ($show_report) $result .= $report;        

        $saldo_volume = $period->buys_volume - $period->sells_volume;    
        $total_volume += $saldo_volume;
        $result .= sprintf("Position saldo volume = $%.2f, total volume = $%.2f \n", $saldo_volume, $total_volume);                 

        if (!is_null($stats)) {
            $stats->open_volume = $saldo_volume;
            $stats->pos_price = $pos_price;
            $stats->last_pos = $curr_pos;
        }

        return $result;
    } 

    $saldo_info = [];
    function FormatReport(\stdClass $ti, bool $print_saldo) {
        global $mysqli, $start, $end, $exch, $acc_id, $table_name, $pairs_map, $saldo_info;    
        $pair_id = $ti->pair_id;
        $pair = $ti->pair;
        $result = sprintf("<h2>Trades report for %s</h2>\n", $pair);

        $strict = "(pair_id = $pair_id) AND (account_id = $acc_id) AND (flags = 1)";

        $first = $mysqli->select_value('MIN(ts)', $table_name, "WHERE $strict"); 
        if (is_null($first))
        return "<div class=red>#ERROR: failed retrieve first trade time for $pair</div>\n";

        $boot = null;

        if ($first < $start) {
            // TODO: cross zero checking (position reversal), can product nearest trade timestamp
            $zero = $mysqli->select_value('ts', $table_name, 
                "WHERE $strict AND (ts <= '$start')  AND (position = 0) ORDER BY ts DESC"); // latest with 0

            if (is_null($zero)) 
                $zero = $first;

            // следующие трейды нужны, чтобы оценить среднюю цену входа   
            $best = $mysqli->select_rows('*', $table_name,    
                        "WHERE $strict AND (ts >= '$zero') AND (ts < '$start') ORDER BY ts", MYSQLI_ASSOC);               
            if (is_array($best)) {                  
                $boot = $best;
                $result .= sprintf("<div>Zero position detected at %s, boot trades %d before %s</div>\n", $zero, count($boot), $start);
            }    
        }      


        $stats  = new \stdClass();
        $stats->pos = new AccumPosition($ti);
        $stats->period = new AccumPosition($ti);
        $stats->period->zero_reset = false;

        if ($boot) {
            $result .= "<h3>bootstrap stats:</h3>\n";
            $result .= FormatTrades($ti, $boot, $stats);
        }
        
        
        $list = $mysqli->select_rows('*', $table_name, 
                            "WHERE $strict AND (ts >= '$start') AND (ts < '$end') ORDER BY ts", MYSQLI_ASSOC);
        // printf("%s\n", $mysqli->last_query);                          
        if (!is_array($list))
            return "<div class=red>ERROR: failed retrieve orders</div>\n";
        // if (0 == count($list))  return "INFO: no trades loaded between $start and $end\n";


        $result .= "<h3>Period stats:</h3>\n";
        $result .= FormatTrades($ti, $list, $stats);

        $period = $stats->period;

        $RPnL = $saldo_info["RPnL"] ?? new stdClass();
        $UPnL = $saldo_info["UPnL"] ?? new stdClass();
        $saldo_info["RPnL"] = $RPnL;
        $saldo_info["UPnL"] = $UPnL;
        if (!isset($RPnL->details))
            $RPnL->details = [];

        $RPnL->details[$pair] = $period->by_date;

        $RPnL->value = $period->RPnL;  
        $UPnL->value = $period->UPnL;    
        $RPnL->saldo = $RPnL->saldo ?? 0 + $period->RPnL;    

        // $upnl = $total_amount * ($price - $last_entry);
        // $result .= sprintf("Last position %f x $%f, unrealzied P/L = $%.2f \n", $total_amount, $last_entry, $upnl);


        return $result;
    }  
 


    function ReportForPair(int $pair_id) {
        global $mysqli, $pairs_map, $ticker_map, $exch, $bot_dir, $price_data, $start, $end; 
        // printf("<h2>Pair #%d</h2>\n", $pair_id);
        $pair = $pairs_map[$pair_id] ?? false;    
        if (false === $pair) {            
            print ("<div class=red>#WARN: pair_id $pair_id not found</div>\n");  
            return;
        }
        $ticker_file = $bot_dir."/$pair.json"; 
        if (!file_exists($ticker_file)) {
            echo ("<div class=red>#FATAL: not found $ticker_file\n</div>");
            return;
        }   
        $json = file_get_contents($ticker_file);
        $ti = json_decode($json);
        $ticker = $ticker_map[$pair_id] ?? false;
        $price_data = [];
        if ($ticker) {
            $c_table = strtolower("{$exch}.candles__{$ticker}");
            $after = date('Y-m-d H:i:00', strtotime($start) - 86400 * 90); // ожидается, что в течении квартала все позиции закрывались хотя-бы раз
            if ($mysqli->table_exists($c_table)) {
                $candles = $mysqli->select_rows('SUBSTR(ts, 1, 16) as ts,close', $c_table, "WHERE ts > '$after' AND ts < '$end' ");
                if (is_array($candles))                              {                    
                    foreach ($candles as $row) {
                        $tk = $row[0];                        
                        $price_data[$tk] = $row[1];                       
                    }                                
                    $first = array_slice($price_data, 0, 1, true);                
                    log_cmsg("~C96#PERF:~C00 from %s loaded %d candles after %s, first: %s",                
                                $c_table, count($price_data), $after, json_encode($first));
                }                
            }
            else
                printf("<h3>WARN: not exists table %s</h3>\n", $c_table);
        }
        else 
           printf("<h3>WARN: no ticker for pair %s = no candle data</h3>\n", $pair);

        if (is_object($ti) && !is_null($ti)) {
            $qcurr = $ti->quote_currency ?? 'USD';      
            $ti->is_btc_pair = ($qcurr == 'XBT' || $qcurr == 'BTC');      
            echo FormatReport($ti, true);
        }    
        else   
            printf("<div class=red>#ERROR: failed decode ticker info for %d, loaded: %s</div>\n", $pair_id, $json);
        ob_flush();
    }  

    if (!$mysqli->table_exists($table_name))
        die("<div class=red>ERROR: table $table_name not exists\n</div>");
  
    $pairs = $mysqli->select_col('pair_id', $table_name, "WHERE (account_id = $acc_id) AND (ts <= '$end') GROUP BY pair_id");    

    if ($pair_id > 0) {
        ReportForPair($pair_id);
    } elseif (is_array($pairs)) {
        foreach ($pairs as $pair_id) {
        ReportForPair($pair_id);
        }
    }  

    //  print_r($saldo_info);
    $RPnL = $saldo_info["RPnL"];
    if (!is_object($RPnL)) die(''); 
    echo "<h2>Summary RPL</h2>\n";
    echo "<table border =1>\n";
    echo "<tr><th>Date";
    $dkeys = [];
    $count = 0;
    foreach ($RPnL->details as $pair => $data)  {
        // printf("<!-- %s -->\n", json_encode($data));
        echo "<th>$pair";
        $count ++;
        $rpl = 0;
        foreach ($data as $date => $vals) 
        if (0 == $vals[0])
            unset($data[$date]);
        
        $dkeys = array_replace($dkeys, $data);
    }
    echo "<th>Saldo<th>Reference<th>Deposit/Withdraw<th>EoD funds\n";
    $dkeys = array_keys($dkeys);
    sort($dkeys);
    $t_start = strtotime($start);
    $qp = $show_usd ? 0 : 3;  // quantity precision
    $fmt = $show_usd ? '$%.0f' : '₿%.4f';
    $key = $show_usd ? 'value' : 'value_btc';

    // TODO: yet now only table trading.bitmex__summary is available, need generation for other exchanges
    $ref_map = $mysqli->select_map('date,SUM(RPnL_wallet) as rpnl', "{$exch}__summary", "WHERE (account_id = $acc_id) GROUP BY date");

    $prev_date = $dkeys[0];    
    foreach ($dkeys as $n_row => $date) {
        $mid = $date.' 12:00:00';
        $hour = round(strtotime($mid) / 3600);
        $btc_price = $btc_pmap[$hour] ?? $btc_price;
        $ref_btc = $ref_map[$date] ?? 0;

        $eod = $date.' 23:59:59';
        $bgc = 'Canvas';
        if (0 == $n_row % 2) $bgc = '#ccccDD';
        if ($ref_btc < -0.1) $bgc = '#FFC0C0';
        if ($ref_btc < -0.2) $bgc = '#FFA0A0';


        $tag = strtotime($eod) <  $t_start ? '' : '<b>';
        echo "<tr style='background: $bgc;'><td>$n_row. $tag$date</td>";
        $saldo = 0;
        foreach ($RPnL->details as $pair => $data) {
            $val = $data[$date] ?? [0, 0];
            $rpl = $val[1];
            $saldo += $rpl;
            $tag = abs($rpl) > 1000 ? '<b>' : '';
            $cl = $rpl >= 0 ? 'green' : 'red';
            if (0 == $rpl) $cl = 'white';
            printf("<td><font class=$cl>$tag$%.{$qp}f</font>", $rpl);
        }

        $tag = abs($saldo) > 5000 ? '<b>' : '';
        $cl = $saldo >= 0 ? 'green' : 'red';
        printf("<td><span class=$cl>$tag $fmt</span></td>", $saldo);     
        printf("<td>%.4f</td>", $ref_btc);        
        $funds_chg = 0;         
        $rows = $mysqli->select_rows('SUM(value_usd) as usd,SUM(value_btc) as btc,withdrawal', $exch.'__deposit_history', 
                                     "WHERE (ts > '$prev_date') AND (ts <= '$eod') AND (account_id = $acc_id) GROUP BY withdrawal", MYSQLI_OBJECT);
        if (is_array($rows) && count($rows) > 0) {
            foreach ($rows as $row) {
                $sign = $row->withdrawal ? -1 : 1;
                if ($show_usd) {
                    $funds_chg += $row->usd * $sign;
                    $funds_chg += $row->btc * $sign * $btc_price;
                }
                else {
                    $funds_chg += $row->usd * $sign / $btc_price;
                    $funds_chg += $row->btc * $sign;
                }    
            }
            printf("<td>$fmt</td>", $funds_chg); 
        }       
        else
           print("<td></td>");       

        $funds_btc = $mysqli->select_value('balance', $exch.'__wallet_history', "WHERE (ts <= '$eod') AND (currency = 'XBt') AND (account_id = $acc_id) ORDER BY trans_ts DESC") ?? 0;
        $funds_usd = $mysqli->select_value('balance', $exch.'__wallet_history', "WHERE (ts <= '$eod') AND (currency = 'USDt') AND (account_id = $acc_id) ORDER BY trans_ts DESC") ?? 0;   
        $funds = 0;
        if ($show_usd) 
            $funds = $funds_usd + $funds_btc * $btc_price;
        else 
            $funds = $funds_usd / $btc_price + $funds_btc;
        printf("<td><b>$fmt</b>", $funds);
        echo "</tr>\n";
        flush();
        $prev_date = $eod;
    }
        
    echo "<tr><td>Period:<td colspan=$count>{$RPnL->value}\n";
    echo "</table>\n";
    printf("Used pairs: %s", json_encode(array_values($pairs_map)));
    print_r($dkeys);
?>  