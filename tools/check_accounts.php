<?php
require_once 'includes/db.php';

echo "=== Checking Accounts ===\n";

// Check account id=23
echo "\n1. Account id=23:\n";
$stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = 23");
$stmt->execute();
$account23 = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($account23);

// Check all accounts starting with 113
echo "\n2. Accounts starting with 113:\n";
$stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE account_code LIKE '113%' ORDER BY account_code");
$stmt->execute();
$accounts113 = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($accounts113);

// Check accounts 11101001 and 11102001
echo "\n3. Accounts 11101001 and 11102001:\n";
$stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE account_code IN ('11101001', '11102001')");
$stmt->execute();
$defaultAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($defaultAccounts);

// Check sp_post_receipt_voucher and sp_post_payment_voucher
echo "\n4. sp_post_receipt_voucher:\n";
try {
    $stmt = $pdo->prepare("SHOW CREATE PROCEDURE sp_post_receipt_voucher");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Procedure'] ?? 'Not found';
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }

echo "\n5. sp_post_payment_voucher:\n";
try {
    $stmt = $pdo->prepare("SHOW CREATE PROCEDURE sp_post_payment_voucher");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Procedure'] ?? 'Not found';
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }

echo "\n6. sp_unpost_invoice:\n";
try {
    $stmt = $pdo->prepare("SHOW CREATE PROCEDURE sp_unpost_invoice");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Procedure'] ?? 'Not found';
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }
