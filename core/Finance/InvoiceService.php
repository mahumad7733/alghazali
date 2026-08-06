<?php

namespace Core\Finance;

use Core\Finance\Contracts\InvoiceInterface;
use Core\Finance\Contracts\FinanceGatewayInterface;

class InvoiceService implements InvoiceInterface
{
    private FinanceGatewayInterface $gateway;

    public function __construct(FinanceGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function createInvoiceDraft(array $data, string $category): int
    {
        return $this->gateway->createInvoiceDraft($data, $category);
    }

    public function postInvoice(int $invoiceId): void
    {
        $this->gateway->postInvoice($invoiceId);
    }

    public function recalculateInvoicePaymentStatus(int $invoiceId): void
    {
        $this->gateway->recalculateInvoicePaymentStatus($invoiceId);
    }
}
