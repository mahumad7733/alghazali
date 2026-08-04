-- ============================================================
-- Migration: إضافة أعمدة جديدة إلى جدول customers
-- الهدف: دعم عميل المبيعات النقدية الافتراضي + كود العميل
-- ============================================================

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS customer_code VARCHAR(50) NULL COMMENT 'كود العميل الداخلي' AFTER full_name,
    ADD COLUMN IF NOT EXISTS created_by INT(11) NULL COMMENT 'المستخدم الذي أنشأ العميل' AFTER branch_id,
    ADD COLUMN IF NOT EXISTS customer_status ENUM('active','inactive') NULL COMMENT 'حالة العميل (نسخة موحدة)' AFTER status,
    ADD COLUMN IF NOT EXISTS is_default_cash TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = العميل الافتراضي لمبيعات النقد العام',
    ADD UNIQUE KEY IF NOT EXISTS idx_customers_code (customer_code),
    ADD KEY IF NOT EXISTS idx_customers_default_cash (is_default_cash);
