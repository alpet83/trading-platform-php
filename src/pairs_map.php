<?php
  require 'lib/common.php';
  require_once 'lib/esctext.php';

  $exch = $argv[1];

  $field = "{$exch}_pair";
  $url = 'https://vps.alpet.me/pairs_map.php?field='.$field;

  while (true) {
    $json = curl_http_request($url);
    if (false === strpos($json, '#ERROR')) { 
      $pairs = json_decode($json, true);  
      if (!is_array($pairs)) continue;
      file_put_contents("data/pairs_map.json", json_encode($pairs, JSON_PRETTY_PRINT));
      break;
    }  
    sleep(1);
  }
