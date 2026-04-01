<?php
    $cm_map = [];
    $cm_symbols = [];  

    function resolve_cm_symbols_url(): string {
      $candidates = [
        getenv('SIGNALS_CM_SYMBOLS_URL') ?: '',
        getenv('CM_SYMBOLS_URL') ?: '',
      ];

      foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
          return $candidate;
        }
      }

      return '';
    }

    function load_symbols(string $json, string $source = '', float $elps = 0 )  {
      global $cm_map, $curl_resp_header;
      $src = json_decode($json);
      // file_put_contents('/tmp/cm_symbols.json', $json); 
      $count = 0;
      if (is_array($src))
      foreach ($src as $rec) {
        $sym = trim(strtoupper($rec->symbol));
        if ( isset($cm_map[$sym]) && 
            $rec->ts_updated <= $cm_map[$sym]->ts_updated ) continue; // ignore oldest
        $cm_map[$sym] = $rec;
        $count ++;
      } 
      else 
        printf("<!-- failed/added load_symbols('$json', '$source', %.1f);  -->\n", $elps);   
      if ($count > 0)
        printf("<!-- load_symbols: from $source added/updated $count symbols, total = %d  in %.2f seconds; headers:\n $curl_resp_header-->\n", count($cm_map), $elps);
    }  

    $json0 = '[]';
    $elps0 = 0.0;
    $cm_symbols_url = resolve_cm_symbols_url();
    if ($cm_symbols_url !== '') {
      $t_start = pr_time();
      $json0 = curl_http_request($cm_symbols_url);
      $elps0 = pr_time() - $t_start;
    }
    $t_start = pr_time();
    $json1 = '[]'; // curl_http_request('http://vm-office.vpn/cm_symbols.php');
    $elps1 = pr_time() - $t_start;

    if (false === strpos($json0, '#ERROR') && false === strpos($json0, '#FATAL'))
        load_symbols($json0, 'local', $elps0); 

    if (false === strpos($json1, '#ERROR') && false === strpos($json1, '#FATAL'))
        load_symbols($json1, 'remote', $elps1);  

    $list = array_values($cm_map); // only records
    // 
    $bad_sym = array('GOMINING');

    $skipped = [];

    foreach ($cm_map as $sym => $rec)
    if (is_object($rec))  {      
        $sym .= 'USD';
        $sym = str_replace('ETHDYDX', 'DYDX', $sym);
        $name = strtoupper(trim($rec->name));
        // TODO: use bad symbols filter table
        if ($sym == 'GMT' && $name != 'GMT') continue; 
        if (false !== array_search($name, $bad_sym)) continue; 

        if (!isset($pairs_map[$sym])) {
          $skipped []= $sym;
          continue;
        }    
        $id = $pairs_map[$sym];
        $cm_symbols[$id] = $rec;
    }
    $used = array_keys($cm_symbols);
    if (rqs_param("debug", false))
        printf("<!-- pairs_map: %s\n    used: %s\n, skipped: %s -->\n", json_encode($pairs_map), json_encode($used), json_encode($skipped));
