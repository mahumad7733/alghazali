<?php

namespace Core\Finance\Exceptions;

/**
 * يُرمى عند محاولة تسجيل عملية مالية في فترة مالية مغلقة.
 * انظر: FiscalPeriodValidator::assertFiscalPeriodOpen
 */
class FiscalPeriodClosedException extends FinanceException
{
    public function __construct(string $periodName, string $operationDate)
    {
        parent::__construct(
            "الفترة المالية «{$periodName}» مغلقة. لا يمكن تسجيل عمليات مالية بتاريخ {$operationDate} داخلها.",
            'fiscal',
            ['period_name' => $periodName, 'operation_date' => $operationDate]
        );
    }
}
