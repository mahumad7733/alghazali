<?php

namespace Core\Finance;

use Core\Finance\Contracts\FinanceGatewayInterface;

/**
 * Anti-corruption adapter around the pre-refactor implementation.
 *
 * Keeping this adapter explicit makes the migration boundary visible and
 * lets the domain services be tested with a fake gateway later.
 */
final class LegacyFinanceGateway implements FinanceGatewayInterface
{
    public function __construct(private \LegacyFinanceService $legacy)
    {
    }

    public function normalizeFinancialPayload(array $data): array { return $this->legacy->normalizeFinancialPayload($data); }
    public function createInvoiceDraft(array $data, string $category): int { return $this->legacy->createInvoiceDraft($data, $category); }
    public function postInvoice(int $invoiceId): void { $this->legacy->postInvoice($invoiceId); }
    public function createReceiptVoucherDraft(array $data): int { return $this->legacy->createReceiptVoucherDraft($data); }
    public function createPaymentVoucherDraft(array $data): int { return $this->legacy->createPaymentVoucherDraft($data); }
    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void { $this->legacy->allocatePayment($voucherId, $invoiceId, $allocatedAmount); }
    public function postReceiptVoucher(int $voucherId): void { $this->legacy->postReceiptVoucher($voucherId); }
    public function postPaymentVoucher(int $voucherId): void { $this->legacy->postPaymentVoucher($voucherId); }
    public function recalculateInvoicePaymentStatus(int $invoiceId): void { $this->legacy->recalculateInvoicePaymentStatus($invoiceId); }
    public function processServiceOperation(array $data): array { return $this->legacy->processServiceOperation($data); }
    public function receiveInvoicePayment(array $data): int { return $this->legacy->receiveInvoicePayment($data); }
    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int { return $this->legacy->getOrCreateDefaultCashCustomer($branchId); }
    public function createExpenseVoucherDraft(array $data): int { return $this->legacy->createExpenseVoucherDraft($data); }
    public function postExpenseVoucher(int $voucherId): void { $this->legacy->postExpenseVoucher($voucherId); }
    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void { $this->legacy->processExpenseApproval($voucherId, $level, $approved, $comment); }
}
