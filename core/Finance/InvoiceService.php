<?php

namespace Core\Finance;

use Core\Finance\Contracts\InvoiceInterface;

class InvoiceService implements InvoiceInterface
{
    private \LegacyFinanceService $legacy;

    public function __construct(\LegacyFinanceService $legacy)
    {
        $this->legacy = $legacy;
    }

    public function createInvoiceDraft(array $data, string $category): int
    {
        return $this->legacy->createInvoiceDraft($data, $category);
    }

    public function postInvoice(int $invoiceId): void
    {
        $this->legacy->postInvoice($invoiceId);
    }

    public function recalculateInvoicePaymentStatus(int $invoiceId): void
    {
        $this->legacy->recalculateInvoicePaymentStatus($invoiceId);
    }
}
