<?php

namespace Core\Finance;

use Core\Finance\Contracts\AuditLoggerInterface;
use PDO;
use Throwable;

/** Shared infrastructure for migrated finance services. */
final class FinanceContext
{
    public function __construct(
        private PDO $pdo,
        private int $userId,
        private TransactionManager $transactions,
        private AuditLoggerInterface $audit
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function requestIp(): string
    {
        return trim((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')) ?: '127.0.0.1';
    }

    public function normalize(array $data): array
    {
        $discount = (float)($data['discount_amount'] ?? $data['discount'] ?? 0);
        $paid = (float)($data['paid_amount'] ?? $data['received_amount'] ?? $data['amount_received'] ?? 0);
        $saleTotal = (float)($data['sale_total_amount'] ?? $data['total_amount'] ?? $data['sale_price'] ?? 0);
        $purchaseTotal = (float)($data['purchase_total_amount'] ?? $data['purchase_price'] ?? 0);
        $tax = (float)($data['tax_amount'] ?? 0);
        $net = max(0.0, $saleTotal - $discount + $tax);
        $operationDate = $data['operation_date'] ?? null;
        if (function_exists('normalize_datetime_db')) {
            $operationDate = normalize_datetime_db($operationDate);
        }

        return [
            'branch_id' => isset($data['branch_id']) ? (int)$data['branch_id'] : null,
            'source_type' => $data['source_type'] ?? $data['service_type'] ?? null,
            'source_id' => isset($data['source_id']) ? (int)$data['source_id'] : null,
            'customer_id' => isset($data['customer_id']) ? (int)$data['customer_id'] : null,
            'supplier_id' => isset($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            'agent_id' => isset($data['agent_id']) ? (int)$data['agent_id'] : null,
            'account_id' => isset($data['account_id']) ? (int)$data['account_id'] : (isset($data['payment_account_id']) ? (int)$data['payment_account_id'] : null),
            'currency_id' => isset($data['currency_id']) ? (int)$data['currency_id'] : (isset($data['sale_currency_id']) ? (int)$data['sale_currency_id'] : null),
            'sale_currency_id' => isset($data['sale_currency_id']) ? (int)$data['sale_currency_id'] : (isset($data['currency_id']) ? (int)$data['currency_id'] : null),
            'purchase_currency_id' => isset($data['purchase_currency_id']) ? (int)$data['purchase_currency_id'] : (isset($data['pur_currency_id']) ? (int)$data['pur_currency_id'] : (isset($data['currency_id']) ? (int)$data['currency_id'] : null)),
            'exchange_rate' => (float)($data['exchange_rate'] ?? 1),
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'paid_amount' => $paid,
            'sale_total_amount' => $saleTotal,
            'purchase_total_amount' => $purchaseTotal,
            'total_amount' => $saleTotal,
            'net_amount' => $net,
            'remaining_amount' => max(0, $net - $paid),
            'transaction_status' => $data['transaction_status'] ?? $data['invoice_status'] ?? 'draft',
            'delivery_type' => $data['delivery_type'] ?? 'draft',
            'description' => trim((string)($data['description'] ?? '')),
            'operation_date' => $operationDate,
            'source_number' => $data['source_number'] ?? null,
            'record_purchase' => isset($data['record_purchase']) ? (string)$data['record_purchase'] : '1',
            'expense_account_id' => isset($data['expense_account_id']) ? (int)$data['expense_account_id'] : null,
            'voucher_date' => $data['voucher_date'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'cost_center_id' => isset($data['cost_center_id']) ? (int)$data['cost_center_id'] : null,
            'budget_id' => isset($data['budget_id']) ? (int)$data['budget_id'] : null,
            'idempotency_key' => $data['idempotency_key'] ?? $data['request_id'] ?? null,
        ];
    }

    public function executeAtomically(callable $callback)
    {
        return $this->transactions->executeAtomically($callback);
    }

    public function assertUserCan(string $permission, string $operation): void
    {
        try {
            if (function_exists('has_permission')) {
                $reflection = new \ReflectionFunction('has_permission');
                $hasPermission = false;
                if ($reflection->getNumberOfParameters() >= 2) {
                    $hasPermission = has_permission($this->userId, $permission);
                } else {
                    $hadUserId = array_key_exists('user_id', $_SESSION ?? []);
                    $previousUserId = $_SESSION['user_id'] ?? null;
                    if (!$hadUserId) {
                        $_SESSION['user_id'] = $this->userId;
                    }
                    $hasPermission = has_permission($permission);
                    if (!$hadUserId) {
                        unset($_SESSION['user_id']);
                    } else {
                        $_SESSION['user_id'] = $previousUserId;
                    }
                }
                if (!$hasPermission) {
                    throw new \RuntimeException("Permission denied for {$operation}");
                }
                return;
            }
            $permissions = $_SESSION['_permissions'] ?? [];
            if (is_array($permissions) && $permissions !== []
                && !in_array($permission, $permissions, true)
                && !in_array('*', $permissions, true)
                && !in_array('super_admin', $permissions, true)) {
                throw new \RuntimeException("Permission denied for {$operation}");
            }
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'permission')) {
                throw $e;
            }
        }
    }

    public function assertFiscalPeriodOpen(?string $operationDate): void
    {
        $date = substr($operationDate ?: date('Y-m-d'), 0, 10);
        try {
            $stmt = $this->pdo->prepare(
                'SELECT period_name, is_closed FROM fiscal_periods
                 WHERE ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$date]);
            $period = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($period && !empty($period['is_closed'])) {
                throw new \RuntimeException("Fiscal period {$period['period_name']} is closed");
            }
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'closed')) {
                throw $e;
            }
        }
    }

    public function audit(string $action, string $entity, ?int $entityId, array $extra = []): void
    {
        $this->audit->log($action, $entity, $entityId, $extra);
    }

    public function resolvePartyAccountId(string $entityType, ?int $entityId): ?int
    {
        if ($entityId === null || $entityId <= 0) {
            return null;
        }
        $table = $entityType === 'supplier' ? 'suppliers' : 'customers';
        $stmt = $this->pdo->prepare("SELECT account_id FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$entityId]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (int)$id;
    }

    public function assertAccountUsable(int $accountId, string $label): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_active, account_status, deleted_at FROM unified_accounts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$account || !empty($account['deleted_at']) || (int)$account['is_active'] !== 1
            || !in_array((string)($account['account_status'] ?? 'active'), ['', '0', 'active'], true)) {
            throw new \RuntimeException("Account {$label} {$accountId} is not usable");
        }
    }
}
