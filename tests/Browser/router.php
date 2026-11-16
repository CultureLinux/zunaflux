<?php

declare(strict_types=1);

// Router for the loopback PHP test server, never a production entry point.
if ('browser' !== getenv('APP_ENV')) {
    http_response_code(404);
    exit;
}
// cli-server does not reliably populate $_SERVER/$_ENV from process variables.
// Otherwise Symfony Runtime can load .env defaults and boot the dev database.
$databaseUrl = getenv('BROWSER_TEST_DATABASE_URL') ?: '';
if ('' === $databaseUrl) {
    http_response_code(500);
    exit;
}
foreach (getenv() as $key => $value) {
    if (in_array($key, ['APP_SECRET', 'APP_TIMEZONE', 'MAILER_DSN', 'MESSENGER_TRANSPORT_DSN'], true)
        || str_starts_with($key, 'ZUNAFLUX_')
    ) {
        $_SERVER[$key] = $_ENV[$key] = $value;
    }
}
foreach (['APP_ENV' => 'browser', 'APP_DEBUG' => '0', 'DATABASE_URL' => $databaseUrl] as $key => $value) {
    $_SERVER[$key] = $_ENV[$key] = $value;
}
$_SERVER['APP_RUNTIME_OPTIONS'] = ['disable_dotenv' => true];
$public = dirname(__DIR__, 2).'/public';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = realpath($public.$path);
if (false !== $file && str_starts_with($file, $public.DIRECTORY_SEPARATOR)
    && is_file($file) && 'php' !== strtolower(pathinfo($file, PATHINFO_EXTENSION))
    && !preg_match('~(?:^|/)\.~', $path)
) {
    return false;
}
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
require $public.'/index.php';
