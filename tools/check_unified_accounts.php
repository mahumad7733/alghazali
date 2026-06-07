
<?php
require_once 'includes/db.php';
echo "<h2>Unified Accounts</h2>";
$stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, account_type, normal_balance FROM unified_accounts ORDER BY account_code");
$stmt->execute();
echo "<table border='1'><tr><th>ID</th><th>Code</th><th>Name</th><th>Type</th><th>Normal Balance</th></tr>";
foreach ($stmt->fetchAll() as $acc) {
    echo "<tr><td>{$acc['id']}</td><td>{$acc['account_code']}</td><td>{$acc['account_name_ar']}</td><td>{$acc['account_type']}</td><td>{$acc['normal_balance']}</td></tr>";
}
echo "</table>";
?>
