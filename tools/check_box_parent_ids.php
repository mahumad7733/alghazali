<?php
require_once 'includes/db.php';

echo "<h1>Checking Box Account Parent IDs</h1>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Account Code</th><th>Name</th><th>Current Parent ID</th><th>Current Parent Code</th></tr>";

$stmt = $pdo->query("
    SELECT coa.id, coa.account_code, coa.account_name_ar, coa.parent_id, p.account_code as parent_code, p.account_name_ar as parent_name
    FROM unified_accounts coa 
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id 
    WHERE coa.account_code LIKE '101%' OR coa.account_code LIKE '11101%'
    ORDER BY coa.account_code
");

while ($row = $stmt->fetch()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['account_code']}</td>";
    echo "<td>{$row['account_name_ar']}</td>";
    echo "<td>{$row['parent_id']}</td>";
    echo "<td>{$row['parent_code']} - {$row['parent_name']}</td>";
    echo "</tr>";
}
echo "</table>";
?>