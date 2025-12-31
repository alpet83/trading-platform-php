<?php

    $g_bot = null; // global instance of bot
    $g_logger = null; // alias for bot, TODO: need assume interface
    $g_queue = null;
    $gmt_tz = new DateTimeZone('GMT');

    define('POSITION_HALF_DELAY', 3600 * 24 * 20);
    define('REPORTABLE_ORDER_COST', 800); // USD
    define('NON_LIQUID_ORDER_COST', 1000); // for pairs with low liquidity in DOM
    define('ABNORMAL_HIGH_COST', 5000000); // TODO: move to config 

    define ('IGNORE_BUSY_TRADE', 0);
    define ('IGNORE_SMALL_BIAS', 1);
    define ('IGNORE_NO_INFO',    2);
    define ('IGNORE_SKIP_TRADE', 4);
    define ('IGNORE_EXPIRED',    5);

?>