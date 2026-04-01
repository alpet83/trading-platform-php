#!/usr/bin/php
<?php

$manager_script = '/app/datafeed/src/datafeed_manager.php';
if (!is_file($manager_script)) {
    fwrite(STDERR, "Missing required manager script: {$manager_script}\n");
    exit(1);
}

require_once $manager_script;
