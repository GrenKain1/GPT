<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function fd_smtp_read($stream, array $expected): string
{
    $response = '';
    $code = null;
    for ($lineNumber = 0; $lineNumber < 100; $lineNumber++) {
        $line = fgets($stream, 2048);
        if ($line === false || !str_ends_with($line, "\n") || !preg_match('/\A([0-9]{3})([ -])/', $line, $matches)) {
            throw new RuntimeException('SMTP returned an incomplete response.');
        }
        $lineCode = (int) $matches[1];
        if ($code !== null && $lineCode !== $code) {
            throw new RuntimeException('SMTP returned an inconsistent response.');
        }
        $code = $lineCode;
        $response .= $line;
        if ($matches[2] === ' ') {
            if (!in_array($code, $expected, true)) {
                throw new RuntimeException('SMTP rejected a command (status ' . $code . ').');
            }
            return $response;
        }
    }
    throw new RuntimeException('SMTP returned too many response lines.');
}

function fd_smtp_write($stream, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('The SMTP connection was interrupted.');
        }
        $offset += $written;
    }
}

function fd_smtp_command($stream, string $command, array $expected): string
{
    if (preg_match('/[\r\n]/', $command) || strlen($command) > 998) {
        throw new RuntimeException('An SMTP command is invalid.');
    }
    fd_smtp_write($stream, $command . "\r\n");
    return fd_smtp_read($stream, $expected);
}

function fd_mail_address(string $value): bool
{
    return strlen($value) <= 254 && !preg_match('/[\r\n\x00]/', $value)
        && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function fd_mail_header(string $value): string
{
    if (preg_match('/[\r\n\x00]/', $value) || preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException('An email header is invalid.');
    }
    // Folding keeps UTF-8 encoded-word headers inside the RFC line length.
    $chunks = [];
    $current = '';
    foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        if (strlen($current . $character) > 42 && $current !== '') {
            $chunks[] = '=?UTF-8?B?' . base64_encode($current) . '?=';
            $current = '';
        }
        $current .= $character;
    }
    if ($current !== '') {
        $chunks[] = '=?UTF-8?B?' . base64_encode($current) . '?=';
    }
    return implode("\r\n ", $chunks);
}

function fd_send_mail(string $recipient, string $subject, string $body, string $replyTo): void
{
    $config = fd_config();
    $smtp = $config['smtp'] ?? [];
    if (!is_array($smtp)) {
        throw new RuntimeException('SMTP is not configured.');
    }
    $host = (string) ($smtp['host'] ?? '');
    $port = filter_var($smtp['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $encryption = (string) ($smtp['encryption'] ?? 'starttls');
    $from = (string) ($smtp['from_email'] ?? '');
    $fromName = (string) ($smtp['from_name'] ?? 'FantasyDream');
    $username = (string) ($smtp['username'] ?? '');
    $password = (string) ($smtp['password'] ?? '');
    $plainLocal = $encryption === 'none' && in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    if ($host === '' || strlen($host) > 253 || !preg_match('/\A(?:[A-Za-z0-9.-]+|\[?[0-9A-Fa-f:]+\]?)\z/D', $host)
        || $port === false || !in_array($encryption, ['starttls', 'tls'], true) && !$plainLocal
        || !fd_mail_address($from) || !fd_mail_address($recipient) || !fd_mail_address($replyTo)
        || strlen($username) > 256 || strlen($password) > 256 || strlen($fromName) > 150
        || str_contains($username, "\0") || str_contains($password, "\0")
        || ($username === '') !== ($password === '')) {
        throw new RuntimeException('SMTP configuration is incomplete or invalid.');
    }
    if (!extension_loaded('openssl') && !$plainLocal) {
        throw new RuntimeException('OpenSSL is required for encrypted SMTP.');
    }
    $peerHost = trim($host, '[]');
    $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'peer_name' => $peerHost,
        'SNI_enabled' => true,
        'crypto_method' => $crypto,
    ]]);
    $targetHost = str_contains($peerHost, ':') ? '[' . $peerHost . ']' : $peerHost;
    $transport = $encryption === 'tls' ? 'tls' : 'tcp';
    $stream = @stream_socket_client($transport . '://' . $targetHost . ':' . $port, $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
    if ($stream === false) {
        throw new RuntimeException('Unable to establish the SMTP connection.');
    }
    stream_set_timeout($stream, 15);
    try {
        fd_smtp_read($stream, [220]);
        $siteHost = (string) (parse_url((string) ($config['site_url'] ?? ''), PHP_URL_HOST) ?? 'localhost');
        $clientName = preg_match('/\A[A-Za-z0-9.-]+\z/D', $siteHost) ? $siteHost : 'localhost';
        $capabilities = fd_smtp_command($stream, 'EHLO ' . $clientName, [250]);
        if ($encryption === 'starttls') {
            if (!preg_match('/^250[ -]STARTTLS\b/im', $capabilities)) {
                throw new RuntimeException('The SMTP server does not offer STARTTLS.');
            }
            fd_smtp_command($stream, 'STARTTLS', [220]);
            if (@stream_socket_enable_crypto($stream, true, $crypto) !== true) {
                throw new RuntimeException('SMTP TLS certificate verification failed.');
            }
            $capabilities = fd_smtp_command($stream, 'EHLO ' . $clientName, [250]);
        }
        if ($username !== '') {
            if (preg_match('/^250[ -]AUTH(?:=|\s).*\bPLAIN\b/im', $capabilities)) {
                fd_smtp_command($stream, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), [235]);
            } elseif (preg_match('/^250[ -]AUTH(?:=|\s).*\bLOGIN\b/im', $capabilities)) {
                fd_smtp_command($stream, 'AUTH LOGIN', [334]);
                fd_smtp_command($stream, base64_encode($username), [334]);
                fd_smtp_command($stream, base64_encode($password), [235]);
            } else {
                throw new RuntimeException('The SMTP server does not offer supported authentication.');
            }
        }
        fd_smtp_command($stream, 'MAIL FROM:<' . $from . '>', [250]);
        fd_smtp_command($stream, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        fd_smtp_command($stream, 'DATA', [354]);
        $messageId = bin2hex(random_bytes(18)) . '@' . $clientName;
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . fd_mail_header($fromName) . ' <' . $from . '>',
            'To: <' . $recipient . '>',
            'Reply-To: <' . $replyTo . '>',
            'Subject: ' . fd_mail_header($subject),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        // Base64 cannot contain a leading dot; dot stuffing also keeps this safe if encoding changes.
        $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
        fd_smtp_write($stream, $message . ".\r\n");
        fd_smtp_read($stream, [250]);
        // Once DATA is accepted, failure during QUIT cannot make the accepted message unsent.
        try {
            fd_smtp_command($stream, 'QUIT', [221]);
        } catch (RuntimeException) {
        }
    } finally {
        fclose($stream);
    }
}
