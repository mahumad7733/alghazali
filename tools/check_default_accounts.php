<?php
require_once 'includes/db.php';

echo "=== Checking 11101001 and 11102001 ===\n";

$stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code IN ('11101', '11101001', '11102', '11102001') ORDER BY account_code");
$stmt->execute();
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($accounts);

// Test fn_get_default_leaf_account manually
echo "\nTesting fn_get_default_leaf_account logic manually:\n";
$testCode = '11101001';
$stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
$stmt->execute([$testCode]);
$parentId = $stmt->fetchColumn();
echo "Account $testCode id: $parentId\n";

if ($parentId) {
    $stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE parent_id = ? AND id NOT IN (SELECT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) ORDER BY account_code ASC LIMIT 1");
    $stmt->execute([$parentId]);
    $leafId = $stmt->fetchColumn();
    echo "Leaf account under $testCode: $leafId\n";
}
