<?php

namespace Core\Finance\Contracts;

/**
 * واجهة إدارة المعاملات المالية الذرية مع دعم Nested Transactions عبر Savepoints.
 */
interface TransactionManagerInterface
{
    /**
     * تنفيذ Callable داخل Transaction آمنة.
     *
     * - في حال عدم وجود Transaction خارجية: يبدأ Transaction جديد + COMMIT عند النجاح.
     * - في حال وجود Transaction خارجية: ينشئ SAVEPOINT + ROLLBACK TO SAVEPOINT عند الفشل (لا يكسر الأب).
     *
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     * @throws \Throwable أي استثناء يرميه $callback يُمرر للأعلى بعد Rollback.
     */
    public function executeAtomically(callable $callback);
}
