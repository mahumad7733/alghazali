<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

$settings = getSettings($pdo);

echo "=== getServiceInvoiceConfig لـ 'الزيارة العائلية' ===\n";
$config = getServiceInvoiceConfig('الزيارة العائلية', $settings);
var_dump($config);

echo "\n\n=== الفواتير الحالية ===\n";
$stmt = $pdo->query("SELECT id, invoice_number, invoice_category, source_type FROM invoices ORDER BY id DESC LIMIT 10");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  ID {$row['id']}: {$row['invoice_number']}, {$row['invoice_category']}, {$row['source_type']}\n";
}
?>