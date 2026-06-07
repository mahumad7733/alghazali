<?php
require_once 'includes/db.php';

$invoice_numbers = ["SI-000001", "PI-000001", "SI-000002", "PI-000002"];

foreach ($invoice_numbers as $inv_num) {
    echo "<h2>Invoice $inv_num</h2>";
    // Get invoice
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ?");
    $stmt->execute([$inv_num]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h3>Invoice data:</h3>";
    echo "<pre>";
    print_r($invoice);
    echo "</pre>";

    if ($invoice) {
        // Get financial transactions for this invoice
        $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE reference_id = ? AND reference_type = 'invoice'");
        $stmt->execute([$invoice['id']]);
        $ft = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>Financial transactions:</h3>";
        echo "<pre>";
        print_r($ft);
        echo "</pre>";

        // Get journal lines
        if (!empty($ft)) {
            foreach ($ft as $t) {
                $stmt = $pdo->prepare("SELECT jl.*, ua.account_code, ua.account_name_ar FROM journal_lines jl JOIN unified_accounts ua ON jl.account_id = ua.id WHERE jl.financial_transaction_id = ?");
                $stmt->execute([$t['id']]);
                $jl = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo "<h3>Journal lines for FT " . $t['transaction_number'] . ":</h3>";
                echo "<pre>";
                print_r($jl);
                echo "</pre>";
            }
        }
    }
    echo "<hr>";
}
?>