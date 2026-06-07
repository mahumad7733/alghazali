<?php
require_once '../includes/db.php';

$stmt = $pdo->query("SHOW CREATE PROCEDURE sp_post_receipt_voucher");
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h2>sp_post_receipt_voucher:</h2>";
echo "<pre>" . htmlspecialchars($result['Create Procedure']) . "</pre>";
?>