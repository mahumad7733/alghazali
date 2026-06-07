<?php
require_once 'includes/db.php';

try {
    $pdo->beginTransaction();
    
    // Get current supplier #6's account ID
    $stmt_supplier = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = 6");
    $stmt_supplier->execute();
    $supplier_account_id = $stmt_supplier->fetchColumn();
    
    if (!$supplier_account_id) {
        throw new Exception("Supplier #6 has no account ID!");
    }
    
    echo "Supplier #6's current account ID: $supplier_account_id\n";
    
    // Update invoices where supplier_account_id = 273 or supplier_id = 6
    $stmt_update = $pdo->prepare("
        UPDATE invoices 
        SET supplier_account_id = ?,
            account_id = ?,
            updated_at = NOW()
        WHERE supplier_id = 6 AND supplier_account_id IN (273, NULL)
    ");
    $stmt_update->execute([$supplier_account_id, $supplier_account_id]);
    
    echo "Updated " . $stmt_update->rowCount() . " invoices\n";
    
    $pdo->commit();
    echo "✅ Done!\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>