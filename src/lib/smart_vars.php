<?php
    require_once 'common.php';

    final class ValueConstraint {
        public ?BoundedValue $owner;
        public string $op;
        public string $descr;
        public mixed  $limit;
        public $on_trigger;

        public function __construct(BoundedValue $owner, string $operation, string $descr, mixed $limit) {
            $this->owner = $owner;
            $this->op = $operation;
            $this->descr = $descr;
            $this->limit = $limit;            
        }
    }

    class BoundedValue
    {
        protected float $value;
        protected string $name;
        
        protected bool $unsigned = false;

        /** @var array<int, array{op:string, descr:string, limit:mixed, on_trigger:callable}> */
        protected array $constraints = [];

        /** @var callable[] */
        protected array $onChange = [];

        public array $limit_log = [];

        public bool $zero_crossed = false;

        public ?stdClass $owner = null; 

        public function __construct(float $initial = 0.0, string $name = 'unnamed', bool $unsigned = false) {
            $this->value = $initial;
            $this->name = $name;            
            $this->unsigned = $unsigned;
        }

        public function __toString() {
            return var_export($this->value, true);
        }

        /**
         * Универсальный параметрический лимитер
         * Можно вызывать сколько угодно раз — все лимитеры применяются последовательно
         */
        public function constrain(string $operation, string $descr, mixed $limit, ?callable $on_trigger = null): self {
            $constraint =  new ValueConstraint($this, $operation, $descr, $limit);                
            $constraint->on_trigger = $on_trigger ?? $this->defaultTrigger($constraint);            
            $this->constraints[] = $constraint;              
            return $this;
        }

        public function onChange(callable $callback): self {            
            if (is_callable($callback))
                $this->onChange []= $callback; // жесткая привязка к объекту обработчику           
            else
                printf("#ERROR: Failed to bind onChange handler type %s for %s\n", gettype($callback), $this->name);
            return $this;
        }

        public function get(): float {
            return $this->value;
        }

        public function set(float $value, string $source = 'unknown'): self {            

            if ($this->unsigned && $value < 0)
                throw new RuntimeException("BoundedValue {$this->name} is unsigned and cannot be set to negative value {$value}");

            $old = $this->value;
            // в первую очередь применяются ограничители
            foreach ($this->constraints as $c) {
                assert ($c instanceof ValueConstraint);
                $limit = is_callable($c->limit) ? call_user_func($c->limit, $value, $c) : $c->limit;
                $handler = $c->on_trigger;
                $op = $c->op;
                $descr = $c->descr;

                $violated = match ($op) {
                    'max_abs'     => abs($value) > $limit,
                    'max'         => $value > $limit,
                    'min'         => $value < $limit,
                    'floor'       => $value > $limit,
                    'ceil'        => $value < $limit,
                    'multiple_of' => $limit > 0 && fmod(abs($value), $limit) != 0,
                    'zero_cross'  => ($old > 0 && $value < 0) || ($old < 0 && $value > 0),
                    'callback'    => call_user_func($c->limit, $value, $c),                    
                    default       => false,
                };

                if ($violated) 
                try {
                    $this->limit_log []= sprintf("[%s]: constraint %s:%s with limit %f violated (tried to set %f over %f)", 
                                                 $this->name, $descr, $op, $limit, $value, $old);
                    $this->zero_crossed |= ($op == 'zero_cross');                                                 
                    $value = $handler($value, $limit, $c, $source);
                }
                catch (Throwable $e) {
                    $this->limit_log []= sprintf("Exception in constraint handler for %s:%s - %s", 
                                                 $this->name, $descr, $e->getMessage());    
                }
            }
            // если после ограничений значение не изменилось, выходим - обновление зависимостей не требуется
            if ($this->value === $value) {
                return $this;
            }            

            $trace = format_backtrace();
            $source = str_replace('%BACKTRACE%', $trace, $source);            

            $this->value = $value;
            if ($value != 0)
                $this->zero_crossed = false; // filter not applied, reset flag
            
            // обработка изменения, позволяет пересчитать все зависимые переменные
            foreach ($this->onChange as $cb) 
                try {
                    call_user_func($cb, $value, $old, $source);
                    // $cb($value, $old, $source);    // $this может быть включен в $cb, от самой переменной
                }                         
                catch (Throwable $e) {
                    if (is_array($cb) && count($cb) > 1)
                        printf("Exception in onChange handler '%s' for %s - %s\n ", 
                            var_export($cb[1], true), $this->name, $e->getMessage());
                    else
                        printf("Exception in onChange handler '%s' for %s - %s\n ", 
                            gettype($cb).":".print_r($cb, true), $this->name, $e->getMessage());
                }
            return $this;
        }

        private function defaultTrigger(ValueConstraint $constraint): callable {
            $operation = $constraint->op;
            return match ($operation) {                
                'max_abs' => fn($v, $l) => $l * ($v > 0 ? 1 : -1),
                'max'     => fn($v, $l) => $l,
                'min'     => fn($v, $l) => $l,
                'floor'   => fn($v, $l) => $l,
                'ceil'    => fn($v, $l) => $l,
                'multiple_of' => fn($v, $l) => round($v / $l) * $l,
                'zero_cross' => fn($v, $l) => $l,
                default   => fn($v) => $v,
            };
        }

        public function abnormalTrigger(ValueConstraint $constraint): callable {
            $operation = $constraint->op;
            $descr = $constraint->descr;
            return fn($v, $l, $c) => 
                    throw new RuntimeException("BoundedValue {$this->name} violated constraint {$operation}:{$descr} with limit {$l} (tried to set {$v} over {$this->value})");
        }

        public function name() {
            return $this->name;
        }
    } // class BoundedValue <<<<<<<<<


    /**
     * ArrayTracer class
     * Мигрировал из ext_signal.php, позволяет логировать изменения значений массива
     */
    class ArrayTracer implements ArrayAccess, Countable {
        protected $values = [];
        public    $changes = [];

        public    $ts = '';
        public   $log_changes = false;
        public   $log_unset = true;

        public function __construct() {
            $this->ts = date('Y-m-d H:i:s');
            $this->changes []= "created at {$this->ts}";
        }
        public function count(): int {
            return count($this->values);
        }
        public function __get ( $key ) { // alternate access
            if (array_key_exists($key, $this->values))
                return $this->values[$key];
        return 0;  
        }
        public function __isset ( $key ) {
            return isset ($this->values[$key]);
        }

        public function __toString(){
            return json_encode($this->values);
        }

        public function offsetExists(mixed $offset): bool {
            return isset($this->values[$offset]);
        }

        public function offsetGet(mixed $offset): mixed {
            if (isset($this->values[$offset]))
                return $this->values[$offset];
        return null;
        }

        public function offsetSet(mixed $offset, mixed $value): void {
            if (isset($this->values[$offset]) && isset($value)) {                
                if (is_numeric($value)) {
                    $value = $value ?? 0; // VisualStudio fix wrong warning
                    if ($this->values[$offset] - $value == 0) return;
                }
                elseif ($value === $this->values[$offset]) return;
            }  
            $this->values[$offset] = $value;
            if ($this->log_changes && !is_object($value))
                $this->changes []= [ var_export($offset,true) .'=>'.var_export($value, true), format_backtrace()];
        }    

        public function offsetUnset(mixed $offset): void {
            if ($this->log_unset)
                $this->changes []= [ 'unset '. var_export($offset,true),  format_backtrace()];
            unset($this->values[$offset]);
        }

        public function raw(): array     {
            return $this->values;
        }

        public function reset() {
            $this->changes = [];
            $this->ts = date('Y-m-d H:i:s');
            $this->changes []= "reseted at {$this->ts}";
        }
    }

    /**
     * Класс хранилища отслеживаемых простых переменных. Позволяет логировать изменения значений.
     */

    class ValueTracer extends stdClass {
        protected $values = [];
        public    $changes = [];

        public    $logger = null;
        public function __get ( $key ) {
            if (array_key_exists($key, $this->values))
                return $this->values[$key];
            return 0;  
        }
        public function __isset ( $key ) {
            return isset ($this->values[$key]);
        }

        public function __set ($key, $value) {
            if (isset($this->values[$key])) {
                if (is_numeric($value) && abs($value - $this->values[$key]) == 0) return;
                if ($value === $this->values[$key]) return;
            }  
            $this->values[$key] = $value;
            if (is_numeric($value) || is_string($value) || is_bool($value)) {
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0];
                $kv = sprintf("%20s => %20s", $key, $value);
                $loc = sprintf("%s:%d", $trace['file'], $trace['line']);
                $this->changes []= [$kv, $loc];
            }    
        }    

        public function __toString() {
            $vals = [];
            foreach ($this->values as $key => $value)
                if (is_object($value))  {
                    if (method_exists($value, '__toString'))
                        $vals [$key] = strval($value);
                    else
                        $vals [$key] = get_class($value)." object";
                }
                elseif (is_array($value))
                    $vals [$key] = sprintf("array size %d", count($value));
                elseif (!is_callable($value))
                    $vals [$key] = $value;                   
            
            return json_encode($vals, 0, 1);
        }
        public function dump_changes(string $kfilter = "") {
            foreach ($this->changes as $line)
            if (str_in($line[0], $kfilter) && is_object($this->logger)) 
                $this->logger->LogMsg("TRACE: %s", $line[0]." at ".$line[1]); 
        }

        public function reset() {
            $this->changes = [];
        }
  }
?>