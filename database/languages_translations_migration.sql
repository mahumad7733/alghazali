-- نظام اللغات والترجمة المركزي لمنصة رِحلة
-- نفّذ مرة واحدة بعد أخذ نسخة احتياطية. لا يحتوي على بيانات اتصال.

CREATE TABLE IF NOT EXISTS languages (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_ar VARCHAR(120) NOT NULL,
    name_native VARCHAR(120) NOT NULL,
    code VARCHAR(16) NOT NULL,
    direction ENUM('rtl','ltr') NOT NULL DEFAULT 'ltr',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_languages_code (code),
    KEY idx_languages_active (is_active, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_name VARCHAR(190) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    context VARCHAR(80) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translation_keys_name (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    language_id SMALLINT UNSIGNED NOT NULL,
    translation_key_id BIGINT UNSIGNED NOT NULL,
    value TEXT DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translations_language_key (language_id, translation_key_id),
    KEY idx_translations_key (translation_key_id),
    CONSTRAINT fk_translations_language FOREIGN KEY (language_id) REFERENCES languages(id) ON DELETE CASCADE,
    CONSTRAINT fk_translations_key FOREIGN KEY (translation_key_id) REFERENCES translation_keys(id) ON DELETE CASCADE,
    CONSTRAINT fk_translations_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_language_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    language_id SMALLINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_language_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_language_language FOREIGN KEY (language_id) REFERENCES languages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO languages (name_ar, name_native, code, direction, is_active, is_default)
VALUES ('العربية', 'العربية', 'ar', 'rtl', 1, 1)
ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar), name_native = VALUES(name_native), direction = VALUES(direction), is_active = 1;

INSERT INTO languages (name_ar, name_native, code, direction, is_active, is_default)
VALUES ('الإنجليزية', 'English', 'en', 'ltr', 1, 0)
ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar), name_native = VALUES(name_native), direction = VALUES(direction), is_active = 1;

INSERT INTO translation_keys (key_name, description, context) VALUES
('common.close', 'إغلاق النوافذ', 'common'),
('common.save', 'زر الحفظ', 'common'),
('common.cancel', 'زر الإلغاء', 'common'),
('common.loading', 'رسالة التحميل', 'common'),
('common.search', 'حقل البحث', 'common'),
('common.language', 'اسم اللغة', 'common'),
('common.arabic', 'العربية', 'common'),
('common.english', 'الإنجليزية', 'common'),
('nav.home', 'الرئيسية', 'navigation'),
('nav.bookings', 'الحجوزات', 'navigation'),
('nav.trips', 'الرحلات', 'navigation'),
('nav.languages', 'إدارة اللغات', 'navigation'),
('nav.translations', 'الترجمة المركزية', 'navigation'),
('auth.login', 'تسجيل الدخول', 'auth'),
('auth.register', 'إنشاء حساب', 'auth'),
('booking.confirm', 'تأكيد الحجز', 'booking'),
('booking.cancel', 'إلغاء الحجز', 'booking'),
('booking.success', 'تم إنشاء الحجز بنجاح.', 'booking'),
('dashboard.title', 'لوحة التحكم', 'admin'),
('dashboard.system_setup', 'تهيئة النظام', 'admin'),
('languages.title', 'إدارة اللغات', 'admin'),
('languages.add', 'إضافة لغة', 'admin'),
('languages.code', 'كود اللغة', 'admin'),
('languages.direction', 'اتجاه اللغة', 'admin'),
('languages.active', 'نشطة', 'admin'),
('languages.default', 'اللغة الافتراضية', 'admin'),
('translations.title', 'الترجمة المركزية', 'admin'),
('translations.key', 'مفتاح الترجمة', 'admin'),
('translations.complete', 'مكتملة', 'admin'),
('translations.incomplete', 'ناقصة', 'admin'),
('translations.untranslated', 'غير مترجمة', 'admin')
ON DUPLICATE KEY UPDATE description = VALUES(description), context = VALUES(context);

INSERT INTO translations (language_id, translation_key_id, value)
SELECT l.id, k.id, CASE k.key_name
    WHEN 'common.close' THEN 'إغلاق'
    WHEN 'common.save' THEN 'حفظ'
    WHEN 'common.cancel' THEN 'إلغاء'
    WHEN 'common.loading' THEN 'جارٍ التحميل…'
    WHEN 'common.search' THEN 'بحث'
    WHEN 'common.language' THEN 'اللغة'
    WHEN 'common.arabic' THEN 'العربية'
    WHEN 'common.english' THEN 'الإنجليزية'
    WHEN 'nav.home' THEN 'الرئيسية'
    WHEN 'nav.bookings' THEN 'الحجوزات'
    WHEN 'nav.trips' THEN 'الرحلات'
    WHEN 'nav.languages' THEN 'إدارة اللغات'
    WHEN 'nav.translations' THEN 'الترجمة المركزية'
    WHEN 'auth.login' THEN 'تسجيل الدخول'
    WHEN 'auth.register' THEN 'إنشاء حساب'
    WHEN 'booking.confirm' THEN 'تأكيد الحجز'
    WHEN 'booking.cancel' THEN 'إلغاء الحجز'
    WHEN 'booking.success' THEN 'تم إنشاء الحجز بنجاح.'
    WHEN 'dashboard.title' THEN 'لوحة التحكم'
    WHEN 'dashboard.system_setup' THEN 'تهيئة النظام'
    WHEN 'languages.title' THEN 'إدارة اللغات'
    WHEN 'languages.add' THEN 'إضافة لغة'
    WHEN 'languages.code' THEN 'كود اللغة'
    WHEN 'languages.direction' THEN 'اتجاه اللغة'
    WHEN 'languages.active' THEN 'نشطة'
    WHEN 'languages.default' THEN 'اللغة الافتراضية'
    WHEN 'translations.title' THEN 'الترجمة المركزية'
    WHEN 'translations.key' THEN 'مفتاح الترجمة'
    WHEN 'translations.complete' THEN 'مكتملة'
    WHEN 'translations.incomplete' THEN 'ناقصة'
    WHEN 'translations.untranslated' THEN 'غير مترجمة'
    ELSE NULL END
FROM languages l CROSS JOIN translation_keys k
WHERE l.code = 'ar'
ON DUPLICATE KEY UPDATE value = COALESCE(translations.value, VALUES(value));

INSERT INTO translations (language_id, translation_key_id, value)
SELECT l.id, k.id, CASE k.key_name
    WHEN 'common.close' THEN 'Close'
    WHEN 'common.save' THEN 'Save'
    WHEN 'common.cancel' THEN 'Cancel'
    WHEN 'common.loading' THEN 'Loading…'
    WHEN 'common.search' THEN 'Search'
    WHEN 'common.language' THEN 'Language'
    WHEN 'common.arabic' THEN 'Arabic'
    WHEN 'common.english' THEN 'English'
    WHEN 'nav.home' THEN 'Home'
    WHEN 'nav.bookings' THEN 'Bookings'
    WHEN 'nav.trips' THEN 'Trips'
    WHEN 'nav.languages' THEN 'Languages'
    WHEN 'nav.translations' THEN 'Central translations'
    WHEN 'auth.login' THEN 'Sign in'
    WHEN 'auth.register' THEN 'Create account'
    WHEN 'booking.confirm' THEN 'Confirm booking'
    WHEN 'booking.cancel' THEN 'Cancel booking'
    WHEN 'booking.success' THEN 'Booking created successfully.'
    WHEN 'dashboard.title' THEN 'Dashboard'
    WHEN 'dashboard.system_setup' THEN 'System setup'
    WHEN 'languages.title' THEN 'Language management'
    WHEN 'languages.add' THEN 'Add language'
    WHEN 'languages.code' THEN 'Language code'
    WHEN 'languages.direction' THEN 'Text direction'
    WHEN 'languages.active' THEN 'Active'
    WHEN 'languages.default' THEN 'Default language'
    WHEN 'translations.title' THEN 'Central translations'
    WHEN 'translations.key' THEN 'Translation key'
    WHEN 'translations.complete' THEN 'Complete'
    WHEN 'translations.incomplete' THEN 'Incomplete'
    WHEN 'translations.untranslated' THEN 'Untranslated'
    ELSE NULL END
FROM languages l CROSS JOIN translation_keys k
WHERE l.code = 'en'
ON DUPLICATE KEY UPDATE value = COALESCE(translations.value, VALUES(value));

INSERT INTO permissions (code, name_ar, module_code) VALUES
('languages.view', 'عرض إدارة اللغات', 'languages'),
('languages.create', 'إضافة لغة', 'languages'),
('languages.update', 'تعديل اللغات', 'languages'),
('languages.delete', 'تعطيل اللغات', 'languages'),
('translations.view', 'عرض الترجمة المركزية', 'translations'),
('translations.update', 'تعديل الترجمات', 'translations')
ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar), module_code = VALUES(module_code);


INSERT INTO translation_keys (key_name, description, context) VALUES
('nav.about', 'صفحة من نحن', 'navigation'),
('nav.contact', 'صفحة اتصل بنا', 'navigation'),
('nav.privacy', 'صفحة السياسة والخصوصية', 'navigation')
ON DUPLICATE KEY UPDATE description = VALUES(description), context = VALUES(context);

INSERT INTO translations (language_id, translation_key_id, value)
SELECT l.id, k.id, CASE k.key_name
    WHEN 'nav.about' THEN IF(l.code = 'en', 'About us', 'من نحن')
    WHEN 'nav.contact' THEN IF(l.code = 'en', 'Contact us', 'اتصل بنا')
    WHEN 'nav.privacy' THEN IF(l.code = 'en', 'Privacy policy', 'السياسة والخصوصية')
    ELSE NULL END
FROM languages l CROSS JOIN translation_keys k
WHERE k.key_name IN ('nav.about', 'nav.contact', 'nav.privacy')
ON DUPLICATE KEY UPDATE value = COALESCE(translations.value, VALUES(value));
