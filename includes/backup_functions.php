<?php

/**
 * توليد نسخة SQL احتياطية وحفظها على الخادم (مسار آمن تحت مجلد المشروع فقط).
 */

function backup_get_setting(PDO $pdo, string $key, $default = null)
{
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v !== false && $v !== null && $v !== '' ? (string)$v : $default;
}

function backup_set_setting(PDO $pdo, string $key, string $value, string $group = 'general'): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group)
         VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value, $group]);
}

function escapeSqlValue($value)
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\\'", '\\"', "\\Z"], $value) . "'";
}

function buildTableInsertStatements($pdo, $table)
{
    $columns = [];
    $rows = [];
    $stmt = $pdo->query("SELECT * FROM `{$table}`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (empty($columns)) {
            $columns = array_keys($row);
        }
        $values = array_map('escapeSqlValue', array_values($row));
        $rows[] = '(' . implode(', ', $values) . ')';
        if (count($rows) >= 100) {
            yield [$columns, $rows];
            $rows = [];
        }
    }
    if (!empty($rows)) {
        yield [$columns, $rows];
    }
}

function generateDatabaseBackup(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $sql = [];
    $sql[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $sql[] = "CREATE DATABASE IF NOT EXISTS `{$database}`;";
    $sql[] = "USE `{$database}`;";
    $sql[] = "\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createStmt ? array_values($createStmt)[1] : null;
        if (!$createSql) {
            continue;
        }

        $sql[] = "DROP TABLE IF EXISTS `{$table}`;";
        $sql[] = $createSql . ';';
        foreach (buildTableInsertStatements($pdo, $table) as list($columns, $rows)) {
            $columnList = implode(', ', array_map(function ($col) {
                return "`{$col}`";
            }, $columns));
            $sql[] = "INSERT INTO `{$table}` ({$columnList}) VALUES";
            $sql[] = implode(",\n", $rows) . ';';
        }
        $sql[] = "\n";
    }

    $sql[] = 'SET FOREIGN_KEY_CHECKS=1;';
    return implode("\n", $sql);
}

/**
 * يحل مسار الحفظ المحلي ضمن جذر المشروع فقط (منع directory traversal).
 */
function backup_resolve_storage_dir(PDO $pdo): ?string
{
    $rel = backup_get_setting($pdo, 'backup_local_path', 'storage/db_backups');
    $rel = str_replace(["\0", '..'], '', (string)$rel);
    $rel = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR);

    $root = realpath(dirname(__DIR__));
    if ($root === false) {
        return null;
    }

    $full = $root . DIRECTORY_SEPARATOR . $rel;
    if (!is_dir($full)) {
        if (!@mkdir($full, 0755, true)) {
            return null;
        }
    }
    $rp = realpath($full);
    if ($rp === false) {
        return null;
    }
    if (strpos($rp, $root) !== 0) {
        return null;
    }
    return $rp;
}

/**
 * @return array{ok:bool, path?:string, error?:string}
 */
function backup_save_sql_to_disk(PDO $pdo, string $sql): array
{
    $dir = backup_resolve_storage_dir($pdo);
    if ($dir === null) {
        return ['ok' => false, 'error' => 'مسار الحفظ غير صالح أو غير قابل للإنشاء.'];
    }
    $dbName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    $file = $dir . DIRECTORY_SEPARATOR . 'backup_' . $dbName . '_' . date('Ymd_His') . '.sql';
    $written = @file_put_contents($file, $sql);
    if ($written === false) {
        return ['ok' => false, 'error' => 'تعذر الكتابة على القرص.'];
    }
    return ['ok' => true, 'path' => $file];
}
