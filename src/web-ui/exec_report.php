<?php // content="text/plain; charset=utf-8"
    $path = __DIR__;
    chdir($path);
    $tz = file_get_contents('/etc/timezone');
    $tz = trim($tz);

    require_once ('../jpgraph/jpgraph.php');
    require_once ('../jpgraph/jpgraph_line.php');
    require_once ('../jpgraph/jpgraph_date.php');
    require_once ('../jpgraph/jpgraph_stock.php');
    require_once ('../jpgraph/jpgraph_scatter.php');
    // require_once ('../jpgraph/themes/OceanTheme.class.php');
    require_once 'jpgraph-dark-theme.php';

    require_once ($path.'/lib/common.php');  
    require_once ($path.'/lib/esctext.php');  
    require_once ($path.'/lib/db_config.php');  
    require_once ($path.'/lib/db_tools.php');
    require_once ('lib/mini_core.php');
    
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    mysqli_report(MYSQLI_REPORT_ERROR);  
  

    try {
        date_default_timezone_set($tz);
    } catch(Exception $E) {
        log_msg("#EXCEPTION: {$E->getMessage()}");
    }
    
    $mysqli = init_remote_db('trading');
    if (!$mysqli)
      die("#FATAL: cannot connect to DB!\n");

    

    // $mysqli->try_query("SET time_zone = 'Europe/Moscow';\n");
    $width = 1600;
    $height = 1280;
    $cname = 'balance';
    $exch = 'bitfinex';
    $period = 'daily'; // for hour, or day
    $interval = 15;    // every 15 minutes
    $pair_id = 1;
    $bot = false;
    $acc_id = 0;
    $mark_source = 'batches';


    $offline_mode = true;
    $sapi = php_sapi_name();

    if($sapi === 'cli') {    
      if(isset($argv[1]))
        $exch = $argv[1];      
      if(isset($argv[2]))
        $pair_id = $argv[2];

      if(isset($argv[3]))
        $period = $argv[3];
    }
    elseif (isset($_SERVER['REMOTE_ADDR'])) {
      $offline_mode = false;
      $bot = rqs_param('bot', false);
      $exch = rqs_param('exchange', $exch);
      $acc_id = rqs_param('account', 0);
      $width = rqs_param('width', $width);
      $height = rqs_param('height', $height);
      $period = rqs_param('period', $period);
      $interval = rqs_param('interval', $interval);
      $mark_source = rqs_param('mark_source', $mark_source);
      $pair_id = rqs_param('pair_id', $pair_id);
    } else
      die("#FATAL: invalid environment!\n");

    if (!file_exists("$exch/data"))  
        mkdir("$exch/data", 0775, true);  
    if (!file_exists("$path/log"))      
        mkdir("$path/log", 0775, true);

    $log_file = fopen("$path/log/batch_report-$exch-$sapi-$pair_id.log", 'w');
    log_msg("#CWD: $path");
    if ($offline_mode)
      log_cmsg("~C93 #DBG:~C00 Detected offline mode, working dir: %s", getcwd());
    else
      log_msg("#PARAMS(GET): ".json_encode($_GET));

    set_error_handler(function($code, $string, $file, $line){
        log_cmsg("~C91#FATAL_ERROR:~C00 $string in $file:$line");
        throw new ErrorException("[main.error_handler] ". $string,  $code, E_ERROR, $file, $line);
    }, E_ERROR | E_PARSE | E_CORE_ERROR);
  
    if (!$bot) {
        $bots = $mysqli->select_map('applicant,table_name', 'config__table_map');     
        foreach ($bots as $app => $table) {
            if ($acc_id != $mysqli->select_value('account_id', $table)) continue;
            $bot = $app;
            break;
        }
    }
    if (!$bot) die("#FATAL: can't detect bot/app name\n");

    $core = new MiniCore($mysqli, $bot);
    $engine = $core->trade_engine;

    $exch = strtolower($engine->exchange ?? $exch);
    $json = curl_http_request('http://vps.vpn/pairs_map.php?field='.$exch.'_pair');
    $pmap = json_decode($json, true);
    $symbol = 'nope';
    $ticker = false;
    $ttable = "datafeed.{$exch}__ticker_map";
    // if (!$mysqli->table_exists($ttable))  $ttable = "trading.{$exch}__ticker_map";

    if ($pmap && is_array($pmap)) {
        // log_msg("#DBG: pairs_map is ".print_r($pmap, true));
        if (isset($pmap[$pair_id])) {
          $symbol = $pmap[$pair_id][0];
          $ticker = $mysqli->select_value('ticker', $ttable, "WHERE symbol = '$symbol'");
        }
        else
          die("#FATAL: no entry in pairs_map for pair_id $pair_id\n");
    }
    else
        die("#FATAL: can't retrieve pair_map config from feed\n");

    if (false === $ticker || '' == trim($ticker)) {
      $map = $mysqli->select_map('ticker,pair_id', $ttable);
      echo format_color(" ~C93<pre>%s contains:\n", $ttable);
      print_r($map);
      $msg = format_color(" ~C91#FATAL:~C00 can't determine ticker #%d from $ttable and symbol %s, assumed candles not loaded: \n~C97{$mysqli->error}~C00", $pair_id, $symbol); 
      die($msg);
    }   

    $c_table = '`datafeed`.'.$exch.'__candles__'.$ticker;
    $s_table = '`trading`.'.$exch.'__batches';
    $ts_start = date('Y-m-d 0:00:00'); // default day
    // loading couple minute candles 
    $candles = $mysqli->select_rows('*', $c_table, "WHERE ts >= '$ts_start' ORDER BY ts DESC", MYSQLI_ASSOC);
    if (false === $candles || 0 == count($candles))
      die("#ERROR: no candles data/not exist table $c_table after $ts_start\n");

    // // WARN: batches have hard-encoded UTC timestamps!
    $batches = $mysqli->select_rows('*', $s_table, "WHERE (pair_id = $pair_id) AND (ts >= '$ts_start') AND (exec_price > 0) ORDER BY ts DESC", MYSQLI_ASSOC);
    if (false === $batches)
        die("#WARN: no batches data/not exist table $s_table after $ts_start\n");

    $candles = array_reverse($candles);
    $batch_list = array_reverse($batches);
    
    $msg = sprintf("#SRC(%s): candles = %d, batches = %d ", $ticker, count($candles), count($batches));
    $mysqli->close();
    $mysqli = null;
    log_msg($msg);

    /* Scaning algo:
      Через временной отрезок передвигается временное окно с заданным интервалом (15 минут дефолт). При наличии минутных свечей в окне, они ассемблируются в крупную свечу. При наличии сигналов, они добавляются в дополнительный график.

    //*/
    $t_open = strtotime($ts_start);
    $t_close = $t_open;

    $c_max = count($candles) - 1;
    $s_max = count($batches) - 1;
    $c_idx = 0;
    $b_idx = 0;
    $ts_open = date('Y-m-d H:i:s', $t_open);
    $xdata = [];
    $xdata_bs = [];
    $xdata_ss = [];

    $bc_data = [];

    $bs_data = [];
    $ss_data = [];
    $ba_data = [];
    $sa_data = [];
    $pv_data = [];

    $min_v = 1000000;
    $max_v = 0;

    function SignalArrowCB($x, $y, $angle) {
      $color = $angle < 180 ? 'green' : 'red';
      return [$color, '', ''];
    }

    while ($t_close < time() && $c_idx <= $c_max) {
      $t_close = $t_open + $interval * 60; // processing some amount seconds (900 for 15M)
      $ts_close = date('Y-m-d H:i:s', $t_close);

      $cdata = false;
      while ($c_idx <= $c_max)  {
        $src = $candles[$c_idx];
        $ts = $src['ts'];
        if ($ts >= $ts_close) break;    // scan stop
        $c_idx ++;
        if ($ts < $ts_open) continue; // scan forward

        if (false === $cdata) {
            $cdata = [$src['open'], $src['close'], $src['low'], $src['high']];             // new candle start: OCLH
            continue;
        }      
        
        $cdata [1] = $src['close'];            
        $cdata [2] = min($cdata[2], $src['low']);
        $cdata [3] = max($cdata[3], $src['high']);

        $min_v = min($cdata[2], $min_v);
        $max_v = max($cdata[3], $max_v);
      }

      if (false !== $cdata) {
          foreach ($cdata as $v)
              $bc_data []= $v;
          $xdata []= $t_open;
      }

      // scanning for batches
      $buy_p = 0;
      $buy_v = 0;
      $sell_p = 0;
      $sell_v = 0;
      log_msg("#SIG_SCAN: time range [$ts_open .. $ts_close], batches from $b_idx/$s_max");

      while ($b_idx <= $s_max) {
          $src = $batch_list[$b_idx]; // used forward source
          if (is_array($src['ts'])) {
            log_msg("#WARN: batches[$b_idx] = ".print_r($src, true));
            continue;
          }

          $ts = $src['ts'];  
          $tt = strtotime($ts);
          if ($tt >= $t_close) break;    // scan stop
          $b_idx ++;

          if ($tt < $t_open) {
            log_msg(sprintf("#FWD_SS: #%d ts = '%s', open = '%s' ", $b_idx - 1, $ts, $ts_open));
            continue; // scan forward
          }

          if ($src['start_pos'] < $src['target_pos']) { // buy side
            $buy_p += $src['exec_price'] * $src['exec_qty'];
            $buy_v += $src['exec_qty'];
          } else {
            $sell_p += $src['exec_price'] * $src['exec_qty'];
            $sell_v += $src['exec_qty'];
          }
      } // while batches scan

      if ($buy_v > 0) {
          $pv_data []=  sprintf("b%d = $buy_p", count($bs_data));
          $bs_data []= $buy_p / $buy_v; // average buy
          $ba_data []= 90; // row up
          $xdata_bs []= $t_open;
      }
      if ($sell_v > 0) {
        $pv_data []=  sprintf("s%d = $sell_p", count($ss_data));
        $ss_data []= $sell_p / $sell_v; // average buy
        $sa_data []= 270; // row down
        $xdata_ss []= $t_open;
      }

      $t_open = $t_close;
      $ts_open = $ts_close;
    } // while candles scan

    file_put_contents("$exch/data/{$ticker}_cdata.txt", print_r($bc_data, true));

    $msg = sprintf("#DBG: candles to draw %d, min_v = %.4f, max_v = %.4f, buy_sig = %d, sell_sig = %d ", 
                  count($bc_data) / 4, $min_v, $max_v, count($bs_data), count($ss_data));

    function log_timeline($name, $var) {
      $tl = []; 
      foreach ($var as $t) 
        $tl []= date('Y-m-d H:i', $t);
      log_msg("$name ". json_encode($tl));
    }

    log_timeline('xdata_all ', $xdata);
    log_timeline('xdata_bs  ', $xdata_bs);  
    log_msg($msg);
    if ($s_max <= 3)
        log_msg("#BATCHES: ".json_encode($batches).", aggregated: ".json_encode($pv_data));
    // Create the new graph
    $graph = new Graph($width, $height);
    

    // Slightly larger than normal margins at the bottom to have room for
    // the x-axis labels
    $graph->SetMargin(50, 40, 80, 90);
    $graph->SetScale('datlin', $min_v, $max_v);
    // $graph->SetShadow($aShowShadow=true, $aShadowWidth=5, $aShadowColor=array(102,102,102));  
    $graph->title->Set("$period batch report for $exch / $ticker");

    $theme = new DarkTheme();
    $graph->SetTheme($theme);
    $graph->SetMarginColor('#505050');
    $graph->SetFrame(true,'khaki:0.6',1);
    // The automatic format string for dates can be overridden
    if ($graph->xaxis) {
        $graph->xaxis->SetLabelAngle(90);
        $graph->xaxis->scale->SetDateFormat('d M H:i');
        $graph->xaxis->SetColor('khaki', 'white');
        // Adjust the start/end to a specific alignment
        $graph->xaxis->scale->SetTimeAlign(MINADJ_10);
    } else
      log_msg("#ERROR: not present x-axis for graph object");

    $graph->yaxis->SetPos('max');
    $graph->yaxis->SetColor('khaki', 'white');
    $sp =  new StockPlot($bc_data, $xdata);
    $sp->HideEndLines(true);
    $sp->SetWidth(9);
    $sp->SetColor('yellow', 'white', 'red', 'darkred');
    $graph->Add($sp);

    if (count($bs_data) > 0) {
        $pack = [$bs_data, $xdata_bs, $ba_data];
        file_put_contents("$exch/data/{$ticker}_buysig.txt", json_encode($pack));
        $fp = new FieldPlot($bs_data, $xdata_bs, $ba_data);
        // Setup formatting callback
        $fp->SetCallback('SignalArrowCB');
        $fp->arrow->SetSize(2, 5);
        $fp->arrow->SetColor('#885588');
        $graph->Add($fp);
    }

    if (count($ss_data) > 0) {
        $pack = [$ss_data, $xdata_ss, $sa_data];
        file_put_contents("$exch/data/{$ticker}_sellsig.txt", json_encode($pack));
        $fp = new FieldPlot($ss_data, $xdata_ss, $sa_data);
        // Setup formatting callback
        $fp->SetCallback('SignalArrowCB');
        $fp->arrow->SetSize(2, 5);
        $fp->arrow->SetColor('#776677');
        $graph->Add($fp);
    }
    
    if ($offline_mode) 
      try {            
        $hour = date('H');
        $fname = __DIR__."/{$exch}/reports/batch_report_$ticker-$hour.png";    
        log_msg("#INFO: saving picture to file $fname");  
        if (file_exists($fname))
            unlink($fname);

        if (count($bs_data) + count($ss_data) == 0)   
            echo "#WARN: no batches plotted\n";

        $graph->Stroke(_IMG_HANDLER);
        $res = $graph->img->Stream($fname);          
        if (file_exists($fname))
          echo "#SUCCESS: $fname\n"; // say name to parent app    
        else 
          echo "#FAIL: file $fname not produced by stream, result = $res\n";

      } catch (Exception $E) {
        log_msg("#EXCEPTION: {$E->getMessage()}");
      }
      else {
        $graph->Stroke();
        log_msg("#SUCCESS: picture rendered for $exch / $ticker");
      }   
