#!/bin/sh

php trading_core.php
php orders_lib.php
php impl_$1.php

SFX=`date +'%Y-%m-%d_%H%M'`
LDIR=$PWD/logs/$impl_name
if [ -d $LDIR ];
then 
 rm $LDIR/stdout.log
 rm $LDIR/stderr.log
else
  mkdir -p $LDIR
fi

STDOUT=$LDIR/stdout_$SFX
STDERR=$LDIR/stderr_$SFX
touch $STDOUT
touch $STDERR
ln -s $STDOUT $LDIR/stdout.log
ln -s $STDERR $LDIR/stderr.log

# ls -l bitmex/
echo "[$SFX] $PWD Trade script starting for $impl_name..." >> $STDOUT
php  -d xdebug.auto_trace=ON -d xdebug.trace_output_dir=logs/  bot_instance.php $impl_name >> $STDOUT 2> $STDERR
ERROR=$?
echo "return code "$ERROR
exit $ERROR



