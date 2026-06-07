<?php
require_once 'includes/db.php';

try {
    $pdo->beginTransaction();
    
    // First, for any sales invoice that has cost_amount > 0 and supplier_id is null, set supplier_id to 6 (شركة المتصدر لنقل البري)
    $stmt_update = $pdo->prepare("
        UPDATE invoices 
        SET supplier_id = 6, updated_at = NOW()
        WHERE invoice_category = 'sales' 
        AND cost_amount > 0 
        AND supplier_id IS NULL
    ");
    $stmt_update->execute();
    
    echo "Updated " . $stmt_update->rowCount() . " sales invoices!\n";
    
    $pdo->commit();
    echo "\n✅ Done!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>