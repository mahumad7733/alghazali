<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;

final class PasswordPolicy
{
    /** @return array{code:string,label:string,min_length:int,description:string} */
    public static function settings(Database $database): array
    {
        $code = 'medium';
        try {
            $value = $database->pdo()->query("SELECT password_policy FROM site_settings WHERE id = 1 LIMIT 1")->fetchColumn();
            if (is_string($value) && in_array($value, ['normal', 'medium', 'complex'], true)) $code = $value;
        } catch (\Throwable) {
        }
        return self::definition($code);
    }

    /** @return array{code:string,label:string,min_length:int,description:string} */
    public static function definition(string $code): array
    {
        return match ($code) {
            'normal' => ['code' => 'normal', 'label' => 'عادية', 'min_length' => 8, 'description' => '8 أحرف على الأقل.'],
            'complex' => ['code' => 'complex', 'label' => 'معقدة', 'min_length' => 12, 'description' => '12 حرفًا على الأقل مع حرف كبير وصغير ورقم ورمز.'],
            default => ['code' => 'medium', 'label' => 'متوسطة', 'min_length' => 10, 'description' => '10 أحرف على الأقل مع حروف وأرقام.'],
        };
    }

    public static function validate(Database $database, string $password): void
    {
        $rule = self::settings($database);
        $valid = mb_strlen($password) >= $rule['min_length'];
        if ($rule['code'] === 'medium') $valid = $valid && preg_match('/[A-Za-z]/', $password) === 1 && preg_match('/\d/', $password) === 1;
        if ($rule['code'] === 'complex') $valid = $valid && preg_match('/[a-z]/', $password) === 1 && preg_match('/[A-Z]/', $password) === 1 && preg_match('/\d/', $password) === 1 && preg_match('/[^A-Za-z\d]/', $password) === 1;
        if (!$valid) Response::error('كلمة المرور لا تطابق سياسة المنصة: ' . $rule['description'], 'PASSWORD_POLICY_ERROR', 422);
    }
}
