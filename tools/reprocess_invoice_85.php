
<?php
require_once 'includes/db.php';

echo "<h2>Reprocessing invoice 85</h2>";
$invoice_id = 85;

// Step 1: Unpost invoice
echo "<h3>Step 1: Unposting invoice...</h3>";
// Get the financial transaction id for the invoice
$stmt = $pdo->prepare("SELECT id FROM financial_transactions WHERE reference_id = ? AND reference_type = 'invoice' AND transaction_type = 'invoice'");
$stmt->execute([$invoice_id]);
$ft_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!empty($ft_ids)) {
    // Delete journal lines and then financial transaction
    foreach ($ft_ids as $ft_id) {
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ft_id]);
        $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$ft_id]);
        echo "<p>Deleted financial transaction $ft_id and its journal lines</p>";
    }
}
// Update invoice status to draft
$pdo->prepare("UPDATE invoices SET invoice_status = 'draft' WHERE id = ?")->execute([$invoice_id]);
echo "<p>Invoice status set to draft</p>";

// Step 2: Re-post the invoice
echo "<h3>Step 2: Re-posting invoice...</h3>";
$stmt = $pdo->prepare("CALL sp_post_invoice(?, ?)");
$stmt->execute([$invoice_id, 2]); // user id 2 is محمد الغزالي
echo "<p>✅ Invoice re-posted successfully!</p>";

echo "<h2>Done! Now go check invoice details</h2>";
?>
