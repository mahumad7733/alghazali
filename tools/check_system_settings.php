
<?php
require_once 'includes/db.php';
echo "<h2>System Settings</h2>";
$stmt = $pdo->prepare("SELECT * FROM system_settings");
$stmt->execute();
echo "<pre>";
print_r($stmt->fetchAll());
echo "</pre>";
?>
