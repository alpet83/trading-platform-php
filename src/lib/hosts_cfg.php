<?php
    if (!defined('SIGNALS_API_URL')) {
        $rawSignalsApiUrl = getenv('SIGNALS_API_URL') ?: 'http://localhost';
        define('SIGNALS_API_URL', rtrim($rawSignalsApiUrl, '/'));
    }

    $msg_servers = [SIGNALS_API_URL];

    function signals_api_base_url(): string {
        return SIGNALS_API_URL;
    }

    function signals_api_url(string $path = ''): string {
        $base = rtrim(SIGNALS_API_URL, '/');
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        return $base . '/' . ltrim($path, '/');
    }


    function signals_api_host(): string {
        $host = parse_url(SIGNALS_API_URL, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
        return 'localhost';
    }
    
?>