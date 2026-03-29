<?php

if (!function_exists('admin_otp_config_path')) {
    function admin_otp_config_path(): string {
        $path = trim((string)(getenv('OTP_CONFIG_FILE') ?: ''));
        if ($path !== '') {
            return $path;
        }
        return '/app/var/data/admin_otp.php';
    }
}

if (!function_exists('admin_otp_ttl_seconds')) {
    function admin_otp_ttl_seconds(): int {
        $raw = getenv('OTP_TTL_SECONDS');
        if ($raw === false || trim((string)$raw) === '') {
            return 900;
        }
        $v = intval($raw);
        return ($v < 0 ? 0 : $v);
    }
}

if (!function_exists('admin_otp_mode')) {
    function admin_otp_mode(): string {
        // State file overrides env so the mode can be toggled from the admin UI.
        $state = admin_otp_load_state();
        $fromState = strtolower(trim((string)($state['mode'] ?? '')));
        if (in_array($fromState, ['off', 'on', 'required'], true)) {
            return $fromState;
        }
        $fromEnv = strtolower(trim((string)(getenv('OTP_MODE') ?: 'off')));
        return in_array($fromEnv, ['off', 'on', 'required'], true) ? $fromEnv : 'off';
    }
}

if (!function_exists('admin_otp_default_state')) {
    function admin_otp_default_state(): array {
        return [
            'enabled' => false,
            'mode' => '',
            'token_hash' => '',
            'generated_at' => '',
            'expires_at' => '',
            'used_at' => '',
            'used_from' => '',
            'last_error' => '',
            'version' => 1,
        ];
    }
}

if (!function_exists('admin_otp_load_state')) {
    function admin_otp_load_state(): array {
        $path = admin_otp_config_path();
        if (!is_file($path)) {
            return admin_otp_default_state();
        }

        $state = @require $path;
        if (!is_array($state)) {
            return admin_otp_default_state();
        }

        return array_merge(admin_otp_default_state(), $state);
    }
}

if (!function_exists('admin_otp_save_state')) {
    function admin_otp_save_state(array $state): bool {
        $path = admin_otp_config_path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }

        $data = array_merge(admin_otp_default_state(), $state);
        $php = "<?php\nreturn " . var_export($data, true) . ";\n";
        return @file_put_contents($path, $php, LOCK_EX) !== false;
    }
}

if (!function_exists('admin_otp_generate_token')) {
    function admin_otp_generate_token(): string {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $raw = random_bytes(10);
        $token = '';
        $len = strlen($alphabet);
        for ($i = 0; $i < strlen($raw); $i++) {
            $token .= $alphabet[ord($raw[$i]) % $len];
        }
        return substr($token, 0, 10);
    }
}

if (!function_exists('admin_otp_activate_once')) {
    function admin_otp_activate_once(string $remote = ''): array {
        $token = admin_otp_generate_token();
        $now = gmdate('c');
        $ttl = admin_otp_ttl_seconds();
        $expires = ($ttl > 0) ? gmdate('c', time() + $ttl) : '';

        $state = admin_otp_load_state();
        $state['enabled'] = true;
        $state['token_hash'] = password_hash($token, PASSWORD_DEFAULT);
        $state['generated_at'] = $now;
        $state['expires_at'] = $expires;
        $state['used_at'] = '';
        $state['used_from'] = '';
        $state['last_error'] = '';

        if (!admin_otp_save_state($state)) {
            return ['ok' => false, 'error' => 'Cannot write OTP config file'];
        }

        error_log(sprintf('[ADMIN_OTP_TOKEN] token=%s generated_at=%s remote=%s', $token, $now, $remote));
        return [
            'ok' => true,
            'token' => $token,
            'expires_at' => $expires,
            'config_path' => admin_otp_config_path(),
        ];
    }
}

if (!function_exists('admin_otp_consume')) {
    function admin_otp_consume(string $token, string $remote = ''): array {
        $state = admin_otp_load_state();
        if (empty($state['enabled'])) {
            return ['ok' => false, 'error' => 'OTP is not activated'];
        }

        if (!empty($state['used_at'])) {
            return ['ok' => false, 'error' => 'OTP was already used'];
        }

        if (!empty($state['expires_at']) && strtotime((string)$state['expires_at']) !== false) {
            if (time() > strtotime((string)$state['expires_at'])) {
                $state['last_error'] = 'expired';
                admin_otp_save_state($state);
                return ['ok' => false, 'error' => 'OTP expired'];
            }
        }

        $hash = (string)($state['token_hash'] ?? '');
        if ($hash === '' || !password_verify($token, $hash)) {
            $state['last_error'] = 'mismatch';
            admin_otp_save_state($state);
            return ['ok' => false, 'error' => 'OTP mismatch'];
        }

        $state['used_at'] = gmdate('c');
        $state['used_from'] = $remote;
        $state['enabled'] = false;
        $state['last_error'] = '';
        admin_otp_save_state($state);

        return ['ok' => true];
    }
}
