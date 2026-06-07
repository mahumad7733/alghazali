<?php
require_once 'includes/db.php';

echo "Fixing account 113 structure...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get account 113's id
    $stmt = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '113'");
    $stmt->execute();
    $account113Id = $stmt->fetchColumn();
    echo "Account 113 id: $account113Id\n";
    
    // Set 11301 and 11302's parent_id to 113's id
    $stmt = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code IN ('11301', '11302')");
    $stmt->execute([$account113Id]);
    echo "Updated 11301 and 11302's parent_id to $account113Id\n";
    
    // Verify
    echo "\nVerifying account 113 structure:\n";
    $stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code LIKE '113%' ORDER BY account_code");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as $acc) {
        echo "  - {$acc['account_code']}: {$acc['account_name_ar']}, parent_id={$acc['parent_id']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
