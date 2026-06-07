<?php
/**
 * تطبيق Migration لتوحيد الحقول المالية
 */

require_once 'includes/db.php';

// دالة للتحقق من وجود الجدول
function tableExists($pdo, $table) {
    try {
        $result = $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>🚀 تطبيق Migration لتوحيد الحقول المالية</h2>";
    echo "<ul>";
    
    // =============================================================
    // الجزء الأول: حذف الحقول المالية من جداول الخدمات
    // =============================================================
    echo "<li>جارٍ حذف الحقول المالية من جداول الخدمات...</li>";
    
    // 1. bus_flight_bookings
    if (tableExists($pdo, 'bus_flight_bookings')) {
        $pdo->exec("
            ALTER TABLE `bus_flight_bookings` 
            DROP COLUMN IF EXISTS `sale_price`,
            DROP COLUMN IF EXISTS `cost_price`,
            DROP COLUMN IF EXISTS `currency_id`,
            DROP COLUMN IF EXISTS `payment_type`,
            DROP COLUMN IF EXISTS `payment_status`,
            DROP COLUMN IF EXISTS `amount_received`,
            DROP COLUMN IF EXISTS `discount`,
            DROP COLUMN IF EXISTS `tax_rate`,
            DROP COLUMN IF EXISTS `tax_amount`,
            DROP COLUMN IF EXISTS `net_amount`,
            DROP COLUMN IF EXISTS `revenue_entry_id`,
            DROP COLUMN IF EXISTS `cost_entry_id`
        ");
    }
    
    // 2. family_visit_requests
    if (tableExists($pdo, 'family_visit_requests')) {
        $pdo->exec("
            ALTER TABLE `family_visit_requests` 
            DROP COLUMN IF EXISTS `sale_price`,
            DROP COLUMN IF EXISTS `cost_price`,
            DROP COLUMN IF EXISTS `currency_id`,
            DROP COLUMN IF EXISTS `payment_type`,
            DROP COLUMN IF EXISTS `revenue_entry_id`,
            DROP COLUMN IF EXISTS `cost_entry_id`
        ");
    }
    
    // 3. work_visa_requests
    if (tableExists($pdo, 'work_visa_requests')) {
        $pdo->exec("
            ALTER TABLE `work_visa_requests` 
            DROP COLUMN IF EXISTS `sale_price`,
            DROP COLUMN IF EXISTS `cost_price`,
            DROP COLUMN IF EXISTS `currency_id`,
            DROP COLUMN IF EXISTS `payment_type`,
            DROP COLUMN IF EXISTS `revenue_entry_id`,
            DROP COLUMN IF EXISTS `cost_entry_id`
        ");
    }
    
    // 4. umrah_requests
    if (tableExists($pdo, 'umrah_requests')) {
        $pdo->exec("
            ALTER TABLE `umrah_requests` 
            DROP COLUMN IF EXISTS `sale_price`,
            DROP COLUMN IF EXISTS `cost_price`,
            DROP COLUMN IF EXISTS `currency_id`,
            DROP COLUMN IF EXISTS `payment_type`,
            DROP COLUMN IF EXISTS `revenue_entry_id`,
            DROP COLUMN IF EXISTS `cost_entry_id`
        ");
    }
    
    // 5. passport_transactions
    if (tableExists($pdo, 'passport_transactions')) {
        $pdo->exec("
            ALTER TABLE `passport_transactions` 
            DROP COLUMN IF EXISTS `sale_price`,
            DROP COLUMN IF EXISTS `cost_price`,
            DROP COLUMN IF EXISTS `currency_id`,
            DROP COLUMN IF EXISTS `payment_type`,
            DROP COLUMN IF EXISTS `payment_status`,
            DROP COLUMN IF EXISTS `amount_received`
        ");
    }
    
    // 6. family_visit_individuals
    if (tableExists($pdo, 'family_visit_individuals')) {
        $pdo->exec("
            ALTER TABLE `family_visit_individuals` 
            DROP COLUMN IF EXISTS `agent_price`,
            DROP COLUMN IF EXISTS `branch_price`,
            DROP COLUMN IF EXISTS `sale_price`
        ");
    }
    
    echo "<li class='text-success'>✅ تم حذف الحقول المالية بنجاح!</li>";
    
    // =============================================================
    // الجزء الثاني: إضافة أعمدة الربط
    // =============================================================
    echo "<li>جارٍ إضافة أعمدة الربط...</li>";
    
    // 1. bus_flight_bookings
    if (tableExists($pdo, 'bus_flight_bookings')) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM bus_flight_bookings LIKE 'sales_invoice_id'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE `bus_flight_bookings` 
                ADD COLUMN `sales_invoice_id` int DEFAULT NULL AFTER `status_id`,
                ADD COLUMN `purchase_invoice_id` int DEFAULT NULL AFTER `sales_invoice_id`,
                ADD COLUMN `auto_invoice_generated` tinyint(1) DEFAULT '0' AFTER `purchase_invoice_id`,
                ADD KEY `idx_booking_sales_invoice` (`sales_invoice_id`),
                ADD KEY `idx_booking_purchase_invoice` (`purchase_invoice_id`)
            ");
        }
    }
    
    // 2. family_visit_requests
    if (tableExists($pdo, 'family_visit_requests')) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM family_visit_requests LIKE 'sales_invoice_id'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE `family_visit_requests` 
                ADD COLUMN `sales_invoice_id` int DEFAULT NULL AFTER `status_id`,
                ADD COLUMN `purchase_invoice_id` int DEFAULT NULL AFTER `sales_invoice_id`,
                ADD COLUMN `auto_invoice_generated` tinyint(1) DEFAULT '0' AFTER `purchase_invoice_id`,
                ADD KEY `idx_family_sales_invoice` (`sales_invoice_id`),
                ADD KEY `idx_family_purchase_invoice` (`purchase_invoice_id`)
            ");
        }
    }
    
    // 3. work_visa_requests
    if (tableExists($pdo, 'work_visa_requests')) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM work_visa_requests LIKE 'sales_invoice_id'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE `work_visa_requests` 
                ADD COLUMN `sales_invoice_id` int DEFAULT NULL,
                ADD COLUMN `purchase_invoice_id` int DEFAULT NULL,
                ADD COLUMN `auto_invoice_generated` tinyint(1) DEFAULT '0',
                ADD KEY `idx_work_sales_invoice` (`sales_invoice_id`),
                ADD KEY `idx_work_purchase_invoice` (`purchase_invoice_id`)
            ");
        }
    }
    
    // 4. umrah_requests
    if (tableExists($pdo, 'umrah_requests')) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM umrah_requests LIKE 'sales_invoice_id'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE `umrah_requests` 
                ADD COLUMN `sales_invoice_id` int DEFAULT NULL,
                ADD COLUMN `purchase_invoice_id` int DEFAULT NULL,
                ADD COLUMN `auto_invoice_generated` tinyint(1) DEFAULT '0',
                ADD KEY `idx_umrah_sales_invoice` (`sales_invoice_id`),
                ADD KEY `idx_umrah_purchase_invoice` (`purchase_invoice_id`)
            ");
        }
    }
    
    // 5. passport_transactions
    if (tableExists($pdo, 'passport_transactions')) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM passport_transactions LIKE 'sales_invoice_id'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE `passport_transactions` 
                ADD COLUMN `sales_invoice_id` int DEFAULT NULL,
                ADD COLUMN `purchase_invoice_id` int DEFAULT NULL,
                ADD COLUMN `auto_invoice_generated` tinyint(1) DEFAULT '0',
                ADD KEY `idx_pt_sales_invoice` (`sales_invoice_id`),
                ADD KEY `idx_pt_purchase_invoice` (`purchase_invoice_id`)
            ");
        }
    }
    
    // 6. passports (for umrah and hajj)
    if (tableExists($pdo, 'passports')) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM passports LIKE 'sales_invoice_id'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE `passports` 
                ADD COLUMN `sales_invoice_id` int DEFAULT NULL,
                ADD COLUMN `purchase_invoice_id` int DEFAULT NULL,
                ADD COLUMN `auto_invoice_generated` tinyint(1) DEFAULT '0',
                ADD KEY `idx_passport_sales_invoice` (`sales_invoice_id`),
                ADD KEY `idx_passport_purchase_invoice` (`purchase_invoice_id`)
            ");
        }
    }
    
    // 6. invoices
    if (tableExists($pdo, 'invoices')) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'service_id'")->fetch();
        if (!$checkColumn) {
            $pdo->exec("
                ALTER TABLE `invoices` 
                ADD COLUMN `service_id` int DEFAULT NULL AFTER `source_id`,
                ADD KEY `idx_invoice_service_id` (`service_id`)
            ");
        }
    }
    
    echo "<li class='text-success'>✅ تم إضافة أعمدة الربط بنجاح!</li>";
    echo "</ul>";
    echo "<div class='alert alert-success'><h3>🎉 التطبيق اكتمل بنجاح!</h3></div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'><h3>❌ خطأ:</h3><p>" . $e->getMessage() . "</p></div>";
}
?>