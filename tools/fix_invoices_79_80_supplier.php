<?php
require_once 'includes/db.php';

try {
    $pdo->beginTransaction();
    
    $supplier_id = 6; // شركة المتصدر لنقل البري
    
    // Update invoices 79 and 80
    $stmt_update = $pdo->prepare("
        UPDATE invoices 
        SET supplier_id = ?, updated_at = NOW()
        WHERE id IN (79, 80)
    ");
    $stmt_update->execute([$supplier_id]);
    
    echo "Updated " . $stmt_update->rowCount() . " invoices!\n";
    
    $pdo->commit();
    echo "\n✅ Done!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>