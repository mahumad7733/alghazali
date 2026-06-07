
<?php
require_once 'includes/db.php';
echo "<h2>Looking for JRN-26-00034, JRN-26-00035, JRN-26-00036</h2>";
$stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE transaction_number IN ('JRN-26-00034','JRN-26-00035','JRN-26-00036')");
$stmt->execute();
$fts = $stmt->fetchAll();
echo "<pre>"; print_r($fts); echo "</pre>";

echo "<h2>Also check journal_lines for account_id=5</h2>";
$stmt = $pdo->prepare("SELECT * FROM journal_lines WHERE account_id = 5");
$stmt->execute();
$jls = $stmt->fetchAll();
echo "<table border='1' cellpadding='4'><tr><th>id</th><th>ft id</th><th>debit</th><th>credit</th><th>account id</th></tr>";
foreach ($jls as $jl) {
    echo "<tr><td>{$jl['id']}</td><td>{$jl['financial_transaction_id']}</td><td>{$jl['debit']}</td><td>{$jl['credit']}</td><td>{$jl['account_id']}</td></tr>";
}
echo "</table>";
?>
