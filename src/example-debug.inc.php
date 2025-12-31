<?php
  $params =  ['reverse' => 'true', 'count' => 256];
  $params['startTime'] = gmdate(SQL_TIMESTAMP, time() - 3600 * 24 * 7); // week ago
  // $params['endTime'] = gmdate(SQL_TIMESTAMP, time() );

  $flt = new stdClass();
  $flt->ordStatus = ['New', 'PartiallyFilled']; 
  
  $params['filter'] = json_encode($flt);
  $json = $engine->RequestPrivateAPI('api/v1/order', $params, 'GET');
  $data = json_decode($json);
  if (is_array($data)) 
     $engine->LogMsg("~C04~C96#PERF/DEBUG:~C00 LoadOrders retrived %d open orders", count($data));
    

    
  