<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class LanguageService
{
    private const COOKIE_NAME = 'rihla_language';
    private const COOKIE_TTL = 2592000;

    /** @var array<string, array<string, mixed>> */
    private static array $contextCache = [];
    /** @var array<string, array<string, string>> */
    private static array $translationCache = [];
    private ?bool $schemaAvailable = null;

    public function __construct(private Database $database)
    {
    }

    /** @return array{code:string,name_ar:string,name_native:string,direction:string,bootstrap_css:string,languages:list<array<string,mixed>>} */
    public function context(): array
    {
        $language = $this->resolve();
        $code = $language['code'];
        if (isset(self::$contextCache[$code])) {
            return self::$contextCache[$code];
        }

        $context = [
            'code' => $code,
            'name_ar' => (string) ($language['name_ar'] ?? $code),
            'name_native' => (string) ($language['name_native'] ?? $code),
            'direction' => (string) ($language['direction'] ?? 'rtl'),
            'bootstrap_css' => (string) ($language['direction'] ?? 'rtl') === 'rtl'
                ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css'
                : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            'languages' => $this->availableLanguages(),
        ];
        self::$contextCache[$code] = $context;
        return $context;
    }

    /** @return list<array{id:int,name_ar:string,name_native:string,code:string,direction:string,is_active:int,is_default:int}> */
    public function availableLanguages(): array
    {
        if (!$this->hasSchema()) {
            return [[
                'id' => 0,
                'name_ar' => 'العربية',
                'name_native' => 'العربية',
                'code' => 'ar',
                'direction' => 'rtl',
                'is_active' => 1,
                'is_default' => 1,
            ]];
        }

        $statement = $this->database->pdo()->query(
            'SELECT id, name_ar, name_native, code, direction, is_active, is_default
             FROM languages WHERE is_active = 1 ORDER BY is_default DESC, id ASC'
        );
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name_ar' => (string) $row['name_ar'],
                'name_native' => (string) $row['name_native'],
                'code' => (string) $row['code'],
                'direction' => (string) $row['direction'],
                'is_active' => (int) $row['is_active'],
                'is_default' => (int) $row['is_default'],
            ];
        }, $statement->fetchAll());
    }

    /** @param array<string, scalar> $replace */
    public function translate(string $key, ?string $fallback = null, array $replace = []): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $language = $this->resolve();
        $code = $language['code'];
        if (!array_key_exists($key, self::$translationCache[$code] ?? [])) {
            $this->loadTranslations($code);
        }
        $value = self::$translationCache[$code][$key] ?? null;
        if ($value === null || trim($value) === '') {
            if ($code !== 'ar') {
                if (!array_key_exists($key, self::$translationCache['ar'] ?? [])) {
                    $this->loadTranslations('ar');
                }
                $value = self::$translationCache['ar'][$key] ?? null;
            }
        }
        $value = ($value !== null && trim($value) !== '') ? $value : ($fallback ?? $key);
        foreach ($replace as $name => $replacement) {
            $value = str_replace(':' . $name, (string) $replacement, $value);
        }
        return $value;
    }

    /** @return array{code:string,name_ar:string,name_native:string,direction:string} */
    public function resolve(): array
    {
        if (isset($_GET['lang'])) {
            $requested = $this->findActiveByCode((string) $_GET['lang']);
            if ($requested !== null) {
                $this->remember((string) $requested['code']);
                return $requested;
            }
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            $preference = $this->userPreference($userId);
            if ($preference !== null) {
                return $preference;
            }
        }

        $sessionLanguage = trim((string) ($_SESSION[self::COOKIE_NAME] ?? ''));
        if ($sessionLanguage !== '') {
            $selected = $this->findActiveByCode($sessionLanguage);
            if ($selected !== null) {
                return $selected;
            }
        }

        $cookie = trim((string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
        if ($cookie !== '') {
            $selected = $this->findActiveByCode($cookie);
            if ($selected !== null) {
                return $selected;
            }
        }

        $accepted = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach (preg_split('/\s*,\s*/', $accepted) ?: [] as $candidate) {
            $code = strtolower(substr(trim(explode(';', $candidate, 2)[0]), 0, 16));
            if ($code === '') {
                continue;
            }
            $baseCode = explode('-', $code, 2)[0];
            $selected = $this->findActiveByCode($code) ?? $this->findActiveByCode($baseCode);
            if ($selected !== null) {
                return $selected;
            }
        }

        $default = $this->defaultLanguage();
        return $default ?? ['code' => 'ar', 'name_ar' => 'العربية', 'name_native' => 'العربية', 'direction' => 'rtl'];
    }

    public function setLanguage(string $code, ?int $userId = null): array
    {
        $language = $this->findActiveByCode($code);
        if ($language === null) {
            throw new \InvalidArgumentException('اللغة المطلوبة غير متاحة.');
        }
        $this->remember((string) $language['code']);
        if ($userId !== null && $userId > 0 && $this->hasSchema()) {
            $statement = $this->database->pdo()->prepare(
                'INSERT INTO user_language_preferences (user_id, language_id) VALUES (:user_id, :language_id)
                 ON DUPLICATE KEY UPDATE language_id = VALUES(language_id), updated_at = CURRENT_TIMESTAMP'
            );
            $statement->execute(['user_id' => $userId, 'language_id' => (int) $language['id']]);
        }
        self::$contextCache = [];
        return $language;
    }

    /** @return array<string, string> */
    public function translationsForClient(?array $keys = null): array
    {
        $this->loadTranslations($this->resolve()['code']);
        $catalog = self::$translationCache[$this->resolve()['code']] ?? [];
        if ($keys === null) {
            return $catalog;
        }
        $result = [];
        foreach ($keys as $key) {
            if (isset($catalog[$key])) {
                $result[$key] = $catalog[$key];
            }
        }
        return $result;
    }

    private function remember(string $code): void
    {
        if (PHP_SAPI !== 'cli' && headers_sent() === false) {
            setcookie(self::COOKIE_NAME, $code, [
                'expires' => time() + self::COOKIE_TTL,
                'path' => '/',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $_SESSION[self::COOKIE_NAME] = $code;
    }

    /** @return array{code:string,name_ar:string,name_native:string,direction:string,id?:int}|null */
    private function findActiveByCode(string $code): ?array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        if (!$this->hasSchema()) {
            return $code === 'ar' ? ['code' => 'ar', 'name_ar' => 'العربية', 'name_native' => 'العربية', 'direction' => 'rtl'] : null;
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT id, code, name_ar, name_native, direction FROM languages WHERE code = :code AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name_ar' => (string) $row['name_ar'], 'name_native' => (string) $row['name_native'], 'direction' => (string) $row['direction']];
    }

    /** @return array{code:string,name_ar:string,name_native:string,direction:string,id?:int}|null */
    private function defaultLanguage(): ?array
    {
        if (!$this->hasSchema()) return null;
        try {
            $statement=$this->database->pdo()->query("SELECT l.id,l.code,l.name_ar,l.name_native,l.direction FROM languages l LEFT JOIN site_settings s ON s.id=1 WHERE l.is_active=1 ORDER BY CASE WHEN l.code=COALESCE((SELECT default_language_code FROM site_settings WHERE id=1),'') THEN 0 WHEN l.is_default=1 THEN 1 ELSE 2 END,l.id LIMIT 1");
            $row=$statement->fetch();
            return is_array($row)?['id'=>(int)$row['id'],'code'=>(string)$row['code'],'name_ar'=>(string)$row['name_ar'],'name_native'=>(string)$row['name_native'],'direction'=>(string)$row['direction']]:null;
        } catch (\Throwable) { return null; }
    }
    /** @return array{code:string,name_ar:string,name_native:string,direction:string,id?:int}|null */
    private function userPreference(int $userId): ?array
    {
        if (!$this->hasSchema()) {
            return null;
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT l.id, l.code, l.name_ar, l.name_native, l.direction
             FROM user_language_preferences p INNER JOIN languages l ON l.id = p.language_id
             WHERE p.user_id = :user_id AND l.is_active = 1 LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name_ar' => (string) $row['name_ar'], 'name_native' => (string) $row['name_native'], 'direction' => (string) $row['direction']] : null;
    }

    private function loadTranslations(string $code): void
    {
        if (array_key_exists($code, self::$translationCache)) {
            return;
        }
        self::$translationCache[$code] = [];
        if (!$this->hasSchema()) {
            return;
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT k.key_name, t.value FROM translations t
             INNER JOIN translation_keys k ON k.id = t.translation_key_id
             INNER JOIN languages l ON l.id = t.language_id
             WHERE l.code = :code AND l.is_active = 1 AND t.value IS NOT NULL'
        );
        $statement->execute(['code' => $code]);
        foreach ($statement->fetchAll() as $row) {
            self::$translationCache[$code][(string) $row['key_name']] = (string) $row['value'];
        }
    }

    private function hasSchema(): bool
    {
        if ($this->schemaAvailable !== null) {
            return $this->schemaAvailable;
        }
        try {
            $statement = $this->database->pdo()->prepare("SHOW TABLES LIKE 'languages'");
            $statement->execute();
            $this->schemaAvailable = $statement->fetchColumn() !== false;
        } catch (\Throwable) {
            $this->schemaAvailable = false;
        }
        return $this->schemaAvailable;
    }
}
