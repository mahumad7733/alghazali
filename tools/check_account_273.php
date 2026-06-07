<?php
require_once 'includes/db.php';

echo "=== تفاصيل الحساب ID 273 ===\n\n";
$stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = 273");
$stmt->execute();
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if ($account) {
    foreach ($account as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }
} else {
    echo "❌ لم يتم العثور على الحساب ID 273!\n";
}

echo "\n=== الحسابات الموردين الموجودة في 21101 و الحساب 273 ===\n";
$stmt_all = $pdo->query("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE id IN (21, 86, 87, 273) ORDER BY account_code");
while ($row = $stmt_all->fetch()) {
    echo "  ID: {$row['id']}, Code: {$row['account_code']}, Name: {$row['account_name_ar']}, Parent ID: {$row['parent_id']}\n";
}
?>