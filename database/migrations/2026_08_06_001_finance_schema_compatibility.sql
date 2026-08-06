-- Finance schema compatibility migration
-- Apply only after a full backup and orphan/data checks.

-- UP
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(15,6) NOT NULL DEFAULT 1.000000 AFTER currency_id;

ALTER TABLE journal_lines
    ADD COLUMN IF NOT EXISTS line_number INT NULL AFTER financial_transaction_id,
    ADD COLUMN IF NOT EXISTS account_type VARCHAR(50) NULL AFTER account_id,
    ADD COLUMN IF NOT EXISTS base_debit DECIMAL(18,2) NULL DEFAULT 0 AFTER credit,
    ADD COLUMN IF NOT EXISTS base_credit DECIMAL(18,2) NULL DEFAULT 0 AFTER base_debit,
    ADD COLUMN IF NOT EXISTS line_type VARCHAR(50) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- ROLLBACK
-- ALTER TABLE journal_lines
--     DROP COLUMN IF EXISTS created_at,
--     DROP COLUMN IF EXISTS line_type,
--     DROP COLUMN IF EXISTS base_credit,
--     DROP COLUMN IF EXISTS base_debit,
--     DROP COLUMN IF EXISTS account_type,
--     DROP COLUMN IF EXISTS line_number;
-- ALTER TABLE invoices DROP COLUMN IF EXISTS exchange_rate;
