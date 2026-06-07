<?php
require_once 'includes/db.php';

echo "=== تفاصيل العملية رقم #000006 ===\n\n";

// Check financial_transactions
$stmt_transaction = $pdo->prepare("SELECT * FROM financial_transactions WHERE transaction_number = '000006'");
$stmt_transaction->execute();
$transaction = $stmt_transaction->fetch(PDO::FETCH_ASSOC);

if ($transaction) {
    echo "=== financial_transactions ===\n";
    foreach ($transaction as $key => $value) {
        echo "  $key: " . var_export($value, true) . "\n";
    }

    // Check journal_lines
    echo "\n=== journal_lines ===\n";
    $stmt_journal = $pdo->prepare("SELECT * FROM journal_lines WHERE transaction_id = ?");
    $stmt_journal->execute([$transaction['id']]);
    while ($line = $stmt_journal->fetch(PDO::FETCH_ASSOC)) {
        echo "  Line " . $line['id'] . ": Account " . $line['account_id'] . ", Debit: " . $line['debit'] . ", Credit: " . $line['credit'] . "\n";
    }

    // Check invoices
    echo "\n=== invoices ===\n";
    $stmt_invoices = $pdo->prepare("SELECT * FROM invoices WHERE transaction_id = ? OR transaction_number = '000006'");
    $stmt_invoices->execute([$transaction['id']]);
    while ($invoice = $stmt_invoices->fetch(PDO::FETCH_ASSOC)) {
        echo "\nInvoice #" . $invoice['id'] . ":\n";
        foreach ($invoice as $key => $value) {
            echo "  $key: " . var_export($value, true) . "\n";
        }
    }
} else {
    echo "لم يتم العثور على العملية رقم 000006 في financial_transactions!\n\n";

    // Check invoices table directly
    echo "=== فحص جدول invoices مباشرة ===\n";
    $stmt_all_invoices = $pdo->query("SELECT id, invoice_number, invoice_type, transaction_id, transaction_number, created_at FROM invoices ORDER BY id DESC LIMIT 10");
    while ($inv = $stmt_all_invoices->fetch(PDO::FETCH_ASSOC)) {
        echo "  Invoice #" . $inv['id'] . ": Type " . $inv['invoice_type'] . ", Number: " . $inv['invoice_number'] . ", Transaction: " . ($inv['transaction_number'] ?: 'N/A') . "\n";
    }
}
?>