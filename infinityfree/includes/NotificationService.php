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
}
