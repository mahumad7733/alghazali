
<?php
require_once 'includes/db.php';

echo "<h2>Updating sp_post_invoice procedure</h2>";

$procedureSql = <<<SQL
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_invoice`(IN p_invoice_id INT, IN p_posted_by INT)
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
    DECLARE v_current_balance_base DECIMAL(18,2);
    DECLARE v_credit_limit_base DECIMAL(18,2);
    DECLARE v_new_balance_base DECIMAL(18,2);
    DECLARE v_error_msg TEXT;
    
    -- جلب بيانات الفاتورة
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
    
    IF v_invoice_category = 'sales' AND v_customer_id IS NOT NULL THEN 
        -- التحقق من أن حساب العميل (v_customer_account_id) من النوع الصحيح 
        SELECT account_type INTO @account_type 
        FROM unified_accounts WHERE id = v_customer_account_id; 
        
        IF @account_type NOT IN ('receivable', 'asset') THEN 
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'حساب العميل (الذمم المدينة) يجب أن يكون من نوع "ذمم مدينة" (Receivable) أو "أصل" (Asset).'; 
        END IF; 
    END IF;
    -- ======================================
    -- التحقق من حدود العميل قبل الترحيل
    -- ======================================
    IF v_invoice_category = 'sales' AND v_customer_account_id IS NOT NULL THEN
        -- جلب الرصيد الحالي والحد الائتماني
        SELECT 
            COALESCE(SUM(current_balance_base), 0), 
            COALESCE(credit_limit_base, 0)
        INTO v_current_balance_base, v_credit_limit_base
        FROM account_balances_unified abu
        JOIN unified_accounts ua ON abu.account_id = ua.id
        WHERE abu.account_id = v_customer_account_id;
        
        -- حساب الرصيد الجديد
        SET v_new_balance_base = v_current_balance_base + v_net_amount;
        
        -- التحقق من تجاوز الحد الائتماني
        IF v_credit_limit_base > 0 AND v_new_balance_base > v_credit_limit_base THEN
            SET v_error_msg = CONCAT('تجاوز الحد الائتماني! الرصيد الحالي: ', FORMAT(v_current_balance_base, 2), '، المبلغ الجديد: ', FORMAT(v_net_amount, 2), '، الحد الائتماني: ', FORMAT(v_credit_limit_base, 2));
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_error_msg;
        END IF;
    END IF;
    
    -- جلب حسابات الخدمة من الإعدادات
    IF v_source_type IN ('BusFlight', 'تذاكر طيران وبصات') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_bus_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_bus_account_id';
    ELSEIF v_source_type IN ('umrah', 'حج وعمرة') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_umrah_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_umrah_account_id';
    ELSEIF v_source_type IN ('work_visa', 'فيز العمل') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_work_visa_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_work_visa_account_id';
    ELSEIF v_source_type IN ('FamilyVisit', 'الزيارة العائلية') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_family_visit_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_family_visit_account_id';
    ELSEIF v_source_type IN ('Passport', 'جوازت السفر') THEN
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_passport_account_id';
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_passport_account_id';
    ELSE
        SELECT CAST(setting_value AS UNSIGNED) INTO v_revenue_account_id FROM system_settings WHERE setting_key = 'revenue_bus_account_id' LIMIT 1;
        SELECT CAST(setting_value AS UNSIGNED) INTO v_cost_account_id FROM system_settings WHERE setting_key = 'cost_bus_account_id' LIMIT 1;
    END IF;
    
    SELECT CAST(setting_value AS UNSIGNED) INTO v_cash_account_id FROM system_settings WHERE setting_key = 'default_cash_account_id';
    SELECT CAST(setting_value AS UNSIGNED) INTO v_bank_account_id FROM system_settings WHERE setting_key = 'default_bank_account_id';
    
    -- الحصول على حساب ذمم المدينة (العملاء)
    SELECT CAST(setting_value AS UNSIGNED) INTO v_acc_exists FROM system_settings WHERE setting_key = 'customer_receivable_account_id' LIMIT 1;
    IF v_acc_exists IS NULL OR v_acc_exists = 0 THEN
        -- Fallback to account 10 if setting not found
        SET v_acc_exists = 10;
    END IF;
    
    IF v_account_id IS NULL THEN
        IF v_invoice_category = 'sales' THEN
            SET v_account_id = v_customer_account_id;
        ELSE
            SET v_account_id = v_supplier_account_id;
        END IF;
    END IF;
    
    IF v_invoice_category = 'sales' THEN
        SET v_transaction_number = fn_get_next_sequence('journal');
        
        INSERT INTO financial_transactions (
            transaction_number, transaction_date, branch_id, transaction_type, 
            status, reference_type, reference_id, currency_id, amount, 
            description, created_by, posted_at, posted_by
        ) VALUES (
            v_transaction_number, v_invoice_date, v_branch_id, 'invoice', 
            'posted', 'invoice', p_invoice_id, v_currency_id, v_net_amount, 
            CONCAT('إثبات مبيعات فاتورة رقم ', v_invoice_number, ' - ', IFNULL(v_description,'')), 
            p_posted_by, NOW(), p_posted_by
        );
        
        SET v_transaction_id = LAST_INSERT_ID();
        
        -- قيد الإيرادات
        INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
        VALUES (v_transaction_id, v_revenue_account_id, 0, v_net_amount, v_currency_id, CONCAT('إيرادات خدمات - فاتورة ', v_invoice_number));
        
        IF v_amount_received >= v_net_amount THEN
            -- مدفوع بالكامل
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, v_net_amount, 0, v_currency_id, CONCAT('تحصيل كامل - فاتورة ', v_invoice_number));
        ELSEIF v_amount_received = 0 THEN
            -- مدين بالكامل
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, v_net_amount, 0, v_currency_id, CONCAT('مبيعات آجلة - فاتورة ', v_invoice_number));
        ELSE
            -- مدفوع جزئياً
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, v_amount_received, 0, v_currency_id, CONCAT('تحصيل جزئي (واصل) - فاتورة ', v_invoice_number));
            
            -- المتبقي في حساب العملاء (ذمم المدينة)
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, (v_net_amount - v_amount_received), 0, v_currency_id, CONCAT('متبقي مديونية - فاتورة ', v_invoice_number));
        END IF;
        
        CALL sp_update_account_balances(v_transaction_id);
    ELSE
        -- فاتورة شراء
        SET v_transaction_number = fn_get_next_sequence('journal');
        
        INSERT INTO financial_transactions (
            transaction_number, transaction_date, branch_id, transaction_type, 
            status, reference_type, reference_id, currency_id, amount, 
            description, created_by, posted_at, posted_by
        ) VALUES (
            v_transaction_number, v_invoice_date, v_branch_id, 'invoice', 
            'posted', 'invoice', p_invoice_id, v_currency_id, v_net_amount, 
            CONCAT('إثبات تكلفة فاتورة رقم ', v_invoice_number, ' - ', IFNULL(v_description,'')), 
            p_posted_by, NOW(), p_posted_by
        );
        
        SET v_transaction_id = LAST_INSERT_ID();
        
        -- قيد التكاليف
        INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
        VALUES (v_transaction_id, v_cost_account_id, v_net_amount, 0, v_currency_id, CONCAT('تكاليف خدمات - فاتورة ', v_invoice_number));
        
        IF v_amount_received >= v_net_amount THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, 0, v_net_amount, v_currency_id, CONCAT('سداد نقدي كامل - فاتورة ', v_invoice_number));
        ELSEIF v_amount_received = 0 THEN
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_account_id, 0, v_net_amount, v_currency_id, CONCAT('استحقاق مورد آجل - فاتورة ', v_invoice_number));
        ELSE
            SET v_acc_exists = CASE WHEN v_payment_type = 'bank_transfer' THEN v_bank_account_id ELSE v_cash_account_id END;
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, 0, v_amount_received, v_currency_id, CONCAT('سداد جزئي - فاتورة ', v_invoice_number));
            
            SET v_acc_exists = IFNULL(v_supplier_account_id, v_account_id);
            INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, description)
            VALUES (v_transaction_id, v_acc_exists, 0, (v_net_amount - v_amount_received), v_currency_id, CONCAT('متبقي استحقاق مورد - فاتورة ', v_invoice_number));
        END IF;
        
        CALL sp_update_account_balances(v_transaction_id);
    END IF;
    
    UPDATE invoices 
    SET invoice_status = 'posted', 
        posted_at = NOW(), 
        posted_by = p_posted_by, 
        payment_status = CASE 
            WHEN v_amount_received >= v_net_amount THEN 'fully_paid' 
            WHEN v_amount_received > 0 THEN 'partial' 
            ELSE 'unpaid' 
        END 
    WHERE id = p_invoice_id;
END
SQL;

try {
    $pdo->exec("DROP PROCEDURE IF EXISTS sp_post_invoice");
    $pdo->exec($procedureSql);
    echo "<p>✅ sp_post_invoice updated successfully!</p>";
} catch (Exception $e) {
    echo "<p>❌ Error updating procedure: " . $e->getMessage() . "</p>";
}
?>
