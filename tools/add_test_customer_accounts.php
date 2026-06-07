<?php
require_once 'includes/db.php';

echo "<h1>Adding Test Customer Accounts under 11201 العملاء</h1>";

try {
    $pdo->beginTransaction();
    
    $id_11201 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11201'")->fetchColumn();
    if (!$id_11201) {
        throw new Exception("Account 11201 (العملاء) not found! Run fix_chart_of_accounts.php first!");
    }
    echo "<p>Found 11201 (العملاء) with ID: $id_11201</p>";
    
    $testCustomers = [
        ['11201001', 'العميل / أحمد علي'],
        ['11201002', 'العميل / محمد حسن'],
        ['11201003', 'العميل / شركة البركة']
    ];
    
    foreach ($testCustomers as $customer) {
        list($code, $name) = $customer;
        
        $checkStmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) {
            echo "<p>Adding test customer: $code - $name</p>";
            $insertStmt = $pdo->prepare("
                INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                VALUES (?, ?, 'asset', 'debit', ?, 'active')
            ");
            $insertStmt->execute([$code, $name, $id_11201]);
            $accId = $pdo->lastInsertId();
            
            // Activate base currency for this new account!
            $baseCurrencyId = $pdo->query("SELECT id FROM currencies WHERE is_default = 1 LIMIT 1")->fetchColumn();
            if ($baseCurrencyId) {
                $stmtBaseBalance = $pdo->prepare("
                    INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) 
                    VALUES (?, ?, 0, 0, 0)
                ");
                $stmtBaseBalance->execute([$accId, $baseCurrencyId]);
            }
        } else {
            echo "<p>Test customer $code already exists, skipping.</p>";
        }
    }
    
    $pdo->commit();
    echo "<h2 style='color:green'>✅ Done! Now go to http://localhost:8000/ghazali/admin/financial_accounts.php?repair_tree=1!</h2>";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<h2 style='color:red'>❌ Error: " . $e->getMessage() . "</h2>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>