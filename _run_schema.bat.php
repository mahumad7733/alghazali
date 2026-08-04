<?php
$mysql = 'C:\xampp\mysql\bin\mysql.exe';
$args = ['-h','127.0.0.1','-P','3307','-u','root','-p738155','alghazali','-e'];

$queries = [
    "SHOW COLUMNS FROM unified_accounts;",
    "SHOW COLUMNS FROM customers;",
    "SELECT id, account_code, account_name_ar, account_parent, account_type, normal_balance, account_level, is_active FROM unified_accounts ORDER BY id ASC LIMIT 20;",
];

foreach ($queries as $q) {
    echo str_repeat("=", 70) . "\n";
    echo "$q\n";
    echo str_repeat("-", 70) . "\n";
    $allArgs = array_merge($args, [$q]);
    $cmd = $mysql . ' ' . implode(' ', array_map(function($a) {
        return strpos($a, ' ') !== false || strpos($a, ';') !== false ? '"' . $a . '"' : $a;
    }, $allArgs));
    $out = []; $status = 0;
    exec($cmd . ' 2>&1', $out, $status);
    echo implode("\n", $out) . "\n\n";
}
