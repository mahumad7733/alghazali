<?php
/**
 * Centralized session configuration to avoid permission issues and conflicts
 * Robust version: works no matter whether session was already started before inclusion.
 */

$session_save_path = realpath(__DIR__ . '/../sessions');
if ($session_save_path === false) {
    $session_save_path = __DIR__ . '/../sessions';
}
if (!is_dir($session_save_path)) {
    @mkdir($session_save_path, 0777, true);
}
$session_save_path = realpath($session_save_path);

$cookieSecure = (
    (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);

@ini_set('session.save_path', $session_save_path);
@ini_set('session.use_strict_mode', 1);
@ini_set('session.cookie_httponly', 1);
@ini_set('session.cookie_samesite', 'Strict');
@ini_set('session.cookie_secure', $cookieSecure ? 1 : 0);
@ini_set('session.use_only_cookies', 1);

if (session_status() === PHP_SESSION_ACTIVE) {
    $existingId = session_id();
    $_existing = $_SESSION ?? [];
    @session_write_close();
    @ini_set('session.save_path', $session_save_path);
    @ini_set('session.cookie_httponly', 1);
    @ini_set('session.cookie_samesite', 'Strict');
    @ini_set('session.cookie_secure', $cookieSecure ? 1 : 0);
    if ($existingId) {
        @session_id($existingId);
    }
    @session_start();
    if (!empty($_existing) && empty($_SESSION)) {
        $_SESSION = $_existing;
    }
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    if (headers_sent($file, $line)) {
        @session_start();
        return;
    }
    try {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    } catch (\Throwable $e) {
    }
    session_start();
}
