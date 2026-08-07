<?php

namespace Core\Finance\Contracts;

interface ReceiptInterface
{
    public function createReceiptVoucherDraft(array $data): int;
    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void;
    public function postReceiptVoucher(int $voucherId): void;
    public function receiveInvoicePayment(array $data): int;
}
