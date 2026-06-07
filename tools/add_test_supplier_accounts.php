<?php
require_once 'includes/db.php';

echo "<h1>Adding Test Supplier Accounts under 21101 الموردين</h1>";

try {
    $pdo->beginTransaction();
    
    $id_21101 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '21101'")->fetchColumn();
    if (!$id_21101) {
        throw new Exception("Account 21101 (الموردين) not found! Run fix_chart_of_accounts.php first!");
    }
    echo "<p>Found 21101 (الموردين) with ID: $id_21101</p>";
    
    $testSuppliers = [
        ['21101001', 'المورد / الخطوط الجوية'],
        ['21101002', 'المورد / مكتب العمرة'],
        ['21101003', 'المورد / التأشيرات']
    ];
    
    foreach ($testSuppliers as $supplier) {
        list($code, $name) = $supplier;
        
        $checkStmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) {
            echo "<p>Adding test supplier: $code - $name</p>";
            $insertStmt = $pdo->prepare("
                INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                VALUES (?, ?, 'liability', 'credit', ?, 'active')
            ");
            $insertStmt->execute([$code, $name, $id_21101]);
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
            echo "<p>Test supplier $code already exists, skipping.</p>";
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