<?php
/* класс TradeConfig - загружает и сохраняет настройки торговли в БД.
     Определяется какие инструменты, каким образом могут торговаться.
  */

class TradeConfig implements ArrayAccess {
    public     $raw_config     = [];
    public     $allowed_pairs  = [];
    public     $config_table   = '';
    public     $position_coef  = 0.0;
    public     $shorts_mult    = 1.0;  // multiplier for short positions
    public     $db_tables      = [];
    public     $min_order_cost = 50;
    public     $max_order_cost = 1000; // prevent big orders
    public     $working_source = [];
    protected  $trade_core     = null;
    protected  $updated        = false;

    function  __construct($core) {
      $this->trade_core = $core;
      $this->working_source [56] = 1; // TODO: load from config!
    }

    public function offsetExists(mixed $key): bool {
      return isset($this->raw_config[$key]);
    }

    public function offsetSet(mixed $key, mixed $value): void {
      if (is_null($key))
        throw new Exception('Adding config value witout index not allowed');

      if (isset($this->raw_config[$key]))
        $this->updated = ( $value != $this->raw_config[$key] );
      else
        $this->updated = true;

      $this->raw_config[$key] = $value;
    }


    public function offsetUnset(mixed $key): void {
      unset($this->raw_config[$key]);
    }

    public function offsetGet(mixed $key): mixed {
      return $this->GetValue($key, null);
    }

    public function GetValue($key, $default = false) {
       if (is_array($this->raw_config) && isset($this->raw_config[$key]))
         return $this->raw_config[$key];
       else
         return $default;
    }

    public function Init(string $bot_name) {
      $mysqli = $this->trade_core->mysqli;
      $this->db_tables = $mysqli->select_map('table_key, table_name', 'config__table_map', "WHERE applicant = '$bot_name'");
      if (!is_array($this->db_tables) || 0 == count(array_keys($this->db_tables)))
          throw new Exception('TradeConfig.Init failed retrieve data from table_map: '.$mysqli->error);

      if (isset($this->db_tables['config']) && strlen($this->db_tables['config']) > 3) 
          $this->config_table = "`{$this->db_tables['config']}`";         
      else
          throw new Exception('TradeConfig.Init failed retrieve config table name from config__table_map. Loaded map: '.print_r($this->db_tables, true)) ;      
    }

    public function Load(int $acc_id) {
      $core = $this->trade_core;
      $mysqli = $core->mysqli;
      $core->LogMsg("#LOAD_CONFIG: using bot configuration table %s, account_id = $acc_id ", $this->config_table);         
      $this->raw_config = $mysqli->select_map('param,value', $this->config_table, "WHERE account_id = $acc_id");
      if (!$this->raw_config)
           throw new Exception("TradeConfig.Load failed retrieve config_map from table {$this->config_table} and account ".var_export($acc_id, true)); 

      $this->position_coef = 1.0 * $this->GetValue('position_coef', 0.01);
      $this->shorts_mult   = 1.0 * $this->GetValue('shorts_mult',   1.0);
      $this->min_order_cost = 1.0 * $this->GetValue('min_order_cost', $this->min_order_cost);
      $this->max_order_cost = 1.0 * $this->GetValue('max_order_cost', $this->min_order_cost);      
    }

    function LoadValue(string $key, $acc_id = false) {
      $core = $this->trade_core;
      if (false == $acc_id) 
          $acc_id = $core->Engine()->account_id;

      return $core->mysqli->select_value('value',  $this->config_table, "WHERE (param = '$key') and (account_id = $acc_id)");
    }
    public function SaveValue(string $key, $value, $acc_id = false) {
      $core = $this->trade_core;
      if (false == $acc_id) 
          $acc_id = $core->Engine()->account_id;

      // internal upgrade
      if (isset($this->$key))
        $this->$key = $value;
      else
        $this->raw_config[$key] = $value;                

      $query = "INSERT INTO {$this->config_table} (account_id, `param`, `value`)\n";
      if (is_bool($value))  
          $value = $value ? '1' : '0';      
      elseif (is_string($value))
          $value = "'$value'";
      $query .= "VALUES ($acc_id, '$key', $value)\n";
      $query .= "ON DUPLICATE KEY UPDATE `value` = $value;\n";
      
      

      return $core->mysqli->try_query($query);
    }

    public function Save(string $name = 'all') {
      if (!$this->updated) return false;      
      // TODO: need implementation
      $fset = [];
      $keys =  [];
      if ('all' == $name)  
        $keys = array_keys($this->raw_config);
      elseif (isset($this->raw_config[$name])) 
        $keys = [$name];
      if (0 == count($keys)) return false;       
      foreach ($keys as $key) {
            $value = $this->raw_config[$key];
            if (is_numeric($value))
                $fset[] = "SET `$key` = $value";
            elseif (is_bool($value))  
                $fset[] = "SET `$key` = ".($value ? '1' : '0');
            elseif (is_string($value))
                $fset[] = "SET`$key` = '$value'";              
      }               
         
      $query = "UPDATE {$this->config_table} ".implode(', ', $fset)."\n";         
      if ($this->trade_core->mysqli->try_query($query)) {
         $this->updated = false;
         return true;
      }
      return false;
    }

  }