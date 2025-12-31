 <?php
  include_once('lib/common.php');
  include_once('lib/db_tools.php');
  include_once('lib/db_config.php');

  define('SATOSHI_MULT', 0.00000001);

  $exch = rqs_param('exchange', 'bitfinex');  
  
  // TODO: load pairs map total
  $pairs_map[1] = 'btcusd';
  $pairs_map[3] = 'ethusd';

  define('PAIRS_MAP_CACHE', 'pairs_map.last.json');

  if (!file_exists(PAIRS_MAP_CACHE) || time() - filemtime(PAIRS_MAP_CACHE) > 3600 * 24 * 30) {
     $pmap = file_get_contents('https://vps.alpet.me/pairs_map.php');
     file_put_contents(PAIRS_MAP_CACHE, $pmap);
  }   
  $pmap = file_get_contents(PAIRS_MAP_CACHE);
  $pmap = json_decode($pmap, true);
  echo "<!--\n";
  // print_r($pmap);
  echo "-->";
  foreach ($pmap as $pid => $prec) 
    if (!isset($pairs_map[$pid]))
        $pairs_map[$pid] = strtolower($prec[0]);

  $pair_id = rqs_param('pair_id', 3);
  $pair = false;
  if (isset($pairs_map[$pair_id]))
     $pair = $pairs_map[$pair_id];


  $ticker = array();   
?>
<html>
 <head>
   <title>Trading stats for <?php echo " $exch - $pair";?></title>
   <style type=text/css>
     th, td {
        padding-top: 4px;
     padding-bottom: 4px;
       padding-left: 10px;
       padding-right: 10px;
     }
     .dig {
        text-align: right;
     }
   </style>
 </head>
 <body>
<pre>
<?php
  /*  Формирование отчетов эффективной торговли за произвольный период, для одной пары. 
      1. Выдача статистики по агрегированным сделкам (вход-выход), с расчетом profit factor, sharp ratio
      2. Выдача графика эквити по агрегированным  сделкам с учетом фильтров
  */
  require_once 'aggr_trade.php';
  
  class TickerInfo {
    public bool $is_quanto;
    public bool $is_reverse;
    public float $last_price;    
    public float $multiplier;
    
    public function __construct(int $pair_id) {
      global $exch, $mysqli; 
      $info = $mysqli->select_row('last_price,multiplier,flags', "{$exch}__tickers", "WHERE pair_id = $pair_id");
      if (!$info) {
         echo "#WARN: no ticker record / no tickers table exists.\n";
         return;
      }   
      $this->last_price = $info[0];
      $this->multiplier = $info[1];
      $this->is_quanto = $info[2] & 2;
      $this->is_inverse = $info[2] & 1;
    }
  }

  function array_max(array $data) {
    $result = $data[0];
    foreach ($data as $v)  
      $result = max($v, $result);
    return $result;
  }
  function array_min(array $data) {
    $result = $data[0];
    foreach ($data as $v)  
      $result = min($v, $result);
    return $result;
  }


  function calc_range(array &$candles, $period = 10) {
    $highs = array();
    $lows = array();
    $key = "range_$period";
    foreach ($candles as $ts => $row) {
      $highs []= $row['high'];
      $lows []= $row['low'];
      if (count($highs) > $period) array_shift($highs);
      if (count($lows) > $period) array_shift($lows);
      $row[$key] = array_max($highs) - array_min($lows);
      $candles[$ts] = $row;
    }
      
  }

  function find_candle(array $candles, string $tsn): ?array {
    $result = null;
    if ($candles)
    foreach ($candles as $ts => $cd) {
      if ($ts > $tsn) break;
      $result = $cd;      
    }
    return $result;
  }

  function find_funds(string $ts): ?array {
    global $funds_hst; 
    $result = null;
    foreach ($funds_hst as $row) {
      if ($row['ts'] > $ts) break;
      $result = $row;      
    }
    return $result;
  }

  function btc_price(string $ts) {
    // TODO: scan 1M candles
    return 50000;
  }

  function contracts_to_amount(string $ts, float $value, float $price): float {
    global $tinfo;
    $btc_price = btc_price($ts);
    if ($tinfo->is_quanto && $tinfo->multiplier > 0 && $btc_price > 0) {
      $btc_coef = SATOSHI_MULT * $tinfo->multiplier;   // this value not fluctuated, typically 0.0001
      $btc_cost = $price * $btc_coef * $value;
      $usd_cost = $btc_cost * $btc_price;  // this value may be variable
      $res = $usd_cost / $price;
      return $res;             // real position in base coins
    }
    if ($tinfo->is_inverse) {
      return $value / $price;  // may only XBTUSD and descedants
    }
    return $value;
  }
  
  $mysqli = init_remote_db('trading');

  $from_ts = rqs_param('from_ts', '2024-01-01 00:00:00');
  $last_ts = rqs_param('last_ts', date(SQL_TIMESTAMP));
  
  $min_pl =  rqs_param('min_pl', 0);
  $account_id = rqs_param('account_id', -1);
  $exch = strtolower($exch);  

  


  $tinfo = new TickerInfo($pair_id);
  print_r($tinfo);

  $strict = "WHERE (pair_id = $pair_id) AND (exec_qty != 0) AND (ts >= '$from_ts') AND (ts <= '$last_ts') AND (account_id = $account_id)";
  if ($account_id > 0)
    $strict .= " AND (account_id = $account_id)";

  $batches = $mysqli->select_rows('id, ts, start_pos, target_pos, exec_price, exec_qty', $exch.'__batches', $strict, MYSQLI_ASSOC);
  if (!$batches)
    die("#FATAL: retrieve batches failed");

  $funds_hst = $mysqli->select_rows('*', $exch.'__funds_history', '', MYSQLI_ASSOC);

  $day_cmap = array(); 

  if ($pair) {
    mysqli_report(MYSQLI_REPORT_OFF); 
    $pair = $pairs_map[$pair_id];
    $table_cd = 'datafeed.'.$exch.'__candles__'.$pair.'__1D';
    $day_cd = false;
    if ($mysqli->table_exists($table_cd))
       $day_cd = $mysqli->select_rows('*', $table_cd, '', MYSQLI_ASSOC);
    if (is_array($day_cd)) {
       foreach ($day_cd as $row) {      
         $day_cmap[$row['ts']] = $row;
       }  
       calc_range($day_cmap, 10);
    }
  }  
  
  echo "<!--\n";
  // print_r($batches);
  echo "-->\n";

  $aggregator = new SignalAggregator(); 
  $aggregator->Process($batches);

  
  // print_r($aggregator->agr_trades);
  echo "<table border=1 style='border-collapse:collapse;'>\n";
  echo "<tr><th>Enter time<th>exit time<th>side<th>enter price<th>exit price<th>qty<th>profit<th>10D range %\n";
  $saldo = 0;
  $saldo_master = 0;

  $profit_cnt = 0;
  $loss_cnt = 0;

  foreach ($aggregator->agr_trades as $agt) {
    $bgc = '#FFFFAA';
    $pl = $agt->profit; 
    if (abs($pl) < $min_pl) continue;

    if ($pl < 0) {
      $bgc = '#FFAAAA';
      $loss_cnt ++;
    }  
    elseif ($pl > 0) { 
      $bgc = '#AAFFAA';
      $profit_cnt ++;
    }    
    // if (abs($agt->profit) < 25) continue;
    $side = $agt->direction > 0 ? 'LONG' : 'SHORT';
    echo "<tr style='background-color:$bgc;'><td>{$agt->enter_ts}<td>{$agt->exit_ts}<td>$side";
    $tag = 'span';
    if (abs($pl) >= 3000) $tag = 'strong';
    $funds = find_funds($agt->exit_ts);
    $pl_pp = 0;
    $pl_master = 0;
    if ($funds) {
      $pl_pp = 100 * $pl / $funds['value'];
      $pl_master = $pl / $funds['position_coef'];
    }    
    
    printf("<td>$%.2f<td>$%.2f<td class=dig>%.3f<td class=dig><$tag>$%.1f %.1f%%</$tag>", $agt->enter_price, $agt->exit_price, $agt->exit_qty, $pl, $pl_pp);     
    $cd = find_candle($day_cmap, $agt->exit_ts);
    if ($cd && isset($cd['range_10'])) {
      printf("<td>%.1f %%", $cd['range_10'] / $agt->exit_price * $pl_pp * 10);
    } else
      printf("<td>n/c");
     

    $saldo += $pl;
    $saldo_master += $pl_master;
    printf ("\n<!-- %s %s -->\n", print_r($agt, true), print_r($cd, true));
  }
  printf("<tr><td colspan=6>Total<td><b>$%.1f</b><td>\n", $saldo);
  echo "</table>\n";
  if ($loss_cnt > 0)
     printf("<p>Profit factor: %.1f \n", $profit_cnt / $loss_cnt);
  printf("<code>%s</code>", print_r($aggregator->last_trade, true));
?>