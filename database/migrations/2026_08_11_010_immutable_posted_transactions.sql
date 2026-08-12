DROP TRIGGER IF EXISTS trg_financial_transaction_immutable_posted;
DROP TRIGGER IF EXISTS trg_journal_line_immutable_posted;

DELIMITER $$
CREATE TRIGGER trg_financial_transaction_immutable_posted
BEFORE UPDATE ON financial_transactions
FOR EACH ROW
BEGIN
    IF OLD.status IN ('posted','reversed','reconciled')
       AND (NOT (OLD.amount <=> NEW.amount)
            OR NOT (OLD.currency_id <=> NEW.currency_id)
            OR NOT (OLD.branch_id <=> NEW.branch_id)
            OR NOT (OLD.party_account_id <=> NEW.party_account_id)
            OR NOT (OLD.cash_bank_account_id <=> NEW.cash_bank_account_id)
            OR NOT (OLD.exchange_rate <=> NEW.exchange_rate)
            OR NOT (OLD.transaction_date <=> NEW.transaction_date)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted financial transaction fields are immutable; use reverse/cancel workflow';
    END IF;
END$$

CREATE TRIGGER trg_journal_line_immutable_posted
BEFORE UPDATE ON journal_lines
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM financial_transactions WHERE id=OLD.financial_transaction_id AND status IN ('posted','reversed','reconciled')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines of posted transactions are immutable';
    END IF;
END$$
DELIMITER ;
