<?php
declare(strict_types=1);

// Run against the temporary checkout created by smoke.py, never production data.
require dirname(__DIR__) . '/private/app.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

check(fd_validate_site_url('https://example.org/shop'), 'HTTPS subdirectory must be supported.');
check(!fd_validate_site_url('http://example.org'), 'Production URL must require HTTPS.');
check(!fd_validate_site_url('https://name@example.org'), 'Username-only URL credentials must be rejected.');
check(!fd_validate_site_url('https://name:secret@example.org'), 'URL credentials must be rejected.');
check(!fd_validate_site_url('https://example.org/?next=evil'), 'Query must not become a site base URL.');
check(!fd_validate_site_url('https://example.org/#fragment'), 'Fragment must not become a site base URL.');
check(!fd_validate_site_url("https://example.org/\nheader"), 'Control characters must be rejected.');
check(!fd_validate_site_url('javascript:alert(1)'), 'Script URL must be rejected.');
check(fd_origin_of('https://example.org:443') === 'https://example.org', 'Default port must normalize.');
check(fd_origin_of('https://name@example.org') === '', 'Origins must reject user information.');
check(fd_e('<script>"&\'') === '&lt;script&gt;&quot;&amp;&#039;', 'HTML must escape markup, quotes and ampersands.');

$hash = fd_password_hash('temporary-test-password-2026');
check(password_verify('temporary-test-password-2026', $hash), 'Password hash must verify.');
check(!password_verify('wrong-password', $hash), 'Wrong password must fail.');
foreach (['short', str_repeat('x', 73), "long-password\0hidden"] as $password) {
    try {
        fd_password_hash($password);
        throw new RuntimeException('Invalid password was accepted.');
    } catch (InvalidArgumentException $exception) {
        $checks++;
    }
}

check(fd_rate_limit('qa-limit', 2, 60, 'qa-client'), 'First request must be allowed.');
check(fd_rate_limit('qa-limit', 2, 60, 'qa-client'), 'Second request must be allowed.');
check(!fd_rate_limit('qa-limit', 2, 60, 'qa-client'), 'Third request must be limited.');
check(fd_rate_limit('qa-limit', 2, 60, 'another-client'), 'A different client must have its own limit.');

$storage = FD_PRIVATE_DIR . '/runtime/atomic-test';
fd_atomic_write($storage, 'first');
fd_atomic_write($storage, 'replacement');
check(file_get_contents($storage) === 'replacement', 'Storage replacement must write the complete value.');
check((fileperms($storage) & 0777) === 0600, 'Private storage permissions must remain restrictive.');

echo json_encode(['checks' => $checks], JSON_THROW_ON_ERROR) . "\n";
