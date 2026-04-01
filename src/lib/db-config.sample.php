<?php
    $db_configs = [];
    $db_configs['trading'] = ['trading', 'replace_with_generated_password'];
    $db_configs['datafeed'] = $db_configs['trading'];
    foreach (['binance', 'bitmex', 'bitfinex', 'bybit', 'deribit'] as $db_name)
      $db_configs[$db_name] = $db_configs['datafeed'];

    // Docker-native service hostname for the MariaDB container.
    $db_servers = ['mariadb'];
    const MYSQL_REPLICA = 'mariadb';
    $db_alt_server = MYSQL_REPLICA;
    


