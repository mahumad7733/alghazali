
<?php
require_once 'includes/db.php';
echo "<h2>Financial Accounts</h2>";
$financial_accounts = $pdo->query("SELECT id, account_name_ar as account_name, account_code, account_type FROM unified_accounts WHERE (account_code LIKE '101%' OR account_code LIKE '102%') AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) AND is_active = 1")->fetchAll();
echo "<table border='1'><tr><th>ID</th><th>Code</th><th>Name</th><th>Type</th></tr>";
foreach ($financial_accounts as $acc) {
    echo "<tr><td>{$acc['id']}</td><td>{$acc['account_code']}</td><td>{$acc['account_name']}</td><td>{$acc['account_type']}</td></tr>";
}
echo "</table>";
?>
