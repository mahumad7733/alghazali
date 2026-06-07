
<?php
require_once 'includes/db.php';
echo "<h2>All Invoices</h2>";
$stmt = $pdo->prepare("SELECT * FROM invoices ORDER BY id DESC");
$stmt->execute();
$invoices = $stmt->fetchAll();
echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>
        <tr>
            <th>ID</th>
            <th>Invoice Number</th>
            <th>Date</th>
            <th>Category</th>
            <th>Total</th>
            <th>Amount Received</th>
            <th>Status</th>
        </tr>";
foreach ($invoices as $inv) {
    echo "<tr>
            <td>{$inv['id']}</td>
            <td>{$inv['invoice_number']}</td>
            <td>{$inv['invoice_date']}</td>
            <td>{$inv['invoice_category']}</td>
            <td>{$inv['total_amount']}</td>
            <td>{$inv['amount_received']}</td>
            <td>{$inv['invoice_status']}</td>
          </tr>";
}
echo "</table>";
?>
