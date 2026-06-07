<?php
require_once 'includes/db.php';

echo "=== جميع الفواتير ===\n\n";

$stmt = $pdo->query("
    SELECT i.id, i.invoice_number, i.invoice_category, i.supplier_id, 
           s.supplier_name, i.invoice_status
    FROM invoices i
    LEFT JOIN suppliers s ON i.supplier_id = s.id
    ORDER BY i.id DESC
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  ID: {$row['id']} | Number: {$row['invoice_number']} | Type: {$row['invoice_category']} | Supplier: " . ($row['supplier_name'] ?: 'N/A') . " | Status: {$row['invoice_status']}\n";
}

echo "\n=== الموردين المتاحين ===\n";
$stmt_suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE deleted_at IS NULL ORDER BY id");
while ($s = $stmt_suppliers->fetch(PDO::FETCH_ASSOC)) {
    echo "  ID {$s['id']}: {$s['supplier_name']}\n";
}
?>