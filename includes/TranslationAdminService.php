<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class TranslationAdminService
{
    public function __construct(private Database $database)
    {
    }

    /** @return list<array<string,mixed>> */
    public function languages(): array
    {
        $this->assertSchema();
        $statement = $this->database->pdo()->query(
            'SELECT id, name_ar, name_native, code, direction, is_active, is_default, created_at, updated_at
             FROM languages ORDER BY is_default DESC, is_active DESC, id ASC'
        );
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createLanguage(array $input): array
    {
        $this->assertSchema();
        $nameAr = $this->text($input['name_ar'] ?? '', 120);
        $nameNative = $this->text($input['name_native'] ?? '', 120);
        $code = strtolower($this->text($input['code'] ?? '', 16));
        if (preg_match('/^[a-z]{2,3}(?:-[a-z]{2,4})?$/', $code) !== 1) {
            throw new \InvalidArgumentException('كود اللغة يجب أن يكون مثل ar أو en أو fr-FR.');
        }
        $direction = (($input['direction'] ?? 'ltr') === 'rtl') ? 'rtl' : 'ltr';
        $isActive = !empty($input['is_active']) ? 1 : 0;
        $isDefault = !empty($input['is_default']) ? 1 : 0;
        return $this->database->transaction(function (PDO $pdo) use ($nameAr, $nameNative, $code, $direction, $isActive, $isDefault): array {
            if ($isDefault === 1) {
                $pdo->exec('UPDATE languages SET is_default = 0 WHERE is_default = 1');
            }
            if ($isDefault === 1) {
                $isActive = 1;
            }
            $statement = $pdo->prepare(
                'INSERT INTO languages (name_ar, name_native, code, direction, is_active, is_default)
                 VALUES (:name_ar, :name_native, :code, :direction, :is_active, :is_default)'
            );
            $statement->execute([
                'name_ar' => $nameAr, 'name_native' => $nameNative, 'code' => $code,
                'direction' => $direction, 'is_active' => $isActive, 'is_default' => $isDefault,
            ]);
            $id = (int) $pdo->lastInsertId();
            $seed = $pdo->prepare(
                'INSERT IGNORE INTO translations (language_id, translation_key_id, value)
                 SELECT :language_id, id, NULL FROM translation_keys'
            );
            $seed->execute(['language_id' => $id]);
            return $this->languageById($pdo, $id);
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateLanguage(int $id, array $input): array
    {
        $this->assertSchema();
        if ($id < 1) {
            throw new \InvalidArgumentException('معرّف اللغة غير صالح.');
        }
        $current = $this->languageById($this->database->pdo(), $id);
        $nameAr = $this->text($input['name_ar'] ?? $current['name_ar'], 120);
        $nameNative = $this->text($input['name_native'] ?? $current['name_native'], 120);
        $code = strtolower($this->text($input['code'] ?? $current['code'], 16));
        if (preg_match('/^[a-z]{2,3}(?:-[a-z]{2,4})?$/', $code) !== 1) {
            throw new \InvalidArgumentException('كود اللغة غير صالح.');
        }
        $direction = (($input['direction'] ?? $current['direction']) === 'rtl') ? 'rtl' : 'ltr';
        $isActive = array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : (int) $current['is_active'];
        $isDefault = array_key_exists('is_default', $input) ? (!empty($input['is_default']) ? 1 : 0) : (int) $current['is_default'];
        if ((int) $current['is_default'] === 1 && ($isActive === 0 || $isDefault === 0)) {
            throw new \InvalidArgumentException('لا يمكن تعطيل اللغة الافتراضية قبل تعيين لغة أخرى افتراضية.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($id, $nameAr, $nameNative, $code, $direction, $isActive, $isDefault): array {
            if ($isDefault === 1) {
                $pdo->exec('UPDATE languages SET is_default = 0 WHERE is_default = 1');
                $isActive = 1;
            }
            $statement = $pdo->prepare(
                'UPDATE languages SET name_ar = :name_ar, name_native = :name_native, code = :code,
                 direction = :direction, is_active = :is_active, is_default = :is_default WHERE id = :id'
            );
            $statement->execute([
                'name_ar' => $nameAr, 'name_native' => $nameNative, 'code' => $code,
                'direction' => $direction, 'is_active' => $isActive, 'is_default' => $isDefault, 'id' => $id,
            ]);
            return $this->languageById($pdo, $id);
        });
    }

    public function setActive(int $id, bool $active): array
    {
        return $this->updateLanguage($id, ['is_active' => $active]);
    }

    /** @return array<string,mixed> */
    public function translationCatalog(string $search = '', int $limit = 250): array
    {
        $this->assertSchema();
        $limit = max(1, min($limit, 500));
        $languages = $this->languages();
        $sql = 'SELECT k.id, k.key_name, k.description, k.context FROM translation_keys k';
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' LEFT JOIN translations ts ON ts.translation_key_id = k.id LEFT JOIN languages ls ON ls.id = ts.language_id
                      WHERE k.key_name LIKE :search OR k.description LIKE :search OR ts.value LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        $sql .= ' GROUP BY k.id, k.key_name, k.description, k.context ORDER BY k.key_name ASC LIMIT ' . $limit;
        $keys = $this->database->pdo()->prepare($sql);
        $keys->execute($params);
        $rows = $keys->fetchAll();
        $values = $this->database->pdo()->query(
            'SELECT t.translation_key_id, l.code, t.value FROM translations t INNER JOIN languages l ON l.id = t.language_id'
        )->fetchAll();
        $byKey = [];
        foreach ($values as $value) {
            $byKey[(int) $value['translation_key_id']][(string) $value['code']] = (string) ($value['value'] ?? '');
        }
        $items = [];
        foreach ($rows as $row) {
            $translations = $byKey[(int) $row['id']] ?? [];
            $status = [];
            foreach ($languages as $language) {
                $code = (string) $language['code'];
                $status[$code] = trim((string) ($translations[$code] ?? '')) !== '' ? 'complete' : 'untranslated';
            }
            $items[] = [
                'id' => (int) $row['id'], 'key_name' => (string) $row['key_name'],
                'description' => $row['description'], 'context' => $row['context'],
                'translations' => $translations, 'status' => $status,
            ];
        }
        return ['languages' => $languages, 'items' => $items];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createTranslationKey(array $input, ?int $updatedBy = null): array
    {
        $this->assertSchema();
        $keyName = trim((string) ($input['key_name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $context = trim((string) ($input['context'] ?? ''));
        if ($keyName === '' || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/i', $keyName) !== 1 || mb_strlen($keyName) > 190) {
            throw new \InvalidArgumentException('مفتاح الترجمة يجب أن يكون مثل home.title أو booking.confirm.');
        }
        if (mb_strlen($description) > 255 || mb_strlen($context) > 80) {
            throw new \InvalidArgumentException('وصف الترجمة أو سياقها طويل جدًا.');
        }
        $pdo = $this->database->pdo();
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO translation_keys (key_name, description, context) VALUES (:key_name, :description, :context)');
            $statement->execute(['key_name' => $keyName, 'description' => $description === '' ? null : $description, 'context' => $context === '' ? null : $context]);
            $keyId = (int) $pdo->lastInsertId();
            $seed = $pdo->prepare('INSERT INTO translations (language_id, translation_key_id, value, updated_by) SELECT id, :key_id, NULL, :updated_by FROM languages WHERE is_active = 1');
            $seed->execute(['key_id' => $keyId, 'updated_by' => $updatedBy]);
            $pdo->commit();
            return ['id' => $keyId, 'key_name' => $keyName, 'description' => $description === '' ? null : $description, 'context' => $context === '' ? null : $context, 'status' => 'untranslated'];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof \PDOException && (string) $exception->getCode() === '23000') throw new \InvalidArgumentException('مفتاح الترجمة موجود مسبقًا.');
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function updateTranslation(int $keyId, int $languageId, string $value, ?int $updatedBy = null): array
    {
        $this->assertSchema();
        if ($keyId < 1 || $languageId < 1) {
            throw new \InvalidArgumentException('مفتاح اللغة أو اللغة غير صالح.');
        }
        if (mb_strlen($value) > 10000) {
            throw new \InvalidArgumentException('الترجمة طويلة جدًا.');
        }
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO translations (language_id, translation_key_id, value, updated_by)
             VALUES (:language_id, :translation_key_id, :value, :updated_by)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['language_id' => $languageId, 'translation_key_id' => $keyId, 'value' => trim($value) === '' ? null : $value, 'updated_by' => $updatedBy]);
        return ['key_id' => $keyId, 'language_id' => $languageId, 'value' => trim($value) === '' ? null : $value];
    }

    private function assertSchema(): void
    {
        $statement = $this->database->pdo()->query("SHOW TABLES LIKE 'languages'");
        if ($statement->fetchColumn() === false) {
            throw new \RuntimeException('نظام اللغات غير مهيأ بعد. نفّذ migration اللغات والترجمة محليًا أولًا.');
        }
    }

    /** @return array<string,mixed> */
    private function languageById(PDO $pdo, int $id): array
    {
        $statement = $pdo->prepare('SELECT id, name_ar, name_native, code, direction, is_active, is_default, created_at, updated_at FROM languages WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new \InvalidArgumentException('اللغة المطلوبة غير موجودة.');
        }
        return $row;
    }

    private function text(mixed $value, int $max): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new \InvalidArgumentException('بيانات اللغة مطلوبة وبطول صالح.');
        }
        return $value;
    }
}
