<?php
declare(strict_types=1);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath(__DIR__ . $path);
if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/assets/') && $file && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require dirname(__DIR__) . '/app/Core/bootstrap.php';
