#!/bin/sh
# Change to the directory where trade_ctrl_bot.php is deployed
# Update this path to match your server layout, e.g. /var/www/signals-server
cd "$(dirname "$0")"
while true;
do
 kill `cat /var/run/trade_ctrl_bot.pid`
 php trade_ctrl_bot.php
 sleep 5
done