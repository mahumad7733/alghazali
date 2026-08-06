<?php

declare(strict_types=1);

$env = is_file(__DIR__ . '/../../.env') ? parse_ini_file(__DIR__ . '/../../.env') : [];
$host = getenv('FINANCE_TEST_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = getenv('FINANCE_TEST_DB_PORT') ?: ($env['DB_PORT'] ?? '3307');
$user = getenv('FINANCE_TEST_DB_USER') ?: ($env['DB_USER'] ?? 'root');
$pass = getenv('FINANCE_TEST_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$database = getenv('FINANCE_TEST_DB') ?: ($env['DB_NAME'] ?? 'alghazali_refactor_test');

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$queries = [
    'fiscal_period_lookup' => "SELECT period_name, is_closed FROM fiscal_periods WHERE '2026-08-07' BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1",
    'customer_account_lookup' => 'SELECT account_id FROM customers WHERE id = 1 LIMIT 1',
    'invoice_by_id' => 'SELECT invoice_status, invoice_date FROM invoices WHERE id = 1 LIMIT 1',
    'payment_status_sum' => "SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id WHERE pa.invoice_id = 1 AND ft.status IN ('draft', 'posted')",
    'account_usable' => 'SELECT is_active, account_status, deleted_at FROM unified_accounts WHERE id = 1 LIMIT 1',
];

foreach ($queries as $name => $query) {
    $plan = $pdo->query('EXPLAIN ' . $query)->fetch();
    echo $name . ': ' . json_encode([
        'table' => $plan['table'] ?? null,
        'type' => $plan['type'] ?? null,
        'key' => $plan['key'] ?? null,
        'rows' => $plan['rows'] ?? null,
        'extra' => $plan['Extra'] ?? null,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

foreach (['fiscal_periods', 'customers', 'invoices', 'payment_allocations', 'financial_transactions', 'unified_accounts'] as $table) {
    $indexes = $pdo->query('SHOW INDEX FROM ' . $table)->fetchAll();
    echo 'indexes_' . $table . ': ' . count($indexes) . PHP_EOL;
}
