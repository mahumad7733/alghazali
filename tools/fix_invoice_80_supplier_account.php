<?php
require_once 'includes/db.php';

try {
    $pdo->beginTransaction();
    
    // Get supplier #6's account ID
    $stmt_sup = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = 6");
    $stmt_sup->execute();
    $supplier_account_id = $stmt_sup->fetchColumn();
    
    if (!$supplier_account_id) {
        throw new Exception("Supplier #6 has no account ID!");
    }
    
    echo "Supplier #6's account ID is: $supplier_account_id\n";
    
    // Update invoice 80
    $stmt_update = $pdo->prepare("
        UPDATE invoices 
        SET account_id = ?, supplier_account_id = ?, updated_at = NOW()
        WHERE id = 80
    ");
    $stmt_update->execute([$supplier_account_id, $supplier_account_id]);
    
    echo "Updated invoice 80: " . $stmt_update->rowCount() . " rows\n";
    
    $pdo->commit();
    echo "\n✅ Done!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>