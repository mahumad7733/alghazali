<?php
require_once 'includes/db.php';

echo "=== Checking Fixed Accounts ===\n\n";

$codes = ['113', '11301', '11302', '21103', '21103001', '21103002', '502', '50201', '50201001', '50201002'];
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code IN ($placeholders) ORDER BY account_code");
$stmt->execute($codes);

while ($row = $stmt->fetch()) {
    echo "  ID: {$row['id']}, Code: {$row['account_code']}, Name: {$row['account_name_ar']}, Parent ID: " . var_export($row['parent_id'], true) . "\n";
}

echo "\n=== Done ===\n";
?>