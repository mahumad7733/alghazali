<?php
require_once '../includes/db.php';

echo "<h1>Invoices List</h1>";
$stmt = $pdo->query("SELECT id, invoice_number, invoice_category, invoice_status, source_type FROM invoices ORDER BY id DESC LIMIT 20");
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='8'>";
echo "<tr><th>ID</th><th>Number</th><th>Category</th><th>Status</th><th>Source Type</th></tr>";
foreach ($invoices as $inv) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($inv['id']) . "</td>";
    echo "<td>" . htmlspecialchars($inv['invoice_number']) . "</td>";
    echo "<td>" . htmlspecialchars($inv['invoice_category']) . "</td>";
    echo "<td>" . htmlspecialchars($inv['invoice_status']) . "</td>";
    echo "<td>" . htmlspecialchars($inv['source_type']) . "</td>";
    echo "</tr>";
}
echo "</table>";
?>