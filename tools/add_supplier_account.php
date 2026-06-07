<?php
require_once 'includes/db.php';

try {
    $pdo->beginTransaction();

    // Step 1: Get parent supplier account
    $stmt_parent = $pdo->prepare("SELECT id, account_code FROM unified_accounts WHERE account_code = '21101'");
    $stmt_parent->execute();
    $parent = $stmt_parent->fetch(PDO::FETCH_ASSOC);
    
    if (!$parent) {
        throw new Exception("Parent account 21101 not found!");
    }

    // Step 2: Find next available account code under 21101
    $stmt_max_code = $pdo->prepare("
        SELECT MAX(account_code) as max_code 
        FROM unified_accounts 
        WHERE parent_id = ?
    ");
    $stmt_max_code->execute([$parent['id']]);
    $max_code_result = $stmt_max_code->fetch(PDO::FETCH_ASSOC);
    
    $next_code = '21101001'; // default first child
    if ($max_code_result['max_code']) {
        $max_num = (int)substr($max_code_result['max_code'], 5);
        $next_code = '21101' . str_pad($max_num + 1, 3, '0', STR_PAD_LEFT);
    }

    echo "Creating supplier account with code: $next_code\n";

    // Step 3: Insert new supplier account (matching existing supplier accounts structure)
    $stmt_insert = $pdo->prepare("
        INSERT INTO unified_accounts (
            account_code, 
            account_name_ar, 
            account_type, 
            owner_type,
            normal_balance,
            credit_limit_base,
            debit_limit_base,
            parent_id,
            is_active,
            account_status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt_insert->execute([
        $next_code,
        'شركة المتصدر لنقل البري',
        'liability',
        'supplier',
        'credit',
        0.00,
        0.00,
        $parent['id'],
        1,
        'active'
    ]);
    
    $new_account_id = $pdo->lastInsertId();
    echo "New account created with ID: $new_account_id\n";

    // Step 4: Update supplier #6 with new account ID
    $stmt_update_supplier = $pdo->prepare("
        UPDATE suppliers 
        SET account_id = ?, updated_at = NOW()
        WHERE id = 6
    ");
    $stmt_update_supplier->execute([$new_account_id]);
    
    echo "Supplier #6 updated successfully!\n";

    $pdo->commit();
    echo "\n✅ Done!\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>