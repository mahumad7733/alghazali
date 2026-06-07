<?php
require_once 'includes/db.php';

echo "=== تحقق من الفواتير ===\n";

echo "\n1. فاتورة البيع رقم 79 (SI-000003):\n";
$stmt_sale = $pdo->prepare("SELECT id, invoice_number, supplier_id, cost_amount FROM invoices WHERE id = 79");
$stmt_sale->execute();
$sale = $stmt_sale->fetch(PDO::FETCH_ASSOC);
var_dump($sale);

echo "\n2. فاتورة الشراء رقم 80 (PI-000003):\n";
$stmt_pur = $pdo->prepare("SELECT id, invoice_number, supplier_id, total_amount FROM invoices WHERE id = 80");
$stmt_pur->execute();
$pur = $stmt_pur->fetch(PDO::FETCH_ASSOC);
var_dump($pur);
?>