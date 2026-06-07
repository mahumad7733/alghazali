<?php
// Override database settings for alghazali
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db = 'alghazali';
$charset = 'utf8mb4';
$collation = 'utf8mb4_unicode_ci';

echo "=== Connecting to alghazali database ===\n";

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE $collation");
    echo "✅ Connected successfully!\n\n";

    // 1. Fix fn_get_default_leaf_account
    echo "1. Fixing fn_get_default_leaf_account... ";
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
    
    SELECT COUNT(*) INTO v_has_children FROM unified_accounts WHERE parent_id = v_parent_id;
    
    IF v_has_children = 0 THEN
        RETURN v_parent_id;
    END IF;
    
    SELECT id INTO v_leaf_id 
    FROM unified_accounts 
    WHERE parent_id = v_parent_id 
      AND id NOT IN (SELECT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    ORDER BY account_code ASC
    LIMIT 1;
    
    IF v_leaf_id IS NULL THEN
        SELECT id INTO v_leaf_id 
        FROM unified_accounts 
        WHERE parent_id = v_parent_id 
        ORDER BY account_code ASC
        LIMIT 1;
    END IF;
    
    RETURN v_leaf_id;
END";
    $pdo->exec($sql);
    echo "✅ Done\n";

    // 2. Fix sp_post_invoice
    echo "2. Fixing sp_post_invoice... ";
    $sql = "DROP PROCEDURE IF EXISTS sp_post_invoice";
    $pdo->exec($sql);
    $sql = "CREATE PROCEDURE sp_post_invoice(IN p_invoice_id INT, IN p_posted_by INT)
BEGIN
    DECLARE v_invoice_number VARCHAR(50);
    DECLARE v_invoice_date DATE;
    DECLARE v_branch_id INT;
    DECLARE v_invoice_category VARCHAR(20);
    DECLARE v_source_type VARCHAR(100);
    DECLARE v_source_id INT;
    DECLARE v_customer_id INT;
    DECLARE v_agent_id INT;
    DECLARE v_supplier_id INT;
    DECLARE v_customer_account_id INT;
    DECLARE v_supplier_account_id INT;
    DECLARE v_account_id INT;
    DECLARE v_currency_id INT;
    DECLARE v_total_amount DECIMAL(18,2);
    DECLARE v_discount DECIMAL(18,2);
    DECLARE v_net_amount DECIMAL(18,2);
    DECLARE v_amount_received DECIMAL(18,2);
    DECLARE v_description TEXT;
    DECLARE v_revenue_account_id INT;
    DECLARE v_cost_account_id INT;
    DECLARE v_cash_account_id INT;
    DECLARE v_bank_account_id INT;
    DECLARE v_transaction_number VARCHAR(50);
    DECLARE v_transaction_id INT;
    DECLARE v_acc_exists INT;
    DECLARE v_payment_type VARCHAR(50);
    DECLARE v_validation_msg VARCHAR(255);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT 
        invoice_number, invoice_date, branch_id, invoice_category,
        source_type, source_id, customer_id, agent_id, supplier_id,
        customer_account_id, supplier_account_id, account_id,
        currency_id, total_amount, discount, amount_received,
        description, payment_type
    INTO 
        v_invoice_number, v_invoice_date, v_branch_id, v_invoice_category,
        v_source_type, v_source_id, v_customer_id, v_agent_id, v_supplier_id,
        v_customer_account_id, v_supplier_account_id, v_account_id,
        v_currency_id, v_total_amount, v_discount, v_amount_received,
        v_description, v_payment_type
    FROM invoices
    WHERE id = p_invoice_id;

    SET v_net_amount = v_total_amount - v_discount;

    IF v_source_type = 'BusFlight' THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_bus_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_bus_account_id';
    ELSEIF v_source_type = 'umrah' THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_umrah_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_umrah_account_id';
    ELSEIF v_source_type = 'work_visa' THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_work_visa_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_work_visa_account_id';
    ELSEIF v_source_type = 'FamilyVisit' THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_family_visit_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_family_visit_account_id';
    ELSEIF v_source_type = 'Passport' THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'passports_revenue_account';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'passports_cost_account';
    ELSE
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_bus_account_id' LIMIT 1;
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_bus_account_id' LIMIT 1;
    END IF;

    SET v_cash_account_id = fn_get_default_leaf_account('11101001');
    SET v_bank_account_id = fn_get_default_leaf_account('11102001');
    
    IF v_cash_account_id IS NULL THEN
        SET v_cash_account_id = fn_get_default_leaf_account('11101');
    END IF;
    IF v_bank_account_id IS NULL THEN
        SET v_bank_account_id = fn_get_default_leaf_account('11102');
    END IF;

    IF v_account_id IS NULL THEN
        IF v_invoice_category = 'sales' THEN
            SET v_account_id = v_customer_account_id;
        ELSE
            SET v_account_id = v_supplier_account_id;
        END IF;
    END IF;

    START TRANSACTION;

    IF v_invoice_category = 'sales' THEN
        SET v_transaction_number = fn_get_next_sequence('journal');
        INSERT INTO financial_transactions (
            transaction_number, transaction_date, branch_id,
            transaction_type, status, reference_type, reference_id,
            currency_id, amount, description, created_by, posted_at, posted_by  
        ) VALUES (
            v_transaction_number, v_invoice_date, v_branch_id,
            'invoice', 'posted', 'invoice', p_invoice_id,
            v_currency_id, v_net_amount,
            CONCAT('إثبات مبيعات فاتورة رقم ', v_invoice_number, ' - ', IFNULL(v_description,'')),
            p_posted_by, NOW(), p_posted_by
        );
        SET v_transaction_id = LAST_INSERT_ID();

        IF v_revenue_account_id IS NOT NULL THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_revenue_account_id, 0, v_net_amount, v_currency_id, CONCAT('إيراد خدمات - فاتورة ', v_invoice_number));
        ELSE
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, 34, 0, v_net_amount, v_currency_id, CONCAT('إيراد خدمات - فاتورة ', v_invoice_number));
        END IF;

        IF v_amount_received >= v_net_amount THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, v_net_amount, 0, v_currency_id, CONCAT('تحصيل كامل - فاتورة ', v_invoice_number));
        ELSEIF v_amount_received = 0 THEN
            SET v_acc_exists = IFNULL(v_customer_account_id, v_account_id);
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, v_net_amount, 0, v_currency_id, CONCAT('مبيعات آجلة - فاتورة ', v_invoice_number));
        ELSE
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, v_amount_received, 0, v_currency_id, CONCAT('تحصيل جزئي (واصل) - فاتورة ', v_invoice_number));

            SET v_acc_exists = v_customer_account_id;
            IF v_acc_exists IS NULL OR v_acc_exists = v_account_id THEN
                IF v_customer_id IS NOT NULL THEN
                    SELECT account_id INTO v_acc_exists FROM customers WHERE id = v_customer_id;
                END IF;
                IF v_acc_exists IS NULL OR v_acc_exists = 0 THEN
                    SET v_acc_exists = v_account_id;
                END IF;
            END IF;
            
            IF v_acc_exists IS NOT NULL AND v_acc_exists > 0 THEN
                INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
                VALUES (v_transaction_id, v_acc_exists, (v_net_amount - v_amount_received), 0, v_currency_id, CONCAT('متبقي مديونية - فاتورة ', v_invoice_number));
            ELSE
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ: تعذر العثور على حساب ذمم مدينة لتسجيل المتبقي.';
            END IF;
        END IF;
    ELSE
        SET v_transaction_number = fn_get_next_sequence('journal');
        INSERT INTO financial_transactions (
            transaction_number, transaction_date, branch_id,
            transaction_type, status, reference_type, reference_id,
            currency_id, amount, description, created_by, posted_at, posted_by  
        ) VALUES (
            v_transaction_number, v_invoice_date, v_branch_id,
            'invoice', 'posted', 'invoice', p_invoice_id,
            v_currency_id, v_net_amount,
            CONCAT('إثبات مشتريات فاتورة رقم ', v_invoice_number, ' - ', IFNULL(v_description,'')),
            p_posted_by, NOW(), p_posted_by
        );
        SET v_transaction_id = LAST_INSERT_ID();

        IF v_cost_account_id IS NOT NULL THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_cost_account_id, v_net_amount, 0, v_currency_id, CONCAT('تكلفة المشتريات - فاتورة ', v_invoice_number));
        ELSE
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, 46, v_net_amount, 0, v_currency_id, CONCAT('تكلفة المشتريات - فاتورة ', v_invoice_number));
        END IF;

        IF v_amount_received >= v_net_amount THEN
            SET v_acc_exists = CASE
                WHEN v_payment_type = 'bank_transfer' THEN v_bank_account_id
                ELSE v_cash_account_id
            END;
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, 0, v_net_amount, v_currency_id, CONCAT('دفع نقدي كامل - فاتورة ', v_invoice_number));
        ELSEIF v_amount_received = 0 THEN
            SET v_acc_exists = IFNULL(v_supplier_account_id, v_account_id);
            IF v_acc_exists IS NULL OR v_acc_exists = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ: لم يتم تحديد حساب المورد للفاتورة.';
            END IF;
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, 0, v_net_amount, v_currency_id, CONCAT('ذمة مورد (أجل) - فاتورة ', v_invoice_number));
        ELSE
            SET v_acc_exists = CASE
                WHEN v_payment_type = 'bank_transfer' THEN v_bank_account_id
                ELSE v_cash_account_id
            END;
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, 0, v_amount_received, v_currency_id, CONCAT('دفع نقدي جزئي - فاتورة ', v_invoice_number));

            SET v_acc_exists = IFNULL(v_supplier_account_id, v_account_id);
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, 0, (v_net_amount - v_amount_received), v_currency_id, CONCAT('ذمة مورد (باقي) - فاتورة ', v_invoice_number));
        END IF;
    END IF;

    UPDATE invoices SET
        invoice_status = 'posted',
        posted_at = NOW(),
        posted_by = p_posted_by,
        payment_status = CASE
            WHEN v_amount_received >= v_net_amount THEN 'fully_paid'
            WHEN v_amount_received > 0 THEN 'partial'
            ELSE 'unpaid'
        END
    WHERE id = p_invoice_id;

    COMMIT;
END";
    $pdo->exec($sql);
    echo "✅ Done\n";

    // 3. Fix sp_post_receipt_voucher
    echo "3. Fixing sp_post_receipt_voucher... ";
    $sql = "DROP PROCEDURE IF EXISTS sp_post_receipt_voucher";
    $pdo->exec($sql);
    $sql = "CREATE PROCEDURE sp_post_receipt_voucher(IN p_transaction_id INT, IN p_posted_by INT, IN p_invoice_allocations JSON)
BEGIN
    DECLARE v_amount           DECIMAL(18,4);
    DECLARE v_currency_id      INT;
    DECLARE v_cash_account_id  INT;
    DECLARE v_party_account_id INT;
    DECLARE v_status           VARCHAR(50);
    DECLARE v_inv_id           INT;
    DECLARE v_alloc_amount     DECIMAL(18,4);
    DECLARE done               INT DEFAULT FALSE;

    DECLARE cur_alloc CURSOR FOR
        SELECT invoice_id, allocated_amount
        FROM payment_allocations
        WHERE financial_transaction_id = p_transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT amount, currency_id, cash_bank_account_id, party_account_id, status  
    INTO v_amount, v_currency_id, v_cash_account_id, v_party_account_id, v_status
    FROM financial_transactions
    WHERE id = p_transaction_id;

    IF v_status != 'draft' AND v_status != 'cancelled' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'السند ليس في حالة مسودة أو ملغي ولا يمكن ترحيله';
    END IF;

    DELETE FROM journal_lines WHERE financial_transaction_id = p_transaction_id;

    INSERT INTO journal_lines (financial_transaction_id, account_id, currency_id, debit, credit, description)
    VALUES (p_transaction_id, v_cash_account_id, v_currency_id, v_amount, 0,    
            CONCAT('ترحيل سند قبض رقم #', p_transaction_id));

    INSERT INTO journal_lines (financial_transaction_id, account_id, currency_id, debit, credit, description)
    VALUES (p_transaction_id, v_party_account_id, v_currency_id, 0, v_amount,   
            CONCAT('ترحيل سند قبض رقم #', p_transaction_id));

    CALL sp_update_account_balances(p_transaction_id);

    UPDATE financial_transactions
    SET status = 'posted',
        posted_at = NOW(),
        posted_by = p_posted_by
    WHERE id = p_transaction_id;

    IF p_invoice_allocations IS NOT NULL THEN
        DELETE FROM payment_allocations WHERE financial_transaction_id = p_transaction_id;

        SET @i = 0;
        WHILE @i < JSON_LENGTH(p_invoice_allocations) DO
            SET @inv_id = JSON_EXTRACT(p_invoice_allocations, CONCAT('$[', @i, '].invoice_id'));
            SET @alloc = JSON_EXTRACT(p_invoice_allocations, CONCAT('$[', @i, '].amount'));

            INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount)
            VALUES (p_transaction_id, @inv_id, @alloc);

            CALL sp_recalculate_invoice_payment(@inv_id);
            SET @i = @i + 1;
        END WHILE;
    ELSE
        OPEN cur_alloc;
        alloc_loop: LOOP
            FETCH cur_alloc INTO v_inv_id, v_alloc_amount;
            IF done THEN LEAVE alloc_loop; END IF;
            CALL sp_recalculate_invoice_payment(v_inv_id);
        END LOOP;
        CLOSE cur_alloc;
    END IF;

    COMMIT;
END";
    $pdo->exec($sql);
    echo "✅ Done\n";

    // 4. Fix sp_post_payment_voucher
    echo "4. Fixing sp_post_payment_voucher... ";
    $sql = "DROP PROCEDURE IF EXISTS sp_post_payment_voucher";
    $pdo->exec($sql);
    $sql = "CREATE PROCEDURE sp_post_payment_voucher(IN p_transaction_id INT, IN p_posted_by INT, IN p_invoice_allocations JSON)
BEGIN
    DECLARE v_amount           DECIMAL(18,4);
    DECLARE v_currency_id      INT;
    DECLARE v_cash_account_id  INT;
    DECLARE v_party_account_id INT;
    DECLARE v_status           VARCHAR(50);
    DECLARE v_inv_id           INT;
    DECLARE v_alloc_amount     DECIMAL(18,4);
    DECLARE done               INT DEFAULT FALSE;

    DECLARE cur_alloc CURSOR FOR
        SELECT invoice_id, allocated_amount
        FROM payment_allocations
        WHERE financial_transaction_id = p_transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT amount, currency_id, cash_bank_account_id, party_account_id, status  
    INTO v_amount, v_currency_id, v_cash_account_id, v_party_account_id, v_status
    FROM financial_transactions
    WHERE id = p_transaction_id;

    IF v_status != 'draft' AND v_status != 'cancelled' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'السند ليس في حالة مسودة أو ملغي ولا يمكن ترحيله';
    END IF;

    DELETE FROM journal_lines WHERE financial_transaction_id = p_transaction_id;

    INSERT INTO journal_lines (financial_transaction_id, account_id, currency_id, debit, credit, description)
    VALUES (p_transaction_id, v_party_account_id, v_currency_id, v_amount, 0,   
            CONCAT('ترحيل سند صرف رقم #', p_transaction_id));

    INSERT INTO journal_lines (financial_transaction_id, account_id, currency_id, debit, credit, description)
    VALUES (p_transaction_id, v_cash_account_id, v_currency_id, 0, v_amount,    
            CONCAT('ترحيل سند صرف رقم #', p_transaction_id));

    CALL sp_update_account_balances(p_transaction_id);

    UPDATE financial_transactions
    SET status = 'posted',
        posted_at = NOW(),
        posted_by = p_posted_by
    WHERE id = p_transaction_id;

    IF p_invoice_allocations IS NOT NULL THEN
        DELETE FROM payment_allocations WHERE financial_transaction_id = p_transaction_id;

        SET @i = 0;
        WHILE @i < JSON_LENGTH(p_invoice_allocations) DO
            SET @inv_id = JSON_EXTRACT(p_invoice_allocations, CONCAT('$[', @i, '].invoice_id'));
            SET @alloc = JSON_EXTRACT(p_invoice_allocations, CONCAT('$[', @i, '].amount'));

            INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount)
            VALUES (p_transaction_id, @inv_id, @alloc);

            CALL sp_recalculate_invoice_payment(@inv_id);
            SET @i = @i + 1;
        END WHILE;
    ELSE
        OPEN cur_alloc;
        alloc_loop: LOOP
            FETCH cur_alloc INTO v_inv_id, v_alloc_amount;
            IF done THEN LEAVE alloc_loop; END IF;
            CALL sp_recalculate_invoice_payment(v_inv_id);
        END LOOP;
        CLOSE cur_alloc;
    END IF;

    COMMIT;
END";
    $pdo->exec($sql);
    echo "✅ Done\n";

    // 5. Fix account 113
    echo "5. Fixing account 113... ";
    $sql = "INSERT IGNORE INTO unified_accounts (
        account_code, 
        account_name_ar, 
        account_type, 
        parent_id, 
        is_active, 
        account_status, 
        created_at
    ) VALUES (
        '113', 
        'السلف والعهد', 
        'asset', 
        NULL, 
        1, 
        'active', 
        NOW()
    )";
    $pdo->exec($sql);
    $sql = "SET @account_113_id = (SELECT id FROM unified_accounts WHERE account_code = '113')";
    $pdo->exec($sql);
    $sql = "UPDATE unified_accounts SET parent_id = @account_113_id WHERE account_code IN ('11301', '11302')";
    $pdo->exec($sql);
    echo "✅ Done\n";

    // 6. Add indexes
    echo "6. Adding indexes... ";
    $sql = "CREATE INDEX IF NOT EXISTS idx_jl_account_currency ON journal_lines (account_id, currency_id)";
    $pdo->exec($sql);
    $sql = "CREATE INDEX IF NOT EXISTS idx_ft_created_at ON financial_transactions (created_at)";
    $pdo->exec($sql);
    echo "✅ Done\n";

    echo "\n=== All fixes applied to alghazali database! ===\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
