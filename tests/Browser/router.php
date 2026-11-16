<?php

declare(strict_types=1);

// Router for the loopback PHP test server, never a production entry point.
if ('browser' !== getenv('APP_ENV')) {
    http_response_code(404);
    exit;
}
$public = dirname(__DIR__, 2).'/public';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = realpath($public.$path);
if (false !== $file && str_starts_with($file, $public.DIRECTORY_SEPARATOR)
    && is_file($file) && 'php' !== strtolower(pathinfo($file, PATHINFO_EXTENSION))
    && !preg_match('~(?:^|/)\.~', $path)
) {
    return false;
}
require $public.'/index.php';
