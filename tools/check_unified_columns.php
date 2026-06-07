<?php
require_once 'includes/db.php';
echo "=== unified_accounts columns ===\n";
$stmt = $pdo->query("DESCRIBE unified_accounts");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
