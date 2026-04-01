<?php

    $ip = $_SERVER['REMOTE_ADDR'];

    $white_list = array('46.199.235.248', '89.64.41.127');


    if (false === array_search($ip, $white_list) ) {
    printf("Привет %s, доступ ограничен потому что ты возможно нефор.\n", $ip);
    http_response_code(403);
    die(0);
    } 

    include_once('lib/common.php');
    include_once('lib/db_config.php');
    include_once('lib/db_tools.php');
    
    init_db('trading'); 

    if (!$mysqli)   
    die("#FATAL: can't connect to database\n");


    $format = rqs_param('format', 'json');

    // SELECT ts_current, target, `current`, `offset` FROM `bitfinex__positions` WHERE pair_id <= 3 ORDER BY `pair_id` ASC;

    $pairs = array('BTCUSD', 'XAUTUSD', 'ETHUSD');
    
    $rows = $mysqli->select_rows('pair_id, ts_current, target, `current`, `offset`', 'bitfinex__positions', 'WHERE pair_id <= 3', MYSQLI_ASSOC);
    if ($rows) {
    if ('json' == $format)
     echo json_encode($rows);
    elseif ('table' == $format)  {
     echo "<html>\n<head>\n";
     echo "<style>td { padding: 5pt; } </style>\n";
     echo "<body>\n";
     echo "<table border=1 style='border-collapse: collapse;'>\n";
     echo "<tr><th>Pair<th>Timestamp<th>Target<th>Current<th>Offset\n";
     foreach ($rows as $row) {
        $id = $row['pair_id'];
        printf("<tr><td>%s<td>%s<td>%.2f<td>%.2f<td>%.2f\n", $pairs[$id - 1], $row['ts_current'], $row['target'], $row['current'], $row['offset']);
     }
       
    }
    else 
     print_r($rows);
    }  
    else  
    echo "Oops! DB request failed. :( \n";

?>