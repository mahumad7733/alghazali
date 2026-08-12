-- =============================================================================
-- Migration: نظام الموردين - الاسم التجاري وخدمات الموردين (Many-to-Many)
-- Version: 2026_08_11_012
-- Author: AlGhazali ERP System
-- Description:
--   1. إضافة حقل الاسم التجاري (trade_name) لجدول الموردين
--   2. إنشاء جدول catalog_services للخدمات المتاحة
--   3. إنشاء جدول ربط supplier_services (Many-to-Many)
--   4. إدراج الخدمات الأساسية الثمانية
-- Safety: جميع العمليات Idempotent (آمنة لإعادة التنفيذ)
-- =============================================================================

-- ============================================================
-- القسم 1: إضافة حقل الاسم التجاري إلى جدول suppliers
-- ============================================================
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'suppliers'
      AND COLUMN_NAME = 'trade_name'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE suppliers ADD COLUMN trade_name VARCHAR(255) NULL COMMENT ''الاسم التجاري للمورد - يظهر في واجهة المستخدم'' AFTER supplier_name',
    'SELECT ''trade_name column already exists in suppliers'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- فهرس للبحث السريع بالاسم التجاري
SET @idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'suppliers'
      AND INDEX_NAME = 'idx_suppliers_trade_name'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE suppliers ADD INDEX idx_suppliers_trade_name (trade_name)',
    'SELECT ''idx_suppliers_trade_name already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- القسم 2: إنشاء جدول catalog_services للخدمات
-- ============================================================
SET @table_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'catalog_services'
);

SET @sql = IF(@table_exists = 0,
    'CREATE TABLE catalog_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_code VARCHAR(50) NOT NULL UNIQUE COMMENT ''رمز الخدمة الفريد'',
        service_name_ar VARCHAR(255) NOT NULL COMMENT ''اسم الخدمة بالعربية'',
        service_name_en VARCHAR(100) NULL COMMENT ''اسم الخدمة بالإنجليزية'',
        description TEXT NULL COMMENT ''وصف الخدمة'',
        is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''هل الخدمة نشطة؟'',
        sort_order INT NOT NULL DEFAULT 0 COMMENT ''ترتيب العرض'',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        INDEX idx_catalog_services_code (service_code),
        INDEX idx_catalog_services_active (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT ''جدول الخدمات المتاحة في النظام''',
    'SELECT ''catalog_services table already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- القسم 3: إدراج الخدمات الأساسية الثمانية (Upsert Idempotent)
-- ============================================================
INSERT INTO catalog_services (service_code, service_name_ar, service_name_en, description, is_active, sort_order) VALUES
    ('bus_bookings',           'حجوزات الباصات',        'Bus Bookings',            'خدمات حجوزات وشراء تذاكر الباصات',              1, 1),
    ('flight_bookings',        'حجوزات الطيران',        'Flight Bookings',         'خدمات حجوزات وشراء تذاكر الطيران',              1, 2),
    ('umrah',                  'خدمات العمرة',          'Umrah Services',          'خدمات العمرة (التجهيزات والخدمات المرتبطة)',    1, 3),
    ('hajj',                   'خدمات الحج',            'Hajj Services',           'خدمات الحج (التجهيزات والخدمات المرتبطة)',      1, 4),
    ('work_visa',              'تأشيرات العمل',         'Work Visa',               'خدمات تأشيرات العمل للخارج',                    1, 5),
    ('family_visit',           'الزيارة العائلية',      'Family Visit Visa',       'خدمات الزيارة العائلية وتأشيراتها',             1, 6),
    ('passport_transactions',  'معاملات الجوازات',      'Passport Transactions',   'خدمات معاملات الجوازات والبطائق الشخصية',       1, 7),
    ('postal_services',        'الخدمات البريدية',      'Postal Services',         'خدمات الشحن والبريد والطرود',                   1, 8)
ON DUPLICATE KEY UPDATE
    service_name_ar = VALUES(service_name_ar),
    service_name_en = VALUES(service_name_en),
    description = VALUES(description),
    is_active = VALUES(is_active),
    sort_order = VALUES(sort_order);

-- ============================================================
-- القسم 4: إنشاء جدول ربط supplier_services (Many-to-Many)
-- ============================================================
SET @table_exists2 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'supplier_services'
);

SET @sql = IF(@table_exists2 = 0,
    'CREATE TABLE supplier_services (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL COMMENT ''معرف المورد'',
        service_id INT NOT NULL COMMENT ''معرف الخدمة'',
        is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''هل المورد يقدم الخدمة حالياً؟'',
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        assigned_by INT NULL COMMENT ''معرف المستخدم الذي قام بالربط'',
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_supplier_service (supplier_id, service_id),
        INDEX idx_supplier_services_supplier (supplier_id, is_active),
        INDEX idx_supplier_services_service (service_id, is_active),
        CONSTRAINT fk_supplier_services_supplier
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_supplier_services_service
            FOREIGN KEY (service_id) REFERENCES catalog_services(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT ''جدول ربط الموردين بالخدمات التي يقدمونها (Many-to-Many)''',
    'SELECT ''supplier_services table already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- القسم 5: إنشاء View مساعدة لجلب الموردين مع الاسم التجاري
-- ============================================================
SET @view_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.VIEWS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'v_suppliers_with_display_name'
);

SET @sql = IF(@view_exists = 1, 'DROP VIEW v_suppliers_with_display_name', 'SELECT ''view does not exist'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE VIEW v_suppliers_with_display_name AS
SELECT
    s.id AS supplier_id,
    s.account_id,
    s.supplier_name,
    COALESCE(NULLIF(TRIM(s.trade_name), ''), s.supplier_name) AS display_name,
    s.trade_name,
    s.supplier_phone,
    s.supplier_email,
    s.address,
    s.status,
    s.created_at,
    s.updated_at
FROM suppliers s
WHERE s.deleted_at IS NULL;

-- ============================================================
-- القسم 6: ترحيل بيانات أولية للخلفية (ربط جميع الموردين بجميع الخدمات)
--         للحفاظ على التوافق مع البيانات الحالية
-- ============================================================
INSERT IGNORE INTO supplier_services (supplier_id, service_id, is_active, assigned_at)
SELECT
    s.id AS supplier_id,
    cs.id AS service_id,
    1 AS is_active,
    NOW() AS assigned_at
FROM suppliers s
CROSS JOIN catalog_services cs
WHERE s.deleted_at IS NULL
  AND cs.is_active = 1;

-- ============================================================
-- نهاية Migration
-- ============================================================
SELECT 'Migration 2026_08_11_012 completed successfully' AS migration_status;
