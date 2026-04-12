<?php
    // libarary for basic bot info loading in WebUI
    require_once('lib/db_tools.php');
    define('SATOSHI_MULT', 0.00000001);
    define ('USD_MULT', 0.000001);

    define('SIG_FLAG_TP', 0x001);
    define('SIG_FLAG_SL', 0x002);
    define('SIG_FLAG_LP', 0x004);
    define('SIG_FLAG_SE', 0x010);   // eternal stop
    define('SIG_FLAG_GRID', 0x100);

    class EngineInfo {
        public $account_id;
        public $owner;
        public $exchange = 'NYMEX';        

        /** @var array of TickerInfo/stdClass  */
        public $tickers_map = []; 
        public function __construct(MiniCore $owner) {
            $this->owner = $owner;
            $this->account_id = $owner->mysqli->select_value('account_id', 'config__table_map', "WHERE table_name = '{$owner->config_table}' LIMIT 1");
            $this->exchange = $owner->config['exchange'] ?? $this->exchange;            
        }
        public function TableName(string $table) {
            return strtolower($this->exchange.'__'.$table);
        }
        public function LoadTickers() {
            $core = $this->owner;
            $rows = $core->mysqli->select_rows('pair_id,symbol,last_price,multiplier,flags,tick_size,lot_size', $this->TableName('tickers'), '', MYSQLI_ASSOC);
            if (!is_array($rows)) {
                return;
            }

            foreach ($rows as $row) {
                $pair_id = intval($row['pair_id'] ?? 0);
                if ($pair_id <= 0) {
                    continue;
                }
                $symbol = strtoupper((string)($row['symbol'] ?? ($core->pairs_map[$pair_id] ?? '')));
                $quote = '';
                foreach (['USDT', 'USDC', 'USD', 'XBT', 'BTC', 'ETH'] as $suffix) {
                    if ($symbol !== '' && str_ends_with($symbol, $suffix)) {
                        $quote = $suffix;
                        break;
                    }
                }

                $flags = intval($row['flags'] ?? 0);
                $ti = (object)[
                    'pair_id' => $pair_id,
                    'symbol' => $symbol,
                    'last_price' => floatval($row['last_price'] ?? 0),
                    'multiplier' => floatval($row['multiplier'] ?? 1),
                    'tick_size' => floatval($row['tick_size'] ?? 0),
                    'lot_size' => floatval($row['lot_size'] ?? 0),
                    'is_inverse' => (bool)($flags & 1),
                    'is_quanto' => (bool)($flags & 2),
                    'quote_currency' => $quote,
                    'pos_mult' => 1.0,
                ];

                $this->tickers_map[$pair_id] = $ti;
            }
        }
    }  

    class MiniCore {
        public $mysqli;
        public $trade_engine = null;
        public $impl_name = '';

        public $bots = [];
        public $config = null;
        public $config_table = '';

        public $pairs_map = [];

        public function __construct(mysqli_ex $mysqli, string $impl_name) {
            $this->mysqli = $mysqli;
            $this->impl_name = $impl_name;      
            $this->bots = $mysqli->select_map('applicant,table_name', 'config__table_map'); 
            $this->config_table = $this->bots[$impl_name] ?? 'config__test';
            $this->config = $mysqli->select_map('param,value', $this->config_table, '', MYSQLI_OBJECT);      
            

            $engine = new EngineInfo($this);
            $this->trade_engine = $engine;
            $table = $engine->TableName('pairs_map');
            $this->pairs_map = $mysqli->select_map('pair_id,pair', $table);
            $engine->LoadTickers();
        }

    }

    function AmountToQty(\stdClass $ti, float $price, float $value) {
        global $mysqli, $pair_id, $btc_price, $exch;    

        if ($ti->is_quanto && $ti->multiplier > 0 && $btc_price > 0) {
            $btc_coef = SATOSHI_MULT * $ti->multiplier;   // this value not fluctuated, typically 0.0001
            $btc_cost = $price * $btc_coef * $value;
            $usd_cost = $btc_cost * $btc_price;  // this value may be variable
            $res = $usd_cost / $price;      
            return $res;                 // real position in base coins
        }
        if ($ti->is_inverse) {
            return $value / $price;  // may only XBTUSD and descedants
        }


        if (false === $ti->is_quanto && isset($ti->pos_mult) && $ti->pos_mult > 0)
            return $value / $ti->pos_mult;
        return $value;
    }



?>