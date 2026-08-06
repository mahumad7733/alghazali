<?php

declare(strict_types=1);

/**
 * Build the isolated finance integration database from the local application
 * database. This script never changes the source schema or data.
 */

$env = is_file(__DIR__ . '/../../.env') ? parse_ini_file(__DIR__ . '/../../.env') : [];
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$sourceDb = $env['DB_NAME'] ?? 'alghazali';
$testDb = getenv('FINANCE_TEST_DB') ?: 'alghazali_refactor_test';

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$source = new PDO("mysql:host={$host};port={$port};dbname={$sourceDb};charset=utf8mb4", $user, $pass, $options);
$test = new PDO("mysql:host={$host};port={$port};dbname={$testDb};charset=utf8mb4", $user, $pass, $options);
$source->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$test->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

$test->exec("CREATE TABLE IF NOT EXISTS invoices LIKE {$sourceDb}.invoices");
$columns = $test->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('exchange_rate', $columns, true)) {
    $test->exec('ALTER TABLE invoices ADD COLUMN exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1.000000 AFTER currency_id');
}

$routines = $source->query(
    "SELECT ROUTINE_NAME, ROUTINE_TYPE
       FROM information_schema.ROUTINES
      WHERE ROUTINE_SCHEMA = " . $source->quote($sourceDb) . "
      ORDER BY ROUTINE_TYPE DESC, ROUTINE_NAME"
)->fetchAll();

foreach ($routines as $routine) {
    $name = $routine['ROUTINE_NAME'];
    $type = strtoupper($routine['ROUTINE_TYPE']);
    $show = $source->query("SHOW CREATE {$type} `{$name}`")->fetch(PDO::FETCH_NUM);
    if (!$show || empty($show[2])) {
        continue;
    }

    $sql = $show[2];
    $sql = preg_replace('/CREATE DEFINER=`[^`]+`@`[^`]+`/', 'CREATE', $sql, 1) ?: $sql;

    // The current database stores account codes, while several legacy
    // procedures pass semantic names. Keep this compatibility correction
    // isolated to the test database until a reviewed DB migration is applied.
    $sql = str_replace([
        "fn_get_default_leaf_account('revenue')",
        "fn_get_default_leaf_account('expense')",
        "fn_get_default_leaf_account('accounts_receivable')",
        "fn_get_default_leaf_account('accounts_payable')",
        'CALL sp_update_account_balances();',
        '0, v_discount, 0, v_discount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0),',
    ], [
        "fn_get_default_leaf_account('401')",
        "fn_get_default_leaf_account('5')",
        "fn_get_default_leaf_account('11201')",
        "fn_get_default_leaf_account('21101')",
        'CALL sp_update_account_balances(0);',
        'v_discount, 0, v_discount * COALESCE(NULLIF(v_exchange_rate, 0), 1.0), 0,',
    ], $sql);

    // Recreate input text parameters with the same collation as the tables.
    $sql = preg_replace(
        '/(IN\s+`[^`]+`\s+(?:VARCHAR\(\d+\)|TEXT|ENUM\([^)]*\)))(\s*,|\s*\))/',
        '$1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci$2',
        $sql
    ) ?: $sql;

    $test->exec("DROP {$type} IF EXISTS `{$name}`");
    $test->exec($sql);
}

echo "Prepared isolated finance database: {$testDb}\n";
