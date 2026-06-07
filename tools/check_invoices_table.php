
<?php
require_once 'includes/db.php';
echo "<h2>Invoices Table Dump</h2>";
$stmt = $pdo->query("SELECT * FROM invoices");
$invoices = $stmt->fetchAll();
echo "<pre>";
print_r($invoices);
echo "</pre>";

echo "<h2>Unified Accounts Table</h2>";
$stmt = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_type = 'receivable' OR account_code LIKE '111%' OR account_code LIKE '112%' ORDER BY account_code");
echo "<pre>";
print_r($stmt->fetchAll());
echo "</pre>";
?>
