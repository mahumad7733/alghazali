<?php
require_once 'includes/db.php';

echo "=== بنية جدول account_balances_unified ===\n\n";
$stmt = $pdo->query("DESCRIBE account_balances_unified");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$row['Field']} - {$row['Type']}\n";
}
?>