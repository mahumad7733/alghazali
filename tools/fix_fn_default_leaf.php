<?php
require_once 'includes/db.php';

echo "Fixing fn_get_default_leaf_account...\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = "DROP FUNCTION IF EXISTS fn_get_default_leaf_account";
    $pdo->exec($sql);
    
    $sql = "CREATE FUNCTION fn_get_default_leaf_account(p_parent_account_code VARCHAR(50))
RETURNS INT
DETERMINISTIC
BEGIN
    DECLARE v_parent_id INT;
    DECLARE v_leaf_id INT;
    DECLARE v_has_children INT;
    
    SELECT id INTO v_parent_id FROM unified_accounts WHERE account_code = p_parent_account_code;
    
    IF v_parent_id IS NULL THEN
        IF p_parent_account_code = '11101' THEN
            SELECT id INTO v_parent_id FROM unified_accounts WHERE account_code = '11101001';
        ELSEIF p_parent_account_code = '11102' THEN
            SELECT id INTO v_parent_id FROM unified_accounts WHERE account_code = '11102001';
        END IF;
    END IF;
    
    IF v_parent_id IS NULL THEN
        SELECT id INTO v_parent_id 
        FROM unified_accounts 
        WHERE account_code LIKE CONCAT(p_parent_account_code, '%')
        ORDER BY LENGTH(account_code) ASC
        LIMIT 1;
    END IF;
    
    IF v_parent_id IS NULL THEN
        RETURN NULL;
    END IF;
    
    -- Check if this account has children
    SELECT COUNT(*) INTO v_has_children FROM unified_accounts WHERE parent_id = v_parent_id;
    
    IF v_has_children = 0 THEN
        -- This account is already a leaf, return it
        RETURN v_parent_id;
    END IF;
    
    -- Find first leaf account under this parent
    SELECT id INTO v_leaf_id 
    FROM unified_accounts 
    WHERE parent_id = v_parent_id 
      AND id NOT IN (SELECT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    ORDER BY account_code ASC
    LIMIT 1;
    
    IF v_leaf_id IS NULL THEN
        -- No leaf account found, get first child
        SELECT id INTO v_leaf_id 
        FROM unified_accounts 
        WHERE parent_id = v_parent_id 
        ORDER BY account_code ASC
        LIMIT 1;
    END IF;
    
    RETURN v_leaf_id;
END";
    $pdo->exec($sql);
    
    echo "✅ fn_get_default_leaf_account fixed!\n";
    
    // Test it
    echo "\nTesting fn_get_default_leaf_account:\n";
    $stmt = $pdo->prepare("SELECT fn_get_default_leaf_account('11101001') AS cash, fn_get_default_leaf_account('11102001') AS bank, fn_get_default_leaf_account('11101') AS cash_old, fn_get_default_leaf_account('11102') AS bank_old");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "11101001: {$row['cash']}\n";
    echo "11102001: {$row['bank']}\n";
    echo "11101: {$row['cash_old']}\n";
    echo "11102: {$row['bank_old']}\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
