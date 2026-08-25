<?php
declare(strict_types=1);

$password = (string) getenv('SUPER_USER_PASSWORD');
if ($password === '') {
    throw new RuntimeException('كلمة المرور مطلوبة.');
}
echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
