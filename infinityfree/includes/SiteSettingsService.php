<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;

final class SiteSettingsService
{
    public function __construct(private Database $database)
    {
    }

    /** @return array<string, mixed> */
    public function publicSettings(): array
    {
        $defaults = [
            'id' => 1,
            'site_name_ar' => 'منصة رحلة',
            'tagline_ar' => 'احجز رحلتك بسهولة وأمان',
            'logo_path' => null,
            'icon_path' => null,
            'footer_text_ar' => '© ' . date('Y') . ' منصة رحلة',
        ];
        try {
            $row = $this->database->pdo()->query('SELECT id, site_name_ar, tagline_ar, logo_path, icon_path, footer_text_ar FROM site_settings WHERE id = 1 LIMIT 1')->fetch();
            return is_array($row) ? $row : $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /** @return array<string, mixed> */
    public function update(array $actor, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'] ?? [], true) && !in_array('manage_settings', $actor['permissions'] ?? [], true)) {
            Response::error('لا تملك صلاحية تعديل إعدادات الموقع.', 'FORBIDDEN', 403);
        }
        $siteName = Security::cleanText($input['site_name_ar'] ?? null, 180);
        $tagline = Security::cleanText($input['tagline_ar'] ?? null, 255);
        $footer = Security::cleanText($input['footer_text_ar'] ?? null, 255);
        if ($siteName === '' || $footer === '') {
            Response::error('اسم الموقع وحقوق النشر حقول مطلوبة.', 'VALIDATION_ERROR', 422);
        }
        $statement = $this->database->pdo()->prepare(
            'UPDATE site_settings SET site_name_ar = :site_name_ar, tagline_ar = :tagline_ar, footer_text_ar = :footer_text_ar, updated_by = :updated_by WHERE id = 1'
        );
        $statement->execute(['site_name_ar' => $siteName, 'tagline_ar' => $tagline !== '' ? $tagline : null, 'footer_text_ar' => $footer, 'updated_by' => $actor['id']]);
        (new AuditLogger($this->database))->log((int) $actor['id'], null, 'site_settings_updated', 'site_settings', 1, null, ['site_name_ar' => $siteName, 'tagline_ar' => $tagline, 'footer_text_ar' => $footer]);
        return $this->publicSettings();
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    public function uploadBrandMedia(array $actor, string $slot, array $file): array
    {
        if (!in_array('super_admin', $actor['roles'] ?? [], true) && !in_array('manage_settings', $actor['permissions'] ?? [], true)) {
            Response::error('لا تملك صلاحية رفع هوية الموقع.', 'FORBIDDEN', 403);
        }
        if (!in_array($slot, ['logo', 'icon'], true)) {
            Response::error('نوع الملف المطلوب غير صالح.', 'VALIDATION_ERROR', 422);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            Response::error('تعذر قراءة ملف الصورة المرفوع.', 'UPLOAD_ERROR', 422);
        }
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
            Response::error('حجم الصورة يجب أن يكون حتى 5 ميغابايت.', 'UPLOAD_SIZE', 422);
        }
        $image = @getimagesize((string) $file['tmp_name']);
        $mime = (string) ($image['mime'] ?? '');
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || (int) ($image[0] ?? 0) > 5000 || (int) ($image[1] ?? 0) > 5000) {
            Response::error('استخدم صورة JPG أو PNG أو WEBP بأبعاد مناسبة.', 'UPLOAD_TYPE', 422);
        }
        $directory = dirname(__DIR__) . '/uploads/site';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            Response::error('تعذر تجهيز مجلد هوية الموقع.', 'UPLOAD_STORAGE', 500);
        }
        $root = dirname(__DIR__) . '/uploads';
        $htaccess = $root . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php[0-9]*)$\">\nRequire all denied\n</FilesMatch>\n");
        }
        $relative = 'uploads/site/' . $slot . '.' . $extensions[$mime];
        $target = dirname(__DIR__) . '/' . $relative;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            Response::error('تعذر حفظ صورة هوية الموقع.', 'UPLOAD_STORAGE', 500);
        }
        $column = $slot === 'logo' ? 'logo_path' : 'icon_path';
        $statement = $this->database->pdo()->prepare("UPDATE site_settings SET {$column} = :path, updated_by = :updated_by WHERE id = 1");
        $statement->execute(['path' => $relative, 'updated_by' => $actor['id']]);
        (new AuditLogger($this->database))->log((int) $actor['id'], null, 'site_brand_media_updated', 'site_settings', 1, null, ['slot' => $slot, 'path' => $relative]);
        return ['slot' => $slot, 'path' => $relative, 'settings' => $this->publicSettings()];
    }
}
