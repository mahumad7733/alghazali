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
$sourceId = random_int(900000, 999999);

try {
    $customer = $pdo->query("SELECT id, account_id FROM customers WHERE account_id IS NOT NULL ORDER BY id LIMIT 1")->fetch();
    $cash = $pdo->query("SELECT id FROM unified_accounts WHERE account_code='11101' OR account_sub_type='box' ORDER BY id LIMIT 1")->fetch();
    $branch = $pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
    $currency = $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
    if (!$customer || !$cash || !$branch || !$currency) {
        throw new RuntimeException('Finance facade test fixtures are incomplete');
    }

    $payload = [
        'branch_id' => (int)$branch,
        'source_type' => 'FacadeTest',
        'source_id' => $sourceId,
        'customer_id' => (int)$customer['id'],
        'sale_currency_id' => (int)$currency,
        'sale_total_amount' => 1000,
        'discount_amount' => 0,
        'delivery_type' => 'credit',
        'description' => 'finance facade integration test',
        'operation_date' => date('Y-m-d'),
        'idempotency_key' => 'facade-test-' . $sourceId,
    ];

    $invoiceId = $service->createInvoiceDraft($payload, 'sales');
    if ($invoiceId <= 0) {
        throw new RuntimeException('Facade did not create an invoice');
    }
    $service->postInvoice($invoiceId);
    $invoice = $pdo->prepare('SELECT invoice_status FROM invoices WHERE id = ?');
    $invoice->execute([$invoiceId]);
    if ($invoice->fetchColumn() !== 'posted') {
        throw new RuntimeException('Facade did not post the invoice');
    }

    $voucherId = $service->createReceiptVoucherDraft([
        'branch_id' => (int)$branch,
        'customer_id' => (int)$customer['id'],
        'currency_id' => (int)$currency,
        'sale_currency_id' => (int)$currency,
        'account_id' => (int)$cash['id'],
        'paid_amount' => 200,
        'source_id' => $invoiceId,
        'description' => 'finance facade receipt test',
        'operation_date' => date('Y-m-d'),
    ]);
    $service->allocatePayment($voucherId, $invoiceId, 200);
    $allocationCheck = $pdo->prepare('SELECT pa.financial_transaction_id, pa.invoice_id, pa.allocated_amount, ft.status FROM payment_allocations pa JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id WHERE pa.invoice_id = ?');
    $allocationCheck->execute([$invoiceId]);
    $allocation = $allocationCheck->fetchAll();
    $service->postReceiptVoucher($voucherId);
    $voucherCheck = $pdo->prepare('SELECT status, posted_ip, updated_ip FROM financial_transactions WHERE id = ?');
    $voucherCheck->execute([$voucherId]);
    $voucher = $voucherCheck->fetch();
    if ($voucher['status'] !== 'posted' || empty($voucher['posted_ip']) || empty($voucher['updated_ip'])) {
        throw new RuntimeException('Facade receipt audit metadata mismatch: ' . json_encode($voucher));
    }
    $service->recalculateInvoicePaymentStatus($invoiceId);

    $check = $pdo->prepare('SELECT invoice_status, payment_status, amount_received FROM invoices WHERE id = ?');
    $check->execute([$invoiceId]);
    $result = $check->fetch();
    if ($result['payment_status'] !== 'partial' || (float)$result['amount_received'] !== 200.0) {
        throw new RuntimeException('Facade payment result mismatch: ' . json_encode(['result' => $result, 'allocation' => $allocation, 'voucher' => $voucherId, 'invoice' => $invoiceId]));
    }
    echo "Finance facade integration test: PASS\n";
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    if ($voucherId > 0) {
        $stmt = $pdo->prepare('DELETE FROM payment_allocations WHERE financial_transaction_id = ?');
        $stmt->execute([$voucherId]);
        $stmt = $pdo->prepare('DELETE FROM journal_lines WHERE financial_transaction_id = ?');
        $stmt->execute([$voucherId]);
        $stmt = $pdo->prepare('DELETE FROM financial_transactions WHERE id = ?');
        $stmt->execute([$voucherId]);
    }
    if ($invoiceId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM financial_transactions WHERE reference_type = ? AND reference_id = ?');
        $stmt->execute(['invoice', $invoiceId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $transactionId) {
            $delete = $pdo->prepare('DELETE FROM journal_lines WHERE financial_transaction_id = ?');
            $delete->execute([(int)$transactionId]);
            $delete = $pdo->prepare('DELETE FROM financial_transactions WHERE id = ?');
            $delete->execute([(int)$transactionId]);
        }
        $stmt = $pdo->prepare('DELETE FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
