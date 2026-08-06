-- Finance schema compatibility migration
-- Apply only after a full backup and orphan/data checks.

-- UP
ALTER TABLE invoices
    ADD COLUMN exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1.000000 AFTER currency_id;

ALTER TABLE journal_lines
    ADD COLUMN line_number INT NULL AFTER financial_transaction_id,
    ADD COLUMN account_type VARCHAR(50) NULL AFTER account_id,
    ADD COLUMN base_debit DECIMAL(18,2) NULL DEFAULT 0 AFTER credit,
    ADD COLUMN base_credit DECIMAL(18,2) NULL DEFAULT 0 AFTER base_debit,
    ADD COLUMN line_type VARCHAR(50) NULL AFTER description,
    ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- ROLLBACK
-- ALTER TABLE journal_lines
--     DROP COLUMN created_at,
--     DROP COLUMN line_type,
--     DROP COLUMN base_credit,
--     DROP COLUMN base_debit,
--     DROP COLUMN account_type,
--     DROP COLUMN line_number;
-- ALTER TABLE invoices DROP COLUMN exchange_rate;
