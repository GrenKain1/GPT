<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app.php';

function fd_cli_input(string $prompt, string $default = ''): string
{
    fwrite(STDOUT, $prompt . ($default !== '' ? ' [' . $default . ']' : '') . ': ');
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('No input was supplied.');
    }
    $value = trim($line);
    return $value !== '' ? $value : $default;
}

function fd_cli_password(string $prompt): string
{
    $isTerminal = function_exists('stream_isatty') && stream_isatty(STDIN);
    $hidden = false;
    if ($isTerminal && DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
        $state = shell_exec('stty -g 2>/dev/null');
        if (is_string($state) && trim($state) !== '') {
            shell_exec('stty -echo 2>/dev/null');
            $hidden = true;
        }
    }
    if ($isTerminal && !$hidden) {
        throw new RuntimeException('Cannot hide terminal input. Set FANTASYDREAM_ADMIN_PASSWORD securely or run through a terminal supporting stty.');
    }
    fwrite(STDOUT, $prompt . ': ');
    try {
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('No password was supplied.');
        }
        return rtrim($line, "\r\n");
    } finally {
        if ($hidden) {
            // stty state contains only terminal mode digits/separators; quote it before restoration.
            shell_exec('stty ' . escapeshellarg(trim($state)) . ' 2>/dev/null');
            fwrite(STDOUT, "\n");
        }
    }
}

try {
    if (is_file(FD_PRIVATE_DIR . '/config.local.php')) {
        throw new RuntimeException('Installation has already been completed.');
    }
    $options = getopt('', ['site-url:', 'username:', 'admin-path:']);
    $siteUrl = isset($options['site-url']) && is_string($options['site-url']) ? $options['site-url'] : fd_cli_input('HTTPS site URL');
    $username = isset($options['username']) && is_string($options['username']) ? $options['username'] : fd_cli_input('Administrator username', 'admin');
    $adminPath = isset($options['admin-path']) && is_string($options['admin-path']) ? $options['admin-path'] : 'manage-' . bin2hex(random_bytes(12));
    $password = getenv('FANTASYDREAM_ADMIN_PASSWORD');
    if ($password === false || $password === '') {
        $password = fd_cli_password('Administrator password');
        $confirmation = fd_cli_password('Confirm administrator password');
        if (!hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('The passwords do not match.');
        }
    }
    putenv('FANTASYDREAM_ADMIN_PASSWORD');
    fd_install($username, $password, $siteUrl, $adminPath);
    unset($password, $confirmation);
    fwrite(STDOUT, "Installation completed.\nAdministrator URL: " . rtrim($siteUrl, '/') . '/' . $adminPath . "\nConfigure SMTP and the recipient in the administrator Business details, then send a test email.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Installation failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
