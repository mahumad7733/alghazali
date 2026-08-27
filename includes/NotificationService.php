<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;

final class NotificationService
{
    public function __construct(private Database $database)
    {
    }

    public function send(int $userId, ?int $companyId, string $type, string $title, string $body, ?string $referenceType = null, ?int $referenceId = null): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO notifications (user_id, company_id, type, title_ar, body_ar, reference_type, reference_id)
             VALUES (:user_id, :company_id, :type, :title_ar, :body_ar, :reference_type, :reference_id)'
        );
        $statement->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
            'type' => $type,
            'title_ar' => $title,
            'body_ar' => $body,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    public function sendToBookingManagers(int $companyId, string $title, string $body, ?int $referenceId = null, ?int $excludeUserId = null): void
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT DISTINCT ur.user_id
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = ur.role_id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE r.code = 'super_admin'
                OR (p.code IN ('view_all_bookings', 'view_company_bookings') AND (ur.company_id = :company_id OR ur.company_id IS NULL))"
        );
        $statement->execute(['company_id' => $companyId]);
        foreach ($statement->fetchAll() as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0 && ($excludeUserId === null || $userId !== $excludeUserId)) {
                $this->send($userId, $companyId, 'new_booking', $title, $body, 'booking', $referenceId);
            }
        }
    }

    public function sendToCustomerManagers(string $title, string $body, ?int $referenceId = null): void
    {
        $statement = $this->database->pdo()->query(
            "SELECT DISTINCT ur.user_id
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = ur.role_id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE r.code = 'super_admin' OR p.code = 'manage_users'"
        );
        foreach ($statement->fetchAll() as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $this->send($userId, null, 'new_customer', $title, $body, 'customer', $referenceId);
            }
        }
    }
}
