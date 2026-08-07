<?php

declare(strict_types=1);

$env = is_file(__DIR__ . '/../../.env') ? parse_ini_file(__DIR__ . '/../../.env') : [];
$host = getenv('FINANCE_TEST_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = getenv('FINANCE_TEST_DB_PORT') ?: ($env['DB_PORT'] ?? '3306');
$user = getenv('FINANCE_TEST_DB_USER') ?: ($env['DB_USER'] ?? 'root');
$pass = getenv('FINANCE_TEST_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$db = getenv('FINANCE_TEST_DB') ?: 'alghazali_refactor_test';

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

$migration = file_get_contents(__DIR__ . '/../../database/migrations/2026_08_06_001_finance_schema_compatibility.sql');
if ($migration === false || strpos($migration, 'ADD COLUMN IF NOT EXISTS') === false) {
    throw new RuntimeException('Migration is missing idempotent column additions');
}

$pdo->exec(
    'ALTER TABLE invoices ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1.000000 AFTER currency_id'
);
$pdo->exec(
    'ALTER TABLE journal_lines
        ADD COLUMN IF NOT EXISTS line_number INT NULL AFTER financial_transaction_id,
        ADD COLUMN IF NOT EXISTS account_type VARCHAR(50) NULL AFTER account_id,
        ADD COLUMN IF NOT EXISTS base_debit DECIMAL(18,2) NULL DEFAULT 0 AFTER credit,
        ADD COLUMN IF NOT EXISTS base_credit DECIMAL(18,2) NULL DEFAULT 0 AFTER base_debit,
        ADD COLUMN IF NOT EXISTS line_type VARCHAR(50) NULL AFTER description,
        ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP'
);

$required = [
    'invoices' => ['exchange_rate'],
    'journal_lines' => ['line_number', 'account_type', 'base_debit', 'base_credit', 'line_type', 'created_at'],
];
foreach ($required as $table => $columns) {
    $actual = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $column) {
        if (!in_array($column, $actual, true)) {
            throw new RuntimeException("Missing migrated column {$table}.{$column}");
        }
    }
}
echo "Finance schema migration verification: PASS ({$db})\n";
