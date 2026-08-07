<?php

namespace Core\Finance\Exceptions;

/**
 * ⚠️  يُرمى هذا الاستثناء وفقًا لقرار (Q3 الجديد):
 *     عند فشل إنشاء حساب مالي للعميل أو المورد (ensureCustomer/Supplier)
 *     بدلاً من العودة الصامتة إلى Fallback Account (السلوك القديم).
 *
 * ملاحظات الأمان (Q3):
 *   1. يتم تسجيل Audit Log قبل رمي الاستثناء.
 *   2. يحتوي على كافة تفاصيل السياق لكي يقوم المسؤول بتشخيص المشكلة.
 *   3. يجب على الباعث التنصل من أي عملية مالية معلقة عند التقاطه.
 */
class AccountResolutionFailedException extends FinanceException
{
    public const PARTY_CUSTOMER = 'customer';
    public const PARTY_SUPPLIER = 'supplier';

    /**
     * @param string      $partyType   customer|supplier
     * @param int|null    $partyId     رقم العميل/المورد الذي فشل في ربط الحساب
     * @param string      $reason      سبب الفشل النصي (رسالة الخطأ الأصلية)
     * @param array       $extra       سياق إضافي (branch_id, name, ... إلخ)
     */
    public function __construct(
        string  $partyType,
        ?int    $partyId,
        string  $reason,
        array   $extra = []
    ) {
        $label = $partyType === self::PARTY_SUPPLIER ? 'مورد' : 'عميل';
        $msg = sprintf(
            'فشل إنشاء / إيجاد الحساب المالي لل%s رقم %s. السبب: %s',
            $label,
            $partyId ?? '(NULL)',
            $reason
        );

        parent::__construct($msg, 'integrity', array_merge([
            'party_type' => $partyType,
            'party_id'   => $partyId,
            'reason'     => $reason,
        ], $extra));
    }

    public function getPartyType(): string
    {
        return $this->getContext()['party_type'] ?? 'unknown';
    }

    public function getPartyId(): ?int
    {
        $id = $this->getContext()['party_id'] ?? null;
        return $id === null ? null : (int)$id;
    }
}
