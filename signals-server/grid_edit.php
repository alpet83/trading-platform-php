<?php
    include_once('lib/common.php');
    include_once('lib/db_tools.php');
    include_once('/usr/local/etc/php/db_config.php');

    if (!function_exists('detect_output_format')) {
      function detect_output_format(): string {
        $out = rqs_param('out', 'html');
        return ('json' === $out) ? 'json' : 'html';
      }
    }

    if (!function_exists('ensure_ticker_prices_map_table')) {
      function ensure_ticker_prices_map_table($mysqli): void {
        static $ready = false;
        if ($ready) {
          return;
        }
        $query = "CREATE TABLE IF NOT EXISTS `ticker_prices_map` (\n"
          . " `pair_id` INT NOT NULL,\n"
          . " `symbol` VARCHAR(32) NOT NULL,\n"
          . " `base_symbol` VARCHAR(16) NOT NULL,\n"
          . " `last_price` DOUBLE NOT NULL DEFAULT 0,\n"
          . " `source` VARCHAR(32) NOT NULL DEFAULT 'coingecko',\n"
          . " `ts_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
          . " PRIMARY KEY (`pair_id`),\n"
          . " KEY `idx_base_symbol` (`base_symbol`),\n"
          . " KEY `idx_ts_updated` (`ts_updated`)\n"
          . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $mysqli->try_query($query);
        $ready = true;
      }
    }

    if (!function_exists('normalize_pair_base_symbol')) {
      function normalize_pair_base_symbol(string $pair): string {
        $pair = strtoupper(trim($pair));
        foreach (['USDT', 'USD', 'PERP', 'USDC'] as $suffix) {
          if (str_ends_with($pair, $suffix) && strlen($pair) > strlen($suffix)) {
            return substr($pair, 0, -strlen($suffix));
          }
        }
        return $pair;
      }
    }

    if (!function_exists('resolve_coingecko_endpoint')) {
      function resolve_coingecko_endpoint(): string {
        $url = trim((string)(getenv('SIGNALS_PRICE_FEED_URL') ?: getenv('COINGECKO_PRICE_URL') ?: ''));
        if ($url !== '') {
          return $url;
        }
        return 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=250&page=1&sparkline=false';
      }
    }

    if (!function_exists('fetch_coingecko_price_map')) {
      function fetch_coingecko_price_map(): array {
        $endpoint = resolve_coingecko_endpoint();
        $json = curl_http_request($endpoint);
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
          return [];
        }

        $map = [];
        foreach ($rows as $row) {
          if (!is_array($row)) continue;
          $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
          $price = floatval($row['current_price'] ?? 0);
          if ($sym === '' || $price <= 0) continue;
          $map[$sym] = $price;
        }
        return $map;
      }
    }

    if (!function_exists('maybe_refresh_ticker_prices_map')) {
      function maybe_refresh_ticker_prices_map($mysqli, array $id_map, int $refresh_ttl = 180): void {
        static $refresh_checked = false;
        if ($refresh_checked) {
          return;
        }
        $refresh_checked = true;

        ensure_ticker_prices_map_table($mysqli);

        $lock_file = '/tmp/ticker_prices_map_refresh.ts';
        if (is_file($lock_file)) {
          $last = intval(@file_get_contents($lock_file));
          if ($last > 0 && (time() - $last) < $refresh_ttl) {
            return;
          }
        }

        $price_by_symbol = fetch_coingecko_price_map();
        if (count($price_by_symbol) === 0) {
          return;
        }

        $ts_now = date(SQL_TIMESTAMP);
        foreach ($id_map as $pair_id => $pair) {
          $pair_id = intval($pair_id);
          if ($pair_id <= 0) continue;

          $pair = strtoupper((string)$pair);
          $base_symbol = normalize_pair_base_symbol($pair);
          if (!isset($price_by_symbol[$base_symbol])) continue;

          $price = floatval($price_by_symbol[$base_symbol]);
          if ($price <= 0) continue;

          $pair_sql = "'".$mysqli->real_escape_string($pair)."'";
          $base_sql = "'".$mysqli->real_escape_string($base_symbol)."'";
          $query = "INSERT INTO `ticker_prices_map` (`pair_id`, `symbol`, `base_symbol`, `last_price`, `source`, `ts_updated`) VALUES "
            . "($pair_id, $pair_sql, $base_sql, $price, 'coingecko', '$ts_now') "
            . "ON DUPLICATE KEY UPDATE `symbol` = $pair_sql, `base_symbol` = $base_sql, `last_price` = $price, `source` = 'coingecko', `ts_updated` = '$ts_now'";
          $mysqli->try_query($query);
        }

        @file_put_contents($lock_file, strval(time()), LOCK_EX);
      }
    }

    if (!function_exists('load_cached_ticker_prices')) {
      function load_cached_ticker_prices($mysqli, array $id_map, int $max_age = 600): array {
        ensure_ticker_prices_map_table($mysqli);
        maybe_refresh_ticker_prices_map($mysqli, $id_map);

        $rows = $mysqli->select_rows('pair_id,last_price', 'ticker_prices_map', "WHERE ts_updated >= (UTC_TIMESTAMP() - INTERVAL $max_age SECOND)", MYSQLI_ASSOC);
        $prices = [];
        if (is_array($rows)) {
          foreach ($rows as $row) {
            $pair_id = intval($row['pair_id'] ?? 0);
            $price = floatval($row['last_price'] ?? 0);
            if ($pair_id > 0 && $price > 0) {
              $prices[$pair_id] = $price;
            }
          }
        }
        return $prices;
      }
    }

    if (!function_exists('resolve_grid_price')) {
      function resolve_grid_price(int $pair_id, string $pair, array $cached_prices, array $vwap_prices, array $cm_symbols, int $btc_pair_id, int $eth_pair_id): float {
        if (isset($cached_prices[$pair_id]) && $cached_prices[$pair_id] > 0) {
          return floatval($cached_prices[$pair_id]);
        }
        if (isset($vwap_prices[$pair_id]) && $vwap_prices[$pair_id] > 0) {
          return floatval($vwap_prices[$pair_id]);
        }
        if (isset($cm_symbols[$pair_id]) && isset($cm_symbols[$pair_id]->last_price)) {
          return floatval($cm_symbols[$pair_id]->last_price);
        }
        if ('ETHBTC' === $pair) {
          $eth = floatval($cached_prices[$eth_pair_id] ?? 0);
          $btc = floatval($cached_prices[$btc_pair_id] ?? 0);
          if ($eth > 0 && $btc > 0) {
            return $eth / $btc;
          }
          if (isset($cm_symbols[$btc_pair_id]) && isset($cm_symbols[$eth_pair_id])) {
            $eth = floatval($cm_symbols[$eth_pair_id]->last_price ?? 0);
            $btc = floatval($cm_symbols[$btc_pair_id]->last_price ?? 0);
            if ($eth > 0 && $btc > 0) {
              return $eth / $btc;
            }
          }
        }
        return 0;
      }
    }

    if (!function_exists('detect_grid_index_column')) {
      function detect_grid_index_column($mysqli, string $table_name = 'signals'): string {
        $schema = $mysqli->select_value('DATABASE()', '', '');
        if (!is_string($schema) || $schema === '') {
          $schema = 'trading';
        }
        $schema_sql = "'".$mysqli->real_escape_string($schema)."'";
        $table_sql = "'".$mysqli->real_escape_string($table_name)."'";
        $query = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = $schema_sql AND TABLE_NAME = $table_sql AND COLUMN_NAME = 'trade_no'";
        $count = intval($mysqli->select_value('COUNT(*)', 'INFORMATION_SCHEMA.COLUMNS', "WHERE TABLE_SCHEMA = $schema_sql AND TABLE_NAME = $table_sql AND COLUMN_NAME = 'trade_no'"));
        if ($count > 0) {
          return 'trade_no';
        }
        return 'signal_no';
      }
    }
    //  include_once('lib/db_config.php');
    // [11/19/2023 10:02:41 AM] [BTCUSDT]: Approximate to BUY.X1.22  Series name: LONG
    // [11/19/2023 10:02:41 AM] [BTCUSDT]: Approximate to SELL.22  Series name: SHORT

    // [11/22/2023 11:13:00 GMT+3] [DOTUSDT]:  BUY.X10#2
    // [11/19/2023 10:02.41 AM GMT+3] [BTCUSDT]: SELL#23
    // [now] [ARBUSD]: BUY.X100#2
    // [now] [BTCUSDT]: DEL#23
    define('GRID_LOG', __DIR__.'/logs/grid.log');

    define('SIG_FLAG_TP', 0x001);
    define('SIG_FLAG_SL', 0x002);
    define('SIG_FLAG_LP', 0x004);
    define('SIG_FLAG_GRID', 0x100);
    $req_flags = SIG_FLAG_GRID;

    $output_format = detect_output_format();
    $script = $_SERVER['SCRIPT_NAME'] ?? __FILE__;
    $setup = 0;
    if (preg_match('/(\d+)/', $script, $m) && count($m) > 1)
      $setup = intval($m[1]);

    if (php_sapi_name() != 'cli') {
      if ($output_format === 'json') {
          require_once('api_helper.php');
          $user_rights = get_user_rights();
      } else {
          require_once('lib/auth_check.php');
      }
    }

    if (!str_in($user_rights, 'view')) {
      if ($output_format === 'json')
          send_error("User has no rights to view/edit grid", 403);
      else
          error_exit("ERROR: user has no rights to view/edit signals\n");
      exit;
    }

    if ($output_format === 'json' && !str_in($user_rights, 'admin')) {
      $allowed_min = $user_base_setup ?? 0;
      $allowed_max = $allowed_min + 9;
      if ($setup < $allowed_min || $setup > $allowed_max) {
          send_error("Setup $setup out of allowed range [$allowed_min..$allowed_max]", 403);
          exit;
      }
    }

?>
<?php if ($output_format !== 'json'): ?>
<!DOCTYPE html>
<?php endif; ?>
<?php
    $minute = date('i') * 1;

    $input = rqs_param('signal', false);
    $sfx = $input ? 'edit' : 'view';
    $sfx .=  "-$setup"; 
    $color_scheme = 'cli';
    $log_file = fopen(__DIR__."/logs/grid_$sfx.log", $input ? 'a' : 'w');
    if (!$log_file || php_sapi_name() == 'cli')     
      $log_file = fopen("/tmp/grid_$sfx.log", 'w');

    mysqli_report(MYSQLI_REPORT_OFF);
    $tstart = pr_time();
    log_msg("#START: connecting to DB...");
    $mysqli = init_remote_db('trading');
    $table_name = 'signals';
    
    if (!$mysqli)
     die("FATAL: user '$db_user' can't connect to servers ".json_encode($db_servers)."\n");
    
    $pairs_map = $mysqli->select_map('symbol,id', 'pairs_map');
    $id_map    = $mysqli->select_map('id,symbol', 'pairs_map');
    $grid_no_col = detect_grid_index_column($mysqli, $table_name);
    $cached_prices = load_cached_ticker_prices($mysqli, $id_map);
    $colors = [1 => '#7FFF00', 2 => 'Gold', 3 => '', 66 => '#DABADA'];


       
    $touched = false;
    
    $init_t = pr_time() - $tstart;

    log_msg(sprintf("#PERF: %.2f sec, pairs_map:\n %s", $init_t, json_encode($pairs_map)));
    
    $btc_pair_id = $pairs_map['BTCUSD'];
    $eth_pair_id = $pairs_map['ETHUSD'];

    
    $filter = rqs_param('filter', false);  
    $action = rqs_param('action', '');

    $source = $_SERVER['REMOTE_ADDR'];
    $signal = false;     

    mysqli_report(MYSQLI_REPORT_OFF);
    include_once('load_sym.inc.php');  // request symbols from remote servers and map its data
    log_msg("#PERF: loaded cm_symbols = ".count($cm_symbols));

    $filter_id = 0;
    if ($filter) {
     if (isset($pairs_map[$filter]))
       $filter_id = intval($pairs_map[$filter]);
     else  
       die("<pre>#ERROR: symbol $filter not found in ".print_r($pairs_map, true));

     $color = rqs_param("color", '-'); 
     $lc = strlen($color);
     if ($lc >= 3 && $filter_id > 0) { 
       $mysqli->try_query("UPDATE `pairs_map` SET color = '#$color' WHERE id = $filter_id");
       die("For $filter #$filter_id updated color = $color, result: {$mysqli->affected_rows} ");
     }
     else 
       echo "<!-- $color = $lc, $filter =  $filter_id -->\n";     
    }   

    $colors    = $mysqli->select_map('id,color', 'pairs_map');

    $delete = rqs_param('delete', false);
    if ($delete) {        
     $delete = intval($delete);
     $mysqli->try_query("DELETE FROM $table_name WHERE (id = $delete) AND (setup = $setup);");
    }
    
    $qview = 'quick' == rqs_param('view', 'html');

    if (!$qview) echo "<!-- \n";
    $price = rqs_param('price', 0) * 1.0;
    $amount = rqs_param('amount', 1) * 1;

    function sig_param_set(string $param, string $field, float $value, int $flag) {
    global $mysqli, $table_name, $strict, $touched;
    $id = rqs_param($param, -1);
    if ($id <= 0) return;
    $touched = $mysqli->select_value('pair_id', $table_name, "WHERE (id = $id) AND $strict");        
    $query = "UPDATE `$table_name` SET $field = $value, flags = (flags | $flag) WHERE (id = $id) AND $strict;";
    if ($mysqli->try_query($query) && $mysqli->affected_rows)
      echo "#OK: $field = $value for #$touched\n";
    else 
      echo "#FAIL: [ $query ] result {$mysqli->error};";       
    }

    $singal = false;
    $id_added = 0;  
    $strict = "(setup = $setup)";  
    sig_param_set('sig_id', 'mult', $amount, 0);       
    sig_param_set('edit_qty', 'qty',     rqs_param('qty', 2),  0);
    sig_param_set('edit_tp', 'take_profit', $price, 0);       
    sig_param_set('edit_sl', 'stop_loss', $price, 0);    
    // sig_param_set('edit_lp', 'limit_price', $price, SIG_FLAG_LP);  

    function sig_toggle_flag(string $param, int $flag) {
    global $mysqli, $table_name, $strict;
    $id = rqs_param($param, -1) * 1; 
    if ($id > 0) 
       $mysqli->try_query("UPDATE $table_name SET flags = flags ^ $flag WHERE (id = $id) AND $strict; ");  
    }

    log_cmsg("#REQUEST: dump: %s\n", print_r($_REQUEST, true)); 

    while ($input)
    try {
     
     $m = [];
     $enter = false;     
     $input = str_replace('[now]', '['.date('r').']', $input);
     log_cmsg("#DBG: --------------------  processing %s -------------------- ", $input);

     if (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|SHORT).X([\d\.]*)#(\d*)/', $input, $m) && count($m) >= 6)  {
        $enter = true;
     } elseif (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|SHORT).X(\d).(\d*).*(LONG|SHORT)/', $input, $m) && count($m) > 6)  {
        $enter = true;
     } elseif (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|DEL)#(\d*)/', $input, $m) && count($m) >= 5) {

     } elseif (false !== preg_match('/\[(.*)\]\s\[(\S*)\]:.*(BUY|SELL|DEL).(\d*).*(LONG|SHORT)/', $input, $m) && count($m) > 5) {   

     }
     else  {
        echo "#FAIL: can't parse $input\n";
        log_msg("#FAIL: can't parse $input");
        break;
     }   

     
     print_r($m);
     $pair = $m[2];
     if (!isset($pairs_map[$pair])) {
        log_msg("#ERROR: not registered pair $pair\n");
        throw new Exception("Unknown pair $pair");
     }    

     $t = strtotime($m[1]);    
     $t = min(time(), $t);
     $ts = date(SQL_TIMESTAMP, $t);
     $pair_id = $pairs_map[$pair];

     $deleted = false;

     $shift = $enter ? 1 : 0;    
     $mult  = $enter ? $m[4] : 0;
     $trade_no = $m[4 + $shift];
     

     if (9999 == $trade_no)
       $trade_no = intval($mysqli->select_value($grid_no_col, $table_name, "WHERE (pair_id = $pair_id) AND (setup = $setup) AND (flags & $req_flags) ORDER BY $grid_no_col DESC")) + 1;

     $side = '';
     if (count($m) > $shift + 5)
        $side = $m[5 + $shift];

     $buy = ($enter && 'BUY' === $m[3]) ? 1 : 0;
     if ('DEL' == $m[3] && $trade_no > 0) {
        $query = "DELETE FROM $table_name WHERE (pair_id = $pair_id) AND ($grid_no_col = $trade_no) AND (setup = $setup) ";
        if ($mysqli->try_query($query)) {
          $ar = $mysqli->affected_rows;
          log_msg("#OK: signals #$trade_no for $pair removed from DB. Rows = $ar");
          file_add_contents(SIGNALS_LOG, tss()."#DELETE: $trade_no, affected rows $ar, source = $source\n");
          $deleted = $pair_id;
        }  
        else 
          echo "#ERROR: failed $query\n";
        break;
     }
    

     log_msg("#PERF: load prev signal for setup %d", $setup);
    $prev = $mysqli->select_row('ts, mult', 'signals', "WHERE (pair_id = $pair_id) AND (setup = $setup) AND ($grid_no_col = $trade_no) AND (buy = $buy)");
     $signal = tss().' + '.$m[0];
     
    $query = "INSERT INTO $table_name (ts, pair_id, setup, $grid_no_col, buy, mult, qty, flags, source_ip)\n VALUES ";
     $query .= "('$ts', $pair_id, $setup, $trade_no, $buy, $mult, 2, $req_flags, '$source')\n";
     $query .= "ON DUPLICATE KEY UPDATE ts='$ts', mult=$mult, flags=$req_flags, source_ip='$source';";
     log_cmsg("#QUERY: %s", $query);
     
     if ($mysqli->try_query($query)) {
        $touched = $pair;
        $ar = $mysqli->affected_rows;
        $id_added = $mysqli->select_value('id', 'signals', "WHERE (pair_id = $pair_id) AND ($grid_no_col = $trade_no) AND (setup = $setup)");

        if (false === $prev)  {
           echo "#OK: new record added.\n";
           file_add_contents(GRID_LOG, tss()."#ADD: [$query], affected rows $ar, source = $source\n");
        }   
        else {
           echo "#OK: record changed from previous ".json_encode($prev)."\n";
           file_add_contents(GRID_LOG, tss()."#MODIFY: [$query], affected rows $ar, source = $source\n");
        }   

        if ($id_added) {
          $last_price = floatval($cached_prices[$pair_id] ?? 0);
          if ($last_price <= 0 && isset($cm_symbols[$pair_id])) {
            $last_price = floatval($cm_symbols[$pair_id]->last_price ?? 0);
          }
          if ($last_price > 0) {
          $lo_bound = $last_price * 0.95;
          $hi_bound = $last_price * 1.05;
          $mysqli->try_query("UPDATE $table_name\n SET stop_loss = $lo_bound, take_profit = $hi_bound\n WHERE (id = $id_added) AND (setup = $setup);");   
          echo "#OK: assigned SL price = $last_price\n";
          }
        }  
     }   
     else 
        throw new Exception("Failed to add signal: ".$mysqli->error);
     break;    
    } catch (Exception $e) {
     echo "-->\n";
     die("#ERROR: ".$e->getMessage()." at <pre> ".$e->getTraceAsString());  
    }
    
    if ($qview) {
    if (!$input) {
       echo "#WARN: data param not specified\n";
       print_r($_REQUEST);
    }   
    ob_start();
    }  
    echo "-->\n";
?>
<html>
    <head>
    <title>Grid Editor <?php echo $setup; ?></title> 
    <style type='text/css'>
    td { padding-left: 4pt;
        padding-right: 4pt; } 
    </style>
    <script type='text/javascript'>
<?php
       $js_script = file_get_contents('sig_edit.js');
       $js_script = str_replace('script', $script, $js_script);
       echo $js_script;
?>

    function Startup() {      
<?php
        if ($touched) {
         if (isset($id_map[$touched]))
           $touched = $id_map[$touched];
         echo "document.location = '$script?filter=$touched';\n";
        }    
?>
    }
    </script>
    <body onLoad="Startup()">
<?php
    print "\t<form name='signals' method='POST' action='$script'>\n";
?>
    <input type='text' name='signal' id='signal' value='' placeholder="[NOW] [NOPUSD]: BUY.X1#1" style='width:590pt;'/> <input type='submit' value='Post'/>  
    </form>  
    <h4>Edit setup <?php echo $setup ?></h4>
    <table border=1 style='border-collapse:collapse;'>
    <tr><th>Time<th>#Sig<th>Side<th>Pair<th>Mult<th>Qty</th><th>Low<th>High<th>Last price<th>Action</tr> 
<?php
     $accum_map = [];
     $ts_map = [];
     $buys = [];  
     $shorts = [];
     echo "<!-- btc_id = $btc_pair_id, eth_id = $eth_pair_id -->\n";
     $vwap_prices = [];
     $vwap_fails = [];
     $vwap_cache = [];
     $cache_hits = 0;
     // initialize all positions as closed
     foreach ($id_map as $id => $sym)
       $accum_map[$id] = 0;

     define('VWAP_CACHE_FILE', '/tmp/vwap_cache.json');
     function file_age($fname) {      
       return time() - filemtime($fname) or 0;
     }

     $timeout = 120;
     if ('track' === $action) {       
       $timeout = 30;
     }  

     if (file_exists(VWAP_CACHE_FILE))
       $vwap_cache = file_load_json(VWAP_CACHE_FILE, null, true);
       
     function get_vwap(int $pair_id) {      
       global $id_map, $mysqli, $vwap_prices, $vwap_cache, $vwap_fails, $cache_hits, $timeout;
       if (!isset($id_map[$pair_id])) {
          echo "<!-- #ERROR: unknown pair_id #$pair_id -->\n";
          return;
       }   
       $elps = 3600;
       $pair = strtolower($id_map[$pair_id]);
       if (isset($vwap_cache[$pair_id]))
           unset($vwap_cache[$pair_id]);

       if (isset($vwap_cache[$pair])) {
         $elps = time() - $vwap_cache[$pair]['ts'];
         if ($elps <= $timeout) {
            $cache_hits ++;
            $vwap_prices[$pair_id] = $vwap_cache[$pair]['vwap'];
            return;
         }   
       }        
       if (isset($vwap_fails[$pair_id])) return;

       $vwap = curl_http_request("http://vm-office.vpn/bot/get_vwap.php?pair_id=$pair_id&limit=5");       
       if (false === strpos($vwap, '#') && floatval($vwap) > 0) {       
         $vwap_prices[$pair_id] = $vwap;
         $vwap_cache[$pair] = ['ts' => time(), 'vwap' => $vwap];
         printf("<!-- For %s vwap price loaded as %s, cache elps = $elps -->\n", $pair, $vwap);         
       }   
       else {
         printf("<!-- #WARN: For %s not loaded VWAP, response: $vwap -->\n", $pair);
         $vwap_fails[$pair_id] = 1;
       }   
     }

     $strict = sprintf("WHERE (setup = %d) AND (flags & %d) AND ", $setup, SIG_FLAG_GRID);
     if ($filter_id > 0)
       $strict .=  "(pair_id = $filter_id)";
    else
       $strict .= '(pair_id > 0)';

     log_msg("#PERF: loading grid signals...");
     function cmp_pair($a, $b) {
       global $id_map;
       $pid_a = $a['pair_id'];
       $pid_b = $b['pair_id'];
       if ($pid_a == $pid_b) 
         return intval($a['trade_no']) - intval($b['trade_no']);
       if (isset($id_map[$pid_a]) && isset($id_map[$pid_b]))          
         return $id_map[$pid_a] < $id_map[$pid_b] ? -1 : 1;         
       return $pid_a < $pid_b ? -1 : 1;
     }
     // main rows load <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<====================================
    $rows = $mysqli->select_rows("id, ts, $grid_no_col AS trade_no, buy, pair_id, mult, qty, take_profit, stop_loss, ttl, flags", 'signals', $strict." ORDER BY pair_id,$grid_no_col", MYSQLI_ASSOC);
     if (is_null($rows))  
        die("#ERROR: failed to fetch signals: {$mysqli->error}\n");
     usort($rows, 'cmp_pair');
     if ($filter && count($rows) < 10) {
       printf("<!-- sorted rows:\n%s -->\n", print_r($rows, true));
     }
     if (0 == count($rows)) 
        printf("<h4>No grid configs yet for setup %d</h4>\n", $setup);
     $sym_errs = 0;
     if (!$filter) {
       $rqst = pr_time();
       // precalculate VWAP price for each used pair_id
       foreach ($rows as $row) {      
         $pair_id = intval($row['pair_id']);
         if (isset($vwap_prices[$pair_id])) continue;      
         get_vwap($pair_id);
       }  
       $elps = pr_time() - $rqst;
       file_save_json(VWAP_CACHE_FILE, $vwap_cache);
       if ($cache_hits > 0)
         printf("<!-- load VWAP time = %.1f sec, cache hits $cache_hits -->\n", $elps);       
       else  
         printf("<!-- load VWAP time = %.1f sec, cache data:\n%s -->\n", $elps, print_r($vwap_cache, true));       
       $vwap_good = 0;
       foreach ($vwap_prices as $pair_id => $vwap)
         if ($vwap > 0)
             $vwap_good ++;
      if ($vwap_good < 10 && !$filter && count($rows) > 0) {
       print_r($vwap_prices);
       echo ("#WARN: VWAP calculated/loaded only for $vwap_good pairs");
      }
     }

     log_msg("#PERF: dumping signals");
     

     // processing format table
     foreach ($rows as $row) {
        list($id, $ts, $trade_no, $is_buy, $pair_id, $mult, $qty, $tp, $sl, $ttl, $flags) = array_values($row);
        $ts_map[$pair_id] = $ts;
        $pair = $id_map[$pair_id];
        $curr_ch = '$';
        if (strpos($pair, 'BTC', -3) !== false) 
           $curr_ch = '₿'; // ฿

        $is_buy = $is_buy > 0;
        $side = $is_buy ? 'BUY' : 'SELL';        
        $key   = "$pair_id:$trade_no";
          $price = resolve_grid_price($pair_id, $pair, $cached_prices, $vwap_prices, $cm_symbols, $btc_pair_id, $eth_pair_id);
          if ($price <= 0)
            $sym_errs ++;
                
        $coef = 1;
        $amount = round($mult * $coef);
        if ($is_buy) { // buying                       
          if (isset($shorts[$key])) { // this row closing exist SHORT signal 
            $accum_map [$pair_id] += $shorts[$key];
          } elseif ($amount > 0) { // not existed signal = typical
            $accum_map [$pair_id] += $amount;   
            $buys [$key] = $amount;
          }  
        }  
        else {  // selling
          if (isset($buys[$key]))  // this row closing exist LONG signal 
             $accum_map [$pair_id] -= $buys[$key];
           elseif ($amount > 0) { // not existed signal = typical 
             $accum_map [$pair_id] -= $amount;   
             $shorts [$key] = $amount;
             if ($accum_map [$pair_id] < 0)  $side = 'SHORT';
           } 
        }
        
        printf("<!-- coef = $coef, amount = $amount, TTL = $ttl  --> \n");
        
        $bg_color = 'white';
        if(isset($colors[$pair_id]))
           $bg_color = $colors[$pair_id];
        $style = '';
        if ($id == $id_added) 
          $style .= 'font-weight: bold;';
        $font_color = 'black';

        if (false !== strpos($bg_color, '#')) {
          $hx = substr($bg_color, 1);
          list($r, $g, $b) = sscanf($hx, '%2x%2x%2x');
          $lt = max ($r, max($g, $b));
          printf("<!-- COLOR from $hx: $r, $g, $b = $lt -->\n");
          if ($lt < 145)
            $font_color = 'white';
        }        
        $strict = "(pair_id = $pair_id) AND (setup = $setup) AND (flags & $req_flags)";
        $mno = $mysqli->select_value("MIN($grid_no_col)", $table_name, "WHERE $strict");
        $pair_cell = sprintf("<!-- min trade_no = %s -->", var_export($mno, true));
        $pair_text = sprintf("<a href='$script?filter=%s' style='color:$font_color'>%s</a>", $pair, $pair);

        if (is_numeric($mno) && $mno == $row['trade_no']) {
          $sigcnt = $mysqli->select_value('COUNT(id)', $table_name, "WHERE $strict");
          $pair_cell = sprintf("<td rowspan=%d>%s", $sigcnt, $pair_text);
        }  
        elseif(is_null($mno))
           $pair_cell = "<td>$pair";
        $t = strtotime($ts);
        $ts_rounded = date('Y-m-d H:i', $t);
        printf("\t<tr style='background-color: $bg_color;color: $font_color; $style'><td>%s<td>%d<td>%s", $ts_rounded, $row['trade_no'], $side);
        print $pair_cell;
        printf("\n\t\t<td><u onClick='EditAmount($id, $mult)'>%.1f</u>",  $mult);
        printf("\n\t\t<td><u onClick='EditQty($id, $qty)'>$curr_ch%s</u>", $qty);
        printf("\n\t\t<td><u onClick='EditSL($id, $sl)'>$curr_ch%s</u>\n", $sl);
        printf("\n\t\t<td><u onClick='EditTP($id, $tp)'>$curr_ch%s</u>", $tp);        
        $price_text = $curr_ch.$price;
        if ($price < 0.0001)
            $price_text = sprintf("$curr_ch%.3f/M", $price * 1e6);
        elseif ($price < 0.01)
            $price_text = sprintf("$curr_ch$%.3f/1K", $price * 1000);        
        echo "\n\t\t<td>$price_text\n\t\t<td><input type='submit' name='remove' onclick='DeleteSignal($id)' value='Delete'/>\n ";
     }
     echo "</table>\n";
     if ($sym_errs > 0) {
       echo "#ERROR: cm_symbols loaded incomplete, errors = $sym_errs:</br> \n";
       foreach ($cm_symbols as $pair_id => $rec)
         printf("$pair_id => %f, ", $rec->last_price);
       echo "</br>\n";
     }
     echo "<pre>n";
      
     $ts_now = date(SQL_TIMESTAMP);

     if ($qview) ob_end_clean();
     if ($delete && !isset($accum_map[$delete]))
         $accum_map[$delete] = 0;                
    
     if ($filter) 
        echo "<input type='submit'  onClick='GoHome()' value='Home' />";

     if ($qview) die('');     
     $work_t = pr_time() - $tstart;
     log_msg(sprintf("#END: work_t = %.1f sec, CWD: %s, LOG_FILE %s", $work_t, getcwd(), GRID_LOG));
     
?>
    
