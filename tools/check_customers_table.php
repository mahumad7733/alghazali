<?php
require_once 'includes/db.php';

echo "=== بنية جدول customers ===\n";
$stmt = $pdo->query("DESCRIBE customers");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$row['Field']} - {$row['Type']}\n";
}

echo "\n=== بيانات جدول customers ===\n";
$stmt_data = $pdo->query("SELECT * FROM customers");
while ($row = $stmt_data->fetch(PDO::FETCH_ASSOC)) {
    echo "\n  ID: {$row['id']}\n";
    foreach ($row as $k => $v) {
        echo "    $k: " . var_export($v, true) . "\n";
    }
}
?>