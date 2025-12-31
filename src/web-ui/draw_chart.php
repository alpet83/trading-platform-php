<?php // content="text/plain; charset=utf-8"
    // Этот скрипт рендерит график баланса аккаунта на бирже, с учетом депозитов и выводов. Опционально можно выбирать валюту вывода из USD и BTC
    $path = __DIR__;
    chdir($path);
    $data_dir = "$path/data/";
    set_include_path(".:$path/lib:../lib:/usr/sbin/lib"); 
    
    $test = '/var/www/html/jpgraph/fonts/';
    if (file_exists($test))
      define('TTF_DIR', $test);  
    else
      define ("TTF_DIR","/usr/share/fonts/truetype/");

    const TTF_FONTFILE = "arial.ttf";
    
    ob_start();

    require_once '../vendor/autoload.php';

    // use mitoteam\jpgraph\MtJpGraph;
    // MtJpGraph::load(['date', 'bar', 'line'], true);
    require_once '../jpgraph/jpgraph.php';
    require_once '../jpgraph/jpgraph_line.php';
    require_once '../jpgraph/jpgraph_date.php';  
    

    require_once 'lib/common.php';      
    require_once 'lib/db_tools.php';
    require_once 'lib/db_config.php';
    require_once 'lib/esctext.php';
    require_once 'jpgraph-dark-theme.php';

    if (php_sapi_name() != 'cli')
        require_once 'lib/auth_check.php';

    ob_clean();
    $color_scheme = 'cli';

    $col = 1;
    $width = 1600;
    $height = 1280;
    $cname = 'equity';
    $exch = 'bitmex';
    $period = 'daily';
    $from_ts = '2017-01-01 00:00:00';
    $acc_id = 0;
    $accum_dep = 1;
    $uid = getmyuid();
    $fcolor = 'lightblue';
    $offline_mode = true;

    if(isset($argv[0])) {    
      log_cmsg("~C94 #DBG:~C00 Detected offline mode, working dir: ".getcwd());    
      if(isset($argv[1]))
        $exch = $argv[1];
      if(isset($argv[2]))
        $period = $argv[2];
      if (isset($argv[3]))  
        $acc_id = $argv[3];
      if (isset($argv[4]))
        $fcolor = $argv[4];
  
    }
    elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $acc_id = rqs_param('account_id', $acc_id);
        $exch = rqs_param('exchange', $exch);
        $width = rqs_param('width', $width);
        $height = rqs_param('height', $height);
        $period = rqs_param('period', $period);
        $from_ts = rqs_param('from_ts', $from_ts);
        $accum_dep = rqs_param('accum_dep', $accum_dep);
        $col = rqs_param('table_col', $col);
        $fcolor = rqs_param('fill_color', $fcolor);
        $offline_mode = false;       
    } else
        die("#FATAL: invalid environment!");  

    $www = $_SERVER['DOCUMENT_ROOT'];   
    $root = $offline_mode ? __DIR__.'/log' : "$www/bot/log"; 
    $log_name = sprintf("draw_chart__%s@%d-u%d.log", $exch, $acc_id, $uid);  
    $log_file = fopen("$root/$log_name", 'w');    
    if (!$log_file && !$offline_mode)
        die("#FATAL:<pre> can't open log file for writing {./log/$log_name}\n".print_r($_SERVER, true));
    $mysqli = init_remote_db('trading');
    
    if (!$mysqli)
      die("#FATAL: cannot connect to DB!\n");

    $limit = 60 * 24;

    $start_from = time() - 86400;

    $weekly = strpos($period, 'weekly') !== false;
    $filter = '';
    if ($weekly) {
        // SELECT * FROM `bitfinex__funds_history` WHERE ts >= '2024-06-01 00:00:00';
        // $filter = 'GROUP BY DATE_FORMAT(ts, "%Y-%m-%d %H:00:00")'; // 
        $start_from = time() - 86400 * 365 * 4; // full cycle
        $start_from = min($start_from, strtotime($from_ts));
        $from_ts = date('Y-m-d H:00:00', $start_from);
    }    
    elseif ($period == 'daily') {
        $start_from = time() - 86400 * 2; // 2 days
        $start_from = max($start_from, strtotime($from_ts));
        $from_ts = date('Y-m-d H:i', $start_from);
    }   
  

    $configs = [];
    $configs['equity'] = ['tag' => 0, 'min' => 1000000, 'max' => -1000000, 'title' => "$exch @$acc_id equity from $from_ts "];


    if (!isset($configs[$cname]))
        die("#FATAL: not exists configuration named [$cname]\n");

    $cfg = $configs[$cname];


    $table = strtolower("{$exch}__funds_history"); 
    
    $strict = "(ts >= '$from_ts') AND (account_id = $acc_id) $filter ORDER BY `ts` DESC";
    
    $data = [];
    $xdata = [];

    $t = time();


    $rows = [];
    try {
        $res = $mysqli->select_from('ts, value, value_btc', $table, "WHERE  $strict"); // <<<<<<<<<<<<<<<<<<<<<<<<<< SELECT FROM
        while ($res && $row = $res->fetch_array(MYSQLI_NUM)) {
          $rows []= $row;
        }
    } catch (Exception $e) {
      log_msg("#EXCEPION:   %s", $e->getMessage());
    }

    if (0 == count($rows)) {   
        log_msg("#ERROR: MySQL returned nothing: error = {$mysqli->error}, using query [$query] result = ".var_export($res, true));         
        log_msg("#FATAL: nothing was returned from table $table, limited by [$strict] \n");
        $mysqli->close();  
        if (!$offline_mode) {
            header("Content-type: image/png");
            echo file_get_contents('draw_nothing.png');
        }  
        die();
    }

    

    
    $coef = 1;
    // switch to coins chart
    if (!isset($_GET['table_col']) || 2 == $_GET['table_col'])
        if (stristr($table, 'bitmex') !== false || stristr($table, 'deribit') !== false || 2 == $col) {
            $col = 2;
            $coef = 1000;
            $cfg['title'] .= 'in mBTC';
        }

    $lrow = $rows[0]; // last 
    $rows = array_reverse($rows); // sorting ascending now
    $rcnt = count($rows);
    log_msg("#PERF: loaded %d rows, start_ts = %s, end_ts = %s, restrict = %s", $rcnt, $rows[0][0], $lrow[0], $from_ts);   

    $table_dep = strtolower($exch.'__deposit_history');
    $res = $mysqli->select_rows('ts,withdrawal,value_usd,value_btc', $table_dep, "WHERE (account_id = $acc_id) ORDER BY `ts`"); // deposits and withdrawals
    $accum_usd = 0;
    $accum_btc = 0;
    $idx = 0;
    $applied = 0;
    


    if (!$res)
        $res = []; // void init
    
    $show_accum = rqs_param('show_accum', false);    
    /*   Для применения данных о депозитах и выводах, требуется просканировать все балансовые точки, 
      накопив при пересечении времени сальдо суммы депозитов и выводов. В типичном случае дневного отчета, все накопление
      получится как-бы с левой стороны от графика, и будет применяться уже ко всем балансовым точкам
    */    
    $combine = rqs_param('combine', 1);
    
    $btc_prices = [];
    $src_table = strtolower("$exch.candles_btcusd");
    if ($mysqli->table_exists($src_table)) {
        // select_map тут срабатывает плохо из-за оценки в 3 колонки результата (две запятые), выдает массив строк, а не значений
        $btc_prices = $mysqli->select_map("DATE_FORMAT(ts, '%Y-%m-%d %H') as rts, close", $src_table, "WHERE (ts >= '$from_ts') AND (MINUTE(ts) = 0) ORDER BY `ts`", MYSQLI_NUM);
    }
    else {
        $src_table = strtolower("$exch.ticker_history"); // эта таблица обычно заполняется с интервалом 1 - 2 раза в минуту, поэтому много данных не должно быть
        if ($mysqli->table_exists($src_table))
            $btc_prices = $mysqli->select_map("DATE_FORMAT(ts, '%Y-%m-%d %H') as rts,last", $src_table, "WHERE (pair_id = 1) AND (ts >= '$from_ts') AND (MINUTE(ts) = 0) ORDER BY `ts`", MYSQLI_NUM);
    }  

    $btc_price = 80000; // default price
    if (0 == count($btc_prices)) 
        log_cmsg("~C91#WARN:~C00 no BTC prices for $src_table, using default price %d", $btc_price);        
    else {
        $start = array_key_first($btc_prices);
        $btc_price = $btc_prices[$start][0];
        log_cmsg("~C93#DUMP_BTC:~C00 BTC prices from $src_table, first %.2f", $btc_price);    
    }
    
    $mysqli->close();
    $mysqli = null;   
    

    $res []= [date(SQL_TIMESTAMP), 0, 0, 0];  // nope deposit/withdraw - ending
    if ($accum_dep)
        foreach ($res as $row) {  // сканирование записей о депозитах
            $ts = $row[0];                            

            // пока даты состояния депо меньше даты депозита/вывода... применить аккумулированные суммы
            while ($idx < $rcnt && $rows[$idx][0] <= $ts) {      
                $tp = $rows[$idx][0]; 
                $rts = substr($tp, 0, 13); // 2024-01-01 00 - округление до часов
                $actual = $btc_prices[$rts] ?? [0];          
                $actual = $actual[0];
                if (is_numeric($actual))
                    $btc_price = $actual > 0 ? $actual : $btc_price; // last btc price          
                $usd_coef = $combine * ($btc_price > 0 ? 1 / $btc_price : 0);
                $btc_coef = $combine * $btc_price;

                $rows[$idx][1] -= $accum_usd + $accum_btc * $btc_coef;
                $rows[$idx][2] -= $accum_btc + $accum_usd * $usd_coef;
                
                if ($show_accum)
                    log_cmsg("[%s] point[%s] <= [%s] applied ~C95$%.2f USD %.5f BTC @ ~C95$%.2f, results ~C95$%.2f %.5f", $rts, $tp, $ts, $accum_usd, $accum_btc, $btc_price, $rows[$idx][1], $rows[$idx][2]);
                $applied ++;
                $idx ++;
            }        
            // приращение аккумуляции, из результата надо вычитать(!) все заводы, и прибавлять выводы с биржи
            $sign = $row [1] ? -1 : +1; // withdrawal or deposit
            $usd = $row [2] * $sign;
            $btc = $row [3] * $sign;
            // log_msg("#D/W: ts[$ts] $usd USD, $btc BTC");
            $accum_usd += $usd;
            $accum_btc += $btc;          
        }

    
    log_msg("#DBG: applied deposit data for $applied / $rcnt points "); 
    log_msg("#DBG: saldo deposit/withdrawal BTC = %.3f, USD = %.2f", $accum_btc, $accum_usd);

    $start_funds = $rows[0][$col];  // before averaging

    if ($weekly) {
      $avg_data = [];
      $avg = $rows[0][$col];
      foreach ($rows as $row) {
        $t = $row[0];
        $val = floatval($row[$col]);      
        $avg = $avg * 0.95 + $val * 0.05; // EMA average
        if (strpos($t, '11:0') !== false) {
          $row[$col] = $avg;  // replace value
          $avg_data[]= $row;
        }
      }    
      $rows = $avg_data;
      $fname = sprintf('%s@%d-u%d_chart_data.csv', $exch, $acc_id, $uid);
      file_put_contents($data_dir.$fname, print_r($rows, true));
    }

    $val = 0;
    

    foreach ($rows as $row) {
      $t = strtotime($row[0]);
      $val = $row[$col];
      // if ($val <= 0.001) continue;
      $xdata []= $t;
      $data  []= $val;    
      $cfg['max'] = max($cfg['max'], $val); // для редких экстремальных значений        
      $cfg['min'] = min($cfg['min'], $val); // для редких экстремальных значений
    }
    
    $gain = 0;  
    $end_funds = $val;
    
    if (abs($start_funds) > 0) {
        $gain = ($end_funds - $start_funds) / $start_funds * 100;        
    }
    elseif ($end_funds > 0)
        $gain = 100;
    if ($start_funds > $end_funds)
        $gain = -abs($gain);

    log_msg("#GAIN: deposit change for period from %5f to %5f, gain = %.1f%%", $start_funds, $end_funds, $gain);    

    $val *= $coef;


    $dta = abs($cfg['max'] - $cfg['min']);  
    $cfg['max'] += $dta * 0.05; // -10 = -9.5
    $cfg['min'] -= $dta * 0.05; // -20 = -20.5

    $count = count($rows);

    if (0 == $count) {
        log_msg("#ERROR: nothing to draw, after filtering %d rows", $count);
        if (!$offline_mode) {
            header("Content-type: image/png");
            echo file_get_contents('draw_nothing.png');
        }           
        die();
    }
    $pack = array('labels' => $xdata, 'values' => $data);
    $fname = sprintf('%s@%d-u%d_chart_data.json', $exch, $acc_id, $uid);
    file_save_json($data_dir.$fname, array('chart' => $pack, 'accum_btc' => $accum_btc, 'accum_usd' => $accum_usd, 'config' => $cfg));

    $tsd = date(' Y-m-d', $t);

    

    error_reporting(E_ERROR | E_WARNING | E_PARSE | E_STRICT); 
    // Create the new graph
    $graph = new Graph($width, $height);
    // $graph->SetUserFont('arial.ttf');
    // $graph->title->SetFont(FF_USERFONT,FS_NORMAL,12);
    // Slightly larger than normal margins at the bottom to have room for
    // the x-axis labels
    $graph->SetMargin(50, 40, 30, 90);

    
    
    // Fix the Y-scale to go between [0,100] and use date for the x-axis
    $graph->SetScale('datlin', $cfg['min'], $cfg['max']);
    $title = $cfg['title']." to $tsd";
    $graph->title->Set(sprintf("$title, last = %.0f, gain = %.1f%%", $val, $gain));
    $graph->title->SetFont(FF_ARIAL,FS_BOLD, 13);

    $theme = new DarkTheme();
    $graph->SetTheme($theme);
    $graph->SetMarginColor('#505050');
    $graph->SetFrame(true,'khaki:0.6',1);


    // Set the angle for the labels to 90 degrees
    $graph->xaxis->SetLabelAngle(90);

    
    $graph->xaxis->SetFont (FF_ARIAL, FS_NORMAL, 8);
    $graph->xaxis->scale->SetTimeAlign(DAYADJ_1);
    $graph->xaxis->SetColor('khaki', 'white');
    $graph->xaxis->SetPos("min");

    if ($weekly) {
        $graph->xaxis->scale->SetDateFormat('d-m-Y');
        $graph->xaxis->scale->SetTimeAlign(MONTHADJ_1);
    }  
    else {
        $graph->xaxis->scale->SetDateFormat('d M H');       
        $graph->xaxis->scale->SetAutoTicks();
    }   

    // Adjust the start/end to a specific alignment
    $graph->yaxis->SetFont (FF_ARIAL, FS_NORMAL, 10);  
    
    $graph->yaxis->SetPos('max');

    $graph->yaxis->SetColor('khaki', 'white');

    $line = new LinePlot($data, $xdata);
    if ($cfg['max'] < 0)
        $line->SetFillFromYMin();
    if ($count > 10000)
        $line->SetFastStroke();

    // $line->SetLegend('Year 202x');
    if ($weekly)
        $line->SetFillColor('lightgreen@0.5');
    else {     
        if ($count > 2000)
            $line->SetFillColor( $fcolor.'@0.5');
        else
            $line->SetFillGradient($fcolor.'@0.5', 'black');
    }    
    log_msg("#DBG: using fill color %s", $fcolor);
    try { 
        $graph->Add($line);
        $id = $acc_id > 0 ? $exch.'@'.$acc_id : $exch.'@'.getmypid();
        if ($offline_mode) {
            $d = date('w');
            $fname = $data_dir.$id."_chart_$d.png";    
            $graph->Stroke(_IMG_HANDLER);
            $graph->img->Stream($fname);
            echo "#SUCCESS: $fname\n"; // say name to parent app    
        }
        else {    
            $graph->Stroke();    
            log_msg("complete with params: width = $width, height = $height, exchange = $exch, period = $period");
            log_msg(sprintf(" points count = %d ", count($data)));            
        }   
    } catch (Exception $e) {
        $msg = sprintf("#EXCEPTION: %s, stack:\n %s", $e->getMessage(), $e->getTraceAsString());
        log_msg($msg);
    }
    ob_flush();

