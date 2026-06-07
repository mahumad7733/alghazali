
<?php
require_once 'includes/db.php';
$invoice_id = 85;
echo "<h2>Invoice #85</h2>";
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();
echo "<pre>";
print_r($invoice);
echo "</pre>";

echo "<hr><h2>Financial Transactions related to invoice 85</h2>";
$stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE reference_id = ? AND reference_type = 'invoice'");
$stmt->execute([$invoice_id]);
$trans = $stmt->fetchAll();
echo "<pre>";
print_r($trans);
echo "</pre>";

echo "<hr><h2>Payment Allocations for invoice 85</h2>";
$stmt = $pdo->prepare("SELECT * FROM payment_allocations WHERE invoice_id = ?");
$stmt->execute([$invoice_id]);
$allocations = $stmt->fetchAll();
echo "<pre>";
print_r($allocations);
echo "</pre>";

echo "<hr><h2>Journal Lines for invoice's financial transactions</h2>";
foreach($trans as $t) {
    $stmt = $pdo->prepare("SELECT * FROM journal_lines WHERE financial_transaction_id = ?");
    $stmt->execute([$t['id']]);
    $jl = $stmt->fetchAll();
    echo "FT #{$t['id']}: <pre>"; print_r($jl); echo "</pre>";
}
