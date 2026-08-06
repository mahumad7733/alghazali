<?php

namespace Core\Finance\Exceptions;

use Exception;

/**
 * الاستثناء الأساسي لجميع الأخطاء المالية داخل الطبقة الجديدة.
 *
 * يمكن التقاطه من قبل الـ Facade (FinanceService لاحقًا) لتحويله
 * إلى التنسيق القديم للحفاظ على Backward Compatibility.
 */
class FinanceException extends Exception
{
    /** @var array<string,mixed> بيانات سياق إضافية للتشخيص أو للتدقيق */
    private array $context;

    /** @var string فئة الخطأ: validation | permission | fiscal | integrity | runtime */
    private string $errorCategory;

    public function __construct(
        string         $message,
        string         $errorCategory = 'runtime',
        array          $context = [],
        int            $code = 0,
        ?Exception     $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCategory = $errorCategory;
        $this->context = $context;
    }

    public function getErrorCategory(): string
    {
        return $this->errorCategory;
    }

    /** @return array<string,mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}
