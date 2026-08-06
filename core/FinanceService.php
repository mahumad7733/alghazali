<?php

require_once __DIR__ . '/../includes/accounting_functions.php';
require_once __DIR__ . '/LegacyFinanceService.php';

// Backward-compatible facade. The legacy implementation remains available
// behind the service layer while operations are migrated incrementally.
require_once __DIR__ . '/Finance/Contracts/TransactionManagerInterface.php';
require_once __DIR__ . '/Finance/Contracts/AuditLoggerInterface.php';
require_once __DIR__ . '/Finance/Contracts/InvoiceInterface.php';
require_once __DIR__ . '/Finance/Contracts/ReceiptInterface.php';
require_once __DIR__ . '/Finance/Contracts/PaymentInterface.php';
require_once __DIR__ . '/Finance/TransactionManager.php';
require_once __DIR__ . '/Finance/AuditLogger.php';
require_once __DIR__ . '/Finance/FinanceContext.php';
require_once __DIR__ . '/Finance/Exceptions/FinanceException.php';
require_once __DIR__ . '/Finance/Exceptions/AccountResolutionFailedException.php';
require_once __DIR__ . '/Finance/Exceptions/FiscalPeriodClosedException.php';
require_once __DIR__ . '/Finance/Exceptions/PermissionDeniedException.php';
require_once __DIR__ . '/Finance/InvoiceService.php';
require_once __DIR__ . '/Finance/ReceiptService.php';
require_once __DIR__ . '/Finance/PaymentService.php';
require_once __DIR__ . '/Finance/ExpenseService.php';
require_once __DIR__ . '/Finance/JournalService.php';
require_once __DIR__ . '/Finance/BalanceService.php';

class FinanceService
{
    private \Core\Finance\InvoiceService $invoiceService;
    private \Core\Finance\ReceiptService $receiptService;
    private \Core\Finance\PaymentService $paymentService;
    private \Core\Finance\ExpenseService $expenseService;
    private \Core\Finance\JournalService $journalService;
    private \Core\Finance\BalanceService $balanceService;
    private \Core\Finance\TransactionManager $transactionManager;
    private \Core\Finance\FinanceContext $context;

    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->transactionManager = new \Core\Finance\TransactionManager($pdo);
        $audit = new \Core\Finance\AuditLogger($pdo, (int)($userId ?: ($_SESSION['admin_id'] ?? 1)));
        $this->context = new \Core\Finance\FinanceContext(
            $pdo,
            (int)($userId ?: ($_SESSION['admin_id'] ?? 1)),
            $this->transactionManager,
            $audit
        );
        $this->invoiceService = new \Core\Finance\InvoiceService($this->context);
        $this->receiptService = new \Core\Finance\ReceiptService($this->context, $this->invoiceService);
        $this->paymentService = new \Core\Finance\PaymentService($this->context);
        $this->expenseService = new \Core\Finance\ExpenseService($this->context);
        $this->balanceService = new \Core\Finance\BalanceService($this->context);
        $this->journalService = new \Core\Finance\JournalService(
            $this->context,
            $this->invoiceService,
            $this->receiptService,
            $this->balanceService
        );
    }

    public function normalizeFinancialPayload(array $data): array { return $this->context->normalize($data); }
    public function executeAtomically(callable $callback) { return $this->transactionManager->executeAtomically($callback); }
    public function createInvoiceDraft(array $data, string $category): int { return $this->invoiceService->createInvoiceDraft($data, $category); }
    public function postInvoice(int $invoiceId): void { $this->invoiceService->postInvoice($invoiceId); }
    public function createReceiptVoucherDraft(array $data): int { return $this->receiptService->createReceiptVoucherDraft($data); }
    public function createPaymentVoucherDraft(array $data): int { return $this->paymentService->createPaymentVoucherDraft($data); }
    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void { $this->receiptService->allocatePayment($voucherId, $invoiceId, $allocatedAmount); }
    public function postReceiptVoucher(int $voucherId): void { $this->receiptService->postReceiptVoucher($voucherId); }
    public function postPaymentVoucher(int $voucherId): void { $this->paymentService->postPaymentVoucher($voucherId); }
    public function recalculateInvoicePaymentStatus(int $invoiceId): void { $this->invoiceService->recalculateInvoicePaymentStatus($invoiceId); }
    public function processServiceOperation(array $data): array { return $this->journalService->processServiceOperation($data); }
    public function receiveInvoicePayment(array $data): int { return $this->receiptService->receiveInvoicePayment($data); }
    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int { return $this->balanceService->getOrCreateDefaultCashCustomer($branchId); }
    public function createExpenseVoucherDraft(array $data): int { return $this->expenseService->createExpenseVoucherDraft($data); }
    public function postExpenseVoucher(int $voucherId): void { $this->expenseService->postExpenseVoucher($voucherId); }
    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void { $this->expenseService->processExpenseApproval($voucherId, $level, $approved, $comment); }
}


