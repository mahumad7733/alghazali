<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;

final class AuditLogger
{
    public function __construct(private Database $database)
    {
    }

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues */
    public function log(?int $userId, ?int $companyId, string $action, string $entityType, ?int $entityId, ?array $oldValues = null, ?array $newValues = null): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO audit_logs (user_id, company_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (:user_id, :company_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)'
        );
        $statement->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }
}
