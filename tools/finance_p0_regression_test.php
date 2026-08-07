<?php

declare(strict_types=1);

function has_permission(string $permission): bool
{
    throw new RuntimeException('permission provider unavailable');
}

require_once __DIR__ . '/../core/Finance/Contracts/AuditLoggerInterface.php';
require_once __DIR__ . '/../core/Finance/Contracts/TransactionManagerInterface.php';
require_once __DIR__ . '/../core/Finance/TransactionManager.php';
require_once __DIR__ . '/../core/Finance/Exceptions/FinanceException.php';
require_once __DIR__ . '/../core/Finance/Exceptions/FiscalPeriodClosedException.php';
require_once __DIR__ . '/../core/Finance/Exceptions/PermissionDeniedException.php';
require_once __DIR__ . '/../core/Finance/FinanceContext.php';
require_once __DIR__ . '/../core/Finance/AuditLogger.php';

final class P0NoopAuditLogger implements \Core\Finance\Contracts\AuditLoggerInterface
{
    public function log(string $action, string $entityType, ?int $entityId, array $extra = []): void
    {
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$transactions = new \Core\Finance\TransactionManager($pdo);
$context = new \Core\Finance\FinanceContext($pdo, 1, $transactions, new P0NoopAuditLogger());

try {
    $context->assertUserCan('post_invoice', 'post invoice');
    throw new RuntimeException('Permission provider failure was not rejected');
} catch (\Core\Finance\Exceptions\FinanceException $exception) {
    if ($exception->getErrorCategory() !== 'permission') {
        throw new RuntimeException('Permission failure category was not preserved');
    }
}

try {
    $context->assertFiscalPeriodOpen('2026-08-07');
    throw new RuntimeException('Missing fiscal-period table was not rejected');
} catch (\Core\Finance\Exceptions\FinanceException $exception) {
    if ($exception->getErrorCategory() !== 'fiscal') {
        throw new RuntimeException('Fiscal failure category was not preserved');
    }
}

$pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, action TEXT NOT NULL)');
$audit = new \Core\Finance\AuditLogger($pdo, 1);
$pdo->exec('CREATE TABLE business_state (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');

try {
    $transactions->executeAtomically(function () use ($pdo, $audit): void {
        $pdo->exec("INSERT INTO business_state (value) VALUES ('must rollback')");
        $audit->log('post', 'invoice', 1);
    });
    throw new RuntimeException('Audit failure was not rejected');
} catch (\Core\Finance\Exceptions\FinanceException $exception) {
    if ($exception->getErrorCategory() !== 'integrity') {
        throw new RuntimeException('Audit failure category was not preserved');
    }
}

if ((int)$pdo->query('SELECT COUNT(*) FROM business_state')->fetchColumn() !== 0) {
    throw new RuntimeException('Business transaction was not rolled back after audit failure');
}

echo "Finance P0 fail-closed regression test: PASS\n";
