<?php
require_once '../includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $stmt = $pdo->query("SHOW CREATE PROCEDURE sp_post_invoice");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $result['Create Procedure'];
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>