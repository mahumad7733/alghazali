-- =============================================================
-- تقرير فني: توحيد الحقول المالية في نظام الفواتير
-- =============================================================
-- الجزء الأول: حذف الحقول المالية من جداول الخدمات
-- =============================================================

-- 1. جدول bus_flight_bookings
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
DROP COLUMN IF EXISTS `cost_entry_id`;

-- 2. جدول family_visit_requests
ALTER TABLE `family_visit_requests` 
DROP COLUMN IF EXISTS `sale_price`,
DROP COLUMN IF EXISTS `cost_price`,
DROP COLUMN IF EXISTS `currency_id`,
DROP COLUMN IF EXISTS `payment_type`,
DROP COLUMN IF EXISTS `revenue_entry_id`,
DROP COLUMN IF EXISTS `cost_entry_id`;

-- 3. جدول work_visa_requests (سيتم تخطيه إذا لم يكن موجوداً)
-- ALTER TABLE `work_visa_requests` 
-- DROP COLUMN IF EXISTS `sale_price`,
-- DROP COLUMN IF EXISTS `cost_price`,
-- DROP COLUMN IF EXISTS `currency_id`,
-- DROP COLUMN IF EXISTS `payment_type`,
-- DROP COLUMN IF EXISTS `revenue_entry_id`,
-- DROP COLUMN IF EXISTS `cost_entry_id`;

-- 4. جدول umrah_requests (سيتم تخطيه إذا لم يكن موجوداً)
-- ALTER TABLE `umrah_requests` 
-- DROP COLUMN IF EXISTS `sale_price`,
-- DROP COLUMN IF EXISTS `cost_price`,
-- DROP COLUMN IF EXISTS `currency_id`,
-- DROP COLUMN IF EXISTS `payment_type`,
-- DROP COLUMN IF EXISTS `revenue_entry_id`,
-- DROP COLUMN IF EXISTS `cost_entry_id`;

-- 5. جدول passport_transactions
ALTER TABLE `passport_transactions` 
DROP COLUMN IF EXISTS `sale_price`,
DROP COLUMN IF EXISTS `cost_price`,
DROP COLUMN IF EXISTS `currency_id`,
DROP COLUMN IF EXISTS `payment_type`,
DROP COLUMN IF EXISTS `payment_status`,
DROP COLUMN IF EXISTS `amount_received`;

-- 6. جدول family_visit_individuals
ALTER TABLE `family_visit_individuals` 
DROP COLUMN IF EXISTS `agent_price`,
DROP COLUMN IF EXISTS `branch_price`,
DROP COLUMN IF EXISTS `sale_price`;

-- =============================================================
-- الجزء الثاني: إضافة أعمدة الربط
-- =============================================================

-- 1. إضافة أعمدة الربط إلى bus_flight_bookings
ALTER TABLE `bus_flight_bookings` 
ADD COLUMN IF NOT EXISTS `sales_invoice_id` int DEFAULT NULL AFTER `status_id`,
ADD COLUMN IF NOT EXISTS `purchase_invoice_id` int DEFAULT NULL AFTER `sales_invoice_id`,
ADD COLUMN IF NOT EXISTS `auto_invoice_generated` tinyint(1) DEFAULT '0' AFTER `purchase_invoice_id`,
ADD KEY IF NOT EXISTS `idx_booking_sales_invoice` (`sales_invoice_id`),
ADD KEY IF NOT EXISTS `idx_booking_purchase_invoice` (`purchase_invoice_id`);

-- 2. إضافة أعمدة الربط إلى family_visit_requests
ALTER TABLE `family_visit_requests` 
ADD COLUMN IF NOT EXISTS `sales_invoice_id` int DEFAULT NULL AFTER `status_id`,
ADD COLUMN IF NOT EXISTS `purchase_invoice_id` int DEFAULT NULL AFTER `sales_invoice_id`,
ADD COLUMN IF NOT EXISTS `auto_invoice_generated` tinyint(1) DEFAULT '0' AFTER `purchase_invoice_id`,
ADD KEY IF NOT EXISTS `idx_family_sales_invoice` (`sales_invoice_id`),
ADD KEY IF NOT EXISTS `idx_family_purchase_invoice` (`purchase_invoice_id`);

-- 3. إضافة أعمدة الربط إلى work_visa_requests (ملاحظة: سيتم تخطيه إذا لم يكن موجوداً)
-- ALTER TABLE `work_visa_requests` 
-- ADD COLUMN IF NOT EXISTS `sales_invoice_id` int DEFAULT NULL,
-- ADD COLUMN IF NOT EXISTS `purchase_invoice_id` int DEFAULT NULL,
-- ADD COLUMN IF NOT EXISTS `auto_invoice_generated` tinyint(1) DEFAULT '0',
-- ADD KEY IF NOT EXISTS `idx_work_sales_invoice` (`sales_invoice_id`),
-- ADD KEY IF NOT EXISTS `idx_work_purchase_invoice` (`purchase_invoice_id`);

-- 4. إضافة أعمدة الربط إلى umrah_requests (ملاحظة: سيتم تخطيه إذا لم يكن موجوداً)
-- ALTER TABLE `umrah_requests` 
-- ADD COLUMN IF NOT EXISTS `sales_invoice_id` int DEFAULT NULL,
-- ADD COLUMN IF NOT EXISTS `purchase_invoice_id` int DEFAULT NULL,
-- ADD COLUMN IF NOT EXISTS `auto_invoice_generated` tinyint(1) DEFAULT '0',
-- ADD KEY IF NOT EXISTS `idx_umrah_sales_invoice` (`sales_invoice_id`),
-- ADD KEY IF NOT EXISTS `idx_umrah_purchase_invoice` (`purchase_invoice_id`);

-- 5. إضافة أعمدة الربط إلى passport_transactions
ALTER TABLE `passport_transactions` 
ADD COLUMN IF NOT EXISTS `sales_invoice_id` int DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `purchase_invoice_id` int DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `auto_invoice_generated` tinyint(1) DEFAULT '0',
ADD KEY IF NOT EXISTS `idx_pt_sales_invoice` (`sales_invoice_id`),
ADD KEY IF NOT EXISTS `idx_pt_purchase_invoice` (`purchase_invoice_id`);

-- 6. إضافة service_id إلى جدول invoices
ALTER TABLE `invoices` 
ADD COLUMN IF NOT EXISTS `service_id` int DEFAULT NULL AFTER `source_id`,
ADD KEY IF NOT EXISTS `idx_invoice_service_id` (`service_id`);

-- =============================================================
-- تنفيذ Migration
-- =============================================================
