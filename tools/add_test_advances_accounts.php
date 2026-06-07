<?php
require_once 'includes/db.php';

echo "<h1>Adding Test Advances to Suppliers Accounts under 11204 دفعات مقدمة للموردين</h1>";

try {
    $pdo->beginTransaction();
    
    $id_11204 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11204'")->fetchColumn();
    if (!$id_11204) {
        throw new Exception("Account 11204 (دفعات مقدمة للموردين) not found! Run fix_chart_of_accounts.php first!");
    }
    echo "<p>Found 11204 (دفعات مقدمة للموردين) with ID: $id_11204</p>";
    
    $testAdvances = [
        ['11204001', 'دفعة مقدمة للخطوط الجوية'],
        ['11204002', 'دفعة مقدمة لمكتب العمرة'],
        ['11204003', 'دفعة مقدمة للتأشيرات']
    ];
    
    foreach ($testAdvances as $advance) {
        list($code, $name) = $advance;
        
        $checkStmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) {
            echo "<p>Adding test advance: $code - $name</p>";
            $insertStmt = $pdo->prepare("
                INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                VALUES (?, ?, 'asset', 'debit', ?, 'active')
            ");
            $insertStmt->execute([$code, $name, $id_11204]);
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
            echo "<p>Test advance $code already exists, skipping.</p>";
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