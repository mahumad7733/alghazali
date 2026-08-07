<?php

namespace Core\Finance;

/** Customer/account support for cash sales. */
class BalanceService
{
    private FinanceContext $context;

    /** @var array<int,int> */
    private array $cashCustomerCache = [];

    public function __construct(FinanceContext $context)
    {
        $this->context = $context;
    }

    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int
    {
        $branchId = (int)($branchId ?: 1);
        if (isset($this->cashCustomerCache[$branchId])) {
            return $this->cashCustomerCache[$branchId];
        }

        $pdo = $this->context->pdo();
        $stmt = $pdo->prepare(
            "SELECT id, account_id FROM customers
             WHERE deleted_at IS NULL
               AND (full_name = 'Cash Sales Customer' OR full_name LIKE '%CASH%')
             ORDER BY id LIMIT 1"
        );
        $stmt->execute();
        $customer = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$customer) {
            $pdo->prepare(
                "INSERT INTO customers (full_name, phone, address, created_at, branch_id, status)
                 VALUES ('Cash Sales Customer', '-', '-', NOW(), ?, 'active')"
            )->execute([$branchId]);
            $customer = ['id' => (int)$pdo->lastInsertId(), 'account_id' => null];
            $this->context->audit('ensure_default_cash_customer', 'customers', (int)$customer['id'], [
                'branch_id' => $branchId,
            ]);
        }

        $customerId = (int)$customer['id'];
        $accountId = (int)($customer['account_id'] ?? 0);
        if ($accountId <= 0) {
            $accountId = $this->ensureCustomerAccount($customerId, $branchId);
        }
        if ($accountId <= 0) {
            throw new \RuntimeException('Unable to resolve the cash customer account');
        }

        $this->cashCustomerCache[$branchId] = $customerId;
        return $customerId;
    }

    private function ensureCustomerAccount(int $customerId, int $branchId): int
    {
        $pdo = $this->context->pdo();
        $parent = (int)$pdo->query(
            "SELECT id FROM unified_accounts
             WHERE account_code = '11201'
                OR (account_type = 'asset' AND account_sub_type = 'customer' AND parent_id IS NOT NULL)
             ORDER BY account_code = '11201' DESC, id LIMIT 1"
        )->fetchColumn();
        if ($parent <= 0) {
            $parent = 10;
        }

        $stmt = $pdo->prepare('SELECT full_name FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $name = trim((string)$stmt->fetchColumn()) ?: "Customer {$customerId}";

        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(account_code, '[^0-9]', '') AS UNSIGNED)), 1120100000)
             FROM unified_accounts WHERE parent_id = ?"
        );
        $stmt->execute([$parent]);
        $code = (string)((int)$stmt->fetchColumn() + 1);
        $pdo->prepare(
            "INSERT INTO unified_accounts
                (account_code, account_name_ar, account_type, account_sub_type, owner_type,
                 normal_balance, parent_id, branch_id, is_active, account_status, created_at)
             VALUES (?, ?, 'asset', 'customer', 'customer', 'debit', ?, ?, 1, 'active', NOW())"
        )->execute([$code, "Customer - {$name}", $parent, $branchId]);
        $accountId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE customers SET account_id = ? WHERE id = ?')
            ->execute([$accountId, $customerId]);
        $this->context->audit('ensure_customer_account', 'unified_accounts', $accountId, [
            'customer_id' => $customerId,
        ]);
        return $accountId;
    }
}
