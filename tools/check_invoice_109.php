<?php
require 'includes/db.php';
require 'includes/functions.php';

$invoice_id = 109;

// Get main invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$main_inv = $stmt->fetch();

echo "<pre>Main Invoice: ";
print_r($main_inv);
echo "</pre>";

// Get sale/purchase invoices
$inv_config = getServiceInvoiceConfig($main_inv['source_type'], []);
$s_pref = $inv_config['sales_prefix'] ?? 'INV';
$p_pref = $inv_config['purchase_prefix'] ?? 'PUR';

$numeric_suffix = preg_replace('/^[A-Z-]+/', '', $main_inv['invoice_number']);

// Get linked invoices
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE source_type = ? AND source_id = ? OR invoice_number = ? OR invoice_number = ?");
$stmt->execute([
    $main_inv['source_type'], 
    $main_inv['source_id'], 
    $s_pref . $numeric_suffix, 
    $p_pref . $numeric_suffix
]);
$linked_invoices = $stmt->fetchAll();

echo "<pre>Linked Invoices: ";
print_r($linked_invoices);
echo "</pre>";

// Get all journal entries related to invoice 109
$stmt = $pdo->prepare("
    SELECT
        ft.id as transaction_id,
        ft.transaction_number,
        ft.transaction_date,
        ft.reference_type,
        ft.reference_id,
        ft.amount as total_amount,
        ft.status,
        coa.account_code,
        coa.account_name_ar,
        jl.debit,
        jl.credit,
        jl.currency_id,
        curr.currency_symbol,
        coa.account_type
    FROM financial_transactions ft
    JOIN journal_lines jl ON ft.id = jl.financial_transaction_id
    JOIN unified_accounts coa ON jl.account_id = coa.id
    LEFT JOIN currencies curr ON jl.currency_id = curr.id
    WHERE ft.reference_type = 'invoice' AND ft.reference_id = ?
    ORDER BY ft.transaction_date DESC, coa.account_code
");
$stmt->execute([109]);
$journal_details_109 = $stmt->fetchAll();

echo "<pre>Journal Details for Invoice 109: ";
print_r($journal_details_109);
echo "</pre>";

// Also check for account 11201001
$stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE account_code = '11201001'");
$stmt->execute();
$acc = $stmt->fetch();

echo "<pre>Account 11201001: ";
print_r($acc);
echo "</pre>";
?>