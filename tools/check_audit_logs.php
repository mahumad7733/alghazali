
<?php
require_once 'includes/db.php';
echo "<h2>Last 50 Audit Logs</h2>";
$stmt = $pdo->prepare("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$logs = $stmt->fetchAll();
echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>
        <tr>
            <th>ID</th>
            <th>Table</th>
            <th>Record ID</th>
            <th>Action</th>
            <th>Old Data</th>
            <th>Date</th>
        </tr>";
foreach ($logs as $log) {
    echo "<tr>
            <td>{$log['id']}</td>
            <td>{$log['table_name']}</td>
            <td>{$log['record_id']}</td>
            <td>{$log['action']}</td>
            <td>" . substr($log['old_data'], 0, 200) . "</td>
            <td>{$log['created_at']}</td>
          </tr>";
}
echo "</table>";
?>
