<?php
require 'header.php';
echo "<pre>";

// Check accounts 11201 and 11201001
$stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code IN (11201, 11201001)");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Account codes 11201 and 11201001:\n";
print_r($results);
echo "\n\n";

// Get all children of 11201
if ($results[0]['id']) {
    $stmt2 = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE parent_id = ?");
    $stmt2->execute([$results[0]['id']]);
    $children = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "Children of account ID {$results[0]['id']}:\n";
    print_r($children);
}

echo "</pre>";
?>