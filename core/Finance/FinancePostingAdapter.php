<?php

namespace Core\Finance;

use PDO;

final class FinancePostingAdapter
{
    public function __construct()
    {
        require_once __DIR__ . '/../../includes/accounting_functions.php';
    }

    public function createInvoice(PDO $pdo, array $data, string $category, int $userId): int
    {
        $cost = 0.0;
        if ($category === 'sales' && $data['purchase_total_amount'] > 0) {
            $cost = $data['purchase_total_amount'];
            if ($data['sale_currency_id'] !== $data['purchase_currency_id'] && $data['exchange_rate'] > 0) {
                $cost *= $data['exchange_rate'];
            }
        }

        return (int)php_create_invoice(
            $pdo,
            $category,
            $data['branch_id'],
            $data['source_type'],
            $data['source_id'],
            $category === 'sales' ? $data['customer_id'] : $data['supplier_id'],
            $category === 'sales' ? $data['sale_currency_id'] : $data['purchase_currency_id'],
            $category === 'sales' ? $data['sale_total_amount'] : $data['purchase_total_amount'],
            $category === 'sales' ? $data['discount_amount'] : 0,
            $cost,
            $data['delivery_type'],
            $data['description'],
            $data['operation_date'],
            $userId,
            $data['agent_id'],
            $data['account_id']
        );
    }

    public function postInvoice(PDO $pdo, int $invoiceId, int $userId): void
    {
        php_post_invoice($pdo, $invoiceId, $userId, true);
    }

    public function createReceiptVoucher(PDO $pdo, array $data, int $userId): int
    {
        return $this->createVoucher($pdo, 'receipt', $data, $userId);
    }

    public function createPaymentVoucher(PDO $pdo, array $data, int $userId): int
    {
        return $this->createVoucher($pdo, 'payment', $data, $userId);
    }

    public function createExpenseVoucher(PDO $pdo, array $data, int $userId): int
    {
        $stmt = $pdo->prepare(
            'CALL sp_create_expense_voucher(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @v_id, @v_num)'
        );
        $stmt->execute([
            $data['branch_id'], $data['voucher_date'] ?: date('Y-m-d'),
            $data['expense_account_id'], $data['account_id'], $data['supplier_id'] ?: null,
            $data['cost_center_id'] ?: null, $data['currency_id'],
            (float)($data['exchange_rate'] ?? 1.0), $data['paid_amount'],
            (float)($data['tax_amount'] ?? 0), $data['description'] ?: null,
            $data['reference_number'] ?: null, $data['budget_id'] ?: null,
            $userId, $data['source_number'] ?? $data['source_id'],
        ]);
        $stmt->closeCursor();
        return (int)$pdo->query('SELECT @v_id')->fetchColumn();
    }

    public function postReceiptVoucher(PDO $pdo, int $voucherId, int $userId): void
    {
        php_post_receipt_voucher($pdo, $voucherId, $userId);
    }

    public function postPaymentVoucher(PDO $pdo, int $voucherId, int $userId): void
    {
        php_post_payment_voucher($pdo, $voucherId, $userId, true);
    }

    public function postExpenseVoucher(PDO $pdo, int $voucherId, int $userId): void
    {
        $stmt = $pdo->prepare('CALL sp_post_expense_voucher(?, ?)');
        $stmt->execute([$voucherId, $userId]);
        $stmt->closeCursor();
    }

    public function processExpenseApproval(PDO $pdo, int $voucherId, int $userId, int $level, bool $approved, ?string $comment): void
    {
        $stmt = $pdo->prepare('CALL sp_process_expense_approval(?, ?, ?, ?, ?)');
        $stmt->execute([$voucherId, $userId, $level, $approved ? 1 : 0, $comment]);
        $stmt->closeCursor();
    }

    public static function createFinancialEntry(PDO $pdo, ...$arguments)
    {
        self::loadLegacyFunctions();
        $arguments = array_pad($arguments, 16, null);
        $arguments[15] = true;
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) {
            $pdo->beginTransaction();
        }
        try {
            $transactionId = (int)php_create_financial_entry($pdo, ...$arguments);
            $userId = (int)($arguments[9] ?? 0);
            (new self())->audit($pdo, $userId, 'create_financial_entry', 'financial_transaction', $transactionId, [
                'transaction_type' => $arguments[1] ?? null,
                'reference_type' => $arguments[13] ?? null,
                'reference_id' => $arguments[14] ?? null,
            ]);
            if ($startedHere) {
                $pdo->commit();
            }
            return $transactionId;
        } catch (\Throwable $exception) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function deleteFinancialTransactionAndReverse(PDO $pdo, int $transactionId, ?int $userId = null): void
    {
        self::loadLegacyFunctions();
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) {
            $pdo->beginTransaction();
        }
        try {
            php_delete_financial_transaction_and_reverse($pdo, $transactionId);
            (new self())->audit(
                $pdo,
                (int)($userId ?: ($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0)),
                'delete_financial_transaction',
                'financial_transaction',
                $transactionId
            );
            if ($startedHere) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function handleEntityAccountCreation(PDO $pdo, string $entityType, int $entityId, string $entityName): int
    {
        self::loadLegacyFunctions();
        return (int)php_handle_entity_account_creation($pdo, $entityType, $entityId, $entityName);
    }

    public static function recalculateInvoicePayment(PDO $pdo, int $invoiceId): void
    {
        self::loadLegacyFunctions();
        php_recalculate_invoice_payment($pdo, $invoiceId);
    }

    private function createVoucher(PDO $pdo, string $type, array $data, int $userId): int
    {
        $procedure = $type === 'receipt' ? 'sp_create_receipt_voucher' : 'sp_create_payment_voucher';
        $stmt = $pdo->prepare(
            "CALL {$procedure}(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @v_id, @v_num)"
        );
        $stmt->execute([
            $data['branch_id'], $data['party_type'], $data['party_id'], $data['amount'],
            $data['currency_id'], $data['exchange_rate'], $data['cash_account_id'],
            $data['party_account_id'], $data['reference'], $data['description'], $userId, null,
        ]);
        $stmt->closeCursor();
        return (int)$pdo->query('SELECT @v_id')->fetchColumn();
    }

    private static function loadLegacyFunctions(): void
    {
        require_once __DIR__ . '/../../includes/accounting_functions.php';
    }

    private function audit(PDO $pdo, int $userId, string $action, string $entityType, int $entityId, array $extra = []): void
    {
        (new AuditLogger($pdo, $userId))->log($action, $entityType, $entityId, $extra);
    }
}
