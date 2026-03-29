#!/bin/sh
PID=`cat ./bitmex_bot.pid`
echo "Trying kill process $PID"
ps aux | grep php | grep bot
/bin/kill  $PID
sleep 5
/bin/kill --signal=9  $PID
ps aux | grep $PID | grep php
sleep 10
#tail -n 150 -f debug/stdout.log
