<?php

namespace Core\Finance;

use Core\Finance\Contracts\InvoiceInterface;
use Core\Finance\Contracts\FinanceGatewayInterface;

class InvoiceService implements InvoiceInterface
{
    private FinanceContext $context;
    private ?FinanceGatewayInterface $gateway;

    public function __construct(FinanceContext $context, ?FinanceGatewayInterface $gateway = null)
    {
        $this->context = $context;
        $this->gateway = $gateway;
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

        if (!function_exists('php_create_invoice')) {
            throw new \RuntimeException('php_create_invoice is not loaded');
        }
        $cost = 0.0;
        if ($category === 'sales' && $data['purchase_total_amount'] > 0) {
            $cost = $data['purchase_total_amount'];
            if ($data['sale_currency_id'] !== $data['purchase_currency_id'] && $data['exchange_rate'] > 0) {
                $cost *= $data['exchange_rate'];
            }
        }
        $id = php_create_invoice(
            $this->context->pdo(), $category, $data['branch_id'], $data['source_type'],
            $data['source_id'], $partyId, $currencyId, $total,
            $category === 'sales' ? $data['discount_amount'] : 0,
            $cost, $data['delivery_type'], $data['description'],
            $data['operation_date'], $this->context->userId(), $data['agent_id'], $data['account_id']
        );
        $this->context->audit('create_' . $category . '_invoice_draft', 'invoice', (int)$id, [
            'source_type' => $data['source_type'], 'source_id' => $data['source_id'],
            'party_id' => $partyId, 'currency_id' => $currencyId, 'total' => $total,
        ]);
        return (int)$id;
    }

    public function postInvoice(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invalid invoice id');
        }
        $this->context->assertUserCan('post_invoice', 'post invoice');
        $pdo = $this->context->pdo();
        $pdo->beginTransaction();
        try {
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
            if (!function_exists('php_post_invoice')) {
                throw new \RuntimeException('php_post_invoice is not loaded');
            }
            php_post_invoice($pdo, $invoiceId, $this->context->userId(), true);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->context->audit('post_invoice', 'invoice', $invoiceId);
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
