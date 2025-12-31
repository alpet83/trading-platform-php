<?php
 require_once 'lib/common.php';
 require_once 'lib/esctext.php';

 define('BITCOIN_PAIR_ID', 1);
 // futures flags
 define('TICKER_FLAG_INVERSE', 1);
 define('TICKER_FLAG_QUANTO', 2);
 define('TICKER_FLAG_LINEAR', 4);

class TickerInfo {
    public  $tiker = '';

    public  $cmc_price = 0; // price from CoinMarketCap
    public  $notional = 0;
    public   $json = '';  // RAW API info

    public  $is_btc_pair = false;
    public  $min_notional = 0;
    public  $mid_spread = 0;

    public  $cost_limit = 10000;  // ограничение на заявку в долларах, уменьшается согласно ликвидности
    
    public   $expired = false;
    public   $enabled = true; // returned by API, enabled for trading

    protected $fmt = null;

    

    protected $owner = null;

    public   $amount_divisor = 1; // amount must divide by this value without remainder
    public   $checked = 0;  // last checked time in ms

    protected $raw_set = array('pair' => '?', 'pair_id' => 0, 'symbol' => '?', 'quote_currency' => 'USD', 'last_price' => 0, 'ask_price' => 0, 'bid_price' => 0, 'fair_price' => 0, 'lot_size' => 0.1, 'daily_vol' => 0, 'updated' => 0, 
                               'native_price' => 0, 'tick_size' => 0.00000001, 'min_price' => 0, 'min_cost' => 1, 'q2s_mult' => -1, 'price_precision' => 8, 'pos_mult' => -1, 'multiplier' => 0,
                               'is_quanto' => false, 'is_inverse' => false, 'is_linear' => false, 'qty_precision' => 8, 'trade_coef' => 1.0);

    public function __construct(int $pair_id, string $pair, TradingEngine $owner) {
        $this->pair_id = $pair_id;
        $this->pair = $pair;
        $this->symbol = $pair;
        $this->owner = $owner;      
        $this->updated = strtotime_ms('2013-01-02 00:00:00'); // fake
        $this->checked = $this->updated;
        $this->base_currency = substr($pair, 0, 3); // default 3 chars, but can be 2-3
        $this->DetectQuoteCurrency();
    }

    public function __get ( $key ) {
        if (array_key_exists($key, $this->raw_set))
            return $this->raw_set[$key];
    }
    public function __isset ( $key ) {
        return isset ($this->raw_set[$key]);
    }

    public function __set (string $key, mixed $value) {
        if (!is_numeric($value) && false !== array_search($key, ['pair_id', 'checked', 'updated'])) {
          log_cmsg("~C91#ERROR:~C00 attempt set %s->%s to %s:%s from %s", 
                  strval($this), $key, strval($value), gettype($value), format_backtrace());
          return;
        }
        // if ('pos_mult' == $key && $value > 0)  log_cmsg("#DBG: for %s pos_mult = %d", $this->pair, $value);
        $this->raw_set[$key] = $value;
    }

    public function __toString() {
        $res = sprintf("%d:%s, lp:%s, fp:%s, tc:%f, ls:%.8f, mn:%f", 
              $this->pair_id, $this->pair, 
                    $this->FormatPrice($this->last_price), $this->FormatPrice($this->fair_price),
                    $this->trade_coef, $this->lot_size, $this->min_notional);
        if ($this->is_inverse)
            $res .= ', is_inverse';
        elseif ($this->is_quanto)
            $res .= ', is_quanto';
        return trim($res);
    }

    public static function CalcPP(float $base) {      
        $base = abs($base);
        $frac = $base - floor($base); 
        $frac = sprintf('%.16f', $frac);
        if (str_in($frac, '.'))
            $frac = rtrim($frac, '0');
        $min =  max(0, strlen($frac) - 2);       
        $pp = 6 - log10($base); // default, ex for 1000 = 3 decimals                  
        $pp = max(0, $pp); 
        $pp = min($min, $pp);

        $pp = ceil($pp); // round up always         
        return intval($pp);
    }

    public function DetectQuoteCurrency() {        
        $this->quote_currency = substr(rtrim($this->pair, 'T'), -3);        
        preg_match('/(AUD|BTC|ETH|USDC|USDT|USD|EURC|EUR|GBP|JPY|CHF|XAU|XBT)\w$/', $this->pair, $m);
        if (count($m) > 1) 
            $this->quote_currency = $m[1];  // 4 chars USD stable coin              
        $this->is_btc_pair = 'XBT' == $this->quote_currency || 'BTC' == $this->quote_currency;
    }

    public function FormatPrice($price = 0, bool $trim = true, int $coarse = 0): string {
      $pp = 8;                  
      $price = doubleval($price);         
      if (!$this->fmt) {         
        $pp = $this->price_precision;     
        if (0 == $pp)
            $pp = $this->CalcPP($this->tick_size > 0 ? $this->tick_size : $price);
        $this->fmt = sprintf('%%.%df', $pp);
      }

      if (0 == $price)
          $price = $this->last_price;

      if ($this->tick_size > 0)
          $price = floor($price / $this->tick_size) * $this->tick_size; // round to tick size

      $fmt = $this->fmt;
      if ($coarse > 0) { // correct precision for large numbers
          $pp = $this->CalcPP($price);  // for 1 = 1/1000000 precision          
          if ($price > 10000 && $pp > 3) {
             log_cmsg("~C91#WARN:~C00 price excess precision calculated $pp for %f %s, was saved %d\n", $price, $this->pair, $this->price_precision);
             $pp = 3;
          }  
          $pp = max(0, $pp - $coarse); 
          $fmt = sprintf('%%.%df', $pp);
      }          

      if (abs($price) < 1000 && 0 == $pp && 0 == $coarse) {
         log_cmsg("~C91#ERROR:~C00 price precision calculated $pp for %f %s, was saved %d\n", $price, $this->pair, $this->price_precision);          
         $pp = $this->price_precision;
      }   
      $res = strval($price);
      if ($pp >= 0)         
          $res = sprintf($fmt, $price); // possible round to nearest price

      if (strpos($res, '.') !== false && $trim)          
          $res = rtrim($res, '0');
      return $res; 
    }

    public function FormatAmount($amount, bool $native = true, bool $scale = false) {      
        $qp = 0;
        if ($this->lot_size < 1)
            $qp = ceil(-log10 ($this->lot_size));
        
        $ua = abs($amount);   
        if ($ua < $this->lot_size)
            return 0;

        if ($scale && $ua > 1000) 
            return $this->FormatQty($amount, $scale);

        $qp = min($qp, 6);
        $qp = max($qp, 0);
        if (!$native && isset($this->pos_mult)) // pos_mult используется некоторыми биржами, где нативная позиция представляет большое целочисленное, а реальная может быть дробным числом
            $qp += floor(log10($this->pos_mult));  // lot_size = 1, mult = 100000, so add 5 decimals.      

        $qp = round($qp);     
        $s = number_format($amount, intval($qp), '.', ''); // default precision      
        if (strpos($s, '.') !== false)          
            $s = rtrim($s, '0');
        return rtrim($s, '.'); // remove ending dot if found            
    }
    public static function FormatQty(float $qty, bool $scale = false) {
        $uqty = abs($qty);
        if ($scale && $uqty >= 1000) {
          if ($uqty > 1e9) return sprintf("%.4fB", $qty / 1e9);
          if ($uqty > 1e6) return sprintf("%.4fM", $qty / 1e6);                
          return sprintf("%1G", $qty); // 1000.1 seems good
        }
        $res = number_format($qty, 5, '.', ''); // default precision
        if (strpos($res, '.') !== false)          
            $res = rtrim($res, '0');
        return trim($res, '.');
    }
    public function Initialize($last_price, $ask_price, $bid_price) {
        $this->last_price = $last_price;
        $this->ask_price = $ask_price;
        $this->bid_price = $bid_price;
        $this->updated = time_ms();
    }

    
    public function LimitMin(float $qty, bool $native, bool $absolute = false) {
      $engine = $this->owner;

      $min = $this->lot_size * $this->min_notional;
      if (!$native)
          $min = $engine->AmountToQty($this->pair_id, $this->last_price, $engine->get_btc_price(), $min);

      if ($absolute && abs($qty) > 0 && abs($qty) < $min)
          return  0;
      if (!$absolute && $qty < $min)
          return 0;

      return $qty;   
    }


    public function LoadFromRec(stdClass $rec, array $field_mapping) {
        foreach ($field_mapping as $dst => $src)            
            if (isset($rec->$src))
                $rec->raw_set[$dst] = $rec->$src;
    }

    public function RoundAmount(float $amount): float {
      if ($this->lot_size > 0)
          $amount = floor($amount / $this->lot_size) * $this->lot_size; // round to lot size
      return $amount;
    }

    public function RoundPrice(float $price): float {
      if ($this->tick_size > 0)
          $price = floor($price / $this->tick_size) * $this->tick_size; // round to tick size
      return round($price, $this->price_precision);
    }

    public function RoundQty(float $qty) {
      if ($this->last_price <= 0)  
          $this->last_price = 1; // ugly hack for uninitialized price

      $min_qty = $this->min_cost / $this->last_price;
      $qp = 3;
      if ($min_qty < 1)
          $qp = ceil(2 - log10 ($min_qty)); // 0.1 = 3 decimals
      $qp = max($qp, 0);   
      return round($qty, $qp);
    }

    public function SaveToDB() {
      $engine = $this->owner;
      $table = $engine->TableName('tickers');      
      $elps = (time_ms() - $this->updated) / 1000;
      if ($elps > 600) return; // outdated/hangout - not need      
      // TODO: make cache ticker info global method
      $flags = 0;
      if ($this->is_inverse)  $flags |= TICKER_FLAG_INVERSE;
      if ($this->is_quanto)   $flags |= TICKER_FLAG_QUANTO;
      if ($this->is_linear)   $flags |= TICKER_FLAG_LINEAR;
      $ts = date_ms(SQL_TIMESTAMP3, $this->updated);
      $query = "INSERT INTO `$table`(pair_id, last_price, lot_size, tick_size, multiplier, flags, trade_coef, ts_updated)\n ";
      $query .= "VALUES($this->pair_id, {$this->last_price}, {$this->lot_size}, {$this->tick_size}, {$this->multiplier}, $flags, {$this->trade_coef}, '$ts' )\n";
      $query .= "ON DUPLICATE KEY UPDATE symbol = '{$this->pair}', last_price = {$this->last_price}, multiplier = {$this->multiplier}, trade_coef = {$this->trade_coef}, ts_updated = '$ts'; ";
      $engine->sqli()->try_query($query);
    }

    public function SaveToJSON () {
      $raw = $this->raw_set;
      $raw['is_btc_pair'] = $this->is_btc_pair;
      $raw['fmt'] = $this->fmt;
      $raw['mid_spread'] = $this->mid_spread;
      $raw['save_time'] = date_ms(SQL_TIMESTAMP3);
      if (strlen($this->json) > 20)
          $raw['json'] = $this->json;
      return json_encode($raw);
    }    
    public function QtyCost(float $qty): float {
      $res = $this->last_price * $qty;
      if ($this->is_btc_pair)
         $res *= active_bot()->Engine()->get_btc_price();
      return round($res, 2);  
    }

    public function OnUpdate($ts = 'now') {
       $this->mid_spread = 0;
       if ($this->ask_price > 0 && $this->bid_price > 0)
          $this->mid_spread = ($this->ask_price + $this->bid_price) / 2;
       if (is_string($ts)) 
           $ts = strtotime_ms($ts);
       $this->updated = $ts; 
       $this->checked = time_ms();
    }
  } // class TickerInfo

  