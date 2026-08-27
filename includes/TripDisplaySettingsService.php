<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class TripDisplaySettingsService
{
    public function __construct(private Database $database)
    {
        $this->ensureTable();
    }

    /** @return array<string, int> */
    public function get(array $actor): array
    {
        $this->assertManageSettings($actor);
        return $this->row();
    }

    /** @return array{show_price_change_badge:int} */
    public function publicPriceBadgeSetting(): array
    {
        $row = $this->row();
        return ['show_price_change_badge' => (int) ($row['show_price_change_badge'] ?? 0)];
    }

    /** @return array<string, int> */
    public function publicPaymentSettings(): array
    {
        $row = $this->row();
        return [
            'allow_agent_payment' => (int) ($row['allow_agent_payment'] ?? 1),
            'allow_company_payment' => (int) ($row['allow_company_payment'] ?? 1),
            'allow_bank_transfer' => (int) ($row['allow_bank_transfer'] ?? 1),
        ];
    }

    /** @return array<string, int> */
    public function update(array $actor, array $input): array
    {
        $this->assertManageSettings($actor);
        $values = [
            'show_company_cost' => $this->booleanValue($input['show_company_cost'] ?? null, 0),
            'show_available_seats' => $this->booleanValue($input['show_available_seats'] ?? null, 1),
            'show_bookings_button' => $this->booleanValue($input['show_bookings_button'] ?? null, 1),
            'show_agent_commission' => $this->booleanValue($input['show_agent_commission'] ?? null, 1),
            'show_price_change_badge' => $this->booleanValue($input['show_price_change_badge'] ?? null, 0),
            'allow_agent_payment' => $this->booleanValue($input['allow_agent_payment'] ?? null, 1),
            'allow_company_payment' => $this->booleanValue($input['allow_company_payment'] ?? null, 1),
            'allow_bank_transfer' => $this->booleanValue($input['allow_bank_transfer'] ?? null, 1),
        ];
        $statement = $this->database->pdo()->prepare(
            'UPDATE trip_display_settings SET show_company_cost = :show_company_cost, show_available_seats = :show_available_seats, show_bookings_button = :show_bookings_button, show_agent_commission = :show_agent_commission, show_price_change_badge = :show_price_change_badge, allow_agent_payment = :allow_agent_payment, allow_company_payment = :allow_company_payment, allow_bank_transfer = :allow_bank_transfer, updated_by = :updated_by WHERE id = 1'
        );
        $statement->execute($values + ['updated_by' => $actor['id']]);
        return $this->row();
    }

    private function ensureTable(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS trip_display_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            show_company_cost TINYINT(1) NOT NULL DEFAULT 0,
            show_available_seats TINYINT(1) NOT NULL DEFAULT 1,
            show_bookings_button TINYINT(1) NOT NULL DEFAULT 1,
            show_agent_commission TINYINT(1) NOT NULL DEFAULT 1,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ($pdo->query("SHOW COLUMNS FROM trip_display_settings LIKE 'show_price_change_badge'")->fetchColumn() === false) { $pdo->exec("ALTER TABLE trip_display_settings ADD COLUMN show_price_change_badge TINYINT(1) NOT NULL DEFAULT 0 AFTER show_agent_commission"); }
        foreach (['allow_agent_payment', 'allow_company_payment', 'allow_bank_transfer'] as $column) {
            if ($pdo->query("SHOW COLUMNS FROM trip_display_settings LIKE '{$column}'")->fetchColumn() === false) { $pdo->exec("ALTER TABLE trip_display_settings ADD COLUMN {$column} TINYINT(1) NOT NULL DEFAULT 1"); }
        }
        $pdo->exec("INSERT IGNORE INTO trip_display_settings (id, show_company_cost, show_available_seats, show_bookings_button, show_agent_commission, show_price_change_badge, allow_agent_payment, allow_company_payment, allow_bank_transfer) VALUES (1, 0, 1, 1, 1, 0, 1, 1, 1)");
    }

    /** @return array<string, int> */
    private function row(): array
    {
        $row = $this->database->pdo()->query('SELECT show_company_cost, show_available_seats, show_bookings_button, show_agent_commission, show_price_change_badge, allow_agent_payment, allow_company_payment, allow_bank_transfer FROM trip_display_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'show_company_cost' => (int) ($row['show_company_cost'] ?? 0),
            'show_available_seats' => (int) ($row['show_available_seats'] ?? 1),
            'show_bookings_button' => (int) ($row['show_bookings_button'] ?? 1),
            'show_agent_commission' => (int) ($row['show_agent_commission'] ?? 1),
            'show_price_change_badge' => (int) ($row['show_price_change_badge'] ?? 0),
            'allow_agent_payment' => (int) ($row['allow_agent_payment'] ?? 1),
            'allow_company_payment' => (int) ($row['allow_company_payment'] ?? 1),
            'allow_bank_transfer' => (int) ($row['allow_bank_transfer'] ?? 1),
        ];
    }

    private function booleanValue(mixed $value, int $default): int
    {
        if ($value === null || $value === '') return $default;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true ? 1 : 0;
    }

    private function assertManageSettings(array $actor): void
    {
        if (!in_array('manage_settings', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية تعديل إعدادات عرض الرحلات.', 'FORBIDDEN', 403);
        }
    }
}
