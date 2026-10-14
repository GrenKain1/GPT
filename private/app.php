<?php
declare(strict_types=1);

const FD_PRIVATE_DIR = __DIR__;
if (!defined('FD_PUBLIC_DIR')) define('FD_PUBLIC_DIR', __DIR__ . '/../public_html');
const FD_SESSION_IDLE_SECONDS = 1800;
const FD_SESSION_MAX_SECONDS = 43200;

function fd_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fd_config(): array
{
    $path = FD_PRIVATE_DIR . '/config.local.php';
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function fd_is_installed(): bool
{
    $config = fd_config();
    return isset($config['password_hash'], $config['admin_username'], $config['site_url'], $config['admin_path'])
        && is_string($config['password_hash']) && $config['password_hash'] !== '';
}

function fd_mkdir_private(string $directory): void
{
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private storage directory is not writable.');
    }
}

function fd_atomic_write(string $path, string $contents, int $mode = 0600): void
{
    fd_mkdir_private(dirname($path));
    $temporary = tempnam(dirname($path), '.fd-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create a temporary storage file.');
    }
    try {
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open storage.');
        }
        try {
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to save all storage data.');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Unable to flush storage data.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Unable to replace storage safely.');
        }
        if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function fd_with_lock(string $name, callable $callback): mixed
{
    $directory = FD_PRIVATE_DIR . '/runtime';
    fd_mkdir_private($directory);
    $handle = fopen($directory . '/' . $name . '.lock', 'c');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the storage lock.');
    }
    @chmod($directory . '/' . $name . '.lock', 0600);
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock storage.');
        }
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function fd_data(): array
{
    $path = FD_PRIVATE_DIR . '/content.json';
    if (is_file($path)) {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read site content.');
        }
        $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Site content has an invalid format.');
        }
        return $data;
    }
    $defaults = FD_PRIVATE_DIR . '/defaults.php';
    if (!is_file($defaults)) {
        throw new RuntimeException('Default site content is missing.');
    }
    $data = require $defaults;
    if (!is_array($data)) {
        throw new RuntimeException('Default site content has an invalid format.');
    }
    return $data;
}

function fd_save_data(array $data): void
{
    if (($data['schema'] ?? null) !== 1 || !is_array($data['products'] ?? null)
        || !is_array($data['settings'] ?? null)) {
        throw new InvalidArgumentException('Site content has an invalid format.');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (strlen($json) > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('Site content exceeds the size limit.');
    }
    fd_with_lock('content', function () use ($json): void {
        fd_atomic_write(FD_PRIVATE_DIR . '/content.json', $json);
    });
}

function fd_base_path(): string
{
    $configured = (string) (fd_config()['site_url'] ?? '');
    if ($configured !== '') {
        $path = parse_url($configured, PHP_URL_PATH);
        return is_string($path) ? rtrim($path, '/') : '';
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return rtrim(dirname($script), '/.');
}

function fd_url(string $path = ''): string
{
    return (fd_base_path() ?: '') . '/' . ltrim($path, '/');
}

function fd_request_secure(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $host = strtolower((string) (parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?? ''));
    $host = trim($host, '[]');
    $localHosts = ['localhost', '127.0.0.1', '::1'];
    $localAddresses = ['127.0.0.1', '::1'];
    $serverAddress = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($host, $localHosts, true)
        && in_array($remoteAddress, $localAddresses, true)
        && (PHP_SAPI === 'cli-server' || in_array($serverAddress, $localAddresses, true));
}

function fd_csp_nonce(): string
{
    static $nonce;
    if (!isset($nonce)) {
        $nonce = base64_encode(random_bytes(18));
    }
    return $nonce;
}

function fd_security_headers(bool $private = false): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . fd_csp_nonce() . "'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    if ($private) {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
}

function fd_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    fd_mkdir_private(FD_PRIVATE_DIR . '/runtime/sessions');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) FD_SESSION_MAX_SECONDS);
    session_save_path(FD_PRIVATE_DIR . '/runtime/sessions');
    session_name('fantasydream_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (fd_base_path() ?: '') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (!session_start()) {
        throw new RuntimeException('Unable to start a secure session.');
    }
    $now = time();
    if (isset($_SESSION['admin'])
        && ($now - (int) ($_SESSION['last_activity'] ?? 0) > FD_SESSION_IDLE_SECONDS
            || $now - (int) ($_SESSION['authenticated_at'] ?? 0) > FD_SESSION_MAX_SECONDS)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (isset($_SESSION['admin'])) {
        $_SESSION['last_activity'] = $now;
    }
}

function fd_csrf_token(): string
{
    fd_session();
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function fd_csrf_check(mixed $token): bool
{
    fd_session();
    return is_string($token) && strlen($token) === 64 && isset($_SESSION['csrf'])
        && is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function fd_origin_of(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['https', 'http'], true)) {
        return '';
    }
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) && !(($scheme === 'https' && $parts['port'] === 443)
        || ($scheme === 'http' && $parts['port'] === 80)) ? ':' . $parts['port'] : '';
    return $scheme . '://' . $host . $port;
}

function fd_origin_ok(): bool
{
    $expected = fd_origin_of((string) (fd_config()['site_url'] ?? ''));
    if ($expected === '' && fd_request_secure()) {
        $scheme = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
        $expected = fd_origin_of($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
    }
    if ($expected === '') {
        return false;
    }
    if (array_key_exists('HTTP_ORIGIN', $_SERVER)) {
        $origin = (string) $_SERVER['HTTP_ORIGIN'];
        if ($origin === '' || $origin === 'null' || preg_match('/[\r\n]/', $origin)) {
            return false;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || !empty($parts['path'])) {
            return false;
        }
        return hash_equals($expected, fd_origin_of($origin));
    }
    // Some older browsers omit Origin on a native form POST. A same-origin HTTPS
    // Referer remains required in that case; a supplied bad Origin never falls back.
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === '' || preg_match('/[\r\n]/', $referer) || !fd_request_secure()) {
        return false;
    }
    $refererOrigin = fd_origin_of($referer);
    return $refererOrigin !== '' && hash_equals($expected, $refererOrigin);
}

function fd_rate_limit(string $scope, int $limit, int $window, ?string $key = null): bool
{
    if ($limit < 1 || $window < 1) {
        throw new InvalidArgumentException('Invalid rate limit.');
    }
    return fd_with_lock('rates', function () use ($scope, $limit, $window, $key): bool {
        $secretPath = FD_PRIVATE_DIR . '/runtime/rate-key';
        if (!is_file($secretPath)) {
            fd_atomic_write($secretPath, random_bytes(32));
        }
        $secret = file_get_contents($secretPath);
        if ($secret === false || strlen($secret) !== 32) {
            throw new RuntimeException('Rate limiting storage is unavailable.');
        }
        $id = hash_hmac('sha256', $scope . "\0" . ($key ?? (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')), $secret);
        $path = FD_PRIVATE_DIR . '/runtime/rates.json';
        $records = [];
        if (is_file($path)) {
            $json = file_get_contents($path);
            $records = $json === false ? null : json_decode($json, true);
            if (!is_array($records)) {
                throw new RuntimeException('Rate limiting storage is invalid.');
            }
        }
        $now = time();
        foreach ($records as $recordId => $record) {
            if (!is_array($record) || (int) ($record['expires'] ?? 0) <= $now) {
                unset($records[$recordId]);
            }
        }
        $hits = array_values(array_filter($records[$id]['hits'] ?? [], static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $now - $window));
        if (count($hits) >= $limit || (count($records) >= 10000 && !isset($records[$id]))) {
            return false;
        }
        $hits[] = $now;
        $records[$id] = ['expires' => $now + $window, 'hits' => $hits];
        fd_atomic_write($path, json_encode($records, JSON_THROW_ON_ERROR));
        return true;
    });
}

function fd_is_admin(): bool
{
    fd_session();
    if (!fd_request_secure() || !fd_is_installed() || !isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
        return false;
    }
    $config = fd_config();
    $fingerprint = hash('sha256', (string) $config['admin_username'] . "\0" . (string) $config['password_hash']);
    if (!isset($_SESSION['admin_fingerprint']) || !is_string($_SESSION['admin_fingerprint'])
        || !hash_equals($fingerprint, $_SESSION['admin_fingerprint'])) {
        fd_logout();
        return false;
    }
    return true;
}

function fd_login(string $username, string $password): bool
{
    fd_session();
    if (!fd_request_secure() || !fd_is_installed() || strlen($username) > 64 || strlen($password) > 72) {
        return false;
    }
    if (!fd_rate_limit('login-ip', 12, 900) || !fd_rate_limit('login-user', 6, 900, strtolower($username))) {
        return false;
    }
    $config = fd_config();
    $correctUser = hash_equals((string) $config['admin_username'], $username);
    $correctPassword = password_verify($password, (string) $config['password_hash']);
    if (!$correctUser || !$correctPassword) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = [
        'admin' => true,
        'admin_fingerprint' => hash('sha256', (string) $config['admin_username'] . "\0" . (string) $config['password_hash']),
        'authenticated_at' => time(), 'last_activity' => time(), 'csrf' => bin2hex(random_bytes(32)),
    ];
    return true;
}

function fd_logout(): void
{
    fd_session();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function fd_require_admin(): void
{
    if (!fd_request_secure()) {
        http_response_code(403);
        exit('HTTPS is required.');
    }
    if (!fd_is_admin()) {
        header('Location: ' . fd_url((string) (fd_config()['admin_path'] ?? '')));
        exit;
    }
}

function fd_validate_site_url(string $url): bool
{
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !isset($parts['host'], $parts['scheme']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
        return false;
    }
    return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http'
        && in_array(trim(strtolower($parts['host']), '[]'), ['localhost', '127.0.0.1', '::1'], true));
}

function fd_password_hash(string $password): string
{
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Use a password between 12 and 72 bytes.');
    }
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
}

function fd_verify_password(string $password): bool
{
    if (!fd_request_secure() || !fd_is_admin() || strlen($password) > 72 || str_contains($password, "\0")
        || !fd_rate_limit('account-verification', 5, 900)) {
        return false;
    }
    return password_verify($password, (string) (fd_config()['password_hash'] ?? ''));
}

function fd_save_config(array $config): void
{
    $siteUrl = $config['site_url'] ?? null;
    $username = $config['admin_username'] ?? null;
    $adminPath = $config['admin_path'] ?? null;
    $hash = $config['password_hash'] ?? null;
    if (!is_string($siteUrl) || !fd_validate_site_url($siteUrl)
        || !is_string($username) || !preg_match('/\A[A-Za-z0-9_.-]{3,64}\z/D', $username)
        || !is_string($adminPath) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{15,63}\z/D', $adminPath)
        || !is_string($hash) || (password_get_info($hash)['algoName'] ?? 'unknown') === 'unknown') {
        throw new InvalidArgumentException('The website configuration is invalid.');
    }
    $smtp = $config['smtp'] ?? [];
    if (!is_array($smtp)) {
        throw new InvalidArgumentException('The SMTP configuration is invalid.');
    }
    $host = $smtp['host'] ?? '';
    $port = filter_var($smtp['port'] ?? 587, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $encryption = $smtp['encryption'] ?? 'starttls';
    $loopback = is_string($host) && in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    if (!is_string($host) || strlen($host) > 253 || ($host !== '' && !preg_match('/\A(?:[A-Za-z0-9.-]+|\[?[0-9A-Fa-f:]+\]?)\z/D', $host))
        || $port === false || !in_array($encryption, ['starttls', 'tls'], true) && !($encryption === 'none' && $loopback)) {
        throw new InvalidArgumentException('The SMTP host, port or encryption is invalid.');
    }
    foreach (['username', 'password'] as $field) {
        if (isset($smtp[$field]) && (!is_string($smtp[$field]) || strlen($smtp[$field]) > 256 || str_contains($smtp[$field], "\0"))) {
            throw new InvalidArgumentException('The SMTP credentials are invalid.');
        }
    }
    $from = $smtp['from_email'] ?? '';
    $recipient = $config['order_recipient'] ?? '';
    $fromName = $smtp['from_name'] ?? 'FantasyDream';
    if (!is_string($from) || ($from !== '' && (!filter_var($from, FILTER_VALIDATE_EMAIL) || strlen($from) > 254 || preg_match('/[\r\n]/', $from)))
        || !is_string($recipient) || ($recipient !== '' && (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strlen($recipient) > 254 || preg_match('/[\r\n]/', $recipient)))
        || !is_string($fromName) || strlen($fromName) > 150 || preg_match('/[\r\n\x00]/', $fromName)) {
        throw new InvalidArgumentException('The SMTP sender or recipient is invalid.');
    }
    $config['site_url'] = rtrim($siteUrl, '/');
    fd_with_lock('config', function () use ($config): void {
        if (!is_file(FD_PRIVATE_DIR . '/config.local.php')) {
            throw new RuntimeException('Complete installation before changing the configuration.');
        }
        fd_atomic_write(FD_PRIVATE_DIR . '/config.local.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
    });
}

function fd_install(string $username, string $password, string $siteUrl, string $adminPath, ?string $setupToken = null): void
{
    if (!preg_match('/\A[A-Za-z0-9_.-]{3,64}\z/D', $username)) {
        throw new InvalidArgumentException('Use a username with 3–64 letters, numbers, dots, underscores or hyphens.');
    }
    if (!fd_validate_site_url($siteUrl)) {
        throw new InvalidArgumentException('Enter a valid HTTPS site URL, including any installation subdirectory.');
    }
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{15,63}\z/D', $adminPath)
        || in_array(strtolower($adminPath), ['setup', 'order', 'assets', 'media', 'catalog', 'contact', 'index'], true)) {
        throw new InvalidArgumentException('Use an administrator path with 16–64 letters, numbers, underscores or hyphens.');
    }
    $hash = fd_password_hash($password);
    fd_with_lock('install', function () use ($username, $hash, $siteUrl, $adminPath, $setupToken): void {
        if (is_file(FD_PRIVATE_DIR . '/config.local.php')) {
            throw new RuntimeException('Installation has already been completed.');
        }
        $tokenPath = FD_PRIVATE_DIR . '/setup-token.txt';
        if ($setupToken !== null) {
            $storedToken = is_file($tokenPath) ? trim((string) file_get_contents($tokenPath)) : '';
            if (strlen($storedToken) !== 64 || !hash_equals($storedToken, $setupToken)) {
                throw new RuntimeException('The installation token is invalid.');
            }
        } elseif (PHP_SAPI !== 'cli') {
            throw new RuntimeException('The installation token is required.');
        }
        $config = [
            'site_url' => rtrim($siteUrl, '/'),
            'admin_path' => $adminPath,
            'admin_username' => $username,
            'password_hash' => $hash,
            'smtp' => [
                'host' => '', 'port' => 587, 'encryption' => 'starttls',
                'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'FantasyDream',
            ],
            'order_recipient' => '',
        ];
        // Establish writable storage before publishing the completed configuration.
        fd_mkdir_private(FD_PRIVATE_DIR . '/runtime/sessions');
        if (!is_file(FD_PRIVATE_DIR . '/content.json')) {
            fd_save_data(fd_data());
        }
        fd_atomic_write(FD_PRIVATE_DIR . '/config.local.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
        if (is_file($tokenPath) && !@unlink($tokenPath)) {
            // A configuration already exists, so even an undeletable token cannot repeat installation.
            error_log('FantasyDream: remove the expired private/setup-token.txt file.');
        }
    });
}
