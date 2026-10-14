<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

fd_security_headers(true);
header('Content-Type: text/html; charset=UTF-8');
if (is_file(FD_PRIVATE_DIR . '/config.local.php') || !is_file(FD_PRIVATE_DIR . '/setup-token.txt')) {
    http_response_code(404);
    exit('Not found.');
}
if (!fd_request_secure()) {
    http_response_code(403);
    exit('Use HTTPS to install FantasyDream. HTTP is allowed only on a local development server.');
}

$error = '';
$complete = false;
$adminPath = 'manage-' . bin2hex(random_bytes(12));
$username = '';
$scheme = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$suggestedUrl = $scheme . '://' . $host . fd_base_path();
$siteUrl = fd_validate_site_url($suggestedUrl) ? $suggestedUrl : '';
try {
    fd_session();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            throw new InvalidArgumentException('The request is too large.');
        }
        if (!fd_origin_ok() || !fd_csrf_check($_POST['csrf'] ?? null)) {
            http_response_code(403);
            throw new RuntimeException('The request could not be verified. Refresh this page and try again.');
        }
        if (!fd_rate_limit('setup', 5, 900)) {
            http_response_code(429);
            header('Retry-After: 900');
            throw new RuntimeException('Too many installation attempts. Wait 15 minutes and try again.');
        }
        foreach (['token', 'username', 'password', 'password_confirmation', 'site_url', 'admin_path'] as $field) {
            if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
                throw new InvalidArgumentException('Complete all installation fields.');
            }
        }
        $token = trim($_POST['token']);
        $storedToken = trim((string) file_get_contents(FD_PRIVATE_DIR . '/setup-token.txt'));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token) || !hash_equals($storedToken, $token)) {
            throw new InvalidArgumentException('The installation token is invalid.');
        }
        $username = trim($_POST['username']);
        $siteUrl = trim($_POST['site_url']);
        $adminPath = trim($_POST['admin_path']);
        if (!hash_equals($_POST['password'], $_POST['password_confirmation'])) {
            throw new InvalidArgumentException('The passwords do not match.');
        }
        fd_install($username, $_POST['password'], $siteUrl, $adminPath, $token);
        session_regenerate_id(true);
        $_SESSION = [];
        $complete = true;
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new RuntimeException('Use the installation form.');
    }
} catch (InvalidArgumentException $exception) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    if (http_response_code() < 400) {
        http_response_code(503);
    }
    $error = $exception instanceof RuntimeException && in_array($exception->getMessage(), [
        'The request could not be verified. Refresh this page and try again.',
        'Too many installation attempts. Wait 15 minutes and try again.',
        'Installation has already been completed.',
        'Use the installation form.',
    ], true) ? $exception->getMessage() : 'Installation could not be saved. Check the private directory permissions and the server error log.';
    error_log('FantasyDream installation: ' . get_class($exception));
}
?><!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Install FantasyDream</title>
    <link rel="stylesheet" href="<?= fd_e(fd_url('assets/admin.css')) ?>">
</head>
<body>
<main class="admin-shell setup-shell">
    <h1>Install FantasyDream</h1>
    <?php if ($complete): ?>
        <p role="status">Installation completed. Keep the administrator address in a safe place.</p>
        <p><a href="<?= fd_e(fd_url($adminPath)) ?>">Open the administrator login</a></p>
        <p>Open Business details in the administrator panel to add your SMTP connection and order recipient, then send a test email before accepting order requests. The credentials are saved privately and never displayed in the browser.</p>
        <p>The installation token has expired. You may also remove <code>public_html/setup.php</code> after installation.</p>
    <?php else: ?>
        <p>Create your administrator account. The installation token is in the private file supplied with your hosting archive.</p>
        <?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= fd_e($error) ?></p><?php endif; ?>
        <form method="post" action="<?= fd_e(fd_url('setup.php')) ?>">
            <input type="hidden" name="csrf" value="<?= fd_e(fd_csrf_token()) ?>">
            <label>Installation token <input type="password" name="token" required minlength="64" maxlength="64" autocomplete="off" spellcheck="false"></label>
            <label>Administrator username <input name="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_.-]{3,64}" value="<?= fd_e($username) ?>" autocomplete="username" spellcheck="false"></label>
            <label>Password <input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help"></label>
            <p id="password-help">Use a unique password of 12–72 bytes. A long ASCII passphrase works well.</p>
            <label>Confirm password <input type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></label>
            <label>Site URL <input type="url" name="site_url" required maxlength="2048" value="<?= fd_e($siteUrl) ?>" placeholder="https://your-domain.example" aria-describedby="url-help"></label>
            <p id="url-help">Use the final HTTPS address, including a subdirectory if the site is installed in one.</p>
            <label>Private administrator path <input name="admin_path" required minlength="16" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{15,63}" value="<?= fd_e($adminPath) ?>" spellcheck="false" aria-describedby="path-help"></label>
            <p id="path-help">Save this address privately. Password authentication protects it; the address alone does not grant access.</p>
            <button type="submit">Install website</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
