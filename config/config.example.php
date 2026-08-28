<?php
declare(strict_types=1);

return [
    'app_name' => 'منصة حجوزات الباصات',
    'app_url' => 'http://localhost/rihla',
    'environment' => 'production',
    'timezone' => 'Asia/Aden',
    'session_name' => 'rihla_session',
    // Generate a unique 32-byte base64 key per installation; never commit the real key.
    'security' => [
        'encryption_key' => 'ضع_مفتاح_تشفير_32_بايت_بصيغة_base64_هنا',
    ],
    'booking_hold_minutes' => 30,
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'rihla',
        'username' => 'ضع_اسم_مستخدم_قاعدة_البيانات_هنا',
        'password' => 'ضع_كلمة_مرور_قاعدة_البيانات_هنا',
        'charset' => 'utf8mb4',
    ],
];
