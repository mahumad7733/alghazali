<?php

namespace AlGhazali\Finance\Exceptions;

/**
 * يُرمى عند عدم تمتع المستخدم بالصلاحية المطلوبة للعملية.
 */
class PermissionDeniedException extends FinanceException
{
    public function __construct(string $operation, string $requiredPermission)
    {
        parent::__construct(
            "ليس لديك صلاحية للقيام بـ: {$operation}",
            'permission',
            ['operation' => $operation, 'required_permission' => $requiredPermission]
        );
    }
}
