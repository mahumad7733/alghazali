<?php
declare(strict_types=1);

namespace App\Includes;

use RuntimeException;

final class SecretVault
{
    private const PREFIX = 'v1:';

    public static function isConfigured(): bool
    {
        return self::key() !== null;
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('يجب إعداد مفتاح تشفير الخادم قبل حفظ أي سر.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('تعذر تشفير السر على الخادم.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(?string $ciphertext): string
    {
        $ciphertext = (string) $ciphertext;
        if ($ciphertext === '') {
            return '';
        }
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            return '';
        }
        $key = self::key();
        if ($key === null) {
            return '';
        }
        $raw = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $body = substr($raw, 28);
        $plaintext = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? '' : $plaintext;
    }

    public static function mask(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('•', $length);
        }
        return substr($value, 0, 2) . str_repeat('•', min(12, $length - 4)) . substr($value, -2);
    }

    private static function key(): ?string
    {
        static $key;
        static $loaded = false;
        if ($loaded) {
            return $key;
        }
        $loaded = true;
        $config = [];
        $configFile = defined('APP_ROOT') ? APP_ROOT . '/config/config.php' : '';
        if ($configFile !== '' && is_file($configFile)) {
            $loadedConfig = require $configFile;
            if (is_array($loadedConfig)) {
                $config = $loadedConfig;
            }
        }
        $raw = (string) ($config['security']['encryption_key'] ?? getenv('RIHLA_ENCRYPTION_KEY') ?: '');
        if ($raw === '') {
            return $key = null;
        }
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $key = $decoded;
        }
        if (strlen($raw) >= 32) {
            return $key = hash('sha256', $raw, true);
        }
        return $key = null;
    }
}
