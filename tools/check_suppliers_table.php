<?php
require_once 'includes/db.php';

echo "=== بنية جدول suppliers ===\n\n";
$stmt_desc = $pdo->query("DESCRIBE suppliers");
while ($row = $stmt_desc->fetch()) {
    echo "  {$row['Field']} - {$row['Type']}\n";
}

echo "\n=== بيانات جدول suppliers ===\n\n";
$stmt_data = $pdo->query("SELECT * FROM suppliers");
while ($row = $stmt_data->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']}\n";
    foreach ($row as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }
    echo "\n";
}
?>