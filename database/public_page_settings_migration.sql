-- إعدادات ظهور ومحتوى الصفحات العامة في منصة رِحلة
-- آمن لإعادة التنفيذ: لا يحذف بيانات ولا يغيّر جداول الأعمال.
CREATE TABLE IF NOT EXISTS public_page_settings (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(40) NOT NULL UNIQUE,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    title_ar VARCHAR(180) NOT NULL,
    intro_ar VARCHAR(500) NULL,
    body_ar TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_public_page_visible (is_visible, page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO public_page_settings (page_key, is_visible, title_ar, intro_ar, body_ar) VALUES
('about', 1, 'من نحن', 'منصة رقمية تساعد المسافرين على البحث عن الرحلات وإرسال طلبات الحجز بسهولة.', 'ابدأ بتحديد مدينة الانطلاق ومدينة الوصول وتاريخ السفر، ثم اختر الرحلة والمقاعد المناسبة وأكمل بيانات المسافرين.'),
('contact', 1, 'اتصل بنا', 'قنوات التواصل المعتمدة التي يديرها المشرف من لوحة التحكم.', 'يمكنك التواصل مع فريق منصة رِحلة عبر القنوات النشطة الظاهرة في هذه الصفحة.'),
('privacy', 1, 'السياسة والخصوصية', 'نحافظ على بيانات الحساب والحجز ونستخدمها لتقديم خدمات المنصة.', 'يتطلب إتمام طلب الحجز تسجيل الدخول وإدخال بيانات المسافرين المطلوبة للتذكرة. لا تعرض هذه الصفحة وعودًا أو قنوات اتصال غير منشورة في النظام.'),
('developers', 1, 'مركز المطورين وواجهة API', 'توثيق التكامل الفعلي مع منصة رِحلة.', 'يمكن لشركات النقل والمطورين ربط أنظمتهم مع منصة رِحلة باستخدام واجهات API وفق الصلاحيات وقواعد الأمان المعتمدة.');

-- لا تُنشئ مفتاحًا أجنبيًا تلقائيًا حتى تبقى الترقية متوافقة مع قواعد البيانات القديمة.
