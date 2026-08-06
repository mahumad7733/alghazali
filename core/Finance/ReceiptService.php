<?php

namespace Core\Finance;

use Core\Finance\Contracts\ReceiptInterface;
use Core\Finance\Contracts\FinanceGatewayInterface;

class ReceiptService implements ReceiptInterface
{
    private FinanceGatewayInterface $gateway;

    public function __construct(FinanceGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function createReceiptVoucherDraft(array $data): int
    {
        return $this->gateway->createReceiptVoucherDraft($data);
    }

    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void
    {
        $this->gateway->allocatePayment($voucherId, $invoiceId, $allocatedAmount);
    }

    public function postReceiptVoucher(int $voucherId): void
    {
        $this->gateway->postReceiptVoucher($voucherId);
    }

    public function receiveInvoicePayment(array $data): int
    {
        return $this->gateway->receiveInvoicePayment($data);
    }
}
