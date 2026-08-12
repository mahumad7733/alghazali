ALTER TABLE financial_transactions
    ADD COLUMN idempotency_key VARCHAR(128) NULL;
ALTER TABLE financial_transactions
    ADD UNIQUE KEY uq_financial_transactions_idempotency (idempotency_key);

ALTER TABLE invoices
    ADD COLUMN idempotency_key VARCHAR(128) NULL;
ALTER TABLE invoices
    ADD UNIQUE KEY uq_invoices_idempotency (idempotency_key);
