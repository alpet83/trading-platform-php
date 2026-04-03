<?php
    // Log retention cleanup — invoked by bot_manager.php once per UTC day at midnight.
    // Usage: php log_cleanup.php [log_root [retention_days]]
    //
    // Removes regular files older than $retention_days and empty directories left
    // behind. Symlinks are never removed (logs.td, current.log, etc.).

    $log_root       = (isset($argv[1]) && $argv[1] !== '') ? $argv[1] : '/app/var/log';
    $retention_days = (isset($argv[2]) && ctype_digit($argv[2])) ? (int)$argv[2] : 7;
    $cutoff         = time() - ($retention_days * 86400);

    $removed_files  = 0;
    $removed_dirs   = 0;

    function lc_cleanup_dir(string $dir, int $cutoff, int &$rf, int &$rd): void {
        $items = @scandir($dir);
        if ($items === false)
            return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..')
                continue;

            $path = $dir . '/' . $item;

            if (is_link($path))
                continue;   // preserve symlinks

            if (is_dir($path)) {
                lc_cleanup_dir($path, $cutoff, $rf, $rd);
                // Remove directory if now empty
                $remaining = array_diff((array)@scandir($path), ['.', '..']);
                if (empty($remaining)) {
                    if (@rmdir($path))
                        $rd++;
                }
            } elseif (is_file($path) && @filemtime($path) < $cutoff) {
                if (@unlink($path))
                    $rf++;
            }
        }
    }

    if (!is_dir($log_root)) {
        fwrite(STDERR, sprintf("#LOG_CLEANUP: log root '%s' is not a directory, skipping.\n", $log_root));
        exit(0);
    }

    lc_cleanup_dir($log_root, $cutoff, $removed_files, $removed_dirs);

    printf(
        "#LOG_CLEANUP: removed %d file(s) and %d empty dir(s) older than %d days from %s\n",
        $removed_files,
        $removed_dirs,
        $retention_days,
        $log_root
    );
