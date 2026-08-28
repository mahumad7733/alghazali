-- Rihla Saudi payment foundation (additive, re-runnable on MariaDB)
-- This migration does not delete or rewrite existing business data.
SET NAMES utf8mb4;

INSERT INTO currencies (code, name_ar, symbol_ar, decimal_places, is_default, is_active)
SELECT 'SAR', 'الريال السعودي', 'ر.س', 2, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM currencies WHERE code = 'SAR');

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(14,2) NULL AFTER total_amount,
  ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(9,4) NULL AFTER tax_amount,
  ADD COLUMN IF NOT EXISTS tax_snapshot_json LONGTEXT NULL AFTER tax_rate;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS internal_state VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN IF NOT EXISTS gateway_provider VARCHAR(64) NULL AFTER payment_channel,
  ADD COLUMN IF NOT EXISTS gateway_environment VARCHAR(16) NULL AFTER gateway_provider,
  ADD COLUMN IF NOT EXISTS provider_payment_id VARCHAR(160) NULL AFTER gateway_provider,
  ADD COLUMN IF NOT EXISTS provider_invoice_id VARCHAR(160) NULL AFTER provider_payment_id,
  ADD COLUMN IF NOT EXISTS provider_status VARCHAR(64) NULL AFTER provider_invoice_id,
  ADD COLUMN IF NOT EXISTS provider_fee_amount DECIMAL(14,2) NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS provider_net_amount DECIMAL(14,2) NULL AFTER provider_fee_amount,
  ADD COLUMN IF NOT EXISTS provider_currency_code CHAR(3) NULL AFTER provider_net_amount,
  ADD COLUMN IF NOT EXISTS metadata_json LONGTEXT NULL AFTER receipt_image_path,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE refunds
  ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER amount,
  ADD COLUMN IF NOT EXISTS provider_refund_id VARCHAR(160) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS provider_payment_id VARCHAR(160) NULL AFTER provider_refund_id,
  ADD COLUMN IF NOT EXISTS idempotency_key CHAR(36) NULL AFTER provider_payment_id,
  ADD COLUMN IF NOT EXISTS gateway_fee_amount DECIMAL(14,2) NULL AFTER idempotency_key,
  ADD COLUMN IF NOT EXISTS net_refund_amount DECIMAL(14,2) NULL AFTER gateway_fee_amount,
  ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(500) NULL AFTER reason_ar,
  ADD COLUMN IF NOT EXISTS refunded_at DATETIME NULL AFTER approved_by_user_id;

CREATE TABLE IF NOT EXISTS payment_gateway_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_code VARCHAR(64) NOT NULL,
  environment VARCHAR(16) NOT NULL DEFAULT 'sandbox',
  display_name_ar VARCHAR(160) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  public_key VARCHAR(500) NULL,
  secret_ciphertext TEXT NULL,
  webhook_secret_ciphertext TEXT NULL,
  config_json LONGTEXT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gateway_provider_environment (provider_code, environment),
  CONSTRAINT fk_gateway_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  provider_code VARCHAR(64) NOT NULL,
  attempt_type VARCHAR(32) NOT NULL DEFAULT 'hosted_invoice',
  idempotency_key CHAR(36) NOT NULL,
  provider_payment_id VARCHAR(160) NULL,
  provider_invoice_id VARCHAR(160) NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'created',
  provider_status VARCHAR(64) NULL,
  amount DECIMAL(14,2) NOT NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  currency_code CHAR(3) NOT NULL,
  provider_fee_amount DECIMAL(14,2) NULL,
  provider_net_amount DECIMAL(14,2) NULL,
  checkout_url TEXT NULL,
  request_hash CHAR(64) NULL,
  response_json LONGTEXT NULL,
  last_error VARCHAR(500) NULL,
  expires_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_attempt_idempotency (provider_code, idempotency_key),
  UNIQUE KEY uq_payment_attempt_provider_payment (provider_code, provider_payment_id),
  UNIQUE KEY uq_payment_attempt_provider_invoice (provider_code, provider_invoice_id),
  INDEX idx_payment_attempt_booking_state (booking_id, state, created_at),
  CONSTRAINT fk_payment_attempt_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_attempt_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_payment_attempt_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_code VARCHAR(64) NOT NULL,
  event_id VARCHAR(160) NOT NULL,
  event_type VARCHAR(100) NULL,
  signature_valid TINYINT(1) NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  processing_status VARCHAR(32) NOT NULL DEFAULT 'received',
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_payment_webhook_event (provider_code, event_id),
  INDEX idx_payment_webhook_status (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  vat_enabled TINYINT(1) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(9,4) NULL,
  tax_label_ar VARCHAR(160) NULL,
  invoice_mode VARCHAR(32) NOT NULL DEFAULT 'none',
  zatca_integration_enabled TINYINT(1) NOT NULL DEFAULT 0,
  supplier_snapshot_json LONGTEXT NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tax_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tax_settings (id, vat_enabled, vat_rate, tax_label_ar, invoice_mode, zatca_integration_enabled)
SELECT 1, 0, NULL, NULL, 'none', 0
WHERE NOT EXISTS (SELECT 1 FROM tax_settings WHERE id = 1);

CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(64) NOT NULL UNIQUE,
  booking_id BIGINT UNSIGNED NULL,
  payment_id BIGINT UNSIGNED NULL,
  invoice_type VARCHAR(32) NOT NULL DEFAULT 'simplified',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  currency_id BIGINT UNSIGNED NOT NULL,
  subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(9,4) NULL,
  supplier_snapshot_json LONGTEXT NULL,
  customer_snapshot_json LONGTEXT NULL,
  zatca_uuid VARCHAR(160) NULL,
  zatca_status VARCHAR(64) NULL,
  issued_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_invoices_booking_date (booking_id, created_at),
  CONSTRAINT fk_invoices_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_invoices_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  description_ar VARCHAR(500) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(9,4) NULL,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  snapshot_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO payment_gateway_settings (provider_code, environment, display_name_ar, is_enabled)
SELECT 'moyasar', 'sandbox', 'Moyasar — بيئة الاختبار', 0
WHERE NOT EXISTS (SELECT 1 FROM payment_gateway_settings WHERE provider_code = 'moyasar' AND environment = 'sandbox');

-- Never insert live credentials or enable a provider in a migration.
