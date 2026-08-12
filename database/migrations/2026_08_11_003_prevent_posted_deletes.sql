DROP TRIGGER IF EXISTS trg_financial_transaction_prevent_posted_delete;
DROP TRIGGER IF EXISTS trg_journal_line_prevent_posted_delete;

DELIMITER $$
CREATE TRIGGER trg_financial_transaction_prevent_posted_delete
BEFORE DELETE ON financial_transactions
FOR EACH ROW
BEGIN
    IF OLD.status IN ('posted', 'reversed', 'reconciled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted financial transactions cannot be deleted';
    END IF;
END$$

CREATE TRIGGER trg_journal_line_prevent_posted_delete
BEFORE DELETE ON journal_lines
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM financial_transactions
        WHERE id = OLD.financial_transaction_id
          AND status IN ('posted', 'reversed', 'reconciled')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines of posted transactions cannot be deleted';
    END IF;
END$$
DELIMITER ;
