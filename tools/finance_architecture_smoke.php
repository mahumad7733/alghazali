<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/FinanceService.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('CREATE TABLE smoke (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');

$service = new FinanceService($pdo, 1);
$payload = $service->normalizeFinancialPayload([
    'sale_total_amount' => 100,
    'discount_amount' => 10,
    'paid_amount' => 25,
]);

if ($payload['net_amount'] !== 90.0 || $payload['remaining_amount'] !== 65.0) {
    throw new RuntimeException('Financial payload normalization mismatch');
}

$service->executeAtomically(function () use ($pdo): void {
    $pdo->prepare('INSERT INTO smoke (value) VALUES (?)')->execute(['ok']);
});

if ((int)$pdo->query('SELECT COUNT(*) FROM smoke')->fetchColumn() !== 1) {
    throw new RuntimeException('Atomic transaction did not commit');
}

$expectedMethods = [
    'createInvoiceDraft', 'postInvoice', 'createReceiptVoucherDraft',
    'createPaymentVoucherDraft', 'allocatePayment', 'postReceiptVoucher',
    'postPaymentVoucher', 'recalculateInvoicePaymentStatus',
    'processServiceOperation', 'receiveInvoicePayment',
    'getOrCreateDefaultCashCustomer', 'createExpenseVoucherDraft',
    'postExpenseVoucher', 'processExpenseApproval',
];

foreach ($expectedMethods as $method) {
    if (!method_exists($service, $method)) {
        throw new RuntimeException("Facade method missing: {$method}");
    }
}

echo "Finance architecture smoke test: PASS\n";
