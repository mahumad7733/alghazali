<?php
declare(strict_types=1);

namespace App\Includes;

final class Security
{
    /** @return array<string, mixed> */
    public static function jsonInput(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            Response::error('نوع البيانات غير مدعوم. يجب إرسال JSON.', 'VALIDATION_ERROR', 415);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            Response::error('بيانات الطلب مطلوبة.', 'VALIDATION_ERROR', 422);
        }

        try {
            $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::error('صيغة JSON غير صالحة.', 'VALIDATION_ERROR', 422);
        }

        if (!is_array($input)) {
            Response::error('بيانات الطلب غير صالحة.', 'VALIDATION_ERROR', 422);
        }

        return $input;
    }

    public static function cleanText(mixed $value, int $maxLength = 255): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('القيمة المدخلة غير صالحة.');
        }

        return $value;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function assertCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if (!is_string($token) || $token === '' || $expected === '' || !hash_equals($expected, $token)) {
            Response::error('انتهت جلسة الحماية. يرجى تحديث الصفحة والمحاولة مجددًا.', 'CSRF_VALIDATION_FAILED', 403);
        }
    }

    public static function escape(string|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
