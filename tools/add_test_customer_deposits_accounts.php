<?php
require_once 'includes/db.php';

echo "<h1>Adding Test Customer Deposits Accounts under 21102 دفعات مقدمة من العملاء</h1>";

try {
    $pdo->beginTransaction();
    
    $id_21102 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '21102'")->fetchColumn();
    if (!$id_21102) {
        throw new Exception("Account 21102 (دفعات مقدمة من العملاء) not found! Run fix_chart_of_accounts.php first!");
    }
    echo "<p>Found 21102 (دفعات مقدمة من العملاء) with ID: $id_21102</p>";
    
    $testDeposits = [
        ['21102001', 'دفعة مقدمة من أحمد علي'],
        ['21102002', 'دفعة مقدمة من محمد حسن'],
        ['21102003', 'دفعة مقدمة من شركة البركة']
    ];
    
    foreach ($testDeposits as $deposit) {
        list($code, $name) = $deposit;
        
        $checkStmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $checkStmt->execute([$code]);
        if (!$checkStmt->fetch()) {
            echo "<p>Adding test deposit: $code - $name</p>";
            $insertStmt = $pdo->prepare("
                INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id, account_status)
                VALUES (?, ?, 'liability', 'credit', ?, 'active')
            ");
            $insertStmt->execute([$code, $name, $id_21102]);
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
            echo "<p>Test deposit $code already exists, skipping.</p>";
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