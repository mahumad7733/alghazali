<?php
require_once 'includes/db.php';

echo "=== تفاصيل الفاتورة رقم 79 (SI-000003) ===\n\n";
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = 79");
$stmt->execute();
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if ($invoice) {
    foreach ($invoice as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }
}
?>