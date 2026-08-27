-- ترقية نظام طرق الدفع والحسابات البنكية في منصة رِحلة
-- نفّذ هذا الملف مرة واحدة على قاعدة البيانات الحالية قبل استخدام الدفع البنكي.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS banks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name_ar VARCHAR(180) NOT NULL,
  account_name_ar VARCHAR(180) NOT NULL,
  account_number VARCHAR(128) NOT NULL,
  iban VARCHAR(128) NULL,
  branch_name_ar VARCHAR(180) NULL,
  notes_ar VARCHAR(500) NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_banks_active_name (is_active, name_ar),
  CONSTRAINT fk_banks_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_payment_channel := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_channel');
SET @sql_payment_channel := IF(@has_payment_channel = 0, 'ALTER TABLE payments ADD COLUMN payment_channel VARCHAR(32) NULL AFTER payment_method', 'SELECT 1');
PREPARE stmt_payment_channel FROM @sql_payment_channel;
EXECUTE stmt_payment_channel;
DEALLOCATE PREPARE stmt_payment_channel;

SET @has_payment_bank := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'bank_id');
SET @sql_payment_bank := IF(@has_payment_bank = 0, 'ALTER TABLE payments ADD COLUMN bank_id BIGINT UNSIGNED NULL AFTER payment_channel', 'SELECT 1');
PREPARE stmt_payment_bank FROM @sql_payment_bank;
EXECUTE stmt_payment_bank;
DEALLOCATE PREPARE stmt_payment_bank;

SET @has_bank_fk := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'fk_payments_bank');
SET @sql_bank_fk := IF(@has_bank_fk = 0, 'ALTER TABLE payments ADD CONSTRAINT fk_payments_bank FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt_bank_fk FROM @sql_bank_fk;
EXECUTE stmt_bank_fk;
DEALLOCATE PREPARE stmt_bank_fk;

SET @has_agent_payment := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_display_settings' AND COLUMN_NAME = 'allow_agent_payment');
SET @sql_agent_payment := IF(@has_agent_payment = 0, 'ALTER TABLE trip_display_settings ADD COLUMN allow_agent_payment TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt_agent_payment FROM @sql_agent_payment;
EXECUTE stmt_agent_payment;
DEALLOCATE PREPARE stmt_agent_payment;

SET @has_company_payment := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_display_settings' AND COLUMN_NAME = 'allow_company_payment');
SET @sql_company_payment := IF(@has_company_payment = 0, 'ALTER TABLE trip_display_settings ADD COLUMN allow_company_payment TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt_company_payment FROM @sql_company_payment;
EXECUTE stmt_company_payment;
DEALLOCATE PREPARE stmt_company_payment;

SET @has_transfer_payment := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_display_settings' AND COLUMN_NAME = 'allow_bank_transfer');
SET @sql_transfer_payment := IF(@has_transfer_payment = 0, 'ALTER TABLE trip_display_settings ADD COLUMN allow_bank_transfer TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt_transfer_payment FROM @sql_transfer_payment;
EXECUTE stmt_transfer_payment;
DEALLOCATE PREPARE stmt_transfer_payment;

UPDATE trip_display_settings SET allow_agent_payment = COALESCE(allow_agent_payment, 1), allow_company_payment = COALESCE(allow_company_payment, 1), allow_bank_transfer = COALESCE(allow_bank_transfer, 1) WHERE id = 1;
