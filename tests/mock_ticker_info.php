<?php    
    require_once __DIR__.'/../src/ticker_info.php';

    class MockTickerInfo extends TickerInfo {
        public function __construct()
        {
            global $g_bot;
            assert (is_object($g_bot));
            $engine = $g_bot->Engine();
            parent::__construct(1, 'DUMMYUSD', $engine);
            $this->price = 2.0;
            $this->is_btc_pair = false;
            $this->lot_size = 0.001;
            $this->last_price = 2.0;
            $this->bid_price = 1.99;
            $this->ask_price = 2.01;
            $this->mid_spread = 2;
        }
    }