<?php
require_once 'includes/db.php';

echo "=== تفاصيل الفاتورة رقم 77 (SI-000006) ===\n\n";
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = 77");
$stmt->execute();
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if ($invoice) {
    foreach ($invoice as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }
    
    // Check what purchase invoice should exist
    $numeric_suffix = '000006';
    echo "\n\n=== محاولة العثور على فاتورة الشراء المرتبطة ===\n";
    $stmt_pur = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number LIKE ? OR invoice_number LIKE ?");
    $stmt_pur->execute(["PI-$numeric_suffix", "PUR-$numeric_suffix"]);
    $pur_invoices = $stmt_pur->fetchAll(PDO::FETCH_ASSOC);
    
    if ($pur_invoices) {
        foreach ($pur_invoices as $pi) {
            echo "\n  فاتورة شراء موجودة: ID {$pi['id']}, Number {$pi['invoice_number']}\n";
        }
    } else {
        echo "❌ لم يتم العثور على فاتورة شراء مرتبطة!\n";
        
        // Let's check what the current purchase invoice prefixes are
        echo "\n=== بادئات الفواتير الشرائية الحالية ===\n";
        $stmt_prefs = $pdo->query("SELECT DISTINCT SUBSTRING_INDEX(invoice_number, '-', 1) as prefix FROM invoices WHERE invoice_category = 'purchase' LIMIT 5");
        while ($p = $stmt_prefs->fetch()) {
            echo "  " . $p['prefix'] . "\n";
        }
    }
}
?>