<?php
require_once '../includes/db.php';

echo "<h2>Stored Procedure: sp_post_invoice</h2>";
$stmt = $pdo->query("SHOW CREATE PROCEDURE sp_post_invoice");
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<pre>" . htmlspecialchars($result['Create Procedure']) . "</pre>";
?>