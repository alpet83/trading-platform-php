<?php

    require_once 'lib/common.php';
    require_once 'lib/table_render.php';
    require_once 'bot_globals.php';

    /**
     *  Трейт для генерации отчетов, обычно отправляемых в Telegram. Подключается к TradingCore.
    **/
    trait TradeReporting {
        protected  $reports_map = [];
        protected  $order_reports = [];  // every 10 minutes send: any minor orders accumulation

        protected  $report_color = [0, 0, 0];

        public function send_event(string $tag, string $msg, $value = 0) {
            global $g_queue;
            $rec = $g_queue->push_event($tag, $msg, $value);
            $rec->on_error = function ($rec) {
                $bot = active_bot();
                if ($bot) $bot->LogError("~C91 #SEND_EVENT_FAILED~C00(~C97{$rec->tag}~C00): ~C92 {$rec->event}~C00, result: {$rec->result}");
            };
        }

        public function send_media(string $tag, string $msg, string $filename, $flags = 0, bool $confirm = false) {
            global $g_queue;
            if (!file_exists($filename)) {
                active_bot()->LogError("~C91 #SEND_ATTACH_FAILED~C00: not exists $filename"); 
                return;
            }
            $rec = $g_queue->push_media($tag, $msg, $filename, $flags);

            if (!$confirm) return;

            $rec->on_success = function ($rec) {
                active_bot()->LogMsg("~C96#SEND_ATTACH_OK:~C00 file = %s", $rec->file);
                unlink($rec->file);
            };         

            $rec->on_error = function ($rec) {        
                $out = shell_exec("ping -c 3 vps.vpn");
                $extra = '';
                if (str_in($out, '100% loss')) 
                    $extra = ", ping:\n $out";        
                active_bot()->LogError("~C91 #SEND_IMAGE_FAILED~C00(~C97$rec->tag~C00): ~C92 {$rec->event}~C00, file ~C93 {$rec->file}~C00, result: {$rec->result} $extra");
            };
        
        } // member function send_image 

        public function NotifyOrderPosted($info, $context) {
            if (!isset($info->price)) {
                var_dump($info);
                throw new Exception("Invaid OrderInfo object used");
            }

            $engine = $this->Engine();
            $subdir = sprintf('/reports/%s@%d/', date('Y-m-d'), $engine->account_id);
            $cwd = getcwd();
            if (!file_exists($cwd.$subdir))  {
                $path = $cwd.$subdir;
                mkdir($path, 0777, true);
                $out = [];
                $cmd = "ln -s $path/index.php $cwd/index.php";
                $this->LogMsg(" trying execute [~C97$cmd~C90]");
                exec($cmd, $out);
            }

            $fname = $subdir . sprintf('%s_order_%d.png', $this->impl_name, $info->id);
            $size = 16;
            $row_h = 28;
            $width = 400;
            $height  = 5 * $row_h;
            $img = imagecreate($width, $height);
            if (!$img)
                throw new Exception("can't create image with size $width x $height");
            $tinfo = $engine->TickerInfo($info->pair_id);
            if (!$tinfo)
                throw new Exception("can't get TickerInfo for #{$info->pair_id}");

            $icon = false;
            $icon_file = $cwd."/icons/{$info->pair_id}.png";
            if (file_exists($icon_file))
                $icon = imagecreatefrompng($icon_file);

            // $bgc = imagecolorallocate($img, 0, 0, 40);
            imagecolorallocate($img, ...$this->report_color); // first color is back color
            $magenta = imagecolorallocate($img, 250, 91, 250);
            $yellow  = imagecolorallocate($img, 250, 255, 91);
            $green   = imagecolorallocate($img, 90,  255, 91);
            $red     = imagecolorallocate($img, 255,  91, 91);
            $white   = imagecolorallocate($img, 255, 255, 255);


            imagerectangle($img, 0, 0, $width - 1, $height - 1, $white);

            if ($icon)
                imagecopy($img, $icon, $width - 80, 30, 0, 0, 64, 64);

            $font = $cwd.'/arial.ttf';
            $side  = $info->buy ? 'buy' : 'sell';
            $color = $info->buy ? $green : $red;

            $xx = 30;
            $y  = 7;
            $yy = $row_h;
            $pts = [];
            if ($info->buy)
                $pts = array($xx, $yy, $xx + 10, $y,  $xx + 20, $yy);
            else
                $pts = array($xx, $y,  $xx + 10, $yy, $xx + 20, $y);

            // imagettftext($img, $size, 0,  15, $yy, $color,   $font, $side);
            imagefilledpolygon($img, $pts, count($pts) / 2, $color);
            imagettftext($img, $size, 0,  70, $yy, $magenta, $font, $info->amount);
            imagettftext($img, $size, 0, 190, $yy, $yellow,  $font, $info->pair);
            $yy = 1 * $row_h + 30;
            imagettftext($img, $size, 0,  15, $yy, $white,   $font, '@');
            imagettftext($img, $size, 0,  70, $yy, $magenta, $font, $tinfo->FormatPrice($info->price));
            $yy = 2 * $row_h + 30;
            imagettftext($img, $size, 0,  15, $yy, $white,    $font, "batch ");
            imagettftext($img, $size, 0, 130, $yy, $magenta,  $font, $info->batch_id);
            $yy = 3 * $row_h + 30;
            imagettftext($img, $size, 0,  15, $yy, $white,    $font, "order_no ");
            imagettftext($img, 10, 0, 130, $yy, $magenta,  $font, $info->order_no);

            // imageline($img, 0, $height - 1, $width, , $white);
            $fd = fopen($cwd.$fname, 'wb');
            if ($fd) {
                imagepng($img, $fd);
                imagedestroy($img);
                fclose($fd);
                // file_put_contents(".$fname", $html);
                // TODO: monitor URL retrieve from configuration
                // $bot_dir = str_replace('/var/www/', '', $cwd);
                // $bot_dir = str_replace('/data/www/', '', $cwd);
                //  http://alpet.me/$bot_dir/$fname
                $batch_time = '??';
                $batch_id = $info->batch_id;
                $batch = $engine->GetOrdersBatch($batch_id );
                $batch_time = $batch->Elapsed();
                if ($batch_time < 10)
                $batch_time = 'now';
                elseif ($batch_time < 600)
                $batch_time = "$batch_time s";
                else
                $batch_time = sprintf("%02d:%02d", $batch_time / 60, $batch_time % 60);

                $curr_pos = $this->CurrentPos($info->pair_id)->amount;
                $batch_desc = '';
                $ep = $batch->Progress($curr_pos);
                if ($batch->exec_amount > 0)
                $batch_desc = sprintf(", EP %d%%, A:%f, slp $%.1f", $ep, $batch->exec_amount, $batch->slippage * $batch->exec_qty);

                $text = strval($info);
                $text = str_replace('sell', "\xF0\x9F\x94\xBB", $text);
                $text = str_replace('buy', "\xF0\x9F\x8C\xB2", $text);
                $msg = sprintf("<b>%s</b> order posted <u>%s</u> <i>%s</i>, batch <u>%d</u> (time %s%s), ctx: %s", 
                            $this->impl_name, $text, $info->comment, $batch_id , $batch_time, $batch_desc, $context);
                $flags = ($this->notify_orders & 1) ^ 1;
                $this->send_media('ORDER', $msg, $cwd.$fname, $flags);
            }
        }

        public function NotifySmallOrderReg(OrderInfo $oinfo) {
            $this->order_reports[$oinfo->id] = $oinfo;
        }
        public function NotifySmallOrders() {

            $engine = $this->Engine();
            $path = sprintf(__DIR__.'/reports/%s@%d/', date('Y-m-d'), $engine->account_id);
            $list = [];
            foreach ($this->order_reports as $id => $info) {
                if ($info->Elapsed('updated') > 600) {
                unset($this->order_reports[$id]);
                continue;
                }
                $list[$id] = $info;
            }

            $ids = array_keys($list);
            
            $rows = count($ids);      
            if (0 == $rows) return;
            $tr = new TableRender(640, 24 + $rows * 24, $this->report_color);
            $tr->SetColumns(0, 90, 250, 400, 500, 570); // pair, price, amount, exec %, status 
            $tr->DrawBack();
            $tr->DrawGrid();
            $tr->DrawText(0, 0, 'Updated');
            $tr->DrawText(1, 0, 'Pair');
            $tr->DrawText(2, 0, 'Price');
            $tr->DrawText(3, 0, 'Amount');
            $tr->DrawText(4, 0, 'Exec %');
            $tr->DrawText(5, 0, 'Status');

            $buy_vol = 0;
            $sell_vol = 0;

            // $fixed = ['filled', 'canceled', 'lost'];
            $row = 0;
            foreach ($ids as $id) {
                $row ++;
                $info = $list[$id];        
                $t = strtotime($info->updated);
                $tr->DrawText(0, $row, date('H:i:s', $t));
                $tinfo = $engine->TickerInfo($info->pair_id);  
                if (!$info->pair) $info->pair = $tinfo->pair;
                $pair = substr($info->pair, 0, 15);

                if (0 == $info->matched)
                    $info->notified = 1;
                else
                    $info->notified = $info->IsFixed() ? 3 : 2;

                if ($info->buy)
                $buy_vol += $info->Cost();
                else
                $sell_vol += $info->Cost;

                $progress = sprintf('%.1f%%', 100 * $info->matched / $info->amount);    
                if ($info->IsFixed() || $progress > 100) 
                    unset($this->order_reports[$id]); // never report again

                $tr->DrawText(1, $row, $pair);
                if ($tinfo)
                $tr->DrawText(2, $row, $tinfo->FormatPrice($info->price));
                else  
                $tr->DrawText(2, $row, $info->price);  
                $qtxt = $tinfo->FormatAmount($info->amount, Y, Y);        
                $tr->DrawText(3, $row, $qtxt ); // amount
                $tr->DrawText(4, $row, $progress);
                $tr->DrawText(5, $row, $info->status);
            }            
            $fname = "$path/active_orders.png";
            if ($tr->SavePNG($fname)) {
                $msg = sprintf('Small orders progress summary for account %d, buy cost %2G, sell cost %2G', $engine->account_id, $buy_vol, $sell_vol);
                $flags = ($this->notify_orders & 1) ^ 1;
                $this->send_media('ORDER', $msg, $fname, $flags);
            }

        } // NotifySmallOrders        
        protected function SendReports (string $rk) {
            $engine = $this->Engine();
            $mysqli = $this->mysqli;
            $acc_id = $engine->account_id;
            $subdir = sprintf('/reports/%s@%d/', date('Y-m-d'), $acc_id);
            $cwd = __DIR__;
            if (!file_exists($cwd.$subdir))
                mkdir($cwd.$subdir, 0777, true);
            
            $this->LogMsg("Preparing hourly/startup report...");

            $errors = $this->ErrorsCount();
            $hour_ago = date(SQL_TIMESTAMP, time() - 3600);                     
            if ($acc_id > 0) {
                $table = $this->Engine()->TableName('last_errors');
                $query = "DELETE FROM `$table` WHERE (account_id = $acc_id) AND (ts < '$hour_ago');";
                $mysqli->try_query($query); // cleanup old errors
                $this->LogMsg("~C94#CLEANUP_DB:~C00 removing old errors using %s, affected %d rows", $query, $mysqli->affected_rows);
            }  
            $this->incidents = []; // clear as hour ends

            if ($errors >= 50) {  // TODO: last 10 fails dump       
                $tr = new TableRender(440, 48, 'red');
                $tr->row_height = 24;
                $tr->SetColumns(0, 160, 320); 
                $tr->DrawBack(); 
                $tr->DrawGrid($tr->row_height, 2 * $tr->row_height); // first row is header
                $tr->DrawText(0, 0, "       ALERT {$this->impl_name}"); 
                $tr->DrawText(0, 1, "Errors logged"); 
                $tr->DrawText(1, 1, $errors); 
                
                $ename = "$cwd/reports/last_errors_$acc_id.png";
                if ($tr->SavePNG($ename)) {
                $logs_filename = $this->error_logger->log_filename(); // retrieve last
                if (!file_exists($logs_filename))
                    $logs_filename = 'logs/stderr.log';
                if (!file_exists($logs_filename))
                    $logs_filename = "logs/{$this->impl_name}/stderr.log";
                if (!file_exists($logs_filename))
                    $logs_filename = 'logs.td/errors.log';
                if (!file_exists($logs_filename)) 
                    $logs_filename .= ' not exists!';
                $this->send_media('ALERT', "Errors for $this->impl_name, trying send journal $logs_filename next", $ename, 0, true); 
                $this->send_media('ALERT', "Errors journal {$this->impl_name}", $logs_filename, 1, true);            
                }
                else {
                $msg = sprintf("Errors logged for bot %s = %d", $this->impl_name, $errors);  
                $this->send_event('WARN', $msg, $errors);  
                $this->LogError("~C91#ERROR:~C00 can't save errors table into %s", $ename);
                }
        
                $this->error_logger->lines = 0; // reset
                if ($errors > 120 && 0 == date('i' ))
                    $this->Shutdown("High errors count = $errors"); // too many errors
            }
        

            $fname = $subdir . sprintf('%s_%s.png', $this->impl_name, date('_H-i'));
            
            $row_h = 32;
            $row_o = 25;
            $width = 720;
            $list = [];
            // building list of actual for report pairs.
            foreach ($this->pairs_map as $pair_id => $pair) {
                if (!isset($this->target_pos[$pair_id])) continue;
                if (!isset($this->current_pos[$pair_id])) continue;

                $rec = $this->target_pos[$pair_id];
                $cpobj = $this->current_pos[$pair_id];
                $curr = $cpobj->amount;        
                $pnl = $cpobj->realized_pnl;
                $upnl = $cpobj->unrealized_pnl;

                if (isset($engine->pnl_map[$pair_id]))
                    $pnl = $engine->pnl_map[$pair_id];
                

                $age = -1;
                if (isset($rec['age_sec'])) $age = $rec['age_sec'];

                if ($age > 3600 && abs($curr) < 0.001) {
                    $this->LogMsg("#WARN: pair %s excluded from report, due age_sec = %d ", $pair, $age);
                    continue; // ignore obsolete/unused pairs
                }
                else
                    $this->LogMsg("#DBG: pair %s age = %d, current pos = %f ", $pair, $age, $curr);

                if (0 == $rec['value'] && 0 == $curr && 0 == $pnl && 0 == $upnl) continue; // skip pairs without trades and pos
                $rec['curpos'] = $curr;
                $list [$pair_id]= $rec;
            }
            $minute = date('i')  * 1;
            $type = $minute <= 1 ? 'Hourly' : 'Startup';

            if (0 == count($list)) {
                if (0 == $minute)
                    $this->LogMsg ("~C31#WARN:~C00 no tradeable pairs for %s report, exiting", $type);
                return;
            }

            $height  = (2 + count($list)) * $row_h;
            $img = imagecreate($width, $height);
            if (!$img)
                    throw new Exception("can't create image with size $width x $height");

            imagecolorallocate($img, ...$this->report_color);
            $magenta = imagecolorallocate($img, 250, 91, 250);
            $red     = imagecolorallocate($img, 250, 91, 91);
            $yellow = imagecolorallocate($img, 250, 255, 91);
            $green  = imagecolorallocate($img, 90,  255, 91);
            $white    = imagecolorallocate($img, 255, 255, 255);
            $y = 0;
            $cols = [0, 150, 370, 500, $width - 120, $width - 1]; // | pair, price, volume, % allocation |
            imagerectangle ($img, 0, 0, $width - 1, $height - 1, $white);

            foreach ($cols as $x)
                imageline($img, $x, $row_h, $x, $height - $row_h, $white);


            $coef = $this->configuration->position_coef;

            // $font = $cwd.'/arial.ttf';
            $font = '/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf';
            $size = 12;
            $now_ms = time_ms();

            imagettftext($img, $size, 0, 10, $row_o,  $white,  $font, $engine->exchange.'@'.$engine->account_id." hourly report");
            imagettftext($img, $size, 0, 400, $row_o,  $yellow,  $font, 'used funds: ');
            imagettftext($img, $size, 0, 550, $row_o,  $magenta,  $font, sprintf('%.1f%%', $this->used_funds));
            $y += $row_h;
            $buys_vol = 0;
            $shorts_vol = 0;

            $btc_price = $engine->get_btc_price();
            // REPORT: FORMATING POSITION TABLE BY DRAWING TOOLS
            foreach ($list as $pair_id => $rec)  {
                $pair = $this->pairs_map[$pair_id];
                $tinfo = $engine->TickerInfo($pair_id);
                $pcoef = 1;
                if (isset($tinfo->pos_mult) && $tinfo->pos_mult > 1)
                    $pcoef = 1 / $tinfo->pos_mult;

                $pnl = 0;
                if (isset($engine->pnl_map[$pair_id]))
                $pnl = $engine->pnl_map[$pair_id];

                $offset = 0;
                if (isset($this->offset_pos[$pair_id])) {
                    $offset = $this->offset_pos[$pair_id];
                }

                $curr = $rec['curpos'];
                $scaled_pos = $engine->ScaleQty($pair_id, $rec['value']);
                $tgt_pos = $engine->NativeAmount ($pair_id,  $scaled_pos, $this->last_batch_price($pair_id), $btc_price, 'rpt: calc tgt_pos'); // WARN: for quanto pairs value can float 

                if ($tinfo) {
                    $tgt_pos = $tinfo->FormatAmount($tgt_pos * $pcoef);
                    $curr = $tinfo->FormatAmount($curr * $pcoef);
                    $offset = $tinfo->FormatAmount($offset * $pcoef);
                }

                $yy = $y + $row_o;
                $ts = isset($rec['ts']) ? $rec['ts'] : 'now';
                $elapsed = ($now_ms - strtotime_ms($ts)) / 1000.0;
                $elapsed /= 3600.0;
                $color = ($elapsed < 1.0 ? $green : $yellow);
                $ptxt = $pair;
                if (strlen($ptxt) > 10)
                    $ptxt = substr($ptxt, 0, 7).'...';
                imagettftext($img, $size, 0, $cols[0] + 15, $yy, $color,  $font, $ptxt);

                $tps = $tgt_pos;
                if (abs($tps) > 1e6)
                    $tps = sprintf('%.3fM', $tps / 1e6);

                imagettftext($img, $size, 0, $cols[1] + 15, $yy, $yellow, $font, $tps);
                if ($offset > 0)
                    imagettftext($img, $size, 0, $cols[1] + 120, $yy, $green, $font, "+ $offset");
                elseif ($offset < 0)
                    imagettftext($img, $size, 0, $cols[1] + 120, $yy, $red, $font, " $offset");

                $ctxt = $curr; // current position as text
                if (abs(floatval($ctxt)) > 1E6)
                    $ctxt = sprintf('%.3fM', floatval($ctxt) / 1e6);

                imagettftext($img, $size, 0, $cols[2] + 15, $yy, $yellow, $font, $ctxt);        
                
                $alloc = 0;        
                $pos_cost = $engine->AmountToQty($pair_id, $tinfo->last_price, $btc_price, abs($curr)) * $tinfo->last_price;
                if ($tinfo->is_btc_pair) 
                    $pos_cost *= $btc_price;

                if ($curr >= 0)  
                $buys_vol += $pos_cost;
                else 
                $shorts_vol += $pos_cost;

                if ($this->total_funds > 0)                     
                    $alloc = $pos_cost * 100 / $this->total_funds; // сколько позиция в % от всего депозита
            
                $text = sprintf('%.1f', $pos_cost);
                imagettftext($img, $size, 0, $cols[3] + 10, $yy,    $white,  $font, $text); // position cost

                $text = sprintf("%.1f%%", $alloc);
                $bb = imagettfbbox($size, 0, $font, $text); // using for right format, just substract from col position        
                $cx = min (60, $bb[4] - $bb[0]);
                imagettftext($img, $size, 0, $cols[4] + 70 - $cx, $yy,   $alloc < 20 ? $white : $red,  $font, $text); // position allocation
                // $html .= "<tr><td>$pair<td>{}<td>$curr\n";
                imageline($img, 0, $y, $width, $y, $white); // draw horizontal 
                $y += $row_h;
                imageline($img, 0, $y, $width, $y, $white);
                if ($y > $height) break;
            }
            

            $yy = $y + $row_o;
            if ($this->total_funds > 0) {
                imagettftext($img, $size, 0, 10, $yy, $white,  $font, "Total funds on account ");
                imagettftext($img, $size, 0, 350, $yy, $magenta,  $font, sprintf('%.1fK$ / %.3f₿', $this->total_funds / 1000, $this->total_btc));
            }

            $y = $height - 1;
            imageline($img, 0, $y, $width, $y, $white);
            $fd = fopen($cwd.$fname, 'wb');
            if ($fd) {
                imagepng($img, $fd);
                imagedestroy($img);
                fclose($fd);
                // file_put_contents(".$fname", $html);
                // TODO: monitor URL retrieve from configuration
                // $bot_dir = str_replace('/var/www/', '', $cwd);
                // $bot_dir = str_replace('/data/www/', '', $cwd);
                // send_event('REPORT', "$type report http://alpet.me/$bot_dir/$fname saved", 0);
                $flags = 0;
                $hour = gmdate('H') * 1;
                if ($hour >= 20 || $hour < 5) $flags = 1; // not disturb :)
                $msg = sprintf("<b>%s report</b>, uptime = %s, reserve_status = 0x%x, open pos volume: longs  = %.0f, shorts = %.0f, bias = %.2f",  
                                $type, $this->uptime, $this->reserve_status,
                                        $buys_vol, $shorts_vol, $buys_vol - $shorts_vol);

                $this->send_media('REPORT', $msg, $cwd.$fname, $flags);
                $this->reports_map[$rk] = true;         

                $exch = strtolower($engine->exchange);
                if ( (8 == $hour || 20 == $hour) ) {
                // $this->LogMsg("#DBG: trying plot equity report chart...");           
                $upnl  = '';   
                $day_ago = date(SQL_TIMESTAMP, time() - 24 * 3600);
                $funds_table = $engine->TableName('funds_history');
                $mysqli = $this->mysqli;
                $funds_start = $mysqli->select_value('value', $funds_table, "WHERE (ts > '$day_ago') and (account_id = $acc_id) ORDER BY ts ASC");
                $funds_end   = $mysqli->select_value('value', $funds_table, "WHERE (account_id = $acc_id) ORDER BY ts DESC");

                if ($funds_start > 0 && $funds_end > 0) {
                    $bias = $funds_end - $funds_start;
                    $bias_pp = 100 * $ctxt->bias / $funds_start;
                    $upnl = sprintf('funds: %.1f -> %.1f, change %.1f%%', $funds_start, $funds_end, $bias_pp);
                    
                    // 🅰🅱🌜🐬𝓔🔩🐋♓🕴🎷🎉👢Ⓜ😀🥄🅿🍳💲🍄⛎✌🔱💤🏋❎ 🅜🅜🅜🅜
                    if ($bias_pp < -5)
                        $upnl .= sprintf(" такими темпами депозит можно прикончить за %.2f дней \xF0\x9F\x98\xB1\xF0\x9F\x98\xAD", abs($funds_end / $bias));
                    if ($bias_pp < -1)
                        $upnl .= sprintf(" проебическая сила действующая на депозит = %.1f КилоРектов \xF0\x9F\x98\xB0", -$bias / 1000);
                    elseif ($bias_pp > 50) 
                        $upnl .= " вот это профит 💲💲💲, это просто праздник какой-то!\xF0\x9F\x92\xB9";
                    elseif ($bias_pp > 20) 
                        $upnl .= " такими темпами вся просадка закроется!🎉";
                    elseif ($bias_pp > 10) 
                        $upnl .= " за такую прибыль можно и выпить... чаю!😀";
                    elseif ($bias_pp > 1)
                        $upnl .= " отскок дохлой кошки, призрак новой надежды? \xF0\x9F\x99\x88";
                }

                $d = date('w');
                $period = 'Daily';  
                $status = '';           
                $out = [];
                $fcolor = sprintf('#%02x%02x%02x', ...$this->report_color);
                if (5 == $d && $hour > 15) {
                    $period = 'Weekly';  
                    exec("php ../draw_chart.php $exch weekly $acc_id", $out);
                }  
                else
                    exec("php ../draw_chart.php $exch daily $acc_id $fcolor", $out);
                
                $fname = "default.png";
                $log = '';           
                foreach ($out as $line) 
                    if (str_in($line, '#SUCC')) 
                    list($status, $fname) = explode(': ', trim($line));
                    else  
                    $log .= "\t\t+$line\n";         



                if (strpos($status, 'SUCC') && file_exists($fname)) {
                    $this->LogMsg("Equity chart in file %s", $fname);                          
                    $msg = "$period account equity chart. $upnl";
                    $this->send_media('REPORT', $msg, $fname, 0, true);
                    $this->reports_map["E$rk"] = true;
                    system("cp -f $fname ./data/"); // backup local             
                } 
                else {                          
                    $this->reports_map[$rk] = false; // mark as failed, next minute retry
                    $this->LogError("~C91#FAILED(equity_report):~C00 draw_chart not produced file ~C92[$fname]~C00, log:\n~C91%s~C00 ", $log);
                }   

                $this->VolumeReport();
                }
            }
            else
                $this->LogError("~C91#FAILED:~C07 cannot save picture into %s", $cwd.$fname);

            $tr = 3600;
            if ($this->updates <= 1 || 23 == date('H'))
                $tr = 24 * 3600;  // daily report
            if ($minute < 3 || $minute > 57)  
                $this->SlippageReport($tr);
        }

        protected function SlippageReport(float $time_range, bool $use_batches = false) {
            $out = [];
            $engine = $this->Engine();
            $engine->RecoveryBatches();
            $matched = $engine->GetOrdersList('matched');
            $elps_max = 0;
            $btc_price = $engine->get_btc_price();

            if ($use_batches)
            foreach ($engine->batch_map as $batch_id => $batch) {
                if (!is_object($batch) || !$batch instanceof OrdersBatch) continue;
                if ($batch->flags & BATCH_LIMIT || $batch->long_lived) continue;  // MM batches expected no slippage, or to much orders
                $elps = $batch->Elapsed();
                if ($elps > $time_range) continue;

                if ($batch->exec_amount > 0 && 0 == $batch->exec_qty)
                    $batch->UpdateExecStats($matched->TableName());

                $pair_id = $batch->pair_id;
                // fresh batch processing
                if (0 == $batch->slippage || 0 == $batch->exec_qty) continue;
                $elps_m = $elps / 60;
                if ($elps_m < 120)
                $this->LogMsg("#DBG(SlippageReport): checking batch %d, elapsed = %.1fM, price = %f, exec_price = %f, exec_qty = %f", $batch_id, $elps_m, $batch->price, $batch->exec_price, $batch->exec_qty);

                $tinfo = $engine->TickerInfo($pair_id);   
                $coef = $tinfo->is_btc_pair ? $btc_price : 1;

                $elps_max = max($elps_max, $elps);

                if (!isset($out[$pair_id]))
                    $out[$pair_id] = array('slippage' => 0, 'orders' => 0, 'volume' => 0.0);
                $rec = $out[$pair_id];
                $rec['volume']   += $batch->exec_price * $batch->exec_qty * $coef;
                $rec['slippage'] += $batch->slippage * $batch->exec_qty * $coef;
                $rec['orders']   += $matched->CountByField('batch_id', $batch->id);
                $out[$pair_id] = $rec;
            }
            else 
            foreach ($matched as $oinfo) {
                $pair_id = $oinfo->pair_id;
                if (!isset($out[$pair_id]))
                    $out[$pair_id] = array('slippage' => 0, 'orders' => 0, 'volume' => 0.0);
                $rec = $out[$pair_id];                 
                $qty = $engine->AmountToQty($pair_id, $oinfo->price, $oinfo->btc_price, $oinfo->matched);
                if (0 == $qty) continue;
                $tinfo = $engine->TickerInfo($pair_id);
                $start = $oinfo->init_price > 0 ? $oinfo->init_price : $oinfo->price;
                $exec = $oinfo->avg_price > 0 ? $oinfo->avg_price : $oinfo->price;
                $coef = $tinfo->is_btc_pair ? $btc_price : 1;
                $rec['volume']   += $exec * $qty * $coef;    
                $slip = $exec - $start; // положительное при покупке, если цена исполнения выше стартовой
                $rec['slippage'] += $slip * $qty * $oinfo->TradeSign() * $coef;
                $rec['orders'] ++;
                $out[$pair_id] = $rec;               
                $elps = $oinfo->Elapsed('updated') / 3600000;
                $elps_max = max($elps_max, $elps);
            }

            if (0 == count($out)) return;

            $rows = count($out) + 2;

            $tr = new TableRender(440, min(240, $rows * 24), $this->report_color);
            $tr->row_height = 24;

            $tr->SetColumns(0, 160, 360);
            $tr->DrawBack();
            $tr->DrawGrid($tr->row_height, $rows * $tr->row_height); // first row is header
            $tr->DrawText(0, 0, sprintf("Slippage report for %.1f hours", $elps_max / 3600));
            $tr->DrawText(0, 1, 'Pair');
            $tr->DrawText(1, 1, 'Slippage');
            $tr->DrawText(2, 1, 'Orders');
            
            $col = 1;
            $hour = date('H');
            $minute = date('i') * 1;

            foreach ($out as $pair_id => $rec) {
                $col ++;

                $tinfo = $engine->TickerInfo($pair_id);
                if ($tinfo)
                $tr->DrawText(0, $col, $tinfo->pair);
                else
                continue;

                $slp = $rec['slippage'];
                $vol = $rec['volume'];
                if ($vol > 0)
                    $tr->DrawText(1, $col, sprintf('$%.0f (%.2f%%)', $slp, 100 * $slp / $vol), 'text', false, 10);

                $tr->DrawText(2, $col, $rec['orders'], 'text', false, 10);
                $rec = false;
                

                // graphical chart report
                if ('bitfinex' !== strtolower($engine->exchange)) continue;
                if ($minute < 50) continue;
                // $img = curl_http_request("http://localhost/bot/signal_report.php?exchange=bitfinex&pair_id=$pair_id");
                $out = [];
                exec("php ../exec_report.php bitfinex $pair_id", $out);
                    
                $fname = false;
                $text = implode("\n", $out);
                if (strlen($text) > 2048) 
                $text = 'RAW_PNG_OUTPUT? length = '.strlen($text);  

                foreach ($out as $i => $line) {
                if (str_in($line, 'no batches plotted'))
                    break;
                if (str_in($line, '#SUCCESS: '))
                    list($prefix, $fname) = explode(': ', $line);
                if (str_in($line, 'PNG') && 0 == $i)
                    $text = '#ERROR: %RAW_PNG_OUTPUT%'; 
                }     

                if (!$fname) {          
                $this->LogError("~C91#WARN:~C00 can't send batch report for pair %s:\n %s", $tinfo->pair, $text);
                continue;
                } 
                $fname = trim($fname);
                $this->send_media('REPORT', "Batch report ".$tinfo->pair, $fname, 1);  // without notify
                $this->reports_map["SigR$hour@{$tinfo->pair}"] = true;
            }

            $subdir = sprintf('/reports/%s@%d/', date('Y-m-d'), $engine->account_id);
            $cwd = getcwd();
            if (!file_exists($cwd.$subdir))
                mkdir($cwd.$subdir, 0777, true);
            $fname = $cwd. $subdir . sprintf('slp_%s_%s.png', $this->impl_name, date('_H-i'));
            if ($tr->SavePNG($fname)) {
                $this->send_media('REPORT', "Slippage report ", $fname, 1, true);  // without notify
                $this->reports_map["SlpR$hour"] = true;
            }     
            
        }

        protected function VolumeReport() {
        
            $engine = $this->Engine();
            $map = [];
            $matched = $engine->GetOrdersList('matched');
            if (0 == $matched->count());
                $matched->LoadFromDB();
            $orders = $matched->RawList();
            if (0 == count($orders)) {
                $this->LogMsg("~C91 #WARN:~C00 No matched orders was loaded, skip volume report");
                return;
            }
            $account = $engine->account_id;
            $after_t = time() - 24 * 3600;
            $counted = 0;

            $flt_map = [];      
            // выборка всех пар, в которых были сделки за последние 24 часа для текущего аккаунта. Только с сигналами
            if ($orders)
                foreach ($orders as $oinfo) {          
                $filter = 0;
                if (strtotime($oinfo->ts) < $after_t) $filter = 1;
                if ($oinfo->account_id != $account) $filter |= 2;
                if (0 == $oinfo->batch_id) $filter |= 4;
                if ($filter > 0)  {
                    if (isset($flt_map[$oinfo->pair_id]))
                    $flt_map[$oinfo->pair_id] |= $filter;
                    else  
                    $flt_map[$oinfo->pair_id] = $filter;
                    continue;
                }

                $pair_id = $oinfo->pair_id;
                $counted ++;
                if (isset($map[$pair_id]))
                    $map[$pair_id] []= $oinfo;
                else
                    $map[$pair_id] = [$oinfo];
                }

            $keys = array_keys($map);  
            $rows = count($keys) + 3; // top header + cells + summary lines
            if ($rows <= 3) {
                $this->LogMsg("~C91 #WARN:~C00 No matched orders for last 24H, skip report");
                return; 
            }      

            $tr = new TableRender(640, max(240, $rows * 24), $this->report_color);
            $tr->row_height = 24;
            
            // pair, buys volume, sells volume
            $tr->SetColumns(0, 100, 330, 330 + 220);
            $tr->DrawBack();
            $tr->DrawGrid($tr->row_height, $rows * $tr->row_height); // first row is header
            $tr->DrawText(0, 0, sprintf("24HR Volume report for $account"));
            $tr->DrawText(0, 1, 'Pair');
            $tr->DrawText(1, 1, 'Buys');
            $tr->DrawText(2, 1, 'Sells');
            $tr->DrawText(3, 1, 'RPL');
            $btc_price = $engine->get_btc_price();
            $row = 1;      
            $total = 0;
            $rpl_sum = 0;
            ksort($map);

            

            foreach ($map as $pair_id => $orders) {
                $tinfo = $engine->TickerInfo($pair_id);
                if (!$tinfo) continue;
                unset($flt_map[$pair_id]);
                // фильтр цены в основном для избежание ошибочных расчетов
                $min_price = $tinfo->last_price * 0.3; // редкий случай краха
                $max_price = $tinfo->last_price * 2.5; // ещё более редкий случай ракеты
                // $orders = $engine->FindOrders('pair_id', $pair_id, 'matched,pending');
                $buys_vol = 0;
                $buys_qty = 0;
                $sells_vol = 0;
                $sells_qty = 0;

                foreach ($orders as $oinfo) {
                    // if (strtotime($oinfo->updated) < $after_t) continue;
                    $price = $oinfo->avg_price > 0 ? $oinfo->avg_price : $oinfo->price;            
                    if ($price < $min_price || $price > $max_price) continue;

                    $qty = $engine->AmountToQty($pair_id, $oinfo->price, $btc_price, $oinfo->matched); 
                    $coef = $tinfo->is_btc_pair ? $btc_price : 1;
                    

                    

                    if ($oinfo->buy) {           
                    $buys_vol += $qty * $price * $coef;
                    $buys_qty += $qty;
                    }  
                    else {
                    $sells_vol += $qty * $price * $coef;
                    $sells_qty += $qty;
                    }  
                }
                $row ++;
                $tr->DrawText(0, $row, substr($tinfo->pair, 0, 8), 'text', false, 10);
                $buys_avg = 0;
                $sells_avg = 0;
                if ($buys_qty > 0)
                    $buys_avg = $buys_vol / $buys_qty;
                

                if ($sells_qty > 0)
                    $sells_avg = $sells_vol / $sells_qty; 
                    
                
                $bp = $tinfo->FormatPrice($buys_avg, Y, 2);   
                $sp = $tinfo->FormatPrice($sells_avg, Y, 2);

                $text = sprintf('%4.0fK$ / %10s = %7s', $buys_vol / 1000, $tinfo->FormatQty($buys_qty, Y), $bp);
                $tr->DrawText(1, $row, $text, 'text', false, 10);
                $text = sprintf('%4.0fK$ / %10s = %7s', $sells_vol / 1000, $tinfo->FormatQty( $sells_qty, Y), $sp);
                $tr->DrawText(2, $row, $text,  'text', false, 10);

                $min_qty = min($buys_qty, $sells_qty);
                $rpl = 0;
                if ($min_qty > 0)
                    $rpl = $sells_avg * $min_qty - $buys_avg * $min_qty; 
                $rpl_sum += $rpl; 
                $tr->DrawText(3, $row, sprintf('%5.2fK$', $rpl / 1000)); 
                $total += $buys_vol + $sells_vol;         
                if ($row > $rows) {
                    $this->LogError("~C91#WARN:~C00 too many pairs in volume report for %d rows", $rows);
                    break;
                }
            }

            $row ++;
            $tr->DrawText(0, $row, "Summary: "); 
            $tr->DrawText(3, $row, sprintf('%5.2fK$', $rpl_sum / 1000)); 
            ksort ($flt_map);
            $this->LogMsg("~C93#DBG(VolumeReport):~C00 formated %d rows, filtered pairs = %s", $row - 1, json_encode($flt_map));
            $subdir = sprintf('/reports/%s@%d/', date('Y-m-d'), $engine->account_id);
            $cwd = getcwd();
            if (!file_exists($cwd.$subdir))
                mkdir($cwd.$subdir, 0777, true);

            $fname = $cwd. $subdir . sprintf('volume_report_%s.png', $this->impl_name);
            if ($tr->SavePNG($fname)) {        
                $this->LogMsg("#DBG: Volume report saved into %s, trying send...", $fname);
                $this->send_media('REPORT', "Volume report for account $account. Total volume = ".
                                sprintf('$%.3fK in %d orders', $total / 1000, $counted), $fname, 1, true);  // without notify        
                
                // $this->reports_map["SlpR$hour"] = true;
            }     
            else 
            $this->LogError("~C91#FAILED:~C07 cannot save volume report into %s", $cwd.$fname);

        }
    }