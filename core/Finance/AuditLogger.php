<?php

namespace Core\Finance;

use Core\Finance\Contracts\AuditLoggerInterface;
use Core\Finance\Exceptions\FinanceException;
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
            // Keep the logger compatible with the current audit_logs schema.
            // entity_type/details_json are represented in new_values because the legacy table
            // stores table_name/record_id/old_values/new_values instead.
            $details = ['entity_type' => $entityType];
            if ($extra !== []) {
                $details['extra'] = $extra;
            }
            $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $statement = $this->pdo->prepare(
                'INSERT INTO audit_logs
                    (user_id, action, table_name, record_id, old_values, new_values,
                     ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $statement->execute([
                $this->userId,
                $action,
                $entityType,
                $entityId,
                null,
                $json === false ? null : $json,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            throw new FinanceException(
                'Financial audit logging failed; operation must not continue',
                'integrity',
                ['action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId],
                0,
                $e instanceof \Exception ? $e : null
            );
        }
    }
}
