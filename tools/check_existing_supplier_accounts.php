<?php
require_once 'includes/db.php';

echo "=== الحسابات الموردين الموجودة بالفعل (IDs 86, 87):\n\n";
$stmt = $pdo->query("SELECT * FROM unified_accounts WHERE id IN (86, 87)");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    foreach ($row as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }
    echo "\n---\n";
}
?>