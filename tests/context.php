<?php
    declare(strict_types=0);

    // 1. Подключаем всё, что нужно
    define("SOURCE_PATH", __DIR__."/../src");
    require_once SOURCE_PATH . '/lib/common.php';
    require_once SOURCE_PATH . '/lib/smart_vars.php';    
    require_once SOURCE_PATH . '/bot_globals.php';
    require_once SOURCE_PATH . '/trading_context.php';
    require_once SOURCE_PATH . '/trade_config.php'; 
    require_once 'mock_ticker_info.php';
    // + любые другие файлы, которые использует TradingContext

    class TradingEngine {   // Мок торгового движка
        private $owner = null;

        public function __construct($core) {
            $this->owner = $core;
        }

        public function QtyToAmount(int $pair_id, float $price, float $btc_price, float $value, mixed $ctx = false) {
           return $value;
        }

        public function AmountToQty(int $pair_id, float $price, float $btc_price, float $value, mixed $ctx = false) { // from contracts to real ammount
            return $value;
        }

        public function  LimitAmountByCost(int $pair_id, float $qty, float $max_cost) {
            printf ("limit amount by cost called: pair_id=%d, qty=%f, max_cost=%f\n", $pair_id, $qty, $max_cost);
            return min($qty, 5000); // fake limit
        }
    }

    // 2. Мокаем глобальный $g_bot (обязательно!)
    class TradingCore {
        private TradingEngine $engine;
        public TradeConfig $configuration;

        public function __construct() {
            $this->engine = new TradingEngine($this);            
            $this->configuration = new TradeConfig($this);
        }
        public function  ConfigValue($key, $default = false) {
            return $this->configuration->GetValue($key, $default);
        }
        public function Engine() { return $this->engine; }
        public function BitcoinPrice() { return 60000.0; }
        public function LogMsg($msg) { echo "[LOG] $msg\n"; }
    }   
        
    // 5. Простой тестовый класс (без PHPUnit)
    class TradingContextTest
    {
        private TradingContext $ctx;

        public function __construct()
        {
            global $g_bot;
            $g_bot = new TradingCore();            
            $ti = new MockTickerInfo();
            $this->ctx = new TradingContext($ti, 10000.0);
            $this->ctx->init();
        }

        public function assertEquals($expected, $actual, $msg = '')
        {
            if ($expected !== $actual) {
                echo "FAIL: $msg | Ожидалось: $expected, получено: $actual\n";
                exit(1);
            } else {
                echo "OK: $msg\n";
            }
        }

        public function testBiasTargetSync()
        {
            $ctx = $this->ctx;
            $this->ctx->amount = 111; // должно перезаписаться в процессе через логику связи переменных

            printf("=== [ Тест: Синхронизация current_pos = %f, bias %f, target_pos %f ] ===\n", $ctx->curr_pos, $ctx->bias, $ctx->target_pos);
            $this->ctx->target_pos = 15000.0;            
            $this->assertEquals(5000.0, $this->ctx->bias, 'bias после target_pos = 15000');
            printf(" cost_limit = %f\n", $this->ctx->cost_limit);
            $amount = $ctx->get_var('amount');
            $this->assertEquals(5000.0, $this->ctx->amount, 'amount после bias: '.implode("\n", $amount->limit_log));
            $this->assertEquals(true, $this->ctx->is_buy(), 'направление buy');

            $this->ctx->bias = 2000.0;
            $this->assertEquals(12000.0, $this->ctx->target_pos, 'target_pos после bias = 2000');
        }

        public function testAmountClampingPreservesBias()
        {
            $this->ctx->get_var('amount')->constrain('max', 1000.0);

            $this->ctx->bias = 5000.0;

            $this->assertEquals(5000.0, $this->ctx->bias, 'bias не должен меняться');
            $this->assertEquals(1000.0, $this->ctx->amount, 'amount обрезан');
            $this->assertEquals(15000.0, $this->ctx->target_pos, 'target_pos сохранён');
        }

        public function run()
        {
            echo "=== Запуск тестов TradingContext ===\n";
            $this->testBiasTargetSync();
            $this->testAmountClampingPreservesBias();
            echo "ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!\n";
        }
    }

    // 6. Запуск
    (new TradingContextTest())->run();