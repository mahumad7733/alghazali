<?php
require 'includes/db.php';

$invoice_id = 109;

echo "=== Invoice 109 Journal Entry ===\n";

// Find the transaction for invoice 109
$stmt_trx = $pdo->prepare("SELECT * FROM financial_transactions WHERE reference_type = 'invoice' AND reference_id = ? AND status = 'posted'");
$stmt_trx->execute([$invoice_id]);
$trx = $stmt_trx->fetch(PDO::FETCH_ASSOC);

if (!$trx) {
    echo "Error: Could not find transaction\n";
    exit;
}

echo "Transaction Number: {$trx['transaction_number']}\n";
echo "Date: {$trx['transaction_date']}\n";
echo "Amount: {$trx['amount']}\n\n";

// Get journal lines
$stmt_jrl = $pdo->prepare("
    SELECT jl.*, ua.account_code, ua.account_name_ar
    FROM journal_lines jl
    JOIN unified_accounts ua ON jl.account_id = ua.id
    WHERE jl.financial_transaction_id = ?
");
$stmt_jrl->execute([$trx['id']]);
$jrls = $stmt_jrl->fetchAll(PDO::FETCH_ASSOC);

foreach ($jrls as $jrl) {
    echo "- {$jrl['account_name_ar']} ({$jrl['account_code']}): Debit {$jrl['debit']}, Credit {$jrl['credit']}\n";
}
?>
