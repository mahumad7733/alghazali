<?php

namespace Core\Finance\Contracts;

interface InvoiceInterface
{
    public function createInvoiceDraft(array $data, string $category): int;
    public function postInvoice(int $invoiceId): void;
    public function recalculateInvoicePaymentStatus(int $invoiceId): void;
}
