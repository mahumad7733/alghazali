<?php
require_once 'includes/db.php';

echo "<h1>Moving Existing Box Accounts Under 11101</h1>";

try {
    $pdo->beginTransaction();
    
    $id_11101 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '11101'")->fetchColumn();
    echo "<p>Found 11101 (الصناديق) with ID: $id_11101</p>";
    
    // Move all accounts starting with 101 (old box codes) to 11101!
    $stmt_move = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code LIKE '101%' AND account_code != '101'");
    $stmt_move->execute([$id_11101]);
    $count = $stmt_move->rowCount();
    echo "<p>Moved $count box accounts under 11101!</p>";
    
    $pdo->commit();
    echo "<h2 style='color:green'>✅ Done! Now go to http://localhost:8000/ghazali/admin/financial_accounts.php?repair_tree=1!</h2>";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<h2 style='color:red'>❌ Error: " . $e->getMessage() . "</h2>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>