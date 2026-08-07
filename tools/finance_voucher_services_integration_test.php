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
require_once __DIR__ . '/../core/FinanceService.php';

$service = new FinanceService($pdo, 1);
$paymentId = 0;
$expenseId = 0;
$transactionIds = [];
$sourceId = random_int(900000, 999999);
$paymentNumber = 'PAYTEST-' . $sourceId;
$expenseNumber = 'EXPTEST-' . $sourceId;

try {
    $branch = (int)$pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
    $currency = (int)$pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
    $cash = (int)$pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11101' OR account_sub_type = 'box' ORDER BY id LIMIT 1")->fetchColumn();
    $expense = (int)$pdo->query("SELECT id FROM unified_accounts WHERE account_type = 'expense' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $supplier = $pdo->query(
        "SELECT s.id FROM suppliers s JOIN unified_accounts a ON a.id = s.account_id
         WHERE s.account_id IS NOT NULL AND a.is_active = 1
           AND COALESCE(a.account_status, 'active') IN ('', '0', 'active')
           AND a.deleted_at IS NULL ORDER BY s.id LIMIT 1"
    )->fetchColumn();
    if (!$branch || !$currency || !$cash || !$expense || !$supplier) {
        throw new RuntimeException('Voucher fixtures are incomplete');
    }

    $paymentId = $service->createPaymentVoucherDraft([
        'branch_id' => $branch, 'supplier_id' => (int)$supplier, 'currency_id' => $currency,
        'purchase_currency_id' => $currency, 'account_id' => $cash, 'paid_amount' => 125,
        'operation_date' => date('Y-m-d'), 'source_type' => 'PaymentServiceTest',
        'source_id' => $sourceId, 'source_number' => $paymentNumber,
        'description' => 'payment service integration test',
    ]);
    $service->postPaymentVoucher($paymentId);

    $expenseId = $service->createExpenseVoucherDraft([
        'branch_id' => $branch, 'expense_account_id' => $expense, 'account_id' => $cash,
        'currency_id' => $currency, 'exchange_rate' => 1, 'paid_amount' => 75,
        'voucher_date' => date('Y-m-d'), 'operation_date' => date('Y-m-d'),
        'source_type' => 'ExpenseServiceTest', 'source_id' => $sourceId,
        'source_number' => $expenseNumber, 'reference_number' => $expenseNumber,
        'description' => 'expense service integration test',
    ]);
    $service->postExpenseVoucher($expenseId);

    $stmt = $pdo->prepare(
        "SELECT id, status, posted_ip, updated_ip FROM financial_transactions
          WHERE id = ?
             OR (reference_type = 'expense_voucher' AND reference_id = ?)
          ORDER BY id"
    );
    $stmt->execute([$paymentId, $expenseId]);
    $rows = $stmt->fetchAll();
    $transactionIds = array_map('intval', array_column($rows, 'id'));
    if (count($rows) !== 2 || count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'posted' && !empty($row['posted_ip']) && !empty($row['updated_ip']))) !== 2) {
        throw new RuntimeException('Posted voucher status or IP audit fields mismatch: ' . json_encode($rows));
    }
    echo "Finance payment/expense voucher integration test: PASS\n";
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    if ($paymentId > 0 || $expenseId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM financial_transactions WHERE transaction_number IN (?, ?)');
        $stmt->execute([$paymentNumber, $expenseNumber]);
        $transactionIds = array_merge($transactionIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    $ids = array_values(array_unique(array_filter($transactionIds)));
    if ($ids) {
        $list = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM payment_allocations WHERE financial_transaction_id IN ({$list})");
        $pdo->exec("DELETE FROM journal_lines WHERE financial_transaction_id IN ({$list})");
        $pdo->exec("DELETE FROM financial_transactions WHERE id IN ({$list})");
    }
    if ($expenseId > 0) {
        $pdo->prepare('DELETE FROM expense_vouchers WHERE id = ?')->execute([$expenseId]);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
