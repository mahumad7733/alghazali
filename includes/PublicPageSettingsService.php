<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;

final class PublicPageSettingsService
{
    private const PAGES = ['about', 'contact', 'privacy', 'developers'];

    public function __construct(private Database $database)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->database->pdo()->exec("CREATE TABLE IF NOT EXISTS public_page_settings (id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, page_key VARCHAR(40) NOT NULL UNIQUE, is_visible TINYINT(1) NOT NULL DEFAULT 1, title_ar VARCHAR(180) NOT NULL, intro_ar VARCHAR(500) NULL, body_ar TEXT NULL, updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $seed = [
            'about' => ['من نحن', 'منصة رقمية تساعد المسافرين على البحث عن الرحلات وإرسال طلبات الحجز بسهولة.', 'ابدأ بتحديد مدينة الانطلاق ومدينة الوصول وتاريخ السفر، ثم اختر الرحلة والمقاعد المناسبة وأكمل بيانات المسافرين.'],
            'contact' => ['اتصل بنا', 'قنوات التواصل المعتمدة التي يديرها المشرف من لوحة التحكم.', 'يمكنك التواصل مع فريق منصة رِحلة عبر القنوات النشطة الظاهرة في هذه الصفحة.'],
            'privacy' => ['السياسة والخصوصية', 'نحافظ على بيانات الحساب والحجز ونستخدمها لتقديم خدمات المنصة.', 'يتطلب إتمام طلب الحجز تسجيل الدخول وإدخال بيانات المسافرين المطلوبة للتذكرة. لا تعرض هذه الصفحة وعودًا أو قنوات اتصال غير منشورة في النظام.'],
            'developers' => ['مركز المطورين وواجهة API', 'توثيق التكامل الفعلي مع منصة رِحلة.', 'يمكن لشركات النقل والمطورين ربط أنظمتهم مع منصة رِحلة باستخدام واجهات API وفق الصلاحيات وقواعد الأمان المعتمدة.'],
        ];
        $statement = $this->database->pdo()->prepare('INSERT IGNORE INTO public_page_settings (page_key, is_visible, title_ar, intro_ar, body_ar) VALUES (:page_key, 1, :title_ar, :intro_ar, :body_ar)');
        foreach ($seed as $key => [$title, $intro, $body]) {
            $statement->execute(['page_key' => $key, 'title_ar' => $title, 'intro_ar' => $intro, 'body_ar' => $body]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function publicPages(): array
    {
        try {
            $rows = $this->database->pdo()->query('SELECT page_key, is_visible, title_ar, intro_ar, body_ar FROM public_page_settings ORDER BY id')->fetchAll();
            $pages = [];
            foreach ($rows as $row) {
                if (in_array((string) ($row['page_key'] ?? ''), self::PAGES, true) && (int) ($row['is_visible'] ?? 0) === 1) {
                    $pages[(string) $row['page_key']] = $row;
                }
            }
            return $pages;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function adminPages(array $actor): array
    {
        $this->assertCanManage($actor);
        return $this->database->pdo()->query('SELECT page_key, is_visible, title_ar, intro_ar, body_ar, updated_at FROM public_page_settings ORDER BY id')->fetchAll();
    }

    /** @return array<string, mixed> */
    public function update(array $actor, string $pageKey, array $input): array
    {
        $this->assertCanManage($actor);
        if (!in_array($pageKey, self::PAGES, true)) {
            Response::error('صفحة المحتوى غير معروفة.', 'NOT_FOUND', 404);
        }
        $title = Security::cleanText($input['title_ar'] ?? null, 180);
        $intro = Security::cleanText($input['intro_ar'] ?? null, 500);
        $body = Security::cleanText($input['body_ar'] ?? null, 10000);
        if ($title === '') {
            Response::error('عنوان الصفحة مطلوب.', 'VALIDATION_ERROR', 422);
        }
        $visible = filter_var($input['is_visible'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $statement = $this->database->pdo()->prepare('UPDATE public_page_settings SET is_visible = :is_visible, title_ar = :title_ar, intro_ar = :intro_ar, body_ar = :body_ar, updated_by = :updated_by WHERE page_key = :page_key');
        $statement->execute(['is_visible' => $visible, 'title_ar' => $title, 'intro_ar' => $intro !== '' ? $intro : null, 'body_ar' => $body !== '' ? $body : null, 'updated_by' => $actor['id'], 'page_key' => $pageKey]);
        (new AuditLogger($this->database))->log((int) $actor['id'], null, 'public_page_settings_updated', 'public_page_settings', null, null, ['page_key' => $pageKey, 'is_visible' => $visible]);
        $row = $this->database->pdo()->prepare('SELECT page_key, is_visible, title_ar, intro_ar, body_ar, updated_at FROM public_page_settings WHERE page_key = :page_key LIMIT 1');
        $row->execute(['page_key' => $pageKey]);
        return (array) $row->fetch();
    }

    private function assertCanManage(array $actor): void
    {
        if (!in_array('super_admin', $actor['roles'] ?? [], true) && !in_array('manage_settings', $actor['permissions'] ?? [], true)) {
            Response::error('لا تملك صلاحية تعديل محتوى الصفحات.', 'FORBIDDEN', 403);
        }
    }
}
