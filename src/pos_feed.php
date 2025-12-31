<?php
class PositionFeed {
    public $applicant;
    protected $trade_core;
    protected $feed_server = 'http://vps.vpn'; // must be specified in /etc/hosts

    public $fails = 0;

    function  __construct($core) {
      $this->trade_core = $core;
    }

    function Initialize () {
      $this->feed_server = $this->trade_core->ConfigValue('position_feed_url', $this->feed_server);
    }

    function LoadPairsConfig(&$trade_coef_map): array {
      $exch = $this->Engine()->exchange;
      if (!$exch)
        throw new Exception('PositionFeed.LoadPairsConfig - configuration value `exchange` is not set');

      $exch = strtolower($exch);
      $core = $this->trade_core;
      $field = $exch.'_pair';
      $cache_file = 'data/pairs_map.json';
      // эти настройки регулируются в БД выдачи позиций, таблица pairs_map
      $online = true;
      $json = curl_http_request($this->feed_server.'/pairs_map.php?field='.$field);
      
      if (strpos($json, '#ERROR') !== false || strpos($json, '#FAILED') !== false) {
         $online = false;
         $core->LogError("~C91#ERROR:~C00 PositionFeed.LoadPairsConfig - load remote data failed from {$this->feed_server}: $json, will use local cache");
         $core->send_event('ALERT', "Network trouble, cannot load pairs map from feed: $json");
         $json = file_get_contents($cache_file);
      }
         
      $cfg = json_decode($json, true);

      $result = [];

      $loaded = 0;
      foreach ($cfg as $id => $row) {
          if (count($row) < 2) continue;
          $result [$id] = $row[0];
          $trade_coef_map[$id] = $row[1];
          $core->LogMsg("~C97#LOAD_PAIRS_MAP:~C00 Pair %s trade_coef = %.7f ", $row[0], $row[1]);        
          if ($online) $loaded ++;
      }       
      if ($loaded > 0) {
          file_put_contents($cache_file, $json);                
          $core->LogMsg("~C97#LOAD_PAIRS_MAP:~C00 Loaded %d pairs, saved to %s", $loaded, $cache_file);
      }    

      return $result;
    }

    function LoadPositions($after_ts = false) {  // load target positions
      $url = $this->feed_server.'/lastpos.php?';
      $core = $this->trade_core;
      if ($after_ts)
          $url .= 'updated_after='.urlencode($after_ts);
      if ($this->fails >= 5 && date('i') % 10 < 9)  return false;

      while (date('s') < 10 && date('s') > 50) sleep(1); // желательно запросить данные около начала минуты, после обновления таблицы
      $json = curl_http_request($url);
      if (strpos($json, '#ERROR') !== false) {
         $core->LogError('~C91#FAILED:~C00 PositionFeed.LoadPositions - load remote data failed from '.$this->feed_server.': '.$json);
         $this->fails++;
         return false;
      }
      if (strpos($json, '#FORBIDDEN') !== false) {
        $core->LogError('~C91#FAILED:~C00 PositionFeed.LoadPositions - load remote data restricted from '.$this->feed_server.': '.$json);
        $core->Shutdown("Position feed is restricted for this host");
        $core->trade_enabled = false;
        $core->send_event('ALERT', "Network misconfiguration, cannot load positions from feed: $json");
        $this->fails++;
        return false;
     }

      $result = json_decode($json, true);
      file_put_contents('data/source_pos.json', $json);
      if (is_array($result))
         $core->SetTargetPositions($result);
      else  {
         $core->LogError("~C91#FAILED:~C00 PositionFeed.LoadPositions - remote data not JSON/array from ".$this->feed_server.': '.$json);   
         $this->fails++;
      }   
      // $core->LogObj($result, '  ', 'target_pos');
      return $result;
    }

    protected function  Engine() {
      return $this->trade_core->Engine();
    }

  }