<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class CompanyFinanceService
{
    public function __construct(private Database $database)
    {
    }

    /** @return array<string, mixed> */
    public function summary(array $actor, ?int $requestedCompanyId = null): array
    {
        $companyId = $this->resolveCompanyId($actor, $requestedCompanyId);
        $pdo = $this->database->pdo();
        $company = $this->one($pdo, 'SELECT id, legal_name, trade_name, base_currency_id FROM companies WHERE id = :id', ['id' => $companyId]);
        if ($company === null) {
            Response::error('الشركة المطلوبة غير موجودة.', 'NOT_FOUND', 404);
        }
        $accounts = $pdo->prepare(
            'SELECT a.id, a.account_code, a.name_ar, a.account_type, a.current_balance, a.is_active,
                    c.id AS currency_id, c.code AS currency_code, c.name_ar AS currency_name, c.symbol_ar AS currency_symbol
             FROM accounts a INNER JOIN currencies c ON c.id = a.currency_id
             WHERE a.company_id = :company_id ORDER BY c.code, a.account_code'
        );
        $accounts->execute(['company_id' => $companyId]);
        $settings = $this->settings($pdo, $companyId, (int) $company['base_currency_id']);
        return ['company' => $company, 'accounts' => $accounts->fetchAll(), 'settings' => $settings];
    }

    /** @return list<array<string, mixed>> */
    public function transactions(array $actor, ?int $requestedCompanyId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $companyId = $this->resolveCompanyId($actor, $requestedCompanyId);
        $params = ['company_id' => $companyId];
        $where = 'a.company_id = :company_id';
        if ($startDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1) {
            $where .= ' AND tx.created_at >= :start_date';
            $params['start_date'] = $startDate . ' 00:00:00';
        }
        if ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) === 1) {
            $where .= ' AND tx.created_at <= :end_date';
            $params['end_date'] = $endDate . ' 23:59:59';
        }
        $statement = $this->database->pdo()->prepare(
            "SELECT tx.id, tx.transaction_type, tx.debit_amount, tx.credit_amount, tx.reference_type, tx.reference_id,
                    tx.note_ar, tx.created_at, a.id AS account_id, a.account_code, a.name_ar AS account_name,
                    c.code AS currency_code, c.name_ar AS currency_name, c.symbol_ar AS currency_symbol,
                    b.booking_number, b.status AS booking_status, u.full_name AS created_by
             FROM account_transactions tx
             INNER JOIN accounts a ON a.id = tx.account_id
             INNER JOIN currencies c ON c.id = a.currency_id
             LEFT JOIN bookings b ON b.id = tx.booking_id
             LEFT JOIN users u ON u.id = tx.created_by_user_id
             WHERE {$where} ORDER BY tx.created_at DESC, tx.id DESC LIMIT 500"
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function updateSettings(array $actor, int $companyId, array $input): array
    {
        $this->assertCompanyScope($actor, $companyId, true);
        $settlementDays = filter_var($input['settlement_cycle_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        $paymentDueDays = filter_var($input['payment_due_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 365]]);
        $creditLimit = filter_var($input['credit_limit_amount'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
        $allowAgentCredit = filter_var($input['allow_agent_credit'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $notes = Security::cleanText($input['notes_ar'] ?? null, 500);
        if ($settlementDays === false || $paymentDueDays === false || $creditLimit === false || $allowAgentCredit === null) {
            Response::error('تحقق من إعدادات التسوية والاستحقاق والائتمان.', 'VALIDATION_ERROR', 422);
        }
        $pdo = $this->database->pdo();
        $company = $this->one($pdo, 'SELECT id FROM companies WHERE id = :id', ['id' => $companyId]);
        if ($company === null) {
            Response::error('الشركة المطلوبة غير موجودة.', 'NOT_FOUND', 404);
        }
        $statement = $pdo->prepare(
            'INSERT INTO company_financial_settings (company_id, settlement_cycle_days, payment_due_days, credit_limit_amount, allow_agent_credit, notes_ar, updated_by_user_id)
             VALUES (:company_id, :settlement_cycle_days, :payment_due_days, :credit_limit_amount, :allow_agent_credit, :notes_ar, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE settlement_cycle_days = VALUES(settlement_cycle_days), payment_due_days = VALUES(payment_due_days), credit_limit_amount = VALUES(credit_limit_amount), allow_agent_credit = VALUES(allow_agent_credit), notes_ar = VALUES(notes_ar), updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $statement->execute([
            'company_id' => $companyId,
            'settlement_cycle_days' => $settlementDays,
            'payment_due_days' => $paymentDueDays,
            'credit_limit_amount' => $creditLimit,
            'allow_agent_credit' => $allowAgentCredit ? 1 : 0,
            'notes_ar' => $notes !== '' ? $notes : null,
            'updated_by_user_id' => $actor['id'],
        ]);
        return $this->settings($pdo, $companyId, null);
    }

    private function resolveCompanyId(array $actor, ?int $requestedCompanyId): int
    {
        $isSuper = in_array('super_admin', $actor['roles'] ?? [], true);
        $companyId = $requestedCompanyId ?: (int) ($actor['company_id'] ?? 0);
        if (!$isSuper && $companyId !== (int) ($actor['company_id'] ?? 0)) {
            Response::error('لا يمكن الوصول إلى بيانات شركة أخرى.', 'FORBIDDEN', 403);
        }
        if ($companyId < 1) {
            Response::error('لم يتم تحديد الشركة المرتبطة بحسابك.', 'VALIDATION_ERROR', 422);
        }
        return $companyId;
    }

    private function assertCompanyScope(array $actor, int $companyId, bool $write = false): void
    {
        if (!in_array('super_admin', $actor['roles'] ?? [], true) && (int) ($actor['company_id'] ?? 0) !== $companyId) {
            Response::error('لا يمكن تعديل إعدادات شركة أخرى.', 'FORBIDDEN', 403);
        }
        if ($write && !in_array('manage_payments', $actor['permissions'] ?? [], true) && !in_array('super_admin', $actor['roles'] ?? [], true)) {
            Response::error('لا تملك صلاحية تعديل الإعدادات المالية للشركة.', 'FORBIDDEN', 403);
        }
    }

    /** @return array<string, mixed> */
    private function settings(PDO $pdo, int $companyId, ?int $baseCurrencyId): array
    {
        $row = $this->one($pdo, 'SELECT company_id, settlement_cycle_days, payment_due_days, credit_limit_amount, allow_agent_credit, notes_ar, updated_by_user_id, updated_at FROM company_financial_settings WHERE company_id = :company_id', ['company_id' => $companyId]);
        return $row ?: [
            'company_id' => $companyId,
            'settlement_cycle_days' => 7,
            'payment_due_days' => 0,
            'credit_limit_amount' => '0.00',
            'allow_agent_credit' => 0,
            'notes_ar' => null,
            'updated_by_user_id' => null,
            'updated_at' => null,
            'base_currency_id' => $baseCurrencyId,
        ];
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}

// Runtime-safe migration for installations that use the existing schema.sql without a separate migration runner.
try {
    $GLOBALS['database']?->pdo()->exec("CREATE TABLE IF NOT EXISTS company_financial_settings (company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, settlement_cycle_days SMALLINT UNSIGNED NOT NULL DEFAULT 7, payment_due_days SMALLINT UNSIGNED NOT NULL DEFAULT 0, credit_limit_amount DECIMAL(14,2) NOT NULL DEFAULT 0, allow_agent_credit TINYINT(1) NOT NULL DEFAULT 0, notes_ar VARCHAR(500) NULL, updated_by_user_id BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_company_fin_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE, CONSTRAINT fk_company_fin_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $ignored) {
    // The service will report a normal API error if the database is unavailable.
}
