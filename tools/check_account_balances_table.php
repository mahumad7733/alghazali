<?php
require_once 'includes/db.php';
echo "<h1>Structure of account_balances_unified</h1>";
$stmt = $pdo->query("DESCRIBE account_balances_unified");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' style='border-collapse: collapse; width:100%'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    foreach ($col as $val) {
        echo "<td>" . htmlspecialchars($val) . "</td>";
    }
    echo "</tr>";
}
echo "</table>";
?>
