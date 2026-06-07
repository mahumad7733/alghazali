<?php
require_once '../includes/db.php';

echo "=== قائمة الموردين ===\n";
$stmt = $pdo->query("SELECT id, supplier_name, account_id FROM suppliers");
$suppliers = $stmt->fetchAll();
foreach ($suppliers as $s) {
    echo "ID: " . $s['id'] . ", Name: " . $s['supplier_name'] . ", Account ID: " . ($s['account_id'] ?? 'NULL') . "\n";
}

echo "\n=== الحسابات تحت 21101 ===\n";
$stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$stmt_parent->execute();
$parent_id = $stmt_parent->fetchColumn();
if ($parent_id) {
    $stmt_acc = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE parent_id = ?");
    $stmt_acc->execute([$parent_id]);
    while ($acc = $stmt_acc->fetch()) {
        echo "ID: " . $acc['id'] . ", Code: " . $acc['account_code'] . ", Name: " . $acc['account_name_ar'] . "\n";
    }
}
?>