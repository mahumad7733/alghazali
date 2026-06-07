<?php
require_once 'includes/db.php';

echo "=== تفاصيل الفاتورة رقم 73 (SI-000003) ===\n";
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = 73");
$stmt->execute();
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if ($invoice) {
    foreach ($invoice as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }
    
    // Check for linked purchase invoice
    echo "\n\n=== التحقق من وجود فاتورة شراء مرتبطة ===\n";
    $numeric_suffix = '000003';
    $stmt_pur = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number LIKE ? OR invoice_number LIKE ?");
    $stmt_pur->execute(["PI-$numeric_suffix", "PUR-$numeric_suffix"]);
    $pur_invoices = $stmt_pur->fetchAll(PDO::FETCH_ASSOC);
    
    if ($pur_invoices) {
        foreach ($pur_invoices as $pi) {
            echo "\n  فاتورة شراء موجودة: ID {$pi['id']}, Number {$pi['invoice_number']}\n";
            foreach ($pi as $key => $value) {
                echo "    $key: " . var_export($value, true) . "\n";
            }
        }
    } else {
        echo "❌ لم يتم العثور على فاتورة شراء مرتبطة!\n";
    }
}
?>