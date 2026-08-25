<?php
declare(strict_types=1);

$passwords = [
    'admin' => 'Admin@123',
    'company' => 'Company@123',
    'agent' => 'Agent@123',
    'customer' => 'Customer@123',
];

foreach ($passwords as $key => $password) {
    echo $key . '=' . password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
}
