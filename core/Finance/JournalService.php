<?php

namespace Core\Finance;

/** Orchestrates service-finance operations without the legacy gateway. */
class JournalService
{
    private FinanceContext $context;
    private InvoiceService $invoices;
    private ReceiptService $receipts;
    private BalanceService $balances;

    public function __construct(
        FinanceContext $context,
        InvoiceService $invoices,
        ReceiptService $receipts,
        BalanceService $balances
    ) {
        $this->context = $context;
        $this->invoices = $invoices;
        $this->receipts = $receipts;
        $this->balances = $balances;
    }

    public function processServiceOperation(array $data): array
    {
        $data = $this->context->normalize($data);
        $this->context->assertUserCan('process_service_operation', 'process service operation');
        $this->context->assertFiscalPeriodOpen($data['operation_date']);

        return $this->context->executeAtomically(function () use ($data): array {
            if (
                empty($data['customer_id'])
                && in_array($data['delivery_type'], ['cash', 'bank_transfer'], true)
                && $data['paid_amount'] > 0
            ) {
                $data['customer_id'] = $this->balances->getOrCreateDefaultCashCustomer($data['branch_id'] ?: null);
            }

            $salesInvoiceId = $this->invoices->createInvoiceDraft($data, 'sales');
            $purchaseInvoiceId = null;
            if ($data['record_purchase'] === '1' && $data['supplier_id'] && $data['purchase_total_amount'] > 0) {
                $purchaseInvoiceId = $this->invoices->createInvoiceDraft($data, 'purchase');
            }

            $receiptVoucherId = null;
            if (
                $data['paid_amount'] > 0
                && in_array($data['delivery_type'], ['cash', 'bank_transfer'], true)
                && $data['account_id']
            ) {
                $receiptVoucherId = $this->receipts->createReceiptVoucherDraft($data);
                $this->receipts->allocatePayment($receiptVoucherId, $salesInvoiceId, (float)$data['paid_amount']);
                $this->receipts->postReceiptVoucher($receiptVoucherId);
                $this->invoices->recalculateInvoicePaymentStatus($salesInvoiceId);
            }

            $this->context->audit('process_service_operation', 'service_finance', $salesInvoiceId, [
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'sales_invoice_id' => $salesInvoiceId,
                'purchase_invoice_id' => $purchaseInvoiceId,
                'receipt_voucher_id' => $receiptVoucherId,
            ]);
            return [
                'sales_invoice_id' => $salesInvoiceId,
                'purchase_invoice_id' => $purchaseInvoiceId,
                'receipt_voucher_id' => $receiptVoucherId,
                'normalized_finance' => $data,
            ];
        });
    }
}
