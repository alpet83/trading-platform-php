<?php
  include_once('lib/common.php');  
  include_once('lib/db_tools.php'); 
   
  mysqli_report(MYSQLI_REPORT_OFF);  
  include_once('/usr/local/etc/php/db_config.php');
  $db_user = 'trader';  
  $mysqli = init_remote_db('trading');

  if (!$mysqli)
    die("#FATAL: no access to local DB @ $db_alt_server\n");
    
    
  $field = rqs_param('field', 'symbol');  
    
    
  $res = $mysqli->select_from("id,$field,contract_ratio", 'pairs_map');

  $map = array();
  while ($res && $row = $res->fetch_array(MYSQLI_NUM))
   if (count($row) > 1 && strlen($row[1]) > 2) {
     $id = $row[0]; 
     $map [$id]= array($row[1], $row[2]);
   }    

  echo json_encode($map);
    
  $mysqli->close();  
?>