<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
session_start();
$_SESSION['admin_id'] = 1;
require_once __DIR__ . '/includes/db.php';

echo "=== Schema: unified_accounts ===\n\n";
$cols = $pdo->query("SHOW COLUMNS FROM unified_accounts")->fetchAll(PDO::FETCH_ASSOC);
$names = [];
foreach ($cols as $c) {
    $names[] = $c['Field'];
    echo "  - {$c['Field']} ({$c['Type']})  Default: {$c['Default']}\n";
}

echo "\n=== Schema: customers ===\n\n";
$cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_ASSOC);
$cnames = [];
foreach ($cols as $c) {
    $cnames[] = $c['Field'];
    echo "  - {$c['Field']} ({$c['Type']})\n";
}

echo "\n=== Sample unified_accounts (first 20 rows): ===\n";
$selectCols = array_slice($names, 0, 8);
$sql = "SELECT " . implode(", ", $selectCols) . " FROM unified_accounts ORDER BY id ASC LIMIT 20";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  ";
    foreach ($r as $k => $v) {
        echo "$k=$v | ";
    }
    echo "\n";
}
