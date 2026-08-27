<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class BankService
{
    public function __construct(private Database $database)
    {
        $this->ensureTable();
    }

    /** @return array<int, array<string, mixed>> */
    public function active(): array
    {
        $statement = $this->database->pdo()->query(
            "SELECT b.id, b.name_ar, b.account_name_ar, b.account_number, b.iban, b.branch_name_ar, b.notes_ar,
                    b.currency_id, c.code AS currency_code, c.symbol_ar AS currency_symbol
             FROM banks b LEFT JOIN currencies c ON c.id = b.currency_id
             WHERE b.is_active = 1 ORDER BY b.name_ar, b.id"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(array $actor): array
    {
        $this->assertManage($actor);
        $statement = $this->database->pdo()->query(
            "SELECT b.id, b.name_ar, b.account_name_ar, b.account_number, b.iban, b.branch_name_ar, b.notes_ar,
                    b.currency_id, c.code AS currency_code, c.name_ar AS currency_name, b.is_active, b.created_at, b.updated_at
             FROM banks b LEFT JOIN currencies c ON c.id = b.currency_id ORDER BY b.is_active DESC, b.name_ar, b.id"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    public function create(array $actor, array $input): array
    {
        $this->assertManage($actor);
        $values = $this->values($input);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO banks (name_ar, account_name_ar, account_number, iban, branch_name_ar, notes_ar, currency_id, is_active)
             VALUES (:name_ar, :account_name_ar, :account_number, :iban, :branch_name_ar, :notes_ar, :currency_id, :is_active)'
        );
        $statement->execute($values);
        return $this->find((int) $this->database->pdo()->lastInsertId());
    }

    /** @return array<string, mixed> */
    public function update(array $actor, int $id, array $input): array
    {
        $this->assertManage($actor);
        $values = $this->values($input);
        $values['id'] = $id;
        $statement = $this->database->pdo()->prepare(
            'UPDATE banks SET name_ar = :name_ar, account_name_ar = :account_name_ar, account_number = :account_number,
             iban = :iban, branch_name_ar = :branch_name_ar, notes_ar = :notes_ar, currency_id = :currency_id,
             is_active = :is_active WHERE id = :id'
        );
        $statement->execute($values);
        return $this->find($id);
    }

    /** @return array<string, mixed> */
    public function setStatus(array $actor, int $id, string $status): array
    {
        $this->assertManage($actor);
        $isActive = $status === 'active' ? 1 : 0;
        $statement = $this->database->pdo()->prepare('UPDATE banks SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $isActive, 'id' => $id]);
        return $this->find($id);
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT b.id, b.name_ar, b.account_name_ar, b.account_number, b.iban, b.branch_name_ar, b.notes_ar,
                    b.currency_id, c.code AS currency_code, c.name_ar AS currency_name, c.symbol_ar AS currency_symbol, b.is_active
             FROM banks b LEFT JOIN currencies c ON c.id = b.currency_id WHERE b.id = :id LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            Response::error('الحساب البنكي المطلوب غير موجود.', 'NOT_FOUND', 404);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function values(array $input): array
    {
        $name = trim((string) ($input['name_ar'] ?? ''));
        $accountName = trim((string) ($input['account_name_ar'] ?? ''));
        $accountNumber = trim((string) ($input['account_number'] ?? ''));
        $currencyId = filter_var($input['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($name === '' || $accountName === '' || $accountNumber === '' || $currencyId === false) {
            Response::error('أدخل اسم البنك واسم صاحب الحساب ورقم الحساب والعملة.', 'VALIDATION_ERROR', 422);
        }
        if (mb_strlen($name) > 180 || mb_strlen($accountName) > 180 || mb_strlen($accountNumber) > 128 || mb_strlen((string) ($input['iban'] ?? '')) > 128 || mb_strlen((string) ($input['branch_name_ar'] ?? '')) > 180 || mb_strlen((string) ($input['notes_ar'] ?? '')) > 500) {
            Response::error('تجاوز أحد حقول الحساب البنكي الحد المسموح.', 'VALIDATION_ERROR', 422);
        }
        if ($this->scalar('SELECT id FROM currencies WHERE id = :id', ['id' => (int) $currencyId]) === null) {
            Response::error('العملة المختارة غير موجودة.', 'VALIDATION_ERROR', 422);
        }
        return [
            'name_ar' => $name,
            'account_name_ar' => $accountName,
            'account_number' => $accountNumber,
            'iban' => trim((string) ($input['iban'] ?? '')) ?: null,
            'branch_name_ar' => trim((string) ($input['branch_name_ar'] ?? '')) ?: null,
            'notes_ar' => trim((string) ($input['notes_ar'] ?? '')) ?: null,
            'currency_id' => (int) $currencyId,
            'is_active' => filter_var($input['is_active'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1,
        ];
    }

    private function ensureTable(): void
    {
        $this->database->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS banks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name_ar VARCHAR(180) NOT NULL,
                account_name_ar VARCHAR(180) NOT NULL,
                account_number VARCHAR(128) NOT NULL,
                iban VARCHAR(128) NULL,
                branch_name_ar VARCHAR(180) NULL,
                notes_ar VARCHAR(500) NULL,
                currency_id BIGINT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_banks_active_name (is_active, name_ar),
                CONSTRAINT fk_banks_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? null : $value;
    }

    private function assertManage(array $actor): void
    {
        if (!in_array('manage_settings', $actor['permissions'] ?? [], true) && !in_array('super_admin', $actor['roles'] ?? [], true)) {
            Response::error('لا تملك صلاحية إدارة البنوك والحسابات البنكية.', 'FORBIDDEN', 403);
        }
    }
}
