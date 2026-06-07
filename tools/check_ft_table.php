
<?php
require_once 'includes/db.php';
echo "<h2>financial_transactions columns</h2>";
$stmt = $pdo->query("DESCRIBE financial_transactions");
$cols = $stmt->fetchAll();
echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
foreach ($cols as $c) {
    echo "<tr><td>{$c['Field']}</td><td>{$c['Type']}</td></tr>";
}
echo "</table>";

echo "<h2>Sample of financial_transactions with status='posted'</h2>";
$stmt = $pdo->query("SELECT id, transaction_number, transaction_type, status FROM financial_transactions LIMIT 10");
echo "<table border='1'><tr><th>id</th><th>number</th><th>type</th><th>status</th></tr>";
foreach ($stmt->fetchAll() as $r) {
    echo "<tr><td>{$r['id']}</td><td>{$r['transaction_number']}</td><td>{$r['transaction_type']}</td><td>{$r['status']}</td></tr>";
}
echo "</table>";

echo "<h2>Check if there's a deleted_at column</h2>";
try {
    $stmt = $pdo->query("SELECT id, deleted_at FROM financial_transactions LIMIT 5");
    echo "<table border='1'><tr><th>id</th><th>deleted_at</th></tr>";
    foreach ($stmt->fetchAll() as $r) {
        echo "<tr><td>{$r['id']}</td><td>" . ($r['deleted_at'] ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "No deleted_at column: " . $e->getMessage();
}
?>
