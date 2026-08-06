<?php

declare(strict_types=1);

$env = is_file(__DIR__ . '/../.env') ? parse_ini_file(__DIR__ . '/../.env') : [];
$host = getenv('FINANCE_TEST_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = getenv('FINANCE_TEST_DB_PORT') ?: ($env['DB_PORT'] ?? '3306');
$user = getenv('FINANCE_TEST_DB_USER') ?: ($env['DB_USER'] ?? 'root');
$pass = getenv('FINANCE_TEST_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$db = getenv('FINANCE_TEST_DB') ?: ($env['DB_NAME'] ?? 'alghazali');

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
require_once __DIR__ . '/../core/FinanceService.php';

$service = new FinanceService($pdo, 1);
$invoiceId = 0;
$voucherId = 0;
$cashCustomerId = 0;
$cashAccountId = 0;
$sourceId = random_int(900000, 999999);

try {
    $customer = $pdo->query(
        'SELECT id FROM customers WHERE account_id IS NOT NULL ORDER BY id LIMIT 1'
    )->fetchColumn();
    $cash = $pdo->query(
        "SELECT id FROM unified_accounts WHERE account_code = '11101' OR account_sub_type = 'box' ORDER BY id LIMIT 1"
    )->fetchColumn();
    $branch = $pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
    $currency = $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
    if (!$customer || !$cash || !$branch || !$currency) {
        throw new RuntimeException('Service operation fixtures are incomplete');
    }

    $cashCustomerId = $service->getOrCreateDefaultCashCustomer((int)$branch);
    $cashAccountStmt = $pdo->prepare('SELECT account_id FROM customers WHERE id = ?');
    $cashAccountStmt->execute([$cashCustomerId]);
    $cashAccountId = (int)$cashAccountStmt->fetchColumn();
    if ($cashCustomerId <= 0 || $cashAccountId <= 0) {
        throw new RuntimeException('Default cash customer/account was not resolved');
    }

    $result = $service->processServiceOperation([
        'branch_id' => (int)$branch,
        'source_type' => 'FacadeServiceOperationTest',
        'source_id' => $sourceId,
        'sale_currency_id' => (int)$currency,
        'sale_total_amount' => 600,
        'purchase_total_amount' => 0,
        'discount_amount' => 0,
        'delivery_type' => 'cash',
        'account_id' => (int)$cash,
        'paid_amount' => 100,
        'record_purchase' => '0',
        'description' => 'finance service operation integration test',
        'operation_date' => date('Y-m-d'),
    ]);
    $invoiceId = (int)$result['sales_invoice_id'];
    $voucherId = (int)$result['receipt_voucher_id'];
    $stmt = $pdo->prepare('SELECT invoice_status, payment_status, amount_received FROM invoices WHERE id = ?');
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT status FROM financial_transactions WHERE id = ?');
    $stmt->execute([$voucherId]);
    $voucherStatus = $stmt->fetchColumn();
    if ($invoice['invoice_status'] !== 'draft' || $invoice['payment_status'] !== 'partial'
        || (float)$invoice['amount_received'] !== 100.0 || $voucherStatus !== 'posted') {
        throw new RuntimeException('Service operation result mismatch: ' . json_encode([
            'invoice' => $invoice, 'voucher_status' => $voucherStatus,
        ]));
    }
    echo "Finance service operation integration test: PASS\n";
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    if ($invoiceId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM financial_transactions WHERE reference_type = \'invoice\' AND reference_id = ?');
        $stmt->execute([$invoiceId]);
        $transactionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $transactionIds[] = $voucherId;
        foreach (array_unique(array_filter($transactionIds)) as $transactionId) {
            $pdo->prepare('DELETE FROM journal_lines WHERE financial_transaction_id = ?')->execute([$transactionId]);
            $pdo->prepare('DELETE FROM financial_transactions WHERE id = ?')->execute([$transactionId]);
        }
        $pdo->prepare('DELETE FROM payment_allocations WHERE invoice_id = ?')->execute([$invoiceId]);
        $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoiceId]);
    }
    if ($cashCustomerId > 0) {
        $pdo->prepare('DELETE FROM account_balances_unified WHERE account_id = ?')->execute([$cashAccountId]);
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cashCustomerId]);
        $pdo->prepare('DELETE FROM unified_accounts WHERE id = ?')->execute([$cashAccountId]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
