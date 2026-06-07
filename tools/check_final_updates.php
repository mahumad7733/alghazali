<?php
require_once 'includes/db.php';

echo "=== Checking Final Updates ===\n\n";

echo "1. Checking fn_get_default_leaf_account(11101): " . var_export($pdo->query("SELECT fn_get_default_leaf_account('11101')")->fetchColumn(), true) . "\n";
echo "2. Checking fn_get_default_leaf_account(11101001): " . var_export($pdo->query("SELECT fn_get_default_leaf_account('11101001')")->fetchColumn(), true) . "\n";
echo "3. Checking fn_get_default_leaf_account(11102): " . var_export($pdo->query("SELECT fn_get_default_leaf_account('11102')")->fetchColumn(), true) . "\n";
echo "4. Checking fn_get_default_leaf_account(11102001): " . var_export($pdo->query("SELECT fn_get_default_leaf_account('11102001')")->fetchColumn(), true) . "\n";
echo "\n";
echo "5. Checking fn_get_account_by_type('box', '11101001'): " . var_export($pdo->query("SELECT fn_get_account_by_type('box', '11101001')")->fetchColumn(), true) . "\n";
echo "6. Checking fn_get_account_by_type('bank', '11102001'): " . var_export($pdo->query("SELECT fn_get_account_by_type('bank', '11102001')")->fetchColumn(), true) . "\n";
echo "\n";

echo "7. Checking accounts:\n";
$stmt_acc = $pdo->query("SELECT id, account_code, account_name_ar, account_type FROM unified_accounts WHERE account_code IN ('11101', '11101001', '11102', '11102001') ORDER BY account_code");
while ($row = $stmt_acc->fetch()) {
    echo "  ID: {$row['id']}, Code: {$row['account_code']}, Name: {$row['account_name_ar']}, Type: {$row['account_type']}\n";
}

echo "\n=== Done ===";
?>