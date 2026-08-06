<?php

namespace Core\Finance;

use Core\Finance\Contracts\ReceiptInterface;

class ReceiptService implements ReceiptInterface
{
    private \LegacyFinanceService $legacy;

    public function __construct(\LegacyFinanceService $legacy)
    {
        $this->legacy = $legacy;
    }

    public function createReceiptVoucherDraft(array $data): int
    {
        return $this->legacy->createReceiptVoucherDraft($data);
    }

    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void
    {
        $this->legacy->allocatePayment($voucherId, $invoiceId, $allocatedAmount);
    }

    public function postReceiptVoucher(int $voucherId): void
    {
        $this->legacy->postReceiptVoucher($voucherId);
    }

    public function receiveInvoicePayment(array $data): int
    {
        return $this->legacy->receiveInvoicePayment($data);
    }
}
