<?php
    include_once('lib/common.php');  
    include_once('lib/db_tools.php'); 
    
    mysqli_report(MYSQLI_REPORT_OFF);  
    include_once('/usr/local/etc/php/db_config.php');
    $db_user = 'trader';  
    $mysqli = init_remote_db('trading');

    if (!$mysqli)
        die("#FATAL: no access to local DB @ $db_alt_server\n");
      
    if (rqs_param('full_dump', false)) {
        $row = $mysqli->select_row('*', 'pairs_map', 'ORDER BY symbol', MYSQLI_ASSOC);
        if (!is_array($row)) 
            die("ERROR: no data in trading.pairs_map\n");        
        $fields = array_keys($row);
        $fields = implode(',', $fields);        
        $res = $mysqli->select_map($fields, 'pairs_map', 'WHERE id > 0 ORDER BY symbol', MYSQLI_ASSOC);
        if (is_array($res)) 
            echo json_encode($res);
        else  
            echo "ERROR: from DB return ".gettype($res);
        die('');
    }  
      
    $field = rqs_param('field', 'symbol');        
      
    $res = $mysqli->select_from("id,$field,contract_ratio", 'pairs_map', 'WHERE id > 0 ORDER BY symbol');

    $map = array();
    while ($res && $row = $res->fetch_array(MYSQLI_NUM))
    if (count($row) > 1 && strlen($row[1]) > 2) {
        $id = $row[0]; 
        $map [$id]= array($row[1], $row[2]);
    }    

    echo json_encode($map);
      
    $mysqli->close();  
?>