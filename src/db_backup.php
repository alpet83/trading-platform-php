#!/usr/bin/php
<?php
    include_once('lib/common.php');
    include_once('lib/esctext.php');
    include_once('trading_common.php');
    include_once('lib/db_config.php');

    function cfg_value(array $cfg, string $key, string $default): string {
        if (isset($cfg[$key]) && is_string($cfg[$key]) && strlen(trim($cfg[$key])) > 0)
            return trim($cfg[$key]);
        return $default;
    }

    function cleanup_old_backups(string $dir, int $retentionDays): void {
        if (!is_dir($dir) || $retentionDays < 1)
            return;

        $cutoff = time() - $retentionDays * 24 * 3600;
        $list = @scandir($dir);
        if (!is_array($list))
            return;

        foreach ($list as $name) {
            if ($name === '.' || $name === '..')
                continue;
            if (!str_ends_with($name, '.sql.gz'))
                continue;

            $path = $dir . '/' . $name;
            $mtime = @filemtime($path);
            if (is_numeric($mtime) && $mtime < $cutoff)
                @unlink($path);
        }
    }

    $cfg = [];
    if (isset($argv[1])) {
        $tmp = json_decode($argv[1], true);
        if (is_array($tmp))
            $cfg = $tmp;
    }

    $dbHost = cfg_value($cfg, 'db_host', getenv('DB_LOCAL_HOST') ?: 'mariadb');
    $dbName = cfg_value($cfg, 'db_name', getenv('MARIADB_DATABASE') ?: 'trading');
    $dbUser = cfg_value($cfg, 'db_user', getenv('MARIADB_USER') ?: 'trading');
    $dbPass = cfg_value($cfg, 'db_pass', getenv('MARIADB_PASSWORD') ?: '');
    $backupDir = cfg_value($cfg, 'backup_dir', '/var/backup/mysql');
    $retentionDays = intval(cfg_value($cfg, 'backup_retention_days', '7'));

    if (!$dbPass && isset($db_configs['trading'][1]))
        $dbPass = strval($db_configs['trading'][1]);

    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        log_cmsg("~C91#BACKUP_FAIL:~C00 cannot create backup directory %s", $backupDir);
        exit(2);
    }

    $ts = gmdate('Ymd-His');
    $backupFile = sprintf('%s/%s-%s.sql.gz', rtrim($backupDir, '/'), $dbName, $ts);
    $tmpFile = $backupFile . '.tmp';

    $dumpBin = trim(shell_exec('command -v mariadb-dump 2>/dev/null'));
    if (!$dumpBin)
        $dumpBin = trim(shell_exec('command -v mysqldump 2>/dev/null'));

    if (!$dumpBin) {
        log_cmsg("~C91#BACKUP_FAIL:~C00 dump binary not found (mariadb-dump/mysqldump)");
        exit(3);
    }

    $cmd = sprintf(
        "%s -h %s -u %s -p%s --single-transaction --routines --events --databases %s | gzip -1 > %s",
        escapeshellcmd($dumpBin),
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName),
        escapeshellarg($tmpFile)
    );

    log_cmsg("~C93#BACKUP:~C00 creating backup %s (host=%s, db=%s)", $backupFile, $dbHost, $dbName);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

    if ($code !== 0 || !file_exists($tmpFile) || filesize($tmpFile) === 0) {
        @unlink($tmpFile);
        log_cmsg("~C91#BACKUP_FAIL:~C00 dump command failed (%d): %s", $code, implode("\n", $out));
        exit(4);
    }

    rename($tmpFile, $backupFile);
    cleanup_old_backups($backupDir, $retentionDays);
    log_cmsg("~C92#BACKUP_OK:~C00 saved %s", $backupFile);

    exit(0);
?>
