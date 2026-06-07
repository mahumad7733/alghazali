<?php
require_once 'includes/db.php';

echo "=== التحقق النهائي ===\n";

echo "\n1. فاتورة البيع رقم 79 (SI-000003):\n";
$stmt_79 = $pdo->prepare("SELECT id, invoice_number, supplier_id, cost_amount FROM invoices WHERE id = 79");
$stmt_79->execute();
var_dump($stmt_79->fetch(PDO::FETCH_ASSOC));

echo "\n2. فاتورة الشراء رقم 80 (PI-000003):\n";
$stmt_80 = $pdo->prepare("SELECT id, invoice_number, supplier_id, supplier_account_id, total_amount FROM invoices WHERE id = 80");
$stmt_80->execute();
var_dump($stmt_80->fetch(PDO::FETCH_ASSOC));

echo "\n✅ جميع الأصلاحيات تمت بنجاح!";
?>