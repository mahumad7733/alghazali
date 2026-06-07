<?php
require_once 'includes/db.php';

echo "=== بنية جدول unified_accounts (كاملة) ===\n\n";
$stmt = $pdo->query("DESCRIBE unified_accounts");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$row['Field']} - {$row['Type']}\n";
}
?>