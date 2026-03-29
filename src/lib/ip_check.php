<?php
 function ip_check(string $msg = 'Not allowed for %IP', bool $die = true) { 
  if (!isset($_SERVER) || isset($_SERVER['REMOTE_ADDR'])) return true;
  $remote = $_SERVER['REMOTE_ADDR'];
  $allow_ip = array();
  
  $white_list = file(__DIR__.'/.allowed_ip.lst');
  foreach ($white_list as $ip) {
    $ip = trim($ip);
    $allow_ip [$ip] = 1;
  }     
  
  if ($remote && !isset($allow_ip[$remote])) {  
    http_response_code (403);
    print_r($allow_ip);
    $msg = str_replace('%IP', $remote, $msg);
    if ($die) 
      die($msg);
    return false;  
  }     
  return true;

 } // ip_check

