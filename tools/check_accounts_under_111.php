<?php
require_once 'includes/db.php';

echo "<h1>All Accounts Under 111 (النقدية وما في حكمها)</h1>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Account Code</th><th>Name</th><th>Parent ID</th><th>Parent Code</th></tr>";

$id_111 = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '111'")->fetchColumn();
echo "<p>111 (النقدية وما في حكمها) ID: $id_111</p>";

$stmt = $pdo->prepare("
    SELECT coa.id, coa.account_code, coa.account_name_ar, coa.parent_id, p.account_code as parent_code 
    FROM unified_accounts coa 
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id 
    WHERE coa.parent_id = ? OR p.parent_id = ?
    ORDER BY coa.account_code
");
$stmt->execute([$id_111, $id_111]);

while ($row = $stmt->fetch()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['account_code']}</td>";
    echo "<td>{$row['account_name_ar']}</td>";
    echo "<td>{$row['parent_id']}</td>";
    echo "<td>{$row['parent_code']}</td>";
    echo "</tr>";
}
echo "</table>";
?>