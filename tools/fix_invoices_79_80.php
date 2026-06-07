<?php
require_once 'includes/db.php';

try {
    $pdo->beginTransaction();
    
    $supplier_id = 6;
    
    // Update invoice 79
    $stmt_79 = $pdo->prepare("UPDATE invoices SET supplier_id = ?, updated_at = NOW() WHERE id = 79");
    $stmt_79->execute([$supplier_id]);
    echo "Updated invoice 79: " . $stmt_79->rowCount() . " rows\n";
    
    // Update invoice 80
    $stmt_80 = $pdo->prepare("UPDATE invoices SET supplier_id = ?, updated_at = NOW() WHERE id = 80");
    $stmt_80->execute([$supplier_id]);
    echo "Updated invoice 80: " . $stmt_80->rowCount() . " rows\n";
    
    $pdo->commit();
    echo "\n✅ Done!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>