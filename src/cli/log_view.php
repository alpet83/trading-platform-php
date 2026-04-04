<?php
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "CLI only\n");
        exit(1);
    }

    function lv_out(string $text = ''): void {
        fwrite(STDOUT, $text . "\n");
    }

    function lv_err(string $text): void {
        fwrite(STDERR, $text . "\n");
    }

    function lv_prompt(string $text): string {
        fwrite(STDOUT, $text);
        $line = fgets(STDIN);
        return is_string($line) ? trim($line) : '';
    }

    function lv_pick(array $items, string $title): int {
        lv_out('');
        lv_out($title);
        foreach ($items as $idx => $label)
            lv_out(sprintf("  %d) %s", $idx + 1, $label));

        while (true) {
            $raw = lv_prompt('Select number (0 to exit): ');
            if ($raw === '0')
                return -1;
            if (ctype_digit($raw)) {
                $i = intval($raw) - 1;
                if ($i >= 0 && $i < count($items))
                    return $i;
            }
            lv_err('Invalid selection, try again.');
        }
    }

    function lv_find_log_root(): string {
        $candidates = [
            trim((string)(getenv('LOG_DIR') ?: '')),
            '/app/var/log',
            __DIR__ . '/../../var/log',
            __DIR__ . '/../var/log',
            '/var/log',
        ];

        foreach ($candidates as $dir) {
            if (!is_string($dir) || trim($dir) === '')
                continue;
            $dir = rtrim($dir, '/');
            if (!is_dir($dir))
                continue;
            if (is_file($dir . '/bot_manager.log') || is_dir($dir . '/bitmex_bot') || is_dir($dir . '/bybit_bot'))
                return $dir;
        }

        return '';
    }

    function lv_latest_session_dir(string $accountDir): string {
        $items = @scandir($accountDir);
        if (!is_array($items))
            return '';

        $dates = [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..')
                continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name))
                continue;
            $path = $accountDir . '/' . $name;
            if (is_dir($path))
                $dates[] = $name;
        }

        if (count($dates) === 0)
            return '';

        rsort($dates, SORT_STRING);
        return $accountDir . '/' . $dates[0];
    }

    function lv_collect_bot_targets(string $logRoot): array {
        $targets = [];
        $items = @scandir($logRoot);
        if (!is_array($items))
            return $targets;

        foreach ($items as $impl) {
            if ($impl === '.' || $impl === '..')
                continue;
            if (!str_ends_with($impl, '_bot'))
                continue;

            $implDir = $logRoot . '/' . $impl;
            if (!is_dir($implDir))
                continue;

            $accounts = @scandir($implDir);
            if (!is_array($accounts))
                continue;

            foreach ($accounts as $acc) {
                if (!ctype_digit($acc))
                    continue;

                $accDir = $implDir . '/' . $acc;
                if (!is_dir($accDir))
                    continue;

                $sessionDir = lv_latest_session_dir($accDir);
                if ($sessionDir === '')
                    continue;

                $targets[] = [
                    'impl' => $impl,
                    'account_id' => $acc,
                    'session_dir' => $sessionDir,
                    'label' => sprintf('%s @ %s (%s)', $impl, $acc, basename($sessionDir)),
                ];
            }
        }

        usort($targets, function (array $a, array $b): int {
            return strcmp($a['label'], $b['label']);
        });

        return $targets;
    }

    function lv_unique_files(array $files): array {
        $seen = [];
        $out = [];
        foreach ($files as $f) {
            $key = realpath($f);
            if (!is_string($key) || $key === '')
                $key = $f;
            if (isset($seen[$key]))
                continue;
            $seen[$key] = true;
            $out[] = $f;
        }
        return $out;
    }

    function lv_is_regular_file(string $path): bool {
        return is_file($path) && !is_link($path);
    }

    function lv_mtime_safe(string $path): int {
        $ts = @filemtime($path);
        return ($ts === false) ? 0 : intval($ts);
    }

    function lv_label_with_mtime(string $path): string {
        $ts = lv_mtime_safe($path);
        $mtime = $ts > 0 ? date('Y-m-d H:i:s', $ts) : 'mtime:unknown';
        return basename($path) . ' [' . $mtime . ']';
    }

    function lv_collect_session_files(string $sessionDir): array {
        $files = [];
        $prefixes = ['core', 'engine', 'errors', 'order'];

        foreach ($prefixes as $prefix) {
            $current = $sessionDir . '/' . $prefix . '.log';
            if (lv_is_regular_file($current))
                $files[] = $current;

            $glob = glob($sessionDir . '/' . $prefix . '_*.log');
            if (is_array($glob) && count($glob) > 0) {
                $glob = array_values(array_filter($glob, fn(string $f): bool => lv_is_regular_file($f)));
                if (count($glob) === 0)
                    continue;
                usort($glob, function (string $a, string $b): int {
                    return lv_mtime_safe($b) <=> lv_mtime_safe($a);
                });
                $files[] = $glob[0];
            }
        }

        $all = glob($sessionDir . '/*.log');
        if (is_array($all) && count($all) > 0) {
            $all = array_values(array_filter($all, fn(string $f): bool => lv_is_regular_file($f)));
            if (count($all) === 0)
                return lv_unique_files($files);
            usort($all, function (string $a, string $b): int {
                return lv_mtime_safe($b) <=> lv_mtime_safe($a);
            });
            foreach (array_slice($all, 0, 12) as $f)
                $files[] = $f;
        }

        return lv_unique_files($files);
    }

    function lv_collect_manager_files(string $logRoot): array {
        $names = [
            'bot_manager.log',
            'bot_manager.errors.log',
            'bot_instance.errors.log',
            'php_errors_bots_hive.log',
            'php_errors_web.log',
            'php_errors_datafeed.log',
            'php_errors_signals_legacy.log',
            'traceback.log',
        ];

        $files = [];
        foreach ($names as $name) {
            $f = $logRoot . '/' . $name;
            if (is_file($f))
                $files[] = $f;
        }

        return $files;
    }

    function lv_has_less(): bool {
        $out = [];
        $code = 1;
        @exec('command -v less 2>/dev/null', $out, $code);
        return $code === 0 && count($out) > 0;
    }

    function lv_open_less(string $file): int {
        if (!lv_is_regular_file($file))
            return 2;

        // Force pager to use terminal device directly, otherwise less can exit
        // immediately after prompt reads in some docker/tty combinations.
        if (is_readable('/dev/tty')) {
            $desc = [
                0 => ['file', '/dev/tty', 'r'],
                1 => ['file', '/dev/tty', 'w'],
                2 => ['file', '/dev/tty', 'w'],
            ];
            $proc = proc_open(['less', '-f', '-r', $file], $desc, $pipes);
            if (is_resource($proc))
                return intval(proc_close($proc));
        }

        $cmd = 'less -fr ' . escapeshellarg($file);
        passthru($cmd, $code);
        return intval($code);
    }

    $logRoot = lv_find_log_root();
    if ($logRoot === '') {
        lv_err('#ERROR: log root was not detected. Set LOG_DIR env or run inside project/container runtime.');
        exit(1);
    }

    if (!lv_has_less()) {
        lv_err('#ERROR: less not found in PATH. Install less or run in container where less is available.');
        exit(1);
    }

    lv_out('#INFO: log root = ' . $logRoot);

    while (true) {
        $sourceIdx = lv_pick([
            'Specific bot (impl_name + account_id)',
            'Bot manager / platform logs',
        ], 'Choose log source:');

        if ($sourceIdx < 0) {
            lv_out('Exit.');
            exit(0);
        }

        if ($sourceIdx === 0) {
            $targets = lv_collect_bot_targets($logRoot);
            if (count($targets) === 0) {
                lv_err('#ERROR: no bot sessions found in ' . $logRoot);
                continue;
            }

            $botLabels = array_map(fn(array $t): string => $t['label'], $targets);

            while (true) {
                $botIdx = lv_pick($botLabels, 'Choose bot session target:');
                if ($botIdx < 0)
                    break;

                $sessionDir = $targets[$botIdx]['session_dir'];
                $files = lv_collect_session_files($sessionDir);

                if (count($files) === 0) {
                    lv_err('#ERROR: no session log files found in ' . $sessionDir);
                    continue;
                }

                $fileLabels = array_map(fn(string $f): string => lv_label_with_mtime($f), $files);

                while (true) {
                    $fileIdx = lv_pick($fileLabels, 'Choose log file to open via less -rf:');
                    if ($fileIdx < 0)
                        break;
                    lv_open_less($files[$fileIdx]);
                }
            }

            continue;
        }

        // manager / platform logs
        $files = lv_collect_manager_files($logRoot);
        if (count($files) === 0) {
            lv_err('#ERROR: manager/platform log files not found in ' . $logRoot);
            continue;
        }

        $fileLabels = array_map(function (string $f): string {
            return lv_label_with_mtime($f);
        }, $files);

        while (true) {
            $fileIdx = lv_pick($fileLabels, 'Choose manager/platform log file to open via less -rf:');
            if ($fileIdx < 0)
                break;
            lv_open_less($files[$fileIdx]);
        }
    }
?>