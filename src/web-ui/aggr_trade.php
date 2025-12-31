<?php
/*
  ================== Концепт агрегатора ========================
  На вход подаются сигналы, имеющие точное значение абсолютной целевой позиции и цену достижения. Предполагается исключение некоторых сигналов по заданному фильтру.
  Пока абсолютное значение позиции растет, идет формирование агрегированного входа, с усреднением цены сигналов по обьему. Снижение позиции фиксирует вход, и начинает формировать выход трейда. Нулевое значение позиции всегда фиксирует закрытие трейда, остаток объема переносится в следующий.
  ?? Промежуточные (частичные) выходы отщипывают объем у основного входа, и формируют отдельные агрегированные трейды. ??

 TODO:
   * работа с сигналами и отдельными трейдами на входе (для мануальных депо).
   * генерация отчетов за кварталы и последний год-два. 
   * BitMEX: start_pos & target_pos - пересчитывать из контрактов в позицию


*/


class AggregatedTrade {
 public   $enter_price;
 public   $enter_ts = '';
 public   $enter_qty;
 public   $exit_price;
 public   $exit_ts = '';     
 public   $exit_qty;        
 
 public   $direction = 0; // 1 = long, -1 = short = 0;     

 public   $opened_cost = 0;
 public   $closed_cost = 0;

 public   $curr_pos = 0;

 public    $profit = 0;
 
 public   $first_batch = 0;
 public   $last_batch = 0;
 protected $batches = 0;
 

 protected $story = '';


 function   AggrEnter(int $sid, int $time, float $pos, float $price) {
     $qty = abs($pos) - abs($this->curr_pos);
     if (0 == $qty) return;
     if (!$this->enter_ts)  {
         $this->enter_ts = date(SQL_TIMESTAMP, $time);
         $this->first_batch = $sid;
     }     

     $this->direction = signval($pos); 
     $this->story .= "o$qty@$price=$pos;";
     $this->opened_cost += $qty * $price;
     $this->enter_qty += $qty;
     $this->enter_price = $this->opened_cost / $this->enter_qty;
     $this->curr_pos = $pos;
     $this->batches ++;
 }

 function   AggrExit(int $sid, int $time, float $pos, float $price) {
     $qty = abs($this->curr_pos) - abs($pos);
     if (0 == $qty) return;     
     $this->last_batch = $sid;   
     $this->story .= "c$qty@$price=$pos;";        
     $this->exit_qty += $qty;
     $this->closed_cost += $qty * $price;
     $this->exit_price = $this->closed_cost / $this->exit_qty;
     $this->curr_pos = $pos;
     $this->batches ++;
     if (0 == $pos) {
         $this->exit_ts = date(SQL_TIMESTAMP, $time); // last batch             
         $this->profit = ($this->closed_cost - $this->opened_cost) * $this->direction; 
     }
     else {
        $enter_cost_part = $this->exit_qty * $this->enter_price;
        $this->profit = ($this->closed_cost - $enter_cost_part) * $this->direction; 
     }   
     
 }

};  

class TradesAggregator {
    public  $agr_trades = [];
    public  $last_trade;

    public function   ProcessBatches (array $batches) {
        if (0 == count($batches)) return;    
        $curr_trade = new AggregatedTrade();            

        foreach ($batches as $batch) {
            $ts = $batch['ts']; 
            $price = $batch['exec_price'];
            $pos = contracts_to_amount( $ts, $batch['target_pos'], $price); // need native coin position
            if ($pos == $curr_trade->curr_pos) continue; // skip same
            $sid = $batch['id'];           
            $time = strtotime($ts);

            if (signval($curr_trade->curr_pos) != signval($pos)) {
            // closing current aggr.trade 
            if ($curr_trade->curr_pos != 0) {
                $curr_trade->AggrExit($sid, $time, 0, $price);
                $this->agr_trades []= $curr_trade;
                $curr_trade = new AggregatedTrade(); // next                
            }  

            if ($pos != 0)                          
                $curr_trade->AggrEnter($sid, $time, $pos, $price);

            } elseif (abs($pos) > abs($curr_trade->curr_pos)) {             
            $curr_trade->AggrEnter($sid, $time, $pos, $price); // accumulation in progress             
            } elseif (abs($pos) < abs($curr_trade->curr_pos)) {             
            $curr_trade->AggrExit($sid, $time, $pos, $price); // partial exit
            }
        }
        $this->last_trade = $curr_trade;
    }

    public function ProcessTrades(array $trades) {
        // RPL оценивается после каждой сделки сокращающей позицию
        // При увеличении позиции, пересчет цены открытия позиции
        // UPL результат для текущей позиции относительно цены открытия
        
        $curr_trade = new AggregatedTrade();
        $this->last_trade = $curr_trade;
    }


} // class

