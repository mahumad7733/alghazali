<?php
require_once 'includes/db.php';

echo "<h2>All Invoices</h2>";
$stmt = $pdo->query("SELECT * FROM invoices ORDER BY id DESC LIMIT 50");
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Invoice Number</th><th>Category</th><th>Customer ID</th><th>Supplier ID</th><th>Account ID</th><th>Customer Account ID</th><th>Total Amount</th><th>Invoice Date</th></tr>";
foreach ($invoices as $i) {
    echo "<tr><td>{$i['id']}</td><td>{$i['invoice_number']}</td><td>{$i['invoice_category']}</td><td>{$i['customer_id']}</td><td>{$i['supplier_id']}</td><td>{$i['account_id']}</td><td>{$i['customer_account_id']}</td><td>{$i['total_amount']}</td><td>{$i['invoice_date']}</td></tr>";
}
echo "</table>";
?>