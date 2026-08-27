<?php
declare(strict_types=1);

use App\Classes\Database;

const APP_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\Classes\\' => APP_ROOT . '/classes/',
        'App\\Includes\\' => APP_ROOT . '/includes/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});

if (!is_file(APP_ROOT . '/config/config.php')) {
    http_response_code(503);
    exit('لم يتم إعداد ملف config/config.php بعد. راجع INSTALL_AR.md.');
}

/** @var array<string, mixed> $appConfig */
$appConfig = require APP_ROOT . '/config/config.php';
date_default_timezone_set((string) ($appConfig['timezone'] ?? 'Asia/Aden'));

session_name((string) ($appConfig['session_name'] ?? 'bus_booking_session'));
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

/** @var Database $database */
$database = require APP_ROOT . '/config/database.php';
