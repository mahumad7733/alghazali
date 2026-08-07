<?php

namespace Core\Finance\Contracts;

/**
 * واجهة تسجيل سجل التدقيق المركزي.
 *
 * أي خدمة مالية تحتاج إلى تسجيل أحداثها في audit_logs
 * يجب أن تعتمد على هذه الواجهة (لا على تنفيذ مُعيّن).
 */
interface AuditLoggerInterface
{
    /**
     * كتابة سجل تدقيق مركزي.
     *
     * @param string               $action     اسم الإجراء (مثل create_invoice_draft)
     * @param string               $entityType نوع الكيان (invoice, receipt_voucher, ...)
     * @param int|null             $entityId   رقم الكيان إن وجد
     * @param array<string, mixed> $extra      بيانات سياق إضافية تُحفظ كـ JSON
     *
     * @return void يجب ألا يرمي أي استثناء أبدًا.
     */
    public function log(string $action, string $entityType, ?int $entityId, array $extra = []): void;
}
