<?php
require_once 'includes/db.php';

echo "=== الحسابات الموردين في شجرة الحسابات الجديدة (21101 - الموردين) ===\n\n";

// 1. Get parent supplier account ID
$stmt_parent = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code = '21101'");
$stmt_parent->execute();
$parent_supplier = $stmt_parent->fetch();
if ($parent_supplier) {
    echo "حساب الأب للموردين: ID {$parent_supplier['id']}, Code {$parent_supplier['account_code']}, Name {$parent_supplier['account_name_ar']}\n\n";

    // 2. Get all child accounts (suppliers) under this parent
    $stmt_suppliers = $pdo->prepare("
        SELECT ua.id, ua.account_code, ua.account_name_ar, 
               s.id AS supplier_id, s.supplier_name, s.account_id AS old_supplier_account_id
        FROM unified_accounts ua
        LEFT JOIN suppliers s ON ua.id = s.account_id
        WHERE ua.parent_id = ?
        ORDER BY ua.account_code
    ");
    $stmt_suppliers->execute([$parent_supplier['id']]);

    echo "الحسابات الموردين (أبناء 21101):\n";
    while ($row = $stmt_suppliers->fetch()) {
        echo "  ID: {$row['id']}, Code: {$row['account_code']}, Name: {$row['account_name_ar']}\n";
        if ($row['supplier_id']) {
            echo "    Supplier Record: ID {$row['supplier_id']}, Name: {$row['supplier_name']}, Old Account ID: {$row['old_supplier_account_id']}\n";
        }
        echo "\n";
    }
} else {
    echo "❌ لم يتم العثور على حساب أب للموردين (account_code = 21101)!\n";
}

echo "\n=== قائمة الموردين من جدول suppliers (لمقارنة):\n";
$stmt_old_suppliers = $pdo->query("SELECT id, supplier_name, account_id FROM suppliers WHERE deleted_at IS NULL ORDER BY id");
while ($row = $stmt_old_suppliers->fetch()) {
    echo "  ID: {$row['id']}, Name: {$row['supplier_name']}, Account ID: {$row['account_id']}\n";
}
?>