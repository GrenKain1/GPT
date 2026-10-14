<?php
// Local development only. Production uses Apache .htaccess.
declare(strict_types=1);
$public = dirname(__DIR__) . '/public_html';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($public . $path);
if ($file !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file) && !str_starts_with(basename($file), '.')) {
    return false;
}
require $public . '/index.php';
