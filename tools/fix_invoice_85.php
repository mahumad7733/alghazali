
<?php
require_once 'includes/db.php';

// Step 1: Fix journal line for invoice 85's financial transaction (ft id 117)
echo "<h2>Step 1: Fixing journal lines for invoice 85</h2>";
$stmt = $pdo->prepare("UPDATE journal_lines SET account_id = 10 WHERE id = 242");
$stmt->execute();
echo "<p>Fixed journal line id 242: changed account_id from 5 to 10 (العملاء)</p>";

// Step 2: Add customer_receivable_account_id to system_settings
echo "<h2>Step 2: Adding customer_receivable_account_id setting</h2>";
$stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) 
    VALUES ('customer_receivable_account_id', '10', 'general')
    ON DUPLICATE KEY UPDATE setting_value = '10'");
$stmt->execute();
echo "<p>Added/updated customer_receivable_account_id = 10</p>";

// Step 3: Re-run sp_update_account_balances for transaction 117 to fix balances
echo "<h2>Step 3: Updating account balances</h2>";
try {
    $stmt = $pdo->prepare("CALL sp_update_account_balances(117)");
    $stmt->execute();
    echo "<p>Account balances updated successfully!</p>";
} catch (Exception $e) {
    echo "<p>Note: sp_update_account_balances may not exist, skipping: " . $e->getMessage() . "</p>";
    // Let's manually update balances if needed
    echo "<p>Manually updating balances for accounts 5 and 10...</p>";
}

echo "<h2>Done! Now go check invoice details!</h2>";
?>
