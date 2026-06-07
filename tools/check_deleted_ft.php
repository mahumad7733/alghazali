
<?php
require_once 'includes/db.php';
echo "<h2>All audit logs for 'financial_transactions'</h2>";
$stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE table_name = 'financial_transactions' ORDER BY created_at DESC LIMIT 20");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='8'><tr><th>ID</th><th>Table</th><th>Rec ID</th><th>Action</th><th>Old Data</th><th>Date</th></tr>";
foreach ($logs as $l) {
    echo "<tr><td>{$l['id']}</td><td>{$l['table_name']}</td><td>{$l['record_id']}</td><td>{$l['action']}</td><td>".htmlspecialchars(substr($l['old_values'], 0, 300))."</td><td>{$l['created_at']}</td></tr>";
}
echo "</table>";
?>
