<?php

namespace Core\Finance;

use Core\Finance\Contracts\TransactionManagerInterface;
use PDO;
use Throwable;

/**
 * إدارة المعاملات المالية الذرية مع حل مشكلة Nested Transactions عبر Savepoints.
 *
 * منقول من FinanceService::safeBegin / safeEnd / executeAtomically
 * السلوك مطابق 100% للسابق للحفاظ على Backward Compatibility.
 */
class TransactionManager implements TransactionManagerInterface
{
    private PDO $pdo;
    private ?\LegacyFinanceService $legacy;

    /** @var array<int,string> */
    private array $savepointStack = [];

    public function __construct(PDO $pdo, ?\LegacyFinanceService $legacy = null)
    {
        $this->pdo = $pdo;
        $this->legacy = $legacy;
    }

    /**
     * @inheritDoc
     */
    public function executeAtomically(callable $callback)
    {
        if ($this->legacy !== null) {
            return $this->legacy->executeAtomically($callback);
        }

        $name = $this->safeBegin();

        try {
            $result = $callback();
            $this->safeEnd($name, true);
            return $result;
        } catch (Throwable $e) {
            $this->safeEnd($name, false);
            throw $e;
        }
    }

    /**
     * إنشاء منطقة حفظ (Savepoint) داخل الترانزاكس الجاري إن وجد، أو بدء ترانزاكس جديد.
     */
    private function safeBegin(): string
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $name = '__TOP__';
            $this->savepointStack[] = $name;
            return $name;
        }

        $name = 'sp_fs_' . uniqid('', false);
        try {
            $this->pdo->exec("SAVEPOINT `$name`");
        } catch (Throwable $e) {
            $name = '__NONE__';
        }
        $this->savepointStack[] = $name;
        return $name;
    }

    /**
     * إنهاء منطقة حفظ (Savepoint) بطريقة آمنة.
     */
    private function safeEnd(string $name, bool $commit): void
    {
        $stackIdx = array_search($name, $this->savepointStack, true);
        if ($stackIdx === false) {
            return;
        }

        while (count($this->savepointStack) > $stackIdx) {
            $n = array_pop($this->savepointStack);
            if ($n === '__TOP__') {
                if ($this->pdo->inTransaction()) {
                    if ($commit) {
                        $this->pdo->commit();
                    } else {
                        $this->pdo->rollBack();
                    }
                }
                return;
            }
            if ($n === '__NONE__' || $n === '') {
                continue;
            }
            try {
                if ($commit) {
                    $this->pdo->exec("RELEASE SAVEPOINT `$n`");
                } else {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT `$n`");
                }
            } catch (Throwable $e) {
                // تجاهل — الأهم أن لا يُكسر الترانزاكس الخارجي (إن وجد).
            }
        }
    }
}
