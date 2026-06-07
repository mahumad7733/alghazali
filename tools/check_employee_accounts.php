<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db = 'alghazali';

echo "=== Checking additional accounts from employee_accounts_migration.sql ===\n";

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);

    $additionalAccounts = ['21103', '21103001', '21103002', '502', '50201', '50201001', '50201002'];
    foreach ($additionalAccounts as $code) {
        $stmt = $pdo->prepare("SELECT id, account_name_ar FROM unified_accounts WHERE account_code = ?");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if ($row) {
            echo "✅ Account $code: Found (id={$row['id']}, name={$row['account_name_ar']})\n";
        } else {
            echo "❌ Account $code: NOT Found\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
