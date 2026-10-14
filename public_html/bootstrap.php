<?php
declare(strict_types=1);
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404); exit('Not found.');
}
define('FD_PUBLIC_DIR', __DIR__);
// A host-level variable supports subdirectory URLs with data outside the web root.
$privateDirectory = getenv('FANTASYDREAM_PRIVATE_DIR');
if ($privateDirectory === false || $privateDirectory === '') $privateDirectory = dirname(__DIR__) . '/private';
$privateDirectory = realpath($privateDirectory);
if ($privateDirectory === false || !is_file($privateDirectory . '/app.php')) {
    http_response_code(503);
    exit('Website configuration is unavailable.');
}
require_once $privateDirectory . '/app.php';
