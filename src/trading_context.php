<?php
    require_once 'lib/common.php';
    require_once 'lib/smart_vars.php';
    require_once 'bot_globals.php';
    require_once 'trade_config.php';
    require_once 'ticker_info.php';
    require_once 'orders_batch.php';    


    /**
     * класс TradingValue
     * Переменная с ограничениями присущими в торговом контексте, касается типично размера позиции или заявки.
     */
    class TradingValue extends BoundedValue {

        public function __construct(TradingContext $owner, float $initial = 0.0, string $name = 'unnamed', bool $unsigned = false) {
            $this->owner = $owner;            
            parent::__construct($initial, $name, $unsigned);
            $this->set_logic();
        }

        public function __toString() {
            $ti = $this->owner->tinfo;
            if (is_object($ti))
                return $ti->FormatAmount($this->value, Y, Y);
            return parent::__toString();
        }

        public function context(): ?TradingContext {            
            return $this->owner;
        }
        public function set_logic() {                                
            
            // настройка логики: запутывание обработчиков изменений между переменными
            // bias → target_pos (абсолютное значение + направление), используется строго для корректировки, без изменения направления заявки            
            $handler = match($this->name) {
                'bias' => function (float $bias, float $old, string $source) {
                    assert ($this instanceof TradingValue);
                    assert ($bias >= 0 );                                        
                    $context = $this->context();
                    $dir = $context->trade_dir_sign();
                    $context->get_var('amount')->set($bias, source: 'bias→amount');

                    if (0 == $dir) return; // unknown direction, no action                    
                    $newTarget = $context->curr_pos + $bias * $dir;
                    if ($newTarget == $context->target_pos) return;
                    $core = $context->core();                    
                    if ($context->verbosity > 1)
                        $core->LogMsg("~C93 #DBG:~C00 TradingValue '%s.bias' changed to %.5f, with dir %d, setting target_pos to %.5f, source: %s", $context->name, $bias, $dir, $newTarget, $source);
                    $context->get_var('target_pos')->set($newTarget, source: 'bias→target_pos');                    
                },
                'amount' => function (float $value, float $old, string $source) { 
                    assert ($this instanceof TradingValue);                    
                    $context = $this->context();
                    $ti = $context->tinfo;
                    $core = $context->core();                    
                    $engine = $context->engine(); 
                    if ($context->verbosity > 1)
                        $core->LogMsg("~C93 #DBG:~C00 TradingValue '%s.amount' changed to %.5f, source: %s", $context->name, $value, $source);                    
                    
                    $qty = (0 == $value) ? 0 : $engine->AmountToQty($ti->pair_id, price: $context->price, btc_price: $context->btc_price, value: $value);                    
                    $context->get_var('qty')->set($qty, source: 'amount→qty');
                },
                'target_pos' => function (float $target_pos, float $old, string $source) { // target_pos → bias (абсолютное значение)
                    assert ($this instanceof TradingValue);
                    $context = $this->context();
                    if (signval($target_pos) == -signval($context->curr_pos) && !$context->allow_zero_cross) {
                        $context->split_trade = true;
                        if ($context->verbosity > 1)
                            $context->core()->LogMsg("~C93 #DBG:~C00 TradingValue '%s.target_pos' zero crossing protecion, setting target_pos to 0.0 instead %.5f, source: %s", $context->name, $target_pos, $source);                        
                        return $context->update('target_pos', 0, source: 'zero_cross_protection');
                    }
                    
                    $delta = $target_pos - $context->curr_pos; // позитивное значение, если нужен лонг
                    $bias = abs($delta);
                    $core = $context->core();
                    if ($context->verbosity > 1)
                        $core->LogMsg("~C93 #DBG:~C00 TradingValue '%s.target_pos' changed from %.5f to %.5f, setting bias to %.5f, source: %s", $context->name, $old, $target_pos, $bias, $source);                    
                    $context->get_var('bias')->set($bias, source: 'target_pos→bias');
                },
                'qty' => function (float $value, float $old, string $source) {                     
                    $context = $this->context();
                    $core = $context->core();
                    $ti = $context->tinfo;                    
                    $cost = $value * $context->price;
                    $decimals = $ti->is_btc_pair ? 7 : 2;
                    $cost = round($cost, $decimals);
                    if ($context->verbosity > 1)
                        $core->LogMsg("~C93 #DBG:~C00 TradingValue '%s.qty' changed to %.5f, cost to %.{$decimals}f source: %s", $context->name, $value, $cost, $source);

                    $context->get_var('cost')->set($cost, source: 'qty->cost');
                },
                default => null,
            };

            if (is_callable($handler))
                $this->onChange( $handler);             

        }
    } // end of class TradingValue

    /* Класс торгового контекста создается для каждой пары (тикера), по которой есть целевая позиция в боте. 
       Получается за каждый цикл в методе TradingCore::Trade для каждой активной пары создается свой временный экземпляр TradingContext, чтобы передавать его между разными методами. 
       Сводит все переменные необходимые для вычисления объема и направления заявки в одну структуру. 
    */

    final class TradingContext extends ValueTracer     {
        /** @var array<string, TradingValue> */
        private array $vars = [];


        /**
         * @var $last_dir_sign - Направление: 1 = buy, -1 = sell, 0 = нет действия
         *  */ 
        private int $last_dir_sign = 0;  // cached value

        /**         
         * @var  TradingCore $owner
         */
        public ?TradingCore $owner = null;

        public  TickerInfo $tinfo;

        protected ?OrdersBatch $batch = null;        

        public float $btc_price = 50000; // сегодня этот курс едва-ли актуален
        public float $price_coef = 1; // коэффициент цены (1 или цена BTC), для пересчета ограничений стоимости

        public $ignore_by = [];  // список причин для пропуска торгового цикла

        public int $verbosity = 0;

        public int $pair_id = 0; // TODO: duplicate of tinfo->pair_id

        public ?ExternalSignal $ext_sig = null;

        public  $name = 'saldo';

        public  $allow_zero_cross = false; // target_pos может иметь отрицательный знак относительно curr_pos

        public function __construct(TickerInfo $ti, float $curr_position) {
            global $g_bot;
            assert ( is_object($g_bot) );            
            $this->batch_id = -100;             
            $this->tinfo = $ti;            
            $this->pair_id = $ti->pair_id;
            $this->owner = $g_bot;                                  
            $this->define('curr_pos',   $curr_position);
            $this->define('target_pos', 0);
            $this->define('bias', 0.0, true);     
            $this->define('amount', 0.0, true);                  
            $this->define('cost', 0.0,  true);
            $this->define('cost_limit', 0.0,   true);
            $this->define('qty', 0.0,  true);
        }

        public function batch(mixed $update = false): ?OrdersBatch {
            if (is_null($update)) {
                if (is_object($this->batch))
                    $this->batch->Close();
                $this->batch = null;
                $this->batch_id = -100;
            }            

            if (is_null($this->batch) && is_int($update)) {                
                $this->batch_id = $update;
                $this->batch = $update > 0 ? $this->owner->Engine()->GetOrdersBatch($update, 3) : null;
                return $this->batch;
            }
            if (is_object($update) && $update instanceof OrdersBatch) {
                $this->batch = $update;
                $this->batch_id = $update->id; // affects changes log
            }

            return $this->batch;
        }

        public function core(): ?TradingCore {
            return $this->owner;
        }
        
        public function engine(): ?TradingEngine {
            return $this->core()->Engine();
        }


        public function ignore_trade(int $code, string $descr = '') {
            $this->ignore_by []= [$code, $descr];
        }

        public function init() {  
            $core = $this->owner;            
            $ti = $this->tinfo;
            $this->btc_price = $core->BitcoinPrice();
            $this->price_coef = $ti->is_btc_pair ? $this->btc_price : 1.0;     
            $this->split_trade = false;
            $this->ignore_by = [];
        }

        public function define(string $name, float $initial = 0.0, bool $unsigned = false): TradingValue  {
            $tv = $this->vars[$name] = new TradingValue($this, $initial, name: $name, unsigned: $unsigned);                        
            return $tv;
        }

        public function dump_limits() {
            $res = [];
            foreach ($this->vars as $name => $var) {
                if (count($var->limit_log) > 0)
                    $res[$name] = $var->limit_log;
            }
            $this->owner->LogMsg("~C93 #DBG(TradingContext):~C00 ::dump_limits for pair %s: %s", 
                $this->tinfo->symbol, print_r($res, true));
        }

        public function __get($name): float
        {
            if (!isset($this->vars[$name])) {
                return parent::__get($name);
            }

            $value = $this->vars[$name]->get();            
            return $value;
        }

        public function __set($name, $value): void
        {
            if (!isset($this->vars[$name])) {
                parent::__set($name, $value);
                return;
            }
            $var = $this->get_var($name);
            assert (is_object($var) and ($var instanceof TradingValue), "Wrong type variable: $name " . gettype($var));
            $source = format_backtrace();
            $var->set($value, $source);
            if ('target_pos' == $name && $var->zero_crossed) 
                $this->split_trade = true;
        }        
    
        // Направление — только для чтения
        public function trade_dir_sign(): int { 
            $dir = signval($this->target_pos - $this->curr_pos);
            /* if ($dir == -$this->last_dir_sign)
                throw new Exception("Trade direction reversed unexpectedly in TradingContext for pair ".$this->tinfo->pair);
            */
            if ($this->last_dir_sign != $dir && $this->verbosity > 1)
                $this->owner->LogMsg("~C93 #DBG(TradingContext):~C00 assigned trade direction = %d (target_pos %.5f, curr_pos %.5f)", 
                    $dir, $this->target_pos, $this->curr_pos);
            $this->last_dir_sign = $dir; 
            return $dir;
        }

        public function get_var(string $name): ?TradingValue {
            return $this->vars[$name] ?? null;
        }    
        public function is_buy(): bool  { return $this->trade_dir_sign() > 0; }
        public function is_sell(): bool { return $this->trade_dir_sign() < 0; }

        public function max_amount(float $value, ?ValueConstraint $c): float {        
            $ti = $this->tinfo;
            $notify = false;
            if (is_object($c)) {
                $bv = $c->owner;
                if ($bv instanceof TradingValue) {
                    $notify = $bv->name() == 'amount';
                }
            }
            $cost_limit = $this->cost_limit;    
            $engine = $this->owner->Engine();
            if ($this->price_coef > 1) // для битковых пар
                $cost_limit = $cost_limit / $this->price_coef;
            $max_qty = $cost_limit / $this->price;
            $max_amount = $engine->QtyToAmount($ti->pair_id, $this->price, $this->btc_price, $max_qty);
            if ($max_amount <= $this->min_amount) {
                $this->owner->LogMsg("~C91 #WARN:~C00 max_amount raw is small %.5f for pair %s, cost_limit %.5f, price %.5f, price_coef %.5f, assumed min_amount x 100", 
                                                $max_amount, $ti->pair, $cost_limit, $this->price, $this->price_coef);                
                return $this->min_amount * 100;                                                
            }
            $result     = $engine->LimitAmountByCost($ti->pair_id, $max_amount, $cost_limit, false); // применение определенных округлений движком
            if ($result <= $this->min_amount) {
                $this->owner->LogMsg("~C91 #WARN:~C00 max_amount computed by engine as small %.5f for pair %s, cost_limit %.5f, price %.5f, price_coef %.5f, assumed raw %.5f", 
                                                $result, $ti->pair, $cost_limit, $this->price, $this->price_coef, $max_amount);
                return $max_amount;                                                
            }
            return $result;
        }

        public function reset() {
            parent::reset();
            $this->batch(false);
        }

        public function trade_ready(): bool {
            return $this->amount > 0 && 0 == count($this->ignore_by);
        }

        public function update(string $name, float $value, string $source): float {  // возвращает обновленную переменную, после применения ограничителей
            $var = $this->get_var($name);
            assert (is_object($var), "No such TradingValue variable: $name");
            $var->set($value, source: $source);
            return $var->get();
        }

    } // end of class TradingContext
