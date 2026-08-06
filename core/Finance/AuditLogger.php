<?php

namespace Core\Finance;

use Core\Finance\Contracts\AuditLoggerInterface;
use PDO;
use Throwable;

final class AuditLogger implements AuditLoggerInterface
{
    public function __construct(private PDO $pdo, private int $userId = 0)
    {
    }

    public function log(string $action, string $entityType, ?int $entityId, array $extra = []): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, table_name, record_id,
                     ip_address, user_agent, details_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $json = $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $statement->execute([
                $this->userId,
                $action,
                $entityType,
                $entityId,
                $entityType,
                $entityId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $json === false ? null : $json,
            ]);
        } catch (Throwable $e) {
            error_log('Finance AuditLogger failed: ' . $e->getMessage());
        }
    }
}
