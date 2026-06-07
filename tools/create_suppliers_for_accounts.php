<?php
require_once 'includes/db.php';

try {
    $pdo->beginTransaction();
    
    $accounts_to_create = [
        ['account_id' => 86, 'account_name' => 'المورد / الخطوط الجوية'],
        ['account_id' => 87, 'account_name' => 'المورد / مكتب العمرة'],
        ['account_id' => 106, 'account_name' => 'مورد تجربه']
    ];
    
    foreach ($accounts_to_create as $acc) {
        // Check if supplier already exists
        $stmt_check = $pdo->prepare("SELECT id FROM suppliers WHERE account_id = ?");
        $stmt_check->execute([$acc['account_id']]);
        if ($stmt_check->fetch()) {
            echo "Supplier already exists for account ID {$acc['account_id']}\n";
            continue;
        }
        
        // Insert new supplier
        $stmt_ins = $pdo->prepare("
            INSERT INTO suppliers (
                account_id, supplier_name, supplier_phone, supplier_email, address, status, created_at
            ) VALUES (?, ?, '', '', '', 'active', NOW())
        ");
        $stmt_ins->execute([$acc['account_id'], $acc['account_name']]);
        
        $new_supplier_id = $pdo->lastInsertId();
        echo "✅ Created supplier ID $new_supplier_id for {$acc['account_name']} (account ID {$acc['account_id']})\n";
    }
    
    $pdo->commit();
    echo "\n✅ Done!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>