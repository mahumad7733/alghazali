
<?php
require_once 'includes/db.php';
echo "<h2>Debug Financial Accounts Query</h2>";
$sql = "SELECT id, account_name_ar as account_name, account_code, account_type 
        FROM unified_accounts 
        WHERE (account_code LIKE '101%' OR account_code LIKE '102%') 
        AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) 
        AND is_active = 1";
echo "<p>Query: $sql</p>";
$financial_accounts = $pdo->query($sql)->fetchAll();
echo "<h3>Results:</h3>";
echo "<pre>";
print_r($financial_accounts);
echo "</pre>";
?>
