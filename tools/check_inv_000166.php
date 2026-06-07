<?php
require_once 'includes/db.php';

echo "<h2>Checking invoice INV-000166</h2>";

// Get invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ?");
$stmt->execute(["INV-000166"]);
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

    // Get customer
    if (!empty($invoice['customer_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$invoice['customer_id']]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<h3>Customer:</h3>";
        echo "<pre>";
        print_r($cust);
        echo "</pre>";
    }
}
?>