<?php
/** Secrets belong in ignored local.php or production.php, not this file. */
function secureposConfigFailure(): void
{
    http_response_code(503);
    exit('SecurePOS configuration is unavailable. Please contact the administrator.');
}

function secureposSettings(): array
{
    static $settings;
    if (isset($settings)) {
        return $settings;
    }
    $environment = getenv('SECUREPOS_ENV');
    if ($environment === false || $environment === '') {
        $environment = is_file(__DIR__ . '/production.php') ? 'production' : 'local';
    }
    if (!in_array($environment, ['local', 'production'], true)) {
        secureposConfigFailure();
    }
    $settings = ['environment' => $environment];
    $file = __DIR__ . '/' . $environment . '.php';
    if (is_file($file)) {
        $values = require $file;
        if (!is_array($values)) {
            secureposConfigFailure();
        }
        $settings = array_merge($settings, $values);
    } elseif ($environment === 'local' && PHP_SAPI === 'cli') {
        $settings += ['db_host' => 'localhost', 'db_port' => 3306,
            'db_name' => 'securepos', 'db_user' => 'root', 'db_password' => ''];
    } elseif ($environment === 'local' && PHP_SAPI === 'cli') {
        secureposConfigFailure();
    } elseif ($environment === 'local') {
        secureposConfigFailure();
    }
    // Missing production configuration must not use local defaults publicly.
    if ($environment === 'local' && PHP_SAPI !== 'cli') {
        $address = (string)($_SERVER['SERVER_ADDR'] ?? '');
        $privateAddress = filter_var($address, FILTER_VALIDATE_IP) !== false
            && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if (!$privateAddress && $address !== '::1') {
            secureposConfigFailure();
        }
    }
    return $settings;
}

function secureposSetting(string $name, $default = null)
{
    $names = ['base_url' => 'SECUREPOS_BASE_URL', 'db_host' => 'SECUREPOS_DB_HOST',
        'db_port' => 'SECUREPOS_DB_PORT', 'db_name' => 'SECUREPOS_DB_NAME',
        'db_user' => 'SECUREPOS_DB_USER', 'db_password' => 'SECUREPOS_DB_PASSWORD',
        'face_template_key' => 'SECUREPOS_FACE_TEMPLATE_KEY', 'leave_storage' => 'SECUREPOS_LEAVE_STORAGE'];
    if (isset($names[$name])) {
        $value = getenv($names[$name]);
        if ($value !== false) {
            return $value;
        }
    }
    return secureposSettings()[$name] ?? $default;
}

function secureposIsProduction(): bool
{
    return secureposSetting('environment') === 'production';
}

function secureposRequestIsHttps(): bool
{
    if ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $loopbackTunnel = ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
        && ($_SERVER['SERVER_ADDR'] ?? '') === '127.0.0.1'
        && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        && ($_SERVER['HTTP_CF_VISITOR'] ?? '') === '{"scheme":"https"}';
    if ($loopbackTunnel) {
        return true;
    }
    // Forwarded headers are accepted only from explicitly trusted proxies.
    $trusted = secureposSetting('trusted_proxy_ips', []);
    return is_array($trusted) && in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function secureposBaseUrl(): string
{
    $configured = trim((string)secureposSetting('base_url', ''));
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (!is_array($parts) || empty($parts['host'])
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\\\\]/', $configured)
            || (secureposIsProduction() && $parts['scheme'] !== 'https')) {
            secureposConfigFailure();
        }
        return rtrim($configured, '/');
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/\A(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])(?::[0-9]{1,5})?\z/iD', $host)) {
        secureposConfigFailure();
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $directory = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    if (preg_match('/[\x00-\x20?#]/', $directory)) {
        secureposConfigFailure();
    }
    return (secureposRequestIsHttps() ? 'https://' : 'http://') . $host . $directory;
}

date_default_timezone_set('Asia/Kuala_Lumpur');
if (secureposIsProduction()) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (PHP_SAPI !== 'cli') {
        if (trim((string)secureposSetting('base_url', '')) === '') {
            secureposConfigFailure();
        }
        $url = secureposBaseUrl();
        if (!secureposRequestIsHttps()) {
            $path = (string)($_SERVER['REQUEST_URI'] ?? '/');
            if ($path === '' || $path[0] !== '/' || preg_match('/[\r\n]/', $path)) {
                secureposConfigFailure();
            }
            $origin = parse_url($url);
            $authority = $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
            header('Location: https://' . $authority . $path, true, 308);
            exit;
        }
    }
}
