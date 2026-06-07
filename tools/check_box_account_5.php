
<?php
require_once 'includes/db.php';
echo "<h2>Account 5 (11101001 - الصندوق الرئيسي) Transactions & Balance</h2>";

// Get account_balances_unified
echo "<h3>Account Balances (account_balances_unified)</h3>";
$stmt = $pdo->prepare("SELECT * FROM account_balances_unified WHERE account_id = ?");
$stmt->execute([5]);
echo "<pre>";
print_r($stmt->fetchAll());
echo "</pre>";

// Get financial_transactions for this account
echo "<h3>Financial Transactions (financial_transactions)</h3>";
$stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE account_id = ? ORDER BY id");
$stmt->execute([5]);
$transactions = $stmt->fetchAll();
echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>
        <tr>
            <th>ID</th>
            <th>Date</th>
            <th>Ref Type</th>
            <th>Ref ID</th>
            <th>Debit</th>
            <th>Credit</th>
            <th>Description</th>
        </tr>";
foreach ($transactions as $t) {
    echo "<tr>
            <td>{$t['id']}</td>
            <td>{$t['transaction_date']}</td>
            <td>{$t['reference_type']}</td>
            <td>{$t['reference_id']}</td>
            <td>{$t['debit_amount']}</td>
            <td>{$t['credit_amount']}</td>
            <td>{$t['description']}</td>
          </tr>";
}
echo "</table>";
?>
