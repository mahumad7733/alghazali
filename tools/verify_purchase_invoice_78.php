<?php
require_once 'includes/db.php';

echo "=== تفاصيل الفاتورة الشرائية الجديدة ID 78 ===\n";
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = 78");
$stmt->execute();
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if ($invoice) {
    foreach ($invoice as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }
}

echo "\n\n=== الآن تحقق من صفحة عرض تفاصيل العملية #000006 ===";
echo "\nURL: http://localhost:8000/ghazali/admin/invoice_details.php?id=77";
?>