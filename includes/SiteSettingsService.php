<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;

final class SiteSettingsService
{
    public function __construct(private Database $database)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->database->pdo()->exec("CREATE TABLE IF NOT EXISTS site_settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, site_name_ar VARCHAR(180) NOT NULL, tagline_ar VARCHAR(255) NULL, logo_path VARCHAR(255) NULL, icon_path VARCHAR(255) NULL, footer_text_ar VARCHAR(255) NOT NULL, password_policy ENUM('normal','medium','complex') NOT NULL DEFAULT 'medium', updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $this->database->pdo()->exec("ALTER TABLE site_settings ADD COLUMN default_language_code VARCHAR(16) NULL"); } catch (\Throwable) {}
        try { $this->database->pdo()->exec("CREATE TABLE IF NOT EXISTS site_identity_translations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id TINYINT UNSIGNED NOT NULL DEFAULT 1, language_id SMALLINT UNSIGNED NOT NULL, site_name VARCHAR(180) NOT NULL, tagline VARCHAR(255) NULL, footer_text VARCHAR(255) NOT NULL, updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_site_identity_language (site_id, language_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (\Throwable) {}
        try { $this->database->pdo()->exec("UPDATE site_settings SET default_language_code = COALESCE(default_language_code, 'ar') WHERE id = 1"); } catch (\Throwable) {}
        $this->database->pdo()->exec("INSERT IGNORE INTO site_settings (id, site_name_ar, tagline_ar, footer_text_ar, password_policy, default_language_code) VALUES (1, 'منصة رحلة', 'احجز رحلتك بسهولة وأمان', '© " . date('Y') . " منصة رحلة', 'medium', 'ar')");
    }

    /** @return array<string, mixed> */
    public function publicSettings(?string $languageCode = null): array
    {
        $defaults=['id'=>1,'site_name_ar'=>'منصة رحلة','tagline_ar'=>'احجز رحلتك بسهولة وأمان','logo_path'=>null,'icon_path'=>null,'footer_text_ar'=>'© '.date('Y').' منصة رحلة','password_policy'=>'medium','default_language_code'=>'ar','site_name'=>'منصة رحلة','tagline'=>'احجز رحلتك بسهولة وأمان','footer_text'=>'© '.date('Y').' منصة رحلة','languages'=>[],'identity_translations'=>[]];
        try {
            $row=$this->database->pdo()->query("SELECT id,site_name_ar,tagline_ar,logo_path,icon_path,footer_text_ar,password_policy,COALESCE(default_language_code,'ar') AS default_language_code FROM site_settings WHERE id=1 LIMIT 1")->fetch();
            if(!is_array($row)) return $defaults;
            $settings=array_merge($defaults,$row);
            $languages=$this->database->pdo()->query('SELECT id,code,name_ar,name_native,direction,is_active FROM languages WHERE is_active=1 ORDER BY id')->fetchAll();
            $identities=[]; $rows=$this->database->pdo()->query('SELECT language_id,site_name,tagline,footer_text FROM site_identity_translations WHERE site_id=1')->fetchAll();
            foreach($rows as $item) $identities[(string)$item['language_id']]=['site_name'=>(string)$item['site_name'],'tagline'=>(string)($item['tagline']??''),'footer_text'=>(string)$item['footer_text']];
            $settings['languages']=$languages; $settings['identity_translations']=$identities;
            $code=strtolower(trim((string)($languageCode ?: $settings['default_language_code'])));
            foreach($languages as $lang){$id=(string)$lang['id']; if((string)$lang['code']===$code && isset($identities[$id])){$settings['site_name']=$identities[$id]['site_name'];$settings['tagline']=$identities[$id]['tagline'];$settings['footer_text']=$identities[$id]['footer_text'];break;}}
            return $settings;
        } catch(\Throwable){return $defaults;}
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
        $passwordPolicy = (string) ($input['password_policy'] ?? 'medium');
        $defaultLanguage = strtolower(trim((string) ($input['default_language_code'] ?? 'ar')));
        $identities = is_array($input['identity_translations'] ?? null) ? $input['identity_translations'] : [];
        if (!in_array($passwordPolicy, ['normal', 'medium', 'complex'], true)) $passwordPolicy = 'medium';
        if ($siteName === '' || $footer === '') {
            Response::error('اسم الموقع وحقوق النشر حقول مطلوبة.', 'VALIDATION_ERROR', 422);
        }
        $valid = $this->database->pdo()->prepare('SELECT code FROM languages WHERE code=:code AND is_active=1 LIMIT 1'); $valid->execute(['code'=>$defaultLanguage]); if (!$valid->fetchColumn()) $defaultLanguage='ar';
        $statement = $this->database->pdo()->prepare('UPDATE site_settings SET site_name_ar=:site_name_ar, tagline_ar=:tagline_ar, footer_text_ar=:footer_text_ar, password_policy=:password_policy, default_language_code=:default_language_code, updated_by=:updated_by WHERE id=1');
        $statement->execute(['site_name_ar'=>$siteName,'tagline_ar'=>$tagline!==''?$tagline:null,'footer_text_ar'=>$footer,'password_policy'=>$passwordPolicy,'default_language_code'=>$defaultLanguage,'updated_by'=>$actor['id']]);
        $langs=$this->database->pdo()->query('SELECT id,code FROM languages WHERE is_active=1')->fetchAll(); $byCode=[]; foreach($langs as $lang) $byCode[(string)$lang['code']] = (int)$lang['id'];
        foreach($identities as $code=>$item){ if(!isset($byCode[$code]) || !is_array($item)) continue; $name=Security::cleanText($item['site_name']??'',180); $tag=Security::cleanText($item['tagline']??'',255); $foot=Security::cleanText($item['footer_text']??'',255); if($name===''||$foot==='') continue; $up=$this->database->pdo()->prepare('INSERT INTO site_identity_translations(site_id,language_id,site_name,tagline,footer_text,updated_by) VALUES(1,:language_id,:site_name,:tagline,:footer_text,:updated_by) ON DUPLICATE KEY UPDATE site_name=VALUES(site_name),tagline=VALUES(tagline),footer_text=VALUES(footer_text),updated_by=VALUES(updated_by)'); $up->execute(['language_id'=>$byCode[$code],'site_name'=>$name,'tagline'=>$tag!==''?$tag:null,'footer_text'=>$foot,'updated_by'=>$actor['id']]); }
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
