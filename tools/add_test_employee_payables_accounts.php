<?php
require_once 'includes/db.php';

echo "<h1>Adding Test Employee Payables Accounts under 21103 مستحقات الموظفين</h1>";

try {
    $pdo->beginTransaction();
    
    $id_21103 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '21103'")->fetchColumn();
    if (!$id_21103) {
        throw new Exception("Account 21103 (مستحقات الموظفين) not found! Run fix_chart_of_accounts.php first!");
    }
    echo "<p>Found 21103 (مستحقات الموظفين) with ID: $id_21103</p>";
    
    $testAccounts = [
        ['21103001', 'مستحقات رواتب الموظفين'],
        ['21103002', 'مستحقات بدلات الموظفين'],
        ['21103003', 'مستحقات مكافآت الموظفين']
    ];
    
    foreach ($testAccounts as $acc) {
        list($code, $name) = $acc;
        
        $checkStmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) {
            echo "<p>Adding test account: $code - $name</p>";
            $insertStmt = $pdo->prepare("
                INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                VALUES (?, ?, 'liability', 'credit', ?, 'active')
            ");
            $insertStmt->execute([$code, $name, $id_21103]);
            $accId = $pdo->lastInsertId();
            
            // Activate base currency!
            $baseCurrencyId = $pdo->query("SELECT id FROM currencies WHERE is_default = 1 LIMIT 1")->fetchColumn();
            if ($baseCurrencyId) {
                $stmtBaseBalance = $pdo->prepare("
                    INSERT INTO account_balances_unified (account_id, currency_id, opening_balance, current_balance, is_frozen) 
                    VALUES (?, ?, 0, 0, 0)
                ");
                $stmtBaseBalance->execute([$accId, $baseCurrencyId]);
            }
        } else {
            echo "<p>Test account $code already exists, skipping.</p>";
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