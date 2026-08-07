<?php

namespace Core\Finance;

use Core\Finance\Contracts\ReceiptInterface;

class ReceiptService implements ReceiptInterface
{
    private FinanceContext $context;
    private InvoiceService $invoices;
    private FinancePostingAdapter $postingAdapter;
    public function __construct(FinanceContext $context, InvoiceService $invoices, FinancePostingAdapter $postingAdapter)
    {
        $this->context = $context;
        $this->invoices = $invoices;
        $this->postingAdapter = $postingAdapter;
    }

    public function createReceiptVoucherDraft(array $data): int
    {
        $data = $this->context->normalize($data);
        $this->context->assertUserCan('create_receipt_voucher', 'create receipt voucher');
        $this->context->assertFiscalPeriodOpen($data['operation_date']);
        $partyAccount = $this->context->resolvePartyAccountId('customer', $data['customer_id']);
        if (!$partyAccount || empty($data['account_id']) || (float)$data['paid_amount'] <= 0) {
            throw new \InvalidArgumentException('Receipt requires a customer account, cash account and positive amount');
        }
        $this->context->assertAccountUsable((int)$data['account_id'], 'receipt');
        $this->context->assertAccountUsable($partyAccount, 'customer');
        return (int)$this->context->executeAtomically(function () use ($data, $partyAccount): int {
            $id = $this->postingAdapter->createReceiptVoucher($this->context->pdo(), [
                'branch_id' => $data['branch_id'], 'party_type' => 'customer',
                'party_id' => $data['customer_id'], 'amount' => $data['paid_amount'],
                'currency_id' => $data['sale_currency_id'], 'exchange_rate' => $data['exchange_rate'],
                'cash_account_id' => $data['account_id'], 'party_account_id' => $partyAccount,
                'reference' => $data['source_number'] ?? $data['source_id'],
                'description' => $data['description'],
            ], $this->context->userId());
            $this->context->audit('create_receipt_voucher_draft', 'receipt_voucher', $id, [
                'customer_id' => $data['customer_id'], 'amount' => $data['paid_amount'],
            ]);
            return $id;
        });
    }

    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void
    {
        if ($voucherId <= 0 || $invoiceId <= 0 || $allocatedAmount <= 0) {
            throw new \InvalidArgumentException('Invalid payment allocation');
        }
        $this->context->executeAtomically(function () use ($voucherId, $invoiceId, $allocatedAmount): void {
            $pdo = $this->context->pdo();
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(allocated_amount), 0) FROM payment_allocations
                 WHERE financial_transaction_id = ? AND invoice_id = ? FOR UPDATE'
            );
            $stmt->execute([$voucherId, $invoiceId]);
            if ((float)$stmt->fetchColumn() > 0) {
                throw new \RuntimeException('Payment allocation already exists');
            }
            $stmt = $pdo->prepare(
                'SELECT COALESCE(net_amount, total_amount - discount) - COALESCE(amount_received, 0)
                 FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$invoiceId]);
            $remaining = $stmt->fetchColumn();
            if ($remaining === false || (float)$remaining < $allocatedAmount - 0.00001) {
                throw new \RuntimeException('Payment allocation exceeds invoice balance');
            }
            $stmt = $pdo->prepare('SELECT COALESCE(amount, 0) FROM financial_transactions WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$voucherId]);
            $voucherAmount = (float)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(allocated_amount), 0) FROM payment_allocations WHERE financial_transaction_id = ?');
            $stmt->execute([$voucherId]);
            if ($voucherAmount > 0 && (float)$stmt->fetchColumn() + $allocatedAmount > $voucherAmount + 0.00001) {
                throw new \RuntimeException('Payment allocation exceeds voucher amount');
            }
            $pdo->prepare('INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)')
                ->execute([$voucherId, $invoiceId, $allocatedAmount]);
            $this->context->audit('payment_allocation', 'payment_allocations', null, compact('voucherId', 'invoiceId', 'allocatedAmount'));
        });
    }

    public function postReceiptVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new \InvalidArgumentException('Invalid receipt voucher id');
        }
        $this->context->assertUserCan('post_receipt_voucher', 'post receipt voucher');
        $this->context->executeAtomically(function () use ($voucherId): void {
            $this->postingAdapter->postReceiptVoucher($this->context->pdo(), $voucherId, $this->context->userId());
            $this->context->pdo()->prepare(
                'UPDATE financial_transactions SET posted_ip = COALESCE(posted_ip, ?), updated_ip = ? WHERE id = ?'
            )->execute([$this->context->requestIp(), $this->context->requestIp(), $voucherId]);
            $this->context->audit('post_receipt_voucher', 'receipt_voucher', $voucherId);
        });
    }

    public function receiveInvoicePayment(array $data): int
    {
        $data = $this->context->normalize($data);
        if (empty($data['paid_amount']) || empty($data['source_id'])) {
            throw new \InvalidArgumentException('Invoice id and positive payment amount are required');
        }
        $this->context->assertUserCan('receive_invoice_payment', 'receive invoice payment');
        $this->context->assertFiscalPeriodOpen($data['operation_date']);
        return $this->context->executeAtomically(function () use ($data): int {
            $voucherId = $this->createReceiptVoucherDraft($data);
            $this->allocatePayment($voucherId, (int)$data['source_id'], (float)$data['paid_amount']);
            $this->postReceiptVoucher($voucherId);
            $this->invoices->recalculateInvoicePaymentStatus((int)$data['source_id']);
            $this->context->audit('receive_invoice_payment', 'invoice', (int)$data['source_id'], [
                'amount' => $data['paid_amount'], 'voucher_id' => $voucherId,
            ]);
            return $voucherId;
        });
    }
}
