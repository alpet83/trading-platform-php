#!/usr/bin/php
<?php
  $exch = false;
  $impl_name = false;
  while (date('i') >= 58) sleep(1);

  if (isset($argv[1]))
    $impl_name = strtolower($argv[1]);
  else 
    die("#FATAL: implementation not specified!\n");

  set_include_path(".:./lib:../lib:/usr/sbin/lib");
  require_once('common.php');
  require_once('esctext.php');
  

  $ds = date('d F Y');
  log_cmsg("~C95 --------- =========================================~C92 $ds~C95 ==================================================== ---------~C00");
  log_cmsg("~C93 #START:~C00 bot instance %s~C00...", $impl_name);

  error_reporting(E_ALL);
  // echo ("1.\n");
  require_once('lib/db_config.php');
  // echo ("2.\n");
  require_once('lib/db_tools.php');
  // echo ("3.\n"); 
  date_default_timezone_set("UTC"); // WARN: for better replication efficiency

  $m = [];
  preg_match('/^([a-z]+)\d*_bot$/', $impl_name, $m);
  if (count($m) > 1)
      $exch = $m[1];
  else
     die("#FATAL: implementation name $impl_name not recognized!\n");  

  $implementation = "impl_$exch.php";
  if (!file_exists($implementation))
    die("#FATAL: not exists $implementation\n");


  require_once($implementation);
  // echo ("4.\n");


  $hostname = file_get_contents('/etc/hostname');
  $hostname = trim($hostname);
  log_msg("#TRADE_BOT($hostname): trying connect to DB..."); 

  error_reporting(E_ERROR | E_WARNING | E_PARSE);
  mysqli_report(MYSQLI_REPORT_ERROR);  

  $mysqli = init_remote_db('trading');
  if (!$mysqli) {
    log_cmsg("~C91#FATAL:~C00 cannot initialze DB interface, last checked server %s from %s!\n", $db_alt_server, json_encode($db_servers));
    die(-15);
  }  

  $bot = null;

  set_error_handler(function($code, $string, $file, $line){
     throw new ErrorException("[main.error_handler] ". $string,  $code, E_ERROR, $file, $line);
  }, E_ERROR | E_PARSE | E_CORE_ERROR);

  register_shutdown_function(function(){
    $error = error_get_last();
    if(null !== $error)
    {
        echo "script ended...\n";
    }
    else 
        echo ("last_error on shutdown: ". var_export($error, true));
    debug_print_backtrace();
  });

  // $factory = array('binance' => BinanceBOT());
  try {
    $mysqli->try_query("SET time_zone='+0:00'");
    $data = getcwd().'/data';
    if (!file_exists($data)) 
        mkdir($data , 0770);
    if (isset($bot_impl_class) && !is_null($bot_impl_class) ) {
      var_dump($bot_impl_class);
      $bot = new $bot_impl_class();
    }  
    else  
      die(format_color("~C91#FATAL:~C00 not specified bot_impl_class \n"));

    if ($bot)
        $bot->Run();
    else
      echo "#FAIL: no exchange support for [$exch]\n";

    $bot = null;
  } catch(Exception $e) {
    echo "Exception catched in main: ".$e->getMessage()."\n";
    echo " ".$e->getTraceAsString()."\n";
    
  }
  $mysqli->close();

?>
