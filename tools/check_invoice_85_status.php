
<?php
require_once 'includes/db.php';
echo "<h2>Invoice 85 Status</h2>";
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([85]);
$invoice = $stmt->fetch();
echo "<pre>";
print_r($invoice);
echo "</pre>";

echo "<h3>Financial Transactions for Invoice 85</h3>";
$stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE reference_id = ? AND reference_type = 'invoice'");
$stmt->execute([85]);
echo "<pre>";
print_r($stmt->fetchAll());
echo "</pre>";
?>
