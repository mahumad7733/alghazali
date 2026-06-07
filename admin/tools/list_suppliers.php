<?php
require_once 'header.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>قائمة الموردين</h1>";
echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>اسم المورد</th><th>account_id</th></tr>";

$stmt = $pdo->query("SELECT id, supplier_name, account_id FROM suppliers");
$suppliers = $stmt->fetchAll();
foreach ($suppliers as $s) {
    echo "<tr><td>" . htmlspecialchars($s['id']) . "</td><td>" . htmlspecialchars($s['supplier_name']) . "</td><td>" . htmlspecialchars($s['account_id'] ?? 'NULL') . "</td></tr>";
}
echo "</table>";

echo "<hr><h1>قائمة الحسابات تحت 21101</h1>";
$stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$stmt_parent->execute();
$parent_id = $stmt_parent->fetchColumn();
if ($parent_id) {
    $stmt_acc = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE parent_id = ?");
    $stmt_acc->execute([$parent_id]);
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>account_code</th><th>account_name_ar</th></tr>";
    while ($acc = $stmt_acc->fetch()) {
        echo "<tr><td>" . htmlspecialchars($acc['id']) . "</td><td>" . htmlspecialchars($acc['account_code']) . "</td><td>" . htmlspecialchars($acc['account_name_ar']) . "</td></tr>";
    }
    echo "</table>";
}
?>