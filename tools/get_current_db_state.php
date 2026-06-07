<?php
require_once 'includes/db.php';

echo "=== Current Database State ===\n";

// Get unified_accounts structure
echo "\n1. unified_accounts columns:\n";
$stmt = $pdo->prepare("DESCRIBE unified_accounts");
$stmt->execute();
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);

// Get unified_accounts (account 113 and children)
echo "\n2. unified_accounts (account 113 and children):\n";
$stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code LIKE '113%' ORDER BY account_code");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

// Get fn_get_default_leaf_account
echo "\n3. fn_get_default_leaf_account definition:\n";
try {
    $stmt = $pdo->prepare("SHOW CREATE FUNCTION fn_get_default_leaf_account");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Function'] ?? 'Not found';
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Get sp_post_invoice
echo "\n4. sp_post_invoice definition:\n";
try {
    $stmt = $pdo->prepare("SHOW CREATE PROCEDURE sp_post_invoice");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Procedure'] ?? 'Not found';
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Get sp_recalculate_invoice_payment
echo "\n5. sp_recalculate_invoice_payment definition:\n";
try {
    $stmt = $pdo->prepare("SHOW CREATE PROCEDURE sp_recalculate_invoice_payment");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Procedure'] ?? 'Not found';
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

