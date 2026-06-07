
<?php
require_once 'includes/db.php';
echo "<h2>All Unified Accounts</h2>";
$stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, account_type, parent_id FROM unified_accounts ORDER BY account_code");
$stmt->execute();
echo "<table border='1' style='border-collapse: collapse;'><tr><th>ID</th><th>Code</th><th>Name</th><th>Type</th><th>Parent ID</th></tr>";
foreach ($stmt->fetchAll() as $acc) {
    echo "<tr><td>{$acc['id']}</td><td>{$acc['account_code']}</td><td>{$acc['account_name_ar']}</td><td>{$acc['account_type']}</td><td>{$acc['parent_id']}</td></tr>";
}
echo "</table>";
?>
