<?php
require_once 'includes/db.php';

echo "<h1>Current Customer Accounts</h1>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Account Code</th><th>Name (AR)</th><th>Parent ID</th></tr>";

// Look for accounts that are linked to customers, or have "عميل" or start with 10103!
$stmt = $pdo->query("
    SELECT coa.id, coa.account_code, coa.account_name_ar, coa.parent_id, c.id as customer_id 
    FROM unified_accounts coa 
    LEFT JOIN customers c ON coa.id = c.account_id 
    WHERE coa.account_code LIKE '10103%' OR coa.account_name_ar LIKE '%عميل%'
    ORDER BY coa.account_code
");

while ($row = $stmt->fetch()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['account_code']}</td>";
    echo "<td>{$row['account_name_ar']}</td>";
    echo "<td>{$row['parent_id']}</td>";
    echo "</tr>";
}
echo "</table>";
?>