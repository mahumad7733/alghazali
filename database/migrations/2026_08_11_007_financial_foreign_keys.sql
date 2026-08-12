-- Repair two orphaned historical account references with explicit placeholders.
INSERT INTO unified_accounts
    (id, account_code, account_name_ar, account_type, account_sub_type, owner_type, normal_balance, parent_id, branch_id, is_active, account_status)
SELECT 181, 'QA-ORPHAN-181', 'Historical orphan account 181', 'asset', 'other', 'other', 'debit', 10, 1, 0, 'inactive'
WHERE NOT EXISTS (SELECT 1 FROM unified_accounts WHERE id = 181);
INSERT INTO unified_accounts
    (id, account_code, account_name_ar, account_type, account_sub_type, owner_type, normal_balance, parent_id, branch_id, is_active, account_status)
SELECT 189, 'QA-ORPHAN-189', 'Historical orphan account 189', 'liability', 'other', 'other', 'credit', 21, 1, 0, 'inactive'
WHERE NOT EXISTS (SELECT 1 FROM unified_accounts WHERE id = 189);

ALTER TABLE journal_lines
    ADD CONSTRAINT fk_journal_lines_transaction FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_journal_lines_account FOREIGN KEY (account_id) REFERENCES unified_accounts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_journal_lines_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_journal_lines_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE payment_allocations
    ADD CONSTRAINT fk_payment_allocations_transaction FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_payment_allocations_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE account_balances_unified
    ADD CONSTRAINT fk_account_balances_account FOREIGN KEY (account_id) REFERENCES unified_accounts(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_account_balances_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_account_balances_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE financial_transactions
    ADD CONSTRAINT fk_financial_transactions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_financial_transactions_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_financial_transactions_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT ON UPDATE CASCADE;
