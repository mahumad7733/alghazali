<?php
require_once 'includes/db.php';

echo "<h1>Adding Test Agent Accounts under 11203 الوكلاء</h1>";

try {
    $pdo->beginTransaction();
    
    $id_11203 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11203'")->fetchColumn();
    if (!$id_11203) {
        throw new Exception("Account 11203 (الوكلاء) not found! Run fix_chart_of_accounts.php first!");
    }
    echo "<p>Found 11203 (الوكلاء) with ID: $id_11203</p>";
    
    $testAgents = [
        ['11203001', 'الوكيل / خالد'],
        ['11203002', 'الوكيل / البركة'],
        ['11203003', 'الوكيل / التميز']
    ];
    
    foreach ($testAgents as $agent) {
        list($code, $name) = $agent;
        
        $checkStmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) {
            echo "<p>Adding test agent: $code - $name</p>";
            $insertStmt = $pdo->prepare("
                INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                VALUES (?, ?, 'asset', 'debit', ?, 'active')
            ");
            $insertStmt->execute([$code, $name, $id_11203]);
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
            echo "<p>Test agent $code already exists, skipping.</p>";
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