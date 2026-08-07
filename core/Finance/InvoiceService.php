<?php

namespace Core\Finance;

use Core\Finance\Contracts\InvoiceInterface;

class InvoiceService implements InvoiceInterface
{
    private FinanceContext $context;
    private FinancePostingAdapter $postingAdapter;
    public function __construct(FinanceContext $context, FinancePostingAdapter $postingAdapter)
    {
        $this->context = $context;
        $this->postingAdapter = $postingAdapter;
    }

    public function createInvoiceDraft(array $data, string $category): int
    {
        $category = strtolower($category);
        if (!in_array($category, ['sales', 'purchase'], true)) {
            throw new \InvalidArgumentException("Invalid invoice category: {$category}");
        }

        $data = $this->context->normalize($data);
        $this->context->assertUserCan('create_' . $category . '_invoice', "create {$category} invoice");
        $this->context->assertFiscalPeriodOpen($data['operation_date']);

        if (!empty($data['idempotency_key'])) {
            $stmt = $this->context->pdo()->prepare(
                'SELECT id FROM invoices WHERE source_type = ? AND source_id = ? AND invoice_category = ? LIMIT 1'
            );
            $stmt->execute([$data['source_type'], (int)$data['source_id'], $category]);
            $existing = (int)$stmt->fetchColumn();
            if ($existing > 0) {
                return $existing;
            }
        }

        $partyId = $category === 'sales' ? $data['customer_id'] : $data['supplier_id'];
        $currencyId = $category === 'sales' ? $data['sale_currency_id'] : $data['purchase_currency_id'];
        $total = $category === 'sales' ? $data['sale_total_amount'] : $data['purchase_total_amount'];
        if ($total < 0 || $currencyId <= 0) {
            throw new \InvalidArgumentException('Invalid invoice amount or currency');
        }

        return (int)$this->context->executeAtomically(function () use ($data, $category, $partyId, $currencyId, $total): int {
            $id = $this->postingAdapter->createInvoice(
                $this->context->pdo(), $data, $category, $this->context->userId()
            );
            $this->context->audit('create_' . $category . '_invoice_draft', 'invoice', $id, [
                'source_type' => $data['source_type'], 'source_id' => $data['source_id'],
                'party_id' => $partyId, 'currency_id' => $currencyId, 'total' => $total,
            ]);
            return $id;
        });
    }

    public function postInvoice(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invalid invoice id');
        }
        $this->context->assertUserCan('post_invoice', 'post invoice');
        $this->context->executeAtomically(function () use ($invoiceId): void {
            $pdo = $this->context->pdo();
            $stmt = $pdo->prepare('SELECT invoice_status, invoice_date FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new \RuntimeException("Invoice {$invoiceId} does not exist");
            }
            if (in_array((string)$invoice['invoice_status'], ['posted', 'void', 'reversed', 'cancelled'], true)) {
                throw new \RuntimeException("Invoice {$invoiceId} cannot be posted from its current state");
            }
            $this->context->assertFiscalPeriodOpen($invoice['invoice_date']);
            $this->postingAdapter->postInvoice($pdo, $invoiceId, $this->context->userId());
            $pdo->prepare('UPDATE invoices SET updated_at = COALESCE(updated_at, NOW()) WHERE id = ?')
                ->execute([$invoiceId]);
            $this->context->audit('post_invoice', 'invoice', $invoiceId);
        });
    }

    public function recalculateInvoicePaymentStatus(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $pdo = $this->context->pdo();
        $stmt = $pdo->prepare('SELECT total_amount FROM invoices WHERE id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $total = $stmt->fetchColumn();
        if ($total === false) {
            throw new \RuntimeException("Invoice {$invoiceId} does not exist");
        }
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pa.allocated_amount), 0)
             FROM payment_allocations pa
             JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
             WHERE pa.invoice_id = ? AND ft.status IN ('draft', 'posted')"
        );
        $stmt->execute([$invoiceId]);
        $received = (float)$stmt->fetchColumn();
        $status = $received >= (float)$total ? 'paid' : ($received > 0 ? 'partial' : 'unpaid');
        $pdo->prepare('UPDATE invoices SET amount_received = ?, payment_status = ? WHERE id = ?')
            ->execute([$received, $status, $invoiceId]);
    }
}
