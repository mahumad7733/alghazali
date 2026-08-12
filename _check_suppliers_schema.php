<?php
require_once __DIR__ . '/includes/db.php';

echo "<h2>Suppliers Table Schema:</h2>";
$stmt = $pdo->query("DESCRIBE suppliers");
echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
}
echo "</table>";

echo "<h2>Sample Suppliers:</h2>";
$stmt2 = $pdo->query("SELECT * FROM suppliers LIMIT 10");
echo "<table border='1'><tr><th>ID</th><th>Supplier Name</th><th>Account ID</th><th>Status</th></tr>";
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr><td>{$row['id']}</td><td>{$row['supplier_name']}</td><td>{$row['account_id']}</td><td>{$row['status']}</td></tr>";
}
echo "</table>";

echo "<h2>All Tables:</h2>";
$stmt3 = $pdo->query("SHOW TABLES");
echo "<ul>";
while ($row = $stmt3->fetch(PDO::FETCH_NUM)) {
    echo "<li>{$row[0]}</li>";
}
echo "</ul>";
?>