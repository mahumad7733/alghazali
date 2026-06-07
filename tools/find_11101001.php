<?php
require_once 'includes/db.php';

echo "<h1>Searching for Account 11101001</h1>";

$stmt = $pdo->prepare("
    SELECT coa.id, coa.account_code, coa.account_name_ar, coa.parent_id, p.account_code as parent_code, p.account_name_ar as parent_name, pp.account_code as grandparent_code, pp.account_name_ar as grandparent_name
    FROM unified_accounts coa 
    LEFT JOIN unified_accounts p ON coa.parent_id = p.id 
    LEFT JOIN unified_accounts pp ON p.parent_id = pp.id 
    WHERE coa.account_code = '11101001'
");
$stmt->execute();
$account = $stmt->fetch();

if ($account) {
    echo "<h2>Found Account!</h2>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    echo "<tr><td>ID</td><td>{$account['id']}</td></tr>";
    echo "<tr><td>Account Code</td><td>{$account['account_code']}</td></tr>";
    echo "<tr><td>Account Name</td><td>{$account['account_name_ar']}</td></tr>";
    echo "<tr><td>Parent ID</td><td>{$account['parent_id']}</td></tr>";
    echo "<tr><td>Parent Code/Name</td><td>{$account['parent_code']} - {$account['parent_name']}</td></tr>";
    echo "<tr><td>Grandparent Code/Name</td><td>{$account['grandparent_code']} - {$account['grandparent_name']}</td></tr>";
    echo "</table>";
    
    // Let's also check if there are any accounts with similar codes (like 101001, etc.)!
    echo "<h2>Checking for similar box accounts (starting with 101):</h2>";
    $stmt2 = $pdo->query("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code LIKE '101%' ORDER BY account_code");
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Parent ID</th></tr>";
    while ($row2 = $stmt2->fetch()) {
        echo "<tr>";
        echo "<td>{$row2['id']}</td>";
        echo "<td>{$row2['account_code']}</td>";
        echo "<td>{$row2['account_name_ar']}</td>";
        echo "<td>{$row2['parent_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} else {
    echo "<p>Account 11101001 not found!</p>";
    
    // Let's check all box accounts!
    echo "<h2>All box accounts (starting with 101 or 11101):</h2>";
    $stmt2 = $pdo->query("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code LIKE '101%' OR account_code LIKE '11101%' ORDER BY account_code");
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Parent ID</th></tr>";
    while ($row2 = $stmt2->fetch()) {
        echo "<tr>";
        echo "<td>{$row2['id']}</td>";
        echo "<td>{$row2['account_code']}</td>";
        echo "<td>{$row2['account_name_ar']}</td>";
        echo "<td>{$row2['parent_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>