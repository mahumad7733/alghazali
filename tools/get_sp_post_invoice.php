<?php
require 'includes/db.php';

$stmt = $pdo->prepare("SHOW CREATE PROCEDURE sp_post_invoice");
$stmt->execute();
$result = $stmt->fetch();

echo "<pre>Current sp_post_invoice definition:</pre>";
echo "<pre>";
print_r($result);
echo "</pre>";
?>