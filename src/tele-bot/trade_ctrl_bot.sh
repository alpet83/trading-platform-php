#!/bin/sh

cd /home/tele-bot
while true;
do
 kill `cat /var/run/trade_ctrl_bot.pid`
 php trade_ctrl_bot.php 
 sleep 5
done
