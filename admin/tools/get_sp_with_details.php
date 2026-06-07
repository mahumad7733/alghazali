<?php
require_once '../includes/db.php';

echo "<h2>Stored Procedures and Collation Info</h2>";
echo "<hr>";

echo "<h3>Database Collation:</h3>";
$stmt_coll = $pdo->query("SHOW VARIABLES LIKE 'character_set_database'");
$coll = $stmt_coll->fetch();
echo "<p>" . htmlspecialchars($coll['Variable_name']) . ": " . htmlspecialchars($coll['Value']) . "</p>";

$stmt_coll2 = $pdo->query("SHOW VARIABLES LIKE 'collation_database'");
$coll2 = $stmt_coll2->fetch();
echo "<p>" . htmlspecialchars($coll2['Variable_name']) . ": " . htmlspecialchars($coll2['Value']) . "</p>";

echo "<hr>";

echo "<h3>sp_post_invoice:</h3>";
try {
    $stmt = $pdo->query("SHOW CREATE PROCEDURE sp_post_invoice");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>" . htmlspecialchars($result['Create Procedure']) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";

echo "<h3>Last 10 Invoices:</h3>";
$stmt_invs = $pdo->query("SELECT id, invoice_number, invoice_category, invoice_status, customer_id, supplier_id, customer_account_id, supplier_account_id, currency_id FROM invoices ORDER BY id DESC LIMIT 10");
$invs = $stmt_invs->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>رقم الفاتورة</th><th>نوع</th><th>حالة</th><th>customer_id</th><th>supplier_id</th><th>customer_account_id</th><th>supplier_account_id</th><th>currency_id</th></tr>";
foreach ($invs as $inv) {
    echo "<tr>";
    foreach ($inv as $val) {
        echo "<td>" . htmlspecialchars($val) . "</td>";
    }
    echo "</tr>";
}
echo "</table>";
?>