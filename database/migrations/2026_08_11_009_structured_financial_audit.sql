DROP TRIGGER IF EXISTS trg_financial_transaction_structured_audit_update;

DELIMITER $$
CREATE TRIGGER trg_financial_transaction_structured_audit_update
AFTER UPDATE ON financial_transactions
FOR EACH ROW
BEGIN
    IF NEW.status IN ('posted','reversed','cancelled')
       AND (OLD.status <> NEW.status OR OLD.status IS NULL)
       AND NOT EXISTS (
           SELECT 1 FROM financial_transaction_audit a
           WHERE a.target_table = 'financial_transactions'
             AND a.target_record_id = NEW.id
             AND a.status_after = NEW.status
       ) THEN
        INSERT INTO financial_transaction_audit
            (user_id, transaction_type, target_table, target_record_id, amount_before, amount_after,
             currency_id_before, currency_id_after, status_before, status_after, before_json, after_json, change_reason)
        VALUES
            (COALESCE(NULLIF(NEW.updated_by,0),NULLIF(NEW.posted_by,0),NULLIF(NEW.cancelled_by,0),NULLIF(NEW.created_by,0),1),
             CASE
               WHEN NEW.status = 'cancelled' THEN 'invoice_cancel'
               WHEN NEW.transaction_type = 'invoice' THEN 'invoice_posted'
               WHEN NEW.transaction_type = 'receipt' THEN 'receipt'
               WHEN NEW.transaction_type = 'payment' THEN 'payment'
               ELSE 'other'
             END,
             'financial_transactions', NEW.id, OLD.amount, NEW.amount,
             OLD.currency_id, NEW.currency_id, OLD.status, NEW.status,
             JSON_OBJECT('status',OLD.status,'amount',OLD.amount,'branch_id',OLD.branch_id),
             JSON_OBJECT('status',NEW.status,'amount',NEW.amount,'branch_id',NEW.branch_id),
             'database status transition');
    END IF;
END$$
DELIMITER ;

INSERT INTO financial_transaction_audit
    (user_id, transaction_type, target_table, target_record_id, amount_after, currency_id_after, status_after, after_json, change_reason)
SELECT COALESCE(NULLIF(ft.posted_by,0),NULLIF(ft.created_by,0),1),
       CASE
         WHEN ft.status = 'cancelled' THEN 'invoice_cancel'
         WHEN ft.transaction_type = 'invoice' THEN 'invoice_posted'
         WHEN ft.transaction_type = 'receipt' THEN 'receipt'
         WHEN ft.transaction_type = 'payment' THEN 'payment'
         ELSE 'other'
       END,
       'financial_transactions', ft.id, ft.amount, ft.currency_id, ft.status,
       JSON_OBJECT('transaction_number',ft.transaction_number,'branch_id',ft.branch_id),
       'phase2 structured audit reconciliation'
FROM financial_transactions ft
WHERE ft.status IN ('posted','reversed','cancelled')
  AND NOT EXISTS (SELECT 1 FROM financial_transaction_audit a WHERE a.target_table='financial_transactions' AND a.target_record_id=ft.id AND a.status_after=ft.status);
