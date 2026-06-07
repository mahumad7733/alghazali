<?php
/**
 * تشغيل نسخة احتياطية على الخادم (مهمة مجدولة أو يدوياً).
 *
 * HTTP:  GET /admin/backup_run.php?token=الرمز_من_الإعدادات
 * CLI:   php admin/backup_run.php YOUR_TOKEN
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/backup_functions.php';

$token = '';
if (PHP_SAPI === 'cli') {
    $token = $argv[1] ?? '';
} else {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_GET['token'] ?? '';
}

$expected = backup_get_setting($pdo, 'backup_cron_secret', '');
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    echo PHP_SAPI === 'cli' ? "Forbidden: invalid token\n" : json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

if ((int)backup_get_setting($pdo, 'backup_local_enabled', '0') !== 1) {
    $msg = ['ok' => false, 'error' => 'backup_local_disabled'];
    echo PHP_SAPI === 'cli' ? "Backup on server is disabled in settings.\n" : json_encode($msg, JSON_UNESCAPED_UNICODE);
    exit(1);
}

$dir = backup_resolve_storage_dir($pdo);
if ($dir === null) {
    backup_set_setting($pdo, 'backup_last_status', 'err: bad path', 'general');
    $msg = ['ok' => false, 'error' => 'invalid_storage_path'];
    echo PHP_SAPI === 'cli' ? "Invalid storage path.\n" : json_encode($msg, JSON_UNESCAPED_UNICODE);
    exit(1);
}

try {
    $sql = generateDatabaseBackup($pdo);
    $res = backup_save_sql_to_disk($pdo, $sql);
    if (!$res['ok']) {
        throw new Exception($res['error'] ?? 'save failed');
    }
    backup_set_setting($pdo, 'backup_last_run_at', date('c'), 'general');
    backup_set_setting($pdo, 'backup_last_run_date', date('Y-m-d'), 'general');
    backup_set_setting($pdo, 'backup_last_status', 'cron_ok: ' . basename($res['path'] ?? ''), 'general');

    $out = ['ok' => true, 'file' => basename($res['path'] ?? '')];
    echo PHP_SAPI === 'cli' ? "OK saved: " . ($res['path'] ?? '') . "\n" : json_encode($out, JSON_UNESCAPED_UNICODE);
    exit(0);
} catch (Throwable $e) {
    backup_set_setting($pdo, 'backup_last_status', 'err: ' . mb_substr($e->getMessage(), 0, 200), 'general');
    $msg = ['ok' => false, 'error' => $e->getMessage()];
    echo PHP_SAPI === 'cli' ? "ERROR: " . $e->getMessage() . "\n" : json_encode($msg, JSON_UNESCAPED_UNICODE);
    exit(1);
}
