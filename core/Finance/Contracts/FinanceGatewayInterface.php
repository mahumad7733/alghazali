<?php

namespace Core\Finance\Contracts;

/**
 * Compatibility port used while the existing financial implementation is
 * migrated service by service. Services depend on this contract, never on a
 * concrete legacy class.
 */
interface FinanceGatewayInterface
{
    public function normalizeFinancialPayload(array $data): array;
    public function createInvoiceDraft(array $data, string $category): int;
    public function postInvoice(int $invoiceId): void;
    public function createReceiptVoucherDraft(array $data): int;
    public function createPaymentVoucherDraft(array $data): int;
    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void;
    public function postReceiptVoucher(int $voucherId): void;
    public function postPaymentVoucher(int $voucherId): void;
    public function recalculateInvoicePaymentStatus(int $invoiceId): void;
    public function processServiceOperation(array $data): array;
    public function receiveInvoicePayment(array $data): int;
    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int;
    public function createExpenseVoucherDraft(array $data): int;
    public function postExpenseVoucher(int $voucherId): void;
    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void;
}
