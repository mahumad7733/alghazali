<?php
require_once 'includes/db.php';

echo "<h1>Debug Account 105 (شركة المتصدر لنقل البري)</h1>";

// Step 1: Get account info
$stmt_acc = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = 105");
$stmt_acc->execute();
$acc = $stmt_acc->fetch(PDO::FETCH_ASSOC);
echo "<h3>Account Info:</h3>";
echo "<pre>";
print_r($acc);
echo "</pre>";

// Step 2: Get direct_unified_balance
$stmt_bal = $pdo->prepare("
    SELECT 
        (SELECT SUM(ab.current_balance_base) FROM account_balances_unified ab WHERE ab.account_id = 105) as direct_unified_balance,
        (SELECT GROUP_CONCAT(CONCAT('currency:', ab.currency_id, ' | bal:', ab.current_balance, ' | base:', ab.current_balance_base) SEPARATOR '<br>') 
         FROM account_balances_unified ab WHERE ab.account_id = 105) as balance_details
");
$stmt_bal->execute();
$bal_info = $stmt_bal->fetch(PDO::FETCH_ASSOC);
echo "<h3>Direct Unified Balance:</h3>";
echo "<pre>";
print_r($bal_info);
echo "</pre>";

// Step 3: Get all balances rows
echo "<h3>All Balance Rows:</h3>";
$stmt_all_bal = $pdo->prepare("SELECT * FROM account_balances_unified WHERE account_id = 105");
$stmt_all_bal->execute();
$all_balances = $stmt_all_bal->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($all_balances);
echo "</pre>";
?>
