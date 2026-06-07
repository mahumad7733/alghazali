<?php
require 'includes/db.php';

$invoice_id = 109;

try {
    $pdo->beginTransaction();

    // Step 1: Get the transaction ID and account IDs for cost and profit
    $stmt_trx = $pdo->prepare("SELECT * FROM financial_transactions WHERE reference_type = 'invoice' AND reference_id = ? AND status = 'posted'");
    $stmt_trx->execute([$invoice_id]);
    $trx = $stmt_trx->fetch(PDO::FETCH_ASSOC);

    if (!$trx) {
        throw new Exception("Could not find transaction for invoice $invoice_id");
    }
    $trx_id = $trx['id'];

    // Step 2: Get account IDs for cost (5010105) and profit (4010205)
    $stmt_cost_acc = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
    $stmt_cost_acc->execute(['5010105']);
    $cost_acc_id = $stmt_cost_acc->fetchColumn();

    $stmt_profit_acc = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
    $stmt_profit_acc->execute(['4010205']);
    $profit_acc_id = $stmt_profit_acc->fetchColumn();

    // Step 3: Delete only the cost and profit lines
    if ($cost_acc_id) {
        $stmt_delete_cost = $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ? AND account_id = ?");
        $stmt_delete_cost->execute([$trx_id, $cost_acc_id]);
    }

    if ($profit_acc_id) {
        $stmt_delete_profit = $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ? AND account_id = ?");
        $stmt_delete_profit->execute([$trx_id, $profit_acc_id]);
    }

    $pdo->commit();
    echo "Successfully removed cost and profit lines from invoice 109's journal entry!\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
