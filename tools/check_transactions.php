<?php
require_once 'includes/db.php';

echo "<h1>أحدث المعاملات المالية</h1>";
$stmt = $pdo->query("SELECT * FROM financial_transactions ORDER BY id DESC LIMIT 20");
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' style='border-collapse: collapse; width: 100%;'><tr><th>ID</th><th>رقم المعاملة</th><th>النوع</th><th>الحالة</th><th>التاريخ</th><th>المبلغ</th></tr>";
foreach ($transactions as $trx) {
    echo "<tr><td>" . $trx['id'] . "</td><td>" . htmlspecialchars($trx['transaction_number']) . "</td><td>" . htmlspecialchars($trx['transaction_type']) . "</td><td>" . htmlspecialchars($trx['status']) . "</td><td>" . htmlspecialchars($trx['transaction_date']) . "</td><td style='text-align: right;'>" . number_format($trx['amount'], 2) . "</td></tr>";
}
echo "</table>";
?>
