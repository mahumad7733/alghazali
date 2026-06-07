<?php
require_once 'includes/db.php';

echo "=== بنية جدول unified_accounts ===\n\n";
$stmt = $pdo->query("DESCRIBE unified_accounts");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$row['Field']} - {$row['Type']}\n";
}

echo "\n=== أول 5 صفوف للتحقق ===\n";
$stmt_data = $pdo->query("SELECT * FROM unified_accounts LIMIT 5");
while ($row = $stmt_data->fetch(PDO::FETCH_ASSOC)) {
    echo "  ID: {$row['id']}, Code: {$row['account_code']}, Name: {$row['account_name_ar']}\n";
}
?>