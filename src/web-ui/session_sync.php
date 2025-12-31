<?php
    require_once('lib/common.php');
    $IP = $_SERVER['REMOTE_ADDR'] ?? 'localhost';

    if ($IP != gethostbyname('vps.vpn'))
        die("ERROR: wrong addr $IP\n");

    $data = $_POST['session_data'] ?? null;
    if (!is_string($data))
        die("ERROR: parameter not set\n"); 

    if (session_status() == PHP_SESSION_NONE) session_start();    

    if (session_decode($data)) {
        print_r($_SERVER); 
        print_r($_SESSION);   
        session_write_close();
        die("OK: session restored \n");
    }     
    else
        die("ERROR: session not restored\n");    