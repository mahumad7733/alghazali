<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h2>آخر 10 سندات قبض:</h2>";
$stmt = $pdo->query("SELECT * FROM financial_transactions WHERE transaction_type = 'receipt' ORDER BY id DESC LIMIT 10");
$receipts = $stmt->fetchAll();

echo "<table border='1' cellpadding='10' cellspacing='0'>";
echo "<tr><th>ID</th><th>رقم السند</th><th>التاريخ</th><th>النوع</th><th>الحالة</th><th>party_account_id</th><th>cash_bank_account_id</th></tr>";
foreach ($receipts as $r) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($r['id']) . "</td>";
    echo "<td>" . htmlspecialchars($r['transaction_number']) . "</td>";
    echo "<td>" . htmlspecialchars($r['transaction_date']) . "</td>";
    echo "<td>" . htmlspecialchars($r['transaction_type']) . "</td>";
    echo "<td>" . htmlspecialchars($r['status']) . "</td>";
    echo "<td>" . htmlspecialchars($r['party_account_id']) . "</td>";
    echo "<td>" . htmlspecialchars($r['cash_bank_account_id']) . "</td>";
    echo "</tr>";
}
echo "</table>";
?>