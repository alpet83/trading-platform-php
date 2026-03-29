<?php
    require_once('lib/common.php');
    $IP = $_SERVER['REMOTE_ADDR'] ?? 'localhost';

    $sync_host_url = (string)(getenv('TRADEBOT_PHP_HOST') ?: getenv('SIGNALS_API_URL') ?: 'http://127.0.0.1');
    $sync_host = parse_url($sync_host_url, PHP_URL_HOST);
    if (!is_string($sync_host) || '' === trim($sync_host))
        $sync_host = '127.0.0.1';
    $allowed_ip = gethostbyname($sync_host);

    if ($IP != $allowed_ip)
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