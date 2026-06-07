
<?php
require_once 'includes/db.php';
echo "<h2>Unified Account #5</h2>";
$stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE id = 5");
$stmt->execute();
echo "<pre>"; print_r($stmt->fetch()); echo "</pre>";

echo "<hr><h2>FT 117's journal lines</h2>";
$stmt = $pdo->prepare("SELECT jl.*, ua.account_type FROM journal_lines jl LEFT JOIN unified_accounts ua ON jl.account_id = ua.id WHERE jl.financial_transaction_id = 117");
$stmt->execute();
echo "<pre>"; print_r($stmt->fetchAll()); echo "</pre>";
