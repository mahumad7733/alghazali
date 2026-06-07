<?php
require_once 'includes/db.php';

echo "<h1>Renaming Box Accounts from 101xxxx to 11101xxxx</h1>";

try {
    $pdo->beginTransaction();
    
    $id_11101 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11101'")->fetchColumn();
    if (!$id_11101) {
        throw new Exception("Account 11101 (الصناديق) not found! Run fix_chart_of_accounts.php first!");
    }
    echo "<p>Found 11101 (الصناديق) with ID: $id_11101</p>";
    
    // Find all accounts starting with 101!
    $stmt = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code LIKE '101%' AND account_code != '101' ORDER BY account_code");
    $boxAccounts = $stmt->fetchAll();
    
    foreach ($boxAccounts as $box) {
        $oldCode = $box['account_code'];
        // Replace '101' with '11101'!
        $newCode = preg_replace('/^101/', '11101', $oldCode);
        echo "<p>Renaming account: {$box['account_name_ar']} ({$oldCode}) → {$newCode}</p>";
        
        $stmtUpdate = $pdo->prepare("UPDATE unified_accounts SET account_code = ? WHERE id = ?");
        $stmtUpdate->execute([$newCode, $box['id']]);
    }
    
    $pdo->commit();
    echo "<h2 style='color:green'>✅ Done! Now go to http://localhost:8000/ghazali/admin/financial_accounts.php?repair_tree=1 to repair the tree!</h2>";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<h2 style='color:red'>❌ Error: " . $e->getMessage() . "</h2>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>