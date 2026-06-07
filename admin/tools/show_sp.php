<?php
require_once '../includes/db.php';

echo "<h2>Stored Procedure: sp_post_invoice</h2>";
try {
    $stmt = $pdo->query("SHOW CREATE PROCEDURE sp_post_invoice");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>" . htmlspecialchars($result['Create Procedure']) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr><h2>Last 5 Invoices:</h2>";
try {
    $stmt_invs = $pdo->query("SELECT id, invoice_number, invoice_category, invoice_status, customer_id, supplier_id, account_id, currency_id FROM invoices ORDER BY id DESC LIMIT 5");
    $invs = $stmt_invs->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>رقم الفاتورة</th><th>نوع</th><th>حالة</th><th>customer_id</th><th>supplier_id</th><th>account_id</th><th>currency_id</th></tr>";
    foreach ($invs as $inv) {
        echo "<tr>";
        foreach ($inv as $val) {
            echo "<td>" . htmlspecialchars($val) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error getting invoices: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>