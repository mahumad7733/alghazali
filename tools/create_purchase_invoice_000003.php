<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

try {
    $pdo->beginTransaction();
    
    // Get sales invoice #79 (SI-000003)
    $stmt_sale = $pdo->prepare("SELECT * FROM invoices WHERE id = 79");
    $stmt_sale->execute();
    $sale_inv = $stmt_sale->fetch(PDO::FETCH_ASSOC);
    
    if (!$sale_inv) {
        throw new Exception("Sale invoice #79 not found!");
    }
    
    // Check if PI-000003 exists
    $stmt_check = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = 'PI-000003'");
    $stmt_check->execute();
    if ($stmt_check->fetch()) {
        echo "PI-000003 already exists!\n";
        exit;
    }
    
    $supplier_id = 6;
    
    // Get supplier account ID
    $stmt_sup = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
    $stmt_sup->execute([$supplier_id]);
    $supplier_account_id = $stmt_sup->fetchColumn();
    
    echo "Creating PI-000003...\n";
    echo "Supplier ID: $supplier_id\n";
    echo "Supplier Account ID: $supplier_account_id\n";
    
    // Insert purchase invoice
    $stmt_purchase = $pdo->prepare("INSERT INTO invoices (
        invoice_number, invoice_date, branch_id, invoice_category,
        source_type, source_id, supplier_id,
        currency_id, total_amount, discount, cost_amount, payment_type,
        delivery_type, account_id, supplier_account_id, amount_received, description,
        invoice_status, created_by
    ) VALUES (?, ?, ?, 'purchase', ?, ?, ?, ?, ?, 0, ?, 'credit', 'credit', ?, ?, 0, ?, 'draft', ?)");
    
    $stmt_purchase->execute([
        'PI-000003',
        $sale_inv['invoice_date'],
        $sale_inv['branch_id'],
        $sale_inv['source_type'],
        $sale_inv['source_id'],
        $supplier_id,
        $sale_inv['currency_id'],
        $sale_inv['cost_amount'],
        $sale_inv['cost_amount'],
        $supplier_account_id,
        $supplier_account_id,
        $sale_inv['description'],
        $sale_inv['created_by']
    ]);
    
    $new_pur_id = $pdo->lastInsertId();
    echo "✅ Purchase invoice created with ID: $new_pur_id\n";
    
    $pdo->commit();
    echo "\n✅ Done!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>