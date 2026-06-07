<?php
require_once 'includes/db.php';

// Test with sample values
// Replace with your actual values you're using in payments.php!
$payer_id = 75; // Replace with actual account_id or entity_id!
$currency_id = 1; // Replace with actual currency id!
$type = 'purchase'; // For supplier, 'sales' for customer!
$type_payer = 'supplier'; // Or 'customer', 'agent', etc!
$voucher_id = 0;

echo "<h3>Debug get_unpaid_invoices</h3>";
echo "<p>payer_id: $payer_id, currency_id: $currency_id, type: $type, type_payer: $type_payer</p>";

// Whitelist validation
$allowed_tables = [
    'customer' => 'customers',
    'agent'    => 'agents',
    'supplier' => 'suppliers',
    'branch'   => 'branches',
    'employee' => 'employees'
];
$allowed_id_columns = [
    'customer' => 'customer_id',
    'agent'    => 'agent_id',
    'supplier' => 'supplier_id',
    'branch'   => 'branch_entity_id',
    'employee' => 'employee_id'
];
if (!isset($allowed_tables[$type_payer]) || !isset($allowed_id_columns[$type_payer])) {
    die("Invalid payer type");
}
$table = $allowed_tables[$type_payer];
$id_column = $allowed_id_columns[$type_payer];

$stmt_entity = $pdo->prepare("SELECT id, account_id FROM $table WHERE (id = ? OR account_id = ?) AND status = 'active' AND deleted_at IS NULL LIMIT 1");
$stmt_entity->execute([$payer_id, $payer_id]);
$entity_data = $stmt_entity->fetch();
$entity_id = null; $account_id = $payer_id;
if ($entity_data) {
    $entity_id = $entity_data['id'];
    $account_id = $entity_data['account_id'];
}
echo "<p>entity_id: " . var_export($entity_id, true) . ", account_id: $account_id</p>";

$account_id_column = '';
if ($type_payer === 'customer') {
    $account_id_column = 'customer_account_id';
} elseif ($type_payer === 'supplier') {
    $account_id_column = 'supplier_account_id';
}

echo "<p>account_id_column: $account_id_column</p>";

$sql = "
    SELECT
        i.id,
        i.invoice_number,
        i.invoice_date,
        i.net_amount,
        c.currency_name,
        c.currency_symbol,
        i.currency_id,
        i.supplier_id,
        i.supplier_account_id,
        i.account_id,
        COALESCE(received.total_received, 0) as total_received,
        (i.net_amount - COALESCE(received.total_received, 0)) as remaining_without_alloc
    FROM invoices i
    JOIN currencies c ON i.currency_id = c.id
    LEFT JOIN (
        SELECT
            pa2.invoice_id,
            SUM(pa2.allocated_amount) as total_received
        FROM payment_allocations pa2
        JOIN financial_transactions ft2 ON pa2.financial_transaction_id = ft2.id
        WHERE ft2.status = 'posted'
        GROUP BY pa2.invoice_id
    ) received ON i.id = received.invoice_id
    WHERE (i.account_id = ? " . 
        ($entity_id ? "OR i.$id_column = ?" : "") . 
        ($account_id_column ? "OR i.$account_id_column = ?" : "") . 
    ")
    AND i.currency_id = ?
    AND i.invoice_category = ?
    AND i.invoice_status = 'posted'
    AND (i.net_amount - COALESCE(received.total_received, 0)) > 0
    GROUP BY i.id
    ORDER BY i.invoice_date ASC
";

echo "<h4>SQL Query:</h4><pre>".htmlspecialchars($sql)."</pre>";

$stmt = $pdo->prepare($sql);
$execute_params = [
    $account_id, // ? - for account_id
];
if ($entity_id) {
    $execute_params[] = $entity_id;
}
if ($account_id_column) {
    $execute_params[] = $account_id;
}
$execute_params[] = $currency_id;
$execute_params[] = $type;

echo "<h4>Execute Params:</h4><pre>";
print_r($execute_params);
echo "</pre>";

$stmt->execute($execute_params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h4>Found Invoices:</h4>";
if (count($invoices) >0) {
    echo "<table border='1'><tr><th>id</th><th>invoice_number</th><th>net_amount</th><th>remaining</th><th>supplier_account_id</th><th>account_id</th><th>supplier_id</th></tr>";
    foreach ($invoices as $inv) {
        echo "<tr><td>{$inv['id']}</td><td>{$inv['invoice_number']}</td><td>{$inv['net_amount']}</td><td>{$inv['remaining_without_alloc']}</td><td>{$inv['supplier_account_id']}</td><td>{$inv['account_id']}</td><td>{$inv['supplier_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No invoices found!</p>";
}

echo "<h4>All posted purchase invoices in currency id $currency_id:</h4>";
$all_invoices = $pdo->prepare("SELECT * FROM invoices WHERE invoice_status='posted' AND invoice_category='purchase' AND currency_id=?");
$all_invoices->execute([$currency_id]);
$all_invoices = $all_invoices->fetchAll();
echo "<pre>";
print_r($all_invoices);
echo "</pre>";
?>