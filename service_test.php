<?php
require_once 'includes/db.php';

// Fetch all services
$services = $pdo->query("SELECT * FROM services")->fetchAll();

echo "<h1>Current service accounts</h1>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Revenue ID</th><th>Cost ID</th><th>Profit ID</th></tr>";
foreach ($services as $s) {
    echo "<tr>";
    echo "<td>{$s['id']}</td>";
    echo "<td>{$s['service_name']}</td>";
    echo "<td>" . ($s['revenue_account_id'] ?? 'NULL') . "</td>";
    echo "<td>" . ($s['cost_account_id'] ?? 'NULL') . "</td>";
    echo "<td>" . ($s['profit_account_id'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<hr><h1>POST data</h1>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";

    $pdo->beginTransaction();
    $db_services = $pdo->query("SELECT id FROM services")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($db_services as $service_id) {
        $revenue_account_id = !empty($_POST["service_{$service_id}_revenue"]) ? (int)$_POST["service_{$service_id}_revenue"] : null;
        $cost_account_id = !empty($_POST["service_{$service_id}_cost"]) ? (int)$_POST["service_{$service_id}_cost"] : null;
        $profit_account_id = !empty($_POST["service_{$service_id}_profit"]) ? (int)$_POST["service_{$service_id}_profit"] : null;

        echo "Updating service $service_id: revenue = $revenue_account_id, cost = $cost_account_id, profit = $profit_account_id<br>";

        $stmt = $pdo->prepare("UPDATE services SET revenue_account_id = ?, cost_account_id = ?, profit_account_id = ? WHERE id = ?");
        $stmt->execute([$revenue_account_id, $cost_account_id, $profit_account_id, $service_id]);

        echo "  Rows affected: " . $stmt->rowCount() . "<br>";
    }
    $pdo->commit();

    echo "<p><b>Saved successfully!</b></p>";
}

$revenue_accounts = $pdo->query("
    SELECT id, account_code, account_name_ar as account_name 
    FROM unified_accounts 
    WHERE id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) 
    AND account_type IN ('income', 'revenue', 'إيراد')
    AND account_status = 'active'
    ORDER BY account_code ASC
")->fetchAll();
$cost_accounts = $pdo->query("
    SELECT id, account_code, account_name_ar as account_name 
    FROM unified_accounts 
    WHERE id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) 
    AND account_type = 'expense'
    AND account_status = 'active'
    ORDER BY account_code ASC
")->fetchAll();
$profit_accounts = $pdo->query("
    SELECT id, account_code, account_name_ar as account_name 
    FROM unified_accounts 
    WHERE id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) 
    AND (account_type = 'equity' OR account_name_ar LIKE '%أرباح%')
    AND account_status = 'active'
    ORDER BY account_code ASC
")->fetchAll();

?>

<hr><h1>Test form</h1>
<form method="POST">
<table border="1" cellpadding="5">
    <tr>
        <th>Service</th>
        <th>Revenue account</th>
        <th>Cost account</th>
        <th>Profit account</th>
    </tr>
    <?php foreach ($services as $s): ?>
    <tr>
        <td><?= htmlspecialchars($s['service_name']); ?></td>
        <td>
            <select name="service_<?= $s['id'] ?>_revenue">
                <option value="">---</option>
                <?php foreach ($revenue_accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= (($s['revenue_account_id'] ?? '') == $acc['id']) ? 'selected' : '' ?>>
                        <?= $acc['account_code'] . ' - ' . htmlspecialchars($acc['account_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="service_<?= $s['id'] ?>_cost">
                <option value="">---</option>
                <?php foreach ($cost_accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= (($s['cost_account_id'] ?? '') == $acc['id']) ? 'selected' : '' ?>>
                        <?= $acc['account_code'] . ' - ' . htmlspecialchars($acc['account_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="service_<?= $s['id'] ?>_profit">
                <option value="">---</option>
                <?php foreach ($profit_accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= (($s['profit_account_id'] ?? '') == $acc['id']) ? 'selected' : '' ?>>
                        <?= $acc['account_code'] . ' - ' . htmlspecialchars($acc['account_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<button type="submit">Test save</button>
</form>

