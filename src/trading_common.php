<?php

if (!function_exists('tp_debug_tmp_path')) {
    function tp_debug_tmp_path(string $name, string $scope = 'runtime'): string {
        $base = rtrim((string)(getenv('TP_DEBUG_TMP_DIR') ?: '/app/var/tmp/debug'), '/');
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        $scope = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $scope);
        if (!is_string($scope) || '' === $scope) {
            $scope = 'runtime';
        }

        $name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($name));
        if (!is_string($name) || '' === $name) {
            $name = 'debug.tmp';
        }

        $dir = $base . '/' . $scope;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . $name;
    }
}
